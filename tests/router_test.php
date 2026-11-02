<?php
/* End-to-end test of the standalone port: sign-in, CSRF and origin checks, file serving. php tests/router_test.php */
$SB = sys_get_temp_dir() . '/undash-rt-' . getmypid();
foreach (["$SB/flash", "$SB/tmp", "$SB/php", "$SB/fs/var/local/emhttp"] as $d) @mkdir($d, 0777, true);
file_put_contents("$SB/fs/var/local/emhttp/var.ini", "NAME=\"Tower\"\n");
$port = 9300 + getmypid() % 500;
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/router_harness.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['UND_SANDBOX' => $SB, 'UNDASH_PASSWORD' => 'a test password', 'PATH' => getenv('PATH')]);
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
/* the sign-in form: its one-time value comes with a cookie of its own */
function signin() {
  $f = req('GET', '/login'); $pre = preg_match('/name="pre" value="([a-f0-9]+)"/', $f['body'], $m) ? $m[1] : '';
  $ck = []; foreach ($f['h']['set-cookie'] ?? [] as $l) $ck[] = explode(';', $l)[0];
  return req('POST', '/login', ['Content-Type: application/x-www-form-urlencoded', 'Cookie: ' . implode('; ', $ck)], http_build_query(['password' => 'a test password', 'pre' => $pre]));
}
$r = req('GET', '/');
t('unauthenticated page goes to the sign-in form', $r['code'] === 303 && ($r['h']['location'][0] ?? '') === '/login', json_encode($r['h']['location'] ?? null));
t('security headers are sent', isset($r['h']['content-security-policy'], $r['h']['x-frame-options']));
$r = req('POST', '/api', ['Content-Type: application/x-www-form-urlencoded'], 'action=history');
t('unauthenticated API refused', $r['code'] === 401);
$r = req('GET', '/manifest.webmanifest');
$mf = json_decode($r['body'], true);
t('a phone can read the app manifest before signing in', $r['code'] === 200 && strpos($r['h']['content-type'][0] ?? '', 'manifest+json') !== false && ($mf['display'] ?? '') === 'standalone' && count($mf['icons'] ?? []) === 2);
t('so can its icons', req('GET', '/icon-192.png')['code'] === 200 && (req('GET', '/icon-512.png')['h']['content-type'][0] ?? '') === 'image/png');
define('UND_DIR', "$SB/flash"); define('UND_TMP', "$SB/tmp"); define('UND_ROOT', dirname(__DIR__) . '/src');
$GLOBALS['UND_FS'] = "$SB/fs";
require UND_ROOT . '/include/lib.php'; require UND_ROOT . '/include/auth.php';
$r = signin();
$cookie = preg_match('/(undash_sid(?:_\d+)?)=([a-f0-9]{64})/', implode(';', $r['h']['set-cookie'] ?? []), $m) ? $m[2] : ''; $cname = $m[1] ?? 'undash_sid';
t('the session cookie is per port, so two UnDash on one server keep their sessions', preg_match('/^undash_sid_\d+$/', $cname) === 1, $cname);
t('the right password gives a session cookie', $r['code'] === 303 && $cookie !== '');
t('session cookie is HttpOnly and SameSite=Strict', stripos(implode(';', $r['h']['set-cookie']), 'httponly') !== false && stripos(implode(';', $r['h']['set-cookie']), 'samesite=strict') !== false);
$C = "Cookie: $cname=$cookie";
$r = req('GET', '/assets/boot.js', [$C]);
$csrf = preg_match('/UND_CSRF="([a-f0-9]+)"/', $r['body'], $m) ? $m[1] : '';
t('signed-in browser gets its CSRF token', $csrf !== '');
$r = req('GET', '/csrf', [$C]);
t('a signed-in page can fetch its token again, and the version with it', json_decode($r['body'], true)['csrf'] === $csrf && isset(json_decode($r['body'], true)['version']));
t('without a session there is no token to fetch', strpos(req('GET', '/csrf')['body'], $csrf) === false);
t('page is served when signed in', strpos(req('GET', '/', [$C])['body'], 'id="und"') !== false);
$r = req('POST', '/api', [$C, 'Content-Type: application/x-www-form-urlencoded'], 'action=history');
t('API without CSRF token refused', $r['code'] === 403);
$r = req('POST', '/api', [$C, 'Content-Type: application/x-www-form-urlencoded', 'X-CSRF-Token: wrong'], 'action=history');
t('API with a wrong CSRF token refused', $r['code'] === 403);
$r = req('POST', '/api', [$C, 'Content-Type: application/x-www-form-urlencoded', "X-CSRF-Token: $csrf", 'Origin: http://evil.example'], 'action=history');
t('cross-origin API call refused', $r['code'] === 403);
$r = req('POST', '/api', [$C, 'Content-Type: application/x-www-form-urlencoded', "X-CSRF-Token: $csrf", 'Origin: http://tower.local:9099'], 'action=history');
t('valid API call works', $r['code'] === 200 && isset(json_decode($r['body'], true)['history']), $r['body']);
$H = [$C, 'Content-Type: application/x-www-form-urlencoded', "X-CSRF-Token: $csrf"];
t('ignoring needs the CSRF token', req('POST', '/api', [$C, 'Content-Type: application/x-www-form-urlencoded'], 'action=ignore_add&kind=instance&category=disk.hot&resource=disk3&duration=3600')['code'] === 403);
$r = req('POST', '/api', $H, 'action=ignore_add&kind=instance&category=disk.hot&resource=disk3&duration=3600&title=hot&severity=warn');
$j = json_decode($r['body'], true);
t('ignore an alert over HTTP', $r['code'] === 200 && ($j['rule']['key'] ?? '') === 'disk.hot:disk3', $r['body']);
t('a bad ignore request is a 400 and adds nothing', req('POST', '/api', $H, 'action=ignore_add&kind=type&category=../x&duration=3600')['code'] === 400
  && req('POST', '/api', $H, 'action=ignore_add&kind=instance&category=disk.hot&resource=disk1&duration=-5')['code'] === 400);
