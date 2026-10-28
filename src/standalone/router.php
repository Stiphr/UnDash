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
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self' https:");

function sa_deny($code, $msg) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }

/** Where Unraid's web UI is: same host, its own port (80/443). nginx redirects http to https when Unraid requires it. */
function sa_unraid_base() { $h = und_req_host(); return $h === '' ? '' : 'http://' . $h; }

/* ---- sign-in ---- */
$sess = und_sess_get();
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
if ($uri === '/logout') { und_sess_end(); header('Location: ' . sa_unraid_base() . '/', true, 303); exit; }

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

if ($uri === '/assets/app.css' || $uri === '/assets/app.js') {
  $f = UND_ROOT . $uri;
  header('Content-Type: ' . (substr($uri, -3) === 'css' ? 'text/css' : 'application/javascript') . '; charset=utf-8');
  header('Cache-Control: no-cache');
  readfile($f); exit;
}
if ($uri === '/assets/boot.js') {
  // page settings as a script file, so the page needs no inline script (the CSP forbids it)
  header('Content-Type: application/javascript; charset=utf-8');
  header('Cache-Control: no-store');
  echo 'window.UND_API="/api";window.UND_CSRF=' . json_encode($sess['csrf']) . ';window.UND_UNRAID=' . json_encode(sa_unraid_base()) . ';window.UND_STANDALONE=true;';
  exit;
}
if ($uri === '/favicon.png' || $uri === '/images/undash.png') { header('Content-Type: image/png'); header('Cache-Control: max-age=86400'); readfile(UND_ROOT . '/images/undash.png'); exit; }

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
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">',
       '<title>UnDash</title><link rel="icon" href="/favicon.png"><link rel="stylesheet" href="/assets/app.css?v=', $v, '">',
       '</head><body class="und-page"><div id="und" class="und"><div class="und-loading">Loading UnDash…</div></div>',
       '<script src="/assets/boot.js"></script><script src="/assets/app.js?v=', $v, '"></script></body></html>';
  exit;
}
sa_deny(404, 'Not found');
