<?php
/*
 * UnDash - configuration.
 * config.json holds the layout (no secrets). secrets.json holds variables and per-service credentials; neither ever leaves
 * the server in clear text: stored secrets are sent to the browser as UND_KEEP and put back when the browser returns UND_KEEP.
 */

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
    'qbittorrent' => ['qBittorrent', ['username', 'password'], true], 'sabnzbd' => ['SABnzbd', ['key'], true],
    'emby' => ['Emby', ['key'], true], 'jellyfin' => ['Jellyfin', ['key'], true], 'plex' => ['Plex', ['key'], true], 'tautulli' => ['Tautulli', ['key'], true],
    'adguard' => ['AdGuard Home', ['username', 'password'], true], 'pihole' => ['Pi-hole', ['key'], true], 'npm' => ['Nginx Proxy Manager', ['username', 'password'], true],
    'immich' => ['Immich', ['key'], true], 'peanut' => ['PeaNUT', ['key', 'username', 'password'], true],
    'customapi' => ['Custom API (JSON)', ['key'], true], 'link' => ['Link only', [], false],
  ];
}

function und_default_config() {
  $svc = function ($name, $type, $icon = '', $extra = []) { return $extra + ['id' => und_id(), 'name' => $name, 'type' => $type, 'icon' => $icon, 'href' => '', 'url' => '', 'fields' => [], 'options' => new stdClass()]; };
  return [
    'general' => ['title' => 'UnDash', 'refresh_system' => 5, 'refresh_services' => 15, 'disk_path' => '/mnt/user', 'search' => 'duckduckgo',
                  'background' => '', 'blur' => 4, 'brightness' => 75, 'show_resources' => true, 'show_search' => true, 'show_date' => true, 'os_check_hours' => 6, 'ups_name' => 'ups', 'theme' => 'dark'],
    'groups' => [
      ['id' => und_id(), 'name' => 'System', 'columns' => 4, 'header' => false, 'services' => [
        $svc('System information', 'system_info', 'sh-unraid'), $svc('CPU Usage', 'cpu'), $svc('Memory Usage', 'memory'), $svc('UPS', 'ups')]],
      ['id' => und_id(), 'name' => 'Server', 'columns' => 3, 'header' => true, 'services' => [
        $svc('Docker', 'docker_summary', 'sh-docker'), $svc('Virtual machines', 'vm_summary', 'sh-qemu'), $svc('Storage', 'storage', 'sh-unraid')]],
    ],
  ];
}

function und_id() { return bin2hex(random_bytes(4)); }

function und_load_config() {
  $c = und_read_json(UND_CFG, null);
  if (!is_array($c) || !isset($c['groups'])) return und_default_config();
  return und_normalize($c);
}

/** Clamp and fill a config that came from disk or from the browser. */
function und_normalize(array $c) {
  $d = und_default_config();
  $g = (isset($c['general']) && is_array($c['general']) ? $c['general'] : []) + $d['general'];
  $g['title'] = und_clip(trim((string)$g['title']), 60) ?: 'UnDash';
  foreach (['refresh_system' => [2, 300], 'refresh_services' => [5, 3600], 'blur' => [0, 40], 'brightness' => [10, 100], 'os_check_hours' => [1, 168]] as $k => $r) $g[$k] = max($r[0], min($r[1], (int)$g[$k]));
  foreach (['show_resources', 'show_search', 'show_date'] as $k) $g[$k] = !empty($g[$k]);
  $g['disk_path'] = preg_match('#^/mnt/[A-Za-z0-9_./ -]*$#', (string)$g['disk_path']) ? (string)$g['disk_path'] : '/mnt/user';
  $g['theme'] = in_array($g['theme'], ['dark', 'light', 'midnight'], true) ? $g['theme'] : 'dark';
  $g['search'] = in_array($g['search'], ['duckduckgo', 'google', 'bing', 'startpage', 'brave'], true) ? $g['search'] : 'duckduckgo';
  $g['background'] = preg_match('#^(https?://|/)[^"\'<>\s]*$#', (string)$g['background']) ? (string)$g['background'] : '';
  $g['ups_name'] = preg_match('/^[A-Za-z0-9_.@:-]{1,64}$/', (string)$g['ups_name']) ? (string)$g['ups_name'] : 'ups';
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
        'fields' => array_values(array_filter(array_map('strval', (array)($s['fields'] ?? [])), function ($f) { return preg_match('/^[A-Za-z0-9_]{1,40}$/', $f); })),
        'options' => $opt ? $opt : new stdClass()];
    }
    $groups[] = $out;
  }
  return ['general' => $g, 'groups' => $groups];
}

