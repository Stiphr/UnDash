<?php
/*
 * UnDash - Docker containers.
 *
 * Live status (every refresh) costs one `docker ps` plus two small cgroup files per running container: no `docker inspect`
 * and no `docker stats` (which takes ~2 s). The full inspect data (icons, WebUI, ports, restart count) is cached and only
 * re-read when a container appears, disappears or changes state, or after 5 minutes.
 * Updates use Unraid's own update_container script, so a container is recreated from its Unraid template exactly as the
 * Docker tab's "apply update" does.
 */


function und_valid_container($n) { return is_string($n) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $n) === 1; }





/*
 * Skipping one version of a container. Neither Unraid nor its API names a version, only "update available", so the skip is
 * kept as the digest the image's tag points at. A new digest means a newer version, and the update shows again.
 * The plugin reads the digest from Unraid's status file. The Docker version asks the registry itself, for skipped images only.
 */
const UND_SKIP_TTL = 21600;      // ask the registry about a skipped image every 6 hours
const UND_SKIP_TICK = 300;       // and about one image every 5 minutes at most: the poller must not stall
const UND_MANIFEST_TYPES = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json';

/** Registry, repository and tag of an image; null for one pinned to a digest (it cannot change) or a name that does not parse. */
function und_image_ref($image) {
  $image = trim((string)$image);
  if ($image === '' || und_has($image, '@')) return null;
  $p = explode('/', $image);
  $host = 'registry-1.docker.io';
  if (count($p) > 1 && (strpbrk($p[0], '.:') !== false || $p[0] === 'localhost')) $host = array_shift($p);
  if ($host === 'docker.io' || $host === 'index.docker.io') $host = 'registry-1.docker.io';
  $last = explode(':', array_pop($p), 2);
  $p[] = $last[0];
  $repo = implode('/', $p);
  if ($host === 'registry-1.docker.io' && count($p) === 1) $repo = 'library/' . $repo;
  $tag = $last[1] ?? 'latest';
  if (!preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host) || !preg_match('#^[a-z0-9][a-z0-9._/-]*$#', $repo) || !preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', $tag)) return null;
  return ['host' => $host, 'repo' => $repo, 'tag' => $tag];
}

/** The digest a tag points at now, asked anonymously (no key or login is ever sent); '' when the registry will not say. */
function und_registry_digest($image) {
  $r = und_image_ref($image);
  if (!$r) return '';
  $url = 'https://' . $r['host'] . '/v2/' . $r['repo'] . '/manifests/' . $r['tag'];
  $o = ['timeout' => 6, 'verify' => true, 'headers' => ['Accept' => UND_MANIFEST_TYPES]];
  $a = und_http('HEAD', $url, $o);
  $ask = $a['headers']['www-authenticate'][0] ?? '';
  if ($a['code'] === 401 && preg_match('/realm="(https:[^"]+)"/i', $ask, $realm)) {
    $q = ['scope' => 'repository:' . $r['repo'] . ':pull'];
    if (preg_match('/service="([^"]+)"/i', $ask, $m)) $q['service'] = $m[1];
    $t = und_http('GET', $realm[1] . '?' . http_build_query($q), ['timeout' => 6, 'verify' => true]);
    $tok = (string)($t['json']['token'] ?? $t['json']['access_token'] ?? '');
    if ($tok === '') return '';
    $o['headers']['Authorization'] = 'Bearer ' . $tok;
    $a = und_http('HEAD', $url, $o);
  }
  $d = $a['code'] === 200 ? (string)($a['headers']['docker-content-digest'][0] ?? '') : '';
  return preg_match('/^sha256:[a-f0-9]{64}$/', $d) ? $d : '';
}

/** The newest digest known for an image. */
function und_remote_digest($image) {
  $f = und_tmp('image-digests.json');
  $d = und_registry_digest($image);
  $seen = und_read_json($f, []);
  $seen[$image] = ['d' => $d !== '' ? $d : (string)($seen[$image]['d'] ?? ''), 'at' => time()];
  und_write_json($f, $seen);
  return $d;
}

/** Marks the containers whose update is the skipped version. A skip stands while the registry cannot be read: it only ever hides that one update. */
function und_docker_skips(array $list) {
  $items = (array)(und_load_config()['docker']['items'] ?? []);
  $seen = und_read_json(und_tmp('image-digests.json'), []);
  foreach ($list as &$c) {
    $s = (string)($items[$c['name']]['skip_update'] ?? '');
    if ($s === '' || $c['update'] !== 'available') continue;
    $now = (string)($seen[$c['image']]['d'] ?? '');
    if ($now === '' || $now === $s) $c['skip'] = true;
  }
  unset($c);
  return $list;
}

