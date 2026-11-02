<?php
/* Tests of the TrueNAS layer (include/truenas.php) against tests/fake_truenas.php, over a real TLS WebSocket. php tests/truenas_test.php */
error_reporting(E_ALL);
$SB = sys_get_temp_dir() . '/undash-tn-' . getmypid(); @mkdir("$SB/config", 0777, true);
define('UND_DIR', "$SB/config"); define('UND_TMP', "$SB/tmp"); define('UND_ROOT', dirname(__DIR__) . '/src');
$port = 9800 + getmypid() % 150; $log = "$SB/calls.log"; touch($log);
$proc = proc_open([PHP_BINARY, __DIR__ . '/fake_truenas.php', (string)$port, $log], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$SB/fake.err", 'w']], $pipes);
for ($i = 0; $i < 60 && !@fsockopen('127.0.0.1', $port, $en, $es, 0.1); $i++) usleep(100000);
$GLOBALS['UND_SPAWN_MOCK'] = function ($argv, $log) { $GLOBALS['SPAWNED'][] = $argv; };
require UND_ROOT . '/include/lib.php';
require UND_ROOT . '/include/auth.php';
$pass = 0; $fail = 0;
function t($n, $c, $d = '') { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "FAIL $n" . ($d !== '' ? " -- $d" : '') . "\n"; } }
function calls() { global $log; $l = file($log, FILE_IGNORE_NEW_LINES); file_put_contents($log, ''); return $l; }
function configure($url, $key = 'tn-key-1', $user = 'dash') { und_save_secrets(['vars' => [], 'svc' => [], 'unraid' => ['url' => $url, 'key' => $key, 'type' => 'truenas', 'user' => $user]]); }
$URL = "https://127.0.0.1:$port";

/* ---- settings ---- */
t('an Unraid server by default', !und_is_truenas() && und_caps()['platform'] === 'unraid' && und_caps()['docker_pause'] === true);
configure($URL);
$c = und_api_conf();
t('TrueNAS keeps its address as it is, with no /graphql', und_is_truenas() && $c['url'] === $URL && $c['user'] === 'dash', json_encode($c['url']));
t('the page is told what this server is and can do', und_caps()['platform_name'] === 'TrueNAS' && und_caps()['docker_pause'] === false && und_caps()['os_check'] === false);
t('settings keep the kind of server, and the key stays out of config.yaml', strpos((string)file_get_contents(UND_DIR . '/config.yaml'), 'truenas') !== false && strpos((string)file_get_contents(UND_DIR . '/config.yaml'), 'tn-key-1') === false);
t('the key is in the private .env', strpos((string)file_get_contents(UND_DIR . '/.env'), 'tn-key-1') !== false && (fileperms(UND_DIR . '/.env') & 0777) === 0600);
t('the user defaults to truenas_admin', (function () { $s = und_secrets(); $s['unraid']['user'] = ''; und_save_secrets($s); $u = und_api_conf()['user']; configure($GLOBALS['URL']); return $u; })() === 'truenas_admin');
putenv('TRUENAS_API_URL=https://nas.test'); putenv('TRUENAS_API_KEY=from-env'); putenv('TRUENAS_API_USER=envuser');
$c = und_api_conf();
t('the container settings win', $c['type'] === 'truenas' && $c['url'] === 'https://nas.test' && $c['key'] === 'from-env' && $c['user'] === 'envuser' && $c['from_env']['type'] === true);
putenv('TRUENAS_API_URL'); putenv('TRUENAS_API_KEY'); putenv('TRUENAS_API_USER');

/* ---- connecting ---- */
configure("http://127.0.0.1:$port");
$r = und_tn_open();
t('a plain http address is refused before anything is sent', strpos($r['error'] ?? '', 'https://') !== false && !calls());
configure($URL, 'wrong');
$r = und_tn_open();
t('a refused key is a clear message', strpos($r['error'] ?? '', 'refused the API key') !== false && !empty($r['auth']), json_encode($r));
t('the key is not in the message', strpos($r['error'] ?? '', 'wrong') === false);
$l = calls();
t('it logs in with API_KEY_PLAIN and the user, not the deprecated login', ($l[0] ?? '') === 'auth.login_ex API_KEY_PLAIN dash', json_encode($l));
configure("https://127.0.0.1:1");
t('an unreachable server is a clear message', strpos(und_tn_open(2)['error'] ?? '', 'Cannot reach TrueNAS') !== false);
configure($URL);
$cn = und_tn_open();
t('a good key logs in', !isset($cn['error']) && !$cn['dead'], json_encode($cn['error'] ?? ''));
$r = und_tn_call($cn, 'no.such.method');
t('a method error is readable', strpos($r['error'] ?? '', 'Method does not exist') !== false && empty($r['auth']));
und_tn_close($cn); calls();
$s = und_tn_system([]);
t('with nothing read yet the page waits, and that is no fault', !empty($s['none']) && strpos($s['error'], 'Connecting') !== false, json_encode($s));