function und_save_config(array $c) { return und_write_json(UND_CFG, und_normalize($c)); }

/* ---------- secrets ---------- */

function und_secrets() {
  $s = isset($GLOBALS['UND_TEST_SECRETS']) ? $GLOBALS['UND_TEST_SECRETS'] : und_read_json(UND_SECRETS, []);
  return ['vars' => isset($s['vars']) && is_array($s['vars']) ? $s['vars'] : [], 'svc' => isset($s['svc']) && is_array($s['svc']) ? $s['svc'] : []];
}
function und_save_secrets(array $s) { return und_write_json(UND_SECRETS, $s, true); }

function und_var_is_secret($name) { return preg_match('/KEY|TOKEN|PASS|SECRET/i', $name) === 1; }

/** What the browser may see: secret variables and stored credentials are replaced by UND_KEEP. */
function und_secrets_view(array $s) {
  $vars = [];
  foreach ($s['vars'] as $k => $v) $vars[] = ['name' => $k, 'value' => und_var_is_secret($k) && $v !== '' ? UND_KEEP : $v, 'secret' => und_var_is_secret($k)];
  $svc = [];
  foreach ($s['svc'] as $id => $c) foreach (['key', 'password', 'username'] as $f) {
    if (!isset($c[$f]) || $c[$f] === '') continue;
    $svc[$id][$f] = ($f === 'username' || und_has($c[$f], '{{')) ? $c[$f] : UND_KEEP;
  }
  return ['vars' => $vars, 'svc' => (object)$svc];
}

/** Merge what the browser sent back with what is stored (UND_KEEP = unchanged). */
function und_secrets_merge(array $old, array $vars, array $svc, array $ids) {
  $new = ['vars' => [], 'svc' => []];
  foreach ($vars as $v) {
    $n = strtoupper(trim((string)($v['name'] ?? '')));
    if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $n)) continue;
    $val = (string)($v['value'] ?? '');
    $new['vars'][$n] = $val === UND_KEEP ? (string)($old['vars'][$n] ?? '') : und_clip($val, 2000);
  }
  foreach ($ids as $id) {
    $in = isset($svc[$id]) && is_array($svc[$id]) ? $svc[$id] : [];
    foreach (['key', 'password', 'username'] as $f) {
      $val = (string)($in[$f] ?? '');
      if ($val === UND_KEEP) $val = (string)($old['svc'][$id][$f] ?? '');
      if ($val !== '') $new['svc'][$id][$f] = und_clip($val, 2000);
    }
  }
  return $new;
}

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
    foreach (['key', 'password', 'username'] as $f) $s[$f] = und_resolve((string)($c[$f] ?? ''), $sec['vars']);
    foreach (['url', 'href'] as $f) $s[$f] = und_resolve($s[$f], $sec['vars']);
    $s['options'] = json_decode(und_resolve(json_encode($s['options']), array_map(function ($v) { return trim(json_encode((string)$v), '"'); }, $sec['vars'])), true) ?: [];
    $s['_group'] = $g['name'];
    return $s;
  }
  return null;
}

function und_find_service(array $cfg, $group, $name, $type = null) {
  foreach ($cfg['groups'] as $g) foreach ($g['services'] as $s) {
    if (strcasecmp($s['name'], (string)$name) === 0 && ($group === null || $group === '' || strcasecmp($g['name'], (string)$group) === 0) && ($type === null || $s['type'] === $type)) return $s['id'];
  }
  return null;
}

/** The layout sent to the browser: hrefs resolved (they are links the user clicks anyway), nothing else. */
function und_layout_view(array $cfg) {
  $vars = und_secrets()['vars'];
  foreach ($cfg['groups'] as &$g) foreach ($g['services'] as &$s) $s['href_resolved'] = und_resolve($s['href'], $vars);
  return $cfg;
}
