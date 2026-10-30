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
$dn = und_normalize(['groups' => [], 'docker' => ['items' => ['plex' => ['no_update' => 1], 'sonarr' => ['no_update' => false]]]])['docker'];
t('container update checks are on by default', $dn['update_check'] === true && und_normalize(['groups' => [], 'docker' => ['update_check' => false]])['docker']['update_check'] === false);
t('a container can skip the update check', ($dn['items']['plex']['no_update'] ?? null) === true && !isset(((array)$dn['items'])['sonarr']));
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
group('compact config');
$cfg = und_load_config();
$cfg['groups'][0]['services'][] = ['id' => 'c0000001', 'type' => 'radarr', 'name' => 'Lean', 'url' => 'http://r', 'key' => '{{LEAN_KEY}}'];
und_save_config($cfg, false);
$yaml = (string)file_get_contents(und_file('config.yaml'));
$lean = und_load_config()['groups'][0]['services'];
$lean = $lean[count($lean) - 1];
t('apps are written without their empty fields', strpos($yaml, 'description: ""') === false && strpos($yaml, 'favorite: false') === false && strpos($yaml, 'password: ""') === false);
t('and read back with every field filled in', $lean['name'] === 'Lean' && $lean['href'] === '' && $lean['span'] === 1 && $lean['favorite'] === false && $lean['key'] === '{{LEAN_KEY}}' && $lean['username'] === '');
$cfg = und_load_config();
$cfg['docker']['items'] = ['plex' => ['hidden' => true], 'sonarr' => ['label' => 'TV', 'no_update' => true]];
$cfg['vms']['items'] = ['Win11' => ['hidden' => true]];
und_save_config($cfg, false);
$yaml = (string)file_get_contents(und_file('config.yaml'));
$back = und_load_config();
t('containers and VMs picked in Settings are written as one line each', strpos($yaml, "plex:\n      hidden: true\n") !== false && strpos($yaml, "Win11:\n      hidden: true\n") !== false && strpos($yaml, 'label: ""') === false);
t('and read back complete', $back['docker']['items']['plex']['hidden'] === true && $back['docker']['items']['plex']['label'] === '' && $back['docker']['items']['sonarr']['no_update'] === true && $back['vms']['items']['Win11']['hidden'] === true);

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
  ['id' => 'd0000004', 'type' => 'link', 'name' => 'Link', 'url' => '', 'href' => 'http://l'],
  ['id' => 'd0000005', 'type' => 'customapi', 'name' => 'Custom', 'url' => 'http://c/api?apikey={{RADARR_API_KEY}}', 'href' => '']]]]])), 'msg');
unset($GLOBALS['UND_TEST_ENV']);
t('check configuration names apps without an address or a key (a custom API needs no key of its own)', count($iv) === 3 && strpos(implode('|', $iv), '"Sonarr" in "G" has no API key') !== false && strpos(implode('|', $iv), '"Prowlarr" in "G" has no address') !== false, json_encode($iv));

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
$GLOBALS['MOCK2'] = $GLOBALS['MOCK'];
$GLOBALS['MOCK'] = function ($argv) {
  if ($argv[1] !== 'inspect') return ['code' => 1];
  return ['out' => json_encode([['Name' => '/radarr', 'Config' => ['Image' => 'lscr.io/linuxserver/radarr', 'Labels' => ['org.opencontainers.image.source' => 'https://github.com/linuxserver/docker-radarr']], 'HostConfig' => [], 'State' => []]])];
};
t('the image\'s source label is kept with the container', und_docker_details(['radarr' => ['id' => 'a4', 'state' => 'running']], true)['radarr']['source'] === 'https://github.com/linuxserver/docker-radarr');
$GLOBALS['MOCK'] = $GLOBALS['MOCK2'];
t('GitHub repositories from source addresses', und_github_repo_of('https://github.com/linuxserver/docker-radarr') === 'linuxserver/docker-radarr' && und_github_repo_of('git@github.com:immich-app/immich.git') === 'immich-app/immich'
  && und_github_repo_of('https://github.com/autobrr/qui/tree/main') === 'autobrr/qui' && und_github_repo_of('https://gitlab.com/a/b') === '' && und_github_repo_of('https://github.com/a/..') === '');
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
$tw = und_w_tautulli(und_service_full($cfgS, 'a0000002'));
t('app cards get the same stream details as Now playing', $tw['streams'][0]['method'] === 'transcode' && $tw['streams'][0]['location'] === 'wan' && $tw['streams'][0]['bandwidth'] === 8000000 && $tw['empty'] === 'No active streams');
$qi = und_arr_queue_issues(array_merge(array_map(function ($e) { return ['title' => 'The.Mentalist.S03E' . sprintf('%02d', $e) . '.1080p', 'seasonNumber' => 3, 'series' => ['title' => 'The Mentalist'], 'trackedDownloadStatus' => 'warning', 'trackedDownloadState' => 'importBlocked', 'statusMessages' => [['messages' => ['Episode was not found']]]]; }, range(1, 16)),
  [['title' => 'Some.Show.S01E02.720p', 'trackedDownloadStatus' => 'ok', 'trackedDownloadState' => 'downloading'], ['title' => 'Movie.2024.1080p', 'movie' => ['title' => 'Movie', 'year' => 2024], 'trackedDownloadStatus' => 'error', 'trackedDownloadState' => 'failedPending', 'errorMessage' => 'Download failed']]));
