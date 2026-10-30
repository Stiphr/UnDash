<?php
/*
 * UnDash - endpoint health: is every app and container web UI answering, with which HTTP status, how fast.
 * Only addresses UnDash already knows are probed: the links of the apps in Settings > Sections & apps and the web UI labels of
 * running containers. Nothing the browser sends becomes a URL. All probes run in parallel; results are cached 60 s.
 */

/** host:port of a URL, without user info, path or query (what the page shows). */
function und_health_hostport($u) {
  $p = parse_url($u);
  if (!$p || empty($p['host'])) return '';
  $port = $p['port'] ?? (strtolower($p['scheme'] ?? 'http') === 'https' ? 443 : 80);
  return $p['host'] . ':' . $port;
}

/** The list to probe: apps first (they have names you chose), then container web UIs that no app already covers. */
function und_health_targets(array $cfg, $docker = null) {
  $out = []; $seen = [];
  $view = und_layout_view($cfg);
  foreach ($view['groups'] as $g) foreach ($g['services'] as $s) {
    if (!empty($s['hidden'])) continue;
    $u = (string)($s['href_resolved'] ?? '');
    if (!preg_match('#^https?://#i', $u)) continue;
    $hp = und_health_hostport($u);
    if ($hp === '' || isset($seen[$hp])) continue;
    $seen[$hp] = 1;
    $out[] = ['name' => $s['name'], 'kind' => 'app', 'group' => $g['name'] ?? '', 'url' => $u, 'hostport' => $hp, 'icon' => $s['icon'] ?? ''];
  }
  $items = (array)($cfg['docker']['items'] ?? []);
  foreach ((array)($docker['containers'] ?? []) as $c) {
    if (($c['state'] ?? '') !== 'running' || !preg_match('#^https?://#i', (string)($c['webui'] ?? ''))) continue;
    $p = (array)($items[$c['name']] ?? []);
    if (!empty($p['hidden'])) continue;
    $hp = und_health_hostport($c['webui']);
    if ($hp === '' || isset($seen[$hp])) continue;
    $seen[$hp] = 1;
    $out[] = ['name' => ($p['label'] ?? '') !== '' ? $p['label'] : $c['name'], 'kind' => 'container', 'group' => 'container', 'url' => $c['webui'], 'hostport' => $hp, 'icon' => $p['icon'] ?? $c['icon'] ?? ''];
  }
  return array_slice($out, 0, 120);
}

function und_health_reason($code) {
  $r = [200 => 'OK', 201 => 'Created', 204 => 'No content', 301 => 'Redirect', 302 => 'Redirect', 303 => 'Redirect', 307 => 'Redirect', 308 => 'Redirect',
        400 => 'Bad request', 401 => 'Login required', 403 => 'Forbidden', 404 => 'Not found', 405 => 'Method not allowed', 500 => 'Server error',
        502 => 'Bad gateway', 503 => 'Unavailable', 504 => 'Gateway timeout'];
  return $r[$code] ?? ($code >= 500 ? 'Server error' : ($code >= 400 ? 'Client error' : ($code >= 300 ? 'Redirect' : 'OK')));
}

/** Probes in parallel. A GET without following redirects; the body is not downloaded (the transfer stops at the first byte). */
function und_health_probe(array $targets) {
  if (isset($GLOBALS['UND_HEALTH_MOCK'])) return call_user_func($GLOBALS['UND_HEALTH_MOCK'], $targets);
  $mh = curl_multi_init(); $hs = [];
  foreach ($targets as $i => $t) {
    $ch = curl_init($t['url']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
      CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'UnDash/' . UND_VERSION . ' (health check)', CURLOPT_NOSIGNAL => true,
      CURLOPT_WRITEFUNCTION => function ($c, $data) { return 0; }]);      // stop after the headers: the status is all we need
    curl_multi_add_handle($mh, $ch); $hs[$i] = $ch;
  }
  do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 0.5); } while ($running && $st === CURLM_OK);
  $res = [];
  foreach ($hs as $i => $ch) {
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ms = (int)round(1000 * (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME));
    $errno = curl_errno($ch);
    // aborting the body on purpose shows up as a write error; anything else with no status is a real failure
    $err = $code > 0 ? '' : ($errno === 28 ? 'No answer (timed out)' : ($errno === 7 ? 'Connection refused' : (curl_error($ch) ?: 'No answer')));
    $res[$i] = ['code' => $code, 'ms' => $code > 0 ? $ms : null, 'err' => $err];
    curl_multi_remove_handle($mh, $ch); if (PHP_VERSION_ID < 80000) curl_close($ch);
  }
  curl_multi_close($mh);
  return $res;
}

/** The section's data. $force probes again now (at most every 10 seconds). */
function und_health(array $cfg, $force = false) {
  $f = und_tmp('health.json');
  $c = und_read_json($f, null);
  $age = is_array($c) ? time() - (int)($c['at'] ?? 0) : PHP_INT_MAX;
  if (is_array($c) && ($age < 60 && !$force || $age < 10)) return $c;
  $lock = und_lock('health');
  if (!$lock) return is_array($c) ? $c : ['at' => time(), 'rows' => [], 'busy' => true];
  $targets = und_health_targets($cfg, und_docker_live());
  $probe = und_health_probe($targets);
  $rows = [];
  foreach ($targets as $i => $t) {
    $p = $probe[$i] ?? ['code' => 0, 'ms' => null, 'err' => 'No answer'];
    $code = (int)$p['code'];
    $rows[] = ['name' => $t['name'], 'kind' => $t['kind'], 'group' => $t['group'], 'url' => $t['url'], 'hostport' => $t['hostport'], 'icon' => $t['icon'],
               'code' => $code, 'text' => $code ? $code . ' ' . und_health_reason($code) : $p['err'], 'ms' => $p['ms'],
               'level' => !$code ? 'bad' : ($code >= 500 ? 'bad' : ($code >= 400 && !in_array($code, [401, 403], true) ? 'warn' : 'ok'))];
  }
  // problems first, then by name
  $rank = ['bad' => 0, 'warn' => 1, 'ok' => 2];
  usort($rows, function ($a, $b) use ($rank) { return $rank[$a['level']] - $rank[$b['level']] ?: strcasecmp($a['name'], $b['name']); });
  $out = ['at' => time(), 'rows' => $rows, 'up' => count(array_filter($rows, function ($r) { return $r['code'] > 0; })), 'total' => count($rows)];
  und_write_json($f, $out);
  und_unlock($lock);
  return $out;
}
