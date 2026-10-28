<?php
/* UnDash - JSON API. Everything is POST: Unraid's own local_prepend.php checks the CSRF token of every POST before this file runs. */
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function fail($m, $code = 400) { out(['error' => $m], $code); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('POST only.', 405);
$action = (string)($_POST['action'] ?? '');
$P = function ($k, $d = '') { return isset($_POST[$k]) ? (string)$_POST[$k] : $d; };
$J = function ($k) { $v = json_decode((string)($_POST[$k] ?? ''), true); return is_array($v) ? $v : null; };

switch ($action) {

  case 'boot': {
    $cfg = und_load_config();
    out(['version' => UND_VERSION, 'layout' => und_layout_view($cfg), 'types' => und_types(), 'server' => ['name' => (string)(und_var_ini()['NAME'] ?? gethostname()), 'ip' => und_server_ip()]]);
  }

  case 'system': {
    $cfg = und_load_config();
    $s = und_system($cfg);
    $os = $s['os_update'];
    // a background check when the cached result is old: the page never waits for the update server
    if ($os['job'] !== 'running' && time() - $os['checked'] > $cfg['general']['os_check_hours'] * 3600 && (!is_file(und_tmp('os-check.lock')) || time() - filemtime(und_tmp('os-check.lock')) > 600)) {
      @touch(und_tmp('os-check.lock'));
      und_spawn([PHP_BINARY ?: '/usr/bin/php', UND_ROOT . '/scripts/oscheck.php'], und_tmp('os-check.log'));
    }
    out($s);
  }

  case 'service': {
    $cfg = und_load_config();
    $id = $P('id');
    if (!preg_match('/^[a-f0-9]{8}$/', $id)) fail('Invalid widget id.');
    $r = und_fetch_service($cfg, $id);
    $r['at'] = time();
    out($r);
  }

  /* ---- Docker ---- */
  case 'docker':        out(und_docker_list());
  case 'docker_stats':  out(und_docker_stats());
  case 'docker_action': { $r = und_docker_action($P('name'), $P('op')); if (isset($r['error'])) fail($r['error']); out($r); }
  case 'docker_logs':   { $r = und_docker_logs($P('name'), (int)$P('lines', '300')); if (isset($r['error'])) fail($r['error']); out($r); }
  case 'docker_check':  out(und_docker_check());
  case 'docker_job': {
    $n = $P('name');
    if ($n === '') out(und_job(und_tmp('docker-check.log')));
    if (!und_valid_name($n) || strpos($n, ' ') !== false) fail('Invalid container name.');
    out(und_job(und_tmp('docker-update-' . $n . '.log')));
  }

  /* ---- VMs ---- */
  case 'vms':       out(und_vm_list());
  case 'vm_action': { $r = und_vm_action($P('name'), $P('op')); if (isset($r['error'])) fail($r['error']); out($r); }

  /* ---- Unraid OS ---- */
  case 'os_status': out(und_os_cached() + ['log' => und_job(und_tmp('os-update.log'))]);
  case 'os_check':  out(und_os_check());
  case 'os_update': { $r = und_os_update(); if (isset($r['error'])) fail($r['error']); out($r); }

  /* ---- storage ---- */
  case 'storage': out(und_storage());

  /* ---- settings ---- */
  case 'settings': {
    $cfg = und_load_config();
    out(['config' => $cfg, 'secrets' => und_secrets_view(und_secrets()), 'types' => und_types(), 'keep' => UND_KEEP]);
  }
  case 'save': {
    $cfg = $J('config'); $vars = $J('vars'); $svc = $J('svc');
    if (!$cfg || !isset($cfg['groups'])) fail('Nothing to save.');
    $norm = und_normalize($cfg);
    $ids = [];
    foreach ($norm['groups'] as $g) foreach ($g['services'] as $s) $ids[] = $s['id'];
    if (!und_save_config($norm)) fail('Could not write ' . UND_CFG . '.', 500);
    und_save_secrets(und_secrets_merge(und_secrets(), $vars ?: [], $svc ?: [], $ids));
    out(['ok' => true]);
  }
  case 'test': {
    // try one widget with the settings shown in the form, before they are saved
    $cfg = $J('config'); $vars = $J('vars'); $svc = $J('svc'); $id = $P('id');
    if (!$cfg || !preg_match('/^[a-f0-9]{8}$/', $id)) fail('Nothing to test.');
    $norm = und_normalize($cfg);
    $ids = [];
    foreach ($norm['groups'] as $g) foreach ($g['services'] as $s) $ids[] = $s['id'];
    $sec = und_secrets_merge(und_secrets(), $vars ?: [], $svc ?: [], $ids);
    $GLOBALS['UND_TEST_SECRETS'] = $sec;     // fetchers read secrets through und_secrets(); this makes them see the unsaved form
    $r = und_fetch_service($norm, $id);
    out($r);
  }
  case 'import_preview': {
    $c = und_hp_convert($P('services'), $P('settings'), $P('env'));
    if (isset($c['error'])) fail($c['error']);
    out(['groups' => $c['groups'], 'services' => $c['services'], 'warnings' => $c['warnings'],
         'vars' => array_map(function ($n, $v) { return ['name' => $n, 'set' => $v !== null && $v !== '', 'stored' => isset(und_secrets()['vars'][$n]) && und_secrets()['vars'][$n] !== '']; }, array_keys($c['vars']), $c['vars']),
         'layout' => array_map(function ($g) { return ['name' => $g['name'], 'services' => array_map(function ($s) { return $s['name'] . ' (' . $s['type'] . ')'; }, $g['services'])]; }, $c['config']['groups'])]);
  }
  case 'import_apply': {
    $c = und_hp_convert($P('services'), $P('settings'), $P('env'));
    if (isset($c['error'])) fail($c['error']);
    out(und_hp_apply($c));
  }
  case 'export': {
    out(['config' => und_load_config()]);
  }
  case 'reset': {
    und_save_config(und_default_config());
    out(['ok' => true]);
  }
}
fail('Unknown action.');
