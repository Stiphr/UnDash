<?php
/*
 * UnDash - now playing: every active stream on the configured Plex, Emby, Jellyfin and Tautulli apps, with who is
 * watching on what, progress, and whether it is direct play or a transcode. When Tautulli is set up it reports Plex
 * (with more detail), so Plex itself is not asked as well and nothing is listed twice. Cached for 5 seconds.
 */

function und_playing_sources(array $cfg) {
  $list = [];
  foreach ($cfg['groups'] as $g) foreach ($g['services'] as $s) if (in_array($s['type'], ['plex', 'emby', 'jellyfin', 'tautulli'], true)) $list[] = $s;
  $haveTautulli = (bool)array_filter($list, function ($s) { return $s['type'] === 'tautulli'; });
  return array_values(array_filter($list, function ($s) use ($haveTautulli) { return !($haveTautulli && $s['type'] === 'plex'); }));
}

function und_playing_method($m) {
  $m = strtolower((string)$m);
  if ($m === 'transcode') return 'transcode';
  if ($m === 'copy' || $m === 'directstream' || $m === 'direct stream') return 'direct stream';
  return $m === '' ? '' : 'direct play';
}

function und_playing_ep($series, $season, $ep, $name) { return [$series, 'S' . sprintf('%02d', (int)$season) . 'E' . sprintf('%02d', (int)$ep) . ($name !== '' ? ' · ' . $name : '')]; }

