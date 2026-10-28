<?php
/*
 * Local preview of UnDash without an Unraid server:  php -S 127.0.0.1:8088 dev/server.php
 * Fakes /proc, Docker, libvirt, NUT and every app API with fixtures, then serves the real UI and the real api.php.
 * Nothing in here ships in the plugin.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$repo = dirname(__DIR__);
$state = sys_get_temp_dir() . '/undash-dev';

if ($uri === '/' || $uri === '/index.html') {
  header('Content-Type: text/html');
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UnDash (dev)</title>',
       '<style>body{margin:0;padding:20px 28px;background:#1c1b1b;font-family:system-ui}</style>',
       '<link rel="stylesheet" href="/assets/app.css"></head><body><div id="und" class="und"><div class="und-loading">Loading</div></div>',
       '<script>window.UND_API="/api";window.UND_CSRF="dev";</script><script src="/assets/app.js"></script></body></html>';
  return true;
}
if (strpos($uri, '/assets/') === 0) {
  $f = $repo . '/src' . $uri;
  if (!is_file($f)) { http_response_code(404); return true; }
  header('Content-Type: ' . (substr($f, -3) === 'css' ? 'text/css' : 'application/javascript'));
  readfile($f); return true;
}
if ($uri !== '/api') { http_response_code(404); return true; }

/* ---------- fake system ---------- */
define('UND_DIR', $state . '/flash');
define('UND_TMP', $state . '/tmp');
define('UND_ROOT', $repo . '/src');
$fs = $state . '/fs';
@mkdir("$fs/proc/net", 0777, true); @mkdir("$fs/etc", 0777, true); @mkdir("$fs/var/local/emhttp", 0777, true); @mkdir("$fs/sys/class/hwmon/hwmon0", 0777, true);
@mkdir("$fs/var/lib/docker", 0777, true); @mkdir("$fs/mnt/user", 0777, true); @mkdir("$fs/boot/config/plugins", 0777, true);
$t = microtime(true) * 100;
file_put_contents("$fs/proc/stat", sprintf("cpu  %d 0 %d %d 0 0 0 0 0 0\n", $t * 0.6 + 400 * sin($t / 900), $t * 0.2, $t * 7 - 400 * sin($t / 900)));
file_put_contents("$fs/proc/meminfo", "MemTotal:       65536000 kB\nMemFree:         2000000 kB\nMemAvailable:   " . (int)(45000000 + 3000000 * sin($t / 2000)) . " kB\n");
file_put_contents("$fs/proc/cpuinfo", str_repeat("processor\t: 0\nmodel name\t: Intel(R) Core(TM) i5-14500\n\n", 20));
file_put_contents("$fs/proc/uptime", "151200.55 2000000.1\n");
file_put_contents("$fs/proc/loadavg", "1.21 1.05 0.98 2/900 1234\n");
file_put_contents("$fs/proc/version", "Linux version 6.18.58-Unraid (root@build) #1 SMP\n");
file_put_contents("$fs/proc/net/route", "Iface\tDestination\tGateway\nbr0\t00000000\t0101A8C0\n");
file_put_contents("$fs/proc/net/dev", sprintf("Inter-|\n face |\n   br0: %d 0 0 0 0 0 0 0 %d 0 0 0 0 0 0 0\n", $t * 470, $t * 80));
file_put_contents("$fs/sys/class/hwmon/hwmon0/name", "coretemp\n");
file_put_contents("$fs/sys/class/hwmon/hwmon0/temp1_label", "Package id 0\n");
file_put_contents("$fs/sys/class/hwmon/hwmon0/temp1_input", (35000 + rand(0, 3000)) . "\n");
file_put_contents("$fs/etc/unraid-version", "version=\"7.3.4\"\n");
file_put_contents("$fs/var/local/emhttp/var.ini", "NAME=\"R1ZE\"\nmdState=\"STARTED\"\nmdResync=\"0\"\n");
file_put_contents("$fs/var/local/emhttp/network.ini", "[eth0]\nIPADDR:0=\"192.168.1.10\"\n");
file_put_contents("$fs/var/local/emhttp/disks.ini", "[\"parity\"]\nname=\"parity\"\ndevice=\"sdb\"\nstatus=\"DISK_OK\"\ntemp=\"31\"\ntype=\"Parity\"\nfsSize=\"\"\n" .
  "[\"disk1\"]\nname=\"disk1\"\ndevice=\"sdc\"\nstatus=\"DISK_OK\"\ntemp=\"33\"\ntype=\"Data\"\nfsSize=\"11718885324\"\nfsFree=\"2343777064\"\nfsType=\"xfs\"\n" .
  "[\"disk2\"]\nname=\"disk2\"\ndevice=\"sdd\"\nstatus=\"DISK_OK\"\ntemp=\"*\"\nspundown=\"1\"\ntype=\"Data\"\nfsSize=\"7811939620\"\nfsFree=\"1453512040\"\nfsType=\"xfs\"\n" .
  "[\"cache\"]\nname=\"cache\"\ndevice=\"nvme0n1\"\nstatus=\"DISK_OK\"\ntemp=\"42\"\ntype=\"Cache\"\nfsSize=\"976761560\"\nfsFree=\"611329392\"\nfsType=\"btrfs\"\n");
