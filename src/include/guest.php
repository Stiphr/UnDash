<?php
/*
 * UnDash - the guest view: a private link that shows part of the dashboard and can change nothing.
 *
 * Everything a guest reaches is under /guest/ (standalone/router.php) and never touches api.php, so a new action there is
 * not open to guests by accident. A guest has a session of their own, in a cookie only /guest/ receives, so opening the
 * link does not sign the owner out. The token is in .env; changing it or turning the link off ends every guest session.
 * What leaves here is built from short lists of what may be shown: no addresses, keys, container names, alerts or settings.
 */

const UND_GUEST_ENV = 'UNDASH_GUEST_TOKEN';
const UND_GUEST_SECTIONS = ['overview', 'media', 'playing', 'apps'];
const UND_GUEST_TILES = ['cpu', 'memory', 'storage', 'network', 'health', 'disks', 'uptime', 'ups', 'cores', 'swap'];
const UND_GUEST_PRIVATE = ['gluetun', 'homeassistant', 'customapi'];       // not offered to guests until the owner ticks them
const UND_GUEST_STREAM = ['title', 'sub', 'user', 'state', 'pos', 'dur', 'method', 'quality', 'hw'];

function und_guest_token() { return (string)(und_env()[UND_GUEST_ENV] ?? ''); }
function und_guest_on() { return und_guest_token() !== ''; }
function und_guest_token_ok($t) { $k = und_guest_token(); return $k !== '' && is_string($t) && hash_equals($k, $t); }

/** What guests may see: sections on, and the ids of the apps ticked one by one. */
function und_guest_prefs() {
  $p = (array)(und_doc()['guest'] ?? []);
  $on = []; foreach (UND_GUEST_SECTIONS as $s) $on[$s] = !array_key_exists($s, (array)($p['sections'] ?? [])) || !empty($p['sections'][$s]);
  return ['sections' => $on, 'apps' => array_values(array_filter((array)($p['apps'] ?? []), function ($i) { return is_string($i) && preg_match('/^[a-f0-9]{8}$/', $i); }))];
}

/** A link a guest may follow: only a public address. A LAN address tells them nothing useful and shows how your network is laid out. */
function und_guest_href($u) {
  $u = (string)$u; $p = parse_url($u);
  $host = strtolower(trim((string)($p['host'] ?? ''), '[]'));
  if ($host === '' || isset($p['user']) || !preg_match('#^https?://#i', $u)) return '';
  if (filter_var($host, FILTER_VALIDATE_IP)) return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $u : '';
  return strpos($host, '.') === false || preg_match('/\.(local|lan|home|internal|intranet|localdomain|arpa|test)$/', $host) ? '' : $u;
}

/** The apps that can be offered: those with a card of their own in the Apps section. A shortcut needs somewhere a guest can go. */
function und_guest_apps(array $cfg) {
  $out = [];
  foreach (und_layout_view($cfg)['groups'] as $g) foreach ($g['services'] as $s) {
    if ($s['zone'] !== 'apps' || !empty($s['hidden']) || ($s['type'] === 'link' && und_guest_href($s['href_resolved']) === '')) continue;
    $out[] = ['id' => $s['id'], 'name' => $s['name'], 'type' => $s['type'], 'icon' => $s['icon']];
  }
  return $out;
}

/** For the owner's Settings page only. */
function und_guest_view(array $cfg) {
  return und_guest_prefs() + ['on' => und_guest_on(), 'path' => und_guest_on() ? '/guest/' . und_guest_token() : '', 'all' => und_guest_apps($cfg)];
}

function und_guest_token_set($t) {
  und_config_lock();
  $env = und_env(); unset($env[UND_GUEST_ENV]);
  if ($t !== '') $env[UND_GUEST_ENV] = $t;
  if (!und_env_write($env)) return false;
  und_guest_drop();
  return true;
}

/** Ends every guest session. */
function und_guest_drop() {
  foreach (glob(und_sess_dir() . '/*.json') ?: [] as $f) if ((und_read_json($f, [])['how'] ?? '') === 'guest') @unlink($f);
}

/** Turns the link on (the first time with every app except the ones that show private details) or off. */
function und_guest_turn($on, array $cfg) {
  if (!$on) return und_guest_token_set('') ? ['ok' => true] : ['error' => 'The .env file could not be written.'];
  if (und_guest_on()) return ['ok' => true];
  if (!isset(und_doc()['guest'])) {
    $apps = array_filter(und_guest_apps($cfg), function ($a) { return !in_array($a['type'], UND_GUEST_PRIVATE, true); });
    und_doc_write(['guest' => ['sections' => array_fill_keys(UND_GUEST_SECTIONS, true), 'apps' => array_column(array_values($apps), 'id')]]);
  }
  return und_guest_token_set(bin2hex(random_bytes(24))) ? ['ok' => true] : ['error' => 'The .env file could not be written.'];
}

