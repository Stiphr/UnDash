<?php
/*
 * UnDash - service widgets. Each fetcher talks to one app's API from the server (credentials never reach the browser)
 * and returns one of these shapes, which the UI renders the same way for every app:
 *   ['blocks' => [['key','label','value','fmt'], ...]]           fmt: n | bytes | rate | ms | pct | text
 *   ['list'   => [['title','sub','right','ts'], ...]]             ts: unix time shown as a relative date
 *   ['calendar' => [['ts','title','sub','done'], ...]]
 *   ['streams' => [['title','user','state','pos','dur'], ...]]   (can be combined with blocks)
 *   ['error' => 'message']
 * The endpoints and the numbers they produce follow gethomepage/homepage's widgets, so the cards show what Homepage showed.
 */

/* ---------- HTTP ---------- */

function und_http($method, $url, array $o = []) {
  if (isset($GLOBALS['UND_HTTP_MOCK'])) { $r = call_user_func($GLOBALS['UND_HTTP_MOCK'], $method, $url, $o); if (is_array($r)) return $r; }
  if (!preg_match('#^https?://#i', $url)) return ['code' => 0, 'body' => '', 'json' => null, 'err' => 'The URL must start with http:// or https://', 'headers' => []];
  $ch = curl_init($url);
  $headers = [];
  foreach (($o['headers'] ?? []) as $k => $v) $headers[] = $k . ': ' . $v;
  $resp = [];
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
    CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => (int)($o['timeout'] ?? 10), CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,     // LAN apps mostly use self-signed certificates
    CURLOPT_USERAGENT => 'UnDash/' . UND_VERSION, CURLOPT_ENCODING => '',
    CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$resp) { $p = explode(':', $h, 2); if (count($p) === 2) $resp[strtolower(trim($p[0]))][] = trim($p[1]); return strlen($h); },
  ]);
  if (isset($o['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body']);
  if (isset($o['basic'])) curl_setopt($ch, CURLOPT_USERPWD, $o['basic'][0] . ':' . $o['basic'][1]);
  if (isset($o['cookie'])) curl_setopt($ch, CURLOPT_COOKIE, $o['cookie']);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = $body === false ? curl_error($ch) : '';
  curl_close($ch);
  $body = $body === false ? '' : $body;
  return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true), 'err' => $err, 'headers' => $resp];
}

/** GET returning decoded JSON, or throws with a readable message. */
function und_get($url, array $o = []) {
  $r = und_http($o['method'] ?? 'GET', $url, $o);
  if ($r['err'] !== '') throw new Exception(und_redact($r['err']));
  if ($r['code'] === 401 || $r['code'] === 403) throw new Exception('Not authorised (HTTP ' . $r['code'] . '): check the API key or login.');
  if ($r['code'] >= 400 || $r['code'] === 0) throw new Exception('HTTP ' . $r['code'] . ' from ' . und_redact(preg_replace('#\?.*$#', '', $url)));
  if (!empty($o['raw'])) return $r;
  if ($r['json'] === null && trim($r['body']) !== 'null') throw new Exception('The answer was not JSON (is the URL right?).');
  return $r['json'];
}

function und_u($base, $path) { return rtrim($base, '/') . '/' . ltrim($path, '/'); }
function und_b($key, $label, $value, $fmt = 'n') { return ['key' => $key, 'label' => $label, 'value' => $value, 'fmt' => $fmt]; }

/* ---------- dispatcher ---------- */

