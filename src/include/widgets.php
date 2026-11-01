<?php
/*
 * UnDash - more service widgets, in the shapes described in services.php. Each one only reads, from the app's own documented API.
 */

function und_bearer($s) { return ['headers' => ['Authorization' => 'Bearer ' . $s['key'], 'Accept' => 'application/json']]; }
function und_token_h($s) { return ['headers' => ['Authorization' => 'Token ' . $s['key'], 'Accept' => 'application/json']]; }
function und_basic($s) { return $s['username'] !== '' ? ['basic' => [$s['username'], $s['password']]] : []; }

/** Names of what is failing, for a widget's list: at most 8 rows. */
function und_down_list(array $names, $right = 'down') { return array_map(function ($n) use ($right) { return ['title' => und_clip((string)$n, 80), 'right' => $right]; }, array_slice($names, 0, 8)); }

/* ---------- downloaders ---------- */

function und_w_deluge($s) {
  $call = function ($cookie, $method, array $params) use ($s) {
    return und_http('POST', und_u($s['url'], '/json'), ['cookie' => $cookie, 'timeout' => 15, 'body' => json_encode(['method' => $method, 'params' => $params, 'id' => 1]), 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json']]);
  };
  $r = $call('', 'auth.login', [$s['password']]);
  if ($r['err'] !== '') throw new Exception(und_redact($r['err']));
  if (empty($r['json']['result'])) throw new Exception('Deluge login failed (HTTP ' . $r['code'] . ').');
  $cookie = '';
  foreach (($r['headers']['set-cookie'] ?? []) as $c) if (preg_match('/^(_session_id=[^;]+)/', $c, $m)) $cookie = $m[1];
  $d = $call($cookie, 'web.update_ui', [['progress'], new stdClass()])['json']['result'] ?? null;
  if (!is_array($d)) throw new Exception('Deluge did not answer: is its web UI connected to a daemon?');
  $leech = $seed = 0;
  foreach ((array)($d['torrents'] ?? []) as $t) { if ((float)($t['progress'] ?? 0) < 100) $leech++; else $seed++; }
  return ['blocks' => [und_b('leech', 'Leech', $leech), und_b('download', 'Download', (float)($d['stats']['download_rate'] ?? 0), 'rate'), und_b('seed', 'Seed', $seed), und_b('upload', 'Upload', (float)($d['stats']['upload_rate'] ?? 0), 'rate')]];
}

function und_w_transmission($s) {
  $url = und_u($s['url'], '/transmission/rpc');
  $o = und_basic($s) + ['timeout' => 15, 'body' => json_encode(['method' => 'torrent-get', 'arguments' => ['fields' => ['percentDone', 'rateDownload', 'rateUpload']]]), 'headers' => ['Content-Type' => 'application/json']];
  $r = und_http('POST', $url, $o);
  if ($r['code'] === 409) { $o['headers']['X-Transmission-Session-Id'] = (string)($r['headers']['x-transmission-session-id'][0] ?? ''); $r = und_http('POST', $url, $o); }   // its guard against forged requests: the first answer hands out the id
  if ($r['err'] !== '') throw new Exception(und_redact($r['err']));
  if ($r['code'] === 401 || $r['code'] === 403) throw new Exception('Not authorised (HTTP ' . $r['code'] . '): check the username and password.');
  if (!is_array($r['json']['arguments']['torrents'] ?? null)) throw new Exception('Transmission did not answer (HTTP ' . $r['code'] . ').');
  $leech = $seed = 0; $dl = $ul = 0;
  foreach ($r['json']['arguments']['torrents'] as $t) { $dl += (float)($t['rateDownload'] ?? 0); $ul += (float)($t['rateUpload'] ?? 0); if ((float)($t['percentDone'] ?? 0) < 1) $leech++; else $seed++; }
  return ['blocks' => [und_b('leech', 'Leech', $leech), und_b('download', 'Download', $dl, 'rate'), und_b('seed', 'Seed', $seed), und_b('upload', 'Upload', $ul, 'rate')]];
}

function und_w_nzbget($s) {
  $j = und_get(und_u($s['url'], '/jsonrpc'), und_basic($s) + ['method' => 'POST', 'body' => json_encode(['method' => 'status', 'params' => [], 'id' => 1]), 'headers' => ['Content-Type' => 'application/json']]);
  $d = $j['result'] ?? null;
  if (!is_array($d)) throw new Exception('NZBGet did not answer.');
  return ['blocks' => [und_b('rate', 'Rate', (float)($d['DownloadRate'] ?? 0), 'rate'), und_b('remaining', 'Remaining', (float)($d['RemainingSizeMB'] ?? 0) * 1048576, 'bytes'), und_b('downloaded', 'Downloaded', (float)($d['DownloadedSizeMB'] ?? 0) * 1048576, 'bytes')]];
}

/* ---------- media ---------- */

/** Navidrome, and any other server with the Subsonic API: the password never travels, only a salted hash of it. */
function und_w_navidrome($s) {
  $salt = bin2hex(random_bytes(6));
  $call = function ($what) use ($s, $salt) {
    $j = und_get(und_u($s['url'], '/rest/' . $what . '.view?' . http_build_query(['u' => $s['username'], 't' => md5($s['password'] . $salt), 's' => $salt, 'v' => '1.16.1', 'c' => 'UnDash', 'f' => 'json'])));
    $r = $j['subsonic-response'] ?? null;
    if (!is_array($r)) throw new Exception('The answer did not come from a Subsonic server.');
    if (($r['status'] ?? '') !== 'ok') throw new Exception((string)($r['error']['message'] ?? 'Navidrome refused the request.'));
    return $r;
  };
  $scan = (array)($call('getScanStatus')['scanStatus'] ?? []);
  $now = (array)($call('getNowPlaying')['nowPlaying']['entry'] ?? []);
  $streams = [];
  foreach ($now as $e) if (is_array($e)) $streams[] = ['title' => (string)($e['title'] ?? ''), 'sub' => (string)($e['artist'] ?? ''), 'user' => (string)($e['username'] ?? ''), 'state' => 'playing', 'pos' => 0, 'dur' => (int)($e['duration'] ?? 0)];
  $b = [und_b('streams', 'Playing', count($streams)), und_b('songs', 'Songs', (int)($scan['count'] ?? 0))];
  if (isset($scan['folderCount'])) $b[] = und_b('folders', 'Folders', (int)$scan['folderCount']);
  return ['blocks' => $b, 'streams' => $streams, 'empty' => 'Nothing playing'];
}

function und_w_audiobookshelf($s) {
  $h = und_bearer($s);
  $n = ['book' => 0, 'podcast' => 0]; $dur = 0; $size = 0;
  foreach (array_slice((array)(und_get(und_u($s['url'], '/api/libraries'), $h)['libraries'] ?? []), 0, 20) as $l) {
    if (!is_array($l) || empty($l['id'])) continue;
    $st = und_get(und_u($s['url'], '/api/libraries/' . rawurlencode((string)$l['id']) . '/stats'), $h);
    if (isset($n[$l['mediaType'] ?? ''])) $n[$l['mediaType']] += (int)($st['totalItems'] ?? 0);
    $dur += (float)($st['totalDuration'] ?? 0); $size += (float)($st['totalSize'] ?? 0);
  }
  return ['blocks' => [und_b('books', 'Books', $n['book']), und_b('podcasts', 'Podcasts', $n['podcast']), und_b('duration', 'Duration', $dur, 'dur'), und_b('size', 'Size', $size, 'bytes')]];
}

function und_w_kavita($s) {
  $a = und_get(und_u($s['url'], '/api/Plugin/authenticate?' . http_build_query(['apiKey' => $s['key'], 'pluginName' => 'UnDash'])), ['method' => 'POST', 'body' => '', 'headers' => ['Accept' => 'application/json']]);
  if (empty($a['token'])) throw new Exception('Kavita refused the API key.');
  $j = und_get(und_u($s['url'], '/api/Stats/server/stats'), ['headers' => ['Authorization' => 'Bearer ' . $a['token'], 'Accept' => 'application/json']]);
  return ['blocks' => [und_b('seriesCount', 'Series', (int)($j['seriesCount'] ?? 0)), und_b('volumes', 'Volumes', (int)($j['volumeCount'] ?? 0)), und_b('totalFiles', 'Files', (int)($j['totalFiles'] ?? 0)), und_b('size', 'Size', (float)($j['totalSize'] ?? 0), 'bytes')]];
}

function und_w_komga($s) {
  $o = $s['key'] !== '' ? ['headers' => ['X-API-Key' => $s['key'], 'Accept' => 'application/json']] : und_basic($s) + ['headers' => ['Accept' => 'application/json']];
  $total = function ($p) use ($s, $o) { return (int)(und_get(und_u($s['url'], '/api/v1/' . $p . '?size=1'), $o)['totalElements'] ?? 0); };
  return ['blocks' => [und_b('libraries', 'Libraries', count((array)und_get(und_u($s['url'], '/api/v1/libraries'), $o))), und_b('series', 'Series', $total('series')), und_b('books', 'Books', $total('books'))]];
}

function und_w_romm($s) {
  $j = und_get(und_u($s['url'], '/api/stats'), und_basic($s));
  return ['blocks' => [und_b('platforms', 'Platforms', (int)($j['PLATFORMS'] ?? 0)), und_b('totalRoms', 'ROMs', (int)($j['ROMS'] ?? 0)), und_b('saves', 'Saves', (int)($j['SAVES'] ?? 0)),
    und_b('states', 'States', (int)($j['STATES'] ?? 0)), und_b('screenshots', 'Screenshots', (int)($j['SCREENSHOTS'] ?? 0)), und_b('totalfilesize', 'Size', (float)($j['TOTAL_FILESIZE_BYTES'] ?? 0), 'bytes')]];
}

function und_w_jellystat($s) {
  $j = und_get(und_u($s['url'], '/stats/getViewsByLibraryType?days=30'), ['headers' => ['x-api-token' => $s['key'], 'Accept' => 'application/json']]);
  return ['blocks' => [und_b('movies', 'Movie views', (int)($j['Movie'] ?? 0)), und_b('series', 'Episode views', (int)($j['Series'] ?? 0)), und_b('songs', 'Song views', (int)($j['Audio'] ?? 0)), und_b('other', 'Other views', (int)($j['Other'] ?? 0))]];
}

function und_w_tubearchivist($s) {
  $h = und_token_h($s);
  $total = function ($p) use ($s, $h) { $j = und_get(und_u($s['url'], '/api/' . $p), $h); return (int)($j['paginate']['total_hits'] ?? $j['pagination']['total_hits'] ?? 0); };
  return ['blocks' => [und_b('downloads', 'Queued', $total('download/?filter=pending')), und_b('videos', 'Videos', $total('video/')), und_b('channels', 'Channels', $total('channel/')), und_b('playlists', 'Playlists', $total('playlist/'))]];
}

function und_w_ombi($s) {
  $j = und_get(und_u($s['url'], '/api/v1/Request/count'), ['headers' => ['ApiKey' => $s['key'], 'Accept' => 'application/json']]);
  return ['blocks' => [und_b('pending', 'Pending', (int)($j['pending'] ?? 0)), und_b('approved', 'Approved', (int)($j['approved'] ?? 0)), und_b('available', 'Available', (int)($j['available'] ?? 0))]];
}

function und_w_frigate($s) {
  $j = und_get(und_u($s['url'], '/api/stats'));
  $speed = [];
  foreach ((array)($j['detectors'] ?? []) as $d) if (isset($d['inference_speed'])) $speed[] = (float)$d['inference_speed'];
  return ['blocks' => [und_b('cameras', 'Cameras', count((array)($j['cameras'] ?? []))), und_b('detectors', 'Detectors', count((array)($j['detectors'] ?? []))),
    und_b('inference', 'Inference', $speed ? round(array_sum($speed) / count($speed), 1) : null, 'ms'), und_b('uptime', 'Uptime', (int)($j['service']['uptime'] ?? 0), 'dur')]];
}

/* ---------- files and home ---------- */

function und_w_nextcloud($s) {
  $h = ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'];
  $o = $s['key'] !== '' ? ['headers' => $h + ['NC-Token' => $s['key']]] : und_basic($s) + ['headers' => $h];
  $d = und_get(und_u($s['url'], '/ocs/v2.php/apps/serverinfo/api/v1/info?format=json'), $o)['ocs']['data'] ?? null;
  if (!is_array($d)) throw new Exception('Nextcloud did not answer: the Monitoring app (serverinfo) has to be on, and the account an admin.');
  $st = (array)($d['nextcloud']['storage'] ?? []);
  return ['blocks' => [und_b('users', 'Users', (int)($st['num_users'] ?? 0)), und_b('active', 'Active today', (int)($d['activeUsers']['last24hours'] ?? 0)), und_b('files', 'Files', (int)($st['num_files'] ?? 0)),
    und_b('free', 'Free', (float)($d['nextcloud']['system']['freespace'] ?? 0), 'bytes')]];
}

function und_w_paperless($s) {
  $j = und_get(und_u($s['url'], '/api/statistics/'), und_token_h($s));
  return ['blocks' => [und_b('total', 'Documents', (int)($j['documents_total'] ?? 0)), und_b('inbox', 'Inbox', (int)($j['documents_inbox'] ?? 0))]];
}

function und_w_filebrowser($s) {
  $r = und_get(und_u($s['url'], '/api/login'), ['method' => 'POST', 'raw' => true, 'body' => json_encode(['username' => $s['username'], 'password' => $s['password'], 'recaptcha' => '']), 'headers' => ['Content-Type' => 'application/json']]);
  $j = und_get(und_u($s['url'], '/api/usage/'), ['headers' => ['X-Auth' => trim($r['body'])]]);
  $total = (float)($j['total'] ?? 0); $used = (float)($j['used'] ?? 0);
  return ['blocks' => [und_b('available', 'Available', $total - $used, 'bytes'), und_b('used', 'Used', $used, 'bytes'), und_b('total', 'Total', $total, 'bytes')]];
}

function und_w_syncthing($s) {
  $h = ['headers' => ['X-API-Key' => $s['key']]];
  $c = und_get(und_u($s['url'], '/rest/system/connections'), $h);
  $on = 0; foreach ((array)($c['connections'] ?? []) as $x) if (!empty($x['connected'])) $on++;
  $d = und_get(und_u($s['url'], '/rest/db/completion'), $h);
  return ['blocks' => [und_b('folders', 'Folders', count((array)und_get(und_u($s['url'], '/rest/config/folders'), $h))), und_b('devices', 'Devices on', $on),
    und_b('synced', 'Synced', isset($d['completion']) ? round((float)$d['completion'], 1) : null, 'pct'), und_b('needed', 'To sync', (float)($d['needBytes'] ?? 0), 'bytes')]];
}

function und_w_karakeep($s) {
  $j = und_get(und_u($s['url'], '/api/v1/users/me/stats'), und_bearer($s));
  return ['blocks' => [und_b('bookmarks', 'Bookmarks', (int)($j['numBookmarks'] ?? 0)), und_b('favorites', 'Favorites', (int)($j['numFavorites'] ?? 0)), und_b('archived', 'Archived', (int)($j['numArchived'] ?? 0)), und_b('lists', 'Lists', (int)($j['numLists'] ?? 0)), und_b('tags', 'Tags', (int)($j['numTags'] ?? 0))]];
}

function und_w_mealie($s) {
  $h = und_bearer($s);
  try { $j = und_get(und_u($s['url'], '/api/households/statistics'), $h); }       // Mealie 2; version 1 kept this under groups
  catch (Exception $e) { $j = und_get(und_u($s['url'], '/api/groups/statistics'), $h); }
  return ['blocks' => [und_b('recipes', 'Recipes', (int)($j['totalRecipes'] ?? 0)), und_b('users', 'Users', (int)($j['totalUsers'] ?? 0)), und_b('categories', 'Categories', (int)($j['totalCategories'] ?? 0)), und_b('tags', 'Tags', (int)($j['totalTags'] ?? 0))]];
}

function und_w_miniflux($s) {
  $j = und_get(und_u($s['url'], '/v1/feeds/counters'), ['headers' => ['X-Auth-Token' => $s['key']]]);
  return ['blocks' => [und_b('unread', 'Unread', array_sum(array_map('intval', (array)($j['unreads'] ?? [])))), und_b('read', 'Read', array_sum(array_map('intval', (array)($j['reads'] ?? []))))]];
}

function und_w_freshrss($s) {
  $base = und_u($s['url'], '/api/greader.php');
  $r = und_get($base . '/accounts/ClientLogin', ['method' => 'POST', 'raw' => true, 'body' => http_build_query(['Email' => $s['username'], 'Passwd' => $s['password']]), 'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']]);
  if (!preg_match('/^Auth=(\S+)/m', $r['body'], $m)) throw new Exception('FreshRSS login failed: use the API password from its profile page.');
  $h = ['headers' => ['Authorization' => 'GoogleLogin auth=' . $m[1]]];
  $unread = 0;
  foreach ((array)(und_get($base . '/reader/api/0/unread-count?output=json', $h)['unreadcounts'] ?? []) as $c) if (preg_match('#/state/com\.google/reading-list$#', (string)($c['id'] ?? ''))) $unread = (int)($c['count'] ?? 0);
  return ['blocks' => [und_b('unread', 'Unread', $unread), und_b('subscriptions', 'Feeds', count((array)(und_get($base . '/reader/api/0/subscription/list?output=json', $h)['subscriptions'] ?? [])))]];
}

/** Gitea and Forgejo: the totals are in the x-total-count header of a list of one. */
function und_w_gitea($s) {
  $h = ['headers' => ['Authorization' => 'token ' . $s['key'], 'Accept' => 'application/json']];
  $total = function ($p) use ($s, $h) { return (int)(und_get(und_u($s['url'], '/api/v1/' . $p), $h + ['raw' => true])['headers']['x-total-count'][0] ?? 0); };
  return ['blocks' => [und_b('notifications', 'Notifications', (int)(und_get(und_u($s['url'], '/api/v1/notifications/new'), $h)['new'] ?? 0)), und_b('issues', 'Issues', $total('repos/issues/search?state=open&type=issues&limit=1')),
    und_b('pulls', 'Pull requests', $total('repos/issues/search?state=open&type=pulls&limit=1')), und_b('repos', 'Repositories', $total('repos/search?limit=1'))]];
}

function und_w_gotify($s) {
  $h = ['headers' => ['X-Gotify-Key' => $s['key']]];
  $m = und_get(und_u($s['url'], '/message?limit=200'), $h);
  return ['blocks' => [und_b('apps', 'Apps', count((array)und_get(und_u($s['url'], '/application'), $h))), und_b('clients', 'Clients', count((array)und_get(und_u($s['url'], '/client'), $h))), und_b('messages', 'Messages', count((array)($m['messages'] ?? [])))]];
}

function und_w_changedetection($s) {
  $w = und_get(und_u($s['url'], '/api/v1/watch'), ['headers' => ['x-api-key' => $s['key']], 'timeout' => 20]);
  $new = []; $bad = 0;
  foreach ((array)$w as $x) {
    if (!is_array($x)) continue;
    if (!empty($x['last_error'])) $bad++;
    if ((int)($x['last_changed'] ?? 0) > (int)($x['last_viewed'] ?? 0)) $new[] = ['title' => und_clip((string)($x['title'] ?? '') ?: (string)($x['url'] ?? ''), 80), 'ts' => (int)$x['last_changed']];
  }
  usort($new, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
  $r = ['blocks' => [und_b('total', 'Watching', count((array)$w)), und_b('changed', 'Changed', count($new)), und_b('errors', 'Errors', $bad)]];
  if ($new) $r['list'] = array_slice($new, 0, 8);
  return $r;
}

/* ---------- network ---------- */

function und_w_technitium($s) {
  $j = und_get(und_u($s['url'], '/api/dashboard/stats/get?' . http_build_query(['token' => $s['key'], 'type' => 'LastDay'])));
  if (($j['status'] ?? '') !== 'ok') throw new Exception('Technitium: ' . und_clip((string)($j['errorMessage'] ?? 'request failed'), 120));
  $st = (array)($j['response']['stats'] ?? []);
  $q = (int)($st['totalQueries'] ?? 0); $bl = (int)($st['totalBlocked'] ?? 0);
  return ['blocks' => [und_b('queries', 'Queries', $q), und_b('blocked', 'Blocked', $bl), und_b('blocked_percent', 'Blocked %', $q ? round($bl / $q * 100, 1) : 0, 'pct'), und_b('clients', 'Clients', (int)($st['totalClients'] ?? 0))]];
}

function und_w_traefik($s) {
  $j = und_get(und_u($s['url'], '/api/overview'), und_basic($s));
  $http = (array)($j['http'] ?? []); $bad = 0;
  foreach ($http as $p) $bad += (int)($p['errors'] ?? 0);
  return ['blocks' => [und_b('routers', 'Routers', (int)($http['routers']['total'] ?? 0)), und_b('services', 'Services', (int)($http['services']['total'] ?? 0)), und_b('middleware', 'Middleware', (int)($http['middlewares']['total'] ?? 0)), und_b('errors', 'Errors', $bad)]];
}

function und_w_caddy($s) {
  $j = und_get(und_u($s['url'], '/reverse_proxy/upstreams'));
  $req = $fails = 0;
  foreach ((array)$j as $u) { $req += (int)($u['num_requests'] ?? 0); $fails += (int)($u['fails'] ?? 0); }
  return ['blocks' => [und_b('upstreams', 'Upstreams', count((array)$j)), und_b('requests', 'Requests now', $req), und_b('fails', 'Failures', $fails)]];
}

function und_w_headscale($s) {
  $on = $off = 0;
  foreach ((array)(und_get(und_u($s['url'], '/api/v1/node'), und_bearer($s))['nodes'] ?? []) as $n) { if (!empty($n['online'])) $on++; else $off++; }
  return ['blocks' => [und_b('online', 'Online', $on), und_b('offline', 'Offline', $off), und_b('total', 'Nodes', $on + $off)]];
}

/** UniFi: an API key reads a UniFi OS console's official API; a local account reads either kind of controller's health. */
function und_w_unifi($s) {
  if ($s['key'] !== '') {
    $h = ['headers' => ['X-API-KEY' => $s['key'], 'Accept' => 'application/json']];
    $base = und_u($s['url'], '/proxy/network/integration/v1/sites');
    $d = $c = 0;
    foreach (array_slice((array)(und_get($base, $h)['data'] ?? []), 0, 10) as $site) {
      $id = rawurlencode((string)($site['id'] ?? ''));
      $d += (int)(und_get("$base/$id/devices?limit=1", $h)['totalCount'] ?? 0); $c += (int)(und_get("$base/$id/clients?limit=1", $h)['totalCount'] ?? 0);
    }
    return ['blocks' => [und_b('devices', 'Devices', $d), und_b('clients', 'Clients', $c)]];
  }
  $body = ['body' => json_encode(['username' => $s['username'], 'password' => $s['password']]), 'headers' => ['Content-Type' => 'application/json']];
  $os = und_http('POST', und_u($s['url'], '/api/auth/login'), $body);
  $r = $os['code'] === 200 ? $os : und_http('POST', und_u($s['url'], '/api/login'), $body);
  if ($r['err'] !== '') throw new Exception(und_redact($r['err']));
  if ($r['code'] !== 200) throw new Exception('UniFi login failed (HTTP ' . $r['code'] . '): use a local account.');
  $cookie = [];
  foreach (($r['headers']['set-cookie'] ?? []) as $c) $cookie[] = explode(';', $c, 2)[0];
  $site = preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string)($s['options']['site'] ?? '')) ? $s['options']['site'] : 'default';
  $j = und_get(und_u($s['url'], ($os['code'] === 200 ? '/proxy/network' : '') . '/api/s/' . $site . '/stat/health'), ['cookie' => implode('; ', $cookie)]);
  $h = []; foreach ((array)($j['data'] ?? []) as $x) if (is_array($x)) $h[(string)($x['subsystem'] ?? '')] = $x;
  $users = (int)($h['lan']['num_user'] ?? 0) + (int)($h['wlan']['num_user'] ?? 0); $guests = (int)($h['lan']['num_guest'] ?? 0) + (int)($h['wlan']['num_guest'] ?? 0);
  return ['blocks' => [und_b('wan', 'WAN', (string)($h['wan']['status'] ?? 'unknown'), 'text'), und_b('users', 'Users', $users), und_b('guests', 'Guests', $guests), und_b('devices', 'Devices', (int)($h['lan']['num_adopted'] ?? 0) + (int)($h['wlan']['num_adopted'] ?? 0))]];
}

