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
  && und_normalize(['groups' => [], 'appearance' => ['theme' => 'nord']])['appearance']['theme'] === 'nord');
t('themes that were removed become the closest one', und_normalize(['groups' => [], 'appearance' => ['theme' => 'tokyo']])['appearance']['theme'] === 'nord' && und_normalize(['groups' => [], 'appearance' => ['theme' => 'zinc']])['appearance']['theme'] === 'minimal'
  && und_normalize(['groups' => [], 'appearance' => ['theme' => 'dragon']])['appearance']['theme'] === 'wave' && und_normalize(['groups' => [], 'appearance' => ['theme' => 'fluent']])['appearance']['theme'] === 'default');
$ct = und_normalize(['groups' => [], 'appearance' => ['theme' => 'c-abcd1234', 'custom_themes' => [
  ['id' => 'c-abcd1234', 'name' => ' Sunset ', 'mode' => 'light', 'colors' => ['bg' => '#FFEEDD', 'accent' => 'red;}body{display:none'], 'look' => ['radius' => 99, 'translucent' => 1, 'evil' => 1], 'css' => '@import url(http://x); .card{color:red}'],
  ['id' => '../../etc', 'name' => 'x'], 'junk']]])['appearance'];
$ctt = $ct['custom_themes'];
t('a custom theme is kept and can be selected', count($ctt) === 1 && $ct['theme'] === 'c-abcd1234' && $ctt[0]['name'] === 'Sunset' && $ctt[0]['mode'] === 'light', json_encode($ct));
t('a custom theme keeps only clean colours, limits and CSS', $ctt[0]['colors']['bg'] === '#ffeedd' && $ctt[0]['colors']['accent'] === '#e8622c' && $ctt[0]['look']['radius'] === 28 && !isset($ctt[0]['look']['evil']) && strpos($ctt[0]['css'], 'url(') === false && strpos($ctt[0]['css'], '.card') !== false, json_encode($ctt));
t('a deleted custom theme falls back to the default', und_normalize(['groups' => [], 'appearance' => ['theme' => 'c-00000000']])['appearance']['theme'] === 'default');
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
t('a wallpaper address may contain brackets', und_normalize(['groups' => [], 'appearance' => ['background' => 'https://x.org/a_(1).jpg']])['appearance']['background'] === 'https://x.org/a_(1).jpg');
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
$y = preg_replace('/^(\s*)title:.*$/m', '$1title: Imported', und_export('config')['text'], 1);
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
t('an API problem is one alert with a Settings button', count($ae) === 1 && $ae[0]['cat'] === 'api.error' && $ae[0]['act'] === 'settings' && strpos($ae[0]['msg'], 'Settings > Server connection') !== false);
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
t('default count is 5', und_load_config()['media']['widgets'][2]['count'] === 5);
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
$all = und_icon_search('', 2, 0, '', '', true);
t('browsing lists every icon, a page at a time', count($all['icons']) === 2 && $all['total'] > 2 && $all['more'] === true && und_icon_search('', 2, 2, '', '', true)['icons'] !== $all['icons'], json_encode($all));
t('the source filter keeps one list', !array_filter(und_icon_search('', 50, 0, 'selfhst', '', true)['icons'], function ($x) { return $x['s'] !== 'selfh.st'; }));
t('a version lists only icons that have it, under its own name', array_column(und_icon_search('plex', 50, 0, '', 'light')['icons'], 'v') === ['sh-plex-light']);
t('the number of results is capped', count(und_icon_search('p', 2)['icons']) === 2 && und_icon_search('p', 2)['more'] === true);
reset_store();
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) { return ['code' => 0, 'body' => '', 'json' => null, 'err' => 'Could not resolve host', 'headers' => []]; };
$err = ''; try { und_icon_search('plex'); } catch (Exception $e) { $err = $e->getMessage(); }
t('no internet gives a readable error', strpos($err, 'could not be downloaded') !== false, $err);
$err2 = ''; try { und_icon_search('plex'); } catch (Exception $e) { $err2 = $e->getMessage(); }
t('a failed download is not retried at once', strpos($err2, 'Try again in a few minutes') !== false, $err2);
unset($GLOBALS['UND_HTTP_MOCK']);

group('card board');
$b = und_board_clean(['on' => false, 'compact' => 0, 'hidden' => ['alerts', 'bad id!', 'app:a1b2c3d4'], 'lg' => [['i' => 'm:cpu', 'x' => 30, 'y' => -2, 'w' => 99, 'h' => 0], ['i' => 'm:cpu', 'x' => 1], ['i' => '<script>', 'x' => 1], 'junk']]);
t('the board keeps only ids and numbers, inside the grid', $b['on'] === false && $b['compact'] === false && $b['lg'] === [['i' => 'm:cpu', 'x' => 0, 'y' => 0, 'w' => 24, 'h' => 1, 'a' => false]] && $b['hidden'] === ['alerts', 'app:a1b2c3d4'], json_encode($b));
t('the board is on by default', und_board_clean(null)['on'] === true && und_default_config()['dashboard']['board']['lg'] === []);
$c = und_default_config(); $c['dashboard']['board']['lg'] = [['i' => 'arr', 'x' => 2, 'y' => 3, 'w' => 6, 'h' => 9]];
$st = function ($t) { return und_normalize(['dashboard' => ['sections' => [['id' => 'docker', 'title' => $t]]]])['dashboard']['sections'][0]['title']; };
t('a section keeps your name for it, empty by default', und_default_config()['dashboard']['sections'][0]['title'] === '' && $st('  My stack ') === 'My stack');
t('a section name loses line breaks, is cut to 40 characters and cannot be a list', $st("a\nb") === 'a b' && mb_strlen($st(str_repeat('x', 90)), 'UTF-8') === 40 && $st(['x']) === '');
$gs = und_normalize(['groups' => [['name' => 'Network', 'section' => true], ['name' => 'Other']]])['groups'];
t('a group can be a section of its own, and is not one by default', $gs[0]['section'] === true && $gs[1]['section'] === false);
t('the card of your own section survives on the board', und_board_clean(['lg' => [['i' => 'grp:0a1b2c3d', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 6]], 'hidden' => ['grp:0a1b2c3d']])['hidden'] === ['grp:0a1b2c3d'] && und_board_clean(['lg' => [['i' => 'grp:0a1b2c3d', 'w' => 6, 'h' => 6]]])['lg'][0]['i'] === 'grp:0a1b2c3d');
t('a saved board survives loading the settings', und_normalize($c)['dashboard']['board']['lg'][0]['x'] === 2);

$c = und_default_config(); $c['appearance']['radius'] = 500;
t('the corner radius stays inside its limits', und_normalize($c)['appearance']['radius'] === 28 && und_default_config()['appearance']['radius'] === 8);
t('zoom starts at 100%, and only the offered steps are kept', und_default_config()['appearance']['zoom'] === 100 && und_normalize(['appearance' => ['zoom' => 125]])['appearance']['zoom'] === 125 && und_normalize(['appearance' => ['zoom' => 999]])['appearance']['zoom'] === 0);
t('server details in the header show by default and can be hidden', und_default_config()['appearance']['header_chips'] === true && und_normalize(['appearance' => ['header_chips' => false]])['appearance']['header_chips'] === false);
t('the dashboard name defaults to empty (the server name), the old default counts as empty, and your own is kept', und_default_config()['general']['title'] === '' && und_normalize(['general' => ['title' => 'UnDash']])['general']['title'] === '' && und_normalize(['general' => ['title' => 'R1ZE']])['general']['title'] === 'R1ZE');
t('the dashboard icon keeps icon names and addresses, and refuses anything else', und_normalize(['general' => ['logo' => 'sh-plex']])['general']['logo'] === 'sh-plex' && und_normalize(['general' => ['logo' => 'https://x.lan/a.png']])['general']['logo'] === 'https://x.lan/a.png' && und_normalize(['general' => ['logo' => 'javascript:alert(1)']])['general']['logo'] === '' && und_normalize(['general' => ['logo' => 'https://x/"><b']])['general']['logo'] === '');
t('icon boxes are on by default and can be turned off', und_default_config()['appearance']['icon_box'] === true && und_normalize(['appearance' => ['icon_box' => false]])['appearance']['icon_box'] === false && und_normalize([])['appearance']['icon_box'] === true);
t('the gradient background is on by default and can be turned off', und_default_config()['appearance']['gradient'] === true && und_normalize(['appearance' => ['gradient' => false]])['appearance']['gradient'] === false && und_normalize([])['appearance']['gradient'] === true);
t('the Translucent Effect is on by default and can be turned off', und_default_config()['appearance']['translucent'] === true && und_normalize(['appearance' => ['translucent' => false]] + und_default_config())['appearance']['translucent'] === false);
$m = und_default_config(); $m['appearance']['motion'] = 'warp';
t('an unknown animation setting falls back to full', und_normalize($m)['appearance']['motion'] === 'full');
$m = und_default_config(); $m['appearance']['glass_opacity'] = 0; $m['appearance']['wall_saturation'] = 900; $m['appearance']['glass_blur'] = -5;
$ma = und_normalize($m)['appearance'];
t('the glass and wallpaper controls stay inside their limits', $ma['glass_opacity'] === 10 && $ma['wall_saturation'] === 200 && $ma['glass_blur'] === 0 && $ma['wall_contrast'] === 100, json_encode($ma));
$c['appearance']['radius'] = -9;
t('a negative corner radius becomes square', und_normalize($c)['appearance']['radius'] === 0);

group('background jobs');
$mock = $GLOBALS['UND_SPAWN_MOCK']; unset($GLOBALS['UND_SPAWN_MOCK']);
$log = und_tmp('job-test.log');
touch("$log.recorded");
und_spawn([PHP_BINARY, '-r', 'echo "hello\n"; exit(3);'], $log);
t('a new job clears the mark of the last one', !is_file("$log.recorded"));
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