function und_fetch_service(array $cfg, $id) {
  $s = und_service_full($cfg, $id);
  if (!$s) return ['error' => 'Unknown widget.'];
  $fn = 'und_w_' . $s['type'];
  if (!function_exists($fn)) return ['error' => 'No data for this widget type.'];
  $types = und_types();
  if (!empty($types[$s['type']][2]) && trim($s['url']) === '') return ['error' => 'Not configured: set the URL in Settings.', 'unconfigured' => true];
  if (und_has($s['url'], '{{')) return ['error' => 'Unknown variable in the URL: ' . und_clip($s['url'], 120), 'unconfigured' => true];
  try {
    $r = call_user_func($fn, $s, $cfg);
  } catch (Throwable $e) {
    return ['error' => und_redact($e->getMessage(), [$s['key'] ?? '', $s['password'] ?? ''])];
  }
  if (isset($r['blocks']) && $s['fields']) {
    $want = array_flip(array_map('strtolower', $s['fields']));
    $keep = array_values(array_filter($r['blocks'], function ($b) use ($want) { return isset($want[strtolower($b['key'])]); }));
    if ($keep) {
      $order = array_map('strtolower', $s['fields']);
      usort($keep, function ($a, $b) use ($order) { return array_search(strtolower($a['key']), $order) - array_search(strtolower($b['key']), $order); });
      $r['blocks'] = $keep;
    }
  }
  return $r;
}

/* ---------- native widgets ---------- */

function und_w_docker_summary($s) { $r = und_docker_summary(); if (isset($r['error'])) throw new Exception($r['error']);
  return ['blocks' => [und_b('running', 'Running', $r['running']), und_b('stopped', 'Stopped', $r['stopped']), und_b('paused', 'Paused', $r['paused']), und_b('updates', 'Updates', $r['updates'])], 'link' => 'docker']; }
function und_w_vm_summary($s) { $r = und_vm_summary(); if (isset($r['error'])) throw new Exception($r['error']);
  return ['blocks' => [und_b('running', 'Running', $r['running']), und_b('stopped', 'Stopped', $r['stopped']), und_b('paused', 'Paused', $r['paused']), und_b('total', 'Total', $r['total'])], 'link' => 'vms']; }
function und_w_ups($s, $cfg) {
  $u = und_ups($s['options']['ups'] ?? $cfg['general']['ups_name']);
  if (!$u) throw new Exception('No UPS found (NUT or apcupsd).');
  $b = [und_b('battery_charge', 'Battery charge', $u['charge'], 'pct')];
  if ($u['load'] !== null) $b[] = und_b('ups_load', 'UPS load', $u['load'], 'pct');
  $b[] = und_b('ups_status', 'UPS status', $u['status'], 'text');
  if ($u['runtime'] !== null) $b[] = und_b('runtime', 'Runtime', $u['runtime'], 'dur');
  return ['blocks' => $b];
}
function und_w_storage() {
  $st = und_storage(); $sum = function ($rows) { $t = 0; $f = 0; foreach ($rows as $r) { $t += $r['size']; $f += $r['free']; } return [$t, $f]; };
  list($t, $f) = $sum($st['array']);
  $list = [];
  if ($t > 0) $list[] = ['title' => 'Array', 'sub' => count($st['array']) . ' disks' . ($st['parity'] ? ' + ' . count($st['parity']) . ' parity' : ''), 'used' => $t - $f, 'size' => $t];
  foreach ($st['pools'] as $p) $list[] = ['title' => ucfirst($p['name']), 'sub' => $p['fs'], 'used' => $p['size'] - $p['free'], 'size' => $p['size']];
  return ['bars' => $list];
}
// system_info / cpu / memory are drawn from the system poll in the browser; they need no fetch.
function und_w_system_info() { return ['native' => true]; }
function und_w_cpu() { return ['native' => true]; }
function und_w_memory() { return ['native' => true]; }
function und_w_link() { return ['native' => true]; }

/* ---------- *arr ---------- */

function und_arr_h($s) { return ['headers' => ['X-Api-Key' => $s['key'], 'Accept' => 'application/json']]; }

function und_w_radarr($s) {
  $movies = und_get(und_u($s['url'], '/api/v3/movie'), und_arr_h($s) + ['timeout' => 20]);
  $q = und_get(und_u($s['url'], '/api/v3/queue/status'), und_arr_h($s));
  $wanted = $missing = 0;
  foreach ((array)$movies as $m) if (!empty($m['monitored']) && empty($m['hasFile'])) { $missing++; if (!empty($m['isAvailable'])) $wanted++; }
  return ['blocks' => [und_b('wanted', 'Wanted', $wanted), und_b('missing', 'Missing', $missing), und_b('queued', 'Queued', (int)($q['totalCount'] ?? 0)), und_b('movies', 'Movies', count((array)$movies))]];
}