/* ---------- monitoring and containers ---------- */

function und_w_healthchecks($s) {
  $n = ['up' => 0, 'down' => 0, 'grace' => 0, 'paused' => 0]; $down = [];
  foreach ((array)(und_get(und_u($s['url'], '/api/v3/checks/'), ['headers' => ['X-Api-Key' => $s['key']]])['checks'] ?? []) as $c) {
    $st = (string)($c['status'] ?? '');
    if (isset($n[$st])) $n[$st]++;
    if ($st === 'down') $down[] = (string)($c['name'] ?? '');
  }
  $r = ['blocks' => [und_b('up', 'Up', $n['up']), und_b('down', 'Down', $n['down']), und_b('late', 'Late', $n['grace']), und_b('paused', 'Paused', $n['paused'])]];
  if ($down) $r['list'] = und_down_list($down);
  return $r;
}

function und_w_gatus($s) {
  $up = 0; $down = [];
  foreach ((array)und_get(und_u($s['url'], '/api/v1/endpoints/statuses?pageSize=100')) as $e) {
    $res = (array)($e['results'] ?? []); $last = $res ? end($res) : null;
    if (!is_array($last)) continue;
    if (!empty($last['success'])) $up++; else $down[] = trim(($e['group'] ?? '') !== '' ? $e['group'] . ' / ' . ($e['name'] ?? '') : (string)($e['name'] ?? ''));
  }
  $r = ['blocks' => [und_b('up', 'Up', $up), und_b('down', 'Down', count($down))]];
  if ($down) $r['list'] = und_down_list($down);
  return $r;
}

