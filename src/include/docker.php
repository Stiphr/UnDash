<?php
/*
 * UnDash - Docker containers. Reads go through the docker CLI; updates use Unraid's own update_container script, so the
 * container is recreated from its Unraid template exactly as the Docker tab's "apply update" does.
 */

function und_docker_bin() { return und_bin('docker'); }

function und_docker_list() {
  $ids = und_run([und_docker_bin(), 'ps', '-aq', '--no-trunc'], 20);
  if ($ids['code'] !== 0) return ['error' => trim($ids['err']) ?: 'Docker is not running.'];
  $ids = array_values(array_filter(preg_split('/\s+/', trim($ids['out']))));
  if (!$ids) return ['containers' => []];
  $r = und_run(array_merge([und_docker_bin(), 'inspect'], $ids), 30, 16777216);
  $data = json_decode($r['out'], true);
  if (!is_array($data)) return ['error' => 'docker inspect failed: ' . und_clip(trim($r['err']), 200)];
  $upd = und_read_json(und_path(UND_UPDSTATUS), []);
  $auto = array_flip(array_map(function ($l) { return trim(explode(' ', $l)[0]); }, explode("\n", und_read('/var/lib/docker/unraid-autostart'))));
  $ip = und_server_ip(); $out = [];
  foreach ($data as $c) {
    $name = ltrim((string)($c['Name'] ?? ''), '/');
    $image = (string)($c['Config']['Image'] ?? '');
    $key = und_has(basename($image), ':') || und_has($image, '@') ? $image : $image . ':latest';
    $u = $upd[$key] ?? null;
    $update = is_array($u) ? (($u['status'] ?? '') === 'false' ? 'available' : (($u['status'] ?? '') === 'true' ? 'current' : 'unknown')) : 'unknown';
    $labels = isset($c['Config']['Labels']) && is_array($c['Config']['Labels']) ? $c['Config']['Labels'] : [];
    $ports = [];
    foreach ((array)($c['HostConfig']['PortBindings'] ?? []) as $cp => $binds) foreach ((array)$binds as $b) if (!empty($b['HostPort'])) $ports[(int)$cp] = (int)$b['HostPort'];
    $net = (string)($c['HostConfig']['NetworkMode'] ?? '');
    $cip = '';
    foreach ((array)($c['NetworkSettings']['Networks'] ?? []) as $n) if (!empty($n['IPAddress'])) { $cip = $n['IPAddress']; break; }
    $webui = (string)($labels['net.unraid.docker.webui'] ?? '');
    if ($webui !== '') {
      $own = !in_array($net, ['bridge', 'host', 'default', ''], true) && strpos($net, 'container:') !== 0 && $cip !== '';
      $webui = str_replace('[IP]', $own ? $cip : $ip, $webui);
      $webui = preg_replace_callback('/\[PORT:(\d+)\]/', function ($m) use ($ports, $own) { return (string)($own ? $m[1] : ($ports[(int)$m[1]] ?? $m[1])); }, $webui);
    }
    $iconFile = '/usr/local/emhttp/state/plugins/dynamix.docker.manager/images/' . $name . '-icon.png';
    $icon = is_file(und_path($iconFile)) ? '/state/plugins/dynamix.docker.manager/images/' . rawurlencode($name) . '-icon.png' : (string)($labels['net.unraid.docker.icon'] ?? '');
    $out[] = ['id' => substr((string)$c['Id'], 0, 12), 'name' => $name, 'image' => $image, 'state' => (string)($c['State']['Status'] ?? ''),
      'health' => (string)($c['State']['Health']['Status'] ?? ''), 'started' => strtotime((string)($c['State']['StartedAt'] ?? '')) ?: null,
      'update' => $update, 'icon' => $icon, 'webui' => preg_match('#^https?://#', $webui) ? $webui : '', 'network' => $net, 'ip' => $cip,
      'ports' => $ports, 'autostart' => isset($auto[$name]), 'managed' => ($labels['net.unraid.docker.managed'] ?? '') === 'dockerman',
      'job' => und_job(und_tmp('docker-update-' . $name . '.log'))['state']];
  }
  usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  return ['containers' => $out];
}