group('accent and wallpaper');
t('the accent follows the theme by default', und_default_config()['appearance']['accent_mode'] === 'theme' && und_normalize([])['appearance']['accent_mode'] === 'theme');
t('a saved accent colour from before accent modes counts as a custom one', und_normalize(['appearance' => ['accent' => '#123456']])['appearance']['accent_mode'] === 'custom');
t('the accent can follow the wallpaper', und_normalize(['appearance' => ['accent_mode' => 'auto']])['appearance']['accent_mode'] === 'auto');
t('custom without a colour falls back to the theme; unknown modes too', und_normalize(['appearance' => ['accent_mode' => 'custom', 'accent' => '']])['appearance']['accent_mode'] === 'theme' && und_normalize(['appearance' => ['accent_mode' => 'rainbow']])['appearance']['accent_mode'] === 'theme');
t('the uploaded picture is a valid background, other words are not', und_normalize(['appearance' => ['background' => 'custom']])['appearance']['background'] === 'custom' && und_normalize(['appearance' => ['background' => 'mine']])['appearance']['background'] === '');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAFklEQVR42mNk+M9Qz0AEYBxVSF+FAAhKDveksOjmAAAAAElFTkSuQmCC');
t('a PNG is recognised by its bytes, a text file is not', und_wall_type($png) === 'png' && und_wall_type("\xFF\xD8\xFF\xE0") === 'jpg' && und_wall_type('RIFF1234WEBPVP8 ') === 'webp' && und_wall_type('<svg onload=alert(1)>') === null);
t('a text file is refused as a wallpaper', isset(und_wall_upload(base64_encode(str_repeat('<svg>', 40)))['error']));
t('an empty upload is refused', isset(und_wall_upload('')['error']));
t('a picture larger than the limit is refused', isset(und_wall_upload(base64_encode(str_repeat('x', UND_WALL_MAX_BYTES + 1)))['error']));
$up = und_wall_upload('data:image/png;base64,' . base64_encode($png));
t('a real PNG is stored under its own type with its size', !empty($up['ok']) && $up['width'] === 16 && is_file(und_wall_custom_dir() . '/custom.png') && (fileperms(und_wall_custom_dir() . '/custom.png') & 0777) === 0600, json_encode($up));
t('the browser learns that a picture exists, not where it is', und_wall_custom_view()['set'] === true && !isset(und_wall_custom_view()['file']));
t('removing the picture leaves nothing behind', !empty(und_wall_remove()['ok']) && und_wall_custom() === null && und_wall_custom_view()['set'] === false);
t('numbers brought back inside their limits are reported', und_clamped(['general' => ['refresh' => 9999, 'title' => 'x'], 'appearance' => ['radius' => 99]], und_normalize(['general' => ['refresh' => 9999], 'appearance' => ['radius' => 99]])) === ['general.refresh' => [9999, 300], 'appearance.radius' => [99, 28]]);
t('nothing is reported when every number is inside its limits', und_clamped(['general' => ['refresh' => 5]], und_normalize(['general' => ['refresh' => 5]])) === []);
t('TLS checks are off unless asked for', und_tls_verify() === false);

/* ---------------- history charts ---------------- */
group('metrics');
$h = ['fine' => [], 'coarse' => [], 'saved' => 0];
$sys = ['cpu' => ['pct' => 12.34, 'temp' => 40.2], 'mem' => ['pct' => 55.55], 'net' => ['rx' => 100.4, 'tx' => null], 'array' => ['size' => 1000, 'free' => 250, 'pools' => []]];
$r0 = und_metrics_row($sys, [['temp' => 31], ['temp' => null], ['temp' => 44]], 1000);
t('a reading holds each value, and null for what was not reported', $r0 === [1000, 12.3, 55.6, 40.0, 44, 100.0, null, 75.0], json_encode($r0));
$t0 = 1800000000 - 1800000000 % 900;
for ($i = 0; $i < 16; $i++) $h = und_metrics_add($h, [$t0 + $i * 60, (float)$i, 50.0, null, null, null, null, null]);
t('one reading a minute is kept', count($h['fine']) === 16);
t('a finished 15 minutes becomes one average', count($h['coarse']) === 1 && $h['coarse'][0][0] === $t0 && $h['coarse'][0][1] === 7.0 && $h['coarse'][0][3] === null, json_encode($h['coarse']));
$n = count($h['fine']); $h = und_metrics_add($h, [$t0 + 15 * 60 + 5, 1.0, 1.0, null, null, null, null, null]);
t('a second check right after a restart is not a reading', count($h['fine']) === $n);
$h = und_metrics_add($h, [$t0 + 40 * 86400, 1.0, 1.0, null, null, null, null, null]);
t('old readings drop off', count($h['fine']) === 1 && $h['coarse'] === []);
und_write_json(und_tmp('metrics.json'), ['fine' => [[1000, 5, 5, null, null, null, null, null], [90000, 6, 6, null, null, null, null, null]], 'coarse' => [], 'saved' => 0]);
$m = und_metrics_range('1h', 90100);
t('a range returns its own rows, with the time step', count($m['rows']) === 1 && $m['step'] === 60 && $m['fields'][0] === 'cpu');
t('an unknown range is the default one', und_metrics_range('evil', 90100)['from'] === 90100 - 86400);
@unlink(und_tmp('metrics.json'));

/* ---------------- push alerts ---------------- */
group('notify');
@unlink(und_notify_file());
$sent = [];
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) use (&$sent) { $sent[] = [$url, $o]; return ['code' => 200, 'body' => '', 'json' => null, 'err' => '', 'headers' => []]; };
$conf = ['ntfy' => ['url' => 'https://ntfy.example/topic', 'token' => '']];
$prefs = ['min' => 'warn', 'resolved' => true];
$hot = ['lv' => 'warn', 'msg' => 'disk1 is at 55°C.', 'cat' => 'disk.hot', 'res' => 'disk1', 'act' => ''];
und_notify_run([$hot], ['system'], $conf, $prefs, [], 'Tower', 10000);
t('a problem is not sent at its first sighting', !$sent);
und_notify_run([$hot], ['system'], $conf, $prefs, [], 'Tower', 10060);
t('it is sent when it is still there a minute later', count($sent) === 1 && $sent[0][1]['body'] === 'disk1 is at 55°C.' && $sent[0][1]['headers']['Title'] === 'Tower: problem', json_encode($sent));
und_notify_run([$hot], ['system'], $conf, $prefs, [], 'Tower', 10120);
t('and only once', count($sent) === 1);
und_notify_run([], [], $conf, $prefs, [], 'Tower', 10180);
t('a check that could not read the server ends nothing', count($sent) === 1);
und_notify_run([], ['system'], $conf, $prefs, [], 'Tower', 10240);
t('the end of a problem is sent', count($sent) === 2 && $sent[1][1]['headers']['Title'] === 'Tower: problem ended' && $sent[1][1]['headers']['Priority'] === 'default');
und_notify_run([$hot], ['system'], $conf, $prefs, [], 'Tower', 10300);
und_notify_run([$hot], ['system'], $conf, $prefs, [], 'Tower', 10360);
und_notify_run([], ['system'], $conf, $prefs, [], 'Tower', 10420);
t('a flapping problem stays quiet, and its silent end sends nothing', count($sent) === 2, json_encode(count($sent)));
@unlink(und_notify_file()); $sent = [];
$info = ['lv' => 'info', 'msg' => 'update', 'cat' => 'os.available', 'res' => '7.4', 'act' => ''];
und_notify_run([$info, $hot], ['system'], $conf, $prefs, [['key' => 'disk.hot', 'kind' => 'type']], 'Tower', 20000);
und_notify_run([$info, $hot], ['system'], $conf, $prefs, [['key' => 'disk.hot', 'kind' => 'type']], 'Tower', 20060);
t('ignored alerts and those below the chosen level are not sent', !$sent);
@unlink(und_notify_file());
$many = []; for ($i = 0; $i < 13; $i++) $many[] = ['lv' => 'bad', 'msg' => "m$i", 'cat' => 'disk.bad', 'res' => "d$i", 'act' => ''];
und_notify_run($many, ['system'], $conf, $prefs, [], 'Tower', 30000); und_notify_run($many, ['system'], $conf, $prefs, [], 'Tower', 30060);
t('a bad minute is one message', count($sent) === 1 && strpos($sent[0][1]['body'], '…and 3 more.') !== false && $sent[0][1]['headers']['Title'] === 'Tower: 13 problems' && $sent[0][1]['headers']['Priority'] === 'high');
@unlink(und_notify_file()); $sent = [];
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) use (&$sent) { $sent[] = $url; return ['code' => 0, 'body' => '', 'json' => null, 'err' => 'Could not connect to ' . $url, 'headers' => []]; };
$secret = ['telegram' => ['token' => '123456:SECRETSECRETSECRETSECRET', 'chat' => '42']];
und_notify_run([$hot], ['system'], $secret, $prefs, [], 'Tower', 40000); $r = und_notify_run([$hot], ['system'], $secret, $prefs, [], 'Tower', 40060);
t('a failed send names the channel and hides the token', strpos($r['error'], 'Telegram') === 0 && strpos($r['error'], 'SECRET') === false && strpos(json_encode(und_read_json(und_notify_file(), [])), 'SECRET') === false, $r['error']);
und_notify_run([$hot], ['system'], $secret, $prefs, [], 'Tower', 40120);
t('and is tried again', count($sent) === 2);
unset($GLOBALS['UND_HTTP_MOCK']); @unlink(und_notify_file());
$rq = und_notify_request('discord', ['url' => 'https://discord.com/api/webhooks/1/x'], 'T', 'm', true);
t('Discord and Telegram always check the certificate', !empty($rq[1]['verify']) && !empty(und_notify_request('telegram', ['token' => 'a', 'chat' => '1'], 'T', 'm', true)[1]['verify']));
$rq = und_notify_request('gotify', ['url' => 'http://g.lan/', 'token' => 'K'], 'T', 'm', true);
t('Gotify gets its key in a header', $rq[0] === 'http://g.lan/message' && $rq[1]['headers']['X-Gotify-Key'] === 'K' && strpos($rq[0], 'K') === false);
t('a wrong Discord address is refused', isset(und_notify_set('discord', ['url' => 'https://evil.example/x'])['error']));
t('a good one is stored in .env only', isset(und_notify_set('discord', ['url' => 'https://discord.com/api/webhooks/123/abc-DEF'])['ok']) && und_env()['NOTIFY_DISCORD_URL'] === 'https://discord.com/api/webhooks/123/abc-DEF' && strpos(json_encode(und_doc()), 'webhooks') === false);
$v = und_notify_view();
t('the page sees that it is on, never the address', $v['channels'][0]['on'] === true && strpos(json_encode($v), 'webhooks') === false);
t('saving with the secret left empty keeps it', isset(und_notify_set('discord', ['url' => ''])['ok']) && isset(und_notify_conf()['discord']));
t('a channel is not a shared value', !in_array('NOTIFY_DISCORD_URL', und_shared_names(und_env(), und_load_config()), true));
und_save_secrets(['vars' => ['OTHER' => '1']]);
t('saving Settings leaves it alone', isset(und_notify_conf()['discord']));
t('a backup of the keys carries it', strpos(und_export('env')['text'], 'NOTIFY_DISCORD_URL') !== false);
t('removing it clears it', isset(und_notify_off('discord')['ok']) && !isset(und_env()['NOTIFY_DISCORD_URL']) && !und_notify_conf());
t('the level is stored', isset(und_notify_save_prefs('bad', false)['ok']) && und_notify_prefs() === ['min' => 'bad', 'resolved' => false]);
und_doc_write(['notify' => null]);
t('the default is warnings and critical, with endings', und_notify_prefs() === ['min' => 'warn', 'resolved' => true]);