file_put_contents("$fs/var/lib/docker/unraid-update-status.json", json_encode(['lscr.io/linuxserver/radarr:latest' => ['status' => 'false'], 'lscr.io/linuxserver/sonarr:latest' => ['status' => 'true'], 'plexinc/pms-docker:latest' => ['status' => 'true'], 'ghcr.io/gethomepage/homepage:latest' => ['status' => 'false']]));
file_put_contents("$fs/var/lib/docker/unraid-autostart", "radarr\nsonarr\nplex\n");
$GLOBALS['UND_FS'] = $fs;

/* ---------- fake commands ---------- */
$cont = function ($name, $image, $state, $port, $health = '') {
  return ['Id' => md5($name), 'Name' => '/' . $name, 'State' => ['Status' => $state, 'StartedAt' => gmdate('c', time() - rand(3600, 900000))] + ($health ? ['Health' => ['Status' => $health]] : []),
    'Config' => ['Image' => $image, 'Labels' => ['net.unraid.docker.managed' => 'dockerman', 'net.unraid.docker.webui' => "http://[IP]:[PORT:$port]/", 'net.unraid.docker.icon' => 'https://cdn.jsdelivr.net/gh/selfhst/icons@main/png/' . explode('-', $name)[0] . '.png']],
    'HostConfig' => ['NetworkMode' => 'bridge', 'PortBindings' => ["$port/tcp" => [['HostPort' => (string)$port]]]], 'NetworkSettings' => ['Networks' => ['bridge' => ['IPAddress' => '172.17.0.' . rand(2, 99)]]]];
};
$containers = [$cont('radarr', 'lscr.io/linuxserver/radarr', 'running', 7878), $cont('sonarr', 'lscr.io/linuxserver/sonarr', 'running', 8989), $cont('plex', 'plexinc/pms-docker', 'running', 32400, 'healthy'),
  $cont('homepage', 'ghcr.io/gethomepage/homepage:latest', 'exited', 3000), $cont('qbittorrent', 'lscr.io/linuxserver/qbittorrent', 'paused', 8080), $cont('immich', 'ghcr.io/immich-app/immich-server:release', 'running', 2283, 'unhealthy')];
$ds = $state . '/docker-state.json';
$dstate = is_file($ds) ? json_decode(file_get_contents($ds), true) : [];
foreach ($containers as &$c) { $n = ltrim($c['Name'], '/'); if (isset($dstate[$n])) $c['State']['Status'] = $dstate[$n]; } unset($c);
$vs = $state . '/vm-state.json';
$vstate = is_file($vs) ? json_decode(file_get_contents($vs), true) : ['Windows 11' => 'running', 'Home Assistant' => 'shut off', 'Ubuntu Server' => 'paused'];

