<?php
/*
 * UnDash test suite. No Unraid, Docker or network needed: commands and file paths go through the in-process seams of lib.php.
 *   php tests/run.php
 */
error_reporting(E_ALL);
$SANDBOX = sys_get_temp_dir() . '/undash-test-' . getmypid();
define('UND_DIR', "$SANDBOX/flash");
define('UND_TMP', "$SANDBOX/tmp");
define('UND_ROOT', dirname(__DIR__) . '/src');
@mkdir("$SANDBOX/fs/var/local/emhttp", 0777, true);
@mkdir("$SANDBOX/fs/mnt/user/appdata", 0777, true);
file_put_contents("$SANDBOX/fs/var/local/emhttp/var.ini", "NAME=\"Tower\"\nmdState=\"STARTED\"\nmdResync=\"0\"\n");
$GLOBALS['UND_FS'] = "$SANDBOX/fs";
$GLOBALS['CMDS'] = [];
$GLOBALS['UND_EXEC_MOCK'] = function ($argv) { $GLOBALS['CMDS'][] = $argv; return isset($GLOBALS['MOCK']) ? call_user_func($GLOBALS['MOCK'], $argv) : ['code' => 1, 'err' => 'not mocked']; };
$GLOBALS['SPAWNED'] = [];
$GLOBALS['UND_SPAWN_MOCK'] = function ($argv, $log) { $GLOBALS['SPAWNED'][] = $argv; };
require UND_ROOT . '/include/lib.php';
require UND_ROOT . '/include/auth.php';

$pass = 0; $fail = 0; $group = '';
function t($name, $cond, $detail = '') { global $pass, $fail, $group; if ($cond) { $pass++; } else { $fail++; echo "FAIL [$group] $name" . ($detail !== '' ? " -- $detail" : '') . "\n"; } }
function group($g) { global $group; $group = $g; }
function rmrf($d) { if (!is_dir($d)) return; foreach (scandir($d) as $f) if ($f !== '.' && $f !== '..') { is_dir("$d/$f") && !is_link("$d/$f") ? rmrf("$d/$f") : unlink("$d/$f"); } rmdir($d); }
function reset_store() { global $SANDBOX; rmrf(UND_DIR); rmrf("$SANDBOX/fs/mnt/user/appdata/undash"); rmrf(UND_TMP); @mkdir(UND_DIR, 0777, true); }

/* ---------------- configuration ---------------- */
group('config');
reset_store();
$c = und_load_config();
t('defaults have schema 4', $c['schema'] === 4);
t('containers show the running ones in a grid by default', $c['docker']['filter'] === 'running' && $c['docker']['layout'] === 'grid');
t('shortcut links are off by default', $c['apps']['links'] === false);
t('default refresh is 2 s', $c['general']['refresh'] === 2);
t('unknown themes fall back to default', und_normalize(['groups' => [], 'appearance' => ['theme' => 'midnight']])['appearance']['theme'] === 'default');
t('the new themes are accepted', und_normalize(['groups' => [], 'appearance' => ['theme' => 'catppuccin', 'mode' => 'system']])['appearance'] ['theme'] === 'catppuccin'
  && und_normalize(['groups' => [], 'appearance' => ['theme' => 'fluent']])['appearance']['theme'] === 'fluent');
t('mode is dark, light or system', und_normalize(['groups' => [], 'appearance' => ['mode' => 'neon']])['appearance']['mode'] === 'dark'
  && und_normalize(['groups' => [], 'appearance' => ['mode' => 'system']])['appearance']['mode'] === 'system');
$la = und_normalize(['groups' => [], 'appearance' => ['theme' => 'light', 'accent' => '#123456']])['appearance'];
t('the old light theme becomes default in light mode, accent kept', $la['theme'] === 'default' && $la['mode'] === 'light' && $la['accent'] === '#123456');
t('accent must be a hex colour or empty (theme accent)', und_normalize(['groups' => [], 'appearance' => ['accent' => 'red;}']])['appearance']['accent'] === ''
  && und_normalize(['groups' => [], 'appearance' => ['accent' => '']])['appearance']['accent'] === ''
  && und_load_config()['appearance']['accent'] === '');
t('refresh is clamped', und_normalize(['groups' => [], 'general' => ['refresh' => 0]])['general']['refresh'] === 1 && und_normalize(['groups' => [], 'general' => ['refresh' => 99999]])['general']['refresh'] === 300);
t('disk path cannot leave /mnt', und_normalize(['groups' => [], 'general' => ['disk_path' => '/mnt/../etc']])['general']['disk_path'] === '/mnt/user');
t('unknown keys are dropped', !isset(und_normalize(['groups' => [], 'general' => ['evil' => 1]])['general']['evil']));
t('wallpaper cannot break out of url()', und_normalize(['groups' => [], 'appearance' => ['background' => 'x") ; background:url(http://e']])['appearance']['background'] === '');
$n = und_normalize(['groups' => [], 'dashboard' => ['sections' => [['id' => 'docker', 'visible' => false], ['id' => 'nope'], ['id' => 'docker']]]]);
t('sections keep order, drop unknown and duplicates, append missing', $n['dashboard']['sections'][0]['id'] === 'docker' && !$n['dashboard']['sections'][0]['visible'] && count($n['dashboard']['sections']) === count(und_section_ids()));
$n = und_normalize(['groups' => [], 'docker' => ['items' => ['plex' => ['label' => 'Plex!', 'favorite' => 1], "bad\nname" => ['hidden' => 1], 'empty' => []]]]);
t('container preferences kept by name', $n['docker']['items']['plex']['label'] === 'Plex!' && $n['docker']['items']['plex']['favorite'] === true);
t('control characters in names rejected', !isset($n['docker']['items']["bad\nname"]));
t('empty preferences dropped', !isset($n['docker']['items']['empty']));

group('migration');
$v1 = ['general' => ['title' => 'R1ZE', 'refresh_system' => 5, 'theme' => 'midnight', 'background' => '/bg.jpg', 'show_resources' => true],
       'groups' => [['id' => 'aaaaaaaa', 'name' => 'System', 'services' => [['id' => 'bbbbbbbb', 'type' => 'cpu', 'name' => 'CPU'], ['id' => 'cccccccc', 'type' => 'memory', 'name' => 'Mem']]],
                    ['id' => 'dddddddd', 'name' => 'Arr', 'services' => [['id' => 'eeeeeeee', 'type' => 'radarr', 'name' => 'Radarr', 'url' => 'http://x']]]]];