/* ---------------- batch 2: forecast, parity alerts, transcodes ---------------- */
group('storage facts');
$t0 = 1800000000; $col = array_search('used', UND_METRIC_FIELDS) + 1;
$mk = function ($days, $per) use ($t0, $col) { $o = []; for ($i = $days * 4; $i >= 0; $i--) { $r = array_fill(0, count(UND_METRIC_FIELDS) + 1, null); $r[0] = $t0 - $i * 21600; $r[$col] = 50 - $i / 4 * $per; $o[] = $r; } return $o; };
$f = und_metrics_forecast($mk(1, 0.1), 1000, $t0);
t('a forecast waits for three days of history', $f === ['ready' => false, 'wait' => 2], json_encode($f));
$f = und_metrics_forecast($mk(10, 1.0), 1000000, $t0);
t('a steady 1% a day from half full is 50 days', $f['rising'] === true && $f['days'] === 50 && $f['per_day'] === 10000, json_encode($f));
t('storage that stays level is not filling up', und_metrics_forecast($mk(10, 0.0), 1000, $t0) === ['ready' => true, 'rising' => false]);
t('storage that shrinks is not filling up', und_metrics_forecast($mk(10, -1.0), 1000, $t0)['rising'] === false);
t('history older than 30 days is left out', und_metrics_forecast($mk(40, 1.0), 1000000, $t0 + 86400 * 40)['ready'] === false);
$now = time(); $sy = function ($a) { return ['array' => $a + ['state' => 'STARTED', 'parity' => 1]]; };
$cats = function ($sys) { return array_map(function ($x) { return $x['cat']; }, und_alerts(und_normalize([]), $sys, null)); };
t('a server with no finished check is told so', in_array('array.parity_old', $cats($sy(['parity_checks' => ['last' => null, 'ok_at' => 0]])), true));
t('a recent finished check is quiet', !array_intersect(['array.parity_old', 'array.parity_errors'], $cats($sy(['parity_checks' => ['last' => ['status' => 'COMPLETED', 'errors' => 0, 'at' => $now - 86400], 'ok_at' => $now - 86400]]))));
t('an old one is overdue', in_array('array.parity_old', $cats($sy(['parity_checks' => ['last' => ['status' => 'COMPLETED', 'errors' => 0, 'at' => $now - 200 * 86400], 'ok_at' => $now - 200 * 86400]])), true));
t('errors in the last check are named', in_array('array.parity_errors', $cats($sy(['parity_checks' => ['last' => ['status' => 'COMPLETED', 'errors' => 3, 'at' => $now], 'ok_at' => $now]])), true));
t('nothing is said while a check runs', !in_array('array.parity_old', $cats($sy(['resync' => 1, 'resync_action' => '', 'parity_checks' => ['last' => null, 'ok_at' => 0]])), true));
t('a server without parity disks needs no check', !in_array('array.parity_old', $cats(['array' => ['state' => 'STARTED', 'parity' => 0, 'parity_checks' => ['last' => null, 'ok_at' => 0]]]), true));
t('a read that failed says nothing', !in_array('array.parity_old', $cats($sy([])), true));
t('a disk that stopped reporting SMART is named', in_array('disk.smart_lost', $cats($sy(['smart_lost' => ['disk1']])), true));
t('every new alert can be ignored', isset(und_alert_categories()['disk.smart_lost'], und_alert_categories()['array.parity_old'], und_alert_categories()['array.parity_errors']));
$tc = ['SessionKey' => 'a', 'NowPlayingItem' => ['Name' => 'X'], 'PlayState' => ['PlayMethod' => 'Transcode']];
t('Emby says when a transcode is on hardware', und_streams_emby([$tc + ['TranscodingInfo' => ['VideoEncoderIsHardware' => true]]])[0]['hw'] === true);
t('or software', und_streams_emby([$tc + ['TranscodingInfo' => ['VideoEncoderIsHardware' => false, 'VideoDecoderIsHardware' => false]]])[0]['hw'] === false);
t('Jellyfin names its method', und_streams_emby([$tc + ['TranscodingInfo' => ['HardwareAccelerationType' => 'qsv']]])[0]['hw'] === true && und_streams_emby([$tc + ['TranscodingInfo' => ['HardwareAccelerationType' => 'none']]])[0]['hw'] === false);
t('a server that does not say is left unsaid', und_streams_emby([$tc + ['TranscodingInfo' => ['Bitrate' => 1]]])[0]['hw'] === null && und_streams_emby([$tc])[0]['hw'] === null);
$pl = function ($ts) { return und_streams_plex(['MediaContainer' => ['Metadata' => [['title' => 'X', 'TranscodeSession' => $ts]]]])[0]['hw']; };
t('Plex too', $pl(['videoDecision' => 'transcode', 'transcodeHwEncoding' => 'vaapi']) === true && $pl(['videoDecision' => 'transcode']) === false && und_streams_plex(['MediaContainer' => ['Metadata' => [['title' => 'X']]]])[0]['hw'] === null);

/* ---- pages ---- */
$bc = und_board_clean(['home' => ' Main ', 'pages' => [['id' => 'aaaaaaaa', 'name' => ' Media ', 'cards' => ['media:recent_movies', 'docker', 'bad id!'], 'lg' => [['i' => 'docker', 'x' => 0, 'y' => 0, 'w' => 99, 'h' => 5]]], ['id' => 'aaaaaaaa', 'name' => 'Twin'], ['id' => 'bbbbbbbb', 'name' => '', 'cards' => ['docker', 'vms']], 'junk']]);
t('a page keeps its name, cards and layout', $bc['home'] === 'Main' && $bc['pages'][0]['name'] === 'Media' && $bc['pages'][0]['cards'] === ['media:recent_movies', 'docker'] && $bc['pages'][0]['lg'][0]['w'] === 24, json_encode($bc));
t('a second page with the same id is dropped', count($bc['pages']) === 2 && $bc['pages'][1]['id'] === 'bbbbbbbb');
t('a card stays on the first page that lists it', $bc['pages'][1]['cards'] === ['vms'] && $bc['pages'][1]['name'] === 'Page');
t('a page id that is not ours is replaced', preg_match('/^[a-f0-9]{8}$/', und_board_clean(['pages' => [['id' => '../x', 'name' => 'A']]])['pages'][0]['id']) === 1);
t('at most 12 pages', count(und_board_clean(['pages' => array_map(function ($i) { return ['name' => "p$i"]; }, range(1, 20))])['pages']) === 12);
t('an old board has no extra pages', und_board_clean(['lg' => []])['pages'] === [] && und_board_clean([])['home'] === '');

/* ---- RSS ---- */
$rss2 = '<?xml version="1.0"?><rss version="2.0"><channel><title>Blog &amp; News</title><item><title><![CDATA[One <b>bold</b> &amp; true]]></title><link>https://a.example/1</link><pubDate>Mon, 02 Nov 2026 10:00:00 GMT</pubDate></item><item><title>Two</title><link>javascript:alert(1)</link></item><item><title></title><link>https://a.example/3</link></item></channel></rss>';
$f = und_rss_parse($rss2);
t('RSS 2.0 is read, and markup in a title is removed', $f['name'] === 'Blog & News' && $f['items'][0]['title'] === 'One bold & true' && $f['items'][0]['href'] === 'https://a.example/1' && $f['items'][0]['ts'] === 1793613600, json_encode($f));
t('a link that is not a web address is dropped', $f['items'][1]['href'] === '');
t('a story without a title is skipped', count($f['items']) === 2);
$f = und_rss_parse('<feed xmlns="http://www.w3.org/2005/Atom"><title>Rel</title><entry><title>v1.2</title><link rel="enclosure" href="https://a.example/f.zip"/><link rel="alternate" href="https://a.example/v1.2"/><updated>2026-11-01T00:00:00Z</updated></entry></feed>');
t('Atom is read, and its page link wins', $f['name'] === 'Rel' && $f['items'][0]['title'] === 'v1.2' && $f['items'][0]['href'] === 'https://a.example/v1.2' && $f['items'][0]['ts'] === strtotime('2026-11-01T00:00:00Z'), json_encode($f));
$f = und_rss_parse('<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>Old</title></channel><item><title>A</title><link>https://a.example/a</link><dc:date>2026-10-01T00:00:00Z</dc:date></item></rdf:RDF>');
t('RSS 1.0 is read, with its dc:date', $f['name'] === 'Old' && $f['items'][0]['ts'] === strtotime('2026-10-01T00:00:00Z'), json_encode($f));
$thrown = function (callable $fn) { try { $fn(); return ''; } catch (Exception $e) { return $e->getMessage(); } };
t('an HTML page is not a feed', strpos($thrown(function () { und_rss_parse('<html><body>hi</body></html>'); }), 'not a feed') !== false);
t('neither is plain text', strpos($thrown(function () { und_rss_parse('{"a":1}'); }), 'not a feed') !== false);
$xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><title>x</title><item><title>a&x;b</title></item></channel></rss>';
$r = ''; try { $r = json_encode(und_rss_parse($xxe)); } catch (Exception $e) { $r = ''; }
t('a feed cannot read files of the server', strpos($r, 'root:') === false, $r);
$calls = []; $GLOBALS['UND_HTTP_MOCK'] = function ($m, $u, $o) use (&$calls, $rss2) { $calls[] = $u; if (strpos($u, 'bad') !== false) return ['code' => 500, 'body' => '', 'json' => null, 'err' => '', 'headers' => []]; return ['code' => 200, 'body' => $rss2, 'json' => null, 'err' => '', 'headers' => []]; };
$r = und_w_rss(['options' => ['feeds' => "Mine | https://a.example/feed\nhttps://bad.example/feed", 'limit' => 1]]);
t('one feed failing leaves the others', count($r['list']) === 1 && $r['list'][0]['title'] === 'One bold & true' && $r['list'][0]['sub'] === 'Mine', json_encode($r));
$n = count($calls); und_w_rss(['options' => ['feeds' => 'Mine | https://a.example/feed']]);
t('a feed is not read again within 15 minutes', count($calls) === $n, json_encode($calls));
t('no feed listed is a clear message', strpos($thrown(function () { und_w_rss(['options' => []]); }), 'Add the address') !== false);
t('an address that is not one is refused', strpos($thrown(function () { und_w_rss(['options' => ['feeds' => 'file:///etc/passwd']]); }), 'Not a web address') !== false);
unset($GLOBALS['UND_HTTP_MOCK']);

/* ---- custom API card builder ---- */
$tr = und_json_tree(['data' => ['users' => 4, 'ok' => true, 'name' => str_repeat('x', 90), 'none' => null], 'items' => [['id' => 1, 'user' => ['name' => 'a']], ['id' => 2]], 'empty' => []]);
$by = []; foreach ($tr as $x) $by[$x['p']] = $x;
t('every value is listed by its path', $by['data.users']['t'] === 'num' && $by['data.users']['v'] === '4' && $by['data.ok']['v'] === 'yes' && $by['data.none']['t'] === 'null');
t('long values are cut short', strlen($by['data.name']['v']) <= 70);
t('a list is one row, with the fields of its first item', $by['items']['t'] === 'list' && $by['items']['n'] === 2 && array_column($by['items']['f'], 'p') === ['id', 'user.name'], json_encode($by['items']));
t('an empty list is listed too', $by['empty']['n'] === 0);
t('an answer that is itself a list works', und_json_tree([['a' => 1]])[0]['p'] === '' && und_json_tree([['a' => 1]])[0]['n'] === 1);
t('a huge answer is cut off', count(und_json_tree(array_fill_keys(array_map(function ($i) { return "k$i"; }, range(1, 1000)), 1))) === 300);
$pc = und_normalize(['groups' => [['id' => 'abcdef01', 'name' => 'G', 'services' => [['id' => 'abcdef02', 'type' => 'customapi', 'name' => 'C', 'url' => 'http://x.example/a', 'options' => ['headers' => ['X-Api-Key' => '{{KEY}}']]], ['id' => 'abcdef03', 'type' => 'radarr', 'url' => 'http://r']]]]]);
$GLOBALS['UND_TEST_SECRETS'] = ['vars' => ['C_API_KEY' => 'sekret123456'], 'svc' => ['abcdef02' => ['key' => 'sekret123456']], 'unraid' => []];
$seen = []; $GLOBALS['UND_HTTP_MOCK'] = function ($m, $u, $o) use (&$seen) { $seen = $o['headers'] ?? []; return ['code' => 200, 'body' => '{"token":"sekret123456-x","n":1}', 'json' => ['token' => 'sekret123456-x', 'n' => 1], 'err' => '', 'headers' => []]; };
$r = und_customapi_probe($pc, 'abcdef02');
t('the card builder sends the key to the app', ($seen['X-Api-Key'] ?? '') === 'sekret123456');
t('and never shows it, even if the app echoes it', strpos(json_encode($r), 'sekret123456') === false, json_encode($r));
t('it only reads custom API apps', isset(und_customapi_probe($pc, 'abcdef03')['error']));
unset($GLOBALS['UND_HTTP_MOCK'], $GLOBALS['UND_TEST_SECRETS']);