$GLOBALS['UND_EXEC_MOCK'] = function ($argv) use ($containers, $ds, $dstate, $vs, $vstate) {
  $cmd = basename($argv[0]);
  if ($cmd === 'docker') {
    if ($argv[1] === 'ps') return ['out' => implode("\n", array_map(function ($c) { return $c['Id']; }, $containers))];
    if ($argv[1] === 'inspect') return ['out' => json_encode($containers)];
    if ($argv[1] === 'stats') { $o = ''; foreach ($containers as $c) if ($c['State']['Status'] === 'running') $o .= json_encode(['Name' => ltrim($c['Name'], '/'), 'CPUPerc' => rand(0, 900) / 100 . '%', 'MemUsage' => rand(80, 2000) . 'MiB / 62.5GiB', 'MemPerc' => '1.2%']) . "\n"; return ['out' => $o]; }
    if ($argv[1] === 'logs') { $o = ''; for ($i = 0; $i < 60; $i++) $o .= gmdate('Y-m-d\TH:i:s', time() - 600 + $i * 10) . '.123456789Z [Info] ' . $argv[5] . ": line $i " . ($i % 7 ? 'everything is fine' : 'RSS sync completed, 3 releases found') . "\n"; return ['out' => $o, 'err' => gmdate('Y-m-d\TH:i:s', time() - 30) . ".5Z [Warn] a warning on stderr\n"]; }
    $map = ['start' => 'running', 'stop' => 'exited', 'restart' => 'running', 'pause' => 'paused', 'unpause' => 'running'];
    if (isset($map[$argv[1]])) { $dstate[$argv[2]] = $map[$argv[1]]; file_put_contents($ds, json_encode($dstate)); return ['out' => $argv[2]]; }
  }
  if ($cmd === 'virsh') {
    if ($argv[1] === 'list') return ['out' => implode("\n", array_keys($vstate)) . "\n"];
    if ($argv[1] === 'dominfo') return ['out' => "Name: {$argv[2]}\nState: " . $vstate[$argv[2]] . "\nCPU(s): 8\nMax memory: 16777216 KiB\nAutostart: " . ($argv[2] === 'Windows 11' ? 'enable' : 'disable') . "\n"];
    if ($argv[1] === 'dumpxml') return ['out' => '<domain><metadata><vmtemplate xmlns="unraid" name="Windows 11" icon="' . ($argv[2] === 'Windows 11' ? 'windows11.png' : 'linux.png') . '" os="' . ($argv[2] === 'Windows 11' ? 'windowstpm' : 'linux') . '"/></metadata><graphics type="vnc" port="5900" websocket="5700"/>' . ($argv[2] === 'Windows 11' ? '<channel type="unix"><target type="virtio" name="org.qemu.guest_agent.0"/></channel>' : '') . '</domain>'];
    $map = ['start' => 'running', 'shutdown' => 'shut off', 'destroy' => 'shut off', 'reboot' => 'running', 'suspend' => 'paused', 'resume' => 'running', 'dompmsuspend' => 'pmsuspended', 'dompmwakeup' => 'running'];
    if ($argv[1] === 'dompmsuspend' && $argv[2] !== 'Windows 11') return ['code' => 1, 'err' => 'error: Guest agent is not responding: QEMU guest agent is not connected'];
    if (isset($map[$argv[1]])) { $vstate[$argv[2]] = $map[$argv[1]]; file_put_contents($vs, json_encode($vstate)); return []; }
  }
  if ($cmd === 'upsc') return ['out' => "battery.charge: 100\nbattery.runtime: 3120\nups.load: 9\nups.status: OL\nups.model: Back-UPS 1500\n"];
  if ($cmd === 'plugin' && $argv[1] === 'check') return ['out' => "7.3.5\n"];
  return ['code' => 127, 'err' => "dev: $cmd not mocked"];
};
$GLOBALS['UND_SPAWN_MOCK'] = function ($argv, $log) {
  file_put_contents($log, "dev: would run " . implode(' ', $argv) . "\nPulling image...\nDone.\n");
  file_put_contents($log . '.done', "0\n");
};

