<?php
/*
 * UnDash - JSON API. Everything is POST.
 * Inside Unraid's web UI, Unraid's own local_prepend.php checks the login and the CSRF token of every POST before this file runs.
 * On the standalone port, standalone/router.php checks the Unraid login (or UnDash's own session) and its CSRF token first.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';      // the Docker version's password (Settings > Connection)

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function fail($m, $code = 400) { out(['error' => $m], $code); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('POST only.', 405);
$action = (string)($_POST['action'] ?? '');
$P = function ($k, $d = '') { return isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : $d; };
$J = function ($k) { $v = json_decode((string)($_POST[$k] ?? ''), true); return is_array($v) ? $v : null; };

/** A background OS update check when the cached result is old: the page never waits for the update server. */
function api_os_autocheck(array $cfg, array $os) {
  $lock = und_tmp('os-check.lock');
  if ($os['job'] === 'running' || time() - $os['checked'] <= $cfg['general']['os_check_hours'] * 3600) return;
  if (is_file($lock) && time() - filemtime($lock) < 600) return;
  @touch($lock);
  und_spawn([PHP_BINARY ?: '/usr/bin/php', UND_ROOT . '/scripts/oscheck.php'], und_tmp('os-check.log'));
}

try {
switch ($action) {

  case 'boot': {
    $cfg = und_load_config(); $loc = und_loc();
    out(['version' => UND_VERSION, 'layout' => und_layout_view($cfg), 'types' => und_types(), 'css' => $cfg['appearance']['css_enabled'] ? und_load_css() : '',
         'server' => und_server_identity(),
         'standalone' => defined('UND_STANDALONE'), 'port' => (int)$loc['PORT'], 'port_enabled' => $loc['STANDALONE'] === 'yes' && !und_api_mode(), 'caps' => und_caps(),
         'api' => und_api_mode() ? ['configured' => und_api_conf()['url'] !== '' && und_api_conf()['key'] !== ''] : null,
         'auth' => und_api_mode() ? und_auth_kind() : 'unraid']);
  }

  /* one call per refresh: system metrics, containers and VMs (each part can be skipped when its section is hidden) */
  case 'live': {
    $cfg = und_load_config();
    $want = array_flip(explode(',', $P('parts', 'system,docker,vms')));
    $r = ['at' => time()];
    if (isset($want['system'])) { $r['system'] = und_system($cfg); if (!und_api_mode() && isset($r['system']['os_update'])) api_os_autocheck($cfg, $r['system']['os_update']); }
    if (isset($want['docker'])) $r['docker'] = und_docker_live();
    if (isset($want['vms'])) $r['vms'] = und_vm_live();
    if (isset($want['ups'])) $r['ups'] = und_ups($cfg['general']['ups_name']);
    // alerts are worked out here (one source for the page and the history); only the parts this request loaded are tracked
    if (isset($r['system'])) {
      $dk = $r['docker'] ?? null;
      $smart = und_smart_failures($cfg);          // what the last Scrutiny read left behind: no network call here
      $r['alerts'] = und_alerts($cfg, $r['system'], $dk, $smart);
      $scopes = isset($r['system']['error']) ? [] : ['system'];
      if (is_array($dk) && !isset($dk['error'])) $scopes[] = 'docker';
      if ($smart['checked']) $scopes[] = 'smart';
      und_alerts_track($dk === null ? array_values(array_filter($r['alerts'], function ($a) { return strpos($a['cat'], 'docker.') !== 0; })) : $r['alerts'], $scopes);
    }
    // the rules in force now, by the server's clock, so every open tab hides the same alerts
    $r['ignores'] = array_map(function ($x) { return ['id' => $x['id'], 'kind' => $x['kind'], 'key' => $x['key'], 'until' => $x['until']]; }, und_ignores_active($r['at']));
    $r['power'] = is_file(und_tmp('power-started')) ? trim((string)@file_get_contents(und_tmp('power-started'))) : '';
    out($r);
  }

  case 'service': {
    $id = $P('id');
    if (!preg_match('/^[a-f0-9]{8}$/', $id)) fail('Invalid widget id.');
    $r = und_fetch_service(und_load_config(), $id);
    $r['at'] = time();
    out($r);
  }

  /* ---- media, Arr Stack, download clients ---- */
  case 'media': {
    $k = $P('kind');
    if (!in_array($k, und_media_ids(), true)) fail('Unknown media list.');
    out(und_media(und_load_config(), $k));
  }
  case 'arr': {
    if (!preg_match('/^[a-f0-9]{8}$/', $P('id'))) fail('Invalid app id.');
    out(und_arr_status(und_load_config(), $P('id')));
  }
  case 'dl': {
    if (!preg_match('/^[a-f0-9]{8}$/', $P('id'))) fail('Invalid client id.');
    out(und_dl_status(und_load_config(), $P('id')));
  }
  case 'playing':  out(und_playing(und_load_config()));
  case 'requests': out(und_requests(und_load_config()));
  case 'request_action': {
    $r = und_request_action(und_load_config(), $P('app'), $P('id'), $P('op'));
    if (isset($r['error'])) fail($r['error'], 409);
    out($r);
  }
  case 'health': out(und_health(und_load_config(), $P('force') === '1'));
  case 'seerr_search': out(und_seerr_search(und_load_config(), $P('q')));
  case 'seerr_request': {
    $r = und_seerr_request(und_load_config(), $P('app'), $P('type'), $P('id'));
    if (isset($r['error'])) fail($r['error'], 409);
    out($r);
  }
  case 'dl_action': {
    if (!preg_match('/^[a-f0-9]{8}$/', $P('id'))) fail('Invalid client id.');
    $r = und_dl_action(und_load_config(), $P('id'), $P('op'));
    if (isset($r['error'])) fail($r['error'], 409);
    out($r);
  }

  /* ---- Docker ---- */
  case 'docker_action':  { $r = und_docker_action($P('name'), $P('op')); if (isset($r['error'])) fail($r['error'], 409); out($r); }
  case 'docker_logs':    { $r = und_docker_logs($P('name'), (int)$P('lines', '300')); if (isset($r['error'])) fail($r['error']); out($r); }
  case 'docker_inspect': { $r = und_docker_inspect($P('name')); if (isset($r['error'])) fail($r['error']); out($r); }
  case 'docker_check':   out(und_docker_check());
  case 'docker_job': {
    $n = $P('name');
    if ($n === '') out(und_job(und_tmp('docker-check.log')));
    if (!und_valid_container($n)) fail('Invalid container name.');
    out(und_docker_job($n));
  }

  /* ---- VMs ---- */
  case 'vm_action': { $r = und_vm_action($P('name'), $P('op')); if (isset($r['error'])) fail($r['error'], 409); out($r); }

  /* ---- server ---- */
  case 'power_prepare': { $r = und_power_prepare($P('op')); if (isset($r['error'])) fail($r['error']); out($r); }
  case 'power':         { $r = und_power($P('op'), $P('nonce'), $P('confirm')); if (isset($r['error'])) fail($r['error'], 409); out($r); }
  case 'history':       out(['history' => und_history()]);
  case 'self_update':   out(und_self_update());
  case 'alert_history': out(und_alert_history());

  /* ignored alerts: hiding only, monitoring goes on */
  case 'ignores_list':  out(['now' => time(), 'rules' => und_ignores(), 'categories' => und_alert_categories()]);
  case 'ignore_add': {
    $d = $P('duration');
    $r = und_ignore_add($P('kind'), $P('category'), $P('resource'), $d === 'permanent' ? 'permanent' : (ctype_digit($d) ? (int)$d : -1), $P('title'), $P('severity'));
    if (isset($r['error'])) fail($r['error']);
    out($r + ['now' => time()]);
  }
  case 'ignore_remove': {
    $ids = $J('ids') ?? [$P('id')];
    $r = und_ignore_remove($ids); if (isset($r['error'])) fail($r['error']); out($r);
  }
  case 'ignore_restore_all': { if ($P('confirm') !== 'yes') fail('Confirm restoring every ignored alert.'); $r = und_ignore_restore_all(); if (isset($r['error'])) fail($r['error']); out($r); }
  case 'ignore_purge_expired': { $r = und_ignore_purge_expired(); if (isset($r['error'])) fail($r['error']); out($r); }
  case 'os_status':     out(und_os_cached() + ['log' => und_job(und_tmp('os-update.log'))]);
  case 'os_check':      out(und_os_check());
  case 'os_update':     { $r = und_os_update(); if (isset($r['error'])) fail($r['error']); und_history_add('server', 'Unraid OS', 'update', true, 'install started'); out($r); }
  case 'storage':       out(und_storage());
  case 'container_sources': out(und_container_sources());
  case 'icons':         { try { out(und_icon_search($P('q'), (int)$P('max', '60'))); } catch (Exception $e) { fail(und_redact($e->getMessage())); } }
  case 'icon_slugs':    { $l = json_decode($P('slugs', '[]'), true); try { out(und_icon_slugs(is_array($l) ? $l : [])); } catch (Exception $e) { fail(und_redact($e->getMessage())); } }

  /* ---- settings ---- */
  case 'settings': {
    $cfg = und_load_config(); $loc = und_loc();
    out(['config' => $cfg, 'creds' => und_creds_view($cfg), 'shared' => und_shared_view($cfg), 'types' => und_types(), 'keep' => UND_KEEP, 'css' => und_load_css(),
         'loc' => $loc, 'store' => und_store_dir(), 'flash' => UND_DIR, 'metrics' => und_metric_ids(), 'sections' => und_section_ids(),
         'zones' => array_map(function ($t) { return und_zone(['type' => $t]); }, array_combine(array_keys(und_types()), array_keys(und_types())))]);
  }
  /* the dashboard as it would look with unsaved settings: nothing is written, credentials are not involved */
  case 'preview': {
    $cfg = $J('config');
    if (!$cfg || !isset($cfg['groups'])) fail('Nothing to preview.');
    $norm = und_normalize($cfg);
    $norm['schema'] = UND_SCHEMA;
    out(['layout' => und_layout_view($norm)]);
  }
  case 'save': {
    $lk = und_config_lock();
    $cfg = $J('config');
    if (!$cfg || !isset($cfg['groups'])) fail('Nothing to save.');
    $old = und_load_config(); $env = und_env();
    $norm = und_creds_apply(und_normalize($cfg), $old, $J('creds') ?: [], $env);
    if (isset($_POST['shared'])) $env = und_shared_apply($env, $old, $norm, $J('shared') ?: []);
    if (!und_save_config($norm)) fail('Could not write the configuration directory. Check that ' . und_store_dir() . ' is writable.', 500);
    if (!und_env_write($env)) fail('Could not write the .env file in ' . und_store_dir() . '.', 500);
    if (isset($_POST['css'])) und_save_css($P('css'), false);
    und_backup('settings saved');
    out(['ok' => true]);
  }
  /* small preference changes made on the dashboard itself (favourite, hide, collapse, layout): no backup, no secrets */
  case 'prefs': {
    $lk = und_config_lock();
    $cfg = und_load_config();
    $kind = $P('kind'); $key = $P('key'); $val = $J('value') ?: [];
    if ($kind === 'docker' || $kind === 'vms') {
      if ($key === '' || strlen($key) > 200) fail('Invalid name.');
      $items = (array)$cfg[$kind]['items'];
      $items[$key] = array_merge((array)($items[$key] ?? []), array_intersect_key($val, ['label' => 1, 'icon' => 1, 'description' => 1, 'hidden' => 1, 'favorite' => 1, 'no_update' => 1]));
      $cfg[$kind]['items'] = $items;
    } elseif ($kind === 'section') {
      foreach ($cfg['dashboard']['sections'] as &$s) if ($s['id'] === $key) $s = array_merge($s, array_intersect_key($val, ['collapsed' => 1, 'visible' => 1, 'width' => 1]));
      unset($s);
    } elseif ($kind === 'docker_view' || $kind === 'vms_view') {
      $k = $kind === 'docker_view' ? 'docker' : 'vms';
      $cfg[$k] = array_merge($cfg[$k], array_intersect_key($val, ['layout' => 1, 'sort' => 1, 'hide_stopped' => 1, 'view' => 1, 'filter' => 1, 'selected' => 1, 'columns' => 1]));
    } elseif ($kind === 'apps_view') {
      if (isset($val['headers'])) $cfg['apps']['headers'] = (bool)$val['headers'];
    } elseif ($kind === 'group') {
      if (isset($val['name'])) $val['name'] = trim((string)$val['name']);
      if (isset($val['name']) && ($val['name'] === '' || strlen($val['name']) > 60)) fail('Give the group a name of up to 60 characters.');
      foreach ($cfg['groups'] as &$g) if ($g['id'] === $key) $g = array_merge($g, array_intersect_key($val, ['name' => 1, 'header' => 1]));
      unset($g);
    } elseif ($kind === 'service') {
      foreach ($cfg['groups'] as &$g) foreach ($g['services'] as &$s) if ($s['id'] === $key) $s = array_merge($s, array_intersect_key($val, ['favorite' => 1, 'hidden' => 1]));
      unset($g, $s);
    } else fail('Unknown preference.');
    if (!und_save_config($cfg, false)) fail('Could not save.', 500);
    out(['ok' => true, 'layout' => und_layout_view(und_load_config())]);
  }
  case 'test': {
    // try one app with what the form shows, before it is saved (nothing is written)
    $cfg = $J('config'); $id = $P('id');
    if (!$cfg || !preg_match('/^[a-f0-9]{8}$/', $id)) fail('Nothing to test.');
    $env = und_env(); $old = und_load_config();
    $norm = und_creds_apply(und_normalize($cfg), $old, $J('creds') ?: [], $env);
    if (isset($_POST['shared'])) $env = und_shared_apply($env, $old, $norm, $J('shared') ?: []);
    $GLOBALS['UND_TEST_SECRETS'] = ['vars' => array_diff_key($env, array_flip(UND_ENV_RESERVED)), 'svc' => [], 'unraid' => []];
    out(und_fetch_service($norm, $id));
  }
  case 'validate': out(['issues' => und_validate(und_load_config())]);

  /* ---- Unraid API connection (Docker version) ---- */
  case 'unraid_conf': {
    $c = und_api_conf();
    out(['url' => $c['url'], 'key_set' => $c['key'] !== '', 'from_env' => $c['from_env'], 'mode' => UND_MODE]);
  }
  case 'unraid_save': {
    $url = trim($P('url')); $key = $P('key');
    if ($url !== '' && !preg_match('#^https?://[^\s"\'<>]+$#i', $url)) fail('The address must start with http:// or https://, e.g. http://192.168.1.10/graphql');
    $sec = und_secrets();
    // the saved key is only ever sent to the address it was entered for: a new server address needs the key again
    $was = parse_url((string)und_api_conf()['url']); $now = parse_url($url);
    if ($key === UND_KEEP && und_api_conf()['key'] !== '' && $url !== '' && strtolower(($was['host'] ?? '') . ':' . ($was['port'] ?? '')) !== strtolower(($now['host'] ?? '') . ':' . ($now['port'] ?? '')))
      fail('Enter the API key again: it is only sent to the server it was saved for.');
    $sec['unraid']['url'] = und_clip($url, 300);
    if ($key !== UND_KEEP) $sec['unraid']['key'] = und_clip(trim($key), 300);
    und_save_secrets($sec);
    foreach (['system', 'containers', 'vms', 'ups'] as $n) @unlink(und_tmp('api-' . $n . '.json'));
    out(['ok' => true]);
  }
  case 'unraid_test': out(und_api_test());

  /* ---- the dashboard password (Docker version) ---- */
  case 'auth_status': out(['kind' => und_api_mode() ? und_auth_kind() : 'unraid']);
  case 'password_set': case 'password_remove': {
    if (!und_api_mode()) fail('The plugin signs in with your Unraid login.');
    $kind = und_auth_kind();
    if ($kind === 'env') fail('The password comes from UNDASH_PASSWORD in the container settings; change it there.');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($kind === 'file') {
      if (und_login_throttled($ip)) fail('Too many wrong passwords. Wait 15 minutes.', 429);
      if (!und_password_ok($P('current'))) { und_login_failed($ip); fail('The current password is wrong.', 403); }
    }
    if ($action === 'password_remove') { if ($P('confirm') !== 'yes') fail('Confirm going without a password.'); $r = und_auth_open(); }
    else { if ($P('password') !== $P('password2')) fail('The passwords do not match.'); $r = und_password_set($P('password')); }
    if (isset($r['error'])) fail($r['error']);
    if ($action === 'password_set') und_sess_drop_others();
    und_history_add('server', 'Dashboard password', $action === 'password_remove' ? 'removed' : ($kind === 'file' ? 'changed' : 'set'), true);
    out(['ok' => true, 'kind' => und_auth_kind()]);
  }
  case 'loc_save': {
    $r = und_save_loc(['CONFIG_DIR' => $P('dir'), 'PORT' => $P('port'), 'BIND' => $P('bind'), 'STANDALONE' => $P('standalone') === 'yes' ? 'yes' : 'no']);
    if (isset($r['error'])) fail($r['error']);
    // the standalone listener picks up a new port or address when it is restarted
    und_spawn([UND_ROOT . '/scripts/rc.undash', 'restart'], und_tmp('rc.log'));
    out($r + ['store' => und_store_dir()]);
  }

  /* ---- import / export / backups ---- */
  case 'export': {
    if ($P('kind') === 'env' && und_api_mode() && in_array(und_auth_kind(), ['none', 'open'], true)) fail('Set a dashboard password first (Settings > Connection): without one, anyone on your network could download every key.', 403);
    out(und_export($P('kind') === 'env' ? 'env' : 'config'));
  }
  case 'import_check':  { $r = und_import_parse($P('file')); if (isset($r['error'])) fail($r['error']); out(['summary' => $r['summary']]); }
  case 'import_file':   { $r = und_import_apply($P('file')); if (isset($r['error'])) fail($r['error']); out($r); }
  case 'backups':       out(['backups' => und_backups()]);
  case 'restore':       { $r = und_restore($P('id')); if (isset($r['error'])) fail($r['error']); out($r); }
  case 'import_preview': {
    $c = und_hp_convert($P('services'), $P('settings'), $P('env'));
    if (isset($c['error'])) fail($c['error']);
    $stored = und_secrets()['vars'];
    out(['groups' => $c['groups'], 'services' => $c['services'], 'warnings' => $c['warnings'],
         'vars' => array_map(function ($n, $v) use ($stored) { return ['name' => $n, 'set' => $v !== null && $v !== '', 'stored' => isset($stored[$n]) && $stored[$n] !== '']; }, array_keys($c['vars']), $c['vars']),
         'layout' => array_map(function ($g) { return ['name' => $g['name'], 'services' => array_map(function ($s) { return $s['name'] . ' (' . $s['type'] . ')'; }, $g['services'])]; }, $c['config']['groups'])]);
  }
  case 'import_apply': {
    $c = und_hp_convert($P('services'), $P('settings'), $P('env'));
    if (isset($c['error'])) fail($c['error']);
    und_backup('before importing services.yaml');
    out(und_hp_apply($c));
  }
  case 'reset': {
    und_backup('before reset');
    und_save_config(und_default_config(), false);
    out(['ok' => true]);
  }
}
} catch (Throwable $e) {
  // never show a stack trace or a path to the browser; the details go to the PHP error log
  error_log('undash: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
  fail('Internal error while handling "' . preg_replace('/[^a-z_]/', '', $action) . '".', 500);
}
fail('Unknown action.');
