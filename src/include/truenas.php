<?php
/*
 * UnDash - the official TrueNAS API (JSON-RPC 2.0 over a WebSocket at /api/current, TrueNAS 25.04 and newer).
 *
 * Every method, event and field below is from the API's own reference, api.truenas.com/v25.10. The REST API is left out on
 * purpose: TrueNAS is removing it. The API key is sent over TLS from the server only; it never reaches the browser, and
 * a plain http:// address is refused, because the key would travel in the clear.
 *
 * Roles the key's user needs: READONLY_ADMIN to show everything, plus APPS_WRITE and VM_WRITE for the buttons.
 * Not offered: pausing apps, the Unraid-only parity, Mover and array parts. Server power and TrueNAS updates are left to TrueNAS.
 *
 * Each login is written to TrueNAS's audit log, so a page never logs in: scripts/apipoll.php holds one connection, and pages
 * read the copies it leaves in the temp folder. Only actions, logs and the connection test open a connection of their own.
 * The shapes returned match the functions of unraid_api.php, so the page and the alerts need to know little about the server.
 */

const UND_TN_REPORT_EVERY = 3;       // seconds between CPU, memory and network reports
const UND_TN_STATS_EVERY = 5;        // seconds between per-app CPU and memory reports
const UND_TN_OLD = 30;               // a report older than this is not shown as live
const UND_TN_MAX_FRAME = 33554432;   // app.query can be large on a server with many apps

/* name => [refresh every N seconds with a page open, without one, method, params] */
const UND_TN_READS = [
  'info'   => [60, 60, 'system.info', []],
  'pools'  => [20, 60, 'pool.query', []],
  'apps'   => [3, 20, 'app.query', []],
  'vms'    => [5, 20, 'vm.query', []],
  'disks'  => [600, 600, 'disk.query', [[], ['extra' => ['pools' => true]]]],
  'alerts' => [60, 60, 'alert.list', []],
];
const UND_TN_TEMPS_EVERY = 300;      // TrueNAS itself reads disk temperatures at most this often

function und_is_truenas() { return und_api_conf()['type'] === 'truenas'; }

function und_tn_file($name) { return und_tmp('tn-' . $name . '.json'); }
function und_tn_copy($name, $max = PHP_INT_MAX) {
  $c = und_read_json(und_tn_file($name), null);
  return is_array($c) && microtime(true) - (float)($c['t'] ?? 0) < $max ? ($c['v'] ?? null) : null;
}

/** Reads again soon. The copy stays until then, so the list does not blink empty after an action. */
function und_tn_stale($name) { $c = und_read_json(und_tn_file($name), null); if (is_array($c)) und_write_json(und_tn_file($name), ['t' => 0] + $c); }

/* ---------- the connection ---------- */

