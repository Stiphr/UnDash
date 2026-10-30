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
t('defaults have schema 5', $c['schema'] === 5);
t('containers show the running ones in a grid by default', $c['docker']['filter'] === 'running' && $c['docker']['layout'] === 'grid');
t('shortcut links are off by default', $c['apps']['links'] === false);
t('default refresh is 2 s', $c['general']['refresh'] === 2);
$sn = function ($g) { return und_normalize(['groups' => [], 'general' => $g])['general']; };
t('the search box looks through everything by default', $sn([])['search_in'] === ['apps', 'web', 'seerr']);
t('search sources keep only known ones, in a fixed order', $sn(['search_in' => ['seerr', 'evil', 'apps']])['search_in'] === ['apps', 'seerr']);
t('the old engine "none" turns web search off', ($x = $sn(['search' => 'none'])) && $x['search_in'] === ['apps', 'seerr'] && $x['search'] === 'duckduckgo');
t('all search sources can be turned off', $sn(['search_in' => []])['search_in'] === []);
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
t('migration result is schema 5', $m['schema'] === 5);
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
$cfg = und_load_config();
t('the old files become config.yaml and .env on first use', $cfg['general']['title'] === 'appdata copy' && is_file("$dir/config.yaml") && is_file("$dir/.env") && !is_file("$dir/config.json") && is_file("$dir/data/legacy/config.json"));
t('.env is private', (fileperms("$dir/.env") & 0777) === 0600);
t('variables nothing refers to are dropped', !isset(und_env()['A']));
$cfg['general']['title'] = 'Saved';
t('save works', und_save_config($cfg));
t('save writes config.yaml in the configuration directory', strpos(file_get_contents("$dir/config.yaml"), 'title: Saved') !== false);
t('save mirrors to the flash drive', strpos(file_get_contents(UND_DIR . '/config.yaml'), 'title: Saved') !== false);
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
$target = null; foreach (und_backups() as $x) { $j = und_read_json(und_backup_dir() . '/' . $x['id'] . '.json', []); if (strpos((string)($j['yaml'] ?? ''), 'title: Before') !== false) { $target = $x['id']; break; } }
t('the backup holding the earlier state exists', $target !== null);
t('restore works', isset(und_restore($target)['ok']) && und_load_config()['general']['title'] === 'Before');
t('restore refuses odd ids', isset(und_restore('../../etc/passwd')['error']));
und_backup('same'); $n = count(glob(und_backup_dir() . '/*.json'));
und_backup('same again');
t('an unchanged config is not backed up twice', count(glob(und_backup_dir() . '/*.json')) === $n);
for ($i = 0; $i < 12; $i++) { $cfg['general']['title'] = 'T' . $i; und_save_config($cfg, false); und_backup('settings saved'); }
t('only the last 7 backups are kept', count(glob(und_backup_dir() . '/*.json')) === 7);
$j = und_read_json(und_backup_dir() . '/' . und_backups()[0]['id'] . '.json', []);
t('the newest backup holds the config just saved', strpos((string)($j['yaml'] ?? ''), 'title: T11') !== false);

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
reset_store();
$cfg = und_default_config();
$cfg['groups'][] = ['id' => 'abcdef01', 'name' => 'Apps', 'services' => [
  ['id' => 'aaaaaaaa', 'name' => 'Radarr', 'type' => 'radarr', 'url' => 'http://{{SERVER_IP}}:7878', 'href' => '', 'fields' => [], 'options' => []],
  ['id' => 'bbbbbbbb', 'name' => 'qBittorrent Private', 'type' => 'qbittorrent', 'url' => 'http://{{SERVER_IP}}:8080', 'href' => '', 'fields' => [], 'options' => []]]];