/* ---- setup wizard ---- */
reset_store();
$sc = und_normalize([]);
$sc['groups'] = [['id' => 'cccc0001', 'name' => 'G', 'columns' => 4, 'header' => true, 'section' => false, 'services' => []]];
t('a new dashboard with no apps asks for setup', und_setup_needed($sc) === true);
$sc2 = $sc; $sc2['groups'][0]['services'] = [['id' => 'cccc0002', 'type' => 'plex']];
t('a dashboard that has apps is left alone', und_setup_needed($sc2) === false);
t('finishing or skipping stops it asking', und_doc_write(['setup' => ['done' => true]]) && und_setup_needed($sc) === false);
und_save_config(und_normalize([]), false);
t('saving settings does not bring it back', !empty(und_doc()['setup']['done']));
reset_store();

/* ---- guest view ---- */
t('the guest token is not a value apps can use', in_array('UNDASH_GUEST_TOKEN', UND_ENV_RESERVED, true));
$gc = und_normalize(['general' => ['disk_path' => '/mnt/secret', 'ups_name' => 'myups'], 'appearance' => ['wall_path' => '/mnt/user/private/w.jpg'],
  'groups' => [['id' => 'aaaa0001', 'name' => 'G', 'services' => [
    ['id' => 'aaaa0002', 'type' => 'immich', 'name' => 'Photos', 'url' => 'http://10.0.0.5:2283', 'key' => '{{IMMICH_API_KEY}}'],
    ['id' => 'aaaa0003', 'type' => 'gluetun', 'name' => 'VPN', 'url' => 'http://10.0.0.6:8000'],
    ['id' => 'aaaa0004', 'type' => 'radarr', 'name' => 'Radarr', 'url' => 'http://10.0.0.7:7878'],
    ['id' => 'aaaa0005', 'type' => 'customapi', 'name' => 'C', 'url' => 'http://x/a?token=abc', 'options' => ['headers' => ['A' => 'b']]]]]]]);
$gp = ['sections' => ['overview' => true, 'media' => true, 'playing' => false, 'apps' => true], 'apps' => ['aaaa0002', 'aaaa0004']];
$gl = und_guest_layout($gc, $gp); $gj = json_encode($gl);
t('guests get only the apps ticked for them', array_column($gl['groups'][0]['services'], 'id') === ['aaaa0002']);
t('an app of another section stays out, ticked or not', strpos($gj, 'Radarr') === false);
t('no address, key, option or path reaches a guest', !preg_match('/10\.0\.0\.[67]|\{\{|IMMICH_API_KEY|token=abc|\/mnt\/secret|private\/w\.jpg|myups/', $gj), $gj);
t('a section turned off is off', array_values(array_filter($gl['dashboard']['sections'], function ($s) { return $s['id'] === 'playing'; }))[0]['visible'] === false);
$vis = array_column(array_filter($gl['dashboard']['sections'], function ($s) { return $s['visible']; }), 'id');
t('containers, VMs, downloads, requests and alerts are never shown', !array_intersect($vis, ['docker', 'vms', 'downloads', 'requests', 'arr', 'alerts', 'health', 'favorites']), json_encode($vis));
$mv = array_column(array_filter($gl['dashboard']['metrics'], function ($m) { return $m['visible']; }), 'id');
t('the Containers and VMs tiles are off', !array_intersect($mv, ['docker', 'vms', 'path', 'load']), json_encode($mv));
t('search is off', $gl['general']['show_search'] === false && $gl['general']['search_in'] === []);
$gs = und_guest_system(['name' => 'Tower', 'ip' => '10.0.0.2', 'os' => '7.3', 'os_update' => ['available' => true], 'now' => 5, 'uptime' => 9, 'hist' => [], 'cpu' => ['pct' => 3, 'temp' => 40, 'threads' => 8, 'cores' => [1], 'model' => 'x'], 'mem' => ['pct' => 1, 'total' => 2],
  'net' => ['rx' => 1, 'tx' => 2, 'iface' => 'eth0'], 'disk' => ['path' => '/mnt/x'], 'array' => ['state' => 'STARTED', 'size' => 1, 'free' => 1, 'pools' => [['name' => 'cache', 'size' => 1, 'free' => 1, 'temp' => 30, 'device' => 'sdb']], '_rows' => ['x'], 'parity_checks' => ['y']]]);
t('a guest gets the numbers, not the address, version, disks or updates', !preg_match('/10\.0\.0\.2|os_update|eth0|mnt|cache|sdb|_rows|parity_checks/', json_encode($gs)) && $gs['cpu']['pct'] === 3 && $gs['array']['pools'][0]['temp'] === 30, json_encode($gs));
t('a server error says nothing more', und_guest_system(['error' => 'cannot reach http://10.0.0.2/graphql']) === ['error' => 'The server is not answering.']);
$st = und_guest_streams([['title' => 'A', 'user' => 'U', 'device' => 'Living room', 'location' => 'wan', 'bandwidth' => 9, 'app' => 'Plex', 'icon' => 'x']]);
t('a stream keeps what is playing, not where from', $st === [['title' => 'A', 'user' => 'U']], json_encode($st));
$g2 = $gp; 
t('an app not ticked cannot be read by its id', isset(und_guest_service($gc, $g2, 'aaaa0003')['error']));
$GLOBALS['UND_HTTP_MOCK'] = function () { return ['code' => 0, 'body' => '', 'json' => null, 'err' => 'connect to 10.0.0.5 failed', 'headers' => []]; };
t('an app error does not name its address', und_guest_service($gc, $gp, 'aaaa0002') === ['error' => 'Not available right now.'], json_encode(und_guest_service($gc, $gp, 'aaaa0002')));
unset($GLOBALS['UND_HTTP_MOCK']);
foreach (['http://192.168.50.50:8096/', 'http://10.0.0.1', 'https://[fd00::1]/', 'http://tower/', 'http://tower.local/', 'https://plex.lan', 'http://localhost:1', 'https://a:b@public.example.com/', 'javascript:alert(1)', ''] as $u)
  t("a guest does not get the link $u", und_guest_href($u) === '');
t('a public address stays', und_guest_href('https://plex.example.com/web') === 'https://plex.example.com/web' && und_guest_href('https://1.1.1.1/') === 'https://1.1.1.1/');
$gc2 = und_normalize(['groups' => [['id' => 'bbbb0001', 'name' => 'G', 'services' => [
  ['id' => 'bbbb0002', 'type' => 'immich', 'name' => 'Photos', 'url' => 'http://192.168.1.5:2283'], ['id' => 'bbbb0003', 'type' => 'link', 'name' => 'Router', 'href' => 'http://192.168.1.1'], ['id' => 'bbbb0004', 'type' => 'link', 'name' => 'Site', 'href' => 'https://example.com']]]]]);
$gl2 = und_guest_layout($gc2, ['sections' => $gp['sections'], 'apps' => ['bbbb0002', 'bbbb0003', 'bbbb0004']]);
t('an app card keeps its numbers but loses a LAN link', $gl2['groups'][0]['services'][0]['href_resolved'] === '' && strpos(json_encode($gl2), '192.168') === false, json_encode($gl2['groups']));
t('a LAN shortcut is not shown, a public one is', array_column($gl2['groups'][0]['services'], 'name') === ['Photos', 'Site']);
t('and is not offered in Settings', array_column(und_guest_apps($gc2), 'name') === ['Photos', 'Site']);
foreach (['settings', 'save', 'docker_action', 'vm_action', 'power', 'prefs', 'export', 'storage', 'metrics', 'probe', 'test', 'guest_get', 'notify_get', 'alert_history', 'docker_logs', 'unraid_conf', ''] as $a)
  t("a guest cannot call $a", und_guest_api($a, []) === ['error' => 'Not available in the guest view.'], json_encode(und_guest_api($a, [])));

/* ---- saved presets ---- */
$pc = und_presets_clean([
  ['id' => 'aaaa1111', 'name' => " Mine\x01 ", 'desc' => 'x', 'at' => 5, 'board' => ['lg' => [['i' => 'arr', 'x' => 0, 'y' => 0, 'w' => 24, 'h' => 9], ['i' => 'bad id!', 'x' => 0]], 'pages' => [['id' => 'bbbb2222', 'name' => 'P', 'cards' => ['docker'], 'lg' => [], 'md' => []]]],
   'sections' => [['id' => 'arr', 'visible' => true, 'width' => 'full', 'title' => 'T', 'url' => 'http://10.0.0.9']], 'metrics' => [['id' => 'cpu', 'visible' => false]], 'media' => ['style' => 'text', 'radarr' => 'aaaaaaaa'], 'docker' => ['layout' => 'cards', 'items' => ['x' => ['label' => 'y']], 'selected' => ['a']],
   'look' => ['theme' => 'c-ab12cd34', 'radius' => 999, 'custom_themes' => [['id' => 'c-11111111']], 'wall_path' => '/mnt/x', 'background' => 'http://10.0.0.9/w.jpg'], 'groups' => [['id' => 'cccc3333']], 'key' => 'SECRET'],
  ['id' => 'aaaa1111', 'name' => 'twin'], ['id' => 'not-an-id', 'name' => 'x'], 'junk']);