/** Connects and logs in. Returns the connection, or ['error' => readable message, 'auth' => bool]. */
function und_tn_open($timeout = 8) {
  $c = und_api_conf(); $u = parse_url($c['url']);
  if ($c['url'] === '' || $c['key'] === '') return ['error' => 'TrueNAS is not configured (Settings > Server connection).', 'auth' => true];
  if (strtolower((string)($u['scheme'] ?? '')) !== 'https' || empty($u['host'])) return ['error' => 'The TrueNAS address must start with https://: the API key must not travel unencrypted.', 'auth' => true];
  $port = (int)($u['port'] ?? 443);
  $ctx = stream_context_create(['ssl' => ['verify_peer' => und_tls_verify(), 'verify_peer_name' => und_tls_verify(), 'peer_name' => $u['host']]]);
  $s = @stream_socket_client('ssl://' . $u['host'] . ':' . $port, $en, $es, $timeout, STREAM_CLIENT_CONNECT, $ctx);
  if (!$s) return ['error' => 'Cannot reach TrueNAS at ' . und_redact($c['url']) . ': ' . und_redact((string)$es, [$c['key']])];
  stream_set_timeout($s, 5);
  fwrite($s, "GET /api/current HTTP/1.1\r\nHost: " . $u['host'] . ':' . $port . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
  $line = fgets($s);
  if ($line === false || strpos($line, ' 101') === false) { fclose($s); return ['error' => 'TrueNAS did not open its API at /api/current. UnDash needs TrueNAS 25.04 or newer.']; }
  while (($l = fgets($s)) !== false && $l !== "\r\n");
  $cn = ['s' => $s, 'id' => 0, 'log' => [], 'dead' => false];
  $r = und_tn_call($cn, 'auth.login_ex', [['mechanism' => 'API_KEY_PLAIN', 'username' => $c['user'], 'api_key' => $c['key']]], 15);
  $type = (string)($r['result']['response_type'] ?? '');
  if ($type === 'SUCCESS') return $cn;
  fclose($s);
  if (isset($r['error'])) return ['error' => $r['error'], 'auth' => true];
  $why = ['AUTH_ERR' => 'TrueNAS refused the API key. Check the key and the user it belongs to.', 'EXPIRED' => 'That API key has expired or was revoked.',
          'OTP_REQUIRED' => 'This user needs a two-factor code, which an API key cannot give.', 'REDIRECT' => 'This TrueNAS node is on standby: use the active node\'s address.'];
  return ['error' => $why[$type] ?? 'TrueNAS did not accept the login.', 'auth' => true];
}

function und_tn_close(&$cn) { if (is_resource($cn['s'] ?? null)) @fclose($cn['s']); $cn['dead'] = true; }

/** One whole message, or null when nothing came in time. A message can arrive in pieces; pings are answered here. */
function und_tn_message(&$cn) {
  $buf = '';
  while (true) {
    $f = und_ws_read($cn['s'], UND_TN_MAX_FRAME);
    if ($f === null) {
      if (stream_get_meta_data($cn['s'])['eof']) $cn['dead'] = true;
      if ($buf === '' || $cn['dead']) return null;
      continue;
    }
    if ($f[0] === 8) { $cn['dead'] = true; return null; }
    if ($f[0] === 9) { und_ws_send($cn['s'], $f[1], 10); continue; }
    if ($f[0] === 10) continue;
    $buf .= $f[1];
    if ($f[2]) return $buf;
  }
}

/** Reports that arrive at any time, whatever is being waited for. */
function und_tn_event(&$cn, array $j) {
  if (($j['method'] ?? '') !== 'collection_update') return;
  $name = (string)($j['params']['collection'] ?? ''); $x = $j['params']['fields'] ?? null;
  if (!is_array($x)) return;
  if (strpos($name, 'reporting.realtime') === 0) {
    unset($x['zfs']);
    und_write_json(und_tn_file('realtime'), ['t' => microtime(true), 'v' => $x]);
  } elseif (strpos($name, 'app.stats') === 0) {
    $out = [];
    foreach ($x as $a) if (isset($a['app_name'])) $out[(string)$a['app_name']] = ['cpu' => round((float)($a['cpu_usage'] ?? 0), 1), 'mem' => (int)($a['memory'] ?? 0)];
    und_write_json(und_tmp('docker-stats.json'), ['t' => time(), 'v' => $out]);
  } elseif (strpos($name, 'app.container_log_follow') === 0) {
    $cn['log'][] = [(string)($x['timestamp'] ?? ''), (string)($x['data'] ?? '')];
  }
}

/** One call. Returns ['result' => ...] or ['error' => readable message, 'auth' => bool]. Reports that arrive meanwhile are kept. */
function und_tn_call(&$cn, $method, array $params = [], $timeout = 15) {
  if ($cn['dead']) return ['error' => 'The TrueNAS connection closed.'];
  $id = ++$cn['id']; $key = und_api_conf()['key'];
  und_ws_send($cn['s'], json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]));
  $end = microtime(true) + $timeout;
  while (microtime(true) < $end) {
    $m = und_tn_message($cn);
    if ($m === null) { if ($cn['dead']) return ['error' => 'The TrueNAS connection closed.']; continue; }
    $j = json_decode($m, true);
    if (!is_array($j)) continue;
    if (($j['id'] ?? null) !== $id) { und_tn_event($cn, $j); continue; }
    if (!isset($j['error'])) return ['result' => $j['result'] ?? null];
    $e = $j['error'];
    $msg = und_clip(und_redact(trim((string)($e['data']['reason'] ?? '')) ?: (string)($e['message'] ?? 'error'), [$key]), 300);
    $auth = preg_match('/not authori[sz]ed|permission|EACCES|EPERM|forbidden/i', $msg . ' ' . (string)($e['data']['errname'] ?? '')) === 1;
    return ['error' => ($auth ? 'The API key is missing a permission: ' : 'TrueNAS: ') . $msg, 'auth' => $auth];
  }
  return ['error' => 'TrueNAS did not answer ' . $method . ' in time.'];
}