und_save_config($cfg);
und_save_secrets(['vars' => ['SERVER_IP' => '10.0.0.2']]);
$old = und_load_config(); $env = und_env();
$new = und_creds_apply($old, $old, ['aaaaaaaa' => ['key' => 'k-secret-value'], 'bbbbbbbb' => ['username' => 'bob', 'password' => 'pw-secret']], $env);
t('a new key goes to .env under a readable name, config.yaml only refers to it', $new['groups'][1]['services'][0]['key'] === '{{RADARR_API_KEY}}' && $env['RADARR_API_KEY'] === 'k-secret-value');
t('names follow the app name', $new['groups'][1]['services'][1]['password'] === '{{QBITTORRENT_PRIVATE_PASSWORD}}' && $env['QBITTORRENT_PRIVATE_USERNAME'] === 'bob');
und_save_config($new); und_env_write($env);
$yaml = file_get_contents(und_file('config.yaml'));
t('config.yaml never holds a credential', strpos($yaml, 'k-secret-value') === false && strpos($yaml, 'pw-secret') === false && strpos($yaml, 'bob') === false);
t('the app reads its key through the reference', und_service_full(und_load_config(), 'aaaaaaaa')['key'] === 'k-secret-value' && und_service_full(und_load_config(), 'aaaaaaaa')['url'] === 'http://10.0.0.2:7878');
$view = json_encode(und_creds_view(und_load_config()));
t('Settings sees that a key is stored, never the key', strpos($view, 'k-secret-value') === false && strpos($view, 'pw-secret') === false && strpos($view, UND_KEEP) !== false && strpos($view, '"bob"') !== false);
$env = und_env(); $cur = und_load_config();
$kept = und_creds_apply($cur, $cur, ['aaaaaaaa' => ['key' => UND_KEEP]], $env);
t('KEEP keeps a stored key', $kept['groups'][1]['services'][0]['key'] === '{{RADARR_API_KEY}}' && $env['RADARR_API_KEY'] === 'k-secret-value');
$env = und_env(); $cur = und_load_config(); $gone = $cur; array_pop($gone['groups'][1]['services']);
und_creds_apply($gone, $cur, [], $env);
t('removing an app drops its credentials from .env', !isset($env['QBITTORRENT_PRIVATE_PASSWORD']) && !isset($env['QBITTORRENT_PRIVATE_USERNAME']) && isset($env['RADARR_API_KEY']) && isset($env['SERVER_IP']));
$env = und_env(); $cur = und_load_config();
$cl = und_creds_apply($cur, $cur, ['aaaaaaaa' => ['key' => '']], $env);
t('clearing a key removes it', $cl['groups'][1]['services'][0]['key'] === '' && !isset($env['RADARR_API_KEY']));
$hand = und_normalize(['groups' => [['name' => 'G', 'services' => [['type' => 'radarr', 'name' => 'R', 'key' => 'typed-in-clear']]]]]);
t('a key typed into config.yaml by hand is not kept there', $hand['groups'][0]['services'][0]['key'] === '');
$sh = json_encode(und_shared_view(und_load_config()));
t('shared values are listed, app credentials are not', strpos($sh, 'SERVER_IP') !== false && strpos($sh, 'RADARR_API_KEY') === false);
$env2 = und_shared_apply(und_env(), und_load_config(), und_load_config(), [['name' => 'server_ip', 'value' => '10.0.0.9'], ['name' => 'COMMON_TOKEN', 'value' => 'tok']]);
t('shared values can be changed and added; credentials stay', $env2['SERVER_IP'] === '10.0.0.9' && $env2['COMMON_TOKEN'] === 'tok' && $env2['RADARR_API_KEY'] === 'k-secret-value');
// a whole Save: an app added with its key, the shared values sent along (the order api.php uses)
$old = und_load_config(); $env = und_env(); $form = $old;
$form['groups'][1]['services'][] = ['id' => 'cccccccc', 'name' => 'qui', 'type' => 'qui', 'url' => 'http://q:7476', 'href' => '', 'fields' => [], 'options' => []];
$norm = und_creds_apply(und_normalize($form), $old, ['cccccccc' => ['key' => 'new-qui-key']], $env);
$env = und_shared_apply($env, $old, $norm, json_decode(json_encode(und_shared_view($old)), true));
t('saving a new app with shared values keeps its new key', ($env['QUI_API_KEY'] ?? '') === 'new-qui-key' && $norm['groups'][1]['services'][2]['key'] === '{{QUI_API_KEY}}' && $env['SERVER_IP'] === '10.0.0.2');
$lv = und_layout_view(['groups' => [['services' => [['href' => 'http://x/?t={{RADARR_API_KEY}}'], ['href' => 'javascript:alert(1)'], ['href' => 'http://{{SERVER_IP}}:1']]]]]);
$hs = array_column($lv['groups'][0]['services'], 'href_resolved');
t('a link carrying a secret variable is not resolved for the browser', $hs[0] === '');
t('javascript: links are dropped', $hs[1] === '');
t('plain variables in links are resolved', $hs[2] === 'http://10.0.0.2:1');
$cy = ['general' => ['title' => "a: b # c"], 'list' => ['x', '12', 12, true, 'true', null, '', "two\nlines"], 'empty' => [], 'obj' => new stdClass(), 'quote' => 'say "hi" \\ there', 'unicode' => 'Café ✓', 'n' => -3.5];
$back = und_yaml_read(und_yaml_emit($cy));
t('YAML written by UnDash reads back exactly', $back['general']['title'] === "a: b # c" && $back['list'] === ['x', '12', 12, true, 'true', null, '', "two\nlines"] && $back['empty'] === [] && $back['obj'] === []
  && $back['quote'] === 'say "hi" \\ there' && $back['unicode'] === 'Café ✓' && $back['n'] === -3.5, json_encode($back));
$hy = und_yaml_read("a: { x: 1, 'y z': \"q, r\", n: { m: [1, 2, {k: v}] } }\nb: [ {id: cpu, visible: false}, x ]\nc: {{NAME}}\nd: {}\ne: [\"a, b\", c]\nf: { broken: [1, 2 }\n");
t('hand-written flow maps and lists read like block ones', $hy['a'] === ['x' => 1, 'y z' => 'q, r', 'n' => ['m' => [1, 2, ['k' => 'v']]]] && $hy['b'] === [['id' => 'cpu', 'visible' => false], 'x']
  && $hy['c'] === '{{NAME}}' && $hy['d'] === [] && $hy['e'] === ['a, b', 'c'] && is_string($hy['f']), json_encode($hy));