$m = und_normalize(und_migrate($v1));
t('v1 title kept', $m['general']['title'] === 'R1ZE');
t('v1 midnight becomes default theme', $m['appearance']['theme'] === 'default');
t('v1 wallpaper moved to appearance', $m['appearance']['background'] === '/bg.jpg');
t('v1 Glances-style group removed, others kept', count($m['groups']) === 1 && $m['groups'][0]['name'] === 'Arr' && $m['groups'][0]['services'][0]['id'] === 'eeeeeeee');
t('migration result is schema 4', $m['schema'] === 4);
$v3 = ['schema' => 3, 'groups' => [], 'docker' => ['layout' => 'list', 'selected' => ['plex', '../bad', 'radarr']], 'dashboard' => ['sections' => [['id' => 'overview'], ['id' => 'arr'], ['id' => 'downloads'], ['id' => 'docker'], ['id' => 'vms'], ['id' => 'apps', 'collapsed' => true]]]];
$m4 = und_normalize(und_migrate($v3));
$ids4 = array_column($m4['dashboard']['sections'], 'id');
t('v3: apps move right below downloads (after the newer Now playing and Requests)', array_slice($ids4, array_search('downloads', $ids4), 4) === ['downloads', 'playing', 'requests', 'apps'] && array_search('apps', $ids4) < array_search('docker', $ids4));
$old = und_normalize(['groups' => [], 'dashboard' => ['sections' => [['id' => 'overview'], ['id' => 'downloads'], ['id' => 'docker'], ['id' => 'vms'], ['id' => 'apps']]]])['dashboard']['sections'];
$oi = array_column($old, 'id');
t('new sections land after their neighbours in an older layout', array_slice($oi, 0, 6) === ['overview', 'downloads', 'playing', 'requests', 'docker', 'vms'] && $oi[6] === 'health');
t('Now playing and Requests are half width, Endpoint health full', $old[2]['width'] === 'half' && $old[3]['width'] === 'half' && $old[6]['width'] === 'full');
$kept = und_normalize(['groups' => [], 'dashboard' => ['sections' => [['id' => 'health', 'visible' => false], ['id' => 'overview']]]])['dashboard']['sections'];
t('a saved position of a new section is kept', $kept[0]['id'] === 'health' && $kept[0]['visible'] === false);
t('v3: apps keep their collapsed state', $m4['dashboard']['sections'][array_search('apps', $ids4)]['collapsed'] === true);
t('v3: containers become a compact grid of running ones', $m4['docker']['layout'] === 'grid' && $m4['docker']['filter'] === 'running' && $m4['docker']['show'] === ['cpu', 'memory', 'update']);
t('selected container names are validated', $m4['docker']['selected'] === ['plex', 'radarr']);
t('container columns are clamped', und_normalize(['groups' => [], 'docker' => ['columns' => 9]])['docker']['columns'] === 4);
$v2 = ['schema' => 2, 'groups' => [], 'dashboard' => ['sections' => [['id' => 'overview', 'visible' => true], ['id' => 'favorites', 'visible' => false], ['id' => 'metrics', 'visible' => true], ['id' => 'docker', 'visible' => false], ['id' => 'storage', 'visible' => true], ['id' => 'services', 'visible' => true, 'collapsed' => true]]]];
$m3 = und_normalize(und_migrate($v2));
$ids = array_column($m3['dashboard']['sections'], 'id');
t('v2 metrics and storage sections are folded into the overview', !in_array('metrics', $ids, true) && !in_array('storage', $ids, true));
t('v2 services becomes apps and keeps its state', $m3['dashboard']['sections'][array_search('apps', $ids)]['collapsed'] === true);
t('media, arr and downloads follow favorites', array_slice($ids, 0, 5) === ['overview', 'favorites', 'media', 'arr', 'downloads']);
$sv = und_normalize(['groups' => [], 'dashboard' => ['sections' => [['id' => 'launcher', 'visible' => true], ['id' => 'overview', 'visible' => true]]]])['dashboard']['sections'];
t('a saved launcher section (2026.10.31c-e) is dropped', !in_array('launcher', array_column($sv, 'id'), true) && $sv[0]['id'] === 'overview');
t('v2 hidden sections stay hidden', $m3['dashboard']['sections'][array_search('docker', $ids)]['visible'] === false);
t('density accepts expanded', und_normalize(['groups' => [], 'appearance' => ['density' => 'expanded']])['appearance']['density'] === 'expanded');

group('storage location');
reset_store();
file_put_contents(UND_DIR . '/config.json', json_encode($v1));
file_put_contents(UND_DIR . '/secrets.json', json_encode(['vars' => ['A' => '1'], 'svc' => []]));
$dir = und_store_dir();
t('configured appdata directory is used when its parent exists', $dir === "$SANDBOX/fs/mnt/user/appdata/undash", $dir);
t('existing flash settings are copied into it', is_file("$dir/config.json") && is_file("$dir/secrets.json"));
file_put_contents("$dir/config.json", json_encode(['groups' => [], 'general' => ['title' => 'appdata copy']]));
und_migrate_into($dir);
t('migration never overwrites an existing file', json_decode(file_get_contents("$dir/config.json"), true)['general']['title'] === 'appdata copy');
$cfg = und_load_config(); $cfg['general']['title'] = 'Saved';
t('save works', und_save_config($cfg));
t('save writes the configuration directory', json_decode(file_get_contents("$dir/config.json"), true)['general']['title'] === 'Saved');
t('save mirrors to the flash drive', json_decode(file_get_contents(UND_DIR . '/config.json'), true)['general']['title'] === 'Saved');
t('a backup was taken before saving', count(und_backups()) >= 1);
t('invalid configuration directory refused', isset(und_save_loc(['CONFIG_DIR' => '/etc/undash'])['error']) && isset(und_save_loc(['CONFIG_DIR' => '/mnt/user/../../etc'])['error']));
t('invalid port refused', isset(und_save_loc(['PORT' => '80'])['error']) && isset(und_save_loc(['PORT' => '70000'])['error']));
t('valid port saved', !isset(und_save_loc(['PORT' => '9100'])['error']) && und_loc()['PORT'] === '9100');
und_save_loc(['PORT' => '9099']);
t('broken undash.cfg falls back to defaults', (function () { file_put_contents(UND_DIR . '/undash.cfg', "garbage\x00\nPORT=abc\n"); $l = und_loc(); und_save_loc(['PORT' => '9099', 'CONFIG_DIR' => '/mnt/user/appdata/undash', 'BIND' => '0.0.0.0', 'STANDALONE' => 'yes']); return $l['PORT'] === '9099' && $l['CONFIG_DIR'] === '/mnt/user/appdata/undash'; })());

group('backups');
$cfg = und_load_config(); $cfg['general']['title'] = 'Before';
und_save_config($cfg);
$cfg['general']['title'] = 'After';
und_save_config($cfg);
$b = und_backups();
t('backups are listed newest first', count($b) >= 2 && $b[0]['created'] >= $b[count($b) - 1]['created']);
$target = null; foreach (und_backups() as $x) { $j = und_read_json(und_backup_dir() . '/' . $x['id'] . '.json', []); if (($j['config']['general']['title'] ?? '') === 'Before') { $target = $x['id']; break; } }
t('the backup holding the earlier state exists', $target !== null);
t('restore works', isset(und_restore($target)['ok']) && und_load_config()['general']['title'] === 'Before');
t('restore refuses odd ids', isset(und_restore('../../etc/passwd')['error']));
for ($i = 0; $i < 20; $i++) und_backup('x' . $i);
t('only 15 backups are kept', count(glob(und_backup_dir() . '/*.json')) === 15);

