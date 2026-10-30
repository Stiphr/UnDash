<?php
/*
 * UnDash - the official Unraid GraphQL API (https://docs.unraid.net/API/), used by the Docker image.
 *
 * Every query and mutation below was checked against the API's own generated schema for API 4.37.7
 * (docs/unraid-api-schema-4.37.7.graphql, from github.com/unraid/api). The API key is sent in the x-api-key header from the
 * server only; it never reaches the browser.
 *
 * Permissions the key needs (Settings > Management Access > API Keys in Unraid):
 *   INFO, ARRAY, DISK, VMS, DOCKER: read            for the dashboard
 *   DOCKER, VMS: update                              for start / stop / restart / pause / update
 * What the API does not offer (so the Docker image does not either): server restart / shutdown, Unraid OS updates,
 * per-container CPU and memory over plain HTTP (only as a WebSocket subscription), VM hibernate, VM vCPU / memory details.
 *
 * Connection settings: UNRAID_API_URL and UNRAID_API_KEY in the environment, or Settings > Connection
 * (stored in secrets.json). The environment wins when both are set.
 */

function und_api_conf() {
  $sec = und_secrets();
  $url = getenv('UNRAID_API_URL') ?: (string)($sec['unraid']['url'] ?? '');
  $key = getenv('UNRAID_API_KEY') ?: (string)($sec['unraid']['key'] ?? '');
  $url = rtrim(trim($url), '/');
  if ($url !== '' && !preg_match('#/graphql$#', $url)) $url .= '/graphql';
  return ['url' => $url, 'key' => $key, 'from_env' => ['url' => (bool)getenv('UNRAID_API_URL'), 'key' => (bool)getenv('UNRAID_API_KEY')]];
}

