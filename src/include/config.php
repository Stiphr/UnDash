<?php
/*
 * UnDash - configuration (schema version UND_SCHEMA, documented in docs/CONFIG.md).
 *
 * Where it lives
 *   The configuration directory defaults to /mnt/user/appdata/undash and is set in /boot/config/plugins/undash/undash.cfg.
 *   Every save is mirrored to the flash drive, so UnDash keeps working while the array (and with it /mnt/user) is stopped.
 *   Reads prefer the configuration directory and fall back to the flash copy.
 *
 * Files in the configuration directory (see include/store.php)
 *   config.yaml   everything you set up; credentials only as references like {{RADARR_API_KEY}}
 *   .env          the values of those references, the Unraid API key (never sent to the browser in clear text)
 *   data/         backups (both files, before every save, import and restore; newest 15) and the alert history
 */

define('UND_SCHEMA', 5);

/** Every widget type: [label, credential fields it uses, needs url]. */
function und_types() {
  return [
    'system_info' => ['System information', [], false], 'cpu' => ['CPU usage', [], false], 'memory' => ['Memory usage', [], false],
    'storage' => ['Array & pools', [], false], 'ups' => ['UPS (NUT / apcupsd)', [], false],
    'docker_summary' => ['Docker summary', [], false], 'vm_summary' => ['VM summary', [], false],
    'calendar' => ['Calendar (Radarr / Sonarr)', [], false], 'tautulli_recent' => ['Recently added (Tautulli)', ['key'], true],
    'radarr' => ['Radarr', ['key'], true], 'sonarr' => ['Sonarr', ['key'], true], 'lidarr' => ['Lidarr', ['key'], true],
    'readarr' => ['Readarr', ['key'], true], 'bazarr' => ['Bazarr', ['key'], true], 'prowlarr' => ['Prowlarr', ['key'], true],
    'overseerr' => ['Overseerr / Jellyseerr', ['key'], true], 'autobrr' => ['Autobrr', ['key'], true], 'wizarr' => ['Wizarr', ['key'], true],
    'qbittorrent' => ['qBittorrent', ['username', 'password'], true], 'sabnzbd' => ['SABnzbd', ['key'], true], 'qui' => ['qui (qBittorrent manager)', ['key'], true],
    'emby' => ['Emby', ['key'], true], 'jellyfin' => ['Jellyfin', ['key'], true], 'plex' => ['Plex', ['key'], true], 'tautulli' => ['Tautulli', ['key'], true],
    'adguard' => ['AdGuard Home', ['username', 'password'], true], 'pihole' => ['Pi-hole', ['key'], true], 'npm' => ['Nginx Proxy Manager', ['username', 'password'], true],
    'immich' => ['Immich', ['key'], true], 'peanut' => ['PeaNUT', ['key', 'username', 'password'], true],
    'customapi' => ['Custom API (JSON)', ['key'], true], 'link' => ['Link only', [], false],
  ];
}

/** Dashboard sections, in their default order. */
function und_section_ids() { return ['overview', 'alerts', 'favorites', 'media', 'arr', 'downloads', 'playing', 'requests', 'apps', 'docker', 'vms', 'health']; }

/** Sections added after the first release, and the section each goes after in a layout saved before it existed. */
function und_section_since() { return ['playing' => 'downloads', 'requests' => 'playing', 'health' => 'vms']; }

/** System metrics the overview can show, in their default order. */
function und_metric_ids() { return ['cpu', 'memory', 'storage', 'network', 'health', 'disks', 'uptime', 'ups', 'cores', 'swap', 'load', 'path', 'docker', 'vms']; }

/** The media widgets, in their default order. */
function und_media_ids() { return ['upcoming_movies', 'upcoming_episodes', 'recent_movies', 'recent_episodes']; }

function und_default_config() {
  $svc = function ($name, $type, $icon = '') { return ['id' => und_id(), 'name' => $name, 'type' => $type, 'icon' => $icon, 'href' => '', 'url' => '', 'fields' => [], 'options' => new stdClass()]; };
  $sections = [];
  foreach (und_section_ids() as $id) $sections[] = ['id' => $id, 'visible' => $id !== 'favorites', 'width' => in_array($id, ['arr', 'downloads', 'playing', 'requests'], true) ? 'half' : 'full', 'collapsed' => false];
  $metrics = [];
  foreach (und_metric_ids() as $id) $metrics[] = ['id' => $id, 'visible' => !in_array($id, ['cores', 'swap', 'load', 'path', 'docker', 'vms'], true)];
  return [
    'schema' => UND_SCHEMA,
    'general' => ['title' => 'UnDash', 'refresh' => 2, 'refresh_services' => 15, 'background_poll' => 'slow', 'disk_path' => '/mnt/user', 'search' => 'duckduckgo',
                  'show_search' => true, 'show_date' => true, 'os_check_hours' => 6, 'ups_name' => 'ups'],
    'appearance' => ['theme' => 'default', 'mode' => 'dark', 'accent' => '', 'density' => 'comfortable', 'background' => '', 'blur' => 4, 'blur_target' => 'wallpaper', 'brightness' => 75, 'safe_x' => -1, 'safe_y' => -1, 'css_enabled' => true],
    'media' => ['radarr' => '', 'sonarr' => '', 'count' => 15, 'days_ahead' => 30, 'widgets' => array_map(function ($id) { return ['id' => $id, 'visible' => true, 'count' => 15]; }, und_media_ids())],
    'downloads' => ['items' => 4],
    'apps' => ['columns' => 0, 'links' => false, 'headers' => false],
    'dashboard' => ['sections' => $sections, 'metrics' => $metrics],
    'docker' => ['layout' => 'grid', 'view' => 'compact', 'filter' => 'running', 'selected' => [], 'columns' => 0, 'sort' => 'name', 'show' => ['cpu', 'memory', 'update'], 'hide_stopped' => false, 'items' => new stdClass()],
    'vms' => ['layout' => 'list', 'show' => ['status', 'cpus', 'memory'], 'items' => new stdClass()],
    'groups' => [
      ['id' => und_id(), 'name' => 'Apps', 'columns' => 4, 'header' => true, 'services' => []],
    ],
  ];
}

