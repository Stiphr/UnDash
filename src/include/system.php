<?php
/*
 * UnDash - system information, read straight from the kernel and from Unraid's own state files (no Glances needed).
 * Rates (CPU, network) are the difference to the previous sample, kept in /tmp/undash so every poll is cheap.
 */

/** Jiffies for the whole CPU ("cpu") and for every core ("cpu0", "cpu1" ...). */
function und_cpu_sample() {
  $out = [];
  foreach (explode("\n", und_read('/proc/stat')) as $l) {
    if (!preg_match('/^cpu(\d*)\s+(.+)$/', $l, $m)) continue;
    $p = array_map('intval', preg_split('/\s+/', trim($m[2])));
    $out[$m[1] === '' ? 'all' : (int)$m[1]] = [array_sum(array_slice($p, 0, 8)), ($p[3] ?? 0) + ($p[4] ?? 0)];
  }
  return $out ?: null;
}

function und_cpu_pct($now, $prev) {
  $dTot = $now[0] - $prev[0]; $dIdle = $now[1] - $prev[1];
  return $dTot > 0 ? max(0, min(100, round(100 * ($dTot - $dIdle) / $dTot, 1))) : 0;
}

/** The interface of the default route. Unraid's macvlan "shim-" interfaces only mirror a bridge, so the bridge itself is preferred. */
function und_net_iface() {
  $found = [];
  foreach (array_slice(explode("\n", und_read('/proc/net/route')), 1) as $l) {
    $c = preg_split('/\s+/', trim($l));
    if (count($c) > 2 && $c[1] === '00000000') $found[] = $c[0];
  }
  foreach ($found as $f) if (strpos($f, 'shim-') !== 0) return $f;
  return $found ? substr($found[0], 5) : '';
}

function und_net_sample($if) {
  foreach (explode("\n", und_read('/proc/net/dev')) as $l) {
    if (strpos($l, ':') === false) continue;
    list($n, $rest) = explode(':', $l, 2);
    if (trim($n) !== $if) continue;
    $c = preg_split('/\s+/', trim($rest));
    return ['rx' => (float)$c[0], 'tx' => (float)$c[8]];
  }
  return null;
}

/** CPU % and network bytes/s since the previous call (a fresh 300 ms sample when there is none, or it is stale). */
function und_rates() {
  $f = und_tmp('rates.json');
  $prev = und_read_json($f, []);
  $now = microtime(true);
  $if = und_net_iface();
  // several browsers polling at once: a result from the last second is reused instead of sampling again
  if (!empty($prev['res']) && $now - ($prev['t'] ?? 0) < 1 && $now >= ($prev['t'] ?? 0)) return $prev['res'];
  $cpu = und_cpu_sample(); $net = $if ? und_net_sample($if) : null;
  if (!$prev || $now - ($prev['t'] ?? 0) > 60 || $now - ($prev['t'] ?? 0) < 0.25) {
    usleep(300000);
    $prev = ['t' => $now, 'cpu' => $cpu, 'net' => $net, 'if' => $if];
    $now = microtime(true); $cpu = und_cpu_sample(); $net = $if ? und_net_sample($if) : null;
  }
  $dt = max(0.05, $now - $prev['t']);
  $pct = null; $cores = [];
  if ($cpu && !empty($prev['cpu']['all'])) {
    $pct = und_cpu_pct($cpu['all'], $prev['cpu']['all']);
    foreach ($cpu as $k => $v) if ($k !== 'all' && isset($prev['cpu'][$k])) $cores[] = und_cpu_pct($v, $prev['cpu'][$k]);
  }
  $rx = $tx = null;
  if ($net && !empty($prev['net']) && ($prev['if'] ?? '') === $if) { $rx = max(0, ($net['rx'] - $prev['net']['rx']) / $dt); $tx = max(0, ($net['tx'] - $prev['net']['tx']) / $dt); }
  $res = ['cpu' => $pct, 'cores' => $cores, 'rx' => $rx, 'tx' => $tx, 'iface' => $if];
  und_write_json($f, ['t' => $now, 'cpu' => $cpu, 'net' => $net, 'if' => $if, 'res' => $res]);
  return $res;
}

