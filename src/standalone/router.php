<?php
/*
 * UnDash - the standalone port (default 9099), started by scripts/rc.undash with PHP's built-in web server:
 *   php -S <BIND>:<PORT> -t standalone standalone/router.php
 * Serves the full-window dashboard, its assets and the API. Every request except the sign-in redirect needs a session
 * (see include/auth.php); every API call also needs the session's CSRF token and a same-origin request.
 */
if (!defined('UND_ROOT')) define('UND_ROOT', dirname(__DIR__));
require_once UND_ROOT . '/include/lib.php';
require_once UND_ROOT . '/include/auth.php';

$uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' http: https: data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self' https:");

/** A static file: revalidated by ETag, cached for good when the URL carries its version (?v=), compressed when the browser accepts it. */
function sa_asset($file, $type) {
  $v = (string)@filemtime($file);
  $etag = '"' . $v . '-' . (int)@filesize($file) . '"';
  header('Content-Type: ' . $type);
  header('ETag: ' . $etag);
  header('Cache-Control: ' . (($_GET['v'] ?? '') === $v ? 'public, max-age=31536000, immutable' : 'no-cache'));
  if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
  if (function_exists('ob_gzhandler')) ob_start('ob_gzhandler');
  readfile($file); exit;
}

function sa_deny($code, $msg) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }

/** Where Unraid's web UI is: same host, its own port (80/443). nginx redirects http to https when Unraid requires it. */
function sa_unraid_base() { $h = und_req_host(); return $h === '' ? '' : 'http://' . $h; }

/** The login / setup page (Docker version). Plain HTML, no script, so the strict CSP still applies. */
function sa_form_page($title, $intro, $fields, $error = '', $skip = false) {
  $pre = bin2hex(random_bytes(16));
  setcookie('undash_pre', $pre, ['expires' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
  $e = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); };
  header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UnDash · ', $e($title), '</title>',
       '<link rel="icon" href="/favicon.png"><link rel="stylesheet" href="/assets/app.css"></head><body class="und-page"><div class="und"><form class="login card" method="post">',
       '<div class="brand"><img src="/favicon.png" alt="" width="40" height="40"><h1 class="und-name">', $e($title), '</h1></div><p class="muted">', $e($intro), '</p>';
  if ($error !== '') echo '<div class="banner bad" role="alert">', $e($error), '</div>';
  foreach ($fields as $f) echo '<div class="fld"><label for="', $f[0], '">', $e($f[1]), '</label><input id="', $f[0], '" name="', $f[0], '" type="', $f[2], '" autocomplete="', $f[3], '" required', $f[0] === $fields[0][0] ? ' autofocus' : '', '></div>';
  echo '<input type="hidden" name="pre" value="', $pre, '"><button class="btn pri" type="submit">Set password and continue</button>';
  if ($skip) {
    // a second form, so skipping never needs the code or the password fields
    echo '</form><form class="login card skip" method="post"><input type="hidden" name="pre" value="', $pre, '"><input type="hidden" name="skip" value="1">',
         '<p class="muted"><b>Recommended:</b> set a password. Without one, anyone who can open this address can see and control your containers and VMs. ',
         'You can add a password later in Settings &gt; Configuration.</p><button class="btn" type="submit">Continue without a password</button>';
  }
  echo '</form></div></body></html>';
  exit;
}
function sa_pre_ok() { $c = (string)($_COOKIE['undash_pre'] ?? ''); return $c !== '' && hash_equals($c, (string)($_POST['pre'] ?? '')); }
function sa_ip() { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }

/* ---- health check (Docker HEALTHCHECK): says nothing about the server ---- */
if ($uri === '/health') { header('Content-Type: text/plain'); header('Cache-Control: no-store'); echo "ok\n"; exit; }
if ($uri === '/assets/inter.woff2') {
  // the bundled Inter font (SIL Open Font License); public, needed by the sign-in page too
  header('Content-Type: font/woff2'); header('Cache-Control: public, max-age=31536000, immutable');
  readfile(UND_ROOT . '/assets/inter.woff2'); exit;
}
if ($uri === '/assets/app.css' || $uri === '/favicon.png') {
  // needed by the sign-in page too; nothing secret in either
  if ($uri === '/favicon.png') sa_asset(UND_ROOT . '/images/undash.png', 'image/png');
  sa_asset(UND_ROOT . $uri, 'text/css; charset=utf-8');
}

