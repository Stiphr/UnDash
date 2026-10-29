<?php
/* End-to-end test of the Docker version's own sign-in: setup with the code, skipping the password, and setting, changing
 * and removing it later from Settings. php tests/docker_auth_test.php */
$SB = sys_get_temp_dir() . '/undash-da-' . getmypid();
foreach (["$SB/flash", "$SB/tmp", "$SB/php", "$SB/fs/var/local/emhttp"] as $d) @mkdir($d, 0777, true);
$port = 9800 + getmypid() % 150;
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/router_harness.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
  ['UND_SANDBOX' => $SB, 'UNDASH_MODE' => 'api', 'PATH' => getenv('PATH')]);
usleep(600000);
$pass = 0; $fail = 0;
function t($n, $c, $d = '') { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "FAIL $n" . ($d !== '' ? " -- $d" : '') . "\n"; } }
/** A tiny browser: keeps cookies, does not follow redirects. */
$JAR = [];
function req($method, $path, $form = null, $headers = []) {
  global $port, $JAR;
  $ck = []; foreach ($JAR as $k => $v) $ck[] = "$k=$v";
  $h = array_merge(['Host: nas.local:9099'], $ck ? ['Cookie: ' . implode('; ', $ck)] : [], $form !== null ? ['Content-Type: application/x-www-form-urlencoded'] : [], $headers);
  $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $form === null ? null : http_build_query($form), 'ignore_errors' => true, 'follow_location' => 0]]);
  $out = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
  $hh = $http_response_header ?? []; preg_match('#HTTP/\S+ (\d+)#', $hh[0] ?? '', $m);
  $loc = '';
  foreach ($hh as $l) {
    if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $l, $c)) { if ($c[2] === '' || $c[2] === 'deleted') unset($JAR[$c[1]]); else $JAR[$c[1]] = $c[2]; }
    if (preg_match('/^Location:\s*(\S+)/i', $l, $c)) $loc = $c[1];
  }
  return ['code' => (int)($m[1] ?? 0), 'body' => (string)$out, 'loc' => $loc];
}
function pre($html) { return preg_match('/name="pre" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : ''; }
function csrf() { $r = req('GET', '/assets/boot.js'); return preg_match('/UND_CSRF="([a-f0-9]+)"/', $r['body'], $m) ? $m[1] : ''; }
function apicall($action, $data = []) { $c = csrf(); $r = req('POST', '/api', ['action' => $action, 'csrf_token' => $c] + $data, ["X-CSRF-Token: $c"]); return ['code' => $r['code'], 'j' => json_decode($r['body'], true)]; }
define('UND_DIR', "$SB/flash"); define('UND_TMP', "$SB/tmp"); define('UND_ROOT', dirname(__DIR__) . '/src');
putenv('UNDASH_MODE=api');
require UND_ROOT . '/include/lib.php'; require UND_ROOT . '/include/auth.php';
und_setup_code_ensure();
$code = trim(file_get_contents(und_setup_file()));

// first start: the setup page offers a password (with the code) or going without one
$r = req('GET', '/');
t('first visit goes to setup', $r['code'] === 303 && $r['loc'] === '/setup');
$r = req('GET', '/setup');
t('setup page offers to skip, and recommends a password', strpos($r['body'], 'Continue without a password') !== false && strpos($r['body'], 'Recommended') !== false && strpos($r['body'], 'name="code"') !== false);
$p = pre($r['body']);
$r = req('POST', '/setup', ['pre' => 'forged', 'skip' => '1']);
t('skipping needs the page\'s own form token', $r['code'] === 200 && strpos($r['body'], 'The form expired') !== false && und_auth_kind() === 'none');
$r = req('GET', '/setup'); $p = pre($r['body']);
$r = req('POST', '/setup', ['pre' => $p, 'skip' => '1']);
t('skipping signs in without a code or password', $r['code'] === 303 && $r['loc'] === '/' && und_auth_kind() === 'open' && !is_file(und_setup_file()));
$r = req('GET', '/');
t('the dashboard opens', $r['code'] === 200 && strpos($r['body'], 'app.js') !== false);
t('boot reports that there is no password', (apicall('boot')['j']['auth'] ?? '') === 'open');
// another browser (no cookies) is let in too: that is what "no password" means
$mine = $JAR; $JAR = [];
t('without a password any browser gets in', req('GET', '/')['code'] === 200);
$other = $JAR; $JAR = $mine;
t('/setup and /login lead to the dashboard once signed in', req('GET', '/setup')['loc'] === '/' && req('GET', '/login')['loc'] === '/');

// add a password later from Settings
t('a short password is refused', apicall('password_set', ['password' => 'short', 'password2' => 'short'])['code'] === 400 && und_auth_kind() === 'open');
t('mismatched passwords are refused', apicall('password_set', ['password' => 'a long password 1', 'password2' => 'a long password 2'])['code'] === 400);
$r = apicall('password_set', ['password' => 'correct horse battery', 'password2' => 'correct horse battery']);
t('a password can be added without the setup code', $r['code'] === 200 && $r['j']['kind'] === 'file' && und_password_ok('correct horse battery'));
t('this browser stays signed in', req('GET', '/')['code'] === 200);
$JAR = $other;
$r = req('GET', '/');
t('the other browser that came in without a password is signed out', $r['code'] === 303 && $r['loc'] === '/login');
$JAR = $mine;

// change and remove need the current password
t('changing needs the current password', apicall('password_set', ['current' => 'nope', 'password' => 'another long one', 'password2' => 'another long one'])['code'] === 403 && und_password_ok('correct horse battery'));
t('changing with it works', apicall('password_set', ['current' => 'correct horse battery', 'password' => 'another long one', 'password2' => 'another long one'])['code'] === 200 && und_password_ok('another long one'));
t('removing needs confirmation', apicall('password_remove', ['current' => 'another long one'])['code'] === 400 && und_auth_kind() === 'file');
t('removing with the current password and confirmation goes back to no password', apicall('password_remove', ['current' => 'another long one', 'confirm' => 'yes'])['j']['kind'] === 'open');
t('wrong current passwords are throttled', (function () { apicall('password_set', ['password' => 'third long password', 'password2' => 'third long password']);
  for ($i = 0; $i < 5; $i++) apicall('password_remove', ['current' => 'wrong', 'confirm' => 'yes']);
  return apicall('password_remove', ['current' => 'third long password', 'confirm' => 'yes'])['code'] === 429 && und_auth_kind() === 'file'; })());

proc_terminate($proc);
exec('rm -rf ' . escapeshellarg($SB));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