function und_id() { return bin2hex(random_bytes(4)); }

/* ---------- location ---------- */

/** undash.cfg on the flash drive: CONFIG_DIR, PORT, BIND, STANDALONE. */
function und_loc() {
  if (und_api_mode()) return ['CONFIG_DIR' => UND_DIR, 'PORT' => (string)((int)(getenv('UNDASH_PORT') ?: 9099)), 'BIND' => '0.0.0.0', 'STANDALONE' => 'yes'];
  $d = ['CONFIG_DIR' => '/mnt/user/appdata/undash', 'PORT' => '9099', 'BIND' => '0.0.0.0', 'STANDALONE' => 'yes'];
  $v = @parse_ini_string(und_read_flash('undash.cfg'));
  $v = is_array($v) ? $v + $d : $d;
  if (!und_valid_cfg_dir($v['CONFIG_DIR'])) $v['CONFIG_DIR'] = $d['CONFIG_DIR'];
  $p = (int)$v['PORT']; $v['PORT'] = (string)($p >= 1024 && $p <= 65535 ? $p : 9099);
  if (!filter_var($v['BIND'], FILTER_VALIDATE_IP)) $v['BIND'] = '0.0.0.0';
  $v['STANDALONE'] = $v['STANDALONE'] === 'no' ? 'no' : 'yes';
  return $v;
}

/** The configuration directory must be an absolute path on a user share, a pool or the flash plugin folder: never anything else. */
function und_valid_cfg_dir($p) {
  $p = (string)$p;
  if (strpos($p, '..') !== false || strpos($p, "\0") !== false) return false;
  return preg_match('#^(/mnt/[A-Za-z0-9_.-]+(/[A-Za-z0-9_. -]+)*|' . preg_quote(UND_DIR, '#') . ')/?$#', $p) === 1;
}

function und_read_flash($name) { $s = @file_get_contents(UND_DIR . '/' . $name); return $s === false ? '' : $s; }

function und_save_loc(array $v) {
  if (und_api_mode()) return ['error' => 'In the Docker version the port and the /config folder are set in the container settings.'];
  $cur = und_loc();
  foreach (['CONFIG_DIR', 'PORT', 'BIND', 'STANDALONE'] as $k) if (isset($v[$k])) $cur[$k] = (string)$v[$k];
  if (!und_valid_cfg_dir($cur['CONFIG_DIR'])) return ['error' => 'The configuration directory must be a path under /mnt (for example /mnt/user/appdata/undash).'];
  $p = (int)$cur['PORT'];
  if ($p < 1024 || $p > 65535) return ['error' => 'The port must be between 1024 and 65535.'];
  if (!filter_var($cur['BIND'], FILTER_VALIDATE_IP)) return ['error' => 'The listen address must be an IP address (0.0.0.0 = all).'];
  $txt = '';
  foreach (['CONFIG_DIR', 'PORT', 'BIND', 'STANDALONE'] as $k) $txt .= $k . '="' . str_replace('"', '', $cur[$k]) . "\"\n";
  @mkdir(UND_DIR, 0755, true);
  if (@file_put_contents(UND_DIR . '/undash.cfg', $txt) === false) return ['error' => 'Could not write ' . UND_DIR . '/undash.cfg.'];
  return ['ok' => true, 'loc' => $cur];
}

/**
 * The directory UnDash writes to: the configured directory when it can be used, else the flash folder.
 * A missing directory is created only when its parent exists (so a stopped array never creates a stray folder on the RAM disk).
 */
function und_store_dir() {
  static $cache = [];
  $dir = und_path(rtrim(und_loc()['CONFIG_DIR'], '/'));
  if (isset($cache[$dir]) && is_dir($cache[$dir])) return $cache[$dir];
  $ok = false;
  if ($dir === UND_DIR) $ok = true;
  elseif (is_dir($dir)) $ok = is_writable($dir);
  elseif (is_dir(dirname($dir)) && is_writable(dirname($dir)) && und_array_mounted($dir)) $ok = @mkdir($dir, 0755, true);
  if ($ok) und_migrate_into($dir);
  return $cache[$dir] = $ok ? $dir : UND_DIR;
}

/** /mnt/user (and pools) only exist while the array is started; before that /mnt is a plain RAM-disk folder. */
function und_array_mounted($dir) {
  if (isset($GLOBALS['UND_FS'])) return true;
  if (strpos($dir, '/mnt/') !== 0) return true;
  $top = '/mnt/' . explode('/', substr($dir, 5))[0];
  foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $l) { $c = explode(' ', $l); if (($c[1] ?? '') === $top) return true; }
  return false;
}