/* ---------- fake app APIs (matched on the path, whatever the host) ---------- */
$GLOBALS['UND_HTTP_MOCK'] = function ($method, $url, $o) {
  $p = parse_url($url, PHP_URL_PATH) ?: '/'; parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
  $j = function ($d, $code = 200) { return ['code' => $code, 'body' => json_encode($d), 'json' => $d, 'err' => '', 'headers' => ['set-cookie' => ['SID=dev; path=/']]]; };
  $host = parse_url($url, PHP_URL_HOST);
  if ($host === 'down.invalid') return ['code' => 0, 'body' => '', 'json' => null, 'err' => 'Failed to connect to down.invalid port 80: Connection refused', 'headers' => []];
  $d = function ($days) { return gmdate('Y-m-d\TH:i:s\Z', time() + $days * 86400); };
  switch (true) {
    case $p === '/api/v3/movie': $m = []; for ($i = 0; $i < 173; $i++) $m[] = ['monitored' => true, 'hasFile' => $i > 7, 'isAvailable' => true]; return $j($m);
    case $p === '/api/v3/queue/status': return $j(['totalCount' => 0]);
    case $p === '/api/v3/wanted/missing': return $j(['totalRecords' => 62]);
    case $p === '/api/v3/queue': return $j(['totalRecords' => 26]);
    case $p === '/api/v3/series': return $j(array_fill(0, 151, []));
    case $p === '/api/v1/wanted/missing': return $j(['totalRecords' => 94]);
    case $p === '/api/v1/queue/status': return $j(['totalCount' => 4]);
    case $p === '/api/v1/artist': return $j(array_fill(0, 19, []));
    case $p === '/api/badges': return $j(['episodes' => 124, 'movies' => 6]);
    case $p === '/api/v1/indexerstats': return $j(['indexers' => [['numberOfGrabs' => 44000, 'numberOfQueries' => 530000, 'numberOfFailedGrabs' => 331, 'numberOfFailedQueries' => 4282], ['numberOfGrabs' => 629, 'numberOfQueries' => 821]]]);
    case $p === '/api/v1/request/count': return $j(['pending' => 0, 'approved' => 4, 'processing' => 1, 'available' => 2, 'completed' => 2]);
    case $p === '/api/release/stats': return $j(['push_approved_count' => 2843, 'push_rejected_count' => 0]);
    case $p === '/api/filters': return $j(array_fill(0, 7, []));
    case $p === '/api/release/indexers': return $j(array_fill(0, 7, 'x'));
    case $p === '/api/status': return $j(['users' => 14, 'invites' => 2, 'pending' => 0, 'expired' => 2]);
    case $p === '/api/v2/auth/login': return ['code' => 200, 'body' => 'Ok.', 'json' => null, 'err' => '', 'headers' => ['set-cookie' => ['SID=dev; path=/']]];
    case $p === '/api/v2/torrents/info': $t = []; for ($i = 0; $i < 1808; $i++) $t[] = ['progress' => 1, 'dlspeed' => 0, 'upspeed' => $i < 3 ? 143200 : 0]; return $j($t);
    case $p === '/api': return $j(['error' => 'API Key Incorrect']);
    case substr($p, -13) === '/Items/Counts': return $j(['MovieCount' => 173, 'SeriesCount' => 151, 'EpisodeCount' => 5570, 'SongCount' => 0]);
    case substr($p, -9) === '/Sessions': return $j([]);
    case $p === '/status/sessions': return $j(['MediaContainer' => ['size' => 1]]);
    case $p === '/library/sections': return $j(['MediaContainer' => ['Directory' => [['key' => '1', 'type' => 'movie'], ['key' => '2', 'type' => 'show']]]]);
    case preg_match('#^/library/sections/(\d)/all$#', $p, $m) === 1: return $j(['MediaContainer' => ['totalSize' => $m[1] === '1' ? 173 : 129]]);
    case $p === '/api/v2' && ($q['cmd'] ?? '') === 'get_activity': return $j(['response' => ['result' => 'success', 'data' => ['sessions' => [['media_type' => 'episode', 'grandparent_title' => 'The Mentalist', 'parent_media_index' => 3, 'media_index' => 9, 'title' => 'Red Moon', 'friendly_name' => 'Stiphr', 'state' => 'playing', 'view_offset' => 1916000, 'duration' => 2580000, 'transcode_decision' => 'direct play']]]]]);
    case $p === '/api/v2' && ($q['cmd'] ?? '') === 'get_recently_added':
      $ep = ($q['media_type'] ?? '') === 'episode';
      $names = $ep ? ['Bleach', 'Has Fallen', 'A Wild Last Boss Appeared!', 'Black Clover', 'Tokyo Revengers', 'Cupertino'] : ['The Paradise', 'Dastaar', 'Jailer 2', 'Runner', 'Toxic', 'Khalifa: The Ruler'];
      $ago = [3, 3, 4, 4, 5, 6]; if (!$ep) $ago = [7, 14, 14, 21, 21, 21];
      $r = []; foreach ($names as $i => $n) $r[] = ['media_type' => $ep ? 'episode' : 'movie', ($ep ? 'grandparent_title' : 'title') => $n, 'title' => $n, 'added_at' => (string)(time() - $ago[$i] * 86400)];
      return $j(['response' => ['result' => 'success', 'data' => ['recently_added' => $r]]]);
    case $p === '/api/v3/calendar' && isset($q['includeSeries']):
      return $j([['airDateUtc' => $d(1), 'series' => ['title' => 'Cupertino'], 'seasonNumber' => 1, 'episodeNumber' => 4, 'hasFile' => false], ['airDateUtc' => $d(1), 'series' => ['title' => 'MobLand'], 'seasonNumber' => 2, 'episodeNumber' => 1, 'hasFile' => false],
        ['airDateUtc' => $d(1), 'series' => ['title' => 'Tulsa King'], 'seasonNumber' => 3, 'episodeNumber' => 8, 'hasFile' => false], ['airDateUtc' => $d(2), 'series' => ['title' => 'Black Clover'], 'seasonNumber' => 5, 'episodeNumber' => 2, 'hasFile' => false],
        ['airDateUtc' => $d(2), 'series' => ['title' => 'A Wild Last Boss Appeared!'], 'seasonNumber' => 1, 'episodeNumber' => 5, 'hasFile' => true], ['airDateUtc' => $d(4), 'series' => ['title' => 'Dexter: Resurrection'], 'seasonNumber' => 2, 'episodeNumber' => 1, 'hasFile' => false]]);
    case $p === '/api/v3/calendar':
      return $j([['title' => 'Avatar Aang: The Last Airbender', 'physicalRelease' => $d(4), 'hasFile' => true], ['title' => 'The End of Oak Street', 'physicalRelease' => $d(5), 'hasFile' => true],
        ['title' => 'Ramayana: Rise of a Legend', 'inCinemas' => $d(6), 'hasFile' => false], ['title' => 'How to Rob a Bank', 'inCinemas' => $d(13), 'hasFile' => false], ['title' => 'The Odyssey', 'physicalRelease' => $d(19), 'digitalRelease' => $d(19), 'hasFile' => false]]);
    case $p === '/control/stats': return $j(['num_dns_queries' => 16947, 'num_blocked_filtering' => 2973, 'num_replaced_safebrowsing' => 0, 'avg_processing_time' => 0.045284]);
    case $p === '/api/tokens': return $j(['token' => 'devtoken', 'expires' => gmdate('c', time() + 86400)]);
    case $p === '/api/nginx/proxy-hosts': return $j(array_merge(array_fill(0, 7, ['enabled' => 1]), array_fill(0, 5, ['enabled' => 0])));
    case $p === '/api/server/statistics': return $j(['photos' => 4206, 'videos' => 311, 'usage' => 54 * 1073741824, 'usageByUser' => [[], []]]);
    case strpos($p, '/api/v1/devices/') === 0: return $j(['battery.charge' => 100, 'ups.load' => 9, 'ups.status' => 'OL']);
  }
  return $j(['error' => 'not found'], 404);
};