/* ---------------- custom CSS ---------------- */
group('custom css');
$css = und_sanitize_css("@import url(http://evil/x.css);\n.card{background:url(http://evil/t.png)}\n</style><script>alert(1)</script>\n.a{width:expression(alert(1))}\n.b{background:url(javascript:alert(1))}\n.ok{background:url(data:image/png;base64,AAA)}");
t('@import removed', stripos($css, '@import url') === false);
t('closing style tag removed', stripos($css, '</style') === false);
t('expression() removed', stripos($css, 'expression(') === false);
t('javascript: removed', stripos($css, 'javascript:') === false);
t('http url() replaced', stripos($css, 'http://evil/t.png') === false);
t('data: image url kept', strpos($css, 'data:image/png') !== false);
t('size capped at 100 KB', strlen(und_sanitize_css(str_repeat('a', 300000))) === 102400);
und_save_css('.card{color:red}');
t('css persisted', und_load_css() === '.card{color:red}');
und_save_css('');
t('css reset', und_load_css() === '');

/* ---------------- secrets ---------------- */
group('secrets');
$view = und_secrets_view(['vars' => ['SERVER_IP' => '10.0.0.2', 'RADARR_API_KEY' => 'abc123secret'], 'svc' => ['aaaaaaaa' => ['key' => 'k-secret-value', 'username' => 'bob', 'password' => '{{PW}}']]]);
$vj = json_encode($view);
t('secret variables never reach the browser', strpos($vj, 'abc123secret') === false);
t('stored API keys never reach the browser', strpos($vj, 'k-secret-value') === false);
t('plain variables are shown', strpos($vj, '10.0.0.2') !== false);
t('variable references are shown', strpos($vj, '{{PW}}') !== false);
$merged = und_secrets_merge(['vars' => ['RADARR_API_KEY' => 'abc123secret'], 'svc' => ['aaaaaaaa' => ['key' => 'old']]], [['name' => 'RADARR_API_KEY', 'value' => UND_KEEP]], ['aaaaaaaa' => ['key' => UND_KEEP], 'zzzzzzzz' => ['key' => 'x']], ['aaaaaaaa']);
t('KEEP keeps stored values', $merged['vars']['RADARR_API_KEY'] === 'abc123secret' && $merged['svc']['aaaaaaaa']['key'] === 'old');
t('credentials of removed widgets are dropped', !isset($merged['svc']['zzzzzzzz']));
und_save_secrets(['vars' => ['SERVER_IP' => '10.0.0.2', 'RADARR_API_KEY' => 'abc123secret'], 'svc' => []]);
$lv = und_layout_view(['groups' => [['services' => [['href' => 'http://x/?t={{RADARR_API_KEY}}'], ['href' => 'javascript:alert(1)'], ['href' => 'http://{{SERVER_IP}}:1']]]]]);
$hs = array_column($lv['groups'][0]['services'], 'href_resolved');
t('a link carrying a secret variable is not resolved for the browser', $hs[0] === '');
t('javascript: links are dropped', $hs[1] === '');
t('plain variables in links are resolved', $hs[2] === 'http://10.0.0.2:1');

/* ---------------- export / import ---------------- */
group('export');
reset_store();
$cfg = und_default_config();
$cfg['groups'][] = ['id' => 'abcdef01', 'name' => 'Apps', 'columns' => 3, 'header' => true, 'services' => [
  ['id' => 'abcdef02', 'name' => 'Radarr', 'type' => 'radarr', 'url' => 'http://admin:hunter2@10.0.0.2:7878/?apikey=PLAINKEY123&x=1', 'href' => 'http://10.0.0.2:7878', 'fields' => [], 'options' => []],
  ['id' => 'abcdef03', 'name' => 'Custom', 'type' => 'customapi', 'url' => 'http://{{SERVER_IP}}/api?token={{TOKEN_VAR}}', 'href' => '', 'fields' => [], 'options' => ['headers' => ['X-Api-Key' => 'HEADERSECRET', 'X-Ref' => '{{SOME_KEY}}']]]]];
und_save_config($cfg);
und_save_secrets(['vars' => ['SERVER_IP' => '10.0.0.2', 'RADARR_API_KEY' => 'VARSECRET1', 'QBIT_PASSWORD' => 'VARSECRET2'], 'svc' => ['abcdef02' => ['key' => 'SVCSECRET', 'username' => 'u', 'password' => 'PWSECRET']]]);
und_save_css('.card{color:red}');
$red = json_encode(und_export('redacted'));
foreach (['VARSECRET1', 'VARSECRET2', 'SVCSECRET', 'PWSECRET', 'hunter2', 'PLAINKEY123', 'HEADERSECRET'] as $s) t("redacted export has no $s", strpos($red, $s) === false);
t('redacted export keeps layout', strpos($red, '"Radarr"') !== false && strpos($red, '"Apps"') !== false);
t('redacted export keeps non-secret variables', strpos($red, '10.0.0.2') !== false);
t('redacted export keeps variable references', strpos($red, '{{TOKEN_VAR}}') !== false && strpos($red, '{{SOME_KEY}}') !== false);
t('redacted export keeps css', strpos($red, 'color:red') !== false);
t('redacted export marks its kind', json_decode($red, true)['kind'] === 'redacted');
$full = json_encode(und_export('full'));
t('full export has the secrets', strpos($full, 'SVCSECRET') !== false && strpos($full, 'VARSECRET1') !== false);
t('exports contain nothing but settings', count(array_diff(array_keys(json_decode($full, true)), ['undash_export', 'schema', 'kind', 'created', 'version', 'config', 'css', 'vars', 'secrets', 'unraid'])) === 0);

group('import');
t('empty file refused', isset(und_import_parse('')['error']));
t('invalid JSON refused', isset(und_import_parse('{nope')['error']));
t('too large refused', isset(und_import_parse(str_repeat(' ', 2097153))['error']));
t('random JSON refused', isset(und_import_parse('{"a":1}')['error']));
t('newer schema refused', isset(und_import_parse(json_encode(['undash_export' => 1, 'schema' => 99, 'config' => ['groups' => []]]))['error']));
t('export without layout refused', isset(und_import_parse(json_encode(['undash_export' => 1, 'schema' => 2, 'config' => ['x' => 1]]))['error']));
t('malformed variables refused', isset(und_import_parse(json_encode(['undash_export' => 1, 'schema' => 2, 'config' => ['groups' => []], 'vars' => ['bad name' => 'x']]))['error']));
t('malformed credentials refused', isset(und_import_parse(json_encode(['undash_export' => 1, 'schema' => 2, 'config' => ['groups' => []], 'secrets' => ['../x' => ['key' => 1]]]))['error']));
t('deeply nested JSON refused', isset(und_import_parse(str_repeat('[', 200) . str_repeat(']', 200))['error']));
$p = und_import_parse(json_encode(['undash_export' => 1, 'schema' => 2, 'kind' => 'redacted', 'config' => ['groups' => [['name' => 'G', 'services' => [['type' => 'radarr', 'name' => 'R'], ['type' => 'rm -rf', 'name' => 'X']]]]]]));
t('unknown widget types skipped and counted', $p['summary']['widgets'] === 1 && $p['summary']['skipped'] === 1);
// redacted import keeps stored credentials of widgets with the same id
$redArr = json_decode($red, true);
$redArr['config']['general']['title'] = 'Imported';
$r = und_import_apply(json_encode($redArr));
t('redacted import applied', isset($r['ok']) && und_load_config()['general']['title'] === 'Imported');
t('redacted import kept stored credentials', und_secrets()['svc']['abcdef02']['key'] === 'SVCSECRET');
t('redacted import kept secret variable values it left empty', und_secrets()['vars']['RADARR_API_KEY'] === 'VARSECRET1');
t('import took a backup first', (function () { foreach (und_backups() as $b) if ($b['reason'] === 'before import') return true; return false; })());
$fullArr = json_decode($full, true); $fullArr['secrets']['abcdef02']['key'] = 'NEWKEY';
und_import_apply(json_encode($fullArr));
t('full import replaces credentials', und_secrets()['svc']['abcdef02']['key'] === 'NEWKEY');
t('layout-only (old export) files are accepted', !isset(und_import_parse(json_encode(['groups' => [], 'general' => ['title' => 'x']]))['error']));

