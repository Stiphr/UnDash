<?php
/*
 * UnDash - requests from Overseerr / Jellyseerr (Seerr): pending ones with Approve and Decline, and the latest others
 * with their status. Request lists do not carry titles, so titles and posters are looked up once per movie or show and
 * kept for a day. Posters are the public TMDB images Seerr itself uses. The list is cached for 30 seconds.
 */

function und_seerr_sources(array $cfg) {
  $list = [];
  foreach ($cfg['groups'] as $g) foreach ($g['services'] as $s) if ($s['type'] === 'overseerr') $list[] = $s;
  return $list;
}

/** Title, year and poster of a movie or show, cached for a day. */
function und_seerr_media(array $s, $type, $tmdb) {
  $f = und_tmp('seerr-media.json');
  $cache = und_read_json($f, []);
  $k = $s['id'] . ':' . $type . ':' . $tmdb;
  if (isset($cache[$k]) && time() - (int)$cache[$k]['at'] < 86400) return $cache[$k];
  $m = und_get(und_u($s['url'], '/api/v1/' . ($type === 'tv' ? 'tv' : 'movie') . '/' . (int)$tmdb), und_arr_h($s) + ['timeout' => 10]);
  $date = (string)($m['releaseDate'] ?? $m['firstAirDate'] ?? '');
  $row = ['title' => (string)($m['title'] ?? $m['name'] ?? ('TMDB ' . $tmdb)), 'year' => $date !== '' ? (int)substr($date, 0, 4) : 0,
          'poster' => !empty($m['posterPath']) && preg_match('#^/[A-Za-z0-9_.-]+$#', $m['posterPath']) ? 'https://image.tmdb.org/t/p/w92' . $m['posterPath'] : '', 'at' => time()];
  $cache[$k] = $row;
  if (count($cache) > 500) $cache = array_slice($cache, -400, null, true);
  und_write_json($f, $cache);
  return $row;
}

function und_seerr_status($req, $media) {
  $r = (int)$req; $m = (int)$media;
  if ($r === 1) return ['pending', 'Pending', 'warn'];
  if ($r === 3) return ['declined', 'Declined', 'bad'];
  if ($r === 4) return ['failed', 'Failed', 'bad'];
  if ($m === 5) return ['available', 'Available', 'ok'];
  if ($m === 4) return ['partial', 'Partly available', 'ok'];
  if ($m === 3) return ['processing', 'Processing', 'info'];
  return ['approved', 'Approved', 'info'];
}