function und_meminfo() {
  $m = [];
  foreach (explode("\n", und_read('/proc/meminfo')) as $l) if (preg_match('/^(\w+):\s+(\d+)/', $l, $x)) $m[$x[1]] = (float)$x[2] * 1024;
  $total = $m['MemTotal'] ?? 0; $avail = $m['MemAvailable'] ?? ($m['MemFree'] ?? 0);
  $st = $m['SwapTotal'] ?? 0; $sf = $m['SwapFree'] ?? 0;
  return ['total' => $total, 'used' => max(0, $total - $avail), 'free' => $avail, 'pct' => $total ? round(100 * ($total - $avail) / $total, 1) : null,
          'swap_total' => $st, 'swap_used' => max(0, $st - $sf)];
}

function und_cpu_model() {
  if (preg_match('/^model name\s*:\s*(.+)$/m', und_read('/proc/cpuinfo'), $m)) return trim(preg_replace('/\s+/', ' ', $m[1]));
  return '';
}

function und_cpu_threads() { return max(1, preg_match_all('/^processor\s*:/m', und_read('/proc/cpuinfo'))); }

/** CPU package temperature in °C (coretemp / k10temp / zenpower), else the hottest thermal zone. */
function und_cpu_temp() {
  foreach (glob(und_path('/sys/class/hwmon/hwmon*')) ?: [] as $d) {
    $n = trim((string)@file_get_contents("$d/name"));
    if (!in_array($n, ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal'], true)) continue;
    $best = null;
    foreach (glob("$d/temp*_label") ?: [] as $lf) {
      $lab = trim((string)@file_get_contents($lf));
      if (preg_match('/^(Package id 0|Tctl|Tdie)$/', $lab)) { $best = str_replace('_label', '_input', $lf); break; }
    }
    if (!$best) $best = "$d/temp1_input";
    $v = (int)@file_get_contents($best);
    if ($v > 0) return round($v / 1000, 1);
  }
  $max = null;
  foreach (glob(und_path('/sys/class/thermal/thermal_zone*/temp')) ?: [] as $f) { $v = (int)@file_get_contents($f); if ($v > 0) $max = max((float)$max, $v / 1000); }
  return $max === null ? null : round($max, 1);
}

function und_uptime() { return (int)(float)explode(' ', trim(und_read('/proc/uptime')) . ' ')[0]; }

function und_disk($path) {
  $p = und_path($path);
  if (!is_dir($p)) return null;
  $t = @disk_total_space($p); $f = @disk_free_space($p);
  // /mnt/user reports 0 on servers whose shares live only on ZFS or other pools
  if (!$t) return ['path' => $path, 'total' => 0, 'free' => 0, 'used' => 0, 'pct' => null];
  return ['path' => $path, 'total' => $t, 'free' => $f, 'used' => $t - $f, 'pct' => round(100 * ($t - $f) / $t, 1)];
}

/** Everything the overview needs, in one cheap call (no Docker, no network). */
function und_system(array $cfg) {
  if (und_api_mode()) return und_api_system($cfg);
  $r = und_rates(); $mem = und_meminfo(); $v = und_var_ini();
  $hist = und_read_json(und_tmp('hist.json'), []);
  $now = time();
  if ($r['cpu'] !== null && (empty($hist) || $now - (int)end($hist)[0] >= 2)) { $hist[] = [$now, $r['cpu'], $mem['used'], $r['rx'], $r['tx']]; $hist = array_slice($hist, -150); und_write_json(und_tmp('hist.json'), $hist); }
  $load = preg_split('/\s+/', trim(und_read('/proc/loadavg')));
  return [
    'now' => $now, 'name' => (string)($v['NAME'] ?? gethostname()), 'ip' => und_server_ip(), 'os' => und_os_version(), 'kernel' => trim((string)preg_replace('/^Linux version (\S+).*$/s', '$1', und_read('/proc/version'))),
    'cpu' => ['pct' => $r['cpu'], 'cores' => $r['cores'], 'model' => und_cpu_model(), 'threads' => und_cpu_threads(), 'temp' => und_cpu_temp(), 'load' => array_map('floatval', array_slice($load, 0, 3))],
    'mem' => $mem, 'net' => ['rx' => $r['rx'], 'tx' => $r['tx'], 'iface' => $r['iface']], 'disk' => und_disk($cfg['general']['disk_path']),
    'uptime' => und_uptime(), 'array' => und_array_info($v), 'hist' => $hist, 'os_update' => und_os_cached(),
  ];
}

/** Array state plus totals and a health summary from Unraid's disks.ini (cheap: a RAM-disk file). */
function und_array_info(array $v) {
  $st = und_storage(); $size = 0; $free = 0;
  foreach ($st['array'] as $d) { $size += $d['size']; $free += $d['free']; }
  $fl = und_disk_flags(array_merge($st['parity'], $st['array'], $st['pools']));
  $resync = (int)($v['mdResync'] ?? 0);
  return ['state' => (string)($v['mdState'] ?? ''), 'resync' => $resync, 'resync_pct' => $resync && (float)($v['mdResyncSize'] ?? 0) > 0 ? round(100 * (float)$v['mdResyncPos'] / (float)$v['mdResyncSize'], 1) : null,
          'resync_action' => (string)($v['mdResyncAction'] ?? ''), 'size' => $size, 'free' => $free, 'disks' => count($st['array']), 'parity' => count($st['parity']), 'pools' => $st['pools'],
          'mover' => is_file('/var/run/mover.pid')] + $fl;
}

/** Health counts plus the names of the disks behind them, so each disk can raise (and be ignored as) its own alert. */
function und_disk_flags(array $rows) {
  $o = ['bad' => 0, 'hot' => 0, 'errors' => 0, 'bad_disks' => [], 'hot_disks' => [], 'error_disks' => []];
  foreach ($rows as $d) {
    $n = (string)($d['name'] ?? '');
    if (($d['health'] ?? '') === 'bad') { $o['bad']++; $o['bad_disks'][] = $n; }
    if (($d['temp'] ?? null) !== null && $d['temp'] >= 50) { $o['hot']++; $o['hot_disks'][] = ['name' => $n, 'temp' => (int)$d['temp']]; }
    if ((int)($d['errors'] ?? 0) > 0) { $o['errors'] += (int)$d['errors']; $o['error_disks'][] = ['name' => $n, 'errors' => (int)$d['errors']]; }
  }
  return $o;
}

/* ---------- array & pools ---------- */

function und_storage() {
  if (und_api_mode()) return und_api_storage();
  $d = @parse_ini_string(und_read('/var/local/emhttp/disks.ini'), true);
  $out = ['array' => [], 'pools' => [], 'parity' => []];
  if (!is_array($d)) return $out;
  foreach ($d as $name => $x) {
    if (!is_array($x) || ($x['status'] ?? '') === 'DISK_NP' || ($x['status'] ?? '') === 'DISK_NP_DSBL') continue;
    $row = ['name' => (string)$name, 'device' => (string)($x['device'] ?? ''), 'status' => (string)($x['status'] ?? ''), 'temp' => is_numeric($x['temp'] ?? '') ? (int)$x['temp'] : null,
            'size' => (float)($x['fsSize'] ?? 0) * 1024, 'free' => (float)($x['fsFree'] ?? 0) * 1024, 'fs' => (string)($x['fsType'] ?? ''), 'spun' => ($x['spundown'] ?? '0') !== '1',
            'errors' => (int)($x['numErrors'] ?? 0), 'color' => (string)($x['color'] ?? '')];
    // Unraid's own disk colour: green = ok, yellow = emulated / being rebuilt, red = disabled; plus read/write errors
    $row['health'] = ($row['status'] !== 'DISK_OK' || strpos($row['color'], 'red') === 0) ? 'bad' : ((strpos($row['color'], 'yellow') === 0 || $row['errors'] > 0) ? 'warn' : 'ok');
    $type = (string)($x['type'] ?? '');
    if ($type === 'Parity') $out['parity'][] = $row;
    elseif ($type === 'Data') $out['array'][] = $row;
    // a pool's first device carries the file system totals; its other members (cache2, cache3 ...) have none
    elseif ($type === 'Cache' && (float)($x['fsSize'] ?? 0) > 0) $out['pools'][] = $row;
  }
  return $out;
}

/* ---------- UPS: NUT (upsc) first, then apcupsd (apcaccess) ---------- */

function und_ups($name = 'ups') {
  // the Unraid API only reads apcupsd; a NUT UPS is read straight from the server's NUT daemon (port 3493)
  if (und_api_mode()) return und_api_ups() ?: und_api_cached('nut', 5, function () use ($name) { return und_nut_tcp((string)parse_url(und_api_conf()['url'], PHP_URL_HOST), $name); });
  $upsc = und_bin('upsc');
  if ($upsc !== 'upsc' || isset($GLOBALS['UND_EXEC_MOCK'])) {
    $r = und_run([$upsc, strpos($name, '@') === false ? $name . '@localhost' : $name], 8);
    if ($r['code'] === 0 && preg_match('/battery\.charge:/', $r['out'])) {
      $k = [];
      foreach (explode("\n", $r['out']) as $l) if (strpos($l, ':') !== false) { list($a, $b) = explode(':', $l, 2); $k[trim($a)] = trim($b); }
      return und_nut_vars($k);
    }
  }
  $apc = und_bin('apcaccess');
  if ($apc !== 'apcaccess' || isset($GLOBALS['UND_EXEC_MOCK'])) {
    $r = und_run([$apc], 8);
    if ($r['code'] === 0 && preg_match('/BCHARGE/', $r['out'])) {
      $k = [];
      foreach (explode("\n", $r['out']) as $l) if (strpos($l, ':') !== false) { list($a, $b) = explode(':', $l, 2); $k[trim($a)] = trim($b); }
      return ['source' => 'apcupsd', 'charge' => (float)($k['BCHARGE'] ?? 0), 'load' => isset($k['LOADPCT']) ? (float)$k['LOADPCT'] : null,
              'status' => ucfirst(strtolower((string)($k['STATUS'] ?? ''))), 'runtime' => isset($k['TIMELEFT']) ? (int)round((float)$k['TIMELEFT'] * 60) : null, 'model' => (string)($k['MODEL'] ?? '')];
    }
  }
  return null;
}

function und_nut_vars(array $k) {
  return ['source' => 'NUT', 'charge' => (float)($k['battery.charge'] ?? 0), 'load' => isset($k['ups.load']) ? (float)$k['ups.load'] : null,
          'status' => und_ups_status($k['ups.status'] ?? ''), 'runtime' => isset($k['battery.runtime']) ? (int)$k['battery.runtime'] : null, 'model' => (string)($k['ups.model'] ?? $k['device.model'] ?? '')];
}

/** NUT's own protocol (LIST VAR), for a UPS name like "ups" or "ups@host[:port]"; null when there is no answer. */
function und_nut_tcp($host, $name) {
  if (strpos($name, '@') !== false) list($name, $host) = explode('@', $name, 2);
  $port = 3493;
  if (preg_match('/^(.+):(\d+)$/', $host, $m)) { $host = $m[1]; $port = (int)$m[2]; }
  if ($host === '' || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name)) return null;
  $f = @fsockopen($host, $port, $en, $es, 1.5);
  if (!$f) return null;
  stream_set_timeout($f, 2);
  fwrite($f, "LIST VAR $name\nLOGOUT\n");
  $k = [];
  while (($l = fgets($f)) !== false) {
    if (preg_match('/^VAR \S+ (\S+) "(.*)"$/', rtrim($l), $m)) $k[$m[1]] = stripcslashes($m[2]);
    elseif (strpos($l, 'END LIST') === 0 || strpos($l, 'ERR') === 0) break;
  }
  fclose($f);
  return isset($k['battery.charge']) ? und_nut_vars($k) : null;
}

function und_ups_status($s) {
  $map = ['OL' => 'Online', 'OB' => 'On battery', 'LB' => 'Low battery', 'CHRG' => 'Charging', 'DISCHRG' => 'Discharging', 'BYPASS' => 'Bypass', 'OVER' => 'Overload', 'RB' => 'Replace battery'];
  $w = [];
  foreach (preg_split('/\s+/', trim($s)) as $t) if ($t !== '') $w[] = $map[$t] ?? $t;
  return implode(', ', $w);
}

/* ---------- Unraid OS update (the same unRAIDServer.plg flow Tools > Update OS uses) ---------- */

function und_os_cached() {
  $c = und_read_json(und_tmp('os.json'), []);
  $installed = und_os_version();
  $latest = (string)($c['latest'] ?? '');
  return ['installed' => $installed, 'latest' => $latest, 'checked' => (int)($c['checked'] ?? 0), 'error' => (string)($c['error'] ?? ''),
          'available' => $latest !== '' && $installed !== '' && version_compare(und_ver_norm($latest), und_ver_norm($installed), '>'),
          'staged' => und_os_staged(), 'job' => und_job(und_tmp('os-update.log'))['state']];
}

/** "7.2.0-rc.1" -> "7.2.0rc1" so version_compare orders prereleases before the release. */
function und_ver_norm($v) { return preg_replace('/[-.]?(rc|beta|alpha)[.-]?/i', '$1', (string)$v); }

/** A newer release that was installed on the flash drive and becomes active after a reboot. */
function und_os_staged() {
  $installed = und_os_version();
  foreach (['/boot/config/plugins/unRAIDServer.plg', '/var/log/plugins/unRAIDServer.plg'] as $p) {
    if (!preg_match('/<!ENTITY\s+version\s+"([^"]+)"/', und_read($p), $m)) continue;
    if ($installed !== '' && version_compare(und_ver_norm($m[1]), und_ver_norm($installed), '>')) return $m[1];
    return '';
  }
  return '';
}

function und_os_check() {
  if (und_api_mode()) return ['error' => 'Unraid OS updates are not available through the Unraid API; use Tools > Update OS in Unraid.'];
  $tmp = '/tmp/plugins/unRAIDServer.plg';
  @unlink($tmp);
  $r = und_run([und_bin('plugin'), 'check', 'unRAIDServer.plg'], 120);
  $out = ['checked' => time(), 'latest' => '', 'error' => ''];
  if ($r['code'] === 0 && is_file($tmp) && preg_match('/<!ENTITY\s+version\s+"([^"]+)"/', (string)@file_get_contents($tmp), $m) && preg_match('/^[0-9][A-Za-z0-9._+-]{0,30}$/', $m[1])) $out['latest'] = $m[1];
  elseif (preg_match('/^\s*([0-9][A-Za-z0-9._+-]{0,30})\s*$/m', $r['out'], $m)) $out['latest'] = $m[1];
  else $out['error'] = $r['timeout'] ? 'The update check timed out.' : (und_clip(trim($r['err'] . ' ' . $r['out']), 200) ?: 'The update server did not answer.');
  und_write_json(und_tmp('os.json'), $out);
  return und_os_cached();
}

/** Downloads and installs the newer release onto the flash drive. It becomes active after a reboot. */
function und_os_update() {
  if (und_api_mode()) return ['error' => 'Unraid OS updates are not available through the Unraid API; use Tools > Update OS in Unraid.'];
  $st = und_os_check();
  if (!$st['available']) return ['error' => $st['error'] ?: 'No newer Unraid release is available.'];
  if (und_job(und_tmp('os-update.log'))['state'] === 'running') return ['error' => 'An update is already running.'];
  und_spawn([und_bin('plugin'), 'update', 'unRAIDServer.plg'], und_tmp('os-update.log'));
  return ['ok' => true];
}