/** Run one GraphQL operation. Returns ['data' => ...] or ['error' => readable message, 'auth' => bool]. */
function und_gql($query, array $vars = [], $timeout = 10) {
  $c = und_api_conf();
  if ($c['url'] === '' || $c['key'] === '') return ['error' => 'The Unraid API is not configured (Settings > Connection).', 'auth' => true];
  if (!preg_match('#^https?://#i', $c['url'])) return ['error' => 'The Unraid API address must start with http:// or https://.'];
  $r = und_http('POST', $c['url'], ['body' => json_encode(['query' => $query, 'variables' => (object)$vars]), 'timeout' => $timeout,
    'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'x-api-key' => $c['key']]]);
  if ($r['err'] !== '') return ['error' => 'Cannot reach the Unraid API at ' . und_redact($c['url']) . ': ' . und_redact($r['err'], [$c['key']])];
  if ($r['code'] === 401 || $r['code'] === 403) return ['error' => 'The Unraid API refused the API key (HTTP ' . $r['code'] . ').', 'auth' => true];
  if (!is_array($r['json'])) return ['error' => 'The Unraid API answered with HTTP ' . $r['code'] . ' and no JSON (is the address right?).'];
  $j = $r['json'];
  if (!empty($j['errors'])) {
    $e = $j['errors'][0];
    $code = (string)($e['extensions']['code'] ?? '');
    $msg = und_clip(und_redact((string)($e['message'] ?? 'error'), [$c['key']]), 300);
    $auth = in_array($code, ['UNAUTHENTICATED', 'FORBIDDEN'], true) || preg_match('/permission|forbidden|unauthori[sz]ed|api key/i', $msg);
    // partial data is still useful (for example everything but the UPS when the key lacks that permission)
    if (!empty($j['data']) && !$auth) return ['data' => $j['data'], 'partial' => $msg];
    $prefix = !$auth ? 'Unraid API: ' : ($code === 'FORBIDDEN' || preg_match('/forbidden|permission/i', $msg) ? 'The API key is missing a permission: ' : 'The Unraid API rejected the API key: ');
    return ['error' => $prefix . $msg, 'auth' => (bool)$auth, 'data' => $j['data'] ?? null];
  }
  return ['data' => $j['data'] ?? []];
}

/** Connection test for Settings: who answers, which Unraid / API version, and which parts the key may read. */
function und_api_test() {
  $r = und_gql('query { info { versions { core { unraid api } } os { hostname } } }', [], 8);
  if (isset($r['error'])) return ['ok' => false, 'error' => $r['error']];
  $v = $r['data']['info']['versions']['core'] ?? [];
  $out = ['ok' => true, 'unraid' => (string)($v['unraid'] ?? ''), 'api' => (string)($v['api'] ?? ''), 'hostname' => (string)($r['data']['info']['os']['hostname'] ?? ''), 'access' => []];
  foreach (['array' => 'query { array { state } }', 'docker' => 'query { docker { containers { id } } }', 'vms' => 'query { vms { domains { id } } }', 'metrics' => 'query { metrics { cpu { percentTotal } } }', 'ups' => 'query { upsDevices { id } }'] as $k => $q) {
    $t = und_gql($q, [], 8);
    $out['access'][$k] = isset($t['error']) ? $t['error'] : 'ok';
  }
  return $out;
}

/* ---------- reads (cached briefly so several open browsers share one request) ---------- */

function und_api_cached($name, $ttl, $fn) {
  $f = und_tmp('api-' . $name . '.json');
  $c = und_read_json($f, null);
  if (is_array($c) && microtime(true) - (float)$c['t'] < $ttl) return $c['v'];
  // one request reads; the others asking at the same moment wait for its answer instead of asking again
  $lk = @fopen($f . '.lock', 'c');
  if ($lk) {
    flock($lk, LOCK_EX);
    $c = und_read_json($f, null);
    if (is_array($c) && microtime(true) - (float)$c['t'] < $ttl) { fclose($lk); return $c['v']; }
  }
  $v = $fn();
  und_write_json($f, ['t' => microtime(true), 'v' => $v]);
  if ($lk) fclose($lk);
  return $v;
}

/*
 * Every read asks only for what the documented key permissions cover: one refused field fails the whole query when it
 * cannot be null, as vars does, so a key without that permission would leave the whole dashboard empty.
 *
 * Two parts of the API are slow (about 2 s each on a busy server, whatever fields are asked for): the network interface
 * list and the primary interface. In Docker, scripts/apipoll.php keeps them fresh in the background while someone has the
 * dashboard open, so page loads and refreshes never wait for them. Without it (tests, dev server) they are read inline.
 * name => [refresh every N seconds, oldest copy still shown, query]
 */
const UND_API_SLOW = [
  'ident' => [600, PHP_INT_MAX, 'query { info { cpu { brand threads } primaryNetwork { name ipAddress } os { hostname } versions { core { unraid } } } }'],
  'net'   => [2, 20, 'query { metrics { network { name rxSec txSec } } }'],
];

function und_api_poller_alive() { clearstatcache(); $b = @filemtime(und_tmp('apipoll.beat')); return $b && time() - $b < 30; }

/** A slow read (UND_API_SLOW): the background copy while the poller runs, else read now. Returns the und_gql result or null. */
function und_api_slow($name) {
  list($ttl, $max, $q) = UND_API_SLOW[$name];
  if (!und_api_poller_alive()) return und_api_cached($name, $ttl, function () use ($q) { return und_gql($q); });
  @touch(und_tmp('viewer'));
  $c = und_read_json(und_tmp('api-' . $name . '.json'), null);
  return is_array($c) && microtime(true) - (float)$c['t'] < $max ? $c['v'] : null;
}

/** One round of the background poller: refreshes what is due. Errors are not stored, so the last good copy stays. */
function und_api_poll_due() {
  foreach (UND_API_SLOW as $name => $x) {
    $f = und_tmp('api-' . $name . '.json');
    $c = und_read_json($f, null);
    if (is_array($c) && microtime(true) - (float)$c['t'] < $x[0]) continue;
    $r = und_gql($x[2]);
    if (!isset($r['error'])) und_write_json($f, ['t' => microtime(true), 'v' => $r]);
  }
}

/** Name, address and Unraid version for the page title, without waiting for a full system read. */
function und_api_identity() {
  $id = und_read_json(und_tmp('api-ident.json'), null);
  $d = is_array($id) ? ($id['v']['data'] ?? []) : (und_api_cached('name', 600, function () { return und_gql('query { info { os { hostname } versions { core { unraid } } } }', [], 5); })['data'] ?? []);
  $host = (string)parse_url(und_api_conf()['url'], PHP_URL_HOST);
  return ['name' => (string)($d['info']['os']['hostname'] ?? '') ?: $host, 'ip' => (string)($d['info']['primaryNetwork']['ipAddress'] ?? '') ?: $host,
          'os' => (string)($d['info']['versions']['core']['unraid'] ?? '')];
}

function und_api_bigint($v) { return is_numeric($v) ? (float)$v : 0.0; }

/** Overview data in the same shape as the plugin's und_system(). */
function und_api_system(array $cfg) {
  $q = 'query {
    info { os { hostname uptime kernel } versions { core { unraid } } }
    metrics {
      cpu { percentTotal cpus { percentTotal } }
      memory { total used available swapTotal swapUsed percentTotal }
      temperature { sensors { type current { value } } summary { hottest { current { value } } } }
    }
    array { state capacity { kilobytes { free used total } } parityCheckStatus { running progress status }
      parities { name device status temp numErrors fsSize fsFree fsType color isSpinning type }
      disks { name device status temp numErrors fsSize fsFree fsType color isSpinning type }
      caches { name device status temp numErrors fsSize fsFree fsType color isSpinning type } }
  }';
  $r = und_api_cached('system', 1, function () use ($q) { return und_gql($q); });
  if (isset($r['error']) && empty($r['data'])) return ['error' => $r['error'], 'auth' => !empty($r['auth'])];
  $d = $r['data']; $now = time();
  $m = $d['metrics'] ?? []; $info = $d['info'] ?? [];
  $ident = und_api_slow('ident')['data']['info'] ?? [];
  $mem = $m['memory'] ?? null;
  $total = und_api_bigint($mem['total'] ?? 0); $avail = und_api_bigint($mem['available'] ?? 0);
  $memOut = $mem ? ['total' => $total, 'used' => max(0, $total - $avail), 'free' => $avail, 'pct' => $total ? round(100 * ($total - $avail) / $total, 1) : null,
                    'swap_total' => und_api_bigint($mem['swapTotal'] ?? 0), 'swap_used' => und_api_bigint($mem['swapUsed'] ?? 0)] : ['total' => 0, 'used' => 0, 'free' => 0, 'pct' => null, 'swap_total' => 0, 'swap_used' => 0];
  $temp = null;
  foreach ((array)($m['temperature']['sensors'] ?? []) as $sn) if (($sn['type'] ?? '') === 'CPU_PACKAGE') { $temp = (float)$sn['current']['value']; break; }
  if ($temp === null) foreach ((array)($m['temperature']['sensors'] ?? []) as $sn) if (($sn['type'] ?? '') === 'CPU_CORE') $temp = max((float)$temp, (float)$sn['current']['value']);
  // the main interface: Unraid's primary network, else the first bridge / bond / ethernet
  $nets = (array)(und_api_slow('net')['data']['metrics']['network'] ?? []); $want = (string)($ident['primaryNetwork']['name'] ?? ''); $net = null;
  foreach ($nets as $n) if ($n['name'] === $want) $net = $n;
  if (!$net) foreach (['br0', 'bond0', 'eth0'] as $w) foreach ($nets as $n) if (!$net && $n['name'] === $w) $net = $n;
  $cpu = $m['cpu'] ?? null;
  $uptime = (string)($info['os']['uptime'] ?? '');
  $up = is_numeric($uptime) ? (int)$uptime : (($t = strtotime($uptime)) ? max(0, $now - $t) : 0);
  $hist = und_read_json(und_tmp('hist.json'), []);
  if ($cpu && (empty($hist) || $now - (int)end($hist)[0] >= 2)) { $hist[] = [$now, round((float)$cpu['percentTotal'], 1), $memOut['used'], $net ? (float)$net['rxSec'] : null, $net ? (float)$net['txSec'] : null]; $hist = array_slice($hist, -150); und_write_json(und_tmp('hist.json'), $hist); }
  $arr = und_api_array($d['array'] ?? []);
  return [
    'now' => $now, 'name' => (string)($info['os']['hostname'] ?? ''), 'ip' => (string)($ident['primaryNetwork']['ipAddress'] ?? ''),
    'os' => (string)($info['versions']['core']['unraid'] ?? ''), 'kernel' => (string)($info['os']['kernel'] ?? ''),
    'cpu' => ['pct' => $cpu ? round((float)$cpu['percentTotal'], 1) : null, 'cores' => $cpu ? array_map(function ($c) { return round((float)$c['percentTotal'], 1); }, (array)$cpu['cpus']) : [],
              'model' => (string)($ident['cpu']['brand'] ?? ''), 'threads' => (int)($ident['cpu']['threads'] ?? 0), 'temp' => $temp, 'load' => null],
    'mem' => $memOut, 'net' => ['rx' => $net ? (float)$net['rxSec'] : null, 'tx' => $net ? (float)$net['txSec'] : null, 'iface' => $net ? $net['name'] : ''],
    'disk' => null, 'uptime' => $up, 'array' => $arr, 'hist' => $hist,
    'os_update' => ['installed' => (string)($info['versions']['core']['unraid'] ?? ''), 'latest' => '', 'checked' => 0, 'error' => '', 'available' => false, 'staged' => '', 'job' => 'idle'],
    'partial' => $r['partial'] ?? null,
  ];
}

/** ArrayDisk -> the plugin's disk row shape. fsSize / fsFree are in KiB, as in Unraid's disks.ini. */
function und_api_disk_row(array $x, $kind) {
  $status = (string)($x['status'] ?? '');
  $color = strtolower(str_replace('_', '-', (string)($x['color'] ?? '')));
  $row = ['name' => (string)($x['name'] ?? ''), 'device' => (string)($x['device'] ?? ''), 'status' => $status, 'temp' => isset($x['temp']) && $x['temp'] !== null && (int)$x['temp'] > 0 ? (int)$x['temp'] : null,
          'size' => und_api_bigint($x['fsSize'] ?? 0) * 1024, 'free' => und_api_bigint($x['fsFree'] ?? 0) * 1024, 'fs' => (string)($x['fsType'] ?? ''), 'spun' => !isset($x['isSpinning']) || $x['isSpinning'] !== false,
          'errors' => (int)und_api_bigint($x['numErrors'] ?? 0), 'color' => $color, 'k' => $kind];
  $row['health'] = ($status !== 'DISK_OK' || strpos($color, 'red') === 0) ? 'bad' : ((strpos($color, 'yellow') === 0 || $row['errors'] > 0) ? 'warn' : 'ok');
  return $row;
}

function und_api_array(array $a) {
  $parity = []; $data = []; $pools = [];
  foreach ((array)($a['parities'] ?? []) as $x) if (($x['status'] ?? '') !== 'DISK_NP' && ($x['status'] ?? '') !== 'DISK_NP_DSBL') $parity[] = und_api_disk_row($x, 'Parity');
  foreach ((array)($a['disks'] ?? []) as $x) if (($x['status'] ?? '') !== 'DISK_NP') $data[] = und_api_disk_row($x, 'Array');
  foreach ((array)($a['caches'] ?? []) as $x) if (und_api_bigint($x['fsSize'] ?? 0) > 0) $pools[] = und_api_disk_row($x, 'Pool');
  $size = 0; $free = 0;
  foreach ($data as $x) { $size += $x['size']; $free += $x['free']; }
  $fl = und_disk_flags(array_merge($parity, $data, $pools));
  $pc = $a['parityCheckStatus'] ?? [];
  return ['state' => (string)($a['state'] ?? ''), 'resync' => !empty($pc['running']) ? 1 : 0, 'resync_pct' => !empty($pc['running']) && isset($pc['progress']) ? (float)$pc['progress'] : null,
          'resync_action' => !empty($pc['running']) ? 'Parity check' : '', 'size' => $size, 'free' => $free, 'disks' => count($data), 'parity' => count($parity),
          'pools' => $pools, 'mover' => false, '_rows' => ['parity' => $parity, 'array' => $data, 'pools' => $pools]] + $fl;
}

function und_api_storage() {
  $s = und_api_system(und_load_config());
  if (isset($s['error'])) return $s;
  return $s['array']['_rows'];
}

function und_api_ups() {
  // nominalPower and currentPower are in newer API versions only; an older one rejects the whole query, so ask again without them
  $r = und_api_cached('ups', 5, function () {
    $q = 'query { upsDevices { name model status battery { chargeLevel estimatedRuntime } power { loadPercentage%s } } }';
    $r = und_gql(sprintf($q, ' nominalPower currentPower'));
    return isset($r['error']) && empty($r['auth']) ? und_gql(sprintf($q, '')) : $r;
  });
  $u = $r['data']['upsDevices'][0] ?? null;
  if (!$u) return null;
  return ['source' => 'Unraid API', 'charge' => (float)$u['battery']['chargeLevel'], 'load' => (float)$u['power']['loadPercentage'], 'status' => (string)$u['status'],
          'runtime' => isset($u['battery']['estimatedRuntime']) ? (int)$u['battery']['estimatedRuntime'] : null, 'model' => (string)$u['model'],
          'nominal' => !empty($u['power']['nominalPower']) ? (int)$u['power']['nominalPower'] : null, 'watts' => !empty($u['power']['currentPower']) ? (float)$u['power']['currentPower'] : null];
}

/* ---------- Docker ---------- */

function und_api_containers($fresh = false) {
  $fn = function () { return und_gql('query { docker { containers { id names image state status autoStart iconUrl webUiUrl isUpdateAvailable isRebuildReady ports { privatePort publicPort type } hostConfig { networkMode } } } }', [], 15); };
  return $fresh ? $fn() : und_api_cached('containers', 1, $fn);
}

function und_api_docker_live() {
  $r = und_api_containers();
  if (isset($r['error']) && empty($r['data']['docker'])) return ['error' => $r['error']];
  $out = [];
  foreach ((array)($r['data']['docker']['containers'] ?? []) as $c) {
    $name = ltrim((string)($c['names'][0] ?? ''), '/');
    $status = (string)$c['status'];
    $health = preg_match('/\((healthy|unhealthy|health: starting)\)/', $status, $m) ? str_replace('health: ', '', $m[1]) : '';
    $state = strtolower((string)$c['state']);
    // a restart loop shows up as EXITED (or RUNNING) with a "Restarting (1) …" status
    if (stripos($status, 'restarting') === 0) $state = 'restarting';
    $ports = [];
    foreach ((array)($c['ports'] ?? []) as $p) if (!empty($p['publicPort'])) $ports[$p['privatePort'] . '/' . strtolower((string)$p['type'])] = (int)$p['publicPort'];
    $job = und_job(und_tmp('docker-update-' . $name . '.log'));
    $out[] = ['name' => $name, 'id' => (string)$c['id'], 'image' => (string)$c['image'], 'state' => $state, 'status' => $status, 'health' => $health, 'cpu' => null, 'mem' => null,
      'update' => $c['isUpdateAvailable'] === true || $c['isRebuildReady'] === true ? 'available' : ($c['isUpdateAvailable'] === false ? 'current' : 'unknown'),
      'icon' => preg_match('#^https?://#', (string)$c['iconUrl']) ? (string)$c['iconUrl'] : '', 'webui' => preg_match('#^https?://#', (string)$c['webUiUrl']) ? (string)$c['webUiUrl'] : '',
      'ports' => (object)$ports, 'network' => (string)($c['hostConfig']['networkMode'] ?? ''), 'autostart' => !empty($c['autoStart']), 'managed' => true, 'restarts' => null, 'started' => null,
      'exit' => preg_match('/^Exited \((\d+)\)/', $status, $m) ? (int)$m[1] : null,
      'job' => $job['state'] === 'running' ? 'updating' : ($job['state'] === 'done' && time() - (int)$job['at'] < 600 ? ($job['code'] === 0 ? 'updated' : 'update_failed') : '')];
  }
  usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  return ['containers' => $out, 'checking' => false, 'checked' => null];
}

function und_api_container_id($name) {
  $r = und_api_containers(true);
  foreach ((array)($r['data']['docker']['containers'] ?? []) as $c) if (ltrim((string)($c['names'][0] ?? ''), '/') === $name) return ['id' => (string)$c['id'], 'state' => strtolower((string)$c['state'])];
  return isset($r['error']) ? ['error' => $r['error']] : null;
}

function und_api_docker_action($name, $op) {
  $valid = ['start' => ['exited'], 'stop' => ['running', 'paused'], 'restart' => ['running', 'exited'], 'pause' => ['running'], 'unpause' => ['paused'], 'update' => null];
  if (!array_key_exists($op, $valid)) return ['error' => 'Unknown action.'];
  $c = und_api_container_id($name);
  if (!$c) return ['error' => 'There is no container named ' . $name . '.'];
  if (isset($c['error'])) return $c;
  if ($valid[$op] !== null && !in_array($c['state'], $valid[$op], true)) return ['error' => ucfirst($op) . ' is not possible while ' . $name . ' is ' . $c['state'] . '.'];
  $lock = und_lock('docker:' . $name);
  if (!$lock) return ['error' => 'Another action on ' . $name . ' is still running.'];
  if ($op === 'update') {
    // updateContainer pulls and recreates; it can take minutes, so it runs in the background like the plugin's update_container
    $log = und_tmp('docker-update-' . $name . '.log');
    if (und_job($log)['state'] === 'running') { und_unlock($lock); return ['error' => 'An update of ' . $name . ' is already running.']; }
    und_spawn([PHP_BINARY ?: 'php', '-d', 'auto_prepend_file=', UND_ROOT . '/scripts/apiop.php', 'update', $c['id']], $log);
    und_unlock($lock);
    und_history_add('docker', $name, 'update', true, 'started');
    return ['ok' => true, 'job' => 'running'];
  }
  $r = und_gql('mutation ($id: PrefixedID!) { docker { ' . $op . '(id: $id) { id state } } }', ['id' => $c['id']], 120);
  und_unlock($lock);
  @unlink(und_tmp('api-containers.json'));
  $ok = !isset($r['error']);
  und_history_add('docker', $name, $op, $ok, $ok ? '' : $r['error']);
  return $ok ? ['ok' => true] : ['error' => $r['error']];
}

function und_api_docker_logs($name, $lines) {
  $c = und_api_container_id($name);
  if (!$c) return ['error' => 'There is no container named ' . $name . '.'];
  if (isset($c['error'])) return $c;
  $r = und_gql('query ($id: PrefixedID!, $tail: Int) { docker { logs(id: $id, tail: $tail) { lines { timestamp message } } } }', ['id' => $c['id'], 'tail' => max(10, min(5000, (int)$lines))], 20);
  if (isset($r['error'])) return ['error' => $r['error']];
  $out = [];
  foreach ((array)($r['data']['docker']['logs']['lines'] ?? []) as $l) $out[] = substr((string)$l['timestamp'], 0, 19) . ' ' . rtrim((string)$l['message']);
  return ['log' => preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', implode("\n", $out))];
}

function und_api_docker_inspect($name) {
  $r = und_gql('query { docker { containers { id names image imageId created state status autoStart ports { ip privatePort publicPort type } hostConfig { networkMode } mounts networkSettings } } }', [], 15);
  if (isset($r['error'])) return ['error' => $r['error']];
  foreach ((array)($r['data']['docker']['containers'] ?? []) as $c) {
    if (ltrim((string)($c['names'][0] ?? ''), '/') !== $name) continue;
    $mounts = [];
    foreach ((array)($c['mounts'] ?? []) as $m) $mounts[] = ['src' => (string)($m['Source'] ?? $m['source'] ?? ''), 'dst' => (string)($m['Destination'] ?? $m['destination'] ?? ''), 'rw' => !empty($m['RW'] ?? $m['rw'] ?? false)];
    $nets = [];
    foreach ((array)($c['networkSettings']['Networks'] ?? []) as $n => $v) $nets[] = ['name' => $n, 'ip' => (string)($v['IPAddress'] ?? '')];
    $ports = [];
    foreach ((array)($c['ports'] ?? []) as $p) if (!empty($p['publicPort'])) $ports[] = ($p['ip'] ?? '') . ':' . $p['publicPort'] . ' → ' . $p['privatePort'] . '/' . strtolower((string)$p['type']);
    return ['name' => $name, 'id' => (string)$c['id'], 'image' => (string)$c['image'], 'image_id' => substr(str_replace('sha256:', '', (string)$c['imageId']), 0, 12), 'created' => (int)$c['created'] ?: null,
      'state' => strtolower((string)$c['state']), 'started' => null, 'finished' => null, 'exit' => 0, 'state_error' => '', 'oom' => false, 'restarts' => null, 'restart_policy' => '',
      'network_mode' => (string)($c['hostConfig']['networkMode'] ?? ''), 'networks' => $nets, 'ports' => $ports, 'mounts' => $mounts,
      'env' => [], 'labels' => new stdClass(), 'health' => [], 'privileged' => false, 'note' => 'Environment variables and health-check history are not available through the Unraid API.'];
  }
  return ['error' => 'There is no container named ' . $name . '.'];
}

/* ---------- VMs ---------- */

function und_api_vm_state($s) {
  $m = ['RUNNING' => 'running', 'IDLE' => 'running', 'PAUSED' => 'paused', 'SHUTOFF' => 'shut off', 'SHUTDOWN' => 'in shutdown', 'CRASHED' => 'crashed', 'PMSUSPENDED' => 'pmsuspended', 'NOSTATE' => 'unknown'];
  return $m[(string)$s] ?? strtolower((string)$s);
}

/** Actions the API offers (VmMutations: start, stop, pause, resume, forceStop, reboot) for each state. */
function und_api_vm_ops($state) {
  switch ($state) {
    case 'running': return ['stop', 'kill', 'restart', 'pause'];
    case 'paused': return ['resume', 'kill'];
    case 'in shutdown': case 'pmsuspended': return ['kill'];
    case 'shut off': case 'crashed': return ['start'];
  }
  return [];
}

function und_api_vm_live() {
  $r = und_api_cached('vms', 1, function () { return und_gql('query { vms { domains { id name state } } }'); });
  if (isset($r['error'])) return ['error' => preg_match('/permission/i', $r['error']) ? $r['error'] : 'VMs are not available: ' . $r['error']];
  $out = [];
  foreach ((array)($r['data']['vms']['domains'] ?? []) as $d) {
    $st = und_api_vm_state($d['state']);
    $out[] = ['name' => (string)$d['name'], 'id' => (string)$d['id'], 'state' => $st, 'ops' => und_api_vm_ops($st), 'cpus' => null, 'memory' => null, 'autostart' => null, 'os' => '', 'icon' => '', 'vnc' => '', 'agent' => false];
  }
  usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  return ['vms' => $out];
}

function und_api_vm_action($name, $op) {
  @unlink(und_tmp('api-vms.json'));
  $l = und_api_vm_live();
  if (isset($l['error'])) return $l;
  $vm = null; foreach ($l['vms'] as $v) if ($v['name'] === $name) $vm = $v;
  if (!$vm) return ['error' => 'There is no VM named ' . $name . '.'];
  if (!in_array($op, $vm['ops'], true)) return ['error' => ucfirst($op) . ' is not possible while ' . $name . ' is ' . $vm['state'] . ($op === 'hibernate' ? ' (hibernate is not offered by the Unraid API)' : '') . '.'];
  $map = ['start' => 'start', 'stop' => 'stop', 'kill' => 'forceStop', 'restart' => 'reboot', 'pause' => 'pause', 'resume' => 'resume'];
  $lock = und_lock('vm:' . $name);
  if (!$lock) return ['error' => 'Another action on ' . $name . ' is still running.'];
  $r = und_gql('mutation ($id: PrefixedID!) { vm { ' . $map[$op] . '(id: $id) } }', ['id' => $vm['id']], 90);
  und_unlock($lock);
  @unlink(und_tmp('api-vms.json'));
  $ok = !isset($r['error']) && !empty($r['data']['vm'][$map[$op]]);
  $err = $ok ? '' : ($r['error'] ?? 'Unraid did not confirm the action.');
  und_history_add('vm', $name, $op, $ok, $err);
  return $ok ? ['ok' => true] : ['error' => $err];
}
