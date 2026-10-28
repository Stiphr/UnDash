<?php
/*
 * UnDash - sign-in for the standalone port (default 9099).
 *
 * The standalone port is served outside Unraid's nginx, so it checks the login itself. A browser is let in when
 *   a) it carries a valid Unraid login session (cookie "unraid_<md5 of the host name>", the same one Unraid's own
 *      auth-request.php checks; cookies are shared across ports of the same host), or
 *   b) it was handed over from Unraid's web UI: /plugins/undash/handoff.php (behind Unraid's login) creates a one-time
 *      token valid for 60 s, and the standalone port swaps it for its own session cookie.
 * Either way UnDash then keeps its own session (HttpOnly, SameSite=Strict, 12 h, renewed while used) with its own CSRF token.
 */

define('UND_SESS_TTL', 12 * 3600);

function und_sess_dir() { $d = und_tmp('sess'); @mkdir($d, 0700, true); return $d; }

function und_sess_file($sid) { return und_sess_dir() . '/' . hash('sha256', (string)$sid) . '.json'; }

/** The UnDash session of this request, or null. */
function und_sess_get() {
  $sid = (string)($_COOKIE['undash_sid'] ?? '');
  if (!preg_match('/^[a-f0-9]{64}$/', $sid)) return null;
  $s = und_read_json(und_sess_file($sid), null);
  if (!is_array($s) || (int)($s['exp'] ?? 0) < time()) { @unlink(und_sess_file($sid)); return null; }
  if ((int)$s['exp'] - time() < UND_SESS_TTL - 600) { $s['exp'] = time() + UND_SESS_TTL; und_write_json(und_sess_file($sid), $s, true); }
  return $s;
}

function und_sess_new($how) {
  $sid = bin2hex(random_bytes(32));
  $s = ['exp' => time() + UND_SESS_TTL, 'csrf' => bin2hex(random_bytes(24)), 'how' => $how, 'at' => time()];
  und_write_json(und_sess_file($sid), $s, true);
  setcookie('undash_sid', $sid, ['expires' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
  // old sessions are removed now and then
  if (random_int(1, 20) === 1) foreach (glob(und_sess_dir() . '/*.json') ?: [] as $f) if (filemtime($f) < time() - UND_SESS_TTL - 3600) @unlink($f);
  return $s;
}

function und_sess_end() {
  $sid = (string)($_COOKIE['undash_sid'] ?? '');
  if (preg_match('/^[a-f0-9]{64}$/', $sid)) @unlink(und_sess_file($sid));
  setcookie('undash_sid', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
}

/** The host name of this request without the port, or '' when it does not look like one. */
function und_req_host() {
  $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
  $h = preg_replace('/:\d+$/', '', $h);
  if (preg_match('/^\[[0-9a-f:]+\]$/', $h)) return $h;
  return preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $h) ? $h : '';
}

/** Is this browser logged in to Unraid? Reads Unraid's PHP session file (read-only, so Unraid's session is never touched). */
function und_unraid_logged_in() {
  $host = und_req_host();
  if ($host === '') return false;
  $sid = (string)($_COOKIE['unraid_' . md5($host)] ?? '');
  if (!preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sid)) return false;
  $path = isset($GLOBALS['UND_PHPSESS_DIR']) ? $GLOBALS['UND_PHPSESS_DIR'] : (ini_get('session.save_path') ?: '/var/lib/php');
  $f = rtrim(preg_replace('/^\d+;/', '', $path), '/') . '/sess_' . $sid;
  $data = @file_get_contents($f);
  if (!is_string($data) || !preg_match('/(^|;|\})unraid_login\|i:(\d+);/', $data, $m)) return false;
  // Unraid's session lifetime (php.ini session.gc_maxlifetime) bounds how old a login may be
  $max = (int)(ini_get('session.gc_maxlifetime') ?: 172800);
  return (int)@filemtime($f) > time() - $max;
}

/* ---------- handoff from Unraid's web UI ---------- */

function und_handoff_create() {
  $t = bin2hex(random_bytes(24));
  $d = und_tmp('handoff'); @mkdir($d, 0700, true);
  foreach (glob("$d/*") ?: [] as $f) if (filemtime($f) < time() - 120) @unlink($f);
  @file_put_contents("$d/" . hash('sha256', $t), (string)(time() + 60));
  return $t;
}

function und_handoff_use($t) {
  if (!preg_match('/^[a-f0-9]{48}$/', (string)$t)) return false;
  $f = und_tmp('handoff') . '/' . hash('sha256', $t);
  $exp = (int)@file_get_contents($f);
  @unlink($f);                                   // single use
  return $exp >= time();
}
