<?php
/*
 * UnDash - virtual machines through libvirt's virsh, the same backend Unraid's VMs tab uses.
 * Live status is one `virsh list --all`; the details (vCPUs, memory, icon, VNC port, guest agent) are cached and re-read
 * only when the set of VMs or a VM's state changes, or after 10 minutes.
 * Hibernate is "dompmsuspend <vm> disk", which needs the QEMU guest agent running inside the VM (as on the VMs tab).
 */

function und_virsh() { return und_bin('virsh'); }

function und_valid_vm($n) { return is_string($n) && $n !== '' && strlen($n) <= 200 && !preg_match('/[\x00-\x1f\/]/', $n); }

/** name => state ("running", "shut off", "paused", "pmsuspended", "in shutdown", "crashed"). Null when libvirt is not running. */
function und_vm_states() {
  if (und_api_mode()) { $l = und_api_vm_live(); if (isset($l['error'])) return null; $o = []; foreach ($l['vms'] as $v) $o[$v['name']] = $v['state']; return $o; }
  $r = und_run([und_virsh(), 'list', '--all'], 10);
  if ($r['code'] !== 0) return null;
  $out = [];
  foreach (array_slice(explode("\n", trim($r['out'])), 2) as $l) {
    if (!preg_match('/^\s*(\d+|-)\s+(.+?)\s{2,}(\S.*?)\s*$/', $l, $m)) continue;
    $out[$m[2]] = strtolower($m[3]);
  }
  return $out;
}

function und_vm_details(array $states) {
  $f = und_tmp('vminfo.json');
  $sig = md5(json_encode($states));
  $c = und_read_json($f, []);
  if (($c['sig'] ?? '') === $sig && time() - (int)($c['at'] ?? 0) < 600) return $c['d'];
  $d = [];
  foreach ($states as $name => $st) {
    $info = [];
    foreach (explode("\n", und_run([und_virsh(), 'dominfo', $name], 10)['out']) as $l) if (strpos($l, ':') !== false) { list($k, $v) = explode(':', $l, 2); $info[trim($k)] = trim($v); }
    $xml = und_run([und_virsh(), 'dumpxml', $name], 10)['out'];
    $icon = preg_match('/<vmtemplate[^>]*\sicon=[\'"]([^\'"]+)[\'"]/', $xml, $m) ? $m[1] : '';
    $ws = preg_match('/<graphics[^>]*type=[\'"]vnc[\'"][^>]*websocket=[\'"](\d+)[\'"]/', $xml, $m) || preg_match('/<graphics[^>]*websocket=[\'"](\d+)[\'"][^>]*type=[\'"]vnc[\'"]/', $xml, $m) ? (int)$m[1] : 0;
    $d[$name] = ['cpus' => (int)($info['CPU(s)'] ?? 0), 'memory' => (float)preg_replace('/[^0-9]/', '', (string)($info['Max memory'] ?? '0')) * 1024,
      'autostart' => ($info['Autostart'] ?? '') === 'enable', 'os' => preg_match('/<vmtemplate[^>]*\sos=[\'"]([^\'"]+)[\'"]/', $xml, $m) ? $m[1] : '',
      'icon' => preg_match('/^[A-Za-z0-9_.-]+\.(png|svg)$/', $icon) ? '/plugins/dynamix.vm.manager/templates/images/' . $icon : '',
      'vnc' => $ws > 0 ? '/wsproxy/' . $ws . '/' : '', 'agent' => und_has($xml, 'org.qemu.guest_agent')];
  }
  und_write_json($f, ['sig' => $sig, 'at' => time(), 'd' => $d]);
  return $d;
}

/** Which actions make sense in a state; the UI shows only these and the server refuses the rest. */
function und_vm_valid_ops($state, $agent) {
  switch ($state) {
    case 'running': return array_values(array_filter(['stop', 'kill', 'restart', 'pause', $agent ? 'hibernate' : null]));
    case 'paused': return ['resume', 'kill'];
    case 'pmsuspended': return ['resume', 'kill'];
    case 'in shutdown': return ['kill'];
    case 'shut off': case 'crashed': return ['start'];
  }
  return [];
}

function und_vm_live() {
  if (und_api_mode()) return und_api_vm_live();
  $st = und_vm_states();
  if ($st === null) return ['error' => 'The VM service is not running.'];
  $det = und_vm_details($st);
  $out = [];
  foreach ($st as $name => $state) {
    $d = $det[$name] ?? ['cpus' => 0, 'memory' => 0, 'autostart' => false, 'os' => '', 'icon' => '', 'vnc' => '', 'agent' => false];
    $out[] = ['name' => $name, 'state' => $state, 'ops' => und_vm_valid_ops($state, $d['agent'])] + $d;
  }
  usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  return ['vms' => $out];
}

function und_vm_action($name, $op) {
  if (!und_valid_vm($name)) return ['error' => 'Invalid VM name.'];
  if (und_api_mode()) return und_api_vm_action($name, $op);
  $st = und_vm_states();
  if ($st === null) return ['error' => 'The VM service is not running.'];
  if (!isset($st[$name])) return ['error' => 'There is no VM named ' . $name . '.'];
  $state = $st[$name];
  $det = und_vm_details($st)[$name] ?? ['agent' => false];
  if (!in_array($op, und_vm_valid_ops($state, !empty($det['agent'])), true)) return ['error' => ucfirst($op) . ' is not possible while ' . $name . ' is ' . $state . '.'];
  $map = ['start' => 'start', 'stop' => 'shutdown', 'kill' => 'destroy', 'restart' => 'reboot', 'pause' => 'suspend', 'resume' => $state === 'pmsuspended' ? 'dompmwakeup' : 'resume', 'hibernate' => 'dompmsuspend'];
  $argv = [und_virsh(), $map[$op], $name];
  if ($op === 'hibernate') $argv[] = 'disk';
  $lock = und_lock('vm:' . $name);
  if (!$lock) return ['error' => 'Another action on ' . $name . ' is still running.'];
  $r = und_run($argv, 90);
  und_unlock($lock);
  @unlink(und_tmp('vminfo.json'));
  $ok = $r['code'] === 0;
  $err = $ok ? '' : und_clip(trim(preg_replace('/^error:\s*/im', '', $r['err'] . ' ' . $r['out'])), 300);
  if (!$ok && $op === 'hibernate' && preg_match('/agent/i', $err)) $err = 'Hibernate needs the QEMU guest agent running inside the VM. ' . $err;
  und_history_add('vm', $name, $op, $ok, $err);
  return $ok ? ['ok' => true] : ['error' => $err ?: 'virsh ' . $map[$op] . ' failed'];
}

function und_vm_summary() {
  $l = und_vm_live();
  if (isset($l['error'])) return $l;
  $s = ['running' => 0, 'stopped' => 0, 'paused' => 0, 'total' => count($l['vms'])];
  foreach ($l['vms'] as $v) {
    if ($v['state'] === 'running') $s['running']++; elseif (in_array($v['state'], ['paused', 'pmsuspended'], true)) $s['paused']++; else $s['stopped']++;
  }
  return $s;
}