function und_guest_save(array $v) {
  $sec = []; foreach (UND_GUEST_SECTIONS as $s) $sec[$s] = !empty($v['sections'][$s]);
  $apps = array_values(array_unique(array_filter((array)($v['apps'] ?? []), function ($i) { return is_string($i) && preg_match('/^[a-f0-9]{8}$/', $i); })));
  return und_doc_write(['guest' => ['sections' => $sec, 'apps' => array_slice($apps, 0, 200)]]) ? ['ok' => true] : ['error' => 'Could not save.'];
}

/* ---------- the guest's session ---------- */

function und_guest_cookie() { return und_cookie('undash_gid'); }

/** The guest session of this request, or null. It ends with the link it was opened from. */
function und_guest_sess() {
  $sid = (string)($_COOKIE[und_guest_cookie()] ?? '');
  if (!preg_match('/^[a-f0-9]{64}$/', $sid) || !und_guest_on()) return null;
  $s = und_read_json(und_sess_file($sid), null);
  if (!is_array($s) || ($s['how'] ?? '') !== 'guest' || (int)($s['exp'] ?? 0) < time() || !hash_equals((string)($s['link'] ?? ''), hash('sha256', und_guest_token()))) return null;
  if ((int)$s['exp'] - time() < UND_SESS_TTL - 600) { $s['exp'] = time() + UND_SESS_TTL; und_write_json(und_sess_file($sid), $s, true); }
  return $s;
}