t('Arr queue problems are grouped per release and problem', count($qi) === 2 && $qi[0]['title'] === 'Movie (2024)' && $qi[0]['level'] === 'bad' && $qi[0]['tag'] === 'Failed'
  && $qi[1]['title'] === 'The Mentalist · Season 3' && $qi[1]['count'] === 16 && $qi[1]['tag'] === 'Import blocked' && $qi[1]['msg'] === 'Episode was not found' && count($qi[1]['releases']) === 5);
$hi = und_arr_health_item('warn', 'IndexerLongTermStatusCheck', 'Indexers unavailable due to failures for more than 6 hours: X');
$hc = und_arr_health_item('warn', 'SomethingNewCheck', 'Caution: be careful');
t('Arr health checks get a short tag', $hi['tag'] === 'Indexer' && und_arr_health_item('warn', 'RemovedSeriesCheck', 'x')['tag'] === 'Series removed' && $hc['tag'] === 'Caution' && $hc['msg'] === 'be careful' && und_arr_health_item('warn', 'SomethingNewCheck', 'x')['tag'] === 'Something New');
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
t('style defaults to posters', $m['style'] === 'art' && und_normalize(['groups' => [], 'media' => ['style' => 'text']])['media']['style'] === 'text' && und_normalize(['groups' => [], 'media' => ['style' => 'x']])['media']['style'] === 'art');
t('requests style defaults to posters', und_normalize(['groups' => []])['requests']['style'] === 'art' && und_normalize(['groups' => [], 'requests' => ['style' => 'text']])['requests']['style'] === 'text' && und_normalize(['groups' => [], 'requests' => 'x'])['requests']['style'] === 'art');
t('section names shown by default and can be hidden', und_normalize(['groups' => []])['appearance']['sec_names'] === true && und_normalize(['groups' => [], 'appearance' => ['sec_names' => false]])['appearance']['sec_names'] === false);
t('icon mode defaults to color and is checked', und_normalize(['groups' => []])['appearance']['icon_mode'] === 'color' && und_normalize(['groups' => [], 'appearance' => ['icon_mode' => 'auto']])['appearance']['icon_mode'] === 'auto' && und_normalize(['groups' => [], 'appearance' => ['icon_mode' => 'x']])['appearance']['icon_mode'] === 'color');
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

group('nut');
// a one-shot NUT daemon on a free local port, answering LIST VAR the way upsd does
$srv = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($srv, false), ':'), 1); fclose($srv);
$fake = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:' . $port . '"); $c = stream_socket_accept($s, 5); fgets($c);'
  . ' fwrite($c, "BEGIN LIST VAR ups\nVAR ups battery.charge \"87\"\nVAR ups battery.runtime \"1800\"\nVAR ups ups.load \"12\"\nVAR ups ups.status \"OB DISCHRG\"\nVAR ups ups.model \"Back-UPS\"\nEND LIST VAR ups\n"); fclose($c);'], [], $pipes);
