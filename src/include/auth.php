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

if (!defined('UND_SESS_TTL')) define('UND_SESS_TTL', 12 * 3600);

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

/** After a password change: every other browser has to sign in again (sessions opened without a password included). */
function und_sess_drop_others() {
  $keep = und_sess_file((string)($_COOKIE['undash_sid'] ?? ''));
  foreach (glob(und_sess_dir() . '/*.json') ?: [] as $f) if ($f !== $keep) @unlink($f);
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

/**
 * May this host name reach a dashboard that has no password (Docker version, before or without one)?
 * Without a password anyone who reaches the port is signed in, so a web page could point a host name of its own at the
 * server's address (DNS rebinding) and drive the dashboard from the visitor's browser. Allowed: IP addresses, localhost,
 * the server's own name (alone or with a local suffix) and the names in UNDASH_ALLOWED_HOSTS (tower.example.com, *.example.com).
 * A password makes this moot: a rebound page has no session and cannot sign in.
 */
function und_host_allowed($host = null) {
  $h = $host === null ? und_req_host() : strtolower((string)$host);
  if ($h === '') return false;
  if ($h === 'localhost' || filter_var(trim($h, '[]'), FILTER_VALIDATE_IP)) return true;
  foreach (preg_split('/[\s,]+/', strtolower((string)getenv('UNDASH_ALLOWED_HOSTS')), -1, PREG_SPLIT_NO_EMPTY) as $a) {
    $a = preg_replace('/:\d+$/', '', $a);
    if ($a === $h || (strpos($a, '*.') === 0 && substr($h, -strlen($a) + 1) === substr($a, 1) && strlen($h) > strlen($a) - 1)) return true;
  }
  $names = [strtolower((string)getenv('HOST_HOSTNAME'))];
  if (function_exists('und_server_identity')) { $id = und_server_identity(); $names[] = strtolower((string)($id['name'] ?? '')); }
  foreach (array_unique(array_filter($names)) as $n) {
    if ($h === $n) return true;
    foreach (['.local', '.lan', '.home', '.internal', '.localdomain', '.home.arpa'] as $sfx) if ($h === $n . $sfx) return true;
  }
  return false;
}

/** Is this browser logged in to Unraid? Reads Unraid's PHP session file (read-only, so Unraid's session is never touched). */
function und_unraid_logged_in() {
  if (und_api_mode()) return false;            // a container cannot see Unraid's sessions
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
  if (und_api_mode()) return false;
  if (!preg_match('/^[a-f0-9]{48}$/', (string)$t)) return false;
  $f = und_tmp('handoff') . '/' . hash('sha256', $t);
  $exp = (int)@file_get_contents($f);
  @unlink($f);                                   // single use
  return $exp >= time();
}

/* ---------- password sign-in (Docker version: there is no Unraid session to reuse) ---------- */

function und_setup_file() { return UND_DIR . '/setup-code.txt'; }

/** The password hash (or the choice to go without one) is kept in .env as UNDASH_PASSWORD_HASH / UNDASH_AUTH=open. */
function und_auth_set(array $set) {
  und_config_lock();
  $env = und_env();
  unset($env['UNDASH_PASSWORD_HASH'], $env['UNDASH_AUTH']);
  return und_env_write($env + $set);
}

/** none = not set up yet (first start), env = UNDASH_PASSWORD, file = password set in the dashboard,
 *  open = the owner chose to go without a password for now (anyone who reaches the port is signed in). */
function und_auth_kind() {
  if ((string)getenv('UNDASH_PASSWORD') !== '') return 'env';
  $e = und_env();
  return !empty($e['UNDASH_PASSWORD_HASH']) ? 'file' : (($e['UNDASH_AUTH'] ?? '') === 'open' ? 'open' : 'none');
}

/** Skip the password (setup page) or remove it later (Settings). A password can be added again at any time. */
function und_auth_open() {
  if (und_auth_kind() === 'env') return ['error' => 'The password comes from UNDASH_PASSWORD in the container settings; remove it there.'];
  if (!und_auth_set(['UNDASH_AUTH' => 'open'])) return ['error' => 'Could not write the .env file. Check the /config volume.'];
  @unlink(und_setup_file());
  return ['ok' => true];
}

function und_password_ok($pw) {
  $pw = (string)$pw;
  if (und_auth_kind() === 'env') return hash_equals((string)getenv('UNDASH_PASSWORD'), $pw);
  $h = (string)(und_env()['UNDASH_PASSWORD_HASH'] ?? '');
  return $h !== '' && password_verify($pw, $h);
}

function und_password_set($pw) {
  if (strlen((string)$pw) < 10) return ['error' => 'Use at least 10 characters.'];
  if (!und_auth_set(['UNDASH_PASSWORD_HASH' => password_hash((string)$pw, PASSWORD_DEFAULT)])) return ['error' => 'Could not write the .env file. Check the /config volume.'];
  @unlink(und_setup_file());
  return ['ok' => true];
}

/** The one-time code that proves the person creating the password can read the container log. Created at container start. */
function und_setup_code_ensure() {
  if (und_auth_kind() !== 'none') { @unlink(und_setup_file()); return null; }
  $c = trim((string)@file_get_contents(und_setup_file()));
  if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $c)) {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $c = '';
    for ($i = 0; $i < 12; $i++) $c .= ($i && $i % 4 === 0 ? '-' : '') . $abc[random_int(0, strlen($abc) - 1)];
    @file_put_contents(und_setup_file(), $c); @chmod(und_setup_file(), 0600);
  }
  return $c;
}

/** At most 5 wrong passwords per address in 15 minutes, and 30 from all addresses together (a guess spread over many
 *  addresses, or every user behind one reverse proxy, is slowed down too). */
function und_login_recent($f) { return array_values(array_filter(und_read_json($f, []), function ($t) { return $t > time() - 900; })); }
function und_login_throttled($ip) {
  return count(und_login_recent(und_tmp('login-' . hash('sha256', (string)$ip) . '.json'))) >= 5 || count(und_login_recent(und_tmp('login-all.json'))) >= 30;
}
function und_login_failed($ip) {
  foreach ([und_tmp('login-' . hash('sha256', (string)$ip) . '.json'), und_tmp('login-all.json')] as $f) {
    $a = und_login_recent($f); $a[] = time(); und_write_json($f, array_slice($a, -100), true);
  }
}