/* ---------------- Docker ---------------- */
group('docker');
$GLOBALS['MOCK'] = function ($argv) {
  if ($argv[1] === 'ps') return ['out' => "1111|plex|running|Up 2 hours (healthy)|plexinc/pms\n2222|radarr|exited|Exited (0) 1 hour ago|lscr.io/radarr\n"];
  if (in_array($argv[1], ['start', 'stop', 'restart', 'pause', 'unpause'], true)) return ['out' => $argv[2]];
  if ($argv[1] === 'inspect') return ['out' => '[]'];
  return ['code' => 1];
};
t('invalid container name refused', isset(und_docker_action('../x', 'stop')['error']) && isset(und_docker_action('a;rm -rf /', 'stop')['error']));
t('unknown action refused', isset(und_docker_action('plex', 'exec')['error']));
t('unknown container refused', isset(und_docker_action('nope', 'stop')['error']));
t('action that does not fit the state refused', isset(und_docker_action('radarr', 'stop')['error']) && isset(und_docker_action('plex', 'start')['error']));
$GLOBALS['CMDS'] = [];
t('valid action runs', isset(und_docker_action('plex', 'restart')['ok']));
t('it runs docker restart <name> without a shell', end($GLOBALS['CMDS']) === [und_docker_bin(), 'restart', 'plex']);
$lock = und_lock('docker:plex');
t('a second action on the same container is refused while one runs', isset(und_docker_action('plex', 'restart')['error']));
und_unlock($lock);
$GLOBALS['SPAWNED'] = [];
t('update goes through Unraid update_container', isset(und_docker_action('radarr', 'update')['ok']) && $GLOBALS['SPAWNED'][0] === [UND_DM . '/scripts/update_container', 'radarr']);
t('logs refuse odd names', isset(und_docker_logs('-f --since', 10)['error']));
t('history records actions', count(und_history()) >= 2 && und_history()[0]['target'] === 'radarr');
$u = und_docker_update_state(['img:latest' => ['status' => 'undef']], 'img');
t('an unchecked image is "unknown", never "current"', $u === 'unknown' && und_docker_update_state([], 'x') === 'unknown' && und_docker_update_state(['x:latest' => ['status' => 'true']], 'x') === 'current');
t('health is parsed from docker ps', und_docker_ps()['ps']['plex']['health'] === 'healthy');
$GLOBALS['MOCK'] = function ($argv) { return $argv[1] === 'inspect' ? ['out' => json_encode([['Id' => 'abc', 'Config' => ['Image' => 'x', 'Env' => ['A=1']], 'State' => ['Status' => 'running', 'Error' => ''], 'HostConfig' => []]])] : ['code' => 1]; };
$ins = und_docker_inspect('plex');
t('container details do not look like an error (regression)', !isset($ins['error']) && $ins['state_error'] === '' && $ins['env'] === ['A']);

/* ---------------- VMs ---------------- */
group('vms');
$GLOBALS['MOCK'] = function ($argv) {
  if ($argv[1] === 'list') return ['out' => " Id   Name         State\n----------------------------\n 2    Windows 10   running\n -    Home Assistant   shut off\n 3    Paused VM    paused\n"];
  if ($argv[1] === 'dominfo') return ['out' => "CPU(s): 4\nMax memory: 8388608 KiB\nAutostart: enable\n"];
  if ($argv[1] === 'dumpxml') return ['out' => $argv[2] === 'Windows 10' ? '<x><channel><target name="org.qemu.guest_agent.0"/></channel></x>' : '<x/>'];
  return ['out' => ''];
};
$st = und_vm_states();
t('virsh list with spaces in names is parsed', $st === ['Windows 10' => 'running', 'Home Assistant' => 'shut off', 'Paused VM' => 'paused'], json_encode($st));
t('running VM offers shutdown, force stop, restart, pause, hibernate with agent', und_vm_valid_ops('running', true) === ['stop', 'kill', 'restart', 'pause', 'hibernate']);
t('no hibernate without the guest agent', !in_array('hibernate', und_vm_valid_ops('running', false), true));
t('stopped VM can only start', und_vm_valid_ops('shut off', false) === ['start']);
t('invalid op for the state refused', isset(und_vm_action('Home Assistant', 'stop')['error']) && isset(und_vm_action('Windows 10', 'start')['error']));
t('unknown VM refused', isset(und_vm_action('Nope', 'start')['error']));
$GLOBALS['CMDS'] = [];
t('graceful shutdown uses virsh shutdown', isset(und_vm_action('Windows 10', 'stop')['ok']) && end($GLOBALS['CMDS']) === [und_virsh(), 'shutdown', 'Windows 10']);
@unlink(und_tmp('vminfo.json'));
t('hibernate uses dompmsuspend disk', isset(und_vm_action('Windows 10', 'hibernate')['ok']) && end($GLOBALS['CMDS']) === [und_virsh(), 'dompmsuspend', 'Windows 10', 'disk']);
t('paused VM resumes with virsh resume', isset(und_vm_action('Paused VM', 'resume')['ok']) && end($GLOBALS['CMDS']) === [und_virsh(), 'resume', 'Paused VM']);

/* ---------------- server power ---------------- */
group('power');
$GLOBALS['MOCK'] = function ($argv) { return $argv[1] === 'list' ? ['out' => " Id Name State\n---\n"] : ['code' => 0]; };
$GLOBALS['SPAWNED'] = []; @unlink(und_tmp('power-started'));
t('unknown power op refused', isset(und_power_prepare('halt')['error']));
$p = und_power_prepare('reboot');
t('prepare returns a nonce and the server name', strlen($p['nonce']) === 32 && $p['name'] === 'Tower');
t('wrong nonce refused', isset(und_power('reboot', 'x', 'Tower')['error']) && !$GLOBALS['SPAWNED']);
$p = und_power_prepare('reboot');
t('wrong server name refused', isset(und_power('reboot', $p['nonce'], 'Other')['error']) && !$GLOBALS['SPAWNED']);
t('a nonce is single use', isset(und_power('reboot', $p['nonce'], 'Tower')['error']));
$p = und_power_prepare('reboot');
t('nonce is bound to the operation', isset(und_power('shutdown', $p['nonce'], 'Tower')['error']));
$p = und_power_prepare('shutdown');
$nf = und_read_json(und_tmp('power-nonce.json'), []); $nf['exp'] = time() - 1; und_write_json(und_tmp('power-nonce.json'), $nf);
t('expired nonce refused', isset(und_power('shutdown', $p['nonce'], 'Tower')['error']) && !$GLOBALS['SPAWNED']);
$p = und_power_prepare('reboot');
t('correct confirmation accepted', isset(und_power('reboot', $p['nonce'], 'tower')['ok']));
t('it runs Unraid\'s own reboot command after a short delay', $GLOBALS['SPAWNED'][0] === ['/bin/bash', '-c', 'sleep 3; exec /sbin/reboot -n']);
$p = und_power_prepare('reboot');
t('a second power request is refused', isset(und_power('reboot', $p['nonce'], 'Tower')['error']) && count($GLOBALS['SPAWNED']) === 1);
@unlink(und_tmp('power-started'));
$p = und_power_prepare('shutdown'); und_power('shutdown', $p['nonce'], 'Tower');
t('shutdown uses poweroff -n', end($GLOBALS['SPAWNED']) === ['/bin/bash', '-c', 'sleep 3; exec /sbin/poweroff -n']);
@unlink(und_tmp('power-started'));

