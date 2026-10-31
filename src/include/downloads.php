<?php
/*
 * UnDash - download clients (qBittorrent, SABnzbd, qui): connection, speeds, counts by state, the active items with
 * progress and ETA, and pause / resume of the whole client through the client's own API. Cached for 3 seconds.
 * qui (github.com/autobrr/qui) manages several qBittorrent instances; UnDash reads its API with an API key (X-API-Key)
 * and shows the totals plus one line per instance. qui is read only here.
 */

function und_dl_types() { return ['qbittorrent', 'sabnzbd', 'qui']; }

/** A qui API call (GET, JSON). */
function und_qui_get(array $s, $path) {
  return und_get(und_u($s['url'], $path), ['headers' => ['X-API-Key' => $s['key'], 'Accept' => 'application/json'], 'timeout' => 15]);
}

/** Counts, speeds and the active torrents of every qBittorrent instance in qui. */
function und_qui_status(array $s, $items = 8) {
  $inst = und_qui_get($s, '/api/instances');
  if (!is_array($inst)) throw new Exception('qui did not answer with a list of instances.');
  $keys = ['downloading', 'seeding', 'idle', 'crossseed', 'stalled', 'paused', 'checking', 'error', 'tracker', 'completed'];
  $tot = array_fill_keys($keys, 0); $down = 0.0; $up = 0.0; $list = []; $active = [];
  foreach ($inst as $i) {
    if (!is_array($i) || !isset($i['id'])) continue;
    $row = ['name' => und_clip((string)($i['name'] ?? ('Instance ' . $i['id'])), 80), 'online' => !empty($i['connected']), 'down' => 0, 'up' => 0, 'counts' => array_fill_keys($keys, 0), 'total' => 0];
    if ($row['online']) {
      try {
        $j = und_qui_get($s, '/api/instances/' . rawurlencode((string)$i['id']) . '/torrents?page=0&limit=' . max(1, (int)$items) . '&sort=dlspeed&order=desc');
        $st = (array)($j['stats'] ?? []); $cs = (array)($j['counts']['status'] ?? []); $tags = (array)($j['counts']['tags'] ?? []);
        $n = function ($k) use ($cs) { return (int)($cs[$k] ?? 0); };
        $row['counts'] = ['downloading' => (int)($st['downloading'] ?? $n('downloading')), 'seeding' => (int)($st['seeding'] ?? $n('seeding')), 'idle' => $n('inactive'),
          'crossseed' => (int)($tags['cross-seed'] ?? 0), 'stalled' => $n('stalled'), 'paused' => (int)($st['paused'] ?? ($n('paused') ?: $n('stopped'))),
          'checking' => (int)($st['checking'] ?? $n('checking')), 'error' => (int)($st['error'] ?? $n('errored')),
          'tracker' => $n('unregistered') + $n('tracker_down') + $n('tracker_error'), 'completed' => $n('completed')];
        $row['total'] = (int)($st['total'] ?? 0);
        $row['down'] = (float)($st['totalDownloadSpeed'] ?? 0); $row['up'] = (float)($st['totalUploadSpeed'] ?? 0);
        foreach ((array)($j['torrents'] ?? []) as $t) {
          $ts = (string)($t['state'] ?? '');
          if (!in_array($ts, ['downloading', 'forcedDL', 'metaDL', 'forcedMetaDL', 'stalledDL', 'checkingDL', 'allocating'], true)) continue;
          $eta = (int)($t['eta'] ?? 0);
          $active[] = ['name' => und_clip((string)($t['name'] ?? ''), 140), 'progress' => round(100 * (float)($t['progress'] ?? 0), 1), 'speed' => (float)($t['dlspeed'] ?? 0),
                       'eta' => $eta > 0 && $eta < 8640000 ? $eta : null, 'state' => $ts === 'stalledDL' ? 'stalled' : 'downloading', 'size' => (float)($t['size'] ?? 0)];
        }
      } catch (Throwable $e) {
        $row['online'] = false; $row['error'] = und_redact($e->getMessage(), [$s['key']]);
      }
    } else $row['error'] = und_clip((string)($i['connectionStatus'] ?? 'not connected'), 120);
    foreach ($keys as $k) $tot[$k] += $row['counts'][$k];
    $down += $row['down']; $up += $row['up'];
    $list[] = $row;
  }
  usort($active, function ($a, $b) { return $b['speed'] <=> $a['speed'] ?: $b['progress'] <=> $a['progress']; });
  return ['counts' => $tot, 'down' => $down, 'up' => $up, 'instances' => $list, 'items' => array_slice($active, 0, $items)];
}