/** First use of a configuration directory: copy the flash files into it, never over existing ones. */
function und_migrate_into($dir) {
  if ($dir === UND_DIR) return;
  // files of older versions only where there are no new ones yet (they are converted on first use)
  $old = is_file("$dir/config.yaml") || is_file(UND_DIR . '/config.yaml') ? [] : ['config.json', 'secrets.json', 'custom.css', 'ignores.json'];
  foreach (array_merge(['config.yaml', '.env'], $old) as $f) {
    if (!is_file("$dir/$f") && is_file(UND_DIR . "/$f")) { @copy(UND_DIR . "/$f", "$dir/$f"); if ($f === 'secrets.json' || $f === '.env') @chmod("$dir/$f", 0600); }
  }
}

function und_file($name) {
  $d = und_store_dir();
  return is_file("$d/$name") || $d === UND_DIR ? "$d/$name" : UND_DIR . "/$name";
}

/** Write one file to the configuration directory and mirror it to the flash drive. */
function und_store_write($name, $content, $private = false) {
  $dirs = array_unique([und_store_dir(), UND_DIR]);
  $ok = false;
  foreach ($dirs as $i => $d) {
    @mkdir($d, 0755, true);
    $f = "$d/$name";
    if (is_file($f) && @file_get_contents($f) === $content) { if ($i === 0) $ok = true; continue; }
    $tmp = $f . '.tmp' . getmypid();
    $w = @file_put_contents($tmp, $content) !== false;
    if ($w && $private) @chmod($tmp, 0600);
    $w = $w && @rename($tmp, $f);
    if (!$w) @unlink($tmp);
    if ($i === 0) $ok = $w;
  }
  return $ok;
}

/* ---------- backups ---------- */

function und_backup_dir() { return und_data_dir() . '/backups'; }