/* first run: import a real Homepage layout when UNDASH_HOMEPAGE_DIR points at its config folder, so the preview looks like your dashboard */
if (!is_file(UND_DIR . '/config.json')) {
  require_once $repo . '/src/include/lib.php';
  $svc = rtrim((string)getenv('UNDASH_HOMEPAGE_DIR'), '/') . '/services.yaml';
  if (getenv('UNDASH_HOMEPAGE_DIR') && is_file($svc)) {
    $c = und_hp_convert(file_get_contents($svc), (string)@file_get_contents(dirname($svc) . '/settings.yaml'), '');
    foreach ($c['vars'] as $n => $v) $c['vars'][$n] = preg_match('/^HOMEPAGE_VAR_PORT_/', $n) ? '80' : ($v ?? 'dev');
    $c['vars']['HOMEPAGE_VAR_SERVER_IP'] = 'apps.local';
    // one card shows the error state
    foreach ($c['config']['groups'] as &$g) foreach ($g['services'] as &$s) if ($s['name'] === 'SABnzbd') $s['url'] = 'http://down.invalid';
    unset($g, $s);
    // add Docker and VM summaries next to the imported cards
    $c['config']['groups'][] = ['id' => 'aaaaaaaa', 'name' => 'Server', 'columns' => 3, 'header' => true, 'services' => [
      ['id' => 'bbbbbbbb', 'name' => 'Docker', 'type' => 'docker_summary', 'icon' => 'sh-docker', 'href' => '', 'url' => '', 'fields' => [], 'options' => []],
      ['id' => 'cccccccc', 'name' => 'Virtual machines', 'type' => 'vm_summary', 'icon' => 'sh-qemu', 'href' => '', 'url' => '', 'fields' => [], 'options' => []],
      ['id' => 'dddddddd', 'name' => 'Storage', 'type' => 'storage', 'icon' => 'sh-unraid', 'href' => '', 'url' => '', 'fields' => [], 'options' => []]]];
    und_hp_apply($c);
  }
}

$_SERVER['REQUEST_METHOD'] = 'POST';
require $repo . '/src/include/api.php';
