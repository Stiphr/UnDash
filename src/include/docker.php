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

function und_docker_bin() { return und_bin('docker'); }

function und_valid_container($n) { return is_string($n) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $n) === 1; }

/** id, name, state and health of every container: one cheap call. */
function und_docker_ps() {
  $r = und_run([und_docker_bin(), 'ps', '-a', '--no-trunc', '--format', '{{.ID}}|{{.Names}}|{{.State}}|{{.Status}}|{{.Image}}'], 15);
  if ($r['code'] !== 0) return ['error' => preg_match('/daemon|connect/i', $r['err']) ? 'Docker is not running.' : (und_clip(trim($r['err']), 200) ?: 'Docker is not available.')];
  $out = [];
  foreach (explode("\n", trim($r['out'])) as $l) {
    $c = explode('|', $l);
    if (count($c) < 5) continue;
    $health = preg_match('/\((healthy|unhealthy|health: starting)\)/', $c[3], $m) ? str_replace('health: ', '', $m[1]) : '';
    $out[$c[1]] = ['id' => $c[0], 'name' => $c[1], 'state' => $c[2], 'status' => $c[3], 'health' => $health, 'image' => $c[4]];
  }
  return ['ps' => $out];
}

/** Inspect data for every container, cached in /tmp/undash/inspect.json and keyed by the set of ids and states. */
function und_docker_details(array $ps, $force = false) {
  $f = und_tmp('inspect.json');
  $sig = md5(json_encode(array_map(function ($c) { return $c['id'] . $c['state']; }, $ps)));
  $c = und_read_json($f, []);
  if (!$force && ($c['sig'] ?? '') === $sig && time() - (int)($c['at'] ?? 0) < 300) return $c['d'];
  $ids = array_values(array_map(function ($x) { return $x['id']; }, $ps));
  $d = [];
  if ($ids) {
    $r = und_run(array_merge([und_docker_bin(), 'inspect'], $ids), 30, 16777216);
    $data = json_decode($r['out'], true);
    if (!is_array($data)) return $c['d'] ?? [];
    $ip = und_server_ip();
    $auto = array_flip(array_map(function ($l) { return trim(explode(' ', $l)[0]); }, explode("\n", und_read('/var/lib/docker/unraid-autostart'))));
    foreach ($data as $x) {
      $name = ltrim((string)($x['Name'] ?? ''), '/');
      $labels = isset($x['Config']['Labels']) && is_array($x['Config']['Labels']) ? $x['Config']['Labels'] : [];
      $ports = [];
      foreach ((array)($x['HostConfig']['PortBindings'] ?? []) as $cp => $binds) foreach ((array)$binds as $b) if (!empty($b['HostPort'])) $ports[(string)$cp] = (int)$b['HostPort'];
      $net = (string)($x['HostConfig']['NetworkMode'] ?? '');
      $cip = '';
      foreach ((array)($x['NetworkSettings']['Networks'] ?? []) as $n) if (!empty($n['IPAddress'])) { $cip = $n['IPAddress']; break; }
      $webui = (string)($labels['net.unraid.docker.webui'] ?? '');
      if ($webui !== '') {
        // the container's own address only when it has one on the LAN (br0 / macvlan style) and publishes nothing on the host;
        // on a user-defined bridge with published ports the web UI is reached through the server's address and the host port
        $own = !$ports && !in_array($net, ['bridge', 'host', 'default', ''], true) && strpos($net, 'container:') !== 0 && $cip !== '';
        $webui = str_replace('[IP]', $own ? $cip : $ip, $webui);
        $webui = preg_replace_callback('/\[PORT:(\d+)\]/', function ($m) use ($ports, $own) {
          foreach ($ports as $cp => $hp) if ((int)$cp === (int)$m[1]) return (string)($own ? $m[1] : $hp);
          return $m[1];
        }, $webui);
      }
      $iconFile = '/usr/local/emhttp/state/plugins/dynamix.docker.manager/images/' . $name . '-icon.png';
      $d[$name] = ['icon' => is_file(und_path($iconFile)) ? '/state/plugins/dynamix.docker.manager/images/' . rawurlencode($name) . '-icon.png' : (string)($labels['net.unraid.docker.icon'] ?? ''),
        'webui' => preg_match('#^https?://#', $webui) ? $webui : '', 'network' => $net, 'ip' => $cip, 'ports' => $ports, 'autostart' => isset($auto[$name]),
        'managed' => ($labels['net.unraid.docker.managed'] ?? '') === 'dockerman', 'restarts' => (int)($x['RestartCount'] ?? 0),
        'started' => strtotime((string)($x['State']['StartedAt'] ?? '')) ?: null, 'exit' => (int)($x['State']['ExitCode'] ?? 0),
        'created' => strtotime((string)($x['Created'] ?? '')) ?: null, 'cfg_image' => (string)($x['Config']['Image'] ?? ''),
        'source' => und_clip((string)($labels['org.opencontainers.image.source'] ?? ''), 300)];
    }
  }
  und_write_json($f, ['sig' => $sig, 'at' => time(), 'd' => $d]);
  return $d;
}