/** Snapshot both settings files before they change. Returns the backup id. */
function und_backup($reason) {
  $dir = und_backup_dir();
  @mkdir($dir, 0700, true);
  $id = date('Ymd-His') . '-' . substr(und_id(), 0, 4);
  $y = (string)@file_get_contents(und_file('config.yaml')); $e = (string)@file_get_contents(und_file('.env'));
  if ($y === '' && $e === '') return null;
  $snap = ['undash_backup' => 2, 'schema' => UND_SCHEMA, 'created' => time(), 'reason' => und_clip((string)$reason, 80), 'yaml' => $y, 'env' => $e];
  @file_put_contents("$dir/$id.json", json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  @chmod("$dir/$id.json", 0600);
  $all = glob("$dir/*.json") ?: [];
  sort($all);
  foreach (array_slice($all, 0, max(0, count($all) - 15)) as $old) @unlink($old);
  return $id;
}

function und_backups() {
  $out = [];
  foreach (array_reverse(glob(und_backup_dir() . '/*.json') ?: []) as $f) {
    $j = und_read_json($f, []);
    $out[] = ['id' => basename($f, '.json'), 'created' => (int)($j['created'] ?? filemtime($f)), 'reason' => (string)($j['reason'] ?? ''), 'schema' => (int)($j['schema'] ?? 0)];
  }
  return $out;
}

function und_restore($id) {
  if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{4}$/', (string)$id)) return ['error' => 'Unknown backup.'];
  $f = und_backup_dir() . "/$id.json";
  $j = und_read_json($f, null);
  if (!is_array($j) || empty($j['undash_backup'])) return ['error' => 'That backup cannot be read.'];
  und_backup('before restoring ' . $id);
  if ((int)$j['undash_backup'] >= 2) {
    // both files exactly as they were
    if (is_string($j['yaml'] ?? null) && $j['yaml'] !== '') und_store_write('config.yaml', $j['yaml']);
    if (is_string($j['env'] ?? null)) und_store_write('.env', $j['env'], true);
    und_store_reset();
    return ['ok' => true];
  }
  // a backup from before config.yaml: convert it like the old files
  $conv = und_store_convert(und_normalize(und_migrate(is_array($j['config'] ?? null) ? $j['config'] : und_default_config())), (array)($j['secrets']['vars'] ?? []), (array)($j['secrets']['svc'] ?? []));
  und_save_config($conv['config'], false);
  $env = und_env(); foreach ($conv['env'] as $k => $v) $env[$k] = $v; und_env_write($env);
  und_save_css((string)($j['css'] ?? ''), false);
  return ['ok' => true];
}

/* ---------- load / save ---------- */

function und_load_config() {
  $c = und_doc();
  if (!isset($c['groups'])) return und_normalize(und_default_config());
  $c['schema'] = (int)($c['version'] ?? UND_SCHEMA);
  return und_normalize(und_migrate($c));
}

/** Bring an older schema up to date. Version 1 (2026.10.29) had general.theme, refresh_system and no dashboard sections. */
function und_migrate(array $c) {
  $v = (int)($c['schema'] ?? 1);
  if ($v < 2) {
    $g = isset($c['general']) && is_array($c['general']) ? $c['general'] : [];
    $theme = (string)($g['theme'] ?? 'dark');
    $c['appearance'] = ['theme' => $theme === 'light' ? 'light' : 'default', 'background' => (string)($g['background'] ?? ''), 'blur' => $g['blur'] ?? 4, 'brightness' => $g['brightness'] ?? 75];
    if (isset($g['refresh_system'])) $g['refresh'] = 2;     // the new default; version 1 only allowed 5 s or more
    if (isset($g['show_resources']) && !$g['show_resources']) $c['dashboard']['sections'] = [['id' => 'metrics', 'visible' => false]];
    unset($g['theme'], $g['background'], $g['blur'], $g['brightness'], $g['refresh_system'], $g['show_resources']);
    $c['general'] = $g;
    // Glances-style system cards are now the built-in System section: drop them, and a group they leave empty
    foreach ((array)($c['groups'] ?? []) as $i => $gr) {
      if (!is_array($gr)) continue;
      $keep = array_values(array_filter((array)($gr['services'] ?? []), function ($s) { return !in_array($s['type'] ?? '', ['system_info', 'cpu', 'memory'], true); }));
      if (!$keep && count((array)($gr['services'] ?? []))) unset($c['groups'][$i]); else $c['groups'][$i]['services'] = $keep;
    }
    $c['groups'] = array_values((array)($c['groups'] ?? []));
    $c['schema'] = 2;
  }
  if ($v < 3) {
    // version 3 (2026.10.30): System metrics joined the overview, Storage moved into it, Services became Apps,
    // and Media, Arr Stack and Download Clients are sections of their own. Visibility and order of the kept sections survive.
    $old = isset($c['dashboard']['sections']) && is_array($c['dashboard']['sections']) ? $c['dashboard']['sections'] : [];
    $new = [];
    foreach ($old as $sec) {
      $id = $sec['id'] ?? '';
      if ($id === 'services') { $sec['id'] = 'apps'; $id = 'apps'; }
      if ($id === 'metrics' || $id === 'storage') continue;
      $sec['width'] = 'full';
      $new[] = $sec;
      if ($id === 'favorites') foreach (['media', 'arr', 'downloads'] as $n) $new[] = ['id' => $n, 'visible' => true, 'width' => $n === 'media' ? 'full' : 'half', 'collapsed' => false];
    }
    $c['dashboard']['sections'] = $new;
    $c['dashboard']['metrics'] = [];
    if (($c['appearance']['density'] ?? '') === 'comfortable') $c['appearance']['density'] = 'comfortable';
    $c['schema'] = 3;
  }
  if ($v < 4) {
    // version 4 (2026.10.31): Apps sit right below Arr Stack and Downloads, containers show as a compact grid of the
    // running ones, and shortcut links are no longer drawn (they stay in the settings; Apps > Show shortcuts brings them back)
    $secs = isset($c['dashboard']['sections']) && is_array($c['dashboard']['sections']) ? $c['dashboard']['sections'] : [];
    $apps = null; $rest = [];
    foreach ($secs as $sec) { if (($sec['id'] ?? '') === 'apps') $apps = $sec; else $rest[] = $sec; }
    if ($apps) {
      $out = []; $placed = false;
      foreach ($rest as $sec) { $out[] = $sec; if (($sec['id'] ?? '') === 'downloads') { $out[] = $apps; $placed = true; } }
      if (!$placed) $out[] = $apps;
      $c['dashboard']['sections'] = $out;
    }
    if (isset($c['docker']) && is_array($c['docker'])) { if (($c['docker']['layout'] ?? 'list') === 'list') $c['docker']['layout'] = 'grid'; $c['docker']['filter'] = 'running'; $c['docker']['show'] = ['cpu', 'memory', 'update']; }
    $c['apps'] = ['columns' => 0, 'links' => false, 'headers' => false];
    $c['schema'] = 4;
  }
  return $c;
}

/** Colour themes (palettes in assets/app.js). AMOLED and Fluent Dark are dark only. */
function und_theme_ids() { return ['default', 'amoled', 'minimal', 'zinc', 'wave', 'dragon', 'catppuccin', 'nord', 'tokyo', 'fluent']; }

function und_hex($c, $d) { return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c) ? strtolower($c) : $d; }