t('a preset with a bad or repeated id is dropped', count($pc) === 1, json_encode(array_column($pc, 'name')));
t('its name is cleaned', $pc[0]['name'] === 'Mine', $pc[0]['name']);
t('it holds no address, key, app or picture', !preg_match('/10\.0\.0\.9|SECRET|cccc3333|\/mnt\/x|custom_themes|"label"|"selected"|radarr/', json_encode($pc)), json_encode($pc));
t('its layout is checked like the real one', array_column($pc[0]['board']['lg'], 'i') === ['arr'] && $pc[0]['board']['pages'][0]['cards'] === ['docker']);
t('its numbers stay in range and your own theme is kept', $pc[0]['look']['radius'] === 28 && $pc[0]['look']['theme'] === 'c-ab12cd34');
t('a preset without a look has none', !isset(und_presets_clean([['id' => 'aaaa1111', 'name' => 'n']])[0]['look']));
$many = []; for ($i = 0; $i < 30; $i++) $many[] = ['id' => sprintf('%08x', $i), 'name' => "p$i"];
t('at most 20 are kept', count(und_presets_clean($many)) === UND_PRESETS_MAX);
$pcfg = und_load_config(); $pcfg['presets'] = $pc; und_save_config($pcfg, false);
t('presets survive config.yaml', und_load_config()['presets'] === und_presets_clean($pc), json_encode(und_load_config()['presets']));
t('the page and guests do not get them', !isset(und_layout_view(und_load_config())['presets']) && !isset(und_guest_layout(und_load_config(), und_guest_prefs())['presets']));
$noP = und_export('config')['text'];
t('a settings file keeps presets', strpos($noP, 'presets:') !== false);
t('a file without presets leaves yours', isset(und_import_apply(preg_replace('/^presets:.*?(?=^[a-z_]+:)/ms', '', $noP))['ok']) && count(und_load_config()['presets']) === 1);
$pcfg = und_load_config(); $pcfg['presets'] = []; und_save_config($pcfg, false);
t('no presets, no key in the file', !isset(und_doc()['presets']));

/* ---- other fixes ---- */
$ei = und_import_parse("RADARR_API_KEY=abc\nUNDASH_PASSWORD_HASH=x\nUNDASH_AUTH=open\nUNDASH_GUEST_TOKEN=y\n");
t('a .env file cannot set the password or the guest link', array_keys($ei['env']) === ['RADARR_API_KEY'] && $ei['summary']['values'] === 1, json_encode($ei['env']));
$fc = und_load_config(); $fc['groups'][] = ['id' => 'dddd0001', 'name' => 'F', 'services' => [['id' => 'dddd0002', 'type' => 'rss', 'name' => 'R', 'options' => ['feeds' => "Mine | https://t.example/rss?passkey=abc123\nhttps://ok.example/a.xml"]]]];
und_save_config($fc, false);
$fx = und_export('config')['text'];
t('a private feed loses its passkey in an export', strpos($fx, 'abc123') === false && strpos($fx, 'ok.example') !== false);
und_notify_set('ntfy', ['url' => 'https://ntfy.sh/topic', 'token' => 'tk_keep']);
t('saving with the optional token left empty keeps it', isset(und_notify_set('ntfy', ['url' => 'https://ntfy.sh/topic', 'token' => ''])['ok']) && und_env()['NOTIFY_NTFY_TOKEN'] === 'tk_keep');
und_notify_off('ntfy');
foreach (['http://192.168.1.2/a.png', '/boot/x.png'] as $u) t("a guest does not get the picture $u", und_guest_pic($u) === '');
t('a public picture and an icon name stay', und_guest_pic('https://a.example/x.png') === 'https://a.example/x.png' && und_guest_pic('sh-plex') === 'sh-plex');
$gv = und_load_config(); $gv['appearance']['background'] = 'http://192.168.1.2/w.jpg'; $gv['general']['logo'] = 'http://10.0.0.8/l.png';
$gvj = json_encode(und_guest_layout($gv, und_guest_prefs())['appearance']) . json_encode(und_guest_layout($gv, und_guest_prefs())['general']);
t('a LAN wallpaper or logo stays away from guests', !preg_match('/192\.168|10\.0\.0\.8/', $gvj), $gvj);

/* ---- Glances, no API key ---- */
t('Glances uptime reads days and hours', und_glances_uptime('5 days, 10:56:00') === 471360 && und_glances_uptime('1 day, 0:00:10') === 86410 && und_glances_uptime('3:00:00') === 10800 && und_glances_uptime('x') === 0);
t('the CPU temperature is the package, not a disk', und_glances_temp([['label' => 'Composite', 'value' => 80], ['label' => 'Core 0', 'value' => 50], ['label' => 'Package id 0', 'value' => 55]]) === 55.0);
t('without a package the hottest core', und_glances_temp([['label' => 'Core 0', 'value' => 50], ['label' => 'Core 1', 'value' => 60], ['label' => 'acpitz 0', 'value' => 99]]) === 60.0);
$gfs = [['device_name' => '/dev/loop2', 'fs_type' => 'btrfs', 'mnt_point' => '/', 'size' => 1e11, 'free' => 1], ['device_name' => '/dev/loop0', 'fs_type' => 'squashfs', 'mnt_point' => '/rootfs/usr', 'size' => 3e10, 'free' => 0],
  ['device_name' => 'cache', 'fs_type' => 'zfs', 'mnt_point' => '/rootfs/mnt/cache', 'size' => 1e12, 'free' => 5e11], ['device_name' => 'cache/appdata', 'fs_type' => 'zfs', 'mnt_point' => '/rootfs/mnt/cache/appdata', 'size' => 1e12, 'free' => 5e11],
  ['device_name' => 'shfs', 'fs_type' => 'fuse.shfs', 'mnt_point' => '/rootfs/mnt/user', 'size' => 9e12, 'free' => 1], ['device_name' => 'data', 'fs_type' => 'zfs', 'mnt_point' => '/rootfs/mnt/data', 'size' => 2e12, 'free' => 1e12]];
t('storage counts each pool once and skips the user share', array_column(und_glances_pools($gfs), 'name') === ['cache', 'data'], json_encode(und_glances_pools($gfs)));
$zp = und_glances_pools([['device_name' => 'tank', 'fs_type' => 'zfs', 'mnt_point' => '/rootfs/mnt/tank', 'size' => 1000, 'free' => 990], ['device_name' => 'tank/media', 'fs_type' => 'zfs', 'mnt_point' => '/rootfs/mnt/tank/media', 'size' => 1500, 'free' => 990]]);
t('a ZFS pool counts what its datasets hold', $zp[0]['size'] === 1510.0 && $zp[0]['free'] === 990.0 && !isset($zp[0]['_dev']), json_encode($zp));
t('with nothing under /mnt, the largest real disk', array_column(und_glances_pools(array_slice($gfs, 0, 2)), 'name') === [basename('/') ?: '/'], json_encode(und_glances_pools(array_slice($gfs, 0, 2))));
t('the main interface wins, loopback does not count', und_glances_net([['interface_name' => 'lo', 'bytes_recv_rate_per_sec' => 9], ['interface_name' => 'eth0', 'bytes_recv_rate_per_sec' => 1, 'bytes_sent_rate_per_sec' => 2], ['interface_name' => 'br0', 'bytes_recv_rate_per_sec' => 3, 'bytes_sent_rate_per_sec' => 4]]) === ['rx' => 3.0, 'tx' => 4.0, 'iface' => 'br0']);
t('Glances 3 rates come from the counters', und_glances_net([['interface_name' => 'eth0', 'rx' => 100, 'tx' => 50, 'time_since_update' => 2]])['rx'] === 50.0);
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $u) {
  $j = strpos($u, '/api/4/') !== false ? null : (strpos($u, 'quicklook') ? ['cpu' => 12.34, 'cpu_name' => 'X', 'percpu' => [['total' => 10], ['total' => 20]]] : (strpos($u, '/mem') && !strpos($u, 'swap') ? ['total' => 100, 'used' => 40, 'available' => 60, 'percent' => 40] : (strpos($u, 'uptime') ? '2:00:00' : [])));
  return ['code' => $j === null ? 404 : 200, 'body' => '', 'json' => $j, 'err' => '', 'headers' => []]; };
$gr = und_glances_read('http://10.0.0.9:61208');
t('an older Glances answers on version 3', $gr['version'] === '3' && $gr['pct'] === 12.3 && $gr['cores'] === [10.0, 20.0] && $gr['mem']['used'] === 40.0 && $gr['uptime'] === 7200, json_encode($gr));
$GLOBALS['UND_HTTP_MOCK'] = function () { return ['code' => 0, 'body' => '', 'json' => null, 'err' => 'refused', 'headers' => []]; };
foreach (glob(und_tmp('glances-fs-*.json')) ?: [] as $f) @unlink($f);
t('a dead address says so', isset(und_glances_read('http://10.0.0.9:61208')['error']));
unset($GLOBALS['UND_HTTP_MOCK']);
t('no address is no alert', und_alerts(und_load_config(), ['error' => 'x', 'none' => true], null) === []);
t('the Glances address must be an address', und_normalize(['general' => ['glances' => 'javascript:alert(1)']])['general']['glances'] === '' && und_normalize(['general' => ['glances' => 'http://1.2.3.4:61208/']])['general']['glances'] === 'http://1.2.3.4:61208');
t('a new dashboard starts at 100%', und_default_config()['appearance']['zoom'] === 100);
t('Auto stays what you chose', und_normalize(['appearance' => ['zoom' => 0]])['appearance']['zoom'] === 0);
t('the side margin goes to 480, the top stays at 200', und_normalize(['appearance' => ['safe_x' => 999, 'safe_y' => 999]])['appearance']['safe_x'] === 480 && und_normalize(['appearance' => ['safe_x' => 999, 'safe_y' => 999]])['appearance']['safe_y'] === 200);