/** A method that is a job. Its answer comes when the job ends; a server that answers with the job's number instead is asked how it went. */
function und_tn_job(&$cn, $method, array $params, $timeout = 120) {
  $r = und_tn_call($cn, $method, $params, $timeout);
  if (isset($r['error']) || !is_int($r['result'] ?? null)) return $r;
  $end = microtime(true) + $timeout;
  while (microtime(true) < $end) {
    $j = und_tn_call($cn, 'core.get_jobs', [[['id', '=', $r['result']]]]);
    if (isset($j['error'])) return $j;
    $st = (string)($j['result'][0]['state'] ?? '');
    if ($st === 'SUCCESS') return ['result' => $j['result'][0]['result'] ?? null];
    if ($st === 'FAILED' || $st === 'ABORTED') return ['error' => 'TrueNAS: ' . und_clip(und_redact((string)($j['result'][0]['error'] ?? $st), [und_api_conf()['key']]), 300)];
    sleep(1);
  }
  return ['error' => 'TrueNAS did not finish ' . $method . ' in time.'];
}

/** Listens for reports for about $sec seconds. */
function und_tn_pump(&$cn, $sec) {
  $end = microtime(true) + $sec;
  stream_set_timeout($cn['s'], 0, 300000);
  while (!$cn['dead'] && microtime(true) < $end) {
    $m = und_tn_message($cn);
    $j = $m === null ? null : json_decode($m, true);
    if (is_array($j)) und_tn_event($cn, $j);
  }
  if (!$cn['dead']) stream_set_timeout($cn['s'], 5);
}

/* ---------- the one background connection ---------- */

/** Asks for what is due. A read that fails keeps its last good copy and says why in the status. */
function und_tn_poll_round(&$cn, $viewer) {
  $st = und_read_json(und_tn_file('status'), []); $errs = (array)($st['reads'] ?? []);
  foreach (UND_TN_READS as $name => $x) {
    if (microtime(true) - (float)(und_read_json(und_tn_file($name), [])['t'] ?? 0) < $x[$viewer ? 0 : 1]) continue;
    $r = und_tn_call($cn, $x[2], $x[3], 30);
    if ($cn['dead']) return;
    if (isset($r['error'])) { $errs[$name] = $r['error']; continue; }
    unset($errs[$name]);
    und_write_json(und_tn_file($name), ['t' => microtime(true), 'v' => $r['result']]);
  }
  if (microtime(true) - (float)(und_read_json(und_tn_file('temps'), [])['t'] ?? 0) >= UND_TN_TEMPS_EVERY && ($names = und_tn_disk_names())) {
    $r = und_tn_call($cn, 'disk.temperatures', [$names, false], 30);
    if (!isset($r['error'])) und_write_json(und_tn_file('temps'), ['t' => microtime(true), 'v' => $r['result']]);
  }
  und_write_json(und_tn_file('status'), ['ok' => true, 'error' => '', 'auth' => false, 'reads' => (object)$errs, 't' => time()]);
}

function und_tn_disk_names() {
  $o = [];
  foreach ((array)und_tn_copy('disks') as $d) if (!empty($d['name'])) $o[] = (string)$d['name'];
  return $o;
}

/** Runs until the connection settings change. Called by scripts/apipoll.php. */
function und_tn_poll_forever() {
  $conf = json_encode(und_api_conf());
  while (und_is_truenas() && json_encode(und_api_conf()) === $conf) {
    @touch(und_tmp('apipoll.beat'));
    $cn = und_tn_open();
    if (isset($cn['error'])) {
      und_write_json(und_tn_file('status'), ['ok' => false, 'error' => $cn['error'], 'auth' => !empty($cn['auth']), 't' => time()]);
      sleep(!empty($cn['auth']) ? 60 : 10);     // a refused key must not fill TrueNAS's audit log
      continue;
    }
    und_tn_call($cn, 'core.subscribe', ['reporting.realtime:{"interval": ' . UND_TN_REPORT_EVERY . '}']);
    und_tn_call($cn, 'core.subscribe', ['app.stats:{"interval": ' . UND_TN_STATS_EVERY . '}']);
    while (!$cn['dead'] && json_encode(und_api_conf()) === $conf) {
      @touch(und_tmp('apipoll.beat'));
      clearstatcache();
      und_tn_poll_round($cn, time() - (int)@filemtime(und_tmp('viewer')) < 60);
      und_docker_skip_tick();
      und_tn_pump($cn, 1);
    }
    und_tn_close($cn);
    sleep(2);
  }
}