/** CPU % (100% = one core, like `docker stats` and Unraid's Docker tab) and memory from cgroup v2, measured against the previous call. */
function und_docker_cgroup(array $ps) {
  $f = und_tmp('cg.json');
  $prev = und_read_json($f, []);
  $now = microtime(true); $cur = []; $out = [];
  foreach ($ps as $c) {
    if ($c['state'] !== 'running') continue;
    $base = null;
    foreach (['/sys/fs/cgroup/docker/' . $c['id'], '/sys/fs/cgroup/system.slice/docker-' . $c['id'] . '.scope'] as $b) if (is_dir(und_path($b))) { $base = $b; break; }
    if ($base === null) continue;
    $usage = preg_match('/^usage_usec (\d+)/m', und_read("$base/cpu.stat"), $m) ? (float)$m[1] : null;
    $mem = (float)trim(und_read("$base/memory.current"));
    $inactive = preg_match('/^inactive_file (\d+)/m', und_read("$base/memory.stat"), $m) ? (float)$m[1] : 0;
    $cur[$c['id']] = $usage;
    $cpu = null;
    if ($usage !== null && isset($prev['u'][$c['id']]) && $now > ($prev['t'] ?? 0)) $cpu = max(0, round(($usage - $prev['u'][$c['id']]) / (($now - $prev['t']) * 1e6) * 100, 1));
    $out[$c['name']] = ['cpu' => $cpu, 'mem' => max(0, $mem - $inactive)];
  }
  // several browsers polling at once must not shrink the window to nothing: keep the older sample if it is very recent
  if ($now - ($prev['t'] ?? 0) >= 1) und_write_json($f, ['t' => $now, 'u' => $cur]);
  return $out;
}

/** Update state per container from Unraid's own status file: available | current | unknown (never checked or not an Unraid template). */
function und_docker_updates() {
  return und_read_json(und_path(UND_UPDSTATUS), []);
}
function und_image_key($image) { return und_has(basename($image), ':') || und_has($image, '@') ? $image : $image . ':latest'; }
function und_docker_update_state(array $upd, $image) {
  $u = $upd[und_image_key($image)] ?? null;
  if (!is_array($u)) return 'unknown';
  $s = (string)($u['status'] ?? '');
  return $s === 'false' ? 'available' : ($s === 'true' ? 'current' : 'unknown');
}

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
  if (!und_api_mode()) { $d = (string)(und_docker_updates()[und_image_key($image)]['remote'] ?? ''); return preg_match('/^sha256:[a-f0-9]{64}$/', $d) ? $d : ''; }
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
  $api = und_api_mode();
  $seen = $api ? und_read_json(und_tmp('image-digests.json'), []) : und_docker_updates();
  foreach ($list as &$c) {
    $s = (string)($items[$c['name']]['skip_update'] ?? '');
    if ($s === '' || $c['update'] !== 'available') continue;
    $now = $api ? (string)($seen[$c['image']]['d'] ?? '') : (string)($seen[und_image_key($c['image'])]['remote'] ?? '');
    if ($now === '' || $now === $s) $c['skip'] = true;
  }
  unset($c);
  return $list;
}