/* ---------- more app presets ---------- */
$ok = function ($j, $h = []) { return ['code' => 200, 'body' => is_string($j) ? $j : json_encode($j), 'json' => is_string($j) ? null : $j, 'err' => '', 'headers' => $h]; };
$sv = function ($type, $x = []) { return $x + ['id' => 'abcd0001', 'type' => $type, 'name' => $type, 'url' => 'http://app', 'key' => 'k', 'username' => 'u', 'password' => 'p', 'fields' => [], 'options' => []]; };
$vals = function ($r) { $o = []; foreach ($r['blocks'] as $b) $o[$b['key']] = $b['value']; return $o; };
$calls = [];
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) use ($ok, &$calls) {
  $calls[] = [$m, $url, $o];
  $p = parse_url($url, PHP_URL_PATH);
  $body = json_decode((string)($o['body'] ?? ''), true);
  switch ($p) {
    case '/json': return ($body['method'] ?? '') === 'auth.login' ? $ok(['result' => true], ['set-cookie' => ['_session_id=abc; Path=/']]) : (($o['cookie'] ?? '') === '_session_id=abc' ? $ok(['result' => ['torrents' => ['a' => ['progress' => 100], 'b' => ['progress' => 40.5]], 'stats' => ['download_rate' => 10, 'upload_rate' => 20]]]) : $ok(['result' => null]));
    case '/transmission/rpc': return empty($o['headers']['X-Transmission-Session-Id']) ? ['code' => 409, 'body' => '', 'json' => null, 'err' => '', 'headers' => ['x-transmission-session-id' => ['sid']]] : $ok(['arguments' => ['torrents' => [['percentDone' => 1, 'rateDownload' => 0, 'rateUpload' => 7], ['percentDone' => .5, 'rateDownload' => 5, 'rateUpload' => 1]]]]);
    case '/rest/getScanStatus.view': return $ok(['subsonic-response' => ['status' => 'ok', 'scanStatus' => ['count' => 900, 'folderCount' => 30]]]);
    case '/rest/getNowPlaying.view': return $ok(['subsonic-response' => ['status' => 'ok', 'nowPlaying' => ['entry' => [['title' => 'Song', 'artist' => 'Band', 'username' => 'sam', 'duration' => 200]]]]]);
    case '/api/libraries': return $ok(['libraries' => [['id' => 'l1', 'mediaType' => 'book'], ['id' => 'l2', 'mediaType' => 'podcast']]]);
    case '/api/libraries/l1/stats': return $ok(['totalItems' => 4, 'totalDuration' => 100, 'totalSize' => 1000]);
    case '/api/libraries/l2/stats': return $ok(['totalItems' => 2, 'totalDuration' => 50, 'totalSize' => 500]);
    case '/api/Plugin/authenticate': return $ok(['token' => 'tok']);
    case '/api/Stats/server/stats': return ($o['headers']['Authorization'] ?? '') === 'Bearer tok' ? $ok(['seriesCount' => 3, 'volumeCount' => 4, 'totalFiles' => 5, 'totalSize' => 6]) : ['code' => 401, 'body' => '', 'json' => null, 'err' => '', 'headers' => []];
    case '/ocs/v2.php/apps/serverinfo/api/v1/info': return $ok(['ocs' => ['data' => ['nextcloud' => ['system' => ['freespace' => 99], 'storage' => ['num_users' => 2, 'num_files' => 7]], 'activeUsers' => ['last24hours' => 1]]]]);
    case '/api/v1/repos/issues/search': return $ok([], ['x-total-count' => [strpos($url, 'type=pulls') ? '2' : '9']]);
    case '/api/v1/repos/search': return $ok(['data' => []], ['x-total-count' => ['12']]);
    case '/api/v1/notifications/new': return $ok(['new' => 4]);
    case '/api/v1/watch': return $ok(['a' => ['title' => 'Old', 'last_changed' => 5, 'last_viewed' => 9], 'b' => ['title' => '', 'url' => 'http://x', 'last_changed' => 9, 'last_viewed' => 1, 'last_error' => 'boom']]);
    case '/api/dashboard/stats/get': return $ok(['status' => 'ok', 'response' => ['stats' => ['totalQueries' => 200, 'totalBlocked' => 50, 'totalClients' => 3]]]);
    case '/api/v3/checks/': return $ok(['checks' => [['status' => 'up', 'name' => 'a'], ['status' => 'down', 'name' => 'nas'], ['status' => 'grace', 'name' => 'c']]]);
    case '/api/v1/endpoints/statuses': return $ok([['name' => 'web', 'results' => [['success' => false], ['success' => true]]], ['name' => 'db', 'group' => 'core', 'results' => [['success' => false]]], ['name' => 'new', 'results' => []]]);
    case '/api/collections/_superusers/auth-with-password': return ['code' => 404, 'body' => '', 'json' => null, 'err' => '', 'headers' => []];
    case '/api/collections/users/auth-with-password': return $ok(['token' => 'pb']);
    case '/api/collections/systems/records': return $ok(['items' => [['status' => 'up', 'name' => 'a'], ['status' => 'down', 'name' => 'b']]]);
    case '/api/v1/targets': return $ok(['data' => ['activeTargets' => [['health' => 'up'], ['health' => 'down', 'labels' => ['job' => 'node', 'instance' => 'x:9100']], ['health' => 'unknown']]]]);
    case '/api/endpoints': return $ok([['Id' => 1, 'Type' => 1, 'Status' => 1], ['Id' => 2, 'Type' => 5, 'Status' => 1], ['Id' => 3, 'Type' => 2, 'Status' => 2]]);
    case '/api/endpoints/1/docker/containers/json': return $ok([['State' => 'running'], ['State' => 'exited']]);
    case '/api/containers': return $ok([['name' => 'a', 'updateAvailable' => true], ['name' => 'b', 'updateAvailable' => false]]);
    case '/api/auth/login': return ['code' => 404, 'body' => '', 'json' => null, 'err' => '', 'headers' => []];
    case '/api/login': return $ok(['meta' => ['rc' => 'ok']], ['set-cookie' => ['unifises=abc; Path=/', 'csrf_token=d; Path=/']]);
    case '/api/s/default/stat/health': return ($o['cookie'] ?? '') === 'unifises=abc; csrf_token=d' ? $ok(['data' => [['subsystem' => 'wan', 'status' => 'ok'], ['subsystem' => 'wlan', 'num_user' => 4, 'num_guest' => 1, 'num_adopted' => 2], ['subsystem' => 'lan', 'num_user' => 3, 'num_adopted' => 1]]]) : $ok([]);
    case '/api/greader.php/accounts/ClientLogin': return $ok("SID=x\nLSID=null\nAuth=tok\n");
    case '/api/greader.php/reader/api/0/unread-count': return $ok(['unreadcounts' => [['id' => 'feed/1', 'count' => 1], ['id' => 'user/-/state/com.google/reading-list', 'count' => 8]]]);
    case '/api/greader.php/reader/api/0/subscription/list': return $ok(['subscriptions' => [[], []]]);
    case '/api/login/': return $ok('x');
  }
  return null;
};
$v = $vals(und_w_deluge($sv('deluge')));
t('Deluge counts by progress, with its login cookie', $v === ['leech' => 1, 'download' => 10.0, 'seed' => 1, 'upload' => 20.0]);
$v = $vals(und_w_transmission($sv('transmission')));
t('Transmission retries with its session id', $v['leech'] === 1 && $v['seed'] === 1 && $v['upload'] === 8.0);
$r = und_w_navidrome($sv('navidrome')); $sent = end($calls)[1];
t('Navidrome sends a salted hash, never the password', !preg_match('/[?&]p=/', $sent) && preg_match('/[?&]s=([a-f0-9]+)/', $sent, $m) && strpos($sent, 't=' . md5('p' . $m[1])) !== false);
t('Navidrome shows who is playing', $vals($r)['songs'] === 900 && $r['streams'][0]['sub'] === 'Band' && $r['streams'][0]['user'] === 'sam');
$v = $vals(und_w_audiobookshelf($sv('audiobookshelf')));
t('Audiobookshelf adds up its libraries by kind', $v['books'] === 4 && $v['podcasts'] === 2 && $v['duration'] == 150);
t('Kavita trades the key for a token', $vals(und_w_kavita($sv('kavita')))['seriesCount'] === 3);
t('Nextcloud reads its server info', $vals(und_w_nextcloud($sv('nextcloud')))['free'] === 99.0);
$v = $vals(und_w_gitea($sv('gitea')));
t('Gitea reads totals from the header', $v === ['notifications' => 4, 'issues' => 9, 'pulls' => 2, 'repos' => 12]);
$r = und_w_changedetection($sv('changedetection'));
t('changedetection counts only unseen changes', $vals($r) === ['total' => 2, 'changed' => 1, 'errors' => 1] && $r['list'][0]['title'] === 'http://x');
t('Technitium works out the blocked share', $vals(und_w_technitium($sv('technitium')))['blocked_percent'] == 25);
$r = und_w_healthchecks($sv('healthchecks'));
t('Healthchecks names what is down', $vals($r)['late'] === 1 && $r['list'][0]['title'] === 'nas');
$r = und_w_gatus($sv('gatus'));
t('Gatus goes by the latest result', $vals($r) === ['up' => 1, 'down' => 1] && $r['list'][0]['title'] === 'core / db');
t('Beszel falls back to the older login', $vals(und_w_beszel($sv('beszel'))) === ['up' => 1, 'down' => 1, 'paused' => 0]);
$r = und_w_prometheus($sv('prometheus'));
t('Prometheus counts targets', $vals($r) === ['up' => 1, 'down' => 1, 'total' => 3] && $r['list'][0]['title'] === 'node x:9100');
t('Portainer reads only Docker environments that are up', $vals(und_w_portainer($sv('portainer'))) === ['running' => 1, 'stopped' => 1, 'total' => 2]);
t('What\'s Up Docker counts updates', $vals(und_w_wud($sv('wud'))) === ['monitored' => 2, 'updates' => 1]);
$v = $vals(und_w_unifi($sv('unifi', ['key' => ''])));
t('UniFi falls back to a classic controller', $v === ['wan' => 'ok', 'users' => 7, 'guests' => 1, 'devices' => 3]);
t('FreshRSS takes the unread total, not a feed', $vals(und_w_freshrss($sv('freshrss'))) === ['unread' => 8, 'subscriptions' => 2]);
unset($GLOBALS['UND_HTTP_MOCK']);
$vi = function ($n) { $o = ''; do { $b = $n & 0x7f; $n >>= 7; $o .= chr($n ? $b | 0x80 : $b); } while ($n); return $o; };
$js = json_encode(['version' => ['name' => '1.21'], 'players' => ['online' => 2, 'max' => 20], 'description' => str_repeat('x', 300)]);
$pl = "\0" . $vi(strlen($js)) . $js;
$j = (function () use ($vi, $pl) { $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0); fwrite($pair[1], $vi(strlen($pl)) . $pl); $r = und_mc_status($pair[0], 'host', 25565); fread($pair[1], 300); return $r; })();
t('Minecraft status reads players and version, past a two-byte length', $j['players']['online'] === 2 && $j['version']['name'] === '1.21');
foreach (array_keys(und_types()) as $x) if (!in_array($x, ['system_info', 'cpu', 'memory', 'link'], true) && !function_exists('und_w_' . $x)) t("widget for $x exists", false);
t('every app type has a reader', true);
$m = und_normalize(['groups' => [], 'media' => ['widgets' => [['id' => 'recent_movies', 'title' => '  New films  ', 'count' => 3]]]])['media']['widgets'];
t('a media list keeps its name', $m[2]['title'] === 'New films' && $m[2]['count'] === 3 && $m[0]['title'] === '');
t('a new media list shows 5', $m[0]['count'] === 5);
$c = und_hp_convert("- G:\n    - N:\n        widget:\n          type: paperlessngx\n          url: http://p\n          key: abc\n");
t('Homepage import keeps the new app types', $c['config']['groups'][0]['services'][0]['type'] === 'paperless');

