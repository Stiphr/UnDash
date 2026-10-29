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
t('defaults have schema 2', $c['schema'] === 2);
t('default refresh is 2 s', $c['general']['refresh'] === 2);
t('three themes only', und_normalize(['groups' => [], 'appearance' => ['theme' => 'midnight']])['appearance']['theme'] === 'default');
t('accent must be a hex colour', und_normalize(['groups' => [], 'appearance' => ['accent' => 'red;}']])['appearance']['accent'] === '#f15a2c');
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
t('migration result is schema 2', $m['schema'] === 2);

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
t('exports contain nothing but settings', count(array_diff(array_keys(json_decode($full, true)), ['undash_export', 'schema', 'kind', 'created', 'version', 'config', 'css', 'vars', 'secrets'])) === 0);

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
