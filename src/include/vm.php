<?php
/*
 * UnDash - virtual machines through libvirt's virsh, the same backend Unraid's VMs tab uses.
 * Hibernate is "dompmsuspend <vm> disk", which needs the QEMU guest agent running inside the VM (as on the VMs tab).
 */

function und_virsh() { return und_bin('virsh'); }

function und_vm_list() {
  $r = und_run([und_virsh(), 'list', '--all', '--name'], 15);
  if ($r['code'] !== 0) return ['error' => trim($r['err']) ?: 'The VM service is not running.'];
  $out = [];
  foreach (array_filter(array_map('trim', explode("\n", $r['out']))) as $name) {
    $info = [];
    foreach (explode("\n", und_run([und_virsh(), 'dominfo', $name], 10)['out']) as $l) if (strpos($l, ':') !== false) { list($k, $v) = explode(':', $l, 2); $info[trim($k)] = trim($v); }
    $xml = und_run([und_virsh(), 'dumpxml', $name], 10)['out'];
    $icon = preg_match('/<vmtemplate[^>]*\sicon=[\'"]([^\'"]+)[\'"]/', $xml, $m) ? $m[1] : '';
    $ws = preg_match('/<graphics[^>]*type=[\'"]vnc[\'"][^>]*websocket=[\'"](\d+)[\'"]/', $xml, $m) || preg_match('/<graphics[^>]*websocket=[\'"](\d+)[\'"][^>]*type=[\'"]vnc[\'"]/', $xml, $m) ? (int)$m[1] : 0;
    $os = preg_match('/<vmtemplate[^>]*\sos=[\'"]([^\'"]+)[\'"]/', $xml, $m) ? $m[1] : '';
    $out[] = ['name' => $name, 'state' => strtolower((string)($info['State'] ?? 'unknown')), 'cpus' => (int)($info['CPU(s)'] ?? 0),
      'memory' => (float)preg_replace('/[^0-9]/', '', (string)($info['Max memory'] ?? '0')) * 1024, 'autostart' => ($info['Autostart'] ?? '') === 'enable',
      'icon' => preg_match('/^[A-Za-z0-9_.-]+\.(png|svg)$/', $icon) ? '/plugins/dynamix.vm.manager/templates/images/' . $icon : '', 'os' => $os,
      'vnc' => $ws > 0 ? '/wsproxy/' . $ws . '/' : '', 'agent' => und_has($xml, 'org.qemu.guest_agent')];
  }
  usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  return ['vms' => $out];
}

function und_vm_action($name, $op) {
  if (!is_string($name) || $name === '' || strlen($name) > 200 || preg_match('/[\x00-\x1f\/]/', $name)) return ['error' => 'Invalid VM name.'];
  $known = und_vm_list();
  if (isset($known['error'])) return $known;
  $vm = null;
  foreach ($known['vms'] as $v) if ($v['name'] === $name) $vm = $v;
  if (!$vm) return ['error' => 'No VM named ' . $name . '.'];
  $map = ['start' => ['start'], 'stop' => ['shutdown'], 'kill' => ['destroy'], 'restart' => ['reboot'], 'pause' => ['suspend'], 'hibernate' => ['dompmsuspend', '__NAME__', 'disk']];
  if ($op === 'resume') $map['resume'] = $vm['state'] === 'pmsuspended' ? ['dompmwakeup'] : ['resume'];
  if (!isset($map[$op])) return ['error' => 'Unknown action.'];
  if ($op === 'start' && $vm['state'] === 'pmsuspended') $map['start'] = ['dompmwakeup'];
  $args = $map[$op];
  $argv = [und_virsh(), $args[0], $name];
  if (count($args) > 2) $argv = [und_virsh(), $args[0], $name, $args[2]];
  $r = und_run($argv, 90);
  if ($r['code'] !== 0) {
    $err = und_clip(trim($r['err'] . ' ' . $r['out']), 300);
    if ($op === 'hibernate' && preg_match('/agent/i', $err)) $err = 'Hibernate needs the QEMU guest agent running inside the VM. ' . $err;
    return ['error' => $err ?: 'virsh ' . $args[0] . ' failed'];
  }
  return ['ok' => true];
}

function und_vm_summary() {
  $l = und_vm_list();
  if (isset($l['error'])) return $l;
  $s = ['running' => 0, 'stopped' => 0, 'paused' => 0, 'total' => count($l['vms']), 'vms' => []];
  foreach ($l['vms'] as $v) {
    if ($v['state'] === 'running') $s['running']++; elseif (in_array($v['state'], ['paused', 'pmsuspended'], true)) $s['paused']++; else $s['stopped']++;
    $s['vms'][] = ['name' => $v['name'], 'state' => $v['state'], 'vnc' => $v['vnc']];
  }
  return $s;
}