/* ---------- audit fixes ---------- */
$seen = [];
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) use (&$seen) {
  $seen[] = [$m, $url, $o];
  $to = ['http://a.lan:8/x' => 'http://evil.example/y', 'http://b.lan/x' => 'https://b.lan/x', 'http://c.lan/p/q' => 'r', 'http://d.lan/' => 'ftp://d.lan/', 'http://e.lan/' => 'http://e.lan/'][$url] ?? null;
  if ($to) return ['code' => 302, 'body' => '', 'json' => null, 'err' => '', 'headers' => ['location' => [$to]]];
  return ['code' => 200, 'body' => '{}', 'json' => [], 'err' => '', 'headers' => []];
};
$sec = ['headers' => ['X-Api-Key' => 'SECRET', 'Authorization' => 'Bearer s', 'Accept' => 'a/b'], 'basic' => ['u', 'p'], 'cookie' => 'SID=1', 'body' => 'x'];
und_http('POST', 'http://a.lan:8/x', $sec); $last = end($seen);
t('a redirect to another server carries no key, login or cookie', $last[1] === 'http://evil.example/y' && $last[0] === 'GET' && !isset($last[2]['basic']) && !isset($last[2]['cookie']) && !isset($last[2]['body']) && array_keys($last[2]['headers']) === ['Accept']);
$seen = []; und_http('GET', 'http://b.lan/x', $sec); $last = end($seen);
t('http to https on the same app keeps them', $last[1] === 'https://b.lan/x' && $last[2]['headers']['X-Api-Key'] === 'SECRET' && $last[2]['basic'] === ['u', 'p']);
$seen = []; und_http('GET', 'http://c.lan/p/q'); t('a relative redirect is followed', end($seen)[1] === 'http://c.lan/p/r');
$seen = []; und_http('GET', 'http://d.lan/'); t('a redirect to anything but the web is not', count($seen) === 1);
$r = und_http('GET', 'http://e.lan/'); t('a redirect loop ends with an error', $r['err'] === 'Too many redirects.');
t('https never drops to http with a key', !und_http_same('https://a.lan/', 'http://a.lan/'));
t('another port is another app', !und_http_same('http://a.lan:8/', 'http://a.lan:9/'));
unset($GLOBALS['UND_HTTP_MOCK']);
foreach (['javascript:alert(1)', ' JavaScript:x', "java\tscript:x", 'data:text/html,x', 'vbscript:x'] as $bad) {
  $m = und_normalize(['groups' => [['name' => 'g', 'services' => [['type' => 'link', 'name' => 'n', 'href' => $bad]]]]]);
  t('an unsafe link is dropped: ' . addcslashes($bad, "\t"), $m['groups'][0]['services'][0]['href'] === '');
}
$m = und_normalize(['groups' => [['name' => 'g', 'services' => [['type' => 'link', 'name' => 'n', 'href' => '{{HOST}}/app'], ['type' => 'link', 'name' => 'm', 'href' => 'https://x.lan/a?b=1']]]]]);
t('ordinary links stay', $m['groups'][0]['services'][0]['href'] === '{{HOST}}/app' && $m['groups'][0]['services'][1]['href'] === 'https://x.lan/a?b=1');
$pf = und_tmp('power-started'); file_put_contents($pf, 'reboot');
t('a power action under way shows', und_power_started() === 'reboot');
touch($pf, time() - 1000); t('one that never finished stops blocking', und_power_started() === '' && !is_file($pf));
und_store_reset();
$cf = und_file('config.yaml'); $good = (string)@file_get_contents($cf);
$svc = ['id' => 'abcd9999', 'type' => 'link', 'name' => 'Kept app', 'href' => 'http://k'];
und_save_config(['groups' => [['name' => 'Mine', 'services' => [$svc]]]] + und_default_config()); und_backup('audit test');
foreach (['cut short' => "version: 3\ngeneral:\n  name: x\n", 'emptied' => '', 'not YAML' => "\0\1 garbage"] as $why => $bad) {
  @unlink(und_tmp('config-recovered.json')); foreach (glob($cf . '.damaged-*') ?: [] as $x) @unlink($x);
  file_put_contents($cf, $bad); und_store_reset();
  $c = und_load_config();
  t("a damaged config.yaml comes back from the backup ($why)", ($c['groups'][0]['services'][0]['name'] ?? '') === 'Kept app' && !empty(und_read_json(und_tmp('config-recovered.json'), null)['kept']));
  t("... and the damaged file is kept ($why)", count(glob($cf . '.damaged-*') ?: []) === 1);
}
@unlink($cf); @unlink(UND_DIR . '/config.yaml'); und_store_reset(); @unlink(und_tmp('config-recovered.json'));
t('a file removed on purpose is not brought back', (und_load_config()['groups'][0]['services'][0]['name'] ?? '') !== 'Kept app' && !is_file($cf));
foreach (glob($cf . '.damaged-*') ?: [] as $x) @unlink($x);
if ($good !== '') file_put_contents($cf, $good); und_store_reset();

/* ---------------- skipping one version of a container ---------------- */
group('update skip');
reset_store();
$r = und_image_ref('radarr'); t('a bare name is a Docker Hub library image', $r && $r['host'] === 'registry-1.docker.io' && $r['repo'] === 'library/radarr' && $r['tag'] === 'latest');
$r = und_image_ref('binhex/arch-sabnzbd:5.0'); t('user image with a tag', $r && $r['repo'] === 'binhex/arch-sabnzbd' && $r['tag'] === '5.0');
$r = und_image_ref('ghcr.io/stiphr/undash:beta'); t('another registry', $r && $r['host'] === 'ghcr.io' && $r['repo'] === 'stiphr/undash' && $r['tag'] === 'beta');
$r = und_image_ref('reg.lan:5000/a/b'); t('a registry with a port', $r && $r['host'] === 'reg.lan:5000' && $r['repo'] === 'a/b');
t('a pinned image has no version to follow', und_image_ref('nginx@sha256:' . str_repeat('a', 64)) === null);
t('a bad name is refused', und_image_ref('../../etc:x y') === null && und_image_ref('') === null);
$dA = 'sha256:' . str_repeat('a', 64); $dB = 'sha256:' . str_repeat('b', 64);
$saved = $GLOBALS['UND_HTTP_MOCK'] ?? null; $GLOBALS['SEEN'] = [];
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) use ($dA) {
  $GLOBALS['SEEN'][] = ['m' => $m, 'u' => $url, 'h' => $o['headers'] ?? []];
  if (strpos($url, 'auth.example') !== false) return ['code' => 200, 'json' => ['token' => 'TOK'], 'body' => '', 'err' => ''];
  if (($o['headers']['Authorization'] ?? '') === 'Bearer TOK') return ['code' => 200, 'json' => null, 'body' => '', 'err' => '', 'headers' => ['docker-content-digest' => [$dA]]];
  return ['code' => 401, 'json' => null, 'body' => '', 'err' => '', 'headers' => ['www-authenticate' => ['Bearer realm="https://auth.example/token",service="reg"']]];
};
t('the digest comes from the anonymous token flow', und_registry_digest('binhex/x') === $dA);
t('the manifest is read with HEAD', $GLOBALS['SEEN'][0]['m'] === 'HEAD' && $GLOBALS['SEEN'][2]['m'] === 'HEAD');
t('the token asks to pull this repository only', strpos($GLOBALS['SEEN'][1]['u'], 'scope=repository%3Abinhex%2Fx%3Apull') !== false && strpos($GLOBALS['SEEN'][1]['u'], 'service=reg') !== false);
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) { return ['code' => 500, 'json' => null, 'body' => '', 'err' => '', 'headers' => []]; };
t('a registry that will not answer gives no digest', und_registry_digest('binhex/x') === '');
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) { return ['code' => 200, 'json' => null, 'body' => '', 'err' => '', 'headers' => ['docker-content-digest' => ['nonsense']]]; };
t('a malformed digest is not trusted', und_registry_digest('binhex/x') === '');
if ($saved) $GLOBALS['UND_HTTP_MOCK'] = $saved; else unset($GLOBALS['UND_HTTP_MOCK']);
$cfgK = und_load_config(); $cfgK['docker']['items'] = ['a' => ['skip_update' => $dA], 'b' => ['skip_update' => 'junk', 'favorite' => true], 'c' => ['no_update' => true]];
und_save_config($cfgK, false);
$kept = (array)und_load_config()['docker']['items'];
t('a skip survives saving', ($kept['a']['skip_update'] ?? '') === $dA);
t('a malformed skip is dropped', !isset($kept['b']['skip_update']) && !empty($kept['b']['favorite']));
@mkdir(dirname(und_path(UND_UPDSTATUS)), 0777, true);
file_put_contents(und_path(UND_UPDSTATUS), json_encode(['img/a:latest' => ['remote' => $dA, 'status' => 'false'], 'img/c:latest' => ['remote' => $dB, 'status' => 'false']]));
$l = und_docker_skips([['name' => 'a', 'image' => 'img/a', 'update' => 'available'], ['name' => 'c', 'image' => 'img/c', 'update' => 'available'], ['name' => 'd', 'image' => 'img/a', 'update' => 'available']]);
t('the skipped version is marked', !empty($l[0]['skip']));
t('an ignored or other container is not', empty($l[1]['skip']) && empty($l[2]['skip']));
file_put_contents(und_path(UND_UPDSTATUS), json_encode(['img/a:latest' => ['remote' => $dB, 'status' => 'false']]));
$l = und_docker_skips([['name' => 'a', 'image' => 'img/a', 'update' => 'available']]);
t('a newer version shows again', empty($l[0]['skip']));
t('an image that is up to date is not marked', empty(und_docker_skips([['name' => 'a', 'image' => 'img/a', 'update' => 'current']])[0]['skip']));
@unlink(und_path(UND_UPDSTATUS));
reset_store();