$l = json_decode(req('POST', '/api', $H, 'action=ignores_list')['body'], true);
t('the list has exactly the one rule', count($l['rules'] ?? []) === 1 && isset($l['categories']['disk.hot']));
$lv = json_decode(req('POST', '/api', $H, 'action=live&parts=none')['body'], true);
t('live refresh carries the active rules', count($lv['ignores'] ?? []) === 1 && $lv['ignores'][0]['key'] === 'disk.hot:disk3');
/* the wallpaper uploaded in Settings: checked on the way in, served only to a signed-in browser, never a stray file */
t('no wallpaper yet is a clean 404', req('GET', '/wallpaper', [$C])['code'] === 404);
t('the wallpaper needs a session', req('GET', '/wallpaper')['code'] === 401);
$png = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAFklEQVR42mNk+M9Qz0AEYBxVSF+FAAhKDveksOjmAAAAAElFTkSuQmCC';
t('a text file is refused as a wallpaper with a clear error', req('POST', '/api', $H, 'action=wallpaper_upload&data=' . base64_encode('<svg/>'))['code'] === 422);
$r = req('POST', '/api', $H, 'action=wallpaper_upload&data=' . urlencode($png));
$wj = json_decode($r['body'], true);
t('a PNG is accepted and gets a version', $r['code'] === 200 && !empty($wj['ok']) && !empty($wj['v']), $r['body']);
$w = req('GET', '/wallpaper?v=' . $wj['v'], [$C]);
t('the picture is served with its own type and a long cache life', $w['code'] === 200 && ($w['h']['content-type'][0] ?? '') === 'image/png' && strpos($w['h']['cache-control'][0] ?? '', 'immutable') !== false && substr($w['body'], 1, 3) === 'PNG');
t('a bad preference name is refused', req('POST', '/api', $H, 'action=prefs&kind=docker&key=' . urlencode("bad name\n") . '&value=' . urlencode('{"hidden":true}'))['code'] === 400);
t('restoring the defaults needs a confirmation', req('POST', '/api', $H, 'action=reset')['code'] === 400);
// a picture named by its path: what is sent comes from the saved setting, whatever the request asks for
file_put_contents(UND_DIR . '/saved-wall.png', base64_decode($png));
$wc = und_load_config(); $wc['appearance']['background'] = 'local'; $wc['appearance']['wall_path'] = UND_DIR . '/saved-wall.png'; und_save_config($wc, false);
$lw = req('GET', '/wallpaper?local=1', [$C]);
t('a saved local path is shown as the wallpaper', $lw['code'] === 200 && $lw['body'] === base64_decode($png));
t('a saved local path needs a session', req('GET', '/wallpaper?local=1')['code'] === 401);
t('a path in the request is ignored', req('GET', '/wallpaper?local=1&path=/etc/passwd', [$C])['body'] === base64_decode($png));
$wc['appearance']['wall_path'] = '/etc/passwd'; und_save_config($wc, false);
t('a saved path outside the allowed folders sends nothing', req('GET', '/wallpaper?local=1', [$C])['code'] === 404);
$wc['appearance']['background'] = ''; $wc['appearance']['wall_path'] = ''; und_save_config($wc, false);
t('the picture can be removed', !empty(json_decode(req('POST', '/api', $H, 'action=wallpaper_remove')['body'], true)['ok']) && req('GET', '/wallpaper', [$C])['code'] === 404);
t('restore all needs confirmation', req('POST', '/api', $H, 'action=ignore_restore_all')['code'] === 400);
$r = req('POST', '/api', $H, 'action=ignore_remove&ids=' . rawurlencode(json_encode([$j['rule']['id'] ?? ''])));
t('restore by id', $r['code'] === 200 && json_decode($r['body'], true)['removed'] === 1, $r['body']);
$r = req('GET', '/assets/inter.woff2', []);
t('the bundled font is served without a session', $r['code'] === 200 && substr($r['body'], 0, 4) === 'wOF2' && strpos(implode(';', $r['h']['content-type'] ?? []), 'font/woff2') !== false);
t('GET on the API refused', req('GET', '/api', [$C])['code'] === 405);
t('icon paths cannot escape their folder', req('GET', '/state/plugins/dynamix.docker.manager/images/..%2F..%2F..%2Fetc%2Fpasswd', [$C])['code'] === 404);
t('unknown paths are 404', req('GET', '/include/config.php', [$C])['code'] === 404);
// signed in to Unraid itself (its PHP session file holds unraid_login)
$sid = 'abcdefghijklmnopqrstuvwxyz12';
file_put_contents("$SB/php/sess_$sid", 'unraid_login|i:' . time() . ';');
$r = req('GET', '/', ['Cookie: unraid_' . md5('tower.local') . "=$sid"]);
t('logout ends the session', req('GET', '/logout', [$C])['code'] === 303 && req('POST', '/api', [$C, "X-CSRF-Token: $csrf", 'Content-Type: application/x-www-form-urlencoded'], 'action=history')['code'] === 401);
// static files
$ac = req('GET', '/assets/app.css');
$etag = $ac['h']['etag'][0] ?? '';
t('a static file has an ETag and is revalidated', $ac['code'] === 200 && $etag !== '' && ($ac['h']['cache-control'][0] ?? '') === 'no-cache', json_encode($ac['h']));
t('an unchanged static file is answered with 304', req('GET', '/assets/app.css', ["If-None-Match: $etag"])['code'] === 304);
$ver = trim(explode('-', trim($etag, '"'))[0]);
t('a versioned URL is cached for good', strpos(req('GET', "/assets/app.css?v=$ver")['h']['cache-control'][0] ?? '', 'immutable') !== false);
t('a static file is compressed when the browser accepts it', (req('GET', '/assets/app.css', ['Accept-Encoding: gzip'])['h']['content-encoding'][0] ?? '') === 'gzip');
t('icons over plain http may load', strpos($ac['h']['content-security-policy'][0] ?? '', "img-src 'self' http: https: data:") !== false);
/* ---- the guest link: its own area, with its own cookie ---- */
$form = ['Content-Type: application/x-www-form-urlencoded'];
$probe = str_repeat('a', 48);
$r = signin();
$C = 'Cookie: ' . (preg_match('/(undash_sid(?:_\d+)?=[a-f0-9]{64})/', implode(';', $r['h']['set-cookie'] ?? []), $m) ? $m[1] : '');
$csrf = preg_match('/UND_CSRF="([a-f0-9]+)"/', req('GET', '/assets/boot.js', [$C])['body'], $m) ? $m[1] : '';
t('with the link off there is no guest area', req('GET', "/guest/$probe")['code'] === 404 && req('POST', '/guest/api', $form, 'action=boot')['code'] === 404);
$ow = req('POST', '/api', [$C, "X-CSRF-Token: $csrf", 'Content-Type: application/x-www-form-urlencoded'], 'action=guest_turn&on=1');
$gv = json_decode($ow['body'], true);
t('the owner turns the link on and gets its address', ($gv['on'] ?? false) === true && preg_match('#^/guest/[a-f0-9]{48}$#', $gv['path'] ?? '') === 1, $ow['body']);
t('nobody else can ask for it', req('POST', '/api', $form, 'action=guest_get')['code'] === 401);
$path = $gv['path'];
t('a wrong link finds nothing', req('GET', "/guest/$probe")['code'] === 404);
t('a guess at the shape finds nothing', req('GET', '/guest/abc')['code'] === 401 && req('GET', '/guest/')['code'] === 401);
$r = req('GET', $path);
$sc = implode(';', $r['h']['set-cookie'] ?? []);
$gcook = preg_match('/(undash_gid(?:_\d+)?)=([a-f0-9]{64})/', $sc, $m) ? $m[1] . '=' . $m[2] : '';
t('the link opens the page', $r['code'] === 200 && strpos($r['body'], '/guest/boot.js') !== false && strpos($r['body'], '/guest/app.js') !== false, $r['body']);
t('its cookie stays inside the guest area', $gcook !== '' && stripos($sc, 'path=/guest/') !== false && stripos($sc, 'httponly') !== false && stripos($sc, 'samesite=strict') !== false, $sc);
t('it does not replace the owner\'s cookie', strpos($sc, 'undash_sid') === false);
t('the address of the link is not sent on to other sites', ($r['h']['referrer-policy'][0] ?? '') === 'no-referrer');
$G = "Cookie: $gcook";
$boot = req('GET', '/guest/boot.js', [$G]);
$gcsrf = preg_match('/UND_CSRF="([a-f0-9]+)"/', $boot['body'], $m) ? $m[1] : '';
t('the guest page knows it is one', strpos($boot['body'], 'UND_GUEST=true') !== false && strpos($boot['body'], '"/guest/api"') !== false && $gcsrf !== '');
t('and gets the page script', req('GET', '/guest/app.js', [$G])['code'] === 200);
$gapi = function ($action, $extra = '') use ($G, $gcsrf) { return req('POST', '/guest/api', [$G, "X-CSRF-Token: $gcsrf", 'Content-Type: application/x-www-form-urlencoded'], "action=$action$extra"); };
$b = json_decode($gapi('boot')['body'], true);
t('a guest gets a dashboard', ($b['guest'] ?? false) === true && isset($b['layout']['dashboard']));
foreach (['settings', 'save', 'docker_action', 'vm_action', 'power', 'prefs', 'export', 'guest_get', 'unraid_conf'] as $a)
  t("a guest cannot call $a", json_decode($gapi($a)['body'], true) === ['error' => 'Not available in the guest view.']);
