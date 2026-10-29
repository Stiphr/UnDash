<?php
/*
 * UnDash - JSON API. Everything is POST.
 * Inside Unraid's web UI, Unraid's own local_prepend.php checks the login and the CSRF token of every POST before this file runs.
 * On the standalone port, standalone/router.php checks the Unraid login (or UnDash's own session) and its CSRF token first.
 */
require_once __DIR__ . '/lib.php';

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
         'server' => ['name' => (string)(und_var_ini()['NAME'] ?? gethostname()), 'ip' => und_server_ip()],
         'standalone' => defined('UND_STANDALONE'), 'port' => (int)$loc['PORT'], 'port_enabled' => $loc['STANDALONE'] === 'yes' && !und_api_mode(), 'caps' => und_caps(),
         'api' => und_api_mode() ? ['configured' => und_api_conf()['url'] !== '' && und_api_conf()['key'] !== ''] : null]);
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
  case 'os_status':     out(und_os_cached() + ['log' => und_job(und_tmp('os-update.log'))]);
  case 'os_check':      out(und_os_check());
  case 'os_update':     { $r = und_os_update(); if (isset($r['error'])) fail($r['error']); und_history_add('server', 'Unraid OS', 'update', true, 'install started'); out($r); }
  case 'storage':       out(und_storage());

  /* ---- settings ---- */
  case 'settings': {
    $cfg = und_load_config(); $loc = und_loc();
    out(['config' => $cfg, 'secrets' => und_secrets_view(und_secrets()), 'types' => und_types(), 'keep' => UND_KEEP, 'css' => und_load_css(),
         'loc' => $loc, 'store' => und_store_dir(), 'flash' => UND_DIR, 'metrics' => und_metric_ids(), 'sections' => und_section_ids()]);
  }
  case 'save': {
    $cfg = $J('config');
    if (!$cfg || !isset($cfg['groups'])) fail('Nothing to save.');
    $norm = und_normalize($cfg);
    if (!und_save_config($norm)) fail('Could not write the configuration directory. Check that ' . und_store_dir() . ' is writable.', 500);
    if (isset($_POST['vars']) || isset($_POST['svc'])) und_save_secrets(und_secrets_merge(und_secrets(), $J('vars') ?: [], $J('svc') ?: [], und_service_ids($norm)));
    if (isset($_POST['css'])) und_save_css($P('css'), false);
    out(['ok' => true]);
  }
  /* small preference changes made on the dashboard itself (favourite, hide, collapse, layout): no backup, no secrets */
  case 'prefs': {
    $cfg = und_load_config();
    $kind = $P('kind'); $key = $P('key'); $val = $J('value') ?: [];
    if ($kind === 'docker' || $kind === 'vms') {
      if ($key === '' || strlen($key) > 200) fail('Invalid name.');
      $items = (array)$cfg[$kind]['items'];
      $items[$key] = array_merge((array)($items[$key] ?? []), array_intersect_key($val, ['label' => 1, 'icon' => 1, 'description' => 1, 'hidden' => 1, 'favorite' => 1]));
      $cfg[$kind]['items'] = $items;
    } elseif ($kind === 'section') {
      foreach ($cfg['dashboard']['sections'] as &$s) if ($s['id'] === $key) $s = array_merge($s, array_intersect_key($val, ['collapsed' => 1, 'visible' => 1, 'width' => 1]));
      unset($s);
    } elseif ($kind === 'docker_view' || $kind === 'vms_view') {
      $k = $kind === 'docker_view' ? 'docker' : 'vms';
      $cfg[$k] = array_merge($cfg[$k], array_intersect_key($val, ['layout' => 1, 'sort' => 1, 'hide_stopped' => 1, 'view' => 1, 'filter' => 1, 'selected' => 1, 'columns' => 1]));
    } elseif ($kind === 'service') {
      foreach ($cfg['groups'] as &$g) foreach ($g['services'] as &$s) if ($s['id'] === $key) $s = array_merge($s, array_intersect_key($val, ['favorite' => 1, 'hidden' => 1]));
      unset($g, $s);
    } else fail('Unknown preference.');
    if (!und_save_config($cfg, false)) fail('Could not save.', 500);
    out(['ok' => true, 'layout' => und_layout_view(und_load_config())]);
  }
  case 'test': {
    // try one widget with the settings shown in the form, before they are saved
    $cfg = $J('config'); $id = $P('id');
    if (!$cfg || !preg_match('/^[a-f0-9]{8}$/', $id)) fail('Nothing to test.');
    $norm = und_normalize($cfg);
    $GLOBALS['UND_TEST_SECRETS'] = und_secrets_merge(und_secrets(), $J('vars') ?: [], $J('svc') ?: [], und_service_ids($norm));
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
    $sec['unraid']['url'] = und_clip($url, 300);
    if ($key !== UND_KEEP) $sec['unraid']['key'] = und_clip(trim($key), 300);
    und_save_secrets($sec);
    foreach (['system', 'containers', 'vms', 'ups'] as $n) @unlink(und_tmp('api-' . $n . '.json'));
    out(['ok' => true]);
  }
  case 'unraid_test': out(und_api_test());
  case 'loc_save': {
    $r = und_save_loc(['CONFIG_DIR' => $P('dir'), 'PORT' => $P('port'), 'BIND' => $P('bind'), 'STANDALONE' => $P('standalone') === 'yes' ? 'yes' : 'no']);
    if (isset($r['error'])) fail($r['error']);
    // the standalone listener picks up a new port or address when it is restarted
    und_spawn([UND_ROOT . '/scripts/rc.undash', 'restart'], und_tmp('rc.log'));
    out($r + ['store' => und_store_dir()]);
  }

  /* ---- import / export / backups ---- */
  case 'export':        out(und_export($P('kind') === 'full' ? 'full' : 'redacted'));
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
    und_backup('before Homepage import');
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