/* ---------------- standalone sign-in ---------------- */
group('auth');
$t1 = und_handoff_create();
t('handoff token works once', und_handoff_use($t1) === true && und_handoff_use($t1) === false);
t('malformed handoff token refused', und_handoff_use('../../x') === false);
$t2 = und_handoff_create(); foreach (glob(und_tmp('handoff') . '/*') as $f) file_put_contents($f, (string)(time() - 1));
t('expired handoff token refused', und_handoff_use($t2) === false);
$GLOBALS['UND_PHPSESS_DIR'] = "$SANDBOX/php"; @mkdir("$SANDBOX/php");
$_SERVER['HTTP_HOST'] = 'tower.local:9099';
$sid = 'abcdefghijklmnopqrstuvwx12';
$_COOKIE = ['unraid_' . md5('tower.local') => $sid];
t('no Unraid session file, not logged in', und_unraid_logged_in() === false);
file_put_contents("$SANDBOX/php/sess_$sid", 'locale|s:0:"";');
t('session without unraid_login, not logged in', und_unraid_logged_in() === false);
file_put_contents("$SANDBOX/php/sess_$sid", 'unraid_user|s:4:"root";unraid_login|i:' . time() . ';');
t('valid Unraid session accepted', und_unraid_logged_in() === true);
$_COOKIE = ['unraid_' . md5('other.local') => $sid];
t('cookie for another host name ignored', und_unraid_logged_in() === false);
$_COOKIE = ['unraid_' . md5('tower.local') => '../../etc/passwd'];
t('path-like session id refused', und_unraid_logged_in() === false);
$_SERVER['HTTP_HOST'] = 'evil.com/x';
t('odd host header refused', und_req_host() === '');

/* ---------------- ignored alerts ---------------- */
group('ignores');
reset_store();
t('no rules to start with', und_ignores() === [] && und_ignores_active() === []);
$a = und_ignore_add('instance', 'disk.hot', 'disk3', 3600, 'disk3 is at 52°C.', 'warn');
t('instance rule added', !empty($a['ok']) && $a['rule']['key'] === 'disk.hot:disk3' && $a['rule']['until'] > time() + 3500);
t('rule written to the config dir and the flash mirror', is_file(UND_DIR . '/ignores.json') && is_file(und_store_dir() . '/ignores.json'));
$p = und_ignore_add('type', 'docker.exited', 'ignored-anyway', 'permanent', 'Container stopped', 'warn');
t('type rule is permanent and has no resource', $p['rule']['until'] === null && $p['rule']['resource'] === '' && $p['rule']['key'] === 'docker.exited');
t('both rules active', count(und_ignores_active()) === 2);
$a2 = und_ignore_add('instance', 'disk.hot', 'disk3', 86400);
t('ignoring the same alert again replaces the rule', count(und_ignores()) === 2 && $a2['rule']['until'] > time() + 86000);
t('unknown category refused', isset(und_ignore_add('instance', 'shell.exec', 'x', 3600)['error']));
t('connection alerts cannot be ignored', isset(und_ignore_add('type', 'conn.down', '', 3600)['error']));
t('unknown scope refused', isset(und_ignore_add('all', 'disk.hot', 'disk3', 3600)['error']));
t('odd resource refused', isset(und_ignore_add('instance', 'disk.hot', '../../etc', 3600)['error']) && isset(und_ignore_add('instance', 'disk.hot', '', 3600)['error']));
t('too short a duration refused', isset(und_ignore_add('instance', 'disk.hot', 'disk1', 10)['error']));
t('too long a duration refused', isset(und_ignore_add('instance', 'disk.hot', 'disk1', 400 * 86400)['error']));
t('non-numeric duration refused', isset(und_ignore_add('instance', 'disk.hot', 'disk1', 'forever')['error']));
t('refusals change nothing', count(und_ignores()) === 2);
// expiry is decided at read time, by the server clock
$raw = json_decode(file_get_contents(und_ignore_file()), true);
$raw[] = ['id' => 'aaaaaaaaaaaa', 'kind' => 'instance', 'key' => 'os.available:7.2.0', 'category' => 'os.available', 'resource' => '7.2.0', 'until' => time() - 60, 'created' => time() - 3660, 'title' => 'x', 'severity' => 'info'];
$raw[] = ['id' => 'bbbbbbbbbbbb', 'kind' => 'instance', 'category' => 'nope', 'resource' => 'x'];
$raw[] = 'garbage';
$raw[] = ['id' => 'NOT-AN-ID', 'kind' => 'type', 'category' => 'disk.bad'];
$raw[] = ['id' => 'cccccccccccc', 'kind' => 'instance', 'category' => 'disk.bad', 'resource' => 'evil;rm', 'until' => null];
und_store_write('ignores.json', json_encode($raw));
t('malformed records are dropped, valid ones kept', count(und_ignores()) === 3);
t('an expired rule is listed but not active', count(und_ignores_active()) === 2 && !in_array('aaaaaaaaaaaa', array_column(und_ignores_active(), 'id'), true));
file_put_contents(und_ignore_file(), '{not json');
t('an unreadable file means no rules, not an error', und_ignores() === []);
und_store_write('ignores.json', json_encode($raw));
$pe = und_ignore_purge_expired();
t('delete expired removes only the expired rule', $pe['removed'] === 1 && count(und_ignores()) === 2);
t('purging also wrote out a clean file', count(json_decode(file_get_contents(und_ignore_file()), true)) === 2);
$rm = und_ignore_remove(['zzzzzzzzzzzz', 'not valid']);
t('restoring unknown ids removes nothing', isset($rm['error']) && count(und_ignores()) === 2);
$rm = und_ignore_remove([$p['rule']['id']]);
t('restore removes just that rule', $rm['removed'] === 1 && und_ignores()[0]['key'] === 'disk.hot:disk3');
und_ignore_add('instance', 'docker.unhealthy', 'plex', 3600);
$ra = und_ignore_restore_all();
t('restore all empties the list', $ra['removed'] === 2 && und_ignores() === []);
// a rule from a month ago that ran out is cleaned up on the next write
und_store_write('ignores.json', json_encode([['id' => 'dddddddddddd', 'kind' => 'type', 'category' => 'disk.hot', 'until' => time() - 40 * 86400, 'created' => time() - 50 * 86400]]));
und_ignore_add('instance', 'disk.hot', 'disk1', 3600);
t('long-expired rules are pruned on write', count(und_ignores()) === 1 && und_ignores()[0]['resource'] === 'disk1');
// rules survive a lost store directory: the flash mirror still has them (and the reverse on first use)
reset_store();
@mkdir(UND_DIR, 0777, true);
file_put_contents(UND_DIR . '/ignores.json', json_encode([['id' => 'eeeeeeeeeeee', 'kind' => 'type', 'category' => 'os.available', 'until' => null, 'created' => time()]]));
t('rules on the flash drive are picked up', count(und_ignores()) === 1);
t('ignores.json is copied into a new config dir', is_file(und_store_dir() . '/ignores.json') || und_store_dir() === UND_DIR);
reset_store();
$fl = und_disk_flags([['name' => 'parity', 'health' => 'ok', 'temp' => 41, 'errors' => 0], ['name' => 'disk3', 'health' => 'bad', 'temp' => 52, 'errors' => 4], ['name' => 'cache', 'health' => 'ok', 'temp' => null, 'errors' => 0]]);
t('disk flags name the disks', $fl['bad_disks'] === ['disk3'] && $fl['hot_disks'] === [['name' => 'disk3', 'temp' => 52]] && $fl['error_disks'] === [['name' => 'disk3', 'errors' => 4]] && $fl['bad'] === 1 && $fl['hot'] === 1 && $fl['errors'] === 4);