usleep(300000);
$u = und_nut_tcp('127.0.0.1:' . $port, 'ups');
proc_close($fake);
t('a NUT UPS is read over TCP', is_array($u) && $u['charge'] === 87.0 && $u['load'] === 12.0 && $u['runtime'] === 1800 && $u['status'] === 'On battery, Discharging' && $u['model'] === 'Back-UPS', json_encode($u));
t('no NUT daemon means no UPS', und_nut_tcp('127.0.0.1:' . $port, 'ups') === null);
t('odd UPS names are refused', und_nut_tcp('127.0.0.1', "ups\nFSD") === null);
$apcOut = "STATUS   : ONLINE\nBCHARGE  : 100.0 Percent\nLOADPCT  : 24.0 Percent\nTIMELEFT : 52.0 Minutes\nMODEL    : Back-UPS XS 1000M\n";
$GLOBALS['MOCK'] = function ($argv) use (&$apcOut) { return basename($argv[0]) === 'apcaccess' ? ['code' => 0, 'out' => $apcOut, 'err' => ''] : ['code' => 1, 'out' => '', 'err' => 'no']; };
$u = und_ups('ups');
t('without a rated power there are no watts', $u['load'] === 24.0 && $u['watts'] === null && $u['nominal'] === null);
$apcOut .= "NOMPOWER : 600 Watts\n";
$u = und_ups('ups');
t('apcupsd NOMPOWER gives the power draw', $u['nominal'] === 600 && $u['watts'] === 144);
t('the rated power from Settings wins', und_ups('ups', 900)['watts'] === 216 && und_ups('ups', 900)['nominal'] === 900);
unset($GLOBALS['MOCK']);
$nv = und_nut_vars(['battery.charge' => '90', 'ups.load' => '20', 'ups.realpower' => '151.6', 'ups.realpower.nominal' => '900']);
t('NUT reports measured watts and its rating', $nv['watts'] === 151.6 && $nv['nominal'] === 900);

/* ---------------- monitoring and tool apps ---------------- */
group('more apps');
reset_store();
$GLOBALS['SEEN'] = [];
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) {
  $GLOBALS['SEEN'][] = [$m, $url, $o];
  $p = parse_url($url, PHP_URL_PATH);
  $j = function ($d, $c = 200) { return ['code' => $c, 'body' => is_string($d) ? $d : json_encode($d), 'json' => is_string($d) ? null : $d, 'err' => '', 'headers' => []]; };
  $dev = function ($n, $st, $t, $arch = false) { return ['device' => ['device_name' => $n, 'model_name' => 'WD', 'device_status' => $st, 'archived' => $arch], 'smart' => ['temp' => $t]]; };
  switch ($p) {
    case '/api/summary': return $j(['data' => ['summary' => ['a' => $dev('sda', 0, 35), 'b' => $dev('sdb', 1, 44), 'c' => $dev('sdc', 2, 30), 'd' => $dev('sdd', 7, 0), 'e' => $dev('sde', 3, 61, true)]]]);
    case '/api/settings': return $j(['settings' => ['metrics' => ['status_threshold' => 1]]]);
    case '/api/v2/cruddb': return $j(['table1Count' => 5, 'table1ViewableCount' => 3, 'table4Count' => 2, 'table2Count' => 10, 'table5Count' => 1, 'table3Count' => 1, 'table6Count' => 0, 'sizeDiff' => 1.5]);
    case '/unmanic/api/v2/workers/status': return $j(['workers_status' => [['idle' => true], ['idle' => false], ['idle' => false]]]);
    case '/unmanic/api/v2/pending/tasks': return $j(['recordsTotal' => 12]);
    case '/api/status-page/home': return $j(['publicGroupList' => [['monitorList' => [['id' => 1, 'name' => 'Plex'], ['id' => 2, 'name' => 'NAS'], ['id' => 3, 'name' => 'Old']]]], 'incident' => null]);
    case '/api/status-page/heartbeat/home': return $j(['heartbeatList' => ['1' => [['status' => 0], ['status' => 1]], '2' => [['status' => 0]], '3' => [['status' => 3]]], 'uptimeList' => ['1_24' => 1, '2_24' => 0.5, '3_24' => 0.9]]);
    case '/api/v1/results/latest': return ($o['headers']['Authorization'] ?? '') === 'Bearer tok' ? $j(['data' => ['download' => 12500000, 'upload' => 2500000, 'ping' => 9.4]]) : $j(['message' => 'Unauthenticated'], 401);
    case '/api/speedtest/latest': return $j(['data' => ['download' => 100, 'upload' => 20, 'ping' => 11]]);
    case '/v1/publicip/ip': return $j(['public_ip' => '203.0.113.9', 'country' => 'Netherlands', 'region' => 'North Holland', 'city' => 'Amsterdam']);
    case '/v1/portforward': return $j(['error' => 'not found'], 404);
    case '/v1/openvpn/portforwarded': return $j(['port' => 51413]);
    case '/api/states/sensor.temp': return $j(['state' => '21.5', 'attributes' => ['friendly_name' => 'Living room', 'unit_of_measurement' => '°C']]);
    case '/api/template': return $j(strpos($o['body'], 'person') !== false ? '2 / 3' : '0 / 4');
  }
  return $j(['error' => 'nope'], 404);
};
$sv = function ($type, $extra = []) { return $extra + ['id' => 'x', 'type' => $type, 'url' => 'http://app.lan:1', 'key' => '', 'username' => '', 'password' => '', 'options' => []]; };
$bv = function ($r) { return array_column($r['blocks'], 'value', 'key'); };
$r = und_w_scrutiny($sv('scrutiny'));
t('Scrutiny: archived disks are left out, failures follow its threshold, hottest disk shown', $bv($r) === ['passed' => 2, 'failed' => 1, 'unknown' => 1, 'hottest' => '44 °C'] && $r['list'][0]['title'] === 'sdb WD', json_encode($bv($r)));
$r = und_w_tdarr($sv('tdarr', ['key' => 'k']));
$last = end($GLOBALS['SEEN']);
t('Tdarr: queue counts as Tdarr does, saved space in bytes, key sent', $bv($r) === ['queue' => 5, 'processed' => 11, 'errored' => 1, 'saved' => 1.5e9] && $last[0] === 'POST' && $last[2]['headers']['x-api-key'] === 'k'
  && json_decode($last[2]['body'], true)['data']['docID'] === 'statistics');