/* ---- one round of the background connection ---- */
$cn = und_tn_open();
$sub = und_tn_call($cn, 'core.subscribe', ['reporting.realtime:{"interval": 3}']); und_tn_call($cn, 'core.subscribe', ['app.stats:{"interval": 5}']);
t('reports are subscribed to', ($sub['result'] ?? '') !== '');
und_tn_poll_round($cn, true);
und_tn_pump($cn, 0.5);
t('a large answer in pieces arrives whole, past the pings', count((array)und_tn_copy('apps')) === 3 && strlen((string)(und_tn_copy('apps')[0]['notes'] ?? '')) === 200000);
$l = implode("\n", calls());
t('pings are answered', strpos($l, 'pong') !== false);
t('disk temperatures are asked by disk name', strpos($l, 'disk.temperatures [["sda","sdb"],false]') !== false, $l);
t('disks are asked with their pools', strpos($l, 'disk.query [[],{"extra":{"pools":true}}]') !== false);
t('a fresh copy is not asked for again', (function () use (&$cn) { und_tn_poll_round($cn, true); return !preg_match('/pool\.query|system\.info/', implode("\n", calls())); })());
und_tn_close($cn);

/* ---- what the page gets ---- */
$s = und_system(und_load_config());
t('CPU total, per core and temperature', $s['cpu']['pct'] === 12.3 && $s['cpu']['cores'] === [10.0, 20.0] && $s['cpu']['temp'] === 48.0 && $s['cpu']['threads'] === 16, json_encode($s['cpu']));
t('memory used is what is not available', $s['mem']['total'] === 1000.0 && $s['mem']['used'] === 750.0 && $s['mem']['pct'] === 75.0);
t('the network is the busiest interface that is up', $s['net']['iface'] === 'enp1s0' && $s['net']['rx'] === 111.0 && $s['net']['tx'] === 222.0, json_encode($s['net']));
t('name, version and uptime', $s['name'] === 'nas' && $s['os'] === '25.10.1' && $s['uptime'] === 90061);
t('no Unraid update is offered', $s['os_update']['available'] === false);
$a = $s['array'];
t('pools have their sizes and an unhealthy one is bad', count($a['pools']) === 2 && $a['pools'][0]['size'] === 4e12 && $a['pools'][0]['free'] === 3e12 && $a['pools'][1]['health'] === 'bad' && $a['bad_disks'] === ['fast'], json_encode($a['bad_disks']));
t('the array is never reported as stopped', $a['state'] === 'STARTED' && $a['parity'] === 0);
t('a scrub shows like a parity check', $a['resync'] === 1 && $a['resync_pct'] === 41.3 && $a['resync_action'] === 'Scrub of tank');
t('a pool shows its hottest disk', $a['pools'][0]['temp'] === 52);
t('a hot disk is named', $a['hot'] === 1 && $a['hot_disks'][0]['name'] === 'sda', json_encode($a['hot_disks']));
$st = und_storage();
t('the disk list has no health the API does not give', count($st['array']) === 2 && $st['array'][0]['health'] === 'none' && $st['array'][1]['temp'] === null);
$id = und_server_identity();
t('the title bar', $id['name'] === 'nas' && $id['os'] === '25.10.1' && $id['ip'] === '127.0.0.1');
$al = und_alerts(und_load_config(), $s, null);
$tn = array_values(array_filter($al, function ($x) { return $x['cat'] === 'truenas.alert'; }));
t('TrueNAS\'s own alerts show as plain text; dismissed ones do not', count($tn) === 1 && $tn[0]['lv'] === 'bad' && $tn[0]['msg'] === 'Pool fast is DEGRADED & needs help', json_encode($al));
t('the unhealthy pool is named for TrueNAS', (bool)array_filter($al, function ($x) { return $x['cat'] === 'disk.bad' && strpos($x['msg'], 'Pool fast') === 0; }));
t('nothing mentions Unraid', strpos(json_encode($al), 'Unraid') === false, json_encode($al));