/** Beszel keeps its data in PocketBase; version 0.9 and later log in as a superuser, older ones as a user. */
function und_w_beszel($s) {
  $body = ['body' => json_encode(['identity' => $s['username'], 'password' => $s['password']]), 'headers' => ['Content-Type' => 'application/json']];
  $r = und_http('POST', und_u($s['url'], '/api/collections/_superusers/auth-with-password'), $body);
  if (empty($r['json']['token'])) $r = und_http('POST', und_u($s['url'], '/api/collections/users/auth-with-password'), $body);
  if ($r['err'] !== '') throw new Exception(und_redact($r['err']));
  if (empty($r['json']['token'])) throw new Exception('Beszel login failed: it needs a superuser account (email and password).');
  $n = ['up' => 0, 'down' => 0, 'paused' => 0]; $down = [];
  foreach ((array)(und_get(und_u($s['url'], '/api/collections/systems/records?perPage=200'), ['headers' => ['Authorization' => $r['json']['token']]])['items'] ?? []) as $x) {
    $st = (string)($x['status'] ?? '');
    if (isset($n[$st])) $n[$st]++;
    if ($st === 'down') $down[] = (string)($x['name'] ?? '');
  }
  $o = ['blocks' => [und_b('up', 'Up', $n['up']), und_b('down', 'Down', $n['down']), und_b('paused', 'Paused', $n['paused'])]];
  if ($down) $o['list'] = und_down_list($down);
  return $o;
}