function und_w_sonarr($s) {
  $w = und_get(und_u($s['url'], '/api/v3/wanted/missing?pageSize=1'), und_arr_h($s));
  $q = und_get(und_u($s['url'], '/api/v3/queue?pageSize=1&includeUnknownSeriesItems=false'), und_arr_h($s));
  $series = und_get(und_u($s['url'], '/api/v3/series'), und_arr_h($s) + ['timeout' => 20]);
  return ['blocks' => [und_b('wanted', 'Wanted', (int)($w['totalRecords'] ?? 0)), und_b('queued', 'Queued', (int)($q['totalRecords'] ?? 0)), und_b('series', 'Series', count((array)$series))]];
}

function und_w_lidarr($s) {
  $w = und_get(und_u($s['url'], '/api/v1/wanted/missing?pageSize=1'), und_arr_h($s));
  $q = und_get(und_u($s['url'], '/api/v1/queue/status'), und_arr_h($s));
  $a = und_get(und_u($s['url'], '/api/v1/artist'), und_arr_h($s) + ['timeout' => 20]);
  return ['blocks' => [und_b('wanted', 'Wanted', (int)($w['totalRecords'] ?? 0)), und_b('queued', 'Queued', (int)($q['totalCount'] ?? 0)), und_b('artists', 'Artists', count((array)$a))]];
}

function und_w_readarr($s) {
  $w = und_get(und_u($s['url'], '/api/v1/wanted/missing?pageSize=1'), und_arr_h($s));
  $q = und_get(und_u($s['url'], '/api/v1/queue/status'), und_arr_h($s));
  $b = und_get(und_u($s['url'], '/api/v1/book'), und_arr_h($s) + ['timeout' => 20]);
  return ['blocks' => [und_b('wanted', 'Wanted', (int)($w['totalRecords'] ?? 0)), und_b('queued', 'Queued', (int)($q['totalCount'] ?? 0)), und_b('books', 'Books', count((array)$b))]];
}

function und_w_bazarr($s) {
  $b = und_get(und_u($s['url'], '/api/badges?apikey=' . rawurlencode($s['key'])), ['headers' => ['X-API-KEY' => $s['key']]]);
  return ['blocks' => [und_b('missingEpisodes', 'Missing episodes', (int)($b['episodes'] ?? 0)), und_b('missingMovies', 'Missing movies', (int)($b['movies'] ?? 0))]];
}

function und_w_prowlarr($s) {
  $st = und_get(und_u($s['url'], '/api/v1/indexerstats'), und_arr_h($s));
  $t = ['g' => 0, 'q' => 0, 'fg' => 0, 'fq' => 0];
  foreach ((array)($st['indexers'] ?? []) as $i) { $t['g'] += (int)($i['numberOfGrabs'] ?? 0); $t['q'] += (int)($i['numberOfQueries'] ?? 0); $t['fg'] += (int)($i['numberOfFailedGrabs'] ?? 0); $t['fq'] += (int)($i['numberOfFailedQueries'] ?? 0); }
  return ['blocks' => [und_b('numberOfGrabs', 'Grabs', $t['g']), und_b('numberOfQueries', 'Queries', $t['q']), und_b('numberOfFailGrabs', 'Fail grabs', $t['fg']), und_b('numberOfFailQueries', 'Fail queries', $t['fq'])]];
}

function und_w_overseerr($s) {
  $c = und_get(und_u($s['url'], '/api/v1/request/count'), und_arr_h($s));
  $b = [und_b('pending', 'Pending', (int)($c['pending'] ?? 0)), und_b('approved', 'Approved', (int)($c['approved'] ?? 0)), und_b('processing', 'Processing', (int)($c['processing'] ?? 0))];
  if (isset($c['completed'])) $b[] = und_b('completed', 'Completed', (int)$c['completed']);
  $b[] = und_b('available', 'Available', (int)($c['available'] ?? 0));
  if (!$s['fields']) $b = array_values(array_filter($b, function ($x) use ($c) { return $x['key'] !== 'processing' && !($x['key'] === 'available' && isset($c['completed'])); }));
  return ['blocks' => $b];
}