/* ---- without a password, only known host names (see und_host_allowed: DNS rebinding) ---- */
if (und_api_mode() && in_array(und_auth_kind(), ['none', 'open'], true) && !und_host_allowed())
  sa_deny(421, "UnDash has no password yet, so it only answers at the server's IP address or name (http://<server IP>:<port>).\n"
    . "To use the name " . (und_req_host() ?: 'in the address bar') . ": set a password in Settings > Connection, or add the name to UNDASH_ALLOWED_HOSTS in the container settings.");

/* ---- sign-in ---- */
$sess = und_sess_get();
if (!$sess && und_api_mode()) {
  $kind = und_auth_kind();
  if ($kind === 'none') {
    if ($uri !== '/setup') { if ($method === 'GET' && $uri === '/') { header('Location: /setup', true, 303); exit; } sa_deny(401, 'Not signed in.'); }
    $err = '';
    if ($method === 'POST' && ($_POST['skip'] ?? '') === '1') {
      // going without a password cannot lock anybody out (a password can be set later), so it needs no setup code
      if (!sa_pre_ok()) $err = 'The form expired. Try again.';
      else { $r = und_auth_open(); if (isset($r['error'])) $err = $r['error']; else { error_log('undash: dashboard password skipped from ' . sa_ip()); und_sess_new('open'); header('Location: /', true, 303); exit; } }
    } elseif ($method === 'POST') {
      $code = trim((string)@file_get_contents(und_setup_file()));
      if (!sa_pre_ok()) $err = 'The form expired. Try again.';
      elseif (und_login_throttled(sa_ip())) $err = 'Too many attempts. Wait 15 minutes.';
      elseif ($code === '' || !hash_equals($code, strtoupper(trim((string)($_POST['code'] ?? ''))))) { und_login_failed(sa_ip()); $err = 'The setup code is wrong. It is in the container log.'; }
      elseif ((string)($_POST['password'] ?? '') !== (string)($_POST['password2'] ?? '')) $err = 'The passwords do not match.';
      else { $r = und_password_set((string)$_POST['password']); if (isset($r['error'])) $err = $r['error']; else { und_sess_new('setup'); header('Location: /', true, 303); exit; } }
    }
    sa_form_page('Set up UnDash', 'Choose the password for this dashboard. The setup code is printed in the container log (Docker tab > UnDash > Logs).',
      [['code', 'Setup code', 'text', 'one-time-code'], ['password', 'New password (10+ characters)', 'password', 'new-password'], ['password2', 'Repeat the password', 'password', 'new-password']], $err, true);
  }
  if ($kind === 'open') { $sess = und_sess_new('open'); }   // no password chosen: everyone who reaches the port is signed in
  else {
  if ($uri === '/login' || ($method === 'GET' && $uri === '/')) {
    $err = '';
    if ($method === 'POST' && $uri === '/login') {
      if (!sa_pre_ok()) $err = 'The form expired. Try again.';
      elseif (und_login_throttled(sa_ip())) $err = 'Too many wrong passwords. Wait 15 minutes.';
      elseif (!und_password_ok((string)($_POST['password'] ?? ''))) { und_login_failed(sa_ip()); usleep(400000); $err = 'Wrong password.'; error_log('undash: failed sign-in from ' . sa_ip()); }
      else { und_sess_new('password'); header('Location: /', true, 303); exit; }
    } elseif ($uri === '/') { header('Location: /login', true, 303); exit; }
    sa_form_page('UnDash', 'Sign in to the dashboard.', [['password', 'Password', 'password', 'current-password']], $err);
  }
  sa_deny(401, 'Not signed in.');
  }
}
if (!$sess && $method === 'GET' && isset($_GET['t'])) {
  if (und_handoff_use((string)$_GET['t'])) { und_sess_new('handoff'); header('Location: /', true, 303); exit; }
  sa_deny(403, 'That sign-in link expired. Open UnDash from Unraid again.');
}
if (!$sess && und_unraid_logged_in()) $sess = und_sess_new('unraid');
if (!$sess) {
  if ($method === 'GET' && ($uri === '/' || $uri === '/index.html')) {
    $base = sa_unraid_base();
    if ($base === '') sa_deny(400, 'Bad host name.');
    // behind Unraid's login: it creates a one-time token and sends the browser back here
    header('Location: ' . $base . '/plugins/undash/handoff.php', true, 303);
    exit;
  }
  sa_deny(401, 'Not signed in. Open UnDash from Unraid first.');
}