/** Clamp and fill a config that came from disk, the browser or an import. Unknown keys are dropped. */
function und_normalize(array $c) {
  $d = und_default_config();
  $g = (isset($c['general']) && is_array($c['general']) ? $c['general'] : []) + $d['general'];
  $g = array_intersect_key($g, $d['general']);
  $g['title'] = und_clip(trim((string)$g['title']), 60) ?: 'UnDash';
  foreach (['refresh' => [1, 300], 'refresh_services' => [5, 3600], 'os_check_hours' => [1, 168]] as $k => $r) $g[$k] = max($r[0], min($r[1], (int)$g[$k]));
  foreach (['show_search', 'show_date'] as $k) $g[$k] = !empty($g[$k]);
  $g['background_poll'] = in_array($g['background_poll'], ['slow', 'pause', 'normal'], true) ? $g['background_poll'] : 'slow';
  $g['disk_path'] = preg_match('#^/mnt/[A-Za-z0-9_./ -]*$#', (string)$g['disk_path']) && strpos($g['disk_path'], '..') === false ? (string)$g['disk_path'] : '/mnt/user';
  $g['search'] = in_array($g['search'], ['duckduckgo', 'google', 'bing', 'startpage', 'brave', 'none'], true) ? $g['search'] : 'duckduckgo';
  $g['ups_name'] = preg_match('/^[A-Za-z0-9_.@:-]{1,64}$/', (string)$g['ups_name']) ? (string)$g['ups_name'] : 'ups';

  $a = (isset($c['appearance']) && is_array($c['appearance']) ? $c['appearance'] : []) + $d['appearance'];
  $a = array_intersect_key($a, $d['appearance']);
  // the old "light" theme is the default palette in light mode
  if ($a['theme'] === 'light') { $a['theme'] = 'default'; if (!isset($c['appearance']['mode'])) $a['mode'] = 'light'; }
  $a['theme'] = in_array($a['theme'], und_theme_ids(), true) ? $a['theme'] : 'default';
  $a['mode'] = in_array($a['mode'], ['dark', 'light', 'system'], true) ? $a['mode'] : 'dark';
  $a['accent'] = $a['accent'] === '' ? '' : und_hex($a['accent'], '');   // empty = the theme's own accent
  $a['density'] = in_array($a['density'], ['compact', 'comfortable', 'expanded'], true) ? $a['density'] : 'comfortable';
  $a['background'] = preg_match('#^(https?://|/)[^"\'<>\s()\\\\]*$#', (string)$a['background']) ? (string)$a['background'] : '';
  $a['blur'] = max(0, min(40, (int)$a['blur'])); $a['brightness'] = max(0, min(100, (int)$a['brightness']));
  // the margin around the dashboard in px; -1 = automatic
  foreach (['safe_x', 'safe_y'] as $k) $a[$k] = (int)$a[$k] < 0 ? -1 : min(200, (int)$a[$k]);
  $a['blur_target'] = in_array($a['blur_target'], ['wallpaper', 'cards'], true) ? $a['blur_target'] : 'wallpaper';   // blur the whole picture, or only what is behind the cards
  $a['css_enabled'] = !empty($a['css_enabled']);

  // sections and metrics: keep the user's order, drop unknown ids, append new ones at the end
  $db = isset($c['dashboard']) && is_array($c['dashboard']) ? $c['dashboard'] : [];
  $order = function ($list, $known, $defaults, $fields) {
    $out = []; $seen = [];
    foreach ((array)$list as $x) {
      if (!is_array($x) || !in_array($x['id'] ?? '', $known, true) || isset($seen[$x['id']])) continue;
      $seen[$x['id']] = 1;
      $def = null; foreach ($defaults as $dd) if ($dd['id'] === $x['id']) $def = $dd;
      $row = ['id' => $x['id']];
      foreach ($fields as $f => $type) {
        $v = array_key_exists($f, $x) ? $x[$f] : $def[$f];
        $row[$f] = $type === 'bool' ? !empty($v) : (in_array($v, $type, true) ? $v : $def[$f]);
      }
      $out[] = $row;
    }
    foreach ($defaults as $dd) if (!isset($seen[$dd['id']])) $out[] = $dd;
    return $out;
  };
  $sections = $order($db['sections'] ?? [], und_section_ids(), $d['dashboard']['sections'], ['visible' => 'bool', 'width' => ['full', 'half'], 'collapsed' => 'bool']);
  // a section added in a later version goes where it belongs, not at the bottom of an older layout
  $had = array_column(array_filter((array)($db['sections'] ?? []), 'is_array'), 'id');
  if ($had) foreach (und_section_since() as $new => $after) {
    if (in_array($new, $had, true)) continue;
    $ids = array_column($sections, 'id'); $i = array_search($new, $ids, true); $row = $sections[$i];
    array_splice($sections, $i, 1);
    $ids = array_column($sections, 'id'); $j = array_search($after, $ids, true);
    array_splice($sections, $j === false ? count($sections) : $j + 1, 0, [$row]);
  }
  $metrics = $order($db['metrics'] ?? [], und_metric_ids(), $d['dashboard']['metrics'], ['visible' => 'bool']);

  $items = function ($list, $extra = []) {
    $out = [];
    foreach ((array)$list as $name => $p) {
      if (!is_array($p) || !is_string($name) || $name === '' || strlen($name) > 200 || preg_match('/[\x00-\x1f]/', $name)) continue;
      $row = ['label' => und_clip(trim((string)($p['label'] ?? '')), 60), 'icon' => und_clip((string)($p['icon'] ?? ''), 300), 'description' => und_clip(trim((string)($p['description'] ?? '')), 200),
              'hidden' => !empty($p['hidden']), 'favorite' => !empty($p['favorite'])];
      if ($row['label'] === '' && $row['icon'] === '' && $row['description'] === '' && !$row['hidden'] && !$row['favorite']) continue;
      $out[$name] = $row;
    }
    return $out ? $out : new stdClass();
  };
  $md = (isset($c['media']) && is_array($c['media']) ? $c['media'] : []) + $d['media'];
  $media = ['radarr' => preg_match('/^[a-f0-9]{8}$/', (string)$md['radarr']) ? $md['radarr'] : '', 'sonarr' => preg_match('/^[a-f0-9]{8}$/', (string)$md['sonarr']) ? $md['sonarr'] : '',
            'count' => max(4, min(40, (int)$md['count'])), 'days_ahead' => max(7, min(120, (int)$md['days_ahead'])),
            'widgets' => $order($md['widgets'] ?? [], und_media_ids(), $d['media']['widgets'], ['visible' => 'bool'])];
  // the four columns always come in this order; each has its own length (older configurations used the shared count)
  $raw = []; foreach ((array)($md['widgets'] ?? []) as $x) if (is_array($x) && is_string($x['id'] ?? null)) $raw[$x['id']] = $x;
  $byId = []; foreach ($media['widgets'] as $w) $byId[$w['id']] = $w;
  $media['widgets'] = array_map(function ($id) use ($byId, $raw, $media) {
    return ['id' => $id, 'visible' => $byId[$id]['visible'], 'count' => max(1, min(50, (int)($raw[$id]['count'] ?? $media['count'])))];
  }, und_media_ids());
  $dl = (isset($c['downloads']) && is_array($c['downloads']) ? $c['downloads'] : []) + $d['downloads'];
  $downloads = ['items' => max(0, min(20, (int)$dl['items']))];
  $dk = (isset($c['docker']) && is_array($c['docker']) ? $c['docker'] : []) + $d['docker'];
  $sel = [];
  foreach ((array)$dk['selected'] as $n) if (is_string($n) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $n) && count($sel) < 500) $sel[$n] = 1;
  $docker = ['layout' => in_array($dk['layout'], ['grid', 'list', 'cards'], true) ? $dk['layout'] : 'grid', 'view' => $dk['view'] === 'expanded' ? 'expanded' : 'compact',
             'filter' => in_array($dk['filter'], ['running', 'all', 'selected'], true) ? $dk['filter'] : 'running', 'selected' => array_keys($sel), 'columns' => max(0, min(4, (int)$dk['columns'])),
             'sort' => in_array($dk['sort'], ['name', 'status', 'cpu', 'memory'], true) ? $dk['sort'] : 'name',
             'show' => array_values(array_intersect((array)$dk['show'], ['status', 'cpu', 'memory', 'uptime', 'image', 'update', 'ports', 'restarts', 'network'])),
             'hide_stopped' => !empty($dk['hide_stopped']), 'items' => $items($dk['items'] ?? [])];
  $ap = (isset($c['apps']) && is_array($c['apps']) ? $c['apps'] : []) + $d['apps'];
  $apps = ['columns' => max(0, min(6, (int)$ap['columns'])), 'links' => !empty($ap['links']), 'headers' => !empty($ap['headers'])];
  $vm = (isset($c['vms']) && is_array($c['vms']) ? $c['vms'] : []) + $d['vms'];
  $vms = ['layout' => $vm['layout'] === 'cards' ? 'cards' : 'list', 'show' => array_values(array_intersect((array)$vm['show'], ['status', 'cpus', 'memory', 'os', 'autostart'])), 'items' => $items($vm['items'] ?? [])];

  $types = und_types(); $groups = [];
  foreach ((array)($c['groups'] ?? []) as $gr) {
    if (!is_array($gr)) continue;
    $out = ['id' => preg_match('/^[a-f0-9]{8}$/', (string)($gr['id'] ?? '')) ? $gr['id'] : und_id(), 'name' => und_clip(trim((string)($gr['name'] ?? 'Group')), 60),
            'columns' => max(1, min(8, (int)($gr['columns'] ?? 4))), 'header' => !isset($gr['header']) || !empty($gr['header']), 'services' => []];
    foreach ((array)($gr['services'] ?? []) as $s) {
      if (!is_array($s) || !isset($types[$s['type'] ?? ''])) continue;
      $opt = isset($s['options']) && is_array($s['options']) ? $s['options'] : [];
      $out['services'][] = ['id' => preg_match('/^[a-f0-9]{8}$/', (string)($s['id'] ?? '')) ? $s['id'] : und_id(), 'name' => und_clip(trim((string)($s['name'] ?? '')), 60) ?: $types[$s['type']][0],
        'type' => $s['type'], 'icon' => und_clip((string)($s['icon'] ?? ''), 300), 'href' => und_clip((string)($s['href'] ?? ''), 500), 'url' => und_clip((string)($s['url'] ?? ''), 500),
        'description' => und_clip(trim((string)($s['description'] ?? '')), 200), 'hidden' => !empty($s['hidden']), 'favorite' => !empty($s['favorite']), 'span' => (int)($s['span'] ?? 1) === 2 ? 2 : 1,
        'fields' => array_values(array_filter(array_map('strval', (array)($s['fields'] ?? [])), function ($f) { return preg_match('/^[A-Za-z0-9_]{1,40}$/', $f); })),
        'options' => $opt ? $opt : new stdClass()]
        // credentials only ever as a reference to .env; a value typed into the file by hand is not kept here
        + array_map(function ($f) use ($s) { $r = und_ref_name($s[$f] ?? ''); return $r ? '{{' . $r . '}}' : ''; }, array_combine(array_keys(UND_CRED_FIELDS), array_keys(UND_CRED_FIELDS)));
    }
    $groups[] = $out;
  }
  return ['schema' => UND_SCHEMA, 'general' => $g, 'appearance' => $a, 'dashboard' => ['sections' => $sections, 'metrics' => $metrics], 'media' => $media, 'downloads' => $downloads, 'apps' => $apps,
          'docker' => $docker, 'vms' => $vms, 'groups' => $groups];
}