function und_w_autobrr($s) {
  $h = ['headers' => ['X-API-Token' => $s['key']]];
  $st = und_get(und_u($s['url'], '/api/release/stats'), $h);
  $f = und_get(und_u($s['url'], '/api/filters'), $h);
  $i = und_get(und_u($s['url'], '/api/release/indexers'), $h);
  return ['blocks' => [und_b('approvedPushes', 'Approved', (int)($st['push_approved_count'] ?? 0)), und_b('rejectedPushes', 'Rejected', (int)($st['push_rejected_count'] ?? 0)),
                       und_b('filters', 'Filters', count((array)$f)), und_b('indexers', 'Indexers', count((array)$i))]];
}

function und_w_wizarr($s) {
  $j = und_get(und_u($s['url'], '/api/status'), ['headers' => ['X-API-Key' => $s['key']]]);
  return ['blocks' => [und_b('users', 'Users', (int)($j['users'] ?? 0)), und_b('invites', 'Invites', (int)($j['invites'] ?? 0)), und_b('pending', 'Pending', (int)($j['pending'] ?? 0)), und_b('expired', 'Expired', (int)($j['expired'] ?? 0))]];
}

/* ---------- downloaders ---------- */

function und_w_qbittorrent($s) {
  $cf = und_tmp('qbit-' . $s['id'] . '.sid');
  $login = function () use ($s, $cf) {
    $r = und_http('POST', und_u($s['url'], '/api/v2/auth/login'), ['body' => http_build_query(['username' => $s['username'], 'password' => $s['password']]),
      'headers' => ['Referer' => rtrim($s['url'], '/'), 'Content-Type' => 'application/x-www-form-urlencoded']]);
    if ($r['err'] !== '') throw new Exception($r['err']);
    $sid = '';
    foreach (($r['headers']['set-cookie'] ?? []) as $c) if (preg_match('/^(SID|QBT_SID_\d+)=([^;]+)/', $c, $m)) $sid = $m[1] . '=' . $m[2];
    if ($sid === '' && trim($r['body']) !== 'Ok.') throw new Exception('qBittorrent login failed (HTTP ' . $r['code'] . ').');
    @file_put_contents($cf, $sid); @chmod($cf, 0600);
    return $sid;
  };
  $sid = is_file($cf) ? (string)@file_get_contents($cf) : $login();
  $r = und_http('GET', und_u($s['url'], '/api/v2/torrents/info'), ['cookie' => $sid, 'timeout' => 20]);
  if ($r['code'] === 403) { $sid = $login(); $r = und_http('GET', und_u($s['url'], '/api/v2/torrents/info'), ['cookie' => $sid, 'timeout' => 20]); }
  if ($r['err'] !== '' || !is_array($r['json'])) throw new Exception($r['err'] ?: 'qBittorrent did not answer (HTTP ' . $r['code'] . ').');
  $leech = $seed = 0; $dl = $ul = 0;
  foreach ($r['json'] as $t) { $dl += (float)($t['dlspeed'] ?? 0); $ul += (float)($t['upspeed'] ?? 0); if ((float)($t['progress'] ?? 0) < 1) $leech++; else $seed++; }
  return ['blocks' => [und_b('leech', 'Leech', $leech), und_b('download', 'Download', $dl, 'rate'), und_b('seed', 'Seed', $seed), und_b('upload', 'Upload', $ul, 'rate')]];
}

function und_w_sabnzbd($s) {
  $j = und_get(und_u($s['url'], '/api?output=json&mode=queue&apikey=' . rawurlencode($s['key'])));
  if (isset($j['error'])) throw new Exception('SABnzbd: ' . $j['error']);
  $q = $j['queue'] ?? [];
  return ['blocks' => [und_b('rate', 'Rate', (float)($q['kbpersec'] ?? 0) * 1024, 'rate'), und_b('queue', 'Queue', (int)($q['noofslots'] ?? 0)), und_b('timeleft', 'Time left', (string)($q['timeleft'] ?? '0:00:00'), 'text')]];
}