/* ---- routes ---- */
if ($sess && $method === 'GET' && ($uri === '/setup' || $uri === '/login')) { header('Location: /', true, 303); exit; }
if ($uri === '/logout') { und_sess_end(); header('Location: ' . (und_api_mode() ? '/login' : sa_unraid_base() . '/'), true, 303); exit; }

if ($uri === '/api') {
  if ($method !== 'POST') sa_deny(405, 'POST only.');
  $tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
  if (!hash_equals($sess['csrf'], $tok)) sa_deny(403, 'Bad CSRF token. Reload the page.');
  // a cross-site page cannot read the token, but refuse foreign origins anyway
  $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
  if ($origin !== '' && strcasecmp(preg_replace('#^https?://#', '', $origin), (string)($_SERVER['HTTP_HOST'] ?? '')) !== 0) sa_deny(403, 'Cross-origin request refused.');
  unset($_POST['csrf_token']);
  define('UND_STANDALONE', 1);
  require UND_ROOT . '/include/api.php';
  exit;
}

if ($uri === '/assets/app.js') sa_asset(UND_ROOT . $uri, 'application/javascript; charset=utf-8');
if ($uri === '/assets/boot.js') {
  // page settings as a script file, so the page needs no inline script (the CSP forbids it)
  header('Content-Type: application/javascript; charset=utf-8');
  header('Cache-Control: no-store');
  $unraid = und_api_mode() ? preg_replace('#/graphql$#', '', und_api_conf()['url']) : sa_unraid_base();
  echo 'window.UND_API="/api";window.UND_CSRF=' . json_encode($sess['csrf']) . ';window.UND_UNRAID=' . json_encode($unraid) . ';window.UND_STANDALONE=true;';
  exit;
}
if ($uri === '/images/undash.png') { header('Content-Type: image/png'); header('Cache-Control: max-age=86400'); readfile(UND_ROOT . '/images/undash.png'); exit; }

// container and VM icons that Unraid itself serves from these two folders
$icons = ['/state/plugins/dynamix.docker.manager/images/' => '/usr/local/emhttp/state/plugins/dynamix.docker.manager/images/',
          '/plugins/dynamix.vm.manager/templates/images/' => '/usr/local/emhttp/plugins/dynamix.vm.manager/templates/images/'];
foreach ($icons as $prefix => $dir) {
  if (strpos($uri, $prefix) !== 0) continue;
  $name = rawurldecode(substr($uri, strlen($prefix)));
  if (!preg_match('/^[A-Za-z0-9_. -]+\.(png|svg|jpg)$/', $name) || strpos($name, '..') !== false || !is_file($dir . $name)) sa_deny(404, 'Not found');
  $ext = pathinfo($name, PATHINFO_EXTENSION);
  header('Content-Type: ' . ($ext === 'svg' ? 'image/svg+xml' : ($ext === 'jpg' ? 'image/jpeg' : 'image/png')));
  header('Cache-Control: max-age=3600');
  if ($ext === 'svg') header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
  readfile($dir . $name); exit;
}

if ($uri === '/' || $uri === '/index.html') {
  $v = (int)@filemtime(UND_ROOT . '/assets/app.js');
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0b0c0f">',
       '<title>UnDash</title><link rel="icon" href="/favicon.png"><link rel="stylesheet" href="/assets/app.css?v=', $v, '">',
       '</head><body class="und-page"><div id="und" class="und"><div class="und-loading">Loading UnDash…</div></div>',
       '<script src="/assets/boot.js"></script><script src="/assets/app.js?v=', $v, '"></script></body></html>';
  exit;
}
sa_deny(404, 'Not found');