/** Serialises read-change-write of config.json (two quick dashboard clicks must not lose one). Released when the request ends. */
function und_config_lock() {
  // reentrant within one request: taking it twice would wait for ourselves
  static $fp = null;
  if (is_resource($fp)) return $fp;
  @mkdir(und_tmp('locks'), 0755, true);
  $fp = @fopen(und_tmp('locks/config.lock'), 'c');
  if ($fp) flock($fp, LOCK_EX);
  return $fp;
}

function und_save_config(array $c, $backup = true) {
  if ($backup) und_backup('settings saved');
  $n = und_normalize($c); unset($n['schema']);
  return und_doc_write(['version' => UND_SCHEMA] + json_decode(json_encode($n), true));
}

/** Every widget id in a config (used to drop credentials of widgets that were removed). */
function und_service_ids(array $cfg) { $ids = []; foreach ($cfg['groups'] as $g) foreach ($g['services'] as $s) $ids[] = $s['id']; return $ids; }

/* ---------- custom CSS ---------- */

/**
 * Custom CSS is only ever CSS: it is inserted with textContent inside the app's own <style>, nested under .und so it cannot
 * restyle Unraid's page. Things that could load or run something else are removed: @import, url() to other sites,
 * legacy expression() / behavior / -moz-binding, javascript: and a closing </style>.
 */