/* ---------- media servers ---------- */

function und_w_emby($s) { return und_emby_like($s, '/emby'); }
function und_w_jellyfin($s) { return und_emby_like($s, ''); }
function und_emby_like($s, $pre) {
  $base = rtrim($s['url'], '/');
  if ($pre !== '' && und_ends($base, $pre)) $pre = '';
  $h = ['headers' => ['X-Emby-Token' => $s['key'], 'Accept' => 'application/json']];
  $c = und_get($base . $pre . '/Items/Counts', $h);
  $sess = und_get($base . $pre . '/Sessions', $h);
  $streams = [];
  foreach ((array)$sess as $x) {
    if (empty($x['NowPlayingItem'])) continue;
    $n = $x['NowPlayingItem'];
    $t = !empty($n['SeriesName']) ? $n['SeriesName'] . ': S' . sprintf('%02d', (int)($n['ParentIndexNumber'] ?? 0)) . ' · E' . sprintf('%02d', (int)($n['IndexNumber'] ?? 0)) . ' - ' . ($n['Name'] ?? '') : (string)($n['Name'] ?? '');
    $streams[] = ['title' => $t, 'user' => (string)($x['UserName'] ?? ''), 'state' => !empty($x['PlayState']['IsPaused']) ? 'paused' : 'playing',
                  'pos' => (int)(($x['PlayState']['PositionTicks'] ?? 0) / 10000000), 'dur' => (int)(($n['RunTimeTicks'] ?? 0) / 10000000)];
  }
  $b = [und_b('movies', 'Movies', (int)($c['MovieCount'] ?? 0)), und_b('series', 'Series', (int)($c['SeriesCount'] ?? 0)), und_b('episodes', 'Episodes', (int)($c['EpisodeCount'] ?? 0)), und_b('songs', 'Songs', (int)($c['SongCount'] ?? 0))];
  return ['blocks' => empty($s['options']['hide_blocks']) ? $b : [], 'streams' => $streams, 'empty' => 'No active streams'];
}
function und_ends($s, $p) { return substr($s, -strlen($p)) === $p; }

function und_w_plex($s) {
  $h = ['headers' => ['X-Plex-Token' => $s['key'], 'Accept' => 'application/json']];
  $sess = und_get(und_u($s['url'], '/status/sessions'), $h);
  $secs = und_get(und_u($s['url'], '/library/sections'), $h);
  $count = ['movie' => 0, 'show' => 0, 'artist' => 0];
  foreach ((array)($secs['MediaContainer']['Directory'] ?? []) as $d) {
    $type = (string)($d['type'] ?? '');
    if (!isset($count[$type])) continue;
    $q = $type === 'artist' ? '?type=9&' : '?';     // albums for music libraries, like Homepage
    $r = und_get(und_u($s['url'], '/library/sections/' . rawurlencode((string)$d['key']) . '/all' . $q . 'X-Plex-Container-Start=0&X-Plex-Container-Size=0'), $h);
    $count[$type] += (int)($r['MediaContainer']['totalSize'] ?? $r['MediaContainer']['size'] ?? 0);
  }
  return ['blocks' => [und_b('streams', 'Active streams', (int)($sess['MediaContainer']['size'] ?? 0)), und_b('albums', 'Albums', $count['artist']),
                       und_b('movies', 'Movies', $count['movie']), und_b('tv', 'TV shows', $count['show'])]];
}

function und_tautulli($s, array $q) {
  $j = und_get(und_u($s['url'], '/api/v2?' . http_build_query(['apikey' => $s['key']] + $q)), ['timeout' => 15]);
  if (($j['response']['result'] ?? '') !== 'success') throw new Exception('Tautulli: ' . ($j['response']['message'] ?? 'request failed'));
  return $j['response']['data'] ?? [];
}