$tpl = und_yaml_read(file_get_contents(dirname(__DIR__) . '/examples/config.yaml'));
$tcfg = und_normalize(und_migrate(['schema' => $tpl['version']] + $tpl));
$rt = und_normalize(und_migrate(['schema' => 5] + und_yaml_read(und_yaml_emit(json_decode(json_encode($tcfg), true)))));
t('a full real-world config survives YAML unchanged', json_encode($rt) === json_encode($tcfg));
$ev = und_env_read(und_env_text(['A' => 'plain', 'B' => 'with space', 'C' => 'quote"and\\back', 'D' => '', 'E' => "multi\nline"]));
t('.env written by UnDash reads back exactly', $ev === ['A' => 'plain', 'B' => 'with space', 'C' => 'quote"and\\back', 'D' => '', 'E' => "multi\nline"], json_encode($ev));

/* ---------------- export / import ---------------- */
group('migration');
reset_store();
$dir = und_store_dir();
@mkdir($dir, 0777, true);
file_put_contents("$dir/config.json", json_encode(['schema' => 4, 'general' => ['title' => 'Old'], 'groups' => [['name' => 'Media', 'services' => [
  ['id' => 'aaaaaaaa', 'name' => 'Radarr', 'type' => 'radarr', 'url' => 'http://{{HOMEPAGE_VAR_IP}}:7878', 'href' => ''],
  ['id' => 'bbbbbbbb', 'name' => 'qBittorrent', 'type' => 'qbittorrent', 'url' => 'http://{{HOMEPAGE_VAR_IP}}:8080', 'href' => '']]]]]));
file_put_contents("$dir/secrets.json", json_encode(['vars' => ['HOMEPAGE_VAR_IP' => '10.0.0.5', 'HOMEPAGE_VAR_RADARR_KEY' => 'RKEY', 'UNUSED' => 'x'],
  'svc' => ['aaaaaaaa' => ['key' => '{{HOMEPAGE_VAR_RADARR_KEY}}'], 'bbbbbbbb' => ['username' => 'admin', 'password' => 'QPASS']], 'unraid' => ['url' => 'http://tower/graphql', 'key' => 'UKEY']]));
file_put_contents("$dir/custom.css", '.x{color:red}');
file_put_contents("$dir/ignores.json", json_encode([['id' => 'ffffffffffff', 'kind' => 'type', 'category' => 'os.available', 'until' => null, 'created' => time()]]));
@mkdir("$dir/backups", 0700, true); file_put_contents("$dir/backups/20260101-000000-abcd.json", '{}');
$m = und_load_config();
$yaml = (string)file_get_contents("$dir/config.yaml"); $envt = (string)file_get_contents("$dir/.env");
$apps = $m['groups'][0]['services'];
t('old settings become config.yaml and .env', $m['general']['title'] === 'Old' && count($apps) === 2);
t('addresses held in variables are written into the apps', $apps[0]['url'] === 'http://10.0.0.5:7878' && strpos($yaml, 'HOMEPAGE_VAR') === false);
t('credentials move to readable .env names', $apps[0]['key'] === '{{RADARR_API_KEY}}' && $apps[1]['username'] === '{{QBITTORRENT_USERNAME}}' && $apps[1]['password'] === '{{QBITTORRENT_PASSWORD}}');
t('.env holds the values, config.yaml none of them', und_env()['RADARR_API_KEY'] === 'RKEY' && und_env()['QBITTORRENT_PASSWORD'] === 'QPASS' && und_env()['UNRAID_API_KEY'] === 'UKEY'
  && strpos($yaml, 'RKEY') === false && strpos($yaml, 'QPASS') === false && strpos($yaml, 'UKEY') === false && strpos($yaml, 'admin') === false);
t('unused variables are dropped', !isset(und_env()['UNUSED']) && strpos($envt, 'UNUSED') === false);
t('Unraid address, custom CSS and ignore rules move into config.yaml', und_doc()['unraid']['url'] === 'http://tower/graphql' && und_load_css() === '.x{color:red}' && count(und_ignores()) === 1);
t('the old files are kept in data/legacy, never deleted', is_file("$dir/data/legacy/config.json") && is_file("$dir/data/legacy/secrets.json") && is_file("$dir/data/legacy/custom.css") && !is_file("$dir/config.json"));
t('old backups move to data/backups', is_file("$dir/data/backups/20260101-000000-abcd.json"));
t('only config.yaml and .env are left at the top', array_values(array_diff(scandir($dir), ['.', '..', 'data'])) === ['.env', 'config.yaml'], implode(',', scandir($dir)));
$m2 = und_load_config(); t('a second load changes nothing', json_encode($m2['groups']) === json_encode($m['groups']) && file_get_contents("$dir/config.yaml") === $yaml, json_encode($m2['groups']) . ' vs ' . json_encode($m['groups']));

