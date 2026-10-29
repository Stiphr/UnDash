<?php
/* End-to-end test of the standalone port: sign-in, handoff, CSRF and origin checks, file serving. php tests/router_test.php */
$SB = sys_get_temp_dir() . '/undash-rt-' . getmypid();
foreach (["$SB/flash", "$SB/tmp", "$SB/php", "$SB/fs/var/local/emhttp"] as $d) @mkdir($d, 0777, true);
file_put_contents("$SB/fs/var/local/emhttp/var.ini", "NAME=\"Tower\"\n");
$port = 9300 + getmypid() % 500;
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/router_harness.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['UND_SANDBOX' => $SB, 'PATH' => getenv('PATH')]);
usleep(600000);
$pass = 0; $fail = 0;
function t($n, $c, $d = '') { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "FAIL $n" . ($d !== '' ? " -- $d" : '') . "\n"; } }
function req($method, $path, $headers = [], $body = null) {
  global $port;
  $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", array_merge(['Host: tower.local:9099'], $headers)), 'content' => $body, 'ignore_errors' => true, 'follow_location' => 0]]);
  $out = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
  $h = $http_response_header ?? [];
  preg_match('#HTTP/\S+ (\d+)#', $h[0] ?? '', $m);
  $hdr = []; foreach ($h as $l) if (strpos($l, ':')) { list($k, $v) = explode(':', $l, 2); $hdr[strtolower(trim($k))][] = trim($v); }
  return ['code' => (int)($m[1] ?? 0), 'body' => (string)$out, 'h' => $hdr];
}
$r = req('GET', '/');
t('unauthenticated page redirects to Unraid\'s handoff', $r['code'] === 303 && ($r['h']['location'][0] ?? '') === 'http://tower.local/plugins/undash/handoff.php', json_encode($r['h']['location'] ?? null));
t('security headers are sent', isset($r['h']['content-security-policy'], $r['h']['x-frame-options']));
$r = req('POST', '/api', ['Content-Type: application/x-www-form-urlencoded'], 'action=history');
t('unauthenticated API refused', $r['code'] === 401);
$r = req('GET', '/?t=' . str_repeat('a', 48));
t('unknown handoff token refused', $r['code'] === 403);
// a valid handoff token (what handoff.php creates behind Unraid's login)
define('UND_DIR', "$SB/flash"); define('UND_TMP', "$SB/tmp"); define('UND_ROOT', dirname(__DIR__) . '/src');
$GLOBALS['UND_FS'] = "$SB/fs";
require UND_ROOT . '/include/lib.php'; require UND_ROOT . '/include/auth.php';
$tok = und_handoff_create();
$r = req('GET', '/?t=' . $tok);
$cookie = preg_match('/undash_sid=([a-f0-9]{64})/', implode(';', $r['h']['set-cookie'] ?? []), $m) ? $m[1] : '';
t('handoff token gives a session cookie', $r['code'] === 303 && $cookie !== '');
t('session cookie is HttpOnly and SameSite=Strict', stripos(implode(';', $r['h']['set-cookie']), 'httponly') !== false && stripos(implode(';', $r['h']['set-cookie']), 'samesite=strict') !== false);
t('handoff token cannot be reused', req('GET', '/?t=' . $tok)['code'] === 403);
$C = "Cookie: undash_sid=$cookie";
$r = req('GET', '/assets/boot.js', [$C]);
$csrf = preg_match('/UND_CSRF="([a-f0-9]+)"/', $r['body'], $m) ? $m[1] : '';
t('signed-in browser gets its CSRF token', $csrf !== '');
t('page is served when signed in', strpos(req('GET', '/', [$C])['body'], 'id="und"') !== false);
$r = req('POST', '/api', [$C, 'Content-Type: application/x-www-form-urlencoded'], 'action=history');
t('API without CSRF token refused', $r['code'] === 403);
$r = req('POST', '/api', [$C, 'Content-Type: application/x-www-form-urlencoded', 'X-CSRF-Token: wrong'], 'action=history');
t('API with a wrong CSRF token refused', $r['code'] === 403);
$r = req('POST', '/api', [$C, 'Content-Type: application/x-www-form-urlencoded', "X-CSRF-Token: $csrf", 'Origin: http://evil.example'], 'action=history');
t('cross-origin API call refused', $r['code'] === 403);
$r = req('POST', '/api', [$C, 'Content-Type: application/x-www-form-urlencoded', "X-CSRF-Token: $csrf", 'Origin: http://tower.local:9099'], 'action=history');
t('valid API call works', $r['code'] === 200 && isset(json_decode($r['body'], true)['history']), $r['body']);
t('GET on the API refused', req('GET', '/api', [$C])['code'] === 405);
t('icon paths cannot escape their folder', req('GET', '/state/plugins/dynamix.docker.manager/images/..%2F..%2F..%2Fetc%2Fpasswd', [$C])['code'] === 404);
t('unknown paths are 404', req('GET', '/include/config.php', [$C])['code'] === 404);
// signed in to Unraid itself (its PHP session file holds unraid_login)
$sid = 'abcdefghijklmnopqrstuvwxyz12';
file_put_contents("$SB/php/sess_$sid", 'unraid_login|i:' . time() . ';');
$r = req('GET', '/', ['Cookie: unraid_' . md5('tower.local') . "=$sid"]);
t('an Unraid login is accepted directly', $r['code'] === 200 && strpos(implode(';', $r['h']['set-cookie'] ?? []), 'undash_sid=') !== false);
t('logout ends the session', req('GET', '/logout', [$C])['code'] === 303 && req('POST', '/api', [$C, "X-CSRF-Token: $csrf", 'Content-Type: application/x-www-form-urlencoded'], 'action=history')['code'] === 401);
proc_terminate($proc); proc_close($proc);
exec('rm -rf ' . escapeshellarg($SB));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