$d = und_docker_live();
$by = []; foreach ($d['containers'] as $c) $by[$c['name']] = $c;
t('apps come as containers, by name', array_keys($by) === ['broken', 'plex', 'sonarr']);
$p = $by['plex'];
t('a running app', $p['state'] === 'running' && $p['image'] === 'plexinc/pms-docker:latest' && $p['webui'] === 'http://192.168.1.20:32400/web' && $p['icon'] === 'https://icons.test/plex.png' && ((array)$p['ports'])['32400/tcp'] === 32400, json_encode($p));
t('its numbers come from the stats report', $p['cpu'] == 7 && $p['mem'] === 2048 && $d['stats'] === true);
t('a new version or new images are an update', $p['update'] === 'available' && $by['sonarr']['update'] === 'available' && $by['broken']['update'] === 'current');
t('a stopped app and a crashed one', $by['sonarr']['state'] === 'exited' && $by['broken']['state'] === 'exited' && $by['broken']['health'] === 'unhealthy');
t('the app summary counts them', und_docker_summary()['running'] === 1 && und_docker_summary()['stopped'] === 2 && und_docker_summary()['unhealthy'] === 1);

/* ---- actions ---- */
calls();
t('apps cannot pause', strpos(und_docker_action('plex', 'pause')['error'] ?? '', 'paused') !== false && !calls());
t('an action that does not fit is refused without asking', isset(und_docker_action('sonarr', 'stop')['error']) && isset(und_docker_action('plex', 'start')['error']) && !calls());
t('an unknown app is refused', isset(und_docker_action('nope', 'stop')['error']));
t('an unknown action is refused', isset(und_docker_action('plex', 'exec')['error']));
$r = und_docker_action('plex', 'stop');
t('stop', !empty($r['ok']) && strpos(implode("\n", calls()), 'app.stop ["plex"]') !== false, json_encode($r));
und_tn_session(function (&$cn) { $x = und_tn_call($cn, 'app.query'); und_write_json(und_tn_file('apps'), ['t' => microtime(true), 'v' => $x['result']]); return []; }); calls();
$r = und_docker_action('plex', 'restart'); $l = calls();
t('restart is a stop and a start, and pulls nothing', !empty($r['ok']) && strpos(implode(' ', $l), 'app.redeploy') === false && strpos(implode(' ', $l), 'app.stop ["plex"] app.start ["plex"]') !== false, json_encode($l));
und_tn_session(function (&$cn) { $x = und_tn_call($cn, 'app.query'); und_write_json(und_tn_file('apps'), ['t' => microtime(true), 'v' => $x['result']]); return []; }); calls();
$r = und_docker_action('sonarr', 'start');
t('a job answered by its number is followed to the end', !empty($r['ok']) && strpos(implode("\n", calls()), 'core.get_jobs') !== false, json_encode($r));
und_tn_session(function (&$cn) { $x = und_tn_call($cn, 'app.query'); und_write_json(und_tn_file('apps'), ['t' => microtime(true), 'v' => $x['result']]); return []; }); calls();
$r = und_docker_action('plex', 'update');
t('an update runs in the background', !empty($r['ok']) && $r['job'] === 'running' && in_array('tn-update', $GLOBALS['SPAWNED'][0], true) && !calls());
und_tn_update('plex'); t('a new version is an upgrade', strpos(implode("\n", calls()), 'app.upgrade ["plex"]') !== false);
und_tn_session(function (&$cn) { $x = und_tn_call($cn, 'app.query'); und_write_json(und_tn_file('apps'), ['t' => microtime(true), 'v' => $x['result']]); return []; }); calls();
und_tn_update('sonarr'); t('new images of the same version are a pull', strpos(implode("\n", calls()), 'app.pull_images ["sonarr"]') !== false);
und_tn_session(function (&$cn) { $x = und_tn_call($cn, 'app.query'); $x['result'][1]['name'] = 'refuse'; und_write_json(und_tn_file('apps'), ['t' => microtime(true), 'v' => $x['result']]); return []; }); calls();
$r = und_docker_action('refuse', 'start');
t('a refused key says so', strpos($r['error'] ?? '', 'missing a permission') !== false, json_encode($r));
t('and the buttons go away', und_api_can('docker') === false && und_caps()['docker_write'] === false);
@unlink(und_tmp('key-refused.json'));
$cn = und_tn_open(); $fails = und_tn_job($cn, 'app.fake', [], 2); und_tn_close($cn); calls();
t('a method that does not exist fails cleanly', isset($fails['error']));