/** Opens a connection for one job, runs it and closes. */
function und_tn_session($fn) {
  $cn = und_tn_open();
  if (isset($cn['error'])) return ['error' => $cn['error'], 'auth' => !empty($cn['auth'])];
  $r = $fn($cn);
  und_tn_close($cn);
  return $r;
}

/** Connection test for Settings. */
function und_tn_test() {
  return und_tn_session(function (&$cn) {
    $i = und_tn_call($cn, 'system.info', [], 10);
    if (isset($i['error'])) return ['ok' => false, 'error' => $i['error']];
    $x = $i['result'];
    $out = ['ok' => true, 'unraid' => und_tn_version((string)($x['version'] ?? '')), 'api' => '', 'hostname' => (string)($x['hostname'] ?? ''), 'access' => []];
    foreach (['pools' => ['pool.query', []], 'disks' => ['disk.query', []], 'apps' => ['app.query', []], 'vms' => ['vm.query', []], 'alerts' => ['alert.list', []]] as $k => $m) {
      $t = und_tn_call($cn, $m[0], $m[1], 15);
      $out['access'][$k] = isset($t['error']) ? $t['error'] : 'ok';
    }
    return $out;
  }) + ['ok' => false];
}

/* ---------- reads ---------- */

function und_tn_version($v) { return (string)preg_replace('/^TrueNAS(-SCALE)?-/i', '', $v); }

function und_tn_identity() {
  $x = (array)und_tn_copy('info'); $host = (string)parse_url(und_api_conf()['url'], PHP_URL_HOST);
  return ['name' => (string)($x['hostname'] ?? '') ?: $host, 'ip' => $host, 'os' => und_tn_version((string)($x['version'] ?? ''))];
}

/** Why there is nothing to show, for the page and the alerts. Still connecting is not a fault. */
function und_tn_down() {
  $st = und_read_json(und_tn_file('status'), []);
  if ($st === []) return ['error' => 'Connecting to TrueNAS…', 'none' => true];
  return ['error' => (string)($st['error'] ?? '') ?: 'TrueNAS has stopped reporting.', 'auth' => !empty($st['auth'])];
}

/** The busiest interface that is up: the rates of every interface add bridges and their members twice. */
function und_tn_net(array $ifs) {
  $best = null; $name = '';
  foreach ($ifs as $n => $i) {
    if (($i['link_state'] ?? '') !== 'LINK_STATE_UP') continue;
    $t = (float)($i['received_bytes'] ?? 0) + (float)($i['sent_bytes'] ?? 0);
    if ($best === null || $t > $best[0]) { $best = [$t, $i]; $name = (string)$n; }
  }
  return $best ? ['rx' => (float)($best[1]['received_bytes_rate'] ?? 0), 'tx' => (float)($best[1]['sent_bytes_rate'] ?? 0), 'iface' => $name] : ['rx' => null, 'tx' => null, 'iface' => ''];
}

function und_tn_pool_row(array $p, array $hot) {
  $st = (string)($p['status'] ?? ''); $ok = !empty($p['healthy']);
  $size = und_api_bigint($p['size'] ?? 0); $free = und_api_bigint($p['free'] ?? 0);
  return ['name' => (string)($p['name'] ?? ''), 'device' => '', 'status' => $st, 'temp' => $hot[(string)($p['name'] ?? '')] ?? null, 'size' => $size, 'free' => $free, 'fs' => 'ZFS', 'spun' => true,
          'errors' => 0, 'color' => '', 'k' => 'Pool', 'health' => !$ok ? 'bad' : (!empty($p['warning']) ? 'warn' : 'ok')];
}