$cv = und_store_convert(und_normalize(['groups' => [['id' => 'aaaaaaaa', 'name' => 'G', 'services' => [
  ['id' => 'c0000001', 'name' => 'Tautulli', 'type' => 'tautulli', 'url' => 'http://t:8181', 'href' => ''],
  ['id' => 'c0000002', 'name' => 'Recent', 'type' => 'customapi', 'url' => 'http://t:8181/api/v2?apikey={{HOMEPAGE_VAR_TAUTULLI_API_KEY}}&cmd=x', 'href' => ''],
  ['id' => 'c0000003', 'name' => 'Other', 'type' => 'customapi', 'url' => 'http://o/?token={{HOMEPAGE_VAR_RADARR_API_KEY}}', 'href' => ''],
  ['id' => 'c0000004', 'name' => 'Radarr', 'type' => 'radarr', 'url' => 'http://r', 'href' => '']]]]]),
  ['HOMEPAGE_VAR_TAUTULLI_API_KEY' => 'TK', 'HOMEPAGE_VAR_RADARR_API_KEY' => 'OTHER'], ['c0000001' => ['key' => '{{HOMEPAGE_VAR_TAUTULLI_API_KEY}}'], 'c0000004' => ['key' => 'RK']]);
$cs = $cv['config']['groups'][0]['services'];
t('a key used in an address and as a credential shares one name', $cs[1]['url'] === 'http://t:8181/api/v2?apikey={{TAUTULLI_API_KEY}}&cmd=x' && $cv['env']['TAUTULLI_API_KEY'] === 'TK' && !isset($cv['env']['HOMEPAGE_VAR_TAUTULLI_API_KEY']));
t('a different value under a taken name gets its own name', $cv['env']['RADARR_API_KEY'] === 'RK' && $cv['env']['RADARR_API_KEY_2'] === 'OTHER' && strpos($cs[2]['url'], '{{RADARR_API_KEY_2}}') !== false, json_encode($cv['env']));
t('config.yaml has no schema line next to version', strpos(file_get_contents("$dir/config.yaml"), "\nschema:") === false);

group('backups');
$before = file_get_contents("$dir/config.yaml"); $envBefore = file_get_contents("$dir/.env");
$m['general']['title'] = 'Changed';
und_save_config($m);
$bk = array_values(array_filter(und_backups(), function ($b) { return $b['schema'] === UND_SCHEMA; }));
t('a save takes a backup of both files', count($bk) >= 1);
$raw = json_decode(file_get_contents(und_backup_dir() . '/' . $bk[0]['id'] . '.json'), true);
t('the backup holds config.yaml and .env as they were', $raw['undash_backup'] === 2 && $raw['yaml'] === $before && $raw['env'] === $envBefore);
t('backups are private', (fileperms(und_backup_dir() . '/' . $bk[0]['id'] . '.json') & 0777) === 0600);
und_env_write(['RADARR_API_KEY' => 'CHANGED']);
t('restore brings both files back', !empty(und_restore($bk[0]['id'])['ok']) && und_load_config()['general']['title'] === 'Old' && und_env()['RADARR_API_KEY'] === 'RKEY');
t('unknown backup ids are refused', isset(und_restore('../../etc/passwd')['error']) && isset(und_restore('20990101-000000-ffff')['error']));
file_put_contents(und_backup_dir() . '/20250101-000000-0aaa.json', json_encode(['undash_backup' => 1, 'schema' => 4, 'config' => ['schema' => 4, 'general' => ['title' => 'Legacy'], 'groups' => [['name' => 'G', 'services' => [['id' => 'cccccccc', 'name' => 'Sonarr', 'type' => 'sonarr', 'url' => 'http://s', 'href' => '']]]]],
  'secrets' => ['vars' => [], 'svc' => ['cccccccc' => ['key' => 'SKEY']]], 'css' => '']));
t('a backup from before config.yaml is converted on restore', !empty(und_restore('20250101-000000-0aaa')['ok']) && und_load_config()['general']['title'] === 'Legacy'
  && und_load_config()['groups'][0]['services'][0]['key'] === '{{SONARR_API_KEY}}' && und_env()['SONARR_API_KEY'] === 'SKEY');

group('export');
reset_store();
$cfg = und_default_config();
$cfg['groups'][] = ['id' => 'abcdef01', 'name' => 'Apps', 'columns' => 3, 'header' => true, 'services' => [
  ['id' => 'abcdef02', 'name' => 'Radarr', 'type' => 'radarr', 'url' => 'http://admin:hunter2@10.0.0.2:7878/?apikey=PLAINKEY123&x=1', 'href' => 'http://10.0.0.2:7878', 'fields' => [], 'options' => []],
  ['id' => 'abcdef03', 'name' => 'Custom', 'type' => 'customapi', 'url' => 'http://{{SERVER_IP}}/api?token={{TOKEN_VAR}}', 'href' => '', 'fields' => [], 'options' => ['headers' => ['X-Api-Key' => 'HEADERSECRET', 'X-Ref' => '{{SOME_KEY}}']]]]];