t('Unmanic: busy and total workers, pending tasks', $bv(und_w_unmanic($sv('unmanic'))) === ['active' => 2, 'workers' => 3, 'pending' => 12]);
$r = und_w_uptimekuma($sv('uptimekuma', ['options' => ['slug' => 'home']]));
t('Uptime Kuma: last beat decides, maintenance is not down, down ones listed by name', $bv($r) === ['up' => 1, 'down' => 1, 'uptime' => 80.0] && $r['list'] === [['title' => 'NAS', 'right' => 'down']], json_encode($r));
$e = ''; try { und_w_uptimekuma($sv('uptimekuma', ['options' => ['slug' => '../x']])); } catch (Exception $x) { $e = $x->getMessage(); }
t('Uptime Kuma: a slug with a path in it is refused', strpos($e, 'slug') !== false);
t('Speedtest Tracker v1: bytes per second with the token', $bv(und_w_speedtest($sv('speedtest', ['key' => 'tok']))) === ['download' => 12500000.0, 'upload' => 2500000.0, 'ping' => 9.4]);
t('Speedtest Tracker without a token: the old API, Mbit/s turned into bytes per second', $bv(und_w_speedtest($sv('speedtest'))) === ['download' => 12500000.0, 'upload' => 2500000.0, 'ping' => 11.0]);
$e = ''; try { und_w_speedtest($sv('speedtest', ['key' => 'wrong'])); } catch (Exception $x) { $e = $x->getMessage(); }
t('Speedtest Tracker: a wrong token says so', strpos($e, 'API token') !== false);
$r = und_w_gluetun($sv('gluetun', ['key' => 'g']));
t('Gluetun: public address and the forwarded port (older API)', $bv($r)['public_ip'] === '203.0.113.9' && $bv($r)['port'] === 51413 && $GLOBALS['SEEN'][count($GLOBALS['SEEN']) - 3][2]['headers']['X-API-Key'] === 'g');
$r = und_w_homeassistant($sv('homeassistant', ['key' => 'tok', 'options' => ['entities' => "sensor.temp\n"]]));
t('Home Assistant: an entity with its name and unit, token sent', $r['blocks'] === [['key' => 'sensor_temp', 'label' => 'Living room', 'value' => '21.5 °C', 'fmt' => 'text']] && end($GLOBALS['SEEN'])[2]['headers']['Authorization'] === 'Bearer tok');
t('Home Assistant: people home, lights and switches by default', array_column(und_w_homeassistant($sv('homeassistant', ['key' => 'tok']))['blocks'], 'value') === ['2 / 3', '0 / 4', '0 / 4']);
$e = ''; try { und_w_homeassistant($sv('homeassistant', ['key' => 'tok', 'options' => ['entities' => '../../api/config']])); } catch (Exception $x) { $e = $x->getMessage(); }
t('Home Assistant: only entity ids are asked for', strpos($e, 'Not an entity id') !== false);
$cfgSm = und_normalize(['groups' => [['id' => 'aaaaaaaa', 'name' => 'Disks', 'services' => [['id' => '5c000001', 'type' => 'scrutiny', 'name' => 'Scrutiny', 'url' => 'http://app.lan:1', 'fields' => [], 'options' => []]]]]]);
@unlink(und_tmp('smart-5c000001.json'));
t('alerts: nothing is known about the disks before Scrutiny is read', und_smart_failures($cfgSm) === ['checked' => false, 'failed' => []]);
$n = count($GLOBALS['SEEN']);
$sm = und_smart_failures($cfgSm, true);
t('the background check reads Scrutiny and keeps the failed disks', $sm['checked'] && count($sm['failed']) === 1 && $sm['failed'][0]['disk'] === 'sdb' && count($GLOBALS['SEEN']) > $n);
$n = count($GLOBALS['SEEN']);
und_smart_failures($cfgSm, true); und_smart_failures($cfgSm);
t('... and does not ask again within 5 minutes; the refresh never asks', count($GLOBALS['SEEN']) === $n);
$al = array_values(array_filter(und_alerts($cfgSm, null, null, $sm), function ($x) { return $x['cat'] === 'disk.smart'; }));
t('a failed disk is a red alert naming the disk, the model and the app', count($al) === 1 && $al[0]['lv'] === 'bad' && $al[0]['res'] === 'sdb' && strpos($al[0]['msg'], 'sdb (WD) failed its SMART check in Scrutiny') === 0, json_encode($al));
t('the alert category can be ignored like the others', isset(und_alert_categories()['disk.smart']));
und_alerts_track($al, ['system', 'smart'], 1000);
und_alerts_track([], ['system', 'docker'], 1100);
t('the alert stays open while Scrutiny was not asked', isset(und_alert_history()['active'][0]) && und_alert_history()['active'][0]['cat'] === 'disk.smart');
und_alerts_track([], ['system', 'smart'], 1200);
t('... and ends once Scrutiny says the disk is fine', !und_alert_history()['active']);
$st = json_decode(file_get_contents(und_tmp('smart-5c000001.json')), true); $st['at'] -= 3600; file_put_contents(und_tmp('smart-5c000001.json'), json_encode($st));
t('an hour-old Scrutiny result counts as not checked', und_smart_failures($cfgSm)['checked'] === false);
t('the new apps show in Apps', und_zone(['type' => 'scrutiny']) === 'apps' && und_zone(['type' => 'homeassistant']) === 'apps');
unset($GLOBALS['UND_HTTP_MOCK']);