function und_playing_one(array $cfg, $id) {
  $s = und_service_full($cfg, $id);
  if (trim($s['url']) === '' || und_has($s['url'], '{{')) return ['error' => $s['name'] . ' has no API URL yet.'];
  $out = [];
  if ($s['type'] === 'tautulli') {
    foreach ((array)(und_tautulli($s, ['cmd' => 'get_activity'])['sessions'] ?? []) as $x) {
      $ep = ($x['media_type'] ?? '') === 'episode';
      [$t, $sub] = $ep ? und_playing_ep($x['grandparent_title'] ?? '', $x['parent_media_index'] ?? 0, $x['media_index'] ?? 0, (string)($x['title'] ?? ''))
                       : [(string)($x['title'] ?? $x['full_title'] ?? ''), !empty($x['year']) ? (string)$x['year'] : ''];
      $out[] = ['title' => $t, 'sub' => $sub, 'user' => (string)($x['friendly_name'] ?? $x['user'] ?? ''), 'device' => trim(($x['player'] ?? '') . (isset($x['product']) && $x['product'] !== ($x['player'] ?? '') ? ' · ' . $x['product'] : '')),
        'state' => (string)($x['state'] ?? 'playing'), 'pos' => (int)((int)($x['view_offset'] ?? 0) / 1000), 'dur' => (int)((int)($x['duration'] ?? 0) / 1000),
        'method' => und_playing_method($x['transcode_decision'] ?? ''), 'quality' => (string)($x['quality_profile'] ?? $x['stream_video_full_resolution'] ?? ''),
        'bandwidth' => isset($x['bandwidth']) && $x['bandwidth'] !== '' ? (int)$x['bandwidth'] * 1000 : null, 'location' => strtolower((string)($x['location'] ?? ''))];
    }
  } elseif ($s['type'] === 'plex') {
    $j = und_get(und_u($s['url'], '/status/sessions'), ['headers' => ['X-Plex-Token' => $s['key'], 'Accept' => 'application/json']]);
    foreach ((array)($j['MediaContainer']['Metadata'] ?? []) as $x) {
      $ep = ($x['type'] ?? '') === 'episode';
      [$t, $sub] = $ep ? und_playing_ep($x['grandparentTitle'] ?? '', $x['parentIndex'] ?? 0, $x['index'] ?? 0, (string)($x['title'] ?? ''))
                       : [(string)($x['title'] ?? ''), !empty($x['year']) ? (string)$x['year'] : ''];
      $ts = $x['TranscodeSession'] ?? null;
      $out[] = ['title' => $t, 'sub' => $sub, 'user' => (string)($x['User']['title'] ?? ''), 'device' => trim(($x['Player']['title'] ?? '') . (!empty($x['Player']['product']) ? ' · ' . $x['Player']['product'] : '')),
        'state' => (string)($x['Player']['state'] ?? 'playing'), 'pos' => (int)((int)($x['viewOffset'] ?? 0) / 1000), 'dur' => (int)((int)($x['duration'] ?? 0) / 1000),
        'method' => $ts ? und_playing_method($ts['videoDecision'] ?? 'transcode') : 'direct play', 'quality' => (string)($x['Media'][0]['videoResolution'] ?? ''),
        'bandwidth' => isset($x['Session']['bandwidth']) ? (int)$x['Session']['bandwidth'] * 1000 : null, 'location' => strtolower((string)($x['Session']['location'] ?? ''))];
    }
  } else {
    $base = rtrim($s['url'], '/'); $pre = $s['type'] === 'emby' && !und_ends($base, '/emby') ? '/emby' : '';
    $j = und_get($base . $pre . '/Sessions', ['headers' => ['X-Emby-Token' => $s['key'], 'Accept' => 'application/json']]);
    foreach ((array)$j as $x) {
      if (empty($x['NowPlayingItem'])) continue;
      $n = $x['NowPlayingItem'];
      [$t, $sub] = !empty($n['SeriesName']) ? und_playing_ep($n['SeriesName'], $n['ParentIndexNumber'] ?? 0, $n['IndexNumber'] ?? 0, (string)($n['Name'] ?? ''))
                                           : [(string)($n['Name'] ?? ''), !empty($n['ProductionYear']) ? (string)$n['ProductionYear'] : ''];
      $w = (int)($n['Width'] ?? 0);
      $out[] = ['title' => $t, 'sub' => $sub, 'user' => (string)($x['UserName'] ?? ''), 'device' => trim(($x['DeviceName'] ?? '') . (!empty($x['Client']) ? ' · ' . $x['Client'] : '')),
        'state' => !empty($x['PlayState']['IsPaused']) ? 'paused' : 'playing', 'pos' => (int)(($x['PlayState']['PositionTicks'] ?? 0) / 10000000), 'dur' => (int)(($n['RunTimeTicks'] ?? 0) / 10000000),
        'method' => und_playing_method($x['PlayState']['PlayMethod'] ?? ''), 'quality' => $w >= 3800 ? '4K' : ($w >= 1900 ? '1080p' : ($w >= 1260 ? '720p' : ($w ? 'SD' : ''))),
        'bandwidth' => isset($x['TranscodingInfo']['Bitrate']) ? (int)$x['TranscodingInfo']['Bitrate'] : null, 'location' => ''];
    }
  }
  return ['streams' => $out];
}

function und_playing(array $cfg) {
  $src = und_playing_sources($cfg);
  if (!$src) return ['unconfigured' => true, 'streams' => [], 'apps' => []];
  return und_api_cached('playing', 5, function () use ($cfg, $src) {
    $streams = []; $apps = [];
    foreach ($src as $s) {
      try { $r = und_playing_one($cfg, $s['id']); }
      catch (Throwable $e) { $full = und_service_full($cfg, $s['id']); $r = ['error' => und_redact($e->getMessage(), [$full['key']])]; }
      $apps[] = ['name' => $s['name'], 'type' => $s['type'], 'icon' => $s['icon'], 'error' => $r['error'] ?? '', 'count' => count($r['streams'] ?? [])];
      foreach ($r['streams'] ?? [] as $x) $streams[] = $x + ['app' => $s['name'], 'app_type' => $s['type'], 'icon' => $s['icon']];
    }
    // playing before buffering before paused, then by user
    $rank = function ($st) { return $st === 'playing' ? 0 : ($st === 'paused' ? 2 : 1); };
    usort($streams, function ($a, $b) use ($rank) { return $rank($a['state']) - $rank($b['state']) ?: strcasecmp($a['user'], $b['user']); });
    return ['streams' => $streams, 'apps' => $apps];
  });
}