/* ---------------- alerts and their history ---------------- */
group('alerts');
reset_store();
$cfgA = und_load_config();
$cfgA['docker']['items'] = ['secret-thing' => ['hidden' => true], 'plex' => ['label' => 'Plex Media']];
$sysA = ['array' => ['state' => 'STARTED', 'resync' => 0, 'bad_disks' => ['disk2'], 'error_disks' => [['name' => 'disk2', 'errors' => 3]], 'hot_disks' => [['name' => 'disk1', 'temp' => 52]]],
         'os_update' => ['staged' => '', 'available' => true, 'latest' => '7.4.0', 'installed' => '7.3.4', 'error' => '']];
$dkA = ['containers' => [['name' => 'plex', 'health' => 'unhealthy', 'state' => 'running'], ['name' => 'gluetun', 'state' => 'restarting', 'restarts' => 12],
         ['name' => 'handbrake', 'state' => 'exited', 'exit' => 128, 'autostart' => true], ['name' => 'manual', 'state' => 'exited', 'exit' => 1, 'autostart' => false],
         ['name' => 'secret-thing', 'health' => 'unhealthy', 'state' => 'running'], ['name' => 'radarr', 'state' => 'running', 'job' => 'update_failed']]];
$al = und_alerts($cfgA, $sysA, $dkA);
$keys = array_map(function ($a) { return $a['cat'] . ':' . $a['res']; }, $al);
t('each disk and container problem is its own alert', $keys === ['disk.bad:disk2', 'disk.errors:disk2', 'disk.hot:disk1', 'os.available:7.4.0', 'docker.unhealthy:plex', 'docker.restarting:gluetun', 'docker.exited:handbrake', 'docker.update_failed:radarr'], implode(',', $keys));
t('labels are used in messages, ids stay container names', $al[4]['msg'] === 'Plex Media is unhealthy.' && $al[4]['act'] === 'logs');
t('hidden containers and manual stops raise nothing', !in_array('docker.unhealthy:secret-thing', $keys, true) && !in_array('docker.exited:manual', $keys, true));
$ae = und_alerts($cfgA, ['error' => 'API key rejected', 'auth' => true], null);
t('an API problem is one alert with a Settings button', count($ae) === 1 && $ae[0]['cat'] === 'api.error' && $ae[0]['act'] === 'settings' && strpos($ae[0]['msg'], 'Settings > Connection') !== false);
t('every alert category can be ignored', !array_diff(array_column($al, 'cat'), array_keys(und_alert_categories())));
// history
$t0 = 1800000000;
t('first sight starts alerts and writes the file', und_alerts_track($al, ['system', 'docker'], $t0) === true && count(und_alert_history()['active']) === 8);
t('the same alerts again write nothing', und_alerts_track($al, ['system', 'docker'], $t0 + 2) === false);
$less = array_values(array_filter($al, function ($a) { return $a['cat'] !== 'disk.hot'; }));
und_alerts_track($less, ['system', 'docker'], $t0 + 600);
$hh = und_alert_history();
t('a cleared alert moves to the log with its start and end', count($hh['active']) === 7 && $hh['log'][0]['cat'] === 'disk.hot' && $hh['log'][0]['start'] === $t0 && $hh['log'][0]['end'] === $t0 + 600);
$sysOnly = array_values(array_filter($less, function ($a) { return strpos($a['cat'], 'docker.') !== 0; }));
und_alerts_track($sysOnly, ['system'], $t0 + 700);
t('container alerts stay open when Docker was not checked', count(und_alert_history()['active']) === 7);
und_alerts_track($sysOnly, ['system', 'docker'], $t0 + 800);
t('and end once Docker answers without them', count(und_alert_history()['active']) === 3 && count(und_alert_history()['log']) === 5);
file_put_contents(und_alert_file(), '{broken');
t('an unreadable history starts over instead of failing', und_alerts_track($al, ['system', 'docker'], $t0 + 900) === true && count(und_alert_history()['active']) === 8);
reset_store();

/* ---------------- container web UI addresses ---------------- */
group('webui');
reset_store();
$GLOBALS['MOCK'] = function ($argv) {
  if ($argv[1] !== 'inspect') return ['code' => 1];
  $c = function ($n, $net, $ip, $ports, $label) { return ['Name' => '/' . $n, 'Config' => ['Labels' => ['net.unraid.docker.webui' => $label]], 'HostConfig' => ['NetworkMode' => $net, 'PortBindings' => $ports],
    'NetworkSettings' => ['Networks' => [$net => ['IPAddress' => $ip]]], 'State' => []]; };
  return ['out' => json_encode([
    $c('lidarr', 'r1ze', '172.18.0.4', ['8686/tcp' => [['HostPort' => '8687']]], 'http://[IP]:[PORT:8687]'),
    $c('dashbrr', 'r1ze', '172.18.0.18', ['8080/tcp' => [['HostPort' => '5456']]], 'http://[IP]:[PORT:8080]/'),
    $c('adguard', 'br0', '192.168.50.10', [], 'http://[IP]:[PORT:3000]/'),
    $c('radarr', 'bridge', '172.17.0.5', ['7878/tcp' => [['HostPort' => '7979']]], 'http://[IP]:[PORT:7878]/')])];
};
file_put_contents("$SANDBOX/fs/var/local/emhttp/network.ini", "[eth0]\nIPADDR:0=\"192.168.50.50\"\n");
$det = und_docker_details(['lidarr' => ['id' => 'a1', 'state' => 'running'], 'dashbrr' => ['id' => 'a2', 'state' => 'running'], 'adguard' => ['id' => 'a3', 'state' => 'running'], 'radarr' => ['id' => 'a4', 'state' => 'running']], true);
$sip = und_server_ip();
t('user-defined bridge with published ports: server address and the port as written', $det['lidarr']['webui'] === "http://$sip:8687", $det['lidarr']['webui']);
t('user-defined bridge: [PORT:container port] becomes the host port', $det['dashbrr']['webui'] === "http://$sip:5456/", $det['dashbrr']['webui']);
t('br0 / macvlan without published ports: the container\'s own LAN address', $det['adguard']['webui'] === 'http://192.168.50.10:3000/', $det['adguard']['webui']);
t('default bridge: server address and host port', $det['radarr']['webui'] === "http://$sip:7979/", $det['radarr']['webui']);
unset($GLOBALS['MOCK']);
reset_store();