/* ---------------- weather, releases, bookmarks ---------------- */
group('widgets');
reset_store();
$GLOBALS['SEEN'] = [];
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) {
  $GLOBALS['SEEN'][] = $url;
  $host = parse_url($url, PHP_URL_HOST); $p = parse_url($url, PHP_URL_PATH); parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
  $j = function ($d, $c = 200) { return ['code' => $c, 'body' => json_encode($d), 'json' => $d, 'err' => '', 'headers' => []]; };
  if ($host === 'geocoding-api.open-meteo.com') return $j($q['name'] === 'Nowhere' ? ['generationtime_ms' => 1] : ['results' => [['latitude' => 52.374, 'longitude' => 4.8897, 'name' => 'Amsterdam']]]);
  if ($host === 'api.open-meteo.com') return $j(['current' => ['temperature_2m' => 11.6, 'apparent_temperature' => 9.2, 'relative_humidity_2m' => 81, 'weather_code' => 3, 'wind_speed_10m' => 17.4],
    'daily' => ['time' => ['2026-10-30', '2026-10-31', '2026-11-01'], 'weather_code' => [3, 61, 0], 'temperature_2m_max' => [13.1, 12, 10.4], 'temperature_2m_min' => [7.9, 8, 4.5], 'precipitation_probability_max' => [10, 70, 0]]]);
  if ($host === 'api.github.com') {
    if ($p === '/repos/immich-app/immich/releases/latest') return $j(['tag_name' => 'v2.1.0', 'published_at' => '2026-10-20T10:00:00Z', 'html_url' => 'https://github.com/immich-app/immich/releases/tag/v2.1.0']);
    if ($p === '/repos/autobrr/qui/releases/latest') return $j(['tag_name' => 'v1.5.0', 'published_at' => '2026-10-28T10:00:00Z', 'html_url' => 'javascript:alert(1)']);
    if ($p === '/repos/a/pre/releases/latest') return $j(['message' => 'Not Found'], 404);
    if ($p === '/repos/a/pre/releases') return $j([['tag_name' => 'v0.1-rc1', 'prerelease' => true, 'published_at' => '2026-01-01T00:00:00Z', 'html_url' => 'https://github.com/a/pre/releases/tag/v0.1-rc1']]);
    if ($p === '/repos/a/limited/releases/latest') return $j(['message' => 'API rate limit exceeded'], 403);
  }
  return $j(['error' => 'nope'], 404);
};
$sv = function ($type, $opt, $key = '') { return ['id' => 'x', 'type' => $type, 'url' => '', 'key' => $key, 'username' => '', 'password' => '', 'options' => $opt]; };
$w = und_w_weather($sv('weather', ['location' => 'Amsterdam']));
t('weather: now, today, humidity and wind', array_column($w['blocks'], 'value', 'key') === ['now' => '12°', 'today' => '13° / 8°', 'humidity' => 81.0, 'wind' => '17 km/h'] && $w['blocks'][0]['label'] === 'Cloudy', json_encode($w['blocks']));
t('weather: the next days, with the chance of rain', $w['list'][0] === ['title' => 'Saturday', 'sub' => 'Light rain · 70% rain', 'right' => '12° / 8°'] && $w['list'][1]['sub'] === 'Clear');
$n = count($GLOBALS['SEEN']);
und_w_weather($sv('weather', ['location' => 'amsterdam']));
t('weather: the place and the forecast are kept a while', count($GLOBALS['SEEN']) === $n);
und_w_weather($sv('weather', ['location' => '52.37, 4.89', 'units' => 'imperial']));
$last = end($GLOBALS['SEEN']);
t('weather: coordinates skip the lookup; imperial asks for °F and mph', count($GLOBALS['SEEN']) === $n + 1 && strpos($last, 'latitude=52.37&longitude=4.89') !== false && strpos($last, 'temperature_unit=fahrenheit&wind_speed_unit=mph') !== false, $last);
$e = ''; try { und_w_weather($sv('weather', ['location' => 'Nowhere'])); } catch (Exception $x) { $e = $x->getMessage(); }
t('weather: an unknown place says so', strpos($e, 'No place called "Nowhere"') !== false);
$rl = und_w_releases($sv('releases', ['repos' => "https://github.com/immich-app/immich/\nautobrr/qui\na/pre"], 'ghp_x'));
t('releases: newest first, with tag and date', array_column($rl['list'], 'title') === ['qui', 'immich', 'pre'] && $rl['list'][1]['sub'] === 'v2.1.0' && $rl['list'][1]['ts'] === strtotime('2026-10-20T10:00:00Z'));
t('releases: only GitHub links', $rl['list'][0]['href'] === 'https://github.com/autobrr/qui' && $rl['list'][1]['href'] === 'https://github.com/immich-app/immich/releases/tag/v2.1.0');
t('releases: a repository with only pre-releases shows its latest one', $rl['list'][2]['sub'] === 'v0.1-rc1 (pre-release)');
$e = ''; try { und_w_releases($sv('releases', ['repos' => 'a/limited'])); } catch (Exception $x) { $e = $x->getMessage(); }
t('releases: the GitHub limit says to add a token', strpos($e, 'GitHub token') !== false, $e);
$e = ''; try { und_w_releases($sv('releases', ['repos' => '../../etc'])); } catch (Exception $x) { $e = $x->getMessage(); }
$rl = und_w_releases($sv('releases', ['repos' => "autobrr/qui\na/missing"]));
t('releases: a repository that cannot be read is its own row; the others still show', count($rl['list']) === 2 && $rl['list'][1]['title'] === 'missing' && strpos($rl['list'][1]['sub'], 'no repository') !== false, json_encode($rl['list']));
t('releases: only owner/repo names', strpos($e, 'Not a GitHub repository') !== false);
$bk = und_w_bookmarks($sv('bookmarks', ['links' => "Router | http://192.168.1.1\nhttps://selfh.st/apps/\nEvil | javascript:alert(1)\nData | data:text/html,x\n\n"]));
t('bookmarks: title and link, or the host name; only http and https', $bk['list'] === [['title' => 'Router', 'href' => 'http://192.168.1.1'], ['title' => 'selfh.st', 'href' => 'https://selfh.st/apps/']], json_encode($bk['list']));
unset($GLOBALS['UND_HTTP_MOCK']);

