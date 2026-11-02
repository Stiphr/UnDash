<?php
/*
 * UnDash - system information, read straight from the kernel and from Unraid's own state files (no Glances needed).
 * Rates (CPU, network) are the difference to the previous sample, kept in /tmp/undash so every poll is cheap.
 */












/** Everything the overview needs, in one cheap call (no Docker, no network). */
function und_system(array $cfg) { return und_api_ready() ? und_api_system($cfg) : und_glances_system($cfg); }


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

function und_storage() { return und_api_storage(); }

/* ---------- UPS: NUT (upsc) first, then apcupsd (apcaccess) ---------- */

/** The UPS, with its power draw in watts when it can be known: measured by the UPS, or its load times its rated power
 *  ($nominal from Settings, else the rating the UPS reports, apcupsd NOMPOWER / NUT ups.realpower.nominal). */
function und_ups($name = 'ups', $nominal = 0) {
  $u = und_ups_read($name);
  if (!$u) return null;
  $n = (int)$nominal > 0 ? (int)$nominal : (int)($u['nominal'] ?? 0);
  $u['nominal'] = $n ?: null;
  if (empty($u['watts'])) $u['watts'] = $n && $u['load'] !== null ? (int)round($u['load'] / 100 * $n) : null;
  else $u['watts'] = (int)round($u['watts']);
  return $u;
}

function und_ups_read($name) {
  // the Unraid API only reads apcupsd; a NUT UPS is read straight from the server's NUT daemon (port 3493)
  return und_api_ups() ?: und_api_cached('nut', 5, function () use ($name) { return und_nut_tcp((string)parse_url(und_api_conf()['url'], PHP_URL_HOST), $name); });
}

function und_nut_vars(array $k) {
  return ['source' => 'NUT', 'charge' => (float)($k['battery.charge'] ?? 0), 'load' => isset($k['ups.load']) ? (float)$k['ups.load'] : null,
          'status' => und_ups_status($k['ups.status'] ?? ''), 'runtime' => isset($k['battery.runtime']) ? (int)$k['battery.runtime'] : null, 'model' => (string)($k['ups.model'] ?? $k['device.model'] ?? ''),
          'nominal' => isset($k['ups.realpower.nominal']) ? (int)(float)$k['ups.realpower.nominal'] : null, 'watts' => isset($k['ups.realpower']) ? (float)$k['ups.realpower'] : null];
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

/* ---------- Unraid OS update check ---------- */


/** "7.2.0-rc.1" -> "7.2.0rc1" so version_compare orders prereleases before the release. */
function und_ver_norm($v) { return preg_replace('/[-.]?(rc|beta|alpha)[.-]?/i', '$1', (string)$v); }


function und_os_check() {
  if (und_is_truenas()) return ['error' => 'TrueNAS shows its own updates under System > Update.'];
  // the API cannot check or install, but Unraid's public release service says what the latest version is
  $cur = (string)(und_system(und_load_config())['os_update']['installed'] ?? '');
  if ($cur === '') $cur = (string)(und_gql('query { info { versions { core { unraid } } } }', [], 8)['data']['info']['versions']['core']['unraid'] ?? '');
  try {
    $j = und_get('https://releases.unraid.net/os?' . http_build_query(['branch' => 'stable', 'current' => $cur]), ['timeout' => 15]);
    $v = (string)($j['version'] ?? '');
    if (!preg_match('/^[0-9][A-Za-z0-9._+-]{0,30}$/', $v)) return ['error' => 'The update server did not answer.'];
  } catch (Throwable $e) { return ['error' => 'The update server did not answer.']; }
  $out = ['checked' => time(), 'latest' => $v, 'installed' => $cur, 'available' => $cur !== '' && version_compare($v, $cur, '>')];
  und_write_json(und_tmp('api-os.json'), $out);
  return $out;
}