function und_docker_skip($name, $on) {
  $l = und_docker_live(false);
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
  $l = und_docker_live(false);
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
function und_docker_live($withDetails = true) {
  if (und_api_mode()) return und_api_docker_live();
  $ps = und_docker_ps();
  if (isset($ps['error'])) return $ps;
  $ps = $ps['ps'];
  $det = $withDetails ? und_docker_details($ps) : [];
  $cg = und_docker_cgroup($ps);
  $upd = und_docker_updates();
  $out = [];
  foreach ($ps as $name => $c) {
    $d = $det[$name] ?? [];
    $job = und_job(und_tmp('docker-update-' . $name . '.log'));
    $image = $d['cfg_image'] ?? $c['image'];
    $out[] = ['name' => $name, 'id' => substr($c['id'], 0, 12), 'image' => $image, 'state' => $c['state'], 'status' => $c['status'], 'health' => $c['health'],
      'cpu' => $cg[$name]['cpu'] ?? null, 'mem' => $cg[$name]['mem'] ?? null, 'update' => und_docker_update_state($upd, $image),
      'icon' => $d['icon'] ?? '', 'webui' => $d['webui'] ?? '', 'ports' => (object)($d['ports'] ?? []), 'network' => $d['network'] ?? '', 'autostart' => !empty($d['autostart']),
      'managed' => !empty($d['managed']), 'restarts' => $d['restarts'] ?? null, 'started' => $d['started'] ?? null, 'exit' => $d['exit'] ?? null,
      'job' => $job['state'] === 'running' ? 'updating' : ($job['state'] === 'done' && time() - (int)$job['at'] < 600 ? ($job['code'] === 0 ? 'updated' : 'update_failed') : '')];
  }
  usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  $chk = und_job(und_tmp('docker-check.log'));
  return ['containers' => und_docker_skips($out), 'checking' => $chk['state'] === 'running', 'checked' => is_file(und_path(UND_UPDSTATUS)) ? (int)@filemtime(und_path(UND_UPDSTATUS)) : null];
}

function und_docker_action($name, $op) {
  if (!und_valid_container($name)) return ['error' => 'Invalid container name.'];
  if (und_api_mode()) return und_api_docker_action($name, $op);
  $ps = und_docker_ps();
  if (isset($ps['error'])) return $ps;
  if (!isset($ps['ps'][$name])) return ['error' => 'There is no container named ' . $name . '.'];
  $state = $ps['ps'][$name]['state'];
  $valid = ['start' => ['exited', 'created', 'dead'], 'stop' => ['running', 'restarting', 'paused'], 'restart' => ['running', 'exited', 'created', 'restarting'],
            'pause' => ['running'], 'unpause' => ['paused'], 'update' => null];
  if (!array_key_exists($op, $valid)) return ['error' => 'Unknown action.'];
  if ($valid[$op] !== null && !in_array($state, $valid[$op], true)) return ['error' => ucfirst($op) . ' is not possible while ' . $name . ' is ' . $state . '.'];
  $lock = und_lock('docker:' . $name);
  if (!$lock) return ['error' => 'Another action on ' . $name . ' is still running.'];
  if ($op === 'update') {
    $log = und_tmp('docker-update-' . $name . '.log');
    if (und_job($log)['state'] === 'running') { und_unlock($lock); return ['error' => 'An update of ' . $name . ' is already running.']; }
    und_spawn([UND_DM . '/scripts/update_container', $name], $log);
    und_history_add('docker', $name, 'update', true, 'started');
    und_unlock($lock);
    return ['ok' => true, 'job' => 'running'];
  }
  $r = und_run([und_docker_bin(), $op, $name], 120);
  und_unlock($lock);
  @unlink(und_tmp('inspect.json'));
  $ok = $r['code'] === 0;
  $msg = $ok ? '' : (und_clip(trim(preg_replace('/^Error response from daemon:\s*/i', '', $r['err'] . ' ' . $r['out'])), 300) ?: 'docker ' . $op . ' failed');
  und_history_add('docker', $name, $op, $ok, $msg);
  return $ok ? ['ok' => true] : ['error' => $msg];
}

/** Starts Unraid's own update check for every container (the "check for updates" button of the Docker tab). */
function und_docker_check() {
  if (und_api_mode()) return ['error' => 'Unraid checks container updates on its own schedule; the API has no "check now".'];
  $log = und_tmp('docker-check.log');
  if (und_job($log)['state'] === 'running') return ['ok' => true, 'job' => 'running'];
  und_spawn([UND_DM . '/scripts/dockerupdate', 'check', 'nonotify'], $log);
  und_history_add('docker', 'all containers', 'check', true, 'update check started');
  return ['ok' => true, 'job' => 'running'];
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
  if (und_api_mode()) return und_api_docker_logs($name, $lines);
  $lines = max(10, min(5000, (int)$lines));
  $r = und_run([und_docker_bin(), 'logs', '--tail', (string)$lines, '--timestamps', $name], 20, 8388608);
  if ($r['code'] !== 0 && $r['out'] === '' && $r['err'] === '') return ['error' => 'Could not read the log.'];
  if ($r['code'] !== 0 && preg_match('/No such container/i', $r['err'])) return ['error' => 'There is no container named ' . $name . '.'];
  $all = array_merge(explode("\n", rtrim($r['out'])), explode("\n", rtrim($r['err'])));
  $all = array_values(array_filter($all, function ($l) { return $l !== ''; }));
  usort($all, function ($a, $b) { return strcmp(substr($a, 0, 30), substr($b, 0, 30)); });
  $all = array_slice($all, -$lines);
  $text = implode("\n", array_map(function ($l) { return preg_replace('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)\.\d+Z /', '$1 ', $l); }, $all));
  return ['log' => preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $text)];
}

