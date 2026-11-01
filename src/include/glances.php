<?php
/*
 * UnDash - Glances as the source of the System tiles when the Docker version has no Unraid API key. It only reads: address and
 * numbers, no key. Glances knows the machine, not Unraid, so array state, parity, each disk's health, containers and VMs stay
 * with the Unraid API (und_layout_view hides them meanwhile). Fields are the ones Glances 3 and 4 document for /api/<version>/<plugin>.
 */

const UND_GLANCES_TILES = ['cpu', 'memory', 'storage', 'network', 'uptime', 'cores', 'swap'];

function und_glances_url(array $cfg) { return rtrim((string)($cfg['general']['glances'] ?? ''), '/'); }

function und_glances_get($url) {
  $r = und_http('GET', $url, ['timeout' => 3]);
  return $r['err'] === '' && $r['code'] === 200 && $r['json'] !== null ? $r['json'] : null;
}

/** "5 days, 10:56:00" or "10:56:00" as seconds. */
function und_glances_uptime($s) {
  if (!preg_match('/^(?:(\d+)\s+days?,\s*)?(\d+):(\d\d):(\d\d)/', trim((string)$s), $m)) return 0;
  return (int)$m[1] * 86400 + (int)$m[2] * 3600 + (int)$m[3] * 60 + (int)$m[4];
}

/** The hottest reading of the CPU itself: the package when there is one. Other sensors (an NVMe's Composite) are not the CPU. */
function und_glances_temp(array $sensors) {
  $pkg = null; $core = null;
  foreach ($sensors as $s) {
    if (!is_array($s) || !is_numeric($s['value'] ?? null)) continue;
    $l = (string)($s['label'] ?? '');
    if (preg_match('/^(Package id|Tctl|Tdie)/i', $l)) $pkg = max((float)$pkg, (float)$s['value']);
    elseif (preg_match('/^Core /i', $l)) $core = max((float)$core, (float)$s['value']);
  }
  return $pkg ?? $core;
}

/** The main interface and its speed in bytes a second. Glances 3 reports rx / tx, 4 reports the rate itself. */
function und_glances_net(array $list) {
  $net = null;
  foreach ($list as $n) {
    $name = (string)($n['interface_name'] ?? '');
    if ($name === '' || preg_match('/^(lo|tunl|veth|docker|shim|virbr|vnet|br-)/', $name)) continue;
    if (!$net || in_array($name, ['br0', 'bond0', 'eth0'], true) && !in_array($net['interface_name'], ['br0', 'bond0'], true)) $net = $n;
  }
  if (!$net) return ['rx' => null, 'tx' => null, 'iface' => ''];
  $since = max(1, (float)($net['time_since_update'] ?? 1));
  return ['rx' => (float)($net['bytes_recv_rate_per_sec'] ?? (($net['rx'] ?? 0) / $since)), 'tx' => (float)($net['bytes_sent_rate_per_sec'] ?? (($net['tx'] ?? 0) / $since)), 'iface' => (string)$net['interface_name']];
}

/** Your pools and disks: what is mounted directly under /mnt (Glances in Docker sees it under /rootfs or /hostfs). Without any, the largest real disk.
 *  Each ZFS dataset counts only its own data, so a pool's used space is the sum of its datasets. */
function und_glances_pools(array $fs) {
  $out = []; $seen = []; $other = null; $zfs = [];
  foreach ($fs as $f) {
    if (!is_array($f) || (float)($f['size'] ?? 0) <= 0) continue;
    if (strtolower((string)($f['fs_type'] ?? '')) === 'zfs') { $pool = explode('/', (string)($f['device_name'] ?? ''))[0]; $zfs[$pool] = ($zfs[$pool] ?? 0) + max(0, (float)$f['size'] - (float)($f['free'] ?? 0)); }
    $type = strtolower((string)($f['fs_type'] ?? '')); $mnt = (string)($f['mnt_point'] ?? '');
    if (preg_match('/^(fuse|shfs|squashfs|tmpfs|overlay|nfs|cifs|smb)/', $type)) continue;
    $row = ['name' => basename($mnt) ?: $mnt, 'size' => (float)$f['size'], 'free' => (float)($f['free'] ?? 0), 'temp' => null];
    if (preg_match('#^(/rootfs|/hostfs)?/mnt/([^/]+)$#', $mnt, $m) && !in_array($m[2], ['user', 'user0', 'disks', 'remotes', 'addons', 'rootshare'], true)) {
      $id = (string)($f['device_name'] ?? $mnt);
      if (!isset($seen[$id])) { $seen[$id] = 1; $out[] = $row + ['_dev' => $id]; }
    } elseif ($type !== 'vfat' && (!$other || $row['size'] > $other['size'])) $other = $row;
  }
  foreach ($out as &$r) { if (isset($zfs[$r['_dev']])) $r['size'] = $zfs[$r['_dev']] + $r['free']; unset($r['_dev']); }
  unset($r);
  return $out ?: ($other ? [$other] : []);
}