function und_dl_service(array $cfg, $id) {
  foreach ($cfg['groups'] as $g) foreach ($g['services'] as $x) if ($x['id'] === $id && in_array($x['type'], und_dl_types(), true)) return und_service_full($cfg, $id);
  return null;
}

/** A qBittorrent request with its login cookie (logs in again once when the cookie expired). */
function und_qbit_req(array $s, $path, $method = 'GET', $body = null) {
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
  $o = ['cookie' => $sid, 'timeout' => 15, 'headers' => ['Referer' => rtrim($s['url'], '/')]];
  if ($body !== null) { $o['body'] = $body; $o['headers']['Content-Type'] = 'application/x-www-form-urlencoded'; }
  $r = und_http($method, und_u($s['url'], $path), $o);
  if ($r['code'] === 403) { $o['cookie'] = $login(); $r = und_http($method, und_u($s['url'], $path), $o); }
  if ($r['err'] !== '') throw new Exception($r['err']);
  return $r;
}

function und_dl_status(array $cfg, $id) {
  $s = und_dl_service($cfg, $id);
  if (!$s) return ['error' => 'Unknown download client.'];
  return und_api_cached('dl-' . $id, 3, function () use ($s) {
    $out = ['online' => false, 'state' => 'disconnected', 'down' => null, 'up' => null, 'counts' => [], 'items' => [], 'href' => preg_match('#^https?://#', $s['href']) ? $s['href'] : '', 'type' => $s['type']];
    if (trim($s['url']) === '' || und_has($s['url'], '{{')) return $out + ['error' => 'No API URL configured.', 'unconfigured' => true];
    try {
      if ($s['type'] === 'qbittorrent') {
        $tr = und_qbit_req($s, '/api/v2/transfer/info')['json'];
        $list = und_qbit_req($s, '/api/v2/torrents/info')['json'];
        if (!is_array($list)) throw new Exception('qBittorrent did not answer.');
        $c = ['downloading' => 0, 'seeding' => 0, 'paused' => 0, 'stalled' => 0, 'queued' => 0, 'completed' => 0, 'error' => 0];
        $active = [];
        foreach ($list as $t) {
          $st = (string)($t['state'] ?? '');
          if (in_array($st, ['downloading', 'forcedDL', 'metaDL', 'forcedMetaDL', 'checkingDL', 'allocating'], true)) { $c['downloading']++; $active[] = $t; }
          elseif ($st === 'stalledDL') { $c['stalled']++; $active[] = $t; }
          elseif (in_array($st, ['pausedDL', 'stoppedDL', 'pausedUP', 'stoppedUP'], true)) $c['paused']++;
          elseif (in_array($st, ['queuedDL', 'queuedUP'], true)) $c['queued']++;
          elseif (in_array($st, ['uploading', 'forcedUP', 'stalledUP', 'checkingUP'], true)) $c['seeding']++;
          elseif (in_array($st, ['error', 'missingFiles'], true)) $c['error']++;
          if ((float)($t['progress'] ?? 0) >= 1) $c['completed']++;
        }
        usort($active, function ($a, $b) { return ($b['dlspeed'] ?? 0) <=> ($a['dlspeed'] ?? 0) ?: ($b['progress'] ?? 0) <=> ($a['progress'] ?? 0); });
        foreach (array_slice($active, 0, 8) as $t) {
          $eta = (int)($t['eta'] ?? 0);
          $out['items'][] = ['name' => und_clip((string)$t['name'], 140), 'progress' => round(100 * (float)$t['progress'], 1), 'speed' => (float)($t['dlspeed'] ?? 0),
                             'eta' => $eta > 0 && $eta < 8640000 ? $eta : null, 'state' => $t['state'] === 'stalledDL' ? 'stalled' : 'downloading', 'size' => (float)($t['size'] ?? 0)];
        }
        $conn = (string)($tr['connection_status'] ?? 'connected');
        $out = array_merge($out, ['online' => true, 'down' => (float)($tr['dl_info_speed'] ?? 0), 'up' => (float)($tr['up_info_speed'] ?? 0), 'counts' => $c,
          'state' => $conn === 'disconnected' ? 'disconnected' : ($c['downloading'] ? 'downloading' : ($c['stalled'] ? 'stalled' : ($c['paused'] && !$c['seeding'] && !$c['queued'] ? 'paused' : 'idle'))),
          'note' => $conn === 'firewalled' ? 'Firewalled: incoming connections are blocked.' : '', 'can_pause' => true]);
      } elseif ($s['type'] === 'qui') {
        $q = und_qui_status($s);
        $on = array_filter($q['instances'], function ($i) { return $i['online']; });
        if (!$q['instances']) throw new Exception('qui has no qBittorrent instances yet.');
        $c = $q['counts'];
        $out = array_merge($out, ['online' => true, 'down' => $q['down'], 'up' => $q['up'], 'counts' => $c, 'items' => $q['items'],
          'instances' => count($q['instances']) > 1 || !$on ? $q['instances'] : [],
          'state' => !$on ? 'disconnected' : ($c['downloading'] ? 'downloading' : ($c['stalled'] ? 'stalled' : 'idle')),
          'note' => count($on) < count($q['instances']) ? (count($q['instances']) - count($on)) . ' of ' . count($q['instances']) . ' instances are not connected.' : '', 'can_pause' => false]);
      } else {
        $q = und_get(und_u($s['url'], '/api?output=json&mode=queue&limit=8&apikey=' . rawurlencode($s['key'])));
        if (isset($q['error'])) throw new Exception('SABnzbd: ' . $q['error']);
        $qq = $q['queue'] ?? [];
        $hist = und_get(und_u($s['url'], '/api?output=json&mode=history&limit=1&apikey=' . rawurlencode($s['key'])));
        $paused = !empty($qq['paused']);
        foreach ((array)($qq['slots'] ?? []) as $sl) {
          $left = (string)($sl['timeleft'] ?? '');
          $sec = preg_match('/^(\d+):(\d+):(\d+)$/', $left, $m) ? $m[1] * 3600 + $m[2] * 60 + $m[3] : null;
          $out['items'][] = ['name' => und_clip((string)($sl['filename'] ?? ''), 140), 'progress' => (float)($sl['percentage'] ?? 0), 'speed' => null, 'eta' => $sec ?: null,
                             'state' => strtolower((string)($sl['status'] ?? '')) === 'paused' || $paused ? 'paused' : (strtolower((string)($sl['status'] ?? '')) === 'downloading' ? 'downloading' : 'queued'),
                             'size' => (float)($sl['mb'] ?? 0) * 1048576];
        }
        $total = (int)($qq['noofslots_total'] ?? $qq['noofslots'] ?? 0);
        $out = array_merge($out, ['online' => true, 'down' => (float)($qq['kbpersec'] ?? 0) * 1024, 'up' => null,
          'counts' => ['downloading' => $total && !$paused ? 1 : 0, 'queued' => max(0, $total - ($paused ? 0 : 1)), 'paused' => $paused ? $total : 0, 'completed' => (int)($hist['history']['noofslots'] ?? 0)],
          'state' => $paused ? 'paused' : ($total ? 'downloading' : 'idle'), 'eta' => (string)($qq['timeleft'] ?? ''), 'can_pause' => true, 'paused' => $paused]);
      }
    } catch (Throwable $e) {
      $out['error'] = und_redact($e->getMessage(), [$s['key'], $s['password']]);
    }
    return $out;
  });
}