und_save_config($cfg);
und_save_secrets(['vars' => ['SERVER_IP' => '10.0.0.2', 'TOKEN_VAR' => 'VARSECRET1'], 'svc' => ['abcdef02' => ['key' => 'SVCSECRET', 'username' => 'u', 'password' => 'PWSECRET']]]);
und_save_css('.card{color:red}');
$cy = und_export('config');
foreach (['VARSECRET1', 'SVCSECRET', 'PWSECRET', 'hunter2', 'PLAINKEY123', 'HEADERSECRET'] as $s) t("the config export has no $s", strpos($cy['text'], $s) === false);
t('the config export is config.yaml with layout, references and css', $cy['name'] === 'undash-config.yaml' && strpos($cy['text'], 'Radarr') !== false && strpos($cy['text'], '{{RADARR_API_KEY}}') !== false
  && strpos($cy['text'], '{{TOKEN_VAR}}') !== false && strpos($cy['text'], 'color:red') !== false);
$ex = und_export('env');
t('the .env export has the secrets', $ex['name'] === 'undash.env' && strpos($ex['text'], 'RADARR_API_KEY=SVCSECRET') !== false && strpos($ex['text'], 'TOKEN_VAR=VARSECRET1') !== false);
t('the .env export never carries the dashboard password hash', strpos($ex['text'], 'UNDASH_PASSWORD_HASH') === false);

group('import');
t('empty file refused', isset(und_import_parse('')['error']));
t('invalid JSON refused', isset(und_import_parse('{nope')['error']));
t('too large refused', isset(und_import_parse(str_repeat(' ', 2097153))['error']));
t('random JSON refused', isset(und_import_parse('{"a":1}')['error']));
t('random text refused', isset(und_import_parse("hello\nworld: [")['error']));
t('newer schema refused', isset(und_import_parse(json_encode(['undash_export' => 1, 'schema' => 99, 'config' => ['groups' => []]]))['error']) && isset(und_import_parse("version: 99\ngroups: []\n")['error']));
t('export without layout refused', isset(und_import_parse(json_encode(['undash_export' => 1, 'schema' => 2, 'config' => ['x' => 1]]))['error']));
t('malformed variables refused', isset(und_import_parse(json_encode(['undash_export' => 1, 'schema' => 2, 'config' => ['groups' => []], 'vars' => ['bad name' => 'x']]))['error']));
t('malformed credentials refused', isset(und_import_parse(json_encode(['undash_export' => 1, 'schema' => 2, 'config' => ['groups' => []], 'secrets' => ['../x' => ['key' => 1]]]))['error']));
t('deeply nested JSON refused', isset(und_import_parse(str_repeat('[', 200) . str_repeat(']', 200))['error']));
$p = und_import_parse(json_encode(['undash_export' => 1, 'schema' => 2, 'kind' => 'redacted', 'config' => ['groups' => [['name' => 'G', 'services' => [['type' => 'radarr', 'name' => 'R'], ['type' => 'rm -rf', 'name' => 'X']]]]]]));
t('unknown app types skipped and counted', $p['summary']['widgets'] === 1 && $p['summary']['skipped'] === 1);
// config.yaml: replaces the layout, keeps .env
$y = str_replace('title: UnDash', 'title: Imported', und_export('config')['text']);
$r = und_import_apply($y);
t('a config.yaml import is applied', isset($r['ok']) && und_load_config()['general']['title'] === 'Imported');
t('... and keeps every stored secret', und_service_full(und_load_config(), 'abcdef02')['key'] === 'SVCSECRET' && und_env()['TOKEN_VAR'] === 'VARSECRET1');
t('import took a backup first', (function () { foreach (und_backups() as $b) if ($b['reason'] === 'before import') return true; return false; })());
$r = und_import_apply("# mine\nRADARR_API_KEY=NEWKEY\nEXTRA=1\n");
t('a .env import adds and replaces values', isset($r['ok']) && und_env()['RADARR_API_KEY'] === 'NEWKEY' && und_env()['EXTRA'] === '1' && und_env()['TOKEN_VAR'] === 'VARSECRET1');
// an export of an older version (JSON): converted, its credentials go to .env
$legacy = ['undash_export' => 1, 'schema' => 4, 'kind' => 'full', 'config' => ['groups' => [['id' => 'cccccccc', 'name' => 'G', 'services' => [['id' => 'dddddddd', 'type' => 'sonarr', 'name' => 'Sonarr', 'url' => 'http://{{HOMEPAGE_VAR_IP}}:{{HOMEPAGE_VAR_PORT}}']]]]],
  'vars' => ['HOMEPAGE_VAR_IP' => '10.5.5.5', 'HOMEPAGE_VAR_PORT' => '8989', 'HOMEPAGE_VAR_SONARR_KEY' => 'SONKEY'], 'secrets' => ['dddddddd' => ['key' => '{{HOMEPAGE_VAR_SONARR_KEY}}']], 'unraid' => ['url' => 'http://10.5.5.5/graphql']];