und_tn_session(function (&$cn) { $x = und_tn_call($cn, 'app.query'); und_write_json(und_tn_file('apps'), ['t' => microtime(true), 'v' => $x['result']]); return []; }); calls();
$r = und_docker_logs('plex', 100);
t('logs: lines with their time, colours removed', ($r['log'] ?? '') === "2026-11-02T10:00:00 line one\nline two", json_encode($r));
$l = implode("\n", calls());
t('it follows the container, then stops following', strpos($l, 'app.container_log_follow:{\"app_name\":\"plex\",\"container_id\":\"c1\",\"tail_lines\":100}') !== false && strpos($l, 'core.unsubscribe') !== false, $l);
t('an app with no containers has no logs', (und_docker_logs('sonarr', 10)['log'] ?? 'x') === '');
$i = und_docker_inspect('plex');
t('details', $i['mounts'][0]['src'] === '/mnt/tank/plex' && $i['mounts'][0]['rw'] === true && $i['ports'][0] === '0.0.0.0:32400 → 32400/tcp' && $i['networks'][0]['name'] === 'ix-plex');

$cn = und_tn_open(); und_tn_poll_round($cn, true); $v = und_tn_call($cn, 'vm.query'); und_write_json(und_tn_file('vms'), ['t' => microtime(true), 'v' => $v['result']]); und_tn_close($cn); calls();
$l = und_vm_live();
t('VM states and what they offer', $l['vms'][0]['name'] === 'Off' && $l['vms'][0]['state'] === 'shut off' && $l['vms'][0]['ops'] === ['start'] && $l['vms'][1]['state'] === 'running' && in_array('kill', $l['vms'][1]['ops'], true), json_encode($l));
foreach (['stop' => 'vm.stop [3]', 'kill' => 'vm.poweroff [3]', 'restart' => 'vm.restart [3]', 'pause' => 'vm.suspend [3]'] as $op => $want) {
  $cn = und_tn_open(); $v = und_tn_call($cn, 'vm.query'); und_write_json(und_tn_file('vms'), ['t' => microtime(true), 'v' => $v['result']]); und_tn_close($cn); calls();
  $r = und_vm_action('Win 11', $op);
  t("VM $op", !empty($r['ok']) && strpos(implode("\n", calls()), $want) !== false, json_encode($r));
}
$cn = und_tn_open(); $v = und_tn_call($cn, 'vm.query'); und_write_json(und_tn_file('vms'), ['t' => microtime(true), 'v' => $v['result']]); und_tn_close($cn); calls();
t('a VM action that does not fit is refused without asking', isset(und_vm_action('Off', 'stop')['error']) && !calls());

$r = und_api_test();
t('the connection test says what it found', $r['ok'] === true && $r['hostname'] === 'nas' && $r['unraid'] === '25.10.1' && $r['access'] === ['pools' => 'ok', 'disks' => 'ok', 'apps' => 'ok', 'vms' => 'ok', 'alerts' => 'ok'], json_encode($r));
configure($URL, 'wrong');
$r = und_api_test();
t('and a refused key fails it', $r['ok'] === false && strpos($r['error'], 'refused') !== false, json_encode($r));
und_write_json(und_tn_file('status'), ['ok' => false, 'error' => 'TrueNAS refused the API key.', 'auth' => true, 't' => time()]);
und_write_json(und_tn_file('realtime'), ['t' => microtime(true) - 3600, 'v' => ['cpu' => []]]);
$s = und_system([]);
t('old numbers never pass for live ones: the fault shows instead', strpos($s['error'] ?? '', 'refused') !== false && !empty($s['auth']) && empty($s['none']), json_encode($s));
t('so an alert can be raised', (bool)array_filter(und_alerts(und_load_config(), $s, null), function ($x) { return $x['cat'] === 'api.error'; }));
t('no Unraid query is ever sent to TrueNAS', und_api_poll_due() === null && und_api_ups() === null && und_api_mover() === null && strpos(implode("\n", calls()), 'query {') === false);
t('the Unraid update check stays away', isset(und_os_check()['error']));

proc_terminate($proc);
$rm = function ($d) use (&$rm) { foreach (scandir($d) as $f) if ($f !== '.' && $f !== '..') { is_dir("$d/$f") ? $rm("$d/$f") : unlink("$d/$f"); } rmdir($d); };
$rm($SB);
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