/** The readings at this address, or ['error' => ...]. Tells Glances 4 from 3 by which one answers. */
function und_glances_read($url) {
  $base = ''; $q = null;
  foreach ([4, 3] as $v) { $q = und_glances_get("$url/api/$v/quicklook"); if (is_array($q)) { $base = "$url/api/$v"; break; } }
  if (!is_array($q)) return ['error' => 'Glances at ' . (parse_url($url, PHP_URL_HOST) ?: 'that address') . ' is not answering. Is it running, with its web server on (-w)?'];
  $get = function ($p) use ($base) { $j = und_glances_get("$base/$p"); return is_array($j) ? $j : []; };
  $mem = $get('mem'); $swap = $get('memswap'); $sys = $get('system'); $pools = und_cached('glances-fs.json', 30, function () use ($get) { return und_glances_pools($get('fs')); });
  $up = und_glances_get("$base/uptime");
  $total = (float)($mem['total'] ?? 0); $free = (float)($mem['available'] ?? $mem['free'] ?? 0);
  return ['version' => $base[strlen($base) - 1], 'pct' => isset($q['cpu']) ? round((float)$q['cpu'], 1) : null, 'model' => (string)($q['cpu_name'] ?? ''),
          'cores' => array_values(array_map(function ($c) { return round((float)($c['total'] ?? 0), 1); }, array_filter((array)($q['percpu'] ?? []), 'is_array'))), 'temp' => und_glances_temp($get('sensors')),
          'mem' => ['total' => $total, 'used' => (float)($mem['used'] ?? max(0, $total - $free)), 'free' => $free, 'pct' => isset($mem['percent']) ? round((float)$mem['percent'], 1) : null, 'swap_total' => (float)($swap['total'] ?? 0), 'swap_used' => (float)($swap['used'] ?? 0)],
          'net' => und_glances_net($get('network')), 'uptime' => und_glances_uptime(is_string($up) ? $up : ''), 'pools' => $pools,
          'os' => preg_match('/Unraid OS (\S+)/', (string)($sys['linux_distro'] ?? ''), $m) ? $m[1] : ''];
}

/** Everything the overview needs, in the shape of und_system(). Without an address it says so: nothing is wrong, there is just no source. */
function und_glances_system(array $cfg) {
  $url = und_glances_url($cfg);
  if ($url === '') return ['error' => 'No source for the system numbers.', 'none' => true];
  $g = und_cached('glances.json', 1, function () use ($url) { return und_glances_read($url); });
  if (isset($g['error'])) return ['error' => $g['error'], 'auth' => true];
  $now = time(); $hist = und_read_json(und_tmp('hist.json'), []);
  if ($g['pct'] !== null && (empty($hist) || $now - (int)end($hist)[0] >= 2)) { $hist[] = [$now, $g['pct'], $g['mem']['used'], $g['net']['rx'], $g['net']['tx']]; $hist = array_slice($hist, -150); und_write_json(und_tmp('hist.json'), $hist); }
  return ['now' => $now, 'name' => (string)parse_url($url, PHP_URL_HOST), 'ip' => (string)parse_url($url, PHP_URL_HOST), 'os' => $g['os'], 'kernel' => '',
          'cpu' => ['pct' => $g['pct'], 'cores' => $g['cores'], 'model' => $g['model'], 'threads' => count($g['cores']), 'temp' => $g['temp'], 'load' => null],
          'mem' => $g['mem'], 'net' => $g['net'], 'disk' => null, 'uptime' => $g['uptime'], 'hist' => $hist,
          'array' => ['state' => '', 'resync' => 0, 'resync_pct' => null, 'resync_action' => '', 'size' => 0, 'free' => 0, 'disks' => 0, 'parity' => 0, 'pools' => $g['pools']],
          'os_update' => ['installed' => $g['os'], 'latest' => '', 'checked' => 0, 'error' => '', 'available' => false, 'staged' => '', 'job' => 'idle']];
}

/** The rows of the storage details, as und_storage() gives them. */
function und_glances_storage(array $cfg) {
  $s = und_glances_system($cfg);
  if (isset($s['error'])) return ['error' => $s['error']];
  $rows = array_map(function ($p) { return $p + ['device' => '', 'status' => 'DISK_OK', 'fs' => '', 'spun' => true, 'errors' => 0, 'color' => 'green-on', 'k' => 'Pool', 'health' => 'ok', 'smart' => null, 'model' => '']; }, $s['array']['pools']);
  return ['parity' => [], 'array' => [], 'pools' => $rows];
}