/* ---------------- less work for slow apps ---------------- */
group('slow apps');
reset_store();
$mkS = function ($id, $type, $name) { return ['id' => $id, 'type' => $type, 'name' => $name, 'url' => 'http://app.lan:1', 'href' => '', 'key' => 'k', 'username' => 'u', 'password' => 'p']; };
$cfgQ = und_normalize(['groups' => [['id' => 'aaaaaaaa', 'name' => 'Dl', 'services' => [$mkS('b0000001', 'qbittorrent', 'Qb'), $mkS('b0000002', 'prowlarr', 'Pr')]]]]);
$saved = $GLOBALS['UND_HTTP_MOCK'] ?? null; $GLOBALS['SEEN'] = []; $GLOBALS['QA'] = 0.5;
$GLOBALS['UND_HTTP_MOCK'] = function ($m, $url, $o) {
  $GLOBALS['SEEN'][] = $url;
  $j = function ($v) { return ['code' => 200, 'json' => $v, 'body' => json_encode($v), 'err' => '', 'headers' => []]; };
  if (strpos($url, '/auth/login') !== false) return ['code' => 200, 'json' => null, 'body' => 'Ok.', 'err' => '', 'headers' => ['set-cookie' => ['SID=x; path=/']]];
  if (strpos($url, '/transfer/info') !== false) return $j(['dl_info_speed' => 1, 'up_info_speed' => 2, 'connection_status' => 'connected']);
  if (strpos($url, 'filter=active') !== false) return $j([['hash' => 'a', 'name' => 'Moving', 'state' => 'downloading', 'progress' => $GLOBALS['QA'], 'dlspeed' => 9, 'eta' => 5, 'size' => 1, 'magnet_uri' => 'long']]);
  if (strpos($url, '/torrents/info') !== false) return $j([['hash' => 'a', 'name' => 'Moving', 'state' => 'downloading', 'progress' => 0.1, 'dlspeed' => 1, 'eta' => 5, 'size' => 1, 'tracker' => 'http://secret/announce'], ['hash' => 'b', 'name' => 'Done', 'state' => 'stalledUP', 'progress' => 1, 'dlspeed' => 0, 'eta' => 0, 'size' => 1]]);
  if (strpos($url, 'indexerstats') !== false) return $j(['indexers' => [['numberOfGrabs' => 7, 'numberOfQueries' => 8]]]);
  return null;
};
$full = function () { return count(array_filter($GLOBALS['SEEN'], function ($u) { return strpos($u, '/torrents/info') !== false && strpos($u, 'filter=') === false; })); };
$a = und_dl_status($cfgQ, 'b0000001');
t('the card counts come from the whole list', $a['counts']['downloading'] === 1 && $a['counts']['seeding'] === 1 && $a['counts']['completed'] === 1);
t('a moving torrent is the fresh one', ($a['items'][0]['progress'] ?? 0) == 50.0, json_encode($a['items']));
@unlink(und_tmp('api-dl-b0000001.json')); $GLOBALS['QA'] = 0.75;
$a = und_dl_status($cfgQ, 'b0000001');
t('the second refresh reads the active ones only', $full() === 1 && ($a['items'][0]['progress'] ?? 0) == 75.0, $full() . ' ' . json_encode($a['items']));
t('the kept list holds only what the card needs', strpos((string)file_get_contents(und_tmp('api-qbit-list-b0000001.json')), 'secret') === false);
$GLOBALS['SPAWNED'] = []; $GLOBALS['UND_SPAWN_MOCK'] = function ($argv, $log) { $GLOBALS['SPAWNED'][] = $argv; };
$pr = ['id' => 'b0000002', 'url' => 'http://app.lan:1', 'key' => 'k', 'username' => '', 'password' => '', 'fields' => []];
$r = und_w_prowlarr($pr);
t('first Prowlarr read waits for the numbers', $r['blocks'][0]['value'] == 7 && !$GLOBALS['SPAWNED'], json_encode($r['blocks'][0]));
$GLOBALS['SEEN'] = []; und_w_prowlarr($pr);
t('a fresh copy asks nothing', !$GLOBALS['SEEN'] && !$GLOBALS['SPAWNED']);
$pf = und_tmp('api-prowlarr-' . md5($pr['url'] . $pr['key']) . '.json'); $oldC = und_read_json($pf, null); $oldC['t'] = microtime(true) - 7200; und_write_json($pf, $oldC);
$r = und_w_prowlarr($pr);
t('an old copy answers at once and refreshes behind', $r['blocks'][0]['value'] == 7 && !$GLOBALS['SEEN'] && count($GLOBALS['SPAWNED']) === 1);
und_w_prowlarr($pr);
t('the refresh starts only once', count($GLOBALS['SPAWNED']) === 1);
$GLOBALS['UND_FRESH'] = true; und_w_prowlarr($pr); unset($GLOBALS['UND_FRESH']);
t('the refresh itself asks Prowlarr', count($GLOBALS['SEEN']) === 1);
if ($saved) $GLOBALS['UND_HTTP_MOCK'] = $saved; else unset($GLOBALS['UND_HTTP_MOCK']);
reset_store();

/* ---------------- navbar ---------------- */
group('navbar');
$n = und_normalize(['appearance' => []])['appearance'];
t('the navbar shows everything by default', $n['nav_logo'] && $n['nav_name'] && $n['nav_os'] && $n['nav_app'] && $n['nav_live'] && $n['nav_alerts'] && $n['nav_server'] && $n['nav_hide'] && $n['nav_sticky'] && $n['nav_search'] === 'normal');
$n = und_normalize(['appearance' => ['header_chips' => false]])['appearance'];
t('the old versions switch carries over to both', !$n['nav_os'] && !$n['nav_app']);
$n = und_normalize(['appearance' => ['header_chips' => false, 'nav_os' => true, 'nav_app' => false]])['appearance'];
t('each version has its own switch', $n['nav_os'] && !$n['nav_app']);
t('an unknown search width is refused', und_normalize(['appearance' => ['nav_search' => 'huge']])['appearance']['nav_search'] === 'normal');
t('a search width is kept', und_normalize(['appearance' => ['nav_search' => 'wide']])['appearance']['nav_search'] === 'wide');

echo "\n--- password guessing\n";
require_once dirname(__DIR__) . '/src/include/auth.php';
foreach (glob(UND_TMP . '/login-*') ?: [] as $f) @unlink($f);
$ip = '203.0.113.7';
for ($i = 0; $i < 4; $i++) und_login_failed($ip);
t('4 wrong passwords do not lock', !und_login_throttled($ip));
und_login_failed($ip);
$w = und_login_wait($ip); t('the 5th locks for 15 minutes', $w > 890 && $w <= 900, (string)$w);
t('the message says how long, and the way back in', strpos(und_login_msg($ip), '15 minutes') !== false && strpos(und_login_msg($ip), 'restart') !== false);
t('another address is free', !und_login_throttled('203.0.113.8'));
$nxt = function ($ip) { $f = und_login_file($ip); $s = und_read_json($f, []); $s['until'] = time() - 1; und_write_json($f, $s, true); for ($i = 0; $i < 5; $i++) und_login_failed($ip); return und_login_wait($ip); };
$w = $nxt($ip); t('the next lockout lasts twice as long', $w > 1790 && $w <= 1800, (string)$w);
$w = $nxt($ip); t('and the next twice that', $w > 3590 && $w <= 3600, (string)$w);
foreach (range(1, 8) as $i) $w = $nxt($ip);
t('a lockout never passes a day', $w > 86300 && $w <= 86400, (string)$w);
$s = und_read_json(und_login_file($ip), []); $s['at'] = time() - UND_LOGIN_FORGET - 1; $s['until'] = 0; und_write_json(und_login_file($ip), $s, true);
t('a quiet week starts over', (int)und_login_read(und_login_file($ip))['locks'] === 0);
foreach (glob(UND_TMP . '/login-*') ?: [] as $f) @unlink($f);
t('one IPv6 /64 is one address', und_login_key('2001:db8:1:2::5') === und_login_key('2001:db8:1:2:ffff::9') && und_login_key('2001:db8:1:2::5') !== und_login_key('2001:db8:1:3::5'));
t('an IPv4 address in IPv6 form counts as itself', und_login_key('::ffff:10.0.0.4') === und_login_key('10.0.0.4'));
$_SERVER['REMOTE_ADDR'] = '172.18.0.5'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
putenv('UNDASH_TRUSTED_PROXIES');
t('X-Forwarded-For counts for nothing by default', und_client_ip() === '172.18.0.5');
putenv('UNDASH_TRUSTED_PROXIES=172.18.0.0/16, 10.1.1.1');
t('from a trusted proxy it gives the visitor', und_client_ip() === '198.51.100.9');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.9, 10.1.1.1';
t('a forged entry on the left is ignored', und_client_ip() === '198.51.100.9');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'junk';
t('a bad header falls back to the proxy', und_client_ip() === '172.18.0.5');
$_SERVER['REMOTE_ADDR'] = '198.51.100.20'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
t('a visitor who is not a proxy cannot claim another address', und_client_ip() === '198.51.100.20');
putenv('UNDASH_TRUSTED_PROXIES'); unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
foreach (['password123', 'Password1234', 'P@ssw0rd2024', 'qwertyuiop', '1234567890', 'aaaaaaaaaaaa', 'unraid12345', 'admin123456'] as $p) t('refused: ' . $p, und_password_weak($p));
foreach (['correct horse battery', 'a long password', 'tram-lamp-river-9'] as $p) t('allowed: ' . $p, !und_password_weak($p));
for ($i = 0; $i < 6; $i++) und_login_failed('198.51.100.1');
$g = und_login_seen(); t('wrong passwords are counted for the alert', $g['n'] === 6 && $g['ip'] === '198.51.100.1');
foreach (glob(UND_TMP . '/login-*') ?: [] as $f) @unlink($f);

echo "\n--- saved keys stay with their address\n";
$svc = function ($id, $name, $url, $key) { return ['id' => $id, 'name' => $name, 'url' => $url, 'key' => $key, 'username' => '', 'password' => '', 'options' => []]; };
$oldc = ['groups' => [['services' => [$svc('aaaaaaaa', 'Radarr', 'http://10.0.0.2:7878', '{{RADARR_API_KEY}}'), $svc('bbbbbbbb', 'Hooked', 'http://{{SERVER_IP}}:9000', '{{HOOK_KEY}}')]]]];
$e = ['RADARR_API_KEY' => 'abc', 'HOOK_KEY' => 'def', 'SERVER_IP' => '10.0.0.3'];
$mv = function ($id, $url, $key = null, $creds = []) use ($oldc, $svc, $e) { $c = $oldc; foreach ($c['groups'][0]['services'] as &$x) if ($x['id'] === $id) { $x['url'] = $url; if ($key !== null) $x['key'] = $key; } return und_creds_moved($c, $oldc, $creds, $e); };
t('the same address is fine', $mv('aaaaaaaa', 'http://10.0.0.2:7878/base') === null);
t('a saved key cannot follow a new address', $mv('aaaaaaaa', 'http://198.51.100.1:7878') === 'Radarr');
t('a new port counts as a new address', $mv('aaaaaaaa', 'http://10.0.0.2:9999') === 'Radarr');
t('typing the key again allows it', $mv('aaaaaaaa', 'http://198.51.100.1:7878', null, ['aaaaaaaa' => ['key' => 'typed-again']]) === null);
t('a reference typed instead does not', $mv('aaaaaaaa', 'http://198.51.100.1:7878', null, ['aaaaaaaa' => ['key' => '{{RADARR_API_KEY}}']]) === 'Radarr');
t('an address written with a shared value is the same address', $mv('bbbbbbbb', 'http://10.0.0.3:9000') === null);
$c2 = $oldc; $c2['groups'][0]['services'][] = $svc('cccccccc', 'Mine', 'http://198.51.100.1', '{{RADARR_API_KEY}}');
t('a new app cannot borrow another app\'s key', und_creds_moved($c2, $oldc, [], $e) === 'Mine');
$c2 = $oldc; $c2['groups'][0]['services'][] = $svc('cccccccc', 'Mine', 'http://198.51.100.1', '{{BRAND_NEW}}');
t('a new app with its own key is fine', und_creds_moved($c2, $oldc, [], $e) === null);
$c2 = $oldc; $c2['groups'][0]['services'][0]['key'] = ''; $h = $svc('cccccccc', 'Hdr', 'http://198.51.100.1', ''); $h['options']['headers'] = ['X-Api-Key' => '{{RADARR_API_KEY}}']; $c2['groups'][0]['services'][] = $h;
t('nor through a custom header', und_creds_moved($c2, $oldc, [], $e) === 'Hdr');
t('more secrets are hidden in logged addresses', strpos(und_redact('http://x/a?password=hunter2&access_token=abc&q=ok'), 'hunter2') === false && strpos(und_redact('http://x/a?access_token=abcdef'), 'abcdef') === false && strpos(und_redact('http://x/a?q=ok'), 'q=ok') !== false);
$_SERVER['REMOTE_ADDR'] = '172.18.0.5'; $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https'; unset($_SERVER['HTTPS']);
t('X-Forwarded-Proto counts for nothing by default', !und_https());
putenv('UNDASH_TRUSTED_PROXIES=172.18.0.0/16');
t('from a trusted proxy cookies are Secure', und_https());
$_SERVER['REMOTE_ADDR'] = '198.51.100.3'; t('not from anyone else', !und_https());
putenv('UNDASH_TRUSTED_PROXIES'); unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

rmrf($SANDBOX);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
