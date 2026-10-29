<?php
/* Tests of the Docker version's Unraid API layer (UNDASH_MODE=api) against a fake GraphQL endpoint. php tests/api_mode_test.php */
error_reporting(E_ALL);
$SB = sys_get_temp_dir() . '/undash-apitest-' . getmypid();
define('UND_MODE', 'api'); define('UND_DIR', "$SB/config"); define('UND_TMP', "$SB/tmp"); define('UND_ROOT', dirname(__DIR__) . '/src');
@mkdir(UND_DIR, 0777, true);
$GLOBALS['SENT'] = []; $GLOBALS['ANSWER'] = null;
$GLOBALS['UND_HTTP_MOCK'] = function ($method, $url, $o) {
  $GLOBALS['SENT'][] = ['url' => $url, 'headers' => $o['headers'] ?? [], 'body' => json_decode((string)($o['body'] ?? ''), true)];
  $a = is_callable($GLOBALS['ANSWER']) ? call_user_func($GLOBALS['ANSWER'], json_decode((string)($o['body'] ?? ''), true)) : $GLOBALS['ANSWER'];
  return $a + ['code' => 200, 'body' => json_encode($a['json'] ?? null), 'err' => '', 'headers' => []];
};
$GLOBALS['UND_SPAWN_MOCK'] = function ($argv, $log) { $GLOBALS['SPAWNED'][] = $argv; };
require UND_ROOT . '/include/lib.php';
require UND_ROOT . '/include/auth.php';
$pass = 0; $fail = 0;
function t($n, $c, $d = '') { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "FAIL $n" . ($d !== '' ? " -- $d" : '') . "\n"; } }
function clear_cache() { foreach (glob(UND_TMP . '/api-*.json') ?: [] as $f) unlink($f); }

t('caps hide what the API cannot do', und_caps()['power'] === false && und_caps()['os_update'] === false && und_caps()['docker_stats'] === false && und_caps()['mode'] === 'api');
t('settings live in /config (UNDASH_CONFIG)', und_store_dir() === UND_DIR);
t('the port is not changeable from the UI', isset(und_save_loc(['PORT' => '9100'])['error']));
t('no API configured: clear error, no request', isset(und_gql('query { online }')['error']) && !$GLOBALS['SENT']);
und_save_secrets(['vars' => [], 'svc' => [], 'unraid' => ['url' => 'http://tower.local', 'key' => 'SECRETKEY123']]);
$GLOBALS['ANSWER'] = ['json' => ['data' => ['online' => true]]];
und_gql('query { online }');
$last = end($GLOBALS['SENT']);
t('/graphql is added to the address', $last['url'] === 'http://tower.local/graphql');
t('the key goes in the x-api-key header', ($last['headers']['x-api-key'] ?? '') === 'SECRETKEY123');
t('the key is not in the request body', strpos(json_encode($last['body']), 'SECRETKEY123') === false);
$GLOBALS['ANSWER'] = ['code' => 401, 'json' => ['message' => 'Unauthorized']];
$r = und_gql('query { online }');
t('a refused key is reported as such', isset($r['error']) && $r['auth'] === true && strpos($r['error'], 'refused') !== false);
$GLOBALS['ANSWER'] = ['json' => ['errors' => [['message' => 'Forbidden resource', 'extensions' => ['code' => 'FORBIDDEN']]], 'data' => null]];
$r = und_gql('query { upsDevices { id } }');
t('a missing permission is reported as such', isset($r['error']) && $r['auth'] && strpos($r['error'], 'permission') !== false);
$GLOBALS['ANSWER'] = ['code' => 0, 'err' => 'Connection timed out after 10001 milliseconds', 'json' => null];
$r = und_gql('query { online }');
t('a timeout is a readable error', isset($r['error']) && strpos($r['error'], 'Cannot reach') !== false);
$GLOBALS['ANSWER'] = ['code' => 0, 'err' => 'error with key SECRETKEY123 inside', 'json' => null];
t('errors never contain the key', strpos(und_gql('query { online }')['error'], 'SECRETKEY123') === false);