/* ---------------- custom API templates ---------------- */
group('custom api');
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) {
  $d = ['data' => ['up' => 7, 'total' => 9, 'results' => [['name' => 'Ubuntu ISO', 'size' => 4831838208, 'done' => 0.5, 'added' => 1761800000000, 'id' => 4, 'meta' => ['user' => 'al']],
    ['name' => 'Notes', 'size' => 512, 'done' => true, 'added' => '2026-10-29T08:00:00Z', 'id' => '5" onmouseover="x']]]];
  return ['code' => 200, 'body' => json_encode($d), 'json' => $d, 'err' => '', 'headers' => []];
};
$ca = function ($o) { return und_w_customapi(['id' => 'x', 'type' => 'customapi', 'url' => 'http://app.lan/api', 'key' => '', 'username' => '', 'password' => '', 'options' => $o]); };
$r = $ca(['display' => 'items', 'items' => 'data.results', 'title' => '{name}', 'sub' => '{size|bytes} · {meta.user|upper}', 'right' => '{done|percent}', 'link' => 'https://app.lan/i/{id}']);
t('items: a row per element, fields and formats filled in', $r['list'][0]['title'] === 'Ubuntu ISO' && $r['list'][0]['sub'] === '4.5 GiB · AL' && $r['list'][0]['right'] === '0.5%' && $r['list'][1]['sub'] === '512 B ·', json_encode($r['list']));
t('items: a link that is not a plain web address is dropped', $r['list'][0]['href'] === 'https://app.lan/i/4' && !isset($r['list'][1]['href']));
$r = $ca(['display' => 'items', 'items' => 'data.results', 'date' => 'added', 'right' => '{done}', 'limit' => 1]);
t('items: a date field (milliseconds) beats the right side; the name is the default title; rows are capped', count($r['list']) === 1 && $r['list'][0]['ts'] === 1761800000 && !isset($r['list'][0]['right']) && $r['list'][0]['title'] === 'Ubuntu ISO');
$e = ''; try { $ca(['display' => 'items', 'items' => 'data.nothing']); } catch (Exception $x) { $e = $x->getMessage(); }
t('items: a wrong path says so', strpos($e, 'Nothing is at "data.nothing"') !== false);
$r = $ca(['mappings' => [['template' => '{data.up} / {data.total}', 'label' => 'Up'], ['field' => 'data.total', 'label' => 'Total']]]);
t('blocks: a template and a plain field side by side', $r['blocks'][0]['value'] === '7 / 9' && $r['blocks'][0]['fmt'] === 'text' && $r['blocks'][1]['value'] === 9);
t('templates: true reads yes, a list counts its items, unknown fields are empty', und_tpl('{a}|{b}|{c}', ['a' => true, 'b' => [1, 2]]) === 'yes|2|');
unset($GLOBALS['UND_HTTP_MOCK']);