function und_sanitize_css($css) {
  $css = str_replace("\0", '', (string)$css);
  if (strlen($css) > 102400) $css = substr($css, 0, 102400);
  $css = preg_replace('#</\s*style#i', '', $css);
  $css = preg_replace('/@import[^;]*;?/i', '/* @import removed */', $css);
  $css = preg_replace('/expression\s*\(|behavior\s*:|-moz-binding|javascript\s*:/i', '/* removed */', $css);
  $css = preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', function ($m) {
    return preg_match('#^(data:image/|/|\./|[A-Za-z0-9_.-]+\.(png|jpe?g|gif|svg|webp|woff2?)$|https://)#i', trim($m[2])) && !preg_match('/^javascript:/i', trim($m[2])) ? $m[0] : 'none';
  }, $css);
  return $css;
}

function und_load_css() { return und_sanitize_css((string)(und_doc()['custom_css'] ?? '')); }

function und_save_css($css, $backup = true) {
  if ($backup) und_backup('custom CSS saved');
  $css = und_sanitize_css($css);
  return und_doc_write(['custom_css' => trim($css) === '' ? null : $css]);
}

/* ---------- secrets ---------- */

/**
 * The secrets as the rest of UnDash sees them: vars = every .env value (except UnDash's own entries), unraid = the API
 * address (config.yaml) and key (.env). Credentials of apps are vars too, referenced from config.yaml.
 */
function und_secrets() {
  if (isset($GLOBALS['UND_TEST_SECRETS'])) { $s = $GLOBALS['UND_TEST_SECRETS']; return ['vars' => (array)($s['vars'] ?? []), 'svc' => (array)($s['svc'] ?? []), 'unraid' => (array)($s['unraid'] ?? [])]; }
  $env = und_env();
  $vars = array_diff_key($env, array_flip(UND_ENV_RESERVED));
  return ['vars' => $vars, 'svc' => [], 'unraid' => ['url' => (string)(und_doc()['unraid']['url'] ?? ''), 'key' => (string)($env['UNRAID_API_KEY'] ?? '')]];
}
/** vars replace the .env values (UnDash's own entries are kept); unraid sets the address and key; svc (old per-widget credentials) become references. */
function und_save_secrets(array $s) {
  $env = und_env();
  $keep = array_intersect_key($env, array_flip(UND_ENV_RESERVED));
  $new = isset($s['vars']) && is_array($s['vars']) ? array_change_key_case($s['vars'], CASE_UPPER) : array_diff_key($env, $keep);
  $new = array_diff_key($new, array_flip(UND_ENV_RESERVED)) + $keep;
  if (isset($s['unraid']) && is_array($s['unraid'])) {
    if (array_key_exists('key', $s['unraid'])) { if ((string)$s['unraid']['key'] === '') unset($new['UNRAID_API_KEY']); else $new['UNRAID_API_KEY'] = (string)$s['unraid']['key']; }
    if (array_key_exists('url', $s['unraid'])) und_doc_write(['unraid' => (string)$s['unraid']['url'] === '' ? null : ['url' => (string)$s['unraid']['url']]]);
  }
  if (!empty($s['svc']) && is_array($s['svc'])) {
    // credentials from an old export or backup: into .env under readable names, referenced from the config
    $cfg = und_load_config();
    foreach ($cfg['groups'] as &$g) foreach ($g['services'] as &$sv) foreach (array_keys(UND_CRED_FIELDS) as $f) {
      $v = (string)($s['svc'][$sv['id']][$f] ?? '');
      if ($v === '') continue;
      if ($r = und_ref_name($v)) { $sv[$f] = '{{' . $r . '}}'; continue; }
      $name = und_ref_name($sv[$f] ?? '') ?: und_cred_name($sv['name'], $f, $new + und_cfg_refs($cfg));
      $new[$name] = $v; $sv[$f] = '{{' . $name . '}}';
    }
    unset($g, $sv);
    und_save_config($cfg, false);
  }
  return und_env_write($new);
}

function und_var_is_secret($name) { return preg_match('/KEY|TOKEN|PASS|SECRET|AUTH|COOKIE|SESSION|CREDENTIAL/i', $name) === 1; }

/** Replace {{NAME}} with variable values. UNRAID_IP is built in. Unknown names are left as they are. */
function und_resolve($s, array $vars) {
  if (!und_has($s, '{{')) return $s;
  return preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
    $n = strtoupper($m[1]);
    if (isset($vars[$n])) return $vars[$n];
    if ($n === 'UNRAID_IP') return und_server_ip();
    return $m[0];
  }, (string)$s);
}