// containers
$state = ['plex' => 'RUNNING', 'old' => 'EXITED'];
$GLOBALS['ANSWER'] = function ($req) use (&$state) {
  $q = $req['query'];
  if (preg_match('/docker \{ (\w+)\(id: \$id\)/', $q, $m)) { $GLOBALS['MUT'][] = [$m[1], $req['variables']['id']]; return ['json' => ['data' => ['docker' => [$m[1] => ['id' => $req['variables']['id'], 'state' => 'RUNNING']]]]]; }
  if (preg_match('/vm \{ (\w+)\(id: \$id\)/', $q, $m)) { $GLOBALS['MUT'][] = [$m[1], $req['variables']['id']]; return ['json' => ['data' => ['vm' => [$m[1] => true]]]]; }
  if (strpos($q, 'vms {') !== false) return ['json' => ['data' => ['vms' => ['domains' => [['id' => 'v1', 'name' => 'Win', 'state' => 'RUNNING'], ['id' => 'v2', 'name' => 'Off', 'state' => 'SHUTOFF']]]]]];
  if (strpos($q, 'docker {') !== false) return ['json' => ['data' => ['docker' => ['containers' => [
    ['id' => 'c1', 'names' => ['/plex'], 'image' => 'plexinc/pms', 'state' => $state['plex'], 'status' => 'Up 2 hours (healthy)', 'autoStart' => true, 'iconUrl' => 'https://x/plex.png', 'webUiUrl' => 'http://10.0.0.2:32400/web', 'isUpdateAvailable' => true, 'isRebuildReady' => false, 'ports' => [], 'hostConfig' => ['networkMode' => 'bridge']],
    ['id' => 'c2', 'names' => ['/old'], 'image' => 'old/img', 'state' => $state['old'], 'status' => 'Exited (1) 3 days ago', 'autoStart' => false, 'iconUrl' => 'javascript:alert(1)', 'webUiUrl' => null, 'isUpdateAvailable' => null, 'isRebuildReady' => null, 'ports' => [], 'hostConfig' => null],
    ['id' => 'c3', 'names' => ['/gluetun'], 'image' => 'qmcgaw/gluetun', 'state' => 'EXITED', 'status' => 'Restarting (1) 45 seconds ago', 'autoStart' => true, 'iconUrl' => '', 'webUiUrl' => null, 'isUpdateAvailable' => false, 'isRebuildReady' => false, 'ports' => [], 'hostConfig' => ['networkMode' => 'bridge']]]]]]];
  return ['json' => ['data' => []]];
};
clear_cache();
$l = und_docker_live();
$by = []; foreach ($l['containers'] as $c) $by[$c['name']] = $c;
t('containers listed from the API', isset($by['plex'], $by['old']));
t('state, health and update mapped', $by['plex']['state'] === 'running' && $by['plex']['health'] === 'healthy' && $by['plex']['update'] === 'available' && $by['old']['update'] === 'unknown' && $by['old']['exit'] === 1);
t('unsafe icon URLs dropped', $by['old']['icon'] === '');
t('no CPU or memory is invented', $by['plex']['cpu'] === null && $by['plex']['mem'] === null);
$GLOBALS['MUT'] = [];
t('action that does not fit the state refused before calling the API', isset(und_docker_action('old', 'stop')['error']) && !$GLOBALS['MUT']);
t('stop calls docker.stop with the container id', isset(und_docker_action('plex', 'stop')['ok']) && $GLOBALS['MUT'][0] === ['stop', 'c1']);
t('unknown container refused', isset(und_docker_action('nope', 'start')['error']));
t('invalid name refused', isset(und_docker_action('a b', 'start')['error']));
$GLOBALS['SPAWNED'] = [];
t('update runs updateContainer in the background', isset(und_docker_action('plex', 'update')['ok']) && end($GLOBALS['SPAWNED'])[3] === UND_ROOT . '/scripts/apiop.php' && end($GLOBALS['SPAWNED'])[5] === 'c1');
// VMs
clear_cache(); $GLOBALS['MUT'] = [];
$v = und_vm_live();
t('VMs listed with API-only actions', $v['vms'][1]['name'] === 'Win' && $v['vms'][1]['ops'] === ['stop', 'kill', 'restart', 'pause'] && $v['vms'][0]['ops'] === ['start']);
t('hibernate refused (not in the API)', isset(und_vm_action('Win', 'hibernate')['error']) && !$GLOBALS['MUT']);
t('force stop maps to vm.forceStop', isset(und_vm_action('Win', 'kill')['ok']) && $GLOBALS['MUT'][0] === ['forceStop', 'v1']);
t('restart maps to vm.reboot', isset(und_vm_action('Win', 'restart')['ok']) && end($GLOBALS['MUT']) === ['reboot', 'v1']);
t('starting a running VM refused', isset(und_vm_action('Win', 'start')['error']));
// power and OS are not offered
t('server power refused in Docker mode', isset(und_power_prepare('reboot')['error']) && isset(und_power('reboot', 'x', 'y')['error']));
t('OS update refused in Docker mode', isset(und_os_update()['error']));
// secrets
$view = json_encode(und_secrets_view(und_secrets()));
t('the API key is not in the settings view', strpos($view, 'SECRETKEY123') === false);
$red = und_export('config')['text'];
t('the API key is not in the config.yaml export', strpos($red, 'SECRETKEY123') === false && strpos($red, 'tower.local') !== false);
t('the API key is in the .env export', strpos(und_export('env')['text'], 'SECRETKEY123') !== false);
// password sign-in
t('no password yet: setup code created', und_auth_kind() === 'none' && preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', und_setup_code_ensure()));
t('short passwords refused', isset(und_password_set('short')['error']));
t('password set, setup code removed', isset(und_password_set('a long password')['ok']) && !is_file(und_setup_file()) && und_auth_kind() === 'file');
t('password checked with a hash', und_password_ok('a long password') && !und_password_ok('wrong') && strpos(file_get_contents(und_store_dir() . '/.env'), 'a long password') === false && !empty(und_env()['UNDASH_PASSWORD_HASH']));
for ($i = 0; $i < 5; $i++) und_login_failed('10.0.0.9');
t('5 wrong passwords lock that address out', und_login_throttled('10.0.0.9') && !und_login_throttled('10.0.0.8'));
putenv('UNDASH_PASSWORD=from-env-password');
t('UNDASH_PASSWORD takes over', und_auth_kind() === 'env' && und_password_ok('from-env-password') && !und_password_ok('a long password'));
putenv('UNDASH_PASSWORD');
t('Unraid sessions and handoff links are ignored in Docker mode', und_unraid_logged_in() === false && und_handoff_use(str_repeat('a', 48)) === false);
$gl = array_values(array_filter(und_docker_live()['containers'] ?? [], function ($c) { return $c['name'] === 'gluetun'; }));
t('a restart loop reported as EXITED + "Restarting" is a restart loop', $gl && $gl[0]['state'] === 'restarting', json_encode($gl));
$ar = und_api_array(['state' => 'STARTED', 'parities' => [['name' => 'parity', 'status' => 'DISK_OK', 'temp' => 38, 'fsSize' => 0, 'numErrors' => 0]],
  'disks' => [['name' => 'disk1', 'status' => 'DISK_OK', 'temp' => 55, 'fsSize' => 100, 'fsFree' => 50, 'numErrors' => 0], ['name' => 'disk2', 'status' => 'DISK_DSBL', 'temp' => null, 'fsSize' => 100, 'fsFree' => 10, 'numErrors' => 3]], 'caches' => []]);
t('API-mode array names the hot, bad and erroring disks', $ar['hot_disks'] === [['name' => 'disk1', 'temp' => 55]] && $ar['bad_disks'] === ['disk2'] && $ar['error_disks'] === [['name' => 'disk2', 'errors' => 3]] && $ar['bad'] === 1, json_encode($ar));
t('ignore rules are stored in /config in Docker mode', !empty(und_ignore_add('type', 'os.available', '', 'permanent')['ok']) && strpos((string)@file_get_contents(UND_DIR . '/config.yaml'), 'os.available') !== false);
$id = und_server_identity();
t('in Docker mode the title shows the server (here: the API host, as the system read is not answered), never the container', $id['name'] === 'tower.local' && $id['ip'] === 'tower.local' && $id['name'] !== gethostname(), json_encode($id));
exec('rm -rf ' . escapeshellarg($SB));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
