<?php
/*
 * UnDash - ignored alerts. Alerts are still worked out on every refresh; an ignore rule only hides a matching alert
 * from the dashboard, so monitoring never stops and an ignored alert comes back as soon as its rule is removed or runs
 * out while the condition is still there. Rules live in config.yaml (ignored_alerts), so they survive plugin updates and
 * container re-creation.
 *
 * A rule hides either one alert (kind "instance", key "<category>:<resource>", e.g. "disk.hot:disk3") or every alert
 * of a category (kind "type", key "<category>"). until is a Unix time, or null for "permanently".
 */

/** The alert categories the dashboard raises and lets you ignore. Connection and sign-in alerts are never ignorable. */
function und_alert_categories() {
  return ['docker.unhealthy' => 'Container unhealthy', 'docker.restarting' => 'Container restarting', 'docker.exited' => 'Container stopped with an error',
          'docker.update_failed' => 'Container update failed', 'array.state' => 'Array not started', 'array.resync' => 'Parity operation running',
          'disk.bad' => 'Disk disabled or missing', 'disk.errors' => 'Disk read/write errors', 'disk.hot' => 'Disk temperature', 'disk.smart' => 'Disk failed SMART (Scrutiny)',
          'os.staged' => 'Unraid update waiting for restart', 'os.available' => 'Unraid update available', 'os.check_failed' => 'Unraid update check failed',
          'api.error' => 'Unraid API problem', 'auth.open' => 'No dashboard password', 'health.down' => 'Endpoint not answering'];
}

const UND_IGNORE_MAX = 500;
const UND_IGNORE_MIN_S = 300;            // 5 minutes
const UND_IGNORE_MAX_S = 366 * 86400;    // a year; longer than that is "permanently"


/** Valid rules only: anything malformed in the file is dropped instead of breaking the dashboard. */
function und_ignore_clean($list) {
  $cats = und_alert_categories(); $out = []; $seen = [];
  foreach (is_array($list) ? $list : [] as $r) {
    if (!is_array($r)) continue;
    $kind = (string)($r['kind'] ?? ''); $cat = (string)($r['category'] ?? ''); $res = (string)($r['resource'] ?? '');
    if (!isset($cats[$cat]) || !in_array($kind, ['instance', 'type'], true)) continue;
    if ($kind === 'instance' && !und_ignore_res_ok($res)) continue;
    $key = $kind === 'type' ? $cat : $cat . ':' . $res;
    $id = (string)($r['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{12}$/', $id) || isset($seen[$id]) || isset($seen["$kind|$key"])) continue;
    $until = $r['until'] ?? null;
    $out[] = ['id' => $id, 'kind' => $kind, 'key' => $key, 'category' => $cat, 'resource' => $kind === 'type' ? '' : $res,
              'until' => $until === null ? null : (int)$until, 'created' => (int)($r['created'] ?? 0),
              'title' => und_clip((string)($r['title'] ?? ''), 200), 'severity' => in_array($r['severity'] ?? '', ['bad', 'warn', 'info'], true) ? $r['severity'] : 'warn'];
    $seen[$id] = 1; $seen["$kind|$key"] = 1;
  }
  return array_slice($out, 0, UND_IGNORE_MAX);
}

/** Container names, disk names, versions, array states: letters, digits and a few separators. */
function und_ignore_res_ok($res) { return is_string($res) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+@ -]{0,127}$/', $res) === 1; }

function und_ignores() { return und_ignore_clean(und_doc()['ignored_alerts'] ?? []); }

/** The rules still in force at $now (what the live refresh sends with every answer). */
function und_ignores_active($now = null) {
  $now = $now ?? time();
  return array_values(array_filter(und_ignores(), function ($r) use ($now) { return $r['until'] === null || $r['until'] > $now; }));
}

/** Read, change and write the rules under one lock, so two browser tabs cannot lose each other's changes. */
function und_ignore_edit(callable $fn) {
  und_config_lock();
  $list = und_ignores();
  $res = $fn($list);
  if (isset($res['error'])) return $res;
  // keep expired rules a month so Settings can show them, then let them go
  $old = time() - 30 * 86400;
  $list = array_values(array_filter($list, function ($r) use ($old) { return $r['until'] === null || $r['until'] > $old; }));
  $clean = und_ignore_clean($list);
  if (!und_doc_write(['ignored_alerts' => $clean ? $clean : null])) return ['error' => 'The ignore list could not be saved.'];
  return $res + ['ok' => true];
}

/** duration: seconds, or "permanent". Ignoring the same alert again replaces the old rule. */
function und_ignore_add($kind, $cat, $res, $duration, $title = '', $severity = 'warn') {
  $cats = und_alert_categories();
  if (!in_array($kind, ['instance', 'type'], true)) return ['error' => 'Unknown ignore scope.'];
  if (!isset($cats[$cat])) return ['error' => 'This alert cannot be ignored.'];
  if ($kind === 'instance' && !und_ignore_res_ok($res)) return ['error' => 'Unknown alert.'];
  if ($duration === 'permanent') $until = null;
  elseif (is_numeric($duration) && (int)$duration == $duration && $duration >= UND_IGNORE_MIN_S && $duration <= UND_IGNORE_MAX_S) $until = time() + (int)$duration;
  else return ['error' => 'Choose a duration between 5 minutes and a year, or permanently.'];
  $key = $kind === 'type' ? $cat : $cat . ':' . $res;
  return und_ignore_edit(function (&$list) use ($kind, $cat, $res, $key, $until, $title, $severity) {
    $list = array_values(array_filter($list, function ($r) use ($kind, $key) { return !($r['kind'] === $kind && $r['key'] === $key); }));
    if (count($list) >= UND_IGNORE_MAX) return ['error' => 'Too many ignored alerts. Restore or delete some in Settings first.'];
    $rule = ['id' => bin2hex(random_bytes(6)), 'kind' => $kind, 'key' => $key, 'category' => $cat, 'resource' => $kind === 'type' ? '' : $res,
             'until' => $until, 'created' => time(), 'title' => und_clip((string)$title, 200), 'severity' => in_array($severity, ['bad', 'warn', 'info'], true) ? $severity : 'warn'];
    $list[] = $rule;
    return ['rule' => $rule];
  });
}

/** Remove the rules with these ids. Unknown ids are ignored, so a stale tab cannot remove anything else. */
function und_ignore_remove(array $ids) {
  $ids = array_values(array_filter($ids, function ($i) { return is_string($i) && preg_match('/^[a-f0-9]{12}$/', $i); }));
  if (!$ids) return ['error' => 'Nothing to restore.'];
  return und_ignore_edit(function (&$list) use ($ids) {
    $n = count($list);
    $list = array_values(array_filter($list, function ($r) use ($ids) { return !in_array($r['id'], $ids, true); }));
    return ['removed' => $n - count($list)];
  });
}

function und_ignore_restore_all() {
  return und_ignore_edit(function (&$list) { $n = count($list); $list = []; return ['removed' => $n]; });
}

function und_ignore_purge_expired() {
  return und_ignore_edit(function (&$list) {
    $now = time(); $n = count($list);
    $list = array_values(array_filter($list, function ($r) use ($now) { return $r['until'] === null || $r['until'] > $now; }));
    return ['removed' => $n - count($list)];
  });
}