function und_w_netdata($s) {
  $a = (array)(und_get(und_u($s['url'], '/api/v1/info'))['alarms'] ?? []);
  return ['blocks' => [und_b('critical', 'Critical', (int)($a['critical'] ?? 0)), und_b('warning', 'Warning', (int)($a['warning'] ?? 0)), und_b('normal', 'Normal', (int)($a['normal'] ?? 0))]];
}

function und_w_prometheus($s) {
  $up = 0; $down = []; $other = 0;
  foreach ((array)(und_get(und_u($s['url'], '/api/v1/targets?state=active'), und_basic($s))['data']['activeTargets'] ?? []) as $t) {
    $h = (string)($t['health'] ?? '');
    if ($h === 'up') $up++; elseif ($h === 'down') $down[] = trim((string)($t['labels']['job'] ?? '') . ' ' . (string)($t['labels']['instance'] ?? '')); else $other++;
  }
  $r = ['blocks' => [und_b('up', 'Up', $up), und_b('down', 'Down', count($down)), und_b('total', 'Targets', $up + count($down) + $other)]];
  if ($down) $r['list'] = und_down_list($down);
  return $r;
}

function und_w_grafana($s) {
  $o = $s['key'] !== '' ? und_bearer($s) : und_basic($s);
  $j = und_get(und_u($s['url'], '/api/admin/stats'), $o);
  $b = [und_b('dashboards', 'Dashboards', (int)($j['dashboards'] ?? 0)), und_b('datasources', 'Data sources', (int)($j['datasources'] ?? 0)), und_b('users', 'Users', (int)($j['users'] ?? 0))];
  try {       // alerts have their own permission: the other numbers still show without it
    $firing = 0;
    foreach ((array)(und_get(und_u($s['url'], '/api/prometheus/grafana/api/v1/alerts'), $o)['data']['alerts'] ?? []) as $a) if (in_array(strtolower((string)($a['state'] ?? '')), ['alerting', 'firing'], true)) $firing++;
    $b[] = und_b('firing', 'Firing', $firing);
  } catch (Exception $e) { }
  return ['blocks' => $b];
}