/** Rows for the disk table, and the hottest disk of each pool. The API gives no health per disk, so a disk row has none. */
function und_tn_disk_rows() {
  $rows = []; $hot = []; $temps = (array)und_tn_copy('temps');
  foreach ((array)und_tn_copy('disks') as $d) {
    $n = (string)($d['name'] ?? ''); $t = $temps[$n] ?? null; $t = is_numeric($t) && $t > 0 ? (int)$t : null; $pool = (string)($d['pool'] ?? '');
    if ($t !== null && $pool !== '') $hot[$pool] = max($hot[$pool] ?? 0, $t);
    $rows[] = ['name' => $n, 'device' => (string)($d['model'] ?? ''), 'status' => '', 'temp' => $t, 'size' => 0, 'free' => 0, 'fs' => $pool, 'spun' => true, 'errors' => 0, 'color' => '', 'k' => 'Disk', 'health' => 'none'];
  }
  return [$rows, $hot];
}

/** A scrub or resilver under way, in the shape of a parity check. */
function und_tn_scan(array $pools) {
  foreach ($pools as $p) {
    $s = $p['scan'] ?? null;
    if (!is_array($s) || ($s['state'] ?? '') !== 'SCANNING') continue;
    return ['resync' => 1, 'resync_pct' => isset($s['percentage']) ? round((float)$s['percentage'], 1) : null, 'resync_action' => ucfirst(strtolower((string)($s['function'] ?? 'scrub'))) . ' of ' . (string)($p['name'] ?? ''),
            'resync_paused' => !empty($s['pause'])];
  }
  return ['resync' => 0, 'resync_pct' => null, 'resync_action' => '', 'resync_paused' => false];
}

function und_tn_array() {
  $pools = (array)und_tn_copy('pools');
  list($disks, $hot) = und_tn_disk_rows();
  $rows = [];
  foreach ($pools as $p) $rows[] = und_tn_pool_row($p, $hot);
  $fl = und_disk_flags($rows);
  $fl['hot'] = 0; $fl['hot_disks'] = [];
  foreach ($disks as $d) if ($d['temp'] !== null && $d['temp'] >= 50) { $fl['hot']++; $fl['hot_disks'][] = ['name' => $d['name'], 'temp' => $d['temp']]; }
  return ['state' => 'STARTED', 'size' => 0, 'free' => 0, 'disks' => count($disks), 'parity' => 0, 'pools' => $rows, 'mover' => false, 'smart_lost' => [], 'parity_checks' => null,
          'resync_speed' => '', 'resync_time' => 0, '_rows' => ['parity' => [], 'array' => $disks, 'pools' => $rows]] + und_tn_scan($pools) + $fl;
}

function und_tn_system(array $cfg) {
  $rt = und_tn_copy('realtime', UND_TN_OLD);
  if (!is_array($rt)) return und_tn_down();
  $now = time(); $x = (array)und_tn_copy('info');
  $total = und_api_bigint($rt['memory']['physical_memory_total'] ?? 0); $avail = und_api_bigint($rt['memory']['physical_memory_available'] ?? 0);
  $mem = ['total' => $total, 'used' => max(0, $total - $avail), 'free' => $avail, 'pct' => $total ? round(100 * ($total - $avail) / $total, 1) : null, 'swap_total' => 0, 'swap_used' => 0];
  $cores = [];
  foreach ((array)($rt['cpu'] ?? []) as $k => $c) if (preg_match('/^cpu(\d+)$/', (string)$k, $m)) $cores[(int)$m[1]] = round((float)($c['usage'] ?? 0), 1);
  ksort($cores);
  $all = $rt['cpu']['cpu'] ?? null;
  $pct = is_array($all) ? round((float)($all['usage'] ?? 0), 1) : null;
  $net = und_tn_net((array)($rt['interfaces'] ?? []));
  $hist = und_read_json(und_tmp('hist.json'), []);
  if ($pct !== null && (empty($hist) || $now - (int)end($hist)[0] >= 2)) { $hist[] = [$now, $pct, $mem['used'], $net['rx'], $net['tx']]; $hist = array_slice($hist, -150); und_write_json(und_tmp('hist.json'), $hist); }
  $os = und_tn_version((string)($x['version'] ?? ''));
  return ['now' => $now, 'name' => (string)($x['hostname'] ?? ''), 'ip' => (string)parse_url(und_api_conf()['url'], PHP_URL_HOST), 'os' => $os, 'kernel' => '',
    'cpu' => ['pct' => $pct, 'cores' => array_values($cores), 'model' => (string)($x['model'] ?? ''), 'threads' => (int)($x['cores'] ?? 0), 'temp' => isset($all['temp']) ? (float)$all['temp'] : null, 'load' => null],
    'mem' => $mem, 'net' => $net, 'disk' => null, 'uptime' => (int)($x['uptime_seconds'] ?? 0), 'array' => und_tn_array(), 'hist' => $hist,
    'os_update' => ['installed' => $os, 'latest' => '', 'checked' => 0, 'error' => '', 'available' => false, 'job' => 'idle'], 'partial' => null];
}