function und_docker_action($name, $op) {
  if (!und_valid_name($name) || strpos($name, ' ') !== false) return ['error' => 'Invalid container name.'];
  $map = ['start' => 'start', 'stop' => 'stop', 'restart' => 'restart', 'pause' => 'pause', 'unpause' => 'unpause'];
  if ($op === 'update') {
    $log = und_tmp('docker-update-' . $name . '.log');
    if (und_job($log)['state'] === 'running') return ['error' => 'An update of ' . $name . ' is already running.'];
    und_spawn([UND_DM . '/scripts/update_container', $name], $log);
    return ['ok' => true, 'job' => 'running'];
  }
  if (!isset($map[$op])) return ['error' => 'Unknown action.'];
  $r = und_run([und_docker_bin(), $map[$op], $name], 120);
  if ($r['code'] !== 0) return ['error' => und_clip(trim($r['err'] . ' ' . $r['out']), 300) ?: 'docker ' . $op . ' failed'];
  return ['ok' => true];
}

/** Starts Unraid's own update check for every container (the "check for updates" button of the Docker tab). */
function und_docker_check() {
  $log = und_tmp('docker-check.log');
  if (und_job($log)['state'] === 'running') return ['ok' => true, 'job' => 'running'];
  und_spawn([UND_DM . '/scripts/dockerupdate', 'check', 'nonotify'], $log);
  return ['ok' => true, 'job' => 'running'];
}

/** The last N lines of stdout and stderr, merged in time order. */
function und_docker_logs($name, $lines = 300) {
  if (!und_valid_name($name) || strpos($name, ' ') !== false) return ['error' => 'Invalid container name.'];
  $lines = max(10, min(5000, (int)$lines));
  $r = und_run([und_docker_bin(), 'logs', '--tail', (string)$lines, '--timestamps', $name], 20, 8388608);
  if ($r['code'] !== 0 && $r['out'] === '' && $r['err'] === '') return ['error' => 'Could not read the log.'];
  $all = array_merge(explode("\n", rtrim($r['out'])), explode("\n", rtrim($r['err'])));
  $all = array_values(array_filter($all, function ($l) { return $l !== ''; }));
  usort($all, function ($a, $b) { return strcmp(substr($a, 0, 30), substr($b, 0, 30)); });
  $all = array_slice($all, -$lines);
  $text = implode("\n", array_map(function ($l) { return preg_replace('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)\.\d+Z /', '$1 ', $l); }, $all));
  return ['log' => preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $text)];
}

/** CPU and memory per running container (docker stats takes about two seconds, so it is a separate call). */
function und_docker_stats() {
  $r = und_run([und_docker_bin(), 'stats', '--no-stream', '--format', '{{json .}}'], 25);
  $out = [];
  foreach (explode("\n", trim($r['out'])) as $l) {
    $j = json_decode($l, true);
    if (!is_array($j) || empty($j['Name'])) continue;
    $out[$j['Name']] = ['cpu' => (float)rtrim((string)($j['CPUPerc'] ?? '0'), '%'), 'mem' => (string)preg_replace('#\s*/.*$#', '', (string)($j['MemUsage'] ?? '')), 'memp' => (float)rtrim((string)($j['MemPerc'] ?? '0'), '%')];
  }
  return ['stats' => (object)$out];
}

function und_docker_summary() {
  $l = und_docker_list();
  if (isset($l['error'])) return $l;
  $s = ['running' => 0, 'stopped' => 0, 'paused' => 0, 'updates' => 0, 'total' => count($l['containers'])];
  foreach ($l['containers'] as $c) {
    if ($c['state'] === 'running') $s['running']++; elseif ($c['state'] === 'paused') $s['paused']++; else $s['stopped']++;
    if ($c['update'] === 'available') $s['updates']++;
  }
  return $s;
}
