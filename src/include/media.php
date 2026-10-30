<?php
/*
 * UnDash - media discovery: upcoming movies, upcoming episodes, recently added movies, recently added episodes.
 * Everything comes from the Radarr and Sonarr widgets already configured (no new integration). Posters are the public
 * TMDB / TheTVDB images Radarr and Sonarr report as remoteUrl, so no API key ever reaches the browser.
 * Each list is cached for 60 seconds per source.
 */

/** The Radarr / Sonarr widget the Media section uses: the one chosen in Settings, else the first one. */
function und_media_source(array $cfg, $type) {
  $want = (string)($cfg['media'][$type] ?? '');
  $first = null;
  foreach ($cfg['groups'] as $g) foreach ($g['services'] as $s) {
    if ($s['type'] !== $type) continue;
    if ($want !== '' && $s['id'] === $want) return $s['id'];
    if ($first === null) $first = $s['id'];
  }
  return $first;
}

function und_media_poster($images) {
  foreach ((array)$images as $i) if (($i['coverType'] ?? '') === 'poster' && preg_match('#^https://#', (string)($i['remoteUrl'] ?? ''))) {
    // TMDB serves any size; the original is several MB, a 185 px wide poster is a few KB
    return preg_replace('#^(https://image\.tmdb\.org/t/p/)original/#', '$1w185/', (string)$i['remoteUrl']);
  }
  return '';
}

function und_media_ep($season, $ep) { return 'S' . sprintf('%02d', (int)$season) . 'E' . sprintf('%02d', (int)$ep); }