/** Glances on another machine; this server's own numbers are in the system overview. */
function und_w_glances($s) {
  $g = und_glances_read(rtrim($s['url'], '/'));
  if (isset($g['error'])) throw new Exception($g['error']);
  return ['blocks' => [und_b('cpu', 'CPU', $g['pct'], 'pct'), und_b('memory', 'Memory', $g['mem']['pct'], 'pct'), und_b('uptime', 'Uptime', $g['uptime'], 'dur')]];
}

function und_w_portainer($s) {
  $h = ['headers' => ['X-API-Key' => $s['key'], 'Accept' => 'application/json']];
  $run = $stop = 0;
  foreach (array_slice((array)und_get(und_u($s['url'], '/api/endpoints'), $h), 0, 10) as $e) {
    if (!is_array($e) || !in_array((int)($e['Type'] ?? 0), [1, 2], true) || (int)($e['Status'] ?? 0) !== 1) continue;      // Docker environments that are up
    foreach ((array)und_get(und_u($s['url'], '/api/endpoints/' . (int)$e['Id'] . '/docker/containers/json?all=1'), $h) as $c) { if (($c['State'] ?? '') === 'running') $run++; else $stop++; }
  }
  return ['blocks' => [und_b('running', 'Running', $run), und_b('stopped', 'Stopped', $stop), und_b('total', 'Total', $run + $stop)]];
}