function und_w_tautulli($s) {
  $d = und_tautulli($s, ['cmd' => 'get_activity']);
  $streams = [];
  foreach ((array)($d['sessions'] ?? []) as $x) {
    $t = ($x['media_type'] ?? '') === 'episode'
      ? $x['grandparent_title'] . ': S' . sprintf('%02d', (int)$x['parent_media_index']) . ' · E' . sprintf('%02d', (int)$x['media_index']) . ' - ' . $x['title']
      : (string)($x['full_title'] ?? $x['title'] ?? '');
    $streams[] = ['title' => $t, 'user' => (string)($x['friendly_name'] ?? $x['user'] ?? ''), 'state' => (string)($x['state'] ?? ''),
                  'pos' => (int)((int)($x['view_offset'] ?? 0) / 1000), 'dur' => (int)((int)($x['duration'] ?? 0) / 1000), 'transcode' => (string)($x['transcode_decision'] ?? '')];
  }
  return ['streams' => $streams, 'empty' => 'No active streams'];
}

function und_w_tautulli_recent($s) {
  $o = $s['options'];
  $q = ['cmd' => 'get_recently_added', 'count' => max(1, min(25, (int)($o['count'] ?? 6)))];
  if (!empty($o['media_type'])) $q['media_type'] = (string)$o['media_type'];
  if (isset($o['section_id']) && $o['section_id'] !== '') $q['section_id'] = (string)$o['section_id'];
  $d = und_tautulli($s, $q);
  $list = [];
  foreach ((array)($d['recently_added'] ?? []) as $x) {
    $title = in_array($x['media_type'] ?? '', ['episode', 'season'], true) ? ($x['grandparent_title'] ?: $x['parent_title']) : ($x['title'] ?? '');
    $list[] = ['title' => (string)$title, 'ts' => (int)($x['added_at'] ?? 0)];
  }
  return ['list' => $list];
}

/* ---------- other apps ---------- */

function und_w_adguard($s) {
  $j = und_get(und_u($s['url'], '/control/stats'), ['basic' => [$s['username'], $s['password']]]);
  $filtered = (int)($j['num_replaced_safebrowsing'] ?? 0) + (int)($j['num_replaced_safesearch'] ?? 0) + (int)($j['num_replaced_parental'] ?? 0);
  return ['blocks' => [und_b('queries', 'Queries', (int)($j['num_dns_queries'] ?? 0)), und_b('blocked', 'Blocked', (int)($j['num_blocked_filtering'] ?? 0)),
                       und_b('filtered', 'Filtered', $filtered), und_b('latency', 'Latency', round((float)($j['avg_processing_time'] ?? 0) * 1000, 3), 'ms')]];
}

function und_w_pihole($s) {
  $r = und_http('POST', und_u($s['url'], '/api/auth'), ['body' => json_encode(['password' => $s['key']]), 'headers' => ['Content-Type' => 'application/json']]);
  if ($r['code'] === 200 && !empty($r['json']['session']['sid'])) {
    $j = und_get(und_u($s['url'], '/api/stats/summary'), ['headers' => ['X-FTL-SID' => $r['json']['session']['sid']]]);
    return ['blocks' => [und_b('queries', 'Queries', (int)($j['queries']['total'] ?? 0)), und_b('blocked', 'Blocked', (int)($j['queries']['blocked'] ?? 0)),
                         und_b('blocked_percent', 'Blocked %', round((float)($j['queries']['percent_blocked'] ?? 0), 1), 'pct'), und_b('gravity', 'Gravity', (int)($j['gravity']['domains_being_blocked'] ?? 0))]];
  }
  $j = und_get(und_u($s['url'], '/admin/api.php?summaryRaw&auth=' . rawurlencode($s['key'])));
  return ['blocks' => [und_b('queries', 'Queries', (int)($j['dns_queries_today'] ?? 0)), und_b('blocked', 'Blocked', (int)($j['ads_blocked_today'] ?? 0)),
                       und_b('blocked_percent', 'Blocked %', round((float)($j['ads_percentage_today'] ?? 0), 1), 'pct'), und_b('gravity', 'Gravity', (int)($j['domains_being_blocked'] ?? 0))]];
}