function und_tn_storage() {
  $s = und_tn_system(und_load_config());
  return isset($s['error']) ? $s : $s['array']['_rows'];
}

/** TrueNAS's own alerts, for the bell. Dismissed ones stay dismissed. */
function und_tn_alerts() {
  $lv = ['INFO' => 'info', 'NOTICE' => 'info', 'WARNING' => 'warn'];
  $out = [];
  foreach ((array)und_tn_copy('alerts') as $a) {
    if (!empty($a['dismissed'])) continue;
    $msg = trim(html_entity_decode(strip_tags((string)($a['formatted'] ?? ''))));
    if ($msg !== '') $out[] = [$lv[(string)($a['level'] ?? '')] ?? 'bad', und_clip($msg, 300), (string)($a['klass'] ?? 'alert') . ':' . substr((string)($a['uuid'] ?? ''), 0, 12)];
  }
  return $out;
}

/* ---------- apps ---------- */

function und_tn_app_state($s) {
  $m = ['RUNNING' => 'running', 'STOPPED' => 'exited', 'CRASHED' => 'exited', 'DEPLOYING' => 'restarting', 'STOPPING' => 'restarting'];
  return $m[(string)$s] ?? 'unknown';
}

function und_tn_app_row(array $a, array $stats) {
  $name = (string)($a['name'] ?? ''); $w = (array)($a['active_workloads'] ?? []); $state = (string)($a['state'] ?? '');
  $ports = [];
  foreach ((array)($w['used_ports'] ?? []) as $p) if (!empty($p['host_ports'][0]['host_port'])) $ports[($p['container_port'] ?? 0) . '/' . strtolower((string)($p['protocol'] ?? 'tcp'))] = (int)$p['host_ports'][0]['host_port'];
  $web = '';
  foreach ((array)($a['portals'] ?? []) as $u) if ($web === '' && preg_match('#^https?://#', (string)$u)) $web = (string)$u;
  $icon = (string)($a['metadata']['icon'] ?? '');
  $job = und_job(und_tmp('docker-update-' . $name . '.log'));
  return ['name' => $name, 'id' => (string)($a['id'] ?? $name), 'image' => (string)($w['images'][0] ?? ''), 'state' => und_tn_app_state($state), 'status' => ucfirst(strtolower($state)) . (!empty($a['human_version']) ? ' · ' . $a['human_version'] : ''),
    'health' => $state === 'CRASHED' ? 'unhealthy' : '', 'cpu' => $stats[$name]['cpu'] ?? null, 'mem' => $stats[$name]['mem'] ?? null,
    'update' => !empty($a['upgrade_available']) || !empty($a['image_updates_available']) ? 'available' : 'current',
    'icon' => preg_match('#^https?://#', $icon) ? $icon : '', 'webui' => $web, 'ports' => (object)$ports, 'network' => '', 'autostart' => false, 'managed' => true, 'restarts' => null, 'started' => null, 'exit' => null,
    'job' => $job['state'] === 'running' ? 'updating' : ($job['state'] === 'done' && time() - (int)$job['at'] < 600 ? ($job['code'] === 0 ? 'updated' : 'update_failed') : '')];
}

function und_tn_docker_live() {
  $apps = und_tn_copy('apps');
  if (!is_array($apps)) { $why = (und_read_json(und_tn_file('status'), [])['reads']['apps'] ?? '') ?: und_tn_down()['error']; return ['error' => $why]; }
  $stats = und_api_stats_seen(); $out = [];
  foreach ($apps as $a) $out[] = und_tn_app_row($a, $stats);
  usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  return ['containers' => und_docker_skips($out), 'checking' => false, 'checked' => null, 'stats' => (bool)$stats];
}