$sx = und_secrets(); $sx['unraid'] = ['url' => 'http://10.9.9.9/graphql', 'key' => 'MYKEY']; und_save_secrets($sx);
und_import_apply(json_encode($legacy));
$so = und_load_config()['groups'][0]['services'][0];
t('an old export is converted: addresses written in, the key as a reference', $so['url'] === 'http://10.5.5.5:8989' && $so['key'] === '{{SONARR_API_KEY}}' && und_env()['SONARR_API_KEY'] === 'SONKEY');
t('an import keeps the existing Unraid API address and key', und_secrets()['unraid']['url'] === 'http://10.9.9.9/graphql' && und_secrets()['unraid']['key'] === 'MYKEY');
$sx = und_secrets(); $sx['unraid'] = ['url' => '', 'key' => 'MYKEY']; und_save_secrets($sx);
und_import_apply(json_encode($legacy));
t('... and fills the address in when none is set', und_secrets()['unraid']['url'] === 'http://10.5.5.5/graphql');
t('layout-only (old export) files are accepted', !isset(und_import_parse(json_encode(['groups' => [], 'general' => ['title' => 'x']]))['error']));
$tx = file_get_contents(dirname(__DIR__) . '/examples/config.yaml');
t('the example config imports: 31 apps, no secrets, no Homepage names', und_import_parse($tx)['summary']['widgets'] === 31 && strpos($tx, 'HOMEPAGE') === false && !preg_match('/^\s*(key|password|username): "?[^"{\s]/m', $tx));
reset_store();
und_import_apply($tx);
t('names the example uses get an empty entry to fill in', und_env() === ['TAUTULLI_API_KEY' => '', 'WIZARR_API_KEY' => ''] && count(array_filter(und_shared_view(und_load_config()), function ($v) { return $v['used']; })) === 2);

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
t('rule written to config.yaml in the config dir and the flash mirror', strpos((string)@file_get_contents(UND_DIR . '/config.yaml'), 'disk.hot') !== false && strpos((string)@file_get_contents(und_store_dir() . '/config.yaml'), 'disk.hot') !== false);
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
$raw = und_doc()['ignored_alerts'];
$raw[] = ['id' => 'aaaaaaaaaaaa', 'kind' => 'instance', 'key' => 'os.available:7.2.0', 'category' => 'os.available', 'resource' => '7.2.0', 'until' => time() - 60, 'created' => time() - 3660, 'title' => 'x', 'severity' => 'info'];
$raw[] = ['id' => 'bbbbbbbbbbbb', 'kind' => 'instance', 'category' => 'nope', 'resource' => 'x'];
$raw[] = 'garbage';
$raw[] = ['id' => 'NOT-AN-ID', 'kind' => 'type', 'category' => 'disk.bad'];
$raw[] = ['id' => 'cccccccc', 'kind' => 'instance', 'category' => 'disk.bad', 'resource' => 'evil;rm', 'until' => null];
und_doc_write(['ignored_alerts' => $raw]);
t('malformed records are dropped, valid ones kept', count(und_ignores()) === 3);
t('an expired rule is listed but not active', count(und_ignores_active()) === 2 && !in_array('aaaaaaaaaaaa', array_column(und_ignores_active(), 'id'), true));
und_doc_write(['ignored_alerts' => 'not a list']);
t('an unreadable list means no rules, not an error', und_ignores() === []);
und_doc_write(['ignored_alerts' => $raw]);
$pe = und_ignore_purge_expired();
t('delete expired removes only the expired rule', $pe['removed'] === 1 && count(und_ignores()) === 2);
t('purging also wrote out a clean file', count(und_yaml_read((string)file_get_contents(und_store_dir() . '/config.yaml'))['ignored_alerts']) === 2);
$rm = und_ignore_remove(['zzzzzzzzzzzz', 'not valid']);
t('restoring unknown ids removes nothing', isset($rm['error']) && count(und_ignores()) === 2);
$rm = und_ignore_remove([$p['rule']['id']]);
t('restore removes just that rule', $rm['removed'] === 1 && und_ignores()[0]['key'] === 'disk.hot:disk3');
und_ignore_add('instance', 'docker.unhealthy', 'plex', 3600);
$ra = und_ignore_restore_all();
t('restore all empties the list', $ra['removed'] === 2 && und_ignores() === []);
// a rule from a month ago that ran out is cleaned up on the next write
und_doc_write(['ignored_alerts' => [['id' => 'dddddddddddd', 'kind' => 'type', 'category' => 'disk.hot', 'until' => time() - 40 * 86400, 'created' => time() - 50 * 86400]]]);
und_ignore_add('instance', 'disk.hot', 'disk1', 3600);
t('long-expired rules are pruned on write', count(und_ignores()) === 1 && und_ignores()[0]['resource'] === 'disk1');
// rules survive a lost store directory: the flash mirror still has them (and the reverse on first use)
reset_store();
@mkdir(UND_DIR, 0777, true);
file_put_contents(UND_DIR . '/config.yaml', und_yaml_emit(['version' => UND_SCHEMA, 'ignored_alerts' => [['id' => 'eeeeeeeeeeee', 'kind' => 'type', 'category' => 'os.available', 'until' => null, 'created' => time()]]]));
t('rules on the flash drive are picked up', count(und_ignores()) === 1);
t('config.yaml is copied into a new config dir', is_file(und_store_dir() . '/config.yaml') || und_store_dir() === UND_DIR);
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
group('validate');
reset_store();
$GLOBALS['UND_TEST_ENV'] = ['RADARR_API_KEY' => 'x', 'SONARR_API_KEY' => ''];
$iv = array_column(und_validate(und_normalize(['groups' => [['id' => 'aaaaaaaa', 'name' => 'G', 'services' => [
  ['id' => 'd0000001', 'type' => 'radarr', 'name' => 'Radarr', 'url' => 'http://r', 'href' => '', 'key' => '{{RADARR_API_KEY}}'],
  ['id' => 'd0000002', 'type' => 'sonarr', 'name' => 'Sonarr', 'url' => 'http://s', 'href' => '', 'key' => '{{SONARR_API_KEY}}'],
  ['id' => 'd0000003', 'type' => 'prowlarr', 'name' => 'Prowlarr', 'url' => '', 'href' => ''],
  ['id' => 'd0000004', 'type' => 'link', 'name' => 'Link', 'url' => '', 'href' => 'http://l']]]]])), 'msg');