t('a guest\'s cookie is no good on the owner\'s API', req('POST', '/api', [$G, "X-CSRF-Token: $gcsrf", 'Content-Type: application/x-www-form-urlencoded'], 'action=settings')['code'] === 401);
t('or for the owner\'s page, which sends a guest to sign in', req('GET', '/', [$G])['code'] === 303);
t('or the owner\'s boot file', req('GET', '/assets/boot.js', [$G])['code'] === 401);
t('the owner\'s cookie does not open the guest API', req('POST', '/guest/api', [$C, "X-CSRF-Token: $csrf", 'Content-Type: application/x-www-form-urlencoded'], 'action=boot')['code'] === 401);
t('a guest call needs its token', req('POST', '/guest/api', [$G, 'Content-Type: application/x-www-form-urlencoded'], 'action=boot')['code'] === 403);
t('and refuses other sites', req('POST', '/guest/api', [$G, "X-CSRF-Token: $gcsrf", 'Origin: http://evil.example', 'Content-Type: application/x-www-form-urlencoded'], 'action=boot')['code'] === 403);
t('the guest area only answers POST on its API', req('GET', '/guest/api', [$G])['code'] === 405);
t('anything else there is not found', req('GET', '/guest/settings', [$G])['code'] === 404 && req('GET', '/guest/../api', [$G])['code'] !== 200);
t('the owner stays signed in after opening the link', strpos(req('GET', '/', [$C])['body'], 'id="und"') !== false);
$nw = json_decode(req('POST', '/api', [$C, "X-CSRF-Token: $csrf", 'Content-Type: application/x-www-form-urlencoded'], 'action=guest_new')['body'], true);
t('a new link replaces the old one', ($nw['path'] ?? $path) !== $path && req('GET', $path)['code'] === 404);
t('and signs guests out at once', $gapi('boot')['code'] === 401);
t('the new link works', req('GET', $nw['path'])['code'] === 200);
req('POST', '/api', [$C, "X-CSRF-Token: $csrf", 'Content-Type: application/x-www-form-urlencoded'], 'action=guest_turn&on=0');
t('turning it off ends it', req('GET', $nw['path'])['code'] === 404);

proc_terminate($proc); proc_close($proc);
exec('rm -rf ' . escapeshellarg($SB));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