function und_tn_app($name) {
  foreach ((array)und_tn_copy('apps') as $a) if ((string)($a['name'] ?? '') === $name) return $a;
  return null;
}

function und_tn_docker_action($name, $op) {
  if ($op === 'pause' || $op === 'unpause') return ['error' => 'TrueNAS apps cannot be paused.'];
  $valid = ['start' => ['exited'], 'stop' => ['running', 'restarting'], 'restart' => ['running', 'exited', 'restarting'], 'update' => null];
  if (!array_key_exists($op, $valid)) return ['error' => 'Unknown action.'];
  $a = und_tn_app($name);
  if (!$a) return ['error' => 'There is no app named ' . $name . '.'];
  $state = und_tn_app_state($a['state'] ?? '');
  if ($valid[$op] !== null && !in_array($state, $valid[$op], true)) return ['error' => ucfirst($op) . ' is not possible while ' . $name . ' is ' . $state . '.'];
  $lock = und_lock('docker:' . $name);
  if (!$lock) return ['error' => 'Another action on ' . $name . ' is still running.'];
  if ($op === 'update') {
    $log = und_tmp('docker-update-' . $name . '.log');
    if (und_job($log)['state'] === 'running') { und_unlock($lock); return ['error' => 'An update of ' . $name . ' is already running.']; }
    und_spawn([PHP_BINARY ?: 'php', '-d', 'auto_prepend_file=', UND_ROOT . '/scripts/apiop.php', 'tn-update', $name], $log);
    und_unlock($lock);
    und_history_add('docker', $name, 'update', true, 'started');
    return ['ok' => true, 'job' => 'running'];
  }
  // a restart is a stop and a start: app.redeploy would also pull new images, which is what Update is for
  $steps = ['start' => ['app.start'], 'stop' => ['app.stop'], 'restart' => $state === 'exited' ? ['app.start'] : ['app.stop', 'app.start']][$op];
  $r = und_tn_session(function (&$cn) use ($steps, $name) {
    foreach ($steps as $m) { $x = und_tn_job($cn, $m, [$name], 170); if (isset($x['error'])) return $x; }
    return [];
  });
  und_unlock($lock);
  und_tn_stale('apps');
  $ok = !isset($r['error']);
  if (!empty($r['auth'])) und_api_refused('docker');
  und_history_add('docker', $name, $op, $ok, $ok ? '' : $r['error']);
  return $ok ? ['ok' => true] : ['error' => $r['error']];
}

/** What an update does: a new version of the app when there is one, else newer images of the same version. */
function und_tn_update($name) {
  $a = und_tn_app($name);
  if (!$a) return ['error' => 'There is no app named ' . $name . '.'];
  $r = und_tn_session(function (&$cn) use ($a, $name) {
    return !empty($a['upgrade_available']) ? und_tn_job($cn, 'app.upgrade', [$name], 1800) : und_tn_job($cn, 'app.pull_images', [$name], 1800);
  });
  und_tn_stale('apps');
  if (!empty($r['auth'])) und_api_refused('docker');
  return $r;
}

