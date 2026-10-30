<?php
/*
 * UnDash - Arr Stack: one compact status per configured *arr / request app (several instances of the same app are fine).
 * Online state and version come from each app's status endpoint, warnings from its health endpoint, and failed or stuck
 * imports from its download queue. Numbers are the same ones the widgets already showed (und_w_*). Cached for 20 seconds.
 */

function und_arr_types() { return ['radarr', 'sonarr', 'lidarr', 'readarr', 'prowlarr', 'bazarr', 'overseerr', 'autobrr', 'wizarr']; }

/** API version path per app family. */
function und_arr_v($type) { return in_array($type, ['radarr', 'sonarr'], true) ? 'v3' : 'v1'; }

/** A health check as a short tag plus the message: "IndexerLongTermStatusCheck" -> "Indexer", a leading "Caution: " becomes the tag. */
function und_arr_health_item($level, $source, $msg) {
  $tags = ['RemovedSeriesCheck' => 'Series removed', 'RemovedMovieCheck' => 'Movie removed', 'RemovedArtistCheck' => 'Artist removed', 'ImportMechanismCheck' => 'Import',
    'RootFolderCheck' => 'Root folder', 'UpdateCheck' => 'Update', 'ProxyCheck' => 'Proxy', 'SystemTimeCheck' => 'System time', 'MountCheck' => 'Mount', 'RecyclingBinCheck' => 'Recycle bin'];
  $msg = trim($msg); $tag = '';
  if (preg_match('/^(Caution|Warning|Error):\s*(.+)$/is', $msg, $m)) { $tag = ucfirst(strtolower($m[1])); $msg = $m[2]; }
  if (isset($tags[$source])) $tag = $tags[$source];
  elseif (preg_match('/^Indexer/', $source)) $tag = 'Indexer';
  elseif (preg_match('/^DownloadClient/', $source)) $tag = 'Download client';
  elseif ($tag === '' && preg_match('/^([A-Z][A-Za-z]+?)Check$/', $source, $m)) $tag = trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $m[1]));
  return ['level' => $level, 'tag' => und_clip($tag, 40), 'msg' => und_clip($msg, 240), 'source' => $source];
}

/** Stuck or failed downloads from an *arr queue, one row per release group and problem: a season pack that is blocked for
 *  16 episodes is one row "The Mentalist · Season 3" with count 16 instead of 16 rows. */
function und_arr_queue_issues(array $records) {
  $states = ['importblocked' => 'Import blocked', 'importpending' => 'Import pending', 'failedpending' => 'Failed', 'failed' => 'Failed', 'importing' => 'Importing', 'downloading' => 'Download'];
  $groups = [];
  foreach ($records as $r) {
    if (!is_array($r)) continue;
    $status = strtolower((string)($r['trackedDownloadStatus'] ?? '')); $state = strtolower((string)($r['trackedDownloadState'] ?? ''));
    if ($status !== 'warning' && $status !== 'error' && !in_array($state, ['importblocked', 'failedpending', 'failed'], true)) continue;
    $msg = (string)($r['errorMessage'] ?? '');
    if ($msg === '' && !empty($r['statusMessages'][0]['messages'][0])) $msg = (string)$r['statusMessages'][0]['messages'][0];
    $level = $status === 'error' || strpos($state, 'fail') !== false ? 'bad' : 'warn';
    $release = (string)($r['title'] ?? '');
    if (!empty($r['series']['title'])) $name = $r['series']['title'] . (isset($r['seasonNumber']) ? ' · Season ' . (int)$r['seasonNumber'] : '');
    elseif (!empty($r['movie']['title'])) $name = $r['movie']['title'] . (!empty($r['movie']['year']) ? ' (' . (int)$r['movie']['year'] . ')' : '');
    elseif (preg_match('/^(.+?[. _-]S\d{1,2})(E\d|[. _-])/i', $release, $m)) $name = preg_replace('/[._]+/', ' ', $m[1]);
    else $name = $release !== '' ? $release : 'Download';
    $tag = $states[$state] ?? ($status === 'error' ? 'Error' : 'Warning');
    $msg = $msg !== '' ? $msg : $tag;
    $key = strtolower($name . '|' . $tag . '|' . $msg);
    if (!isset($groups[$key])) $groups[$key] = ['level' => $level, 'tag' => $tag, 'title' => und_clip($name, 120), 'msg' => und_clip($msg, 200), 'count' => 0, 'releases' => []];
    $g = &$groups[$key];
    $g['count']++;
    if ($level === 'bad') $g['level'] = 'bad';
    if ($release !== '' && count($g['releases']) < 5 && !in_array($release, $g['releases'], true)) $g['releases'][] = und_clip($release, 200);
    unset($g);
  }
  $out = array_values($groups);
  usort($out, function ($a, $b) { return ($a['level'] === 'bad' ? 0 : 1) - ($b['level'] === 'bad' ? 0 : 1) ?: $b['count'] - $a['count']; });
  return $out;
}