function und_guest_sess_new() {
  $sid = bin2hex(random_bytes(32));
  $s = ['exp' => time() + UND_SESS_TTL, 'csrf' => bin2hex(random_bytes(24)), 'how' => 'guest', 'at' => time(), 'link' => hash('sha256', und_guest_token())];
  und_write_json(und_sess_file($sid), $s, true);
  setcookie(und_guest_cookie(), $sid, ['expires' => 0, 'path' => '/guest/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
  return $s;
}

/* ---------- what guests are sent ---------- */

/** The dashboard as a guest may see it. Layout and looks stay; addresses, logins, options and everything else are dropped. */
function und_guest_layout(array $cfg, array $p) {
  $on = array_keys(array_filter($p['sections']));
  $v = und_layout_view($cfg);
  $v['general'] = array_intersect_key($v['general'], array_flip(['title', 'logo', 'refresh', 'refresh_services', 'background_poll', 'show_date'])) + ['search' => '', 'search_in' => [], 'show_search' => false, 'os_check_hours' => 0, 'ups_name' => '', 'ups_watts' => 0, 'disk_path' => ''];
  $v['appearance']['wall_path'] = $v['appearance']['wall_path'] !== '' ? 'set' : '';
  foreach ($v['dashboard']['sections'] as &$s) $s['visible'] = !empty($s['visible']) && in_array($s['id'], $on, true);
  unset($s);
  foreach ($v['dashboard']['metrics'] as &$m) $m['visible'] = !empty($m['visible']) && in_array($m['id'], UND_GUEST_TILES, true);
  unset($m);
  $v['dashboard']['board']['on'] = true;                // the classic layout has controls that save to your settings
  $v['docker'] = ['layout' => 'grid', 'view' => 'compact', 'filter' => 'running', 'selected' => [], 'columns' => 0, 'sort' => 'name', 'show' => [], 'hide_stopped' => false, 'update_check' => false, 'items' => new stdClass()];
  $v['vms'] = ['layout' => 'list', 'show' => [], 'items' => new stdClass()];
  $groups = [];
  foreach ($v['groups'] as $g) {
    $list = [];
    foreach ($g['services'] as $s) {
      $href = und_guest_href($s['href_resolved']);
      if (!in_array('apps', $on, true) || $s['zone'] !== 'apps' || !empty($s['hidden']) || !in_array($s['id'], $p['apps'], true) || ($s['type'] === 'link' && $href === '')) continue;
      $list[] = ['href_resolved' => $href] + array_intersect_key($s, array_flip(['id', 'name', 'type', 'icon', 'description', 'span', 'zone'])) + ['hidden' => false, 'favorite' => false, 'href' => '', 'url' => '', 'fields' => [], 'options' => new stdClass()];
    }
    if ($list) $groups[] = ['id' => $g['id'], 'name' => $g['name'], 'columns' => $g['columns'], 'header' => $g['header'], 'section' => $g['section'], 'services' => $list];
  }
  $v['groups'] = $groups;
  return $v;
}

function und_guest_boot(array $cfg) {
  $id = und_server_identity();
  return ['version' => UND_VERSION, 'layout' => und_guest_layout($cfg, und_guest_prefs()), 'types' => [], 'css' => $cfg['appearance']['css_enabled'] ? und_load_css() : '',
          'server' => ['name' => (string)($id['name'] ?? '')], 'wall' => und_wall_custom_view(), 'standalone' => true, 'port' => 0, 'port_enabled' => false,
          'caps' => ['mode' => und_api_mode() ? 'api' : 'plugin', 'docker_stats' => false], 'api' => null, 'auth' => 'guest', 'guest' => true];
}

/** Numbers for the System tiles. Disk rows, the address, versions and update state stay on the server. */
function und_guest_system(array $s) {
  if (isset($s['error'])) return ['error' => 'The server is not answering.'];
  $a = (array)($s['array'] ?? []);
  $cpu = array_intersect_key((array)($s['cpu'] ?? []), ['pct' => 1, 'temp' => 1, 'threads' => 1, 'cores' => 1]);
  $net = (array)($s['net'] ?? []);
  $pools = array_map(function ($p) { return array_intersect_key((array)$p, ['size' => 1, 'free' => 1, 'temp' => 1]); }, array_values((array)($a['pools'] ?? [])));
  return ['name' => (string)($s['name'] ?? ''), 'now' => $s['now'] ?? time(), 'uptime' => $s['uptime'] ?? 0, 'hist' => $s['hist'] ?? [], 'cpu' => $cpu + ['pct' => null, 'temp' => null, 'cores' => []],
          'mem' => array_intersect_key((array)($s['mem'] ?? []), ['total' => 1, 'used' => 1, 'free' => 1, 'pct' => 1, 'swap_total' => 1, 'swap_used' => 1]),
          'net' => ['rx' => $net['rx'] ?? null, 'tx' => $net['tx'] ?? null, 'iface' => ''],
          'array' => array_intersect_key($a, array_flip(['state', 'size', 'free', 'bad', 'errors', 'disks', 'parity', 'hot', 'resync', 'resync_pct', 'resync_action'])) + ['pools' => $pools]];
}

/** What a guest may read of one stream: not where it comes from. */
function und_guest_streams(array $list) {
  return array_map(function ($x) { return array_intersect_key((array)$x, array_flip(UND_GUEST_STREAM)); }, $list);
}

/** An app card's numbers. An error says no more than that, because the app's own message can name its address. */
function und_guest_service(array $cfg, array $p, $id) {
  if (empty($p['sections']['apps']) || !in_array($id, $p['apps'], true)) return ['error' => 'Not available.'];
  $r = und_fetch_service($cfg, $id);
  if (isset($r['error'])) return ['error' => 'Not available right now.'];
  if (isset($r['streams'])) $r['streams'] = und_guest_streams((array)$r['streams']);
  if (isset($r['list'])) foreach ($r['list'] as &$row) if (isset($row['href'])) $row['href'] = und_guest_href($row['href']);
  unset($row);
  return $r + ['at' => time()];
}

/** Every call of the guest page. Whatever is not listed here does not exist for a guest. */
function und_guest_api($action, array $post) {
  $cfg = und_load_config(); $p = und_guest_prefs(); $on = $p['sections'];
  switch ($action) {
    case 'boot': return und_guest_boot($cfg);
    case 'live': {
      if (!$on['overview']) return ['at' => time()];
      if (und_api_mode()) @touch(und_tmp('viewer'));
      $r = ['at' => time(), 'system' => und_guest_system(und_system($cfg)), 'alerts' => [], 'ignores' => [], 'power' => ''];
      if (in_array('ups', explode(',', (string)($post['parts'] ?? '')), true)) $r['ups'] = und_ups($cfg['general']['ups_name'], $cfg['general']['ups_watts']);
      return $r;
    }
    case 'media': {
      $k = (string)($post['kind'] ?? '');
      if (!$on['media'] || !in_array($k, und_media_ids(), true)) return ['error' => 'Not available.'];
      $r = und_media($cfg, $k);
      if (isset($r['error'])) return ['error' => 'Not available right now.'];
      return ['items' => array_map(function ($it) { unset($it['href'], $it['list']); return $it; }, (array)($r['items'] ?? []))];      // the link goes to Radarr or Sonarr
    }
    case 'playing': {
      if (!$on['playing']) return ['error' => 'Not available.'];
      $r = und_playing($cfg);
      return ['streams' => und_guest_streams((array)($r['streams'] ?? [])), 'apps' => []] + (!empty($r['unconfigured']) ? ['unconfigured' => true] : []);
    }
    case 'service': return preg_match('/^[a-f0-9]{8}$/', (string)($post['id'] ?? '')) ? und_guest_service($cfg, $p, (string)$post['id']) : ['error' => 'Not available.'];
    case 'icon_slugs': { $l = json_decode((string)($post['slugs'] ?? '[]'), true); try { return und_icon_slugs(is_array($l) ? $l : []); } catch (Exception $e) { return ['error' => 'Not available.']; } }
  }
  return ['error' => 'Not available in the guest view.'];
}