/* ---------------- now playing, requests, endpoint health ---------------- */
group('sections');
reset_store();
$mk = function ($id, $type, $name, $href = '') { return ['id' => $id, 'type' => $type, 'name' => $name, 'url' => 'http://app.lan:1', 'href' => $href, 'fields' => [], 'options' => []]; };
$cfgS = und_normalize(['groups' => [['id' => 'aaaaaaaa', 'name' => 'Media', 'services' => [$mk('a0000001', 'plex', 'Plex', 'http://10.0.0.5:32400'), $mk('a0000002', 'tautulli', 'Tautulli'),
  $mk('a0000003', 'jellyfin', 'Jellyfin', 'http://10.0.0.5:8096'), $mk('a0000004', 'overseerr', 'Seerr', 'http://10.0.0.5:5055')]]]]);
$GLOBALS['SEEN'] = [];
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) {
  $GLOBALS['SEEN'][] = $m . ' ' . $url;
  $p = parse_url($url, PHP_URL_PATH); parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
  $j = function ($d, $c = 200) { return ['code' => $c, 'body' => json_encode($d), 'json' => $d, 'err' => '', 'headers' => []]; };
  if ($p === '/api/v2') return $j(['response' => ['result' => 'success', 'data' => ['sessions' => [['media_type' => 'movie', 'title' => 'Heat', 'year' => 1995, 'friendly_name' => 'Al', 'player' => 'TV', 'state' => 'paused',
    'view_offset' => 60000, 'duration' => 600000, 'transcode_decision' => 'transcode', 'bandwidth' => '8000', 'location' => 'wan']]]]]);
  if ($p === '/Sessions') return $j([['UserName' => 'Bo', 'DeviceName' => 'Phone', 'PlayState' => ['PositionTicks' => 600000000, 'PlayMethod' => 'DirectPlay'], 'NowPlayingItem' => ['SeriesName' => 'Dark', 'ParentIndexNumber' => 1, 'IndexNumber' => 2, 'Name' => 'Lies', 'RunTimeTicks' => 36000000000, 'Width' => 1920]], ['UserName' => 'idle']]);
  if ($p === '/api/v1/request') return $j(['results' => ($q['filter'] ?? '') === 'pending' ? [['id' => 5, 'status' => 1, 'type' => 'movie', 'media' => ['tmdbId' => 77, 'status' => 2], 'requestedBy' => ['displayName' => 'Cy'], 'createdAt' => '2026-10-01T00:00:00Z']]
    : [['id' => 5, 'status' => 1, 'type' => 'movie', 'media' => ['tmdbId' => 77, 'status' => 2]], ['id' => 4, 'status' => 2, 'type' => 'tv', 'media' => ['tmdbId' => 88, 'status' => 5], 'seasons' => [['seasonNumber' => 1]]]]]);
  if ($p === '/api/v1/movie/77') return $j(['title' => 'Memento', 'releaseDate' => '2000-09-05', 'posterPath' => '/m.jpg']);
  if ($p === '/api/v1/tv/88') return $j(['name' => 'Dark', 'firstAirDate' => '2017-12-01', 'posterPath' => '"><script>.jpg']);
  if (preg_match('#^/api/v1/request/(\d+)/(approve|decline)$#', $p, $mm)) return $mm[1] === '404' ? $j(['message' => 'gone'], 404) : $j(['id' => (int)$mm[1]]);
  return $j(['error' => 'nope'], 404);
};
$pl = und_playing($cfgS);
t('Tautulli covers Plex, so Plex is not asked', !array_filter($GLOBALS['SEEN'], function ($u) { return strpos($u, ':1/status/sessions') !== false; }) && count($pl['streams']) === 2);
t('streams carry who, what and how', $pl['streams'][0]['user'] === 'Bo' && $pl['streams'][0]['title'] === 'Dark' && $pl['streams'][0]['sub'] === 'S01E02 · Lies' && $pl['streams'][0]['method'] === 'direct play' && $pl['streams'][0]['quality'] === '1080p');
t('playing comes before paused; transcodes and remote streams are marked', $pl['streams'][1]['state'] === 'paused' && $pl['streams'][1]['method'] === 'transcode' && $pl['streams'][1]['location'] === 'wan' && $pl['streams'][1]['bandwidth'] === 8000000);
t('no media server means a hint, not an error', !empty(und_playing(und_normalize(['groups' => []]))['unconfigured']));
$rq = und_requests($cfgS);
t('pending requests first, titles looked up', $rq['pending'] === 1 && $rq['items'][0]['title'] === 'Memento' && $rq['items'][0]['status'] === 'pending' && $rq['items'][1]['status'] === 'available' && $rq['items'][1]['seasons'] === [1]);
t('poster paths that are not plain file names are dropped', $rq['items'][0]['poster'] === 'https://image.tmdb.org/t/p/w92/m.jpg' && $rq['items'][1]['poster'] === '');
t('approve posts to Seerr', !empty(und_request_action($cfgS, 'a0000004', '5', 'approve')['ok']) && in_array('POST http://app.lan:1/api/v1/request/5/approve', $GLOBALS['SEEN'], true));
t('a vanished request says so', strpos(und_request_action($cfgS, 'a0000004', '404', 'decline')['error'] ?? '', 'no longer exists') !== false);
t('only approve or decline, only known apps, only numeric ids', isset(und_request_action($cfgS, 'a0000004', '5', 'delete')['error']) && isset(und_request_action($cfgS, 'a0000001', '5', 'approve')['error'])
  && isset(und_request_action($cfgS, 'a0000004', '5/../../x', 'approve')['error']));
$GLOBALS['UND_HTTP_MOCK2'] = $GLOBALS['UND_HTTP_MOCK'];
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) {
  $GLOBALS['SEEN'][] = $m . ' ' . $url . (isset($o['body']) ? ' ' . $o['body'] : '');
  $p = parse_url($url, PHP_URL_PATH);
  $j = function ($d, $c = 200) { return ['code' => $c, 'body' => json_encode($d), 'json' => $d, 'err' => '', 'headers' => []]; };
  if ($p === '/api/v1/search') return $j(['results' => [['id' => 1, 'mediaType' => 'person', 'name' => 'Someone'], ['id' => 27205, 'mediaType' => 'movie', 'title' => 'Inception', 'releaseDate' => '2010-07-15', 'posterPath' => '/i.jpg'],
    ['id' => 1399, 'mediaType' => 'tv', 'name' => 'Game of Thrones', 'firstAirDate' => '2011-04-17', 'mediaInfo' => ['status' => 5]]]]);
  if ($p === '/api/v1/request' && $m === 'POST') return strpos($o['body'], '"mediaId":1399') !== false ? $j(['message' => 'dup'], 409) : $j(['id' => 7], 201);
  return call_user_func($GLOBALS['UND_HTTP_MOCK2'], $m, $url, $o);
};
$GLOBALS['SEEN'] = [];
$se = und_seerr_search($cfgS, 'the "best" & more');
t('search asks Seerr with %20, not +', (bool)array_filter($GLOBALS['SEEN'], function ($u) { return strpos($u, '/api/v1/search?query=the%20%22best%22%20%26%20more&') !== false; }));
t('search lists movies and series, not people, with their status', array_column($se['items'], 'title') === ['Inception', 'Game of Thrones'] && $se['items'][0]['status'] === 'none' && $se['items'][1]['status'] === 'available' && $se['app']['id'] === 'a0000004');
t('search needs 2 to 100 characters', isset(und_seerr_search($cfgS, 'x')['error']) && isset(und_seerr_search($cfgS, str_repeat('x', 101))['error']));
t('a movie is requested as itself', !empty(und_seerr_request($cfgS, 'a0000004', 'movie', '27205')['ok']) && (bool)array_filter($GLOBALS['SEEN'], function ($u) { return strpos($u, '{"mediaType":"movie","mediaId":27205}') !== false; }));
t('a series is requested with every season, a duplicate says so', strpos(und_seerr_request($cfgS, 'a0000004', 'tv', '1399')['error'] ?? '', 'already requested') !== false
  && (bool)array_filter($GLOBALS['SEEN'], function ($u) { return strpos($u, '"seasons":"all"') !== false; }));