function und_tn_docker_logs($name, $lines) {
  $a = und_tn_app($name);
  if (!$a) return ['error' => 'There is no app named ' . $name . '.'];
  $list = array_slice((array)($a['active_workloads']['container_details'] ?? []), 0, 6);
  if (!$list) return ['log' => ''];
  $tail = max(10, min(5000, (int)$lines));
  $r = und_tn_session(function (&$cn) use ($list, $name, $tail) {
    $out = [];
    foreach ($list as $c) {
      $cn['log'] = [];
      $s = und_tn_call($cn, 'core.subscribe', ['app.container_log_follow:' . json_encode(['app_name' => $name, 'container_id' => (string)($c['id'] ?? ''), 'tail_lines' => $tail])]);
      if (isset($s['error'])) return $s;
      und_tn_pump($cn, 2);
      und_tn_call($cn, 'core.unsubscribe', [(string)$s['result']]);
      $tag = count($list) > 1 ? (string)($c['service_name'] ?? '') . ' | ' : '';
      foreach ($cn['log'] as $l) $out[] = $tag . ($l[0] !== '' ? substr($l[0], 0, 19) . ' ' : '') . rtrim($l[1]);
    }
    return ['log' => preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', implode("\n", $out))];
  });
  return $r;
}

function und_tn_docker_inspect($name) {
  $a = und_tn_app($name);
  if (!$a) return ['error' => 'There is no app named ' . $name . '.'];
  $w = (array)($a['active_workloads'] ?? []); $mounts = []; $ports = []; $nets = [];
  foreach ((array)($w['volumes'] ?? []) as $v) $mounts[] = ['src' => (string)($v['source'] ?? ''), 'dst' => (string)($v['destination'] ?? ''), 'rw' => (string)($v['mode'] ?? '') === 'rw'];
  foreach ((array)($w['used_ports'] ?? []) as $p) foreach ((array)($p['host_ports'] ?? []) as $h) $ports[] = ($h['host_ip'] ?? '') . ':' . ($h['host_port'] ?? '') . ' → ' . ($p['container_port'] ?? '') . '/' . strtolower((string)($p['protocol'] ?? 'tcp'));
  foreach ((array)($w['networks'] ?? []) as $n) $nets[] = ['name' => (string)($n['Name'] ?? ''), 'ip' => ''];
  return ['name' => $name, 'id' => (string)($a['id'] ?? $name), 'image' => (string)($w['images'][0] ?? ''), 'image_id' => '', 'created' => null, 'state' => und_tn_app_state($a['state'] ?? ''), 'started' => null, 'finished' => null, 'exit' => 0,
    'state_error' => '', 'oom' => false, 'restarts' => null, 'restart_policy' => '', 'network_mode' => '', 'networks' => $nets, 'ports' => $ports, 'mounts' => $mounts, 'env' => [], 'labels' => new stdClass(), 'health' => [], 'privileged' => false,
    'note' => 'Environment variables and health-check history are not available through the TrueNAS API.'];
}

/* ---------- VMs ---------- */

function und_tn_vm_state($s) {
  $m = ['RUNNING' => 'running', 'STOPPED' => 'shut off', 'SUSPENDED' => 'paused'];
  return $m[(string)$s] ?? strtolower((string)$s);
}

function und_tn_vm_live() {
  $list = und_tn_copy('vms');
  if (!is_array($list)) { $why = (und_read_json(und_tn_file('status'), [])['reads']['vms'] ?? '') ?: und_tn_down()['error']; return ['error' => preg_match('/permission/i', $why) ? $why : 'VMs are not available: ' . $why]; }
  $out = [];
  foreach ($list as $d) {
    $st = und_tn_vm_state($d['status']['state'] ?? '');
    $out[] = ['name' => (string)($d['name'] ?? ''), 'id' => (int)($d['id'] ?? 0), 'state' => $st, 'ops' => und_api_can('vms') ? und_api_vm_ops($st) : [], 'cpus' => null, 'memory' => null, 'autostart' => !empty($d['autostart']), 'os' => '', 'icon' => '', 'vnc' => '', 'agent' => false];
  }
  usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  return ['vms' => $out];
}

function und_tn_vm_action($name, $op) {
  $l = und_tn_vm_live();
  if (isset($l['error'])) return $l;
  $vm = null; foreach ($l['vms'] as $v) if ($v['name'] === $name) $vm = $v;
  if (!$vm) return ['error' => 'There is no VM named ' . $name . '.'];
  if (!in_array($op, $vm['ops'], true)) return ['error' => ucfirst($op) . ' is not possible while ' . $name . ' is ' . $vm['state'] . '.'];
  $map = ['start' => 'vm.start', 'stop' => 'vm.stop', 'kill' => 'vm.poweroff', 'restart' => 'vm.restart', 'pause' => 'vm.suspend', 'resume' => 'vm.resume'];
  $lock = und_lock('vm:' . $name);
  if (!$lock) return ['error' => 'Another action on ' . $name . ' is still running.'];
  $r = und_tn_session(function (&$cn) use ($map, $op, $vm) { return und_tn_job($cn, $map[$op], [$vm['id']], 120); });
  und_unlock($lock);
  und_tn_stale('vms');
  if (!empty($r['auth'])) und_api_refused('vms');
  $ok = !isset($r['error']);
  und_history_add('vm', $name, $op, $ok, $ok ? '' : $r['error']);
  return $ok ? ['ok' => true] : ['error' => $r['error']];
}