/**
 * Diagnostic details for one container. Environment variables are listed by name only (their values are often passwords),
 * labels are limited to Unraid's and OCI's descriptive ones.
 */
function und_docker_inspect($name) {
  if (!und_valid_container($name)) return ['error' => 'Invalid container name.'];
  if (und_api_mode()) return und_api_docker_inspect($name);
  $r = und_run([und_docker_bin(), 'inspect', $name], 15, 4194304);
  $j = json_decode($r['out'], true);
  if (!is_array($j) || !isset($j[0])) return ['error' => 'There is no container named ' . $name . '.'];
  $x = $j[0];
  $mounts = [];
  foreach ((array)($x['Mounts'] ?? []) as $m) $mounts[] = ['src' => (string)($m['Source'] ?? ''), 'dst' => (string)($m['Destination'] ?? ''), 'rw' => !empty($m['RW'])];
  $nets = [];
  foreach ((array)($x['NetworkSettings']['Networks'] ?? []) as $n => $v) $nets[] = ['name' => $n, 'ip' => (string)($v['IPAddress'] ?? '')];
  $ports = [];
  foreach ((array)($x['HostConfig']['PortBindings'] ?? []) as $cp => $binds) foreach ((array)$binds as $b) $ports[] = ($b['HostIp'] ?? '') . ':' . ($b['HostPort'] ?? '') . ' → ' . $cp;
  $env = [];
  foreach ((array)($x['Config']['Env'] ?? []) as $e) $env[] = explode('=', (string)$e, 2)[0];
  $health = [];
  foreach (array_slice((array)($x['State']['Health']['Log'] ?? []), -3) as $hl) $health[] = ['at' => strtotime((string)($hl['End'] ?? '')) ?: null, 'code' => (int)($hl['ExitCode'] ?? 0), 'out' => und_clip(und_redact(trim((string)($hl['Output'] ?? ''))), 400)];
  $labels = [];
  foreach ((array)($x['Config']['Labels'] ?? []) as $k => $v) if (preg_match('/^(net\.unraid\.docker\.(managed|webui|icon|shell)|org\.opencontainers\.image\.(version|source|title|description|url|created))$/', $k)) $labels[$k] = und_clip((string)$v, 300);
  return ['name' => $name, 'id' => substr((string)$x['Id'], 0, 12), 'image' => (string)($x['Config']['Image'] ?? ''), 'image_id' => substr(str_replace('sha256:', '', (string)($x['Image'] ?? '')), 0, 12),
    'created' => strtotime((string)($x['Created'] ?? '')) ?: null, 'state' => (string)($x['State']['Status'] ?? ''), 'started' => strtotime((string)($x['State']['StartedAt'] ?? '')) ?: null,
    'finished' => strtotime((string)($x['State']['FinishedAt'] ?? '')) ?: null, 'exit' => (int)($x['State']['ExitCode'] ?? 0), 'state_error' => (string)($x['State']['Error'] ?? ''),
    'oom' => !empty($x['State']['OOMKilled']), 'restarts' => (int)($x['RestartCount'] ?? 0), 'restart_policy' => (string)($x['HostConfig']['RestartPolicy']['Name'] ?? ''),
    'network_mode' => (string)($x['HostConfig']['NetworkMode'] ?? ''), 'networks' => $nets, 'ports' => $ports, 'mounts' => $mounts, 'env' => $env, 'labels' => (object)$labels, 'health' => $health,
    'privileged' => !empty($x['HostConfig']['Privileged'])];
}

function und_docker_summary() {
  $l = und_docker_live(false);
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
