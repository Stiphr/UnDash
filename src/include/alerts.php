<?php
/*
 * UnDash - alerts: what is wrong right now, worked out on the server from the same data the dashboard shows, plus a
 * history of when each problem started and ended. The live refresh evaluates them for the page; scripts/alertcheck.php
 * does it once a minute in the background, so the history is complete even when no dashboard is open.
 *
 * Each alert: lv (bad | warn | info), msg, cat (an id from und_alert_categories()), res (the disk, container, version …)
 * and act (the button the page shows: logs | settings | restart | os, or '').
 */

/** The alerts for one snapshot. $sys = und_system(), $dk = und_docker_live() (either may be null when not loaded). */
function und_alerts(array $cfg, $sys, $dk) {
  $out = [];
  $add = function ($lv, $msg, $cat, $res, $act = '') use (&$out) { $out[] = ['lv' => $lv, 'msg' => $msg, 'cat' => $cat, 'res' => (string)$res, 'act' => $act]; };
  if (is_array($sys) && isset($sys['error'])) {
    $add('bad', $sys['error'] . (!empty($sys['auth']) ? ' Check Settings > Connection.' : ''), 'api.error', 'unraid', 'settings');
  } elseif (is_array($sys)) {
    $a = $sys['array'] ?? [];
    $st = (string)($a['state'] ?? '');
    if ($st !== '' && $st !== 'STARTED') $add('warn', 'The array is ' . strtolower($st) . '. Shares, containers and VMs on it are unavailable.', 'array.state', $st);
    if (!empty($a['resync'])) $add('info', ($a['resync_action'] ?: 'A parity operation') . ' is running' . (isset($a['resync_pct']) && $a['resync_pct'] !== null ? ' (' . $a['resync_pct'] . '%)' : '') . '.', 'array.resync', 'array');
    foreach ((array)($a['bad_disks'] ?? []) as $n) $add('bad', $n . ' is disabled, missing or emulated. Check Main in Unraid.', 'disk.bad', $n);
    foreach ((array)($a['error_disks'] ?? []) as $d) $add('bad', $d['name'] . ' reported ' . $d['errors'] . ' read/write error' . ($d['errors'] > 1 ? 's' : '') . '.', 'disk.errors', $d['name']);
    foreach ((array)($a['hot_disks'] ?? []) as $d) $add('warn', $d['name'] . ' is at ' . $d['temp'] . '°C.', 'disk.hot', $d['name']);
    $o = $sys['os_update'] ?? [];
    if (!empty($o['staged'])) $add('info', 'Unraid ' . $o['staged'] . ' is installed and becomes active after a restart.', 'os.staged', $o['staged'], 'restart');
    elseif (!empty($o['available'])) $add('info', 'Unraid ' . $o['latest'] . ' is available (running ' . $o['installed'] . ').', 'os.available', $o['latest'], 'os');
    if (!empty($o['error']) && empty($o['available'])) $add('warn', 'The Unraid update check failed: ' . $o['error'], 'os.check_failed', 'server');
  }
  if (is_array($dk) && !isset($dk['error'])) {
    $items = (array)($cfg['docker']['items'] ?? []);
    foreach ((array)($dk['containers'] ?? []) as $c) {
      $p = (array)($items[$c['name']] ?? []);
      if (!empty($p['hidden'])) continue;
      $nm = ($p['label'] ?? '') !== '' ? $p['label'] : $c['name'];
      if (($c['health'] ?? '') === 'unhealthy') $add('bad', $nm . ' is unhealthy.', 'docker.unhealthy', $c['name'], 'logs');
      elseif (($c['state'] ?? '') === 'restarting') $add('bad', $nm . ' keeps restarting' . (!empty($c['restarts']) ? ' (' . $c['restarts'] . ' restarts)' : '') . '.', 'docker.restarting', $c['name'], 'logs');
      elseif (($c['state'] ?? '') === 'exited' && !empty($c['exit']) && !empty($c['autostart'])) $add('warn', $nm . ' stopped with exit code ' . $c['exit'] . '.', 'docker.exited', $c['name'], 'logs');
      if (($c['job'] ?? '') === 'update_failed') $add('bad', 'The update of ' . $nm . ' failed.', 'docker.update_failed', $c['name'], 'logs');
    }
  }
  return $out;
}

/* ---------- history ---------- */

const UND_ALERT_LOG_MAX = 300;

function und_alert_file() { return und_store_dir() . '/alert-history.json'; }

/**
 * Records starts and ends. $scopes says which categories this snapshot actually checked ('system', 'docker'): an
 * alert of a category that was not checked (for example containers, when Docker did not answer) stays open.
 * The file is only written when something starts or ends, so the 2-second refresh does not wear the disk.
 */
function und_alerts_track(array $alerts, array $scopes, $now = null) {
  $now = $now ?? time();
  @mkdir(und_tmp('locks'), 0755, true);
  $fp = @fopen(und_tmp('locks/alert-history.lock'), 'c');
  if ($fp) flock($fp, LOCK_EX);
  $h = und_read_json(und_alert_file(), []);
  $act = is_array($h['active'] ?? null) ? $h['active'] : [];
  $log = is_array($h['log'] ?? null) ? $h['log'] : [];
  $changed = false; $seen = [];
  foreach ($alerts as $a) {
    $k = $a['cat'] . ':' . $a['res'];
    $seen[$k] = 1;
    if (!isset($act[$k])) { $act[$k] = ['cat' => $a['cat'], 'res' => $a['res'], 'lv' => $a['lv'], 'msg' => und_clip($a['msg'], 300), 'start' => $now]; $changed = true; }
  }
  foreach ($act as $k => $a) {
    if (isset($seen[$k])) continue;
    $scope = strpos((string)$a['cat'], 'docker.') === 0 ? 'docker' : 'system';
    if (!in_array($scope, $scopes, true)) continue;
    $log[] = $a + ['end' => $now];
    unset($act[$k]); $changed = true;
  }
  if ($changed) {
    $old = $now - 30 * 86400;
    $log = array_values(array_filter($log, function ($e) use ($old) { return (int)($e['end'] ?? 0) >= $old; }));
    $log = array_slice($log, -UND_ALERT_LOG_MAX);
    @mkdir(dirname(und_alert_file()), 0755, true);
    und_write_json(und_alert_file(), ['active' => $act, 'log' => $log]);
  }
  if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
  return $changed;
}

/** For the page: open problems first, then ended ones, newest first. */
function und_alert_history() {
  $h = und_read_json(und_alert_file(), []);
  $act = array_values(array_filter((array)($h['active'] ?? []), 'is_array'));
  usort($act, function ($a, $b) { return (int)$b['start'] - (int)$a['start']; });
  $log = array_reverse(array_values(array_filter((array)($h['log'] ?? []), 'is_array')));
  return ['now' => time(), 'active' => $act, 'log' => array_slice($log, 0, 200)];
}

/** One background check (scripts/alertcheck.php): the full system and container state, then the history. */
function und_alerts_check() {
  $cfg = und_load_config();
  $sys = und_system($cfg);
  $dk = und_docker_live();
  $scopes = isset($sys['error']) ? [] : ['system'];
  if (is_array($dk) && !isset($dk['error'])) $scopes[] = 'docker';
  $alerts = und_alerts($cfg, $sys, $dk);
  und_alerts_track($alerts, $scopes);
  @file_put_contents(und_tmp('alertcheck.at'), (string)time());
  return count($alerts);
}