/* ---------------- security ---------------- */
group('security');
reset_store();
$evil = "abc\rUNDASH_PASSWORD_HASH=x\r\nUNDASH_AUTH=open\nZ=1";
$back = und_env_read(und_env_text(['RADARR_API_KEY' => $evil, 'B' => "q\"\\r"]));
t('.env: line breaks inside a value cannot add settings', $back === ['B' => "q\"\\r", 'RADARR_API_KEY' => $evil], json_encode($back));
for ($i = 0; $i < 4; $i++) und_login_failed('10.0.0.' . $i);
t('5 wrong passwords per address', !und_login_throttled('10.0.0.1'));
for ($i = 0; $i < 30; $i++) und_login_failed('10.9.' . intdiv($i, 4) . '.' . $i);
t('30 wrong passwords from all addresses together slow everyone down', und_login_throttled('10.200.0.1'));
putenv('HOST_HOSTNAME=Tower'); putenv('UNDASH_ALLOWED_HOSTS=dash.example.com,*.lan.example.net');
t('host names: IPs, localhost, the server name and local suffixes', und_host_allowed('192.168.1.10') && und_host_allowed('[fd00::1]') && und_host_allowed('localhost') && und_host_allowed('tower') && und_host_allowed('tower.local') && und_host_allowed('tower.home.arpa'));
t('host names: the allowed list, with wildcards', und_host_allowed('dash.example.com') && und_host_allowed('a.lan.example.net') && !und_host_allowed('lan.example.net') && !und_host_allowed('xlan.example.net'));
t('host names: anything else is refused', !und_host_allowed('tower.evil.example') && !und_host_allowed('evil.example') && !und_host_allowed(''));
putenv('HOST_HOSTNAME'); putenv('UNDASH_ALLOWED_HOSTS');
reset_store();