function und_w_wud($s) {
  $all = (array)und_get(und_u($s['url'], '/api/containers'), und_basic($s));
  $new = array_values(array_filter($all, function ($c) { return is_array($c) && !empty($c['updateAvailable']); }));
  $r = ['blocks' => [und_b('monitored', 'Monitored', count($all)), und_b('updates', 'Updates', count($new))]];
  if ($new) $r['list'] = und_down_list(array_column($new, 'name'), 'update');
  return $r;
}

/* ---------- Minecraft ---------- */

/** A Minecraft server's answer to the status question of its network protocol, read from an open connection. */
function und_mc_status($f, $host, $port) {
  $vi = function ($n) { $o = ''; do { $b = $n & 0x7f; $n >>= 7; $o .= chr($n ? $b | 0x80 : $b); } while ($n); return $o; };
  $pack = function ($d) use ($vi) { return $vi(strlen($d)) . $d; };
  fwrite($f, $pack("\0" . $vi(47) . $pack($host) . pack('n', $port) . "\1") . $pack("\0"));
  $read = function () use ($f) { $n = 0; for ($i = 0; $i < 5; $i++) { $c = fread($f, 1); if ($c === '' || $c === false) throw new Exception('The Minecraft server closed the connection.'); $b = ord($c); $n |= ($b & 0x7f) << (7 * $i); if (!($b & 0x80)) return $n; } throw new Exception('That is not a Minecraft server.'); };
  $read();
  if ($read() !== 0) throw new Exception('That is not a Minecraft server.');
  $len = $read();
  if ($len < 2 || $len > 1048576) throw new Exception('That is not a Minecraft server.');
  $json = '';
  while (strlen($json) < $len) { $c = fread($f, $len - strlen($json)); if ($c === '' || $c === false) break; $json .= $c; }
  $j = json_decode($json, true);
  if (!is_array($j)) throw new Exception('That is not a Minecraft server.');
  return $j;
}

function und_w_minecraft($s) {
  $u = trim($s['url']);
  $p = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $u) ? $u : 'tcp://' . $u);
  $host = (string)($p['host'] ?? ''); $port = (int)($p['port'] ?? 25565);
  if ($host === '') throw new Exception('Set the address like 192.168.1.10:25565.');
  $t = microtime(true);
  $f = @fsockopen($host, $port, $no, $msg, 4);
  if (!$f) throw new Exception('Server offline: nothing answers on ' . $host . ':' . $port . '.');
  stream_set_timeout($f, 4);
  try { $j = und_mc_status($f, $host, $port); } finally { fclose($f); }
  return ['blocks' => [und_b('players', 'Players', (int)($j['players']['online'] ?? 0) . ' / ' . (int)($j['players']['max'] ?? 0), 'text'), und_b('version', 'Version', und_clip((string)($j['version']['name'] ?? ''), 30), 'text'), und_b('ping', 'Ping', round((microtime(true) - $t) * 1000), 'ms')]];
}
