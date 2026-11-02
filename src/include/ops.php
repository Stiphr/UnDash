<?php
/*
 * UnDash - operations: one-at-a-time locks per target, the recent-operation history, and server power.
 * Every management action is a fixed operation on a validated target; nothing here takes a command from the browser.
 */

/** Exclusive, non-blocking lock for one target ("docker:plex", "vm:Windows 10", "power"). Returns a handle, or false when busy. */
function und_lock($target) {
  @mkdir(und_tmp('locks'), 0755, true);
  $fp = @fopen(und_tmp('locks/' . hash('sha256', $target) . '.lock'), 'c');
  if (!$fp) return false;
  if (flock($fp, LOCK_EX | LOCK_NB)) return $fp;
  fclose($fp);
  return false;
}
function und_unlock($fp) { if (is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); } }

/** The recent-operation history, kept in /config because /tmp does not survive a restart. */
function und_history_file() { return und_data_dir() . '/history.json'; }

/** Append to the history (newest 100). Messages are redacted. */
function und_history_add($kind, $target, $op, $ok, $msg = '') {
  $f = und_history_file();
  $fp = @fopen($f . '.lock', 'c');
  if ($fp) flock($fp, LOCK_EX);
  $h = und_read_json($f, []);
  $h[] = ['at' => time(), 'kind' => $kind, 'target' => und_clip($target, 120), 'op' => $op, 'ok' => (bool)$ok, 'msg' => und_clip(und_redact($msg), 300)];
  und_write_json($f, array_slice($h, -100));
  if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
}
function und_history() { return array_reverse(und_read_json(und_history_file(), [])); }

/* ---------- is there a newer UnDash? ---------- */

/**
 * The newest release tag (vX.Y.Z) of the UnDash repository, checked at most every 6 hours (a failed check is retried
 * after an hour). Nothing is sent but the request itself.
 */
function und_self_update() {
  $f = und_tmp('self-update.json');
  $c = und_read_json($f, null);
  $fresh = is_array($c) && time() - (int)($c['at'] ?? 0) < (!empty($c['error']) ? 3600 : 21600);
  if (!$fresh) {
    $c = ['at' => time(), 'latest' => '', 'error' => ''];
    $r = und_http('GET', 'https://api.github.com/repos/Stiphr/UnDash/tags?per_page=30', ['timeout' => 6, 'headers' => ['Accept' => 'application/vnd.github+json']]);
    if ($r['code'] !== 200 || !is_array($r['json'])) $c['error'] = $r['err'] ?: 'GitHub answered HTTP ' . $r['code'];
    else foreach ($r['json'] as $t) {
      $v = preg_match('/^v?(\d+\.\d+\.\d+)$/', (string)($t['name'] ?? ''), $m) ? $m[1] : '';
      if ($v !== '' && ($c['latest'] === '' || version_compare($v, $c['latest'], '>'))) $c['latest'] = $v;
    }
    und_write_json($f, $c);
  }
  $cur = UND_VERSION;
  $semver = preg_match('/^\d+\.\d+\.\d+$/', $cur) === 1;
  return ['current' => $cur, 'latest' => $c['latest'], 'newer' => $semver && $c['latest'] !== '' && version_compare($c['latest'], $cur, '>'),
          'checked' => (int)$c['at'], 'error' => $c['error'], 'url' => 'https://github.com/Stiphr/UnDash/releases'];
}