function und_docker_skip($name, $on) {
  $l = und_docker_live();
  if (isset($l['error'])) return $l;
  $c = null;
  foreach ($l['containers'] as $x) if ($x['name'] === $name) $c = $x;
  if (!$c) return ['error' => 'That container no longer exists.'];
  $d = '';
  if ($on) {
    if ($c['update'] !== 'available') return ['error' => $name . ' has no update to skip.'];
    $d = und_remote_digest($c['image']);
    if ($d === '') return ['error' => 'Could not read this image\'s version from its registry, so a newer version could not be told apart. Use Ignore updates instead.'];
  }
  $lk = und_config_lock();
  $cfg = und_load_config();
  $items = (array)$cfg['docker']['items'];
  $row = (array)($items[$name] ?? []);
  if ($on) $row['skip_update'] = $d; else unset($row['skip_update']);
  $items[$name] = $row;
  $cfg['docker']['items'] = $items;
  if (!und_save_config($cfg, false)) return ['error' => 'Could not save.'];
  und_history_add('docker', $name, 'update', true, $on ? 'skipped this version' : 'stopped skipping');
  return ['ok' => true];
}

/** Docker version: rereads the oldest skipped image once its digest is old (called by the poller, every second). */
function und_docker_skip_tick($now = null) {
  $now = $now ?? time();
  $mark = und_tmp('skip.tick');
  if ($now - (int)@filemtime($mark) < UND_SKIP_TICK) return;
  @touch($mark, $now);
  $items = (array)(und_load_config()['docker']['items'] ?? []);
  $names = array_filter($items, function ($p) { return !empty($p['skip_update']); });
  if (!$names) return;
  $l = und_docker_live();
  $seen = und_read_json(und_tmp('image-digests.json'), []);
  $next = ''; $old = $now - UND_SKIP_TTL + 1;
  foreach ((array)($l['containers'] ?? []) as $c) {
    if (!isset($names[$c['name']]) || $c['update'] !== 'available') continue;
    $at = (int)($seen[$c['image']]['at'] ?? 0);
    if ($at < $old) { $old = $at; $next = $c['image']; }
  }
  if ($next !== '') und_remote_digest($next);
}

/** Everything the dashboard shows about containers. Cheap enough for every refresh. */
function und_docker_live() { return und_api_docker_live(); }

function und_docker_action($name, $op) {
  if (!und_valid_container($name)) return ['error' => 'Invalid container name.'];
  return und_api_docker_action($name, $op);
}


/** Record how an update finished (called when the UI sees the job end). */
function und_docker_job($name) {
  $log = und_tmp('docker-update-' . $name . '.log');
  $j = und_job($log);
  if ($j['state'] === 'done' && !is_file($log . '.recorded')) {
    @touch($log . '.recorded');
    @unlink(und_tmp('inspect.json'));
    @unlink(und_tmp('api-containers.json'));
    $ok = $j['code'] === 0 && !preg_match('/\b(error|failed|denied)\b/i', $j['out']);
    und_history_add('docker', $name, 'update', $ok, $ok ? 'finished' : und_clip(trim(substr($j['out'], -300)), 300));
    $j['ok'] = $ok;
  } elseif ($j['state'] === 'done') $j['ok'] = $j['code'] === 0 && !preg_match('/\b(error|failed|denied)\b/i', $j['out']);
  return $j;
}

/** The last N lines of stdout and stderr, merged in time order. */
function und_docker_logs($name, $lines = 300) {
  if (!und_valid_container($name)) return ['error' => 'Invalid container name.'];
  return und_api_docker_logs($name, $lines);
}

/**
 * Diagnostic details for one container. Environment variables are listed by name only (their values are often passwords),
 * labels are limited to Unraid's and OCI's descriptive ones.
 */
function und_docker_inspect($name) {
  if (!und_valid_container($name)) return ['error' => 'Invalid container name.'];
  return und_api_docker_inspect($name);
}

function und_docker_summary() {
  $l = und_docker_live();
  if (isset($l['error'])) return $l;
  $s = ['running' => 0, 'stopped' => 0, 'paused' => 0, 'updates' => 0, 'unhealthy' => 0, 'total' => count($l['containers'])];
  $d = und_load_config()['docker'];
  foreach ($l['containers'] as $c) {
    if ($c['state'] === 'running') $s['running']++; elseif ($c['state'] === 'paused') $s['paused']++; else $s['stopped']++;
    if ($c['update'] === 'available' && empty($c['skip']) && !empty($d['update_check']) && empty(((array)$d['items'])[$c['name']]['no_update'])) $s['updates']++;
    if ($c['health'] === 'unhealthy') $s['unhealthy']++;
  }
  return $s;
}