unset($GLOBALS['UND_TEST_ENV']);
t('check configuration names apps without an address or a key', count($iv) === 3 && strpos(implode('|', $iv), '"Sonarr" in "G" has no API key') !== false && strpos(implode('|', $iv), '"Prowlarr" in "G" has no address') !== false, json_encode($iv));

group('links');
$lv = und_layout_view(und_normalize(['groups' => [['id' => 'aaaaaaaa', 'name' => 'G', 'services' => [
  ['id' => 'b0000001', 'type' => 'radarr', 'name' => 'R', 'url' => 'http://10.0.0.5:7878', 'href' => ''],
  ['id' => 'b0000002', 'type' => 'radarr', 'name' => 'R2', 'url' => 'http://user:pw@10.0.0.5:7878', 'href' => ''],
  ['id' => 'b0000003', 'type' => 'radarr', 'name' => 'R3', 'url' => 'http://10.0.0.5:7878/?apikey=x', 'href' => ''],
  ['id' => 'b0000004', 'type' => 'radarr', 'name' => 'R4', 'url' => 'http://10.0.0.5:7878', 'href' => 'https://radarr.example.com']]]]]))['groups'][0]['services'];
t('an app without a link opens its address', $lv[0]['href_resolved'] === 'http://10.0.0.5:7878');
t('an address with a login or a query string is never used as the link', $lv[1]['href_resolved'] === '' && $lv[2]['href_resolved'] === '');
t('a link of its own wins', $lv[3]['href_resolved'] === 'https://radarr.example.com');

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
t('health probes app links (or their address) and running container web UIs, each address once', array_column($tg, 'hostport') === ['10.0.0.5:32400', 'app.lan:1', '10.0.0.5:8096', '10.0.0.5:5055', '10.0.0.5:8989'], json_encode(array_column($tg, 'hostport')));
$GLOBALS['UND_HEALTH_MOCK'] = function ($t) { $o = []; foreach ($t as $i => $x) $o[$i] = $i === 0 ? ['code' => 0, 'ms' => null, 'err' => 'Connection refused'] : ['code' => $i === 1 ? 404 : ($i === 2 ? 401 : 200), 'ms' => 5, 'err' => '']; return $o; };
$hl = und_health($cfgS, true);
t('health sorts problems first and counts reachable endpoints', $hl['rows'][0]['level'] === 'bad' && $hl['rows'][0]['text'] === 'Connection refused' && $hl['up'] === $hl['total'] - 1);
t('a login page (401) counts as healthy, a 404 as a warning', (bool)array_filter($hl['rows'], function ($r) { return $r['code'] === 404 && $r['level'] === 'warn'; })
  && (bool)array_filter($hl['rows'], function ($r) { return $r['code'] === 401 && $r['level'] === 'ok' && $r['text'] === '401 Login required'; }));
unset($GLOBALS['UND_HEALTH_MOCK']);
reset_store();