function und_arr_status(array $cfg, $id) {
  $s0 = null;
  foreach ($cfg['groups'] as $g) foreach ($g['services'] as $x) if ($x['id'] === $id) $s0 = $x;
  if (!$s0 || !in_array($s0['type'], und_arr_types(), true)) return ['error' => 'Unknown app.'];
  return und_api_cached('arr-' . $id, 20, function () use ($cfg, $id) {
    $s = und_service_full($cfg, $id);
    $out = ['online' => false, 'version' => '', 'blocks' => [], 'health' => [], 'issues' => [], 'href' => preg_match('#^https?://#', $s['href']) ? $s['href'] : ''];
    if (trim($s['url']) === '' || und_has($s['url'], '{{')) return $out + ['error' => 'No API URL configured.', 'unconfigured' => true];
    $base = rtrim($s['url'], '/'); $t = $s['type'];
    $w = und_fetch_service($cfg, $id);
    if (isset($w['error'])) return $out + ['error' => $w['error'], 'unconfigured' => !empty($w['unconfigured'])];
    $out['online'] = true;
    $out['blocks'] = $w['blocks'] ?? [];
    try {
      if (in_array($t, ['radarr', 'sonarr', 'lidarr', 'readarr', 'prowlarr'], true)) {
        $h = und_arr_h($s); $v = und_arr_v($t);
        $st = und_get("$base/api/$v/system/status", $h);
        $out['version'] = (string)($st['version'] ?? '');
        foreach ((array)und_get("$base/api/$v/health", $h) as $x) {
          $type = strtolower((string)($x['type'] ?? ''));
          if ($type === 'warning' || $type === 'error') $out['health'][] = und_arr_health_item($type === 'error' ? 'bad' : 'warn', (string)($x['source'] ?? ''), (string)($x['message'] ?? ''));
        }
        if ($t !== 'prowlarr') {
          $q = und_get("$base/api/$v/queue?page=1&pageSize=50" . ($t === 'sonarr' ? '&includeSeries=true' : ($t === 'radarr' ? '&includeMovie=true' : '')), $h + ['timeout' => 15]);
          $out['issues'] = und_arr_queue_issues((array)($q['records'] ?? []));
        }
      } elseif ($t === 'bazarr') {
        $h = ['headers' => ['X-API-KEY' => $s['key']]];
        $st = und_get("$base/api/system/status", $h);
        $out['version'] = (string)($st['data']['bazarr_version'] ?? '');
        foreach ((array)(und_get("$base/api/system/health", $h)['data'] ?? []) as $x) $out['health'][] = ['level' => 'warn', 'msg' => und_clip((string)($x['issue'] ?? ''), 240), 'source' => (string)($x['object'] ?? '')];
      } elseif ($t === 'overseerr') {
        $st = und_get("$base/api/v1/status", und_arr_h($s));
        $out['version'] = (string)($st['version'] ?? '');
        if (!empty($st['updateAvailable'])) $out['health'][] = ['level' => 'warn', 'msg' => 'An update is available.', 'source' => ''];
      }
    } catch (Throwable $e) {
      // the app answered the widget call, so it is online; only the extra details failed
      $out['health'][] = ['level' => 'warn', 'msg' => 'Status details unavailable: ' . und_redact($e->getMessage(), [$s['key']]), 'source' => 'UnDash'];
    }
    return $out;
  });
}