function und_requests(array $cfg) {
  $src = und_seerr_sources($cfg);
  if (!$src) return ['unconfigured' => true, 'items' => [], 'apps' => []];
  return und_api_cached('requests', 30, function () use ($cfg, $src) {
    $items = []; $apps = [];
    foreach ($src as $s0) {
      $s = und_service_full($cfg, $s0['id']);
      $link = rtrim($s['href'] ?: $s['url'], '/');
      try {
        if (trim($s['url']) === '' || und_has($s['url'], '{{')) throw new Exception($s['name'] . ' has no API URL yet.');
        $pend = und_get(und_u($s['url'], '/api/v1/request?take=20&skip=0&sort=added&filter=pending'), und_arr_h($s) + ['timeout' => 15]);
        $rest = und_get(und_u($s['url'], '/api/v1/request?take=12&skip=0&sort=modified&filter=all'), und_arr_h($s) + ['timeout' => 15]);
        $seen = [];
        foreach (array_merge((array)($pend['results'] ?? []), (array)($rest['results'] ?? [])) as $r) {
          $id = (int)($r['id'] ?? 0);
          if (!$id || isset($seen[$id])) continue; $seen[$id] = 1;
          $type = ($r['type'] ?? $r['media']['mediaType'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
          $tmdb = (int)($r['media']['tmdbId'] ?? 0);
          $m = $tmdb ? und_seerr_media($s, $type, $tmdb) : ['title' => 'Unknown', 'year' => 0, 'poster' => ''];
          [$st, $stLabel, $lvl] = und_seerr_status($r['status'] ?? 0, $r['media']['status'] ?? 0);
          $seasons = $type === 'tv' ? array_map(function ($x) { return (int)($x['seasonNumber'] ?? 0); }, (array)($r['seasons'] ?? [])) : [];
          $items[] = ['id' => $id, 'app' => $s['id'], 'app_name' => $s['name'], 'type' => $type, 'title' => $m['title'], 'year' => $m['year'], 'poster' => $m['poster'],
            'user' => (string)($r['requestedBy']['displayName'] ?? $r['requestedBy']['username'] ?? $r['requestedBy']['email'] ?? ''),
            'at' => !empty($r['createdAt']) ? (int)strtotime($r['createdAt']) : 0, 'is4k' => !empty($r['is4k']), 'seasons' => $seasons,
            'status' => $st, 'status_label' => $stLabel, 'level' => $lvl,
            'href' => preg_match('#^https?://#', $link) && $tmdb ? $link . '/' . $type . '/' . $tmdb : ''];
        }
        $apps[] = ['id' => $s['id'], 'name' => $s['name'], 'error' => '', 'href' => preg_match('#^https?://#', $link) ? $link : ''];
      } catch (Throwable $e) {
        $apps[] = ['id' => $s['id'], 'name' => $s['name'], 'error' => und_redact($e->getMessage(), [$s['key']]), 'href' => ''];
      }
    }
    // pending first (oldest waiting first), then the rest newest first
    usort($items, function ($a, $b) { $pa = $a['status'] === 'pending'; $pb = $b['status'] === 'pending'; return $pa !== $pb ? ($pa ? -1 : 1) : ($pa ? $a['at'] - $b['at'] : $b['at'] - $a['at']); });
    return ['items' => array_slice($items, 0, 30), 'apps' => $apps, 'pending' => count(array_filter($items, function ($x) { return $x['status'] === 'pending'; }))];
  });
}

/** Approve or decline one request (op approve | decline). */
function und_request_action(array $cfg, $app, $id, $op) {
  if (!in_array($op, ['approve', 'decline'], true)) return ['error' => 'Unknown action.'];
  if (!preg_match('/^[a-f0-9]{8}$/', (string)$app) || !ctype_digit((string)$id)) return ['error' => 'Unknown request.'];
  $s0 = null; foreach (und_seerr_sources($cfg) as $x) if ($x['id'] === $app) $s0 = $x;
  if (!$s0) return ['error' => 'Unknown request app.'];
  $s = und_service_full($cfg, $app);
  $lock = und_lock('seerr:' . $app . ':' . $id);
  if (!$lock) return ['error' => 'That request is already being changed.'];
  try {
    $r = und_http('POST', und_u($s['url'], '/api/v1/request/' . (int)$id . '/' . $op), ['headers' => ['X-Api-Key' => $s['key'], 'Content-Type' => 'application/json'], 'body' => '{}', 'timeout' => 20]);
    if ($r['err'] !== '') throw new Exception($r['err']);
    if ($r['code'] === 401 || $r['code'] === 403) throw new Exception('The API key may not ' . $op . ' requests (HTTP ' . $r['code'] . ').');
    if ($r['code'] === 404) throw new Exception('That request no longer exists.');
    if ($r['code'] < 200 || $r['code'] >= 300) throw new Exception('HTTP ' . $r['code'] . (is_array($r['json']) && !empty($r['json']['message']) ? ': ' . und_clip((string)$r['json']['message'], 200) : ''));
    und_unlock($lock);
    @unlink(und_tmp('api-requests.json'));
    und_history_add('request', $s['name'] . ' #' . (int)$id, $op, true);
    return ['ok' => true];
  } catch (Throwable $e) {
    und_unlock($lock);
    $m = und_redact($e->getMessage(), [$s['key']]);
    und_history_add('request', $s['name'] . ' #' . (int)$id, $op, false, $m);
    return ['error' => $s['name'] . ': ' . $m];
  }
}