/** Pause or resume everything in a client. */
function und_dl_action(array $cfg, $id, $op) {
  if (!in_array($op, ['pause', 'resume'], true)) return ['error' => 'Unknown action.'];
  $s = und_dl_service($cfg, $id);
  if (!$s) return ['error' => 'Unknown download client.'];
  if ($s['type'] === 'qui') return ['error' => 'Pause and resume torrents in qui itself.'];
  $lock = und_lock('dl:' . $id);
  if (!$lock) return ['error' => 'Another action on ' . $s['name'] . ' is still running.'];
  try {
    if ($s['type'] === 'qbittorrent') {
      // qBittorrent 5 renamed pause / resume to stop / start; older versions answer 404 to the new names
      $r = und_qbit_req($s, '/api/v2/torrents/' . ($op === 'pause' ? 'stop' : 'start'), 'POST', 'hashes=all');
      if ($r['code'] === 404) $r = und_qbit_req($s, '/api/v2/torrents/' . $op, 'POST', 'hashes=all');
      if ($r['code'] !== 200) throw new Exception('qBittorrent answered HTTP ' . $r['code'] . '.');
    } else {
      $j = und_get(und_u($s['url'], '/api?output=json&mode=' . $op . '&apikey=' . rawurlencode($s['key'])));
      if (empty($j['status'])) throw new Exception('SABnzbd did not confirm the ' . $op . '.');
    }
    und_unlock($lock);
    @unlink(und_tmp('api-dl-' . $id . '.json'));
    und_history_add('download', $s['name'], $op, true, 'all downloads');
    return ['ok' => true];
  } catch (Throwable $e) {
    und_unlock($lock);
    $m = und_redact($e->getMessage(), [$s['key'], $s['password']]);
    und_history_add('download', $s['name'], $op, false, $m);
    return ['error' => $s['name'] . ': ' . $m];
  }
}
