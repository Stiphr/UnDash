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

/** The recent-operation history: in RAM in the plugin (no flash wear), in /config in the Docker version, where /tmp is lost with every image update. */
function und_history_file() { return und_api_mode() ? und_data_dir() . '/history.json' : und_tmp('history.json'); }

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

/* ---------- server power ---------- */

/**
 * Restart or shut down the server with the same commands Unraid's own Reboot / Shutdown buttons run
 * (webGui/include/Boot.php: /sbin/reboot -n and /sbin/poweroff -n; these stop the array cleanly first).
 *
 * Two steps so a single stray request can never power the server off:
 *   power_prepare -> a one-time nonce bound to the operation, valid 120 s, plus anything that makes it a bad moment
 *   power         -> needs that nonce and the server's name typed back; the command starts 3 s later so the answer reaches the browser
 */
function und_power_prepare($op) {
  if (und_api_mode()) return ['error' => 'The Unraid API has no restart or shutdown. Use Unraid\'s own Reboot / Shutdown buttons (or the UnDash plugin).'];
  if (!in_array($op, ['reboot', 'shutdown'], true)) return ['error' => 'Unknown power action.'];
  $nonce = bin2hex(random_bytes(16));
  und_write_json(und_tmp('power-nonce.json'), ['op' => $op, 'hash' => hash('sha256', $nonce), 'exp' => time() + 120], true);
  $v = und_var_ini(); $warn = [];
  if ((int)($v['mdResync'] ?? 0) > 0) $warn[] = 'A parity check, sync or rebuild is running; it will be interrupted.';
  if (is_file('/var/run/mover.pid')) $warn[] = 'The mover is running.';
  $vm = und_vm_states(); $running = [];
  foreach ($vm as $n => $s) if ($s === 'running') $running[] = $n;
  if ($running) $warn[] = 'Running VMs (' . implode(', ', $running) . ') will be shut down.';
  return ['nonce' => $nonce, 'op' => $op, 'name' => (string)($v['NAME'] ?? gethostname()), 'warnings' => $warn];
}

function und_power($op, $nonce, $typed) {
  if (und_api_mode()) return ['error' => 'Not available in the Docker version.'];
  $n = und_read_json(und_tmp('power-nonce.json'), []);
  @unlink(und_tmp('power-nonce.json'));                       // single use, whatever happens next
  if (!in_array($op, ['reboot', 'shutdown'], true) || ($n['op'] ?? '') !== $op) return ['error' => 'Unknown power action.'];
  if (empty($n['hash']) || !hash_equals((string)$n['hash'], hash('sha256', (string)$nonce)) || time() > (int)($n['exp'] ?? 0)) return ['error' => 'The confirmation expired. Start again.'];
  $name = (string)(und_var_ini()['NAME'] ?? gethostname());
  if (strcasecmp(trim((string)$typed), $name) !== 0) return ['error' => 'The server name you typed does not match.'];
  $lock = und_lock('power');
  if (!$lock) return ['error' => 'A power action is already in progress.'];
  if (is_file(und_tmp('power-started'))) return ['error' => 'The server is already ' . ($op === 'reboot' ? 'restarting' : 'shutting down') . '.'];
  @file_put_contents(und_tmp('power-started'), $op);
  und_history_add('server', $name, $op, true, 'requested from UnDash');
  if (function_exists('und_log_syslog')) und_log_syslog("server $op requested from UnDash");
  $cmd = $op === 'reboot' ? '/sbin/reboot' : '/sbin/poweroff';
  und_spawn(['/bin/bash', '-c', 'sleep 3; exec ' . $cmd . ' -n'], und_tmp('power.log'));
  return ['ok' => true, 'op' => $op];
}

/** A line in the system log (Tools > System Log), so power actions are visible outside UnDash too. */
function und_log_syslog($msg) {
  if (isset($GLOBALS['UND_EXEC_MOCK'])) return;
  und_run([und_bin('logger'), '-t', 'undash', '--', $msg], 5);
}

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