t('only movies or series, numeric ids, known apps', isset(und_seerr_request($cfgS, 'a0000004', 'person', '1')['error']) && isset(und_seerr_request($cfgS, 'a0000004', 'movie', '1;2')['error'])
  && isset(und_seerr_request($cfgS, 'a0000001', 'movie', '1')['error']));
unset($GLOBALS['UND_HTTP_MOCK']);
$tg = und_health_targets($cfgS, ['containers' => [['name' => 'plex', 'state' => 'running', 'webui' => 'http://10.0.0.5:32400/web'], ['name' => 'sonarr', 'state' => 'running', 'webui' => 'http://10.0.0.5:8989/'],
  ['name' => 'stopped', 'state' => 'exited', 'webui' => 'http://10.0.0.5:9000/'], ['name' => 'bad', 'state' => 'running', 'webui' => 'javascript:alert(1)']]]);
t('health probes app links and running container web UIs, each address once', array_column($tg, 'hostport') === ['10.0.0.5:32400', '10.0.0.5:8096', '10.0.0.5:5055', '10.0.0.5:8989'], json_encode(array_column($tg, 'hostport')));
$GLOBALS['UND_HEALTH_MOCK'] = function ($t) { $o = []; foreach ($t as $i => $x) $o[$i] = $i === 0 ? ['code' => 0, 'ms' => null, 'err' => 'Connection refused'] : ['code' => [0, 404, 401][$i], 'ms' => 5, 'err' => '']; return $o; };
$hl = und_health($cfgS, true);
t('health sorts problems first and counts reachable endpoints', $hl['rows'][0]['level'] === 'bad' && $hl['rows'][0]['text'] === 'Connection refused' && $hl['up'] === $hl['total'] - 1);
t('a login page (401) counts as healthy, a 404 as a warning', (bool)array_filter($hl['rows'], function ($r) { return $r['code'] === 404 && $r['level'] === 'warn'; })
  && (bool)array_filter($hl['rows'], function ($r) { return $r['code'] === 401 && $r['level'] === 'ok' && $r['text'] === '401 Login required'; }));
unset($GLOBALS['UND_HEALTH_MOCK']);
reset_store();

/* ---------------- media columns ---------------- */
group('media');
$m = und_normalize(['groups' => [], 'media' => ['count' => 12, 'widgets' => [['id' => 'recent_episodes', 'visible' => false, 'count' => 7], ['id' => 'upcoming_movies', 'count' => 999]]]])['media'];
t('columns always in the fixed order', array_column($m['widgets'], 'id') === ['upcoming_movies', 'upcoming_episodes', 'recent_movies', 'recent_episodes']);
t('per-column count kept and clamped', $m['widgets'][3]['count'] === 7 && $m['widgets'][0]['count'] === 50);
t('older configs fall back to the shared count', $m['widgets'][1]['count'] === 12);
t('visibility kept', $m['widgets'][3]['visible'] === false && $m['widgets'][0]['visible'] === true);
t('default count is 15', und_load_config()['media']['widgets'][2]['count'] === 15);
$ap = und_normalize(['groups' => [], 'appearance' => ['brightness' => 0, 'blur_target' => 'cards']])['appearance'];
t('wallpaper brightness goes down to 0 and the blur can sit behind the cards only', $ap['brightness'] === 0 && $ap['blur_target'] === 'cards'
  && und_normalize(['groups' => [], 'appearance' => ['brightness' => 150, 'blur_target' => 'everything']])['appearance']['brightness'] === 100
  && und_normalize(['groups' => [], 'appearance' => ['blur_target' => 'everything']])['appearance']['blur_target'] === 'wallpaper');

/* ---------------- spacing and the update check ---------------- */
group('version');
$sa = und_normalize(['groups' => [], 'appearance' => ['safe_x' => 40, 'safe_y' => 500]])['appearance'];
t('custom margins are kept and capped; default is automatic', $sa['safe_x'] === 40 && $sa['safe_y'] === 200 && und_load_config()['appearance']['safe_x'] === -1
  && und_normalize(['groups' => [], 'appearance' => ['safe_x' => -7]])['appearance']['safe_x'] === -1);
@unlink(und_tmp('self-update.json'));
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url) { $GLOBALS['SEEN'][] = $url; $d = [['name' => 'v2026.11.02d'], ['name' => 'v0.5.1'], ['name' => 'v0.10.0'], ['name' => 'v0.9.3'], ['name' => 'nightly']];
  return ['code' => 200, 'body' => json_encode($d), 'json' => $d, 'err' => '', 'headers' => []]; };
$GLOBALS['SEEN'] = [];
$su = und_self_update();
t('the newest x.y.z release tag wins (0.10.0 over 0.9.3; date tags ignored)', $su['latest'] === '0.10.0' && strpos($GLOBALS['SEEN'][0], 'api.github.com/repos/Stiphr/UnDash/tags') !== false);
und_self_update();
t('the check is cached (one request)', count($GLOBALS['SEEN']) === 1);
unset($GLOBALS['UND_HTTP_MOCK']);
@unlink(und_tmp('self-update.json'));

/* ---------------- Homepage import still works ---------------- */
group('homepage');
$yaml = "- Arr:\n    - Radarr:\n        icon: radarr.png\n        href: http://{{HOMEPAGE_VAR_IP}}:7878\n        widget:\n          type: radarr\n          url: http://{{HOMEPAGE_VAR_IP}}:7878\n          key: {{HOMEPAGE_VAR_RADARR_KEY}}\n          fields: [\"wanted\", \"queued\"]\n- System:\n    - CPU:\n        widget:\n          type: glances\n          metric: cpu\n";
$hc = und_hp_convert($yaml, '', "HOMEPAGE_VAR_IP=10.0.0.9\n");
t('groups and widgets converted', $hc['services'] === 1 && $hc['config']['groups'][0]['services'][0]['fields'] === ['wanted', 'queued']);
t('glances is left to the built-in overview', $hc['config']['groups'][1]['services'] === [] && count($hc['warnings']) === 1);
t('appearance survives a Homepage import', $hc['config']['appearance']['theme'] === und_load_config()['appearance']['theme']);
t('.env values picked up', $hc['vars']['HOMEPAGE_VAR_IP'] === '10.0.0.9');

rmrf($SANDBOX);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