/* ---------------- icon picker ---------------- */
group('icons');
reset_store();
$GLOBALS['ICON_GETS'] = 0;
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) {
  $GLOBALS['ICON_GETS']++;
  $j = function ($d) { return ['code' => 200, 'body' => json_encode($d), 'json' => $d, 'err' => '', 'headers' => []]; };
  if (strpos($url, 'selfhst') !== false) return $j([['Name' => 'Plex', 'Reference' => 'plex', 'SVG' => 'Yes', 'Light' => 'Yes', 'Dark' => 'No', 'PNG' => 'Yes', 'Tags' => 'media'], ['Name' => 'Plexamp', 'Reference' => 'plexamp', 'SVG' => 'No', 'PNG' => 'Yes', 'Tags' => ''],
    ['Name' => 'Jellyfin', 'Reference' => 'jellyfin', 'SVG' => 'Yes', 'Tags' => 'media, plex alternative'], ['Name' => 'Bad', 'Reference' => '../etc', 'SVG' => 'Yes']]);
  if (strpos($url, 'dashboard-icons') !== false) return $j(['png' => ['plex.png', 'plex-light.png', 'complex.png'], 'svg' => ['plex-light.svg']]);
  return null;
};
$ic = und_icon_search('Plex');
t('an exact name comes first, as a selfh.st icon', $ic['icons'][0] === ['v' => 'sh-plex', 'n' => 'Plex', 's' => 'selfh.st'], json_encode($ic));
t('a selfh.st icon without SVG is asked for as PNG', in_array('sh-plexamp.png', array_column($ic['icons'], 'v'), true));
t('dashboard-icons fills in, without repeating selfh.st, SVG when it has one', in_array('plex-light.svg', array_column($ic['icons'], 'v'), true) && in_array('complex.png', array_column($ic['icons'], 'v'), true)
  && !in_array('plex.png', array_column($ic['icons'], 'v'), true));
t('tags match last', end($ic['icons'])['v'] === 'sh-jellyfin');
t('odd names in the lists are dropped', !array_filter(array_column($ic['icons'], 'v'), function ($v) { return strpos($v, '..') !== false; }));
$n = $GLOBALS['ICON_GETS'];
und_icon_search('jelly');
t('the lists are downloaded once, then kept', $GLOBALS['ICON_GETS'] === $n && $n === 2);
t('icon names that exist are reported with their forms', (array)und_icon_slugs(['plex', 'plexamp', 'nothere', '../x'])['icons'] === ['plex' => 'svg,light,png', 'plexamp' => 'png'] && $GLOBALS['ICON_GETS'] === $n);
t('an empty search returns nothing without downloading', und_icon_search('  <> ')['icons'] === []);
t('the number of results is capped', count(und_icon_search('p', 2)['icons']) === 2 && und_icon_search('p', 2)['more'] === true);
reset_store();
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) { return ['code' => 0, 'body' => '', 'json' => null, 'err' => 'Could not resolve host', 'headers' => []]; };
$err = ''; try { und_icon_search('plex'); } catch (Exception $e) { $err = $e->getMessage(); }
t('no internet gives a readable error', strpos($err, 'could not be downloaded') !== false, $err);
$err2 = ''; try { und_icon_search('plex'); } catch (Exception $e) { $err2 = $e->getMessage(); }
t('a failed download is not retried at once', strpos($err2, 'Try again in a few minutes') !== false, $err2);
unset($GLOBALS['UND_HTTP_MOCK']);

group('background jobs');
$mock = $GLOBALS['UND_SPAWN_MOCK']; unset($GLOBALS['UND_SPAWN_MOCK']);
$log = und_tmp('job-test.log');
und_spawn([PHP_BINARY, '-r', 'echo "hello\n"; exit(3);'], $log);
for ($n = 0; $n < 100 && und_job($log)['state'] === 'running'; $n++) usleep(50000);
$j = und_job($log);
t('a real job runs and reports its exit code', $j['state'] === 'done' && $j['code'] === 3 && trim($j['out']) === 'hello', json_encode($j));
und_spawn(['/bin/sleep', '5'], $log);
for ($n = 0; $n < 100 && !is_file("$log.pid"); $n++) usleep(20000);
t('a job in progress is running', und_job($log)['state'] === 'running');
exec('kill -9 ' . (int)file_get_contents("$log.pid"));
for ($n = 0; $n < 100 && und_job($log)['state'] === 'running'; $n++) usleep(50000);
$j = und_job($log);
t('a job whose process died ends as failed', $j['state'] === 'done' && $j['code'] === 1 && strpos($j['out'], 'without a result') !== false, json_encode($j));
@unlink("$log.done"); @unlink("$log.pid"); file_put_contents($log, ''); touch($log, time() - 60);
t('a job that never started ends as failed', und_job($log)['state'] === 'done');
$GLOBALS['UND_SPAWN_MOCK'] = $mock;

rmrf($SANDBOX);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