function und_w_npm($s) {
  $tf = und_tmp('npm-' . $s['id'] . '.json');
  $tok = und_read_json($tf, []);
  if (empty($tok['token']) || strtotime((string)($tok['expires'] ?? '')) < time() + 60) {
    $j = und_get(und_u($s['url'], '/api/tokens'), ['method' => 'POST', 'body' => json_encode(['identity' => $s['username'], 'secret' => $s['password']]), 'headers' => ['Content-Type' => 'application/json']]);
    if (empty($j['token'])) throw new Exception('Nginx Proxy Manager login failed.');
    $tok = ['token' => $j['token'], 'expires' => (string)($j['expires'] ?? '')];
    und_write_json($tf, $tok, true);
  }
  $hosts = und_get(und_u($s['url'], '/api/nginx/proxy-hosts'), ['headers' => ['Authorization' => 'Bearer ' . $tok['token']]]);
  $on = 0; foreach ((array)$hosts as $x) if (!empty($x['enabled'])) $on++;
  return ['blocks' => [und_b('enabled', 'Enabled', $on), und_b('disabled', 'Disabled', count((array)$hosts) - $on), und_b('total', 'Total', count((array)$hosts))]];
}

function und_w_immich($s) {
  $h = ['headers' => ['x-api-key' => $s['key'], 'Accept' => 'application/json']];
  try { $j = und_get(und_u($s['url'], '/api/server/statistics'), $h); }
  catch (Exception $e) { $j = und_get(und_u($s['url'], '/api/server-info/statistics'), $h); }
  return ['blocks' => [und_b('users', 'Users', count((array)($j['usageByUser'] ?? []))), und_b('photos', 'Photos', (int)($j['photos'] ?? 0)),
                       und_b('videos', 'Videos', (int)($j['videos'] ?? 0)), und_b('storage', 'Storage', (float)($j['usage'] ?? 0), 'bytes')]];
}

function und_w_peanut($s) {
  $o = $s['username'] !== '' ? ['basic' => [$s['username'], $s['password']]] : [];
  $j = und_get(und_u($s['url'], '/api/v1/devices/' . rawurlencode($s['key'] ?: 'ups')), $o);
  return ['blocks' => [und_b('battery_charge', 'Battery charge', (float)($j['battery.charge'] ?? 0), 'pct'), und_b('ups_load', 'UPS load', (float)($j['ups.load'] ?? 0), 'pct'),
                       und_b('ups_status', 'UPS status', und_ups_status((string)($j['ups.status'] ?? '')), 'text')]];
}

/* ---------- calendar (Radarr + Sonarr) ---------- */

function und_w_calendar($s, $cfg) {
  $o = $s['options'];
  $prev = max(0, (int)($o['previous_days'] ?? 0)); $ahead = max(1, min(90, (int)($o['days_ahead'] ?? 30)));
  $start = date('Y-m-d', time() - $prev * 86400); $end = date('Y-m-d', time() + $ahead * 86400);
  $from = strtotime($start); $ev = []; $errs = [];
  foreach ((array)($o['integrations'] ?? []) as $in) {
    $src = und_service_full($cfg, (string)($in['service'] ?? ''));
    if (!$src) { $errs[] = 'a linked service no longer exists'; continue; }
    $um = !empty($in['unmonitored']) ? 'true' : 'false';
    try {
      if ($src['type'] === 'radarr') {
        $j = und_get(und_u($src['url'], "/api/v3/calendar?start=$start&end=$end&unmonitored=$um"), und_arr_h($src));
        foreach ((array)$j as $m) foreach (['inCinemas' => 'In cinemas', 'digitalRelease' => 'Digital release', 'physicalRelease' => 'Physical release'] as $k => $lab) {
          if (empty($m[$k])) continue;
          $ts = strtotime($m[$k]);
          if ($ts >= $from && $ts <= time() + $ahead * 86400) $ev[] = ['ts' => $ts, 'title' => $m['title'] . ' - ' . $lab, 'done' => !empty($m['hasFile']), 'src' => 'radarr'];
        }
      } elseif ($src['type'] === 'sonarr') {
        $j = und_get(und_u($src['url'], "/api/v3/calendar?start=$start&end=$end&unmonitored=$um&includeSeries=true"), und_arr_h($src));
        foreach ((array)$j as $e) {
          if (empty($e['airDateUtc'])) continue;
          $ev[] = ['ts' => strtotime($e['airDateUtc']), 'title' => (string)($e['series']['title'] ?? 'Unknown'), 'sub' => 'S' . sprintf('%02d', (int)$e['seasonNumber']) . 'E' . sprintf('%02d', (int)$e['episodeNumber']) . ' ' . ($e['title'] ?? ''), 'done' => !empty($e['hasFile']), 'src' => 'sonarr'];
        }
      }
    } catch (Exception $e) { $errs[] = $src['name'] . ': ' . $e->getMessage(); }
  }
  if (!$ev && $errs) throw new Exception(implode('; ', $errs));
  usort($ev, function ($a, $b) { return $a['ts'] - $b['ts'] ?: strcmp($a['title'], $b['title']); });
  return ['calendar' => array_slice($ev, 0, max(1, min(50, (int)($o['max_events'] ?? 8)))), 'empty' => 'Nothing coming up'];
}

