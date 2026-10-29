<?php
/*
 * UnDash - Arr Stack: one compact status per configured *arr / request app (several instances of the same app are fine).
 * Online state and version come from each app's status endpoint, warnings from its health endpoint, and failed or stuck
 * imports from its download queue. Numbers are the same ones the widgets already showed (und_w_*). Cached for 20 seconds.
 */

function und_arr_types() { return ['radarr', 'sonarr', 'lidarr', 'readarr', 'prowlarr', 'bazarr', 'overseerr', 'autobrr', 'wizarr']; }

/** API version path per app family. */
function und_arr_v($type) { return in_array($type, ['radarr', 'sonarr'], true) ? 'v3' : 'v1'; }

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
          if ($type === 'warning' || $type === 'error') $out['health'][] = ['level' => $type === 'error' ? 'bad' : 'warn', 'msg' => und_clip((string)($x['message'] ?? ''), 240), 'source' => (string)($x['source'] ?? '')];
        }
        if ($t !== 'prowlarr') {
          $q = und_get("$base/api/$v/queue?page=1&pageSize=50" . ($t === 'sonarr' ? '&includeSeries=true' : ($t === 'radarr' ? '&includeMovie=true' : '')), $h + ['timeout' => 15]);
          foreach ((array)($q['records'] ?? []) as $r) {
            $status = strtolower((string)($r['trackedDownloadStatus'] ?? '')); $state = strtolower((string)($r['trackedDownloadState'] ?? ''));
            if ($status !== 'warning' && $status !== 'error' && !in_array($state, ['importblocked', 'failedpending', 'failed'], true)) continue;
            $msg = (string)($r['errorMessage'] ?? '');
            if ($msg === '' && !empty($r['statusMessages'][0]['messages'][0])) $msg = (string)$r['statusMessages'][0]['messages'][0];
            $out['issues'][] = ['level' => $status === 'error' || strpos($state, 'fail') !== false ? 'bad' : 'warn', 'title' => und_clip((string)($r['title'] ?? $r['movie']['title'] ?? $r['series']['title'] ?? 'Download'), 120),
                                'msg' => und_clip($msg !== '' ? $msg : ucfirst($state ?: $status), 200)];
          }
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