/* ---------------- qui ---------------- */
group('qui');
reset_store();
$GLOBALS['UND_TEST_ENV'] = ['QUI_API_KEY' => 'QUIKEY'];
$cfgQ = und_normalize(['groups' => [['id' => 'aaaaaaaa', 'name' => 'Downloads', 'services' => [['id' => 'e0000001', 'type' => 'qui', 'name' => 'qui', 'url' => 'http://qui.lan:7476', 'href' => '', 'key' => '{{QUI_API_KEY}}', 'fields' => [], 'options' => []]]]]]);
$GLOBALS['SEEN'] = [];
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) {
  $GLOBALS['SEEN'][] = [$url, $o['headers']['X-API-Key'] ?? ''];
  $p = parse_url($url, PHP_URL_PATH);
  $j = function ($d, $c = 200) { return ['code' => $c, 'body' => json_encode($d), 'json' => $d, 'err' => '', 'headers' => []]; };
  if ($p === '/api/instances') return $j([['id' => 1, 'name' => 'Main', 'connected' => true], ['id' => 2, 'name' => 'Private', 'connected' => true], ['id' => 3, 'name' => 'Old box', 'connected' => false, 'connectionStatus' => 'connection refused']]);
  if ($p === '/api/instances/1/torrents') return $j(['stats' => ['total' => 120, 'downloading' => 2, 'seeding' => 100, 'paused' => 3, 'error' => 1, 'checking' => 0, 'totalDownloadSpeed' => 5000000, 'totalUploadSpeed' => 200000],
    'counts' => ['status' => ['inactive' => 80, 'stalled' => 4, 'unregistered' => 2, 'tracker_down' => 1, 'completed' => 110], 'tags' => ['cross-seed' => 40, 'movies' => 9]],
    'torrents' => [['name' => 'Big.File', 'state' => 'downloading', 'progress' => 0.5, 'dlspeed' => 4000000, 'eta' => 600, 'size' => 1e9], ['name' => 'Seeded', 'state' => 'uploading', 'progress' => 1, 'dlspeed' => 0]]]);
  if ($p === '/api/instances/2/torrents') return $j(['stats' => ['total' => 10, 'downloading' => 1, 'seeding' => 9, 'totalDownloadSpeed' => 1000000, 'totalUploadSpeed' => 100000],
    'counts' => ['status' => ['inactive' => 5], 'tags' => ['cross-seed' => 2]], 'torrents' => [['name' => 'Small', 'state' => 'stalledDL', 'progress' => 0.1, 'dlspeed' => 0, 'eta' => 8640000]]]);
  return $j(['error' => 'nope'], 404);
};
$q = und_dl_status($cfgQ, 'e0000001');
t('qui is a download client', in_array('qui', und_dl_types(), true) && und_zone(['type' => 'qui']) === 'downloads');
t('the API key goes in the X-API-Key header, never in the address', $GLOBALS['SEEN'][0][1] === 'QUIKEY' && !array_filter($GLOBALS['SEEN'], function ($x) { return strpos($x[0], 'QUIKEY') !== false; }));
t('totals add up every connected instance', $q['counts']['downloading'] === 3 && $q['counts']['seeding'] === 109 && $q['counts']['idle'] === 85 && $q['counts']['crossseed'] === 42
  && $q['counts']['stalled'] === 4 && $q['counts']['tracker'] === 3 && $q['counts']['paused'] === 3 && $q['counts']['error'] === 1, json_encode($q['counts']));
t('speeds are summed', $q['down'] === 6000000.0 && $q['up'] === 300000.0);
t('one line per instance; a disconnected one says why', count($q['instances']) === 3 && $q['instances'][1]['name'] === 'Private' && !$q['instances'][2]['online'] && $q['instances'][2]['error'] === 'connection refused');
t('only downloading torrents are listed, fastest first', array_column($q['items'], 'name') === ['Big.File', 'Small'] && $q['items'][1]['state'] === 'stalled' && $q['items'][1]['eta'] === null);
t('a note says how many instances are down', strpos($q['note'], '1 of 3') === 0 && $q['state'] === 'downloading');
t('qui is read only: no pause button, pause refused', $q['can_pause'] === false && isset(und_dl_action($cfgQ, 'e0000001', 'pause')['error']));
$w = und_fetch_service($cfgQ, 'e0000001');
t('the qui app card shows seeding, idle and cross-seed', array_column($w['blocks'], 'key') === ['downloading', 'seeding', 'idle', 'crossseed', 'download', 'upload', 'instances'] && $w['blocks'][3]['value'] === 42);
$GLOBALS['UND_HTTP_MOCK'] = function () { return ['code' => 401, 'body' => '', 'json' => null, 'err' => '', 'headers' => []]; };
@unlink(und_tmp('api-dl-e0000001.json'));
t('a wrong key is reported without the key', strpos(und_dl_status($cfgQ, 'e0000001')['error'] ?? '', 'Not authorised') !== false);
unset($GLOBALS['UND_HTTP_MOCK'], $GLOBALS['UND_TEST_ENV']);
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

// the built plugin must be well-formed XML, or Unraid cannot install it (not present inside the Docker build)
$plg = dirname(__DIR__) . '/undash.plg';
if (is_file($plg)) { libxml_use_internal_errors(true); $x = simplexml_load_file($plg); t('undash.plg is well-formed XML with a version', $x !== false && (string)$x['version'] !== '', implode('; ', array_map(function ($e) { return trim($e->message) . ' line ' . $e->line; }, libxml_get_errors()))); }

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