/** A service with every {{VAR}} resolved and its credentials attached. Server side only. */
function und_service_full(array $cfg, $id) {
  $sec = und_secrets();
  foreach ($cfg['groups'] as $g) foreach ($g['services'] as $s) {
    if ($s['id'] !== $id) continue;
    $c = $sec['svc'][$id] ?? [];
    // credentials: a reference in config.yaml ({{RADARR_API_KEY}}) to a value in .env
    foreach (['key', 'password', 'username'] as $f) { $v = (string)($s[$f] ?? '') !== '' ? (string)$s[$f] : (string)($c[$f] ?? ''); $s[$f] = und_resolve($v, $sec['vars']); if (und_ref_name($s[$f])) $s[$f] = ''; }
    foreach (['url', 'href'] as $f) $s[$f] = und_resolve($s[$f], $sec['vars']);
    $s['options'] = json_decode(und_resolve(json_encode($s['options']), array_map(function ($v) { return trim(json_encode((string)$v), '"'); }, $sec['vars'])), true) ?: [];
    $s['_group'] = $g['name'];
    return $s;
  }
  return null;
}

/**
 * Where a configured app shows up on the dashboard, so nothing appears twice:
 *   arr        Arr Stack section        downloads  Download Clients section
 *   media      covered by the Media section (calendars, "recently added" lists)
 *   overview   covered by the server overview (system, UPS, Docker / VM summaries)
 *   apps       everything else (Plex, Immich, AdGuard, links ...)
 */
function und_zone(array $s) {
  $t = (string)($s['type'] ?? '');
  if (in_array($t, und_arr_types(), true)) return 'arr';
  if (in_array($t, und_dl_types(), true)) return 'downloads';
  if ($t === 'calendar' || $t === 'tautulli_recent' || ($t === 'customapi' && und_has((string)($s['url'] ?? ''), 'get_recently_added'))) return 'media';
  if (in_array($t, ['system_info', 'cpu', 'memory', 'storage', 'ups', 'peanut', 'docker_summary', 'vm_summary'], true)) return 'overview';
  return 'apps';
}

/** The layout sent to the browser: hrefs resolved (they are links the user clicks anyway), nothing secret. */
function und_layout_view(array $cfg) {
  $vars = und_secrets()['vars'];
  foreach ($cfg['groups'] as &$g) foreach ($g['services'] as &$s) {
    $s['zone'] = und_zone($s);
    // no link of its own: the app's address, when it is a plain one (no login or token in it)
    $src = trim($s['href']) !== '' ? $s['href'] : (preg_match('#^https?://[^/@?\s]+(/[^?@\s]*)?$#i', trim($s['url'])) ? trim($s['url']) : '');
    $h = und_resolve($src, $vars);
    // a link that would carry a secret variable is not resolved for the browser
    if (preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $src, $m)) foreach ($m[1] as $n) if (und_var_is_secret($n)) $h = '';
    $s['href_resolved'] = preg_match('#^(https?://|/)#i', $h) ? $h : '';
  }
  return $cfg;
}

/* ---------- validation ---------- */

/** Problems a user can fix: widgets without a URL, unknown variables, calendar sources that no longer exist. */
function und_validate(array $cfg) {
  $issues = []; $vars = und_secrets()['vars']; $types = und_types(); $ids = array_flip(und_service_ids($cfg));
  foreach ($cfg['groups'] as $g) foreach ($g['services'] as $s) {
    $where = '"' . $s['name'] . '" in "' . $g['name'] . '"';
    if (!empty($types[$s['type']][2]) && trim($s['url']) === '') $issues[] = ['level' => 'warn', 'msg' => "$where has no address."];
    $kr = und_ref_name($s['key'] ?? '');
    if (in_array('key', $types[$s['type']][1] ?? [], true) && $s['type'] !== 'peanut' && (!$kr || (string)($vars[$kr] ?? '') === '')) $issues[] = ['level' => 'warn', 'msg' => "$where has no API key yet (Settings > Apps)."];
    foreach (['url', 'href'] as $f) if (preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $s[$f], $m)) foreach ($m[1] as $n) {
      $n = strtoupper($n);
      if (!isset($vars[$n]) && $n !== 'UNRAID_IP') $issues[] = ['level' => 'bad', 'msg' => "$where uses {{" . $n . '}}, which is not defined.'];
      elseif (isset($vars[$n]) && $vars[$n] === '') $issues[] = ['level' => 'warn', 'msg' => "$where uses {{" . $n . '}}, which is empty.'];
    }
    if ($s['type'] === 'calendar') foreach ((array)(((array)$s['options'])['integrations'] ?? []) as $in) if (!isset($ids[$in['service'] ?? ''])) $issues[] = ['level' => 'warn', 'msg' => "$where refers to a Radarr/Sonarr widget that was removed."];
  }
  if (und_store_dir() !== und_path(rtrim(und_loc()['CONFIG_DIR'], '/'))) $issues[] = ['level' => 'warn', 'msg' => 'The configuration directory ' . und_loc()['CONFIG_DIR'] . ' is not available (is the array started?). Settings are read from and saved to the flash drive until it is.'];
  return $issues;
}