/* ---------- custom JSON API ---------- */

/** "response.data.items.0.title" -> value */
function und_jpath($data, $path) {
  foreach (explode('.', (string)$path) as $p) {
    if ($p === '') continue;
    if (is_array($data) && array_key_exists($p, $data)) $data = $data[$p];
    elseif (is_array($data) && ctype_digit($p) && array_key_exists((int)$p, $data)) $data = $data[(int)$p];
    else return null;
  }
  return $data;
}

function und_w_customapi($s) {
  $o = $s['options'];
  $h = isset($o['headers']) && is_array($o['headers']) ? array_map('strval', $o['headers']) : [];
  foreach ($h as $k => $v) $h[$k] = str_replace('{{KEY}}', $s['key'], $v);
  $opts = ['headers' => $h, 'method' => strtoupper((string)($o['method'] ?? 'GET'))];
  if ($s['username'] !== '') $opts['basic'] = [$s['username'], $s['password']];
  $j = und_get(str_replace('{{KEY}}', rawurlencode($s['key']), $s['url']), $opts);
  $maps = (array)($o['mappings'] ?? []);
  if (($o['display'] ?? 'block') === 'list') {
    $list = [];
    foreach ($maps as $m) {
      $v = und_jpath($j, $m['field'] ?? '');
      if ($v === null) continue;
      $row = ['title' => (string)(is_scalar($v) ? $v : json_encode($v)), 'label' => (string)($m['label'] ?? '')];
      if (!empty($m['additional'])) {
        $a = und_jpath($j, $m['additional']['field'] ?? '');
        $fmt = (string)($m['additional']['format'] ?? 'text');
        if (in_array($fmt, ['relativeDate', 'date'], true)) $row['ts'] = (int)round((float)$a * (float)($m['additional']['scale'] ?? 1) / 1000);
        else $row['right'] = (string)$a;
      }
      $list[] = $row;
    }
    return ['list' => $list];
  }
  $b = [];
  foreach ($maps as $i => $m) {
    $v = und_jpath($j, $m['field'] ?? '');
    $fmt = (string)($m['format'] ?? 'n');
    if ($fmt === 'number') $fmt = 'n';
    if ($fmt === 'percent') $fmt = 'pct';
    if (!in_array($fmt, ['n', 'bytes', 'rate', 'ms', 'pct', 'text'], true)) $fmt = 'text';
    if (is_numeric($v) && isset($m['scale'])) $v = (float)$v * (float)$m['scale'];
    $b[] = und_b((string)($m['field'] ?? $i), (string)($m['label'] ?? $m['field'] ?? ''), is_scalar($v) || $v === null ? $v : count((array)$v), $fmt);
  }
  return ['blocks' => $b];
}