/** kind: upcoming_movies | upcoming_episodes | recent_movies | recent_episodes */
function und_media(array $cfg, $kind) {
  $type = in_array($kind, ['upcoming_movies', 'recent_movies'], true) ? 'radarr' : 'sonarr';
  $id = und_media_source($cfg, $type);
  if (!$id) return ['items' => [], 'unconfigured' => true, 'error' => 'Add a ' . ($type === 'radarr' ? 'Radarr' : 'Sonarr') . ' app in Settings > Sections & apps to see this.'];
  $count = max(1, min(50, (int)($cfg['media']['count'] ?? 15)));
  foreach ((array)($cfg['media']['widgets'] ?? []) as $w) if (($w['id'] ?? '') === $kind && isset($w['count'])) $count = max(1, min(50, (int)$w['count']));
  $days = max(7, min(120, (int)($cfg['media']['days_ahead'] ?? 30)));
  return und_api_cached('media-' . $kind . '-' . $id . '-' . $count . '-' . $days, 60, function () use ($cfg, $id, $kind, $count, $days) {
    $s = und_service_full($cfg, $id);
    if (trim($s['url']) === '' || und_has($s['url'], '{{')) return ['items' => [], 'unconfigured' => true, 'error' => $s['name'] . ' has no API URL yet.'];
    $base = rtrim($s['url'], '/'); $link = rtrim($s['href'] ?: $s['url'], '/');
    if (!preg_match('#^https?://#i', $link)) $link = '';        // links only to web addresses
    $h = und_arr_h($s);
    try {
      $items = [];
      if ($kind === 'upcoming_movies') {
        $start = gmdate('Y-m-d'); $end = gmdate('Y-m-d', time() + $days * 86400);
        foreach ((array)und_get("$base/api/v3/calendar?start=$start&end=$end&unmonitored=false", $h + ['timeout' => 15]) as $m) {
          // the next release of each movie, and which kind of release it is
          $best = null;
          foreach (['inCinemas' => 'In cinemas', 'digitalRelease' => 'Digital', 'physicalRelease' => 'Physical'] as $k => $lab) {
            if (empty($m[$k])) continue;
            $ts = strtotime($m[$k]);
            if ($ts >= strtotime($start) && (!$best || $ts < $best[0])) $best = [$ts, $lab];
          }
          if (!$best) continue;
          $items[] = ['title' => (string)$m['title'], 'year' => (int)($m['year'] ?? 0), 'ts' => $best[0], 'when' => $best[1], 'poster' => und_media_poster($m['images'] ?? []),
                      'href' => !empty($m['titleSlug']) ? "$link/movie/" . rawurlencode($m['titleSlug']) : $link, 'done' => !empty($m['hasFile'])];
        }
        usort($items, function ($a, $b) { return $a['ts'] - $b['ts']; });
      } elseif ($kind === 'upcoming_episodes') {
        $start = gmdate('Y-m-d\TH:i:s\Z', time() - 6 * 3600); $end = gmdate('Y-m-d', time() + $days * 86400);
        foreach ((array)und_get("$base/api/v3/calendar?start=$start&end=$end&unmonitored=false&includeSeries=true", $h + ['timeout' => 15]) as $e) {
          if (empty($e['airDateUtc'])) continue;
          $items[] = ['title' => (string)($e['series']['title'] ?? 'Unknown'), 'sub' => und_media_ep($e['seasonNumber'], $e['episodeNumber']) . (!empty($e['title']) ? ' · ' . $e['title'] : ''),
                      'ts' => strtotime($e['airDateUtc']), 'when' => 'Airs', 'poster' => und_media_poster($e['series']['images'] ?? []),
                      'href' => !empty($e['series']['titleSlug']) ? "$link/series/" . rawurlencode($e['series']['titleSlug']) : $link, 'done' => !empty($e['hasFile'])];
        }
        usort($items, function ($a, $b) { return $a['ts'] - $b['ts']; });
      } elseif ($kind === 'recent_movies') {
        // eventType 3 = downloadFolderImported: the moment a file landed in the library
        $j = und_get("$base/api/v3/history?page=1&pageSize=" . ($count * 2) . "&sortKey=date&sortDirection=descending&eventType=3&includeMovie=true", $h + ['timeout' => 15]);
        $seen = [];
        foreach ((array)($j['records'] ?? []) as $r) {
          $m = $r['movie'] ?? null; $mid = (int)($r['movieId'] ?? 0);
          if (!$m || isset($seen[$mid])) continue; $seen[$mid] = 1;
          $items[] = ['title' => (string)$m['title'], 'year' => (int)($m['year'] ?? 0), 'ts' => strtotime((string)$r['date']), 'when' => 'Added', 'poster' => und_media_poster($m['images'] ?? []),
                      'href' => !empty($m['titleSlug']) ? "$link/movie/" . rawurlencode($m['titleSlug']) : $link, 'quality' => (string)($r['quality']['quality']['name'] ?? '')];
        }
      } else {
        $j = und_get("$base/api/v3/history?page=1&pageSize=" . ($count * 2) . "&sortKey=date&sortDirection=descending&eventType=3&includeSeries=true&includeEpisode=true", $h + ['timeout' => 15]);
        $seen = [];
        foreach ((array)($j['records'] ?? []) as $r) {
          $e = $r['episode'] ?? null; $sr = $r['series'] ?? null; $eid = (int)($r['episodeId'] ?? 0);
          if (!$e || !$sr || isset($seen[$eid])) continue; $seen[$eid] = 1;
          $items[] = ['title' => (string)$sr['title'], 'sub' => und_media_ep($e['seasonNumber'], $e['episodeNumber']) . (!empty($e['title']) ? ' · ' . $e['title'] : ''), 'ts' => strtotime((string)$r['date']),
                      'when' => 'Added', 'poster' => und_media_poster($sr['images'] ?? []), 'href' => !empty($sr['titleSlug']) ? "$link/series/" . rawurlencode($sr['titleSlug']) : $link,
                      'quality' => (string)($r['quality']['quality']['name'] ?? '')];
        }
      }
      if ($link === '') foreach ($items as &$it) $it['href'] = '';
      unset($it);
      return ['items' => array_slice($items, 0, $count), 'app' => ['name' => $s['name'], 'href' => preg_match('#^https?://#', $link) ? $link : '']];
    } catch (Throwable $e) {
      return ['items' => [], 'error' => $s['name'] . ': ' . und_redact($e->getMessage(), [$s['key']])];
    }
  });
}
