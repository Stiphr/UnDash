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
  header('Content-Type: ' . (substr($f, -5) === 'woff2' ? 'font/woff2' : (substr($f, -3) === 'css' ? 'text/css' : 'application/javascript')));
  readfile($f); return true;
}
if ($uri !== '/api') { http_response_code(404); return true; }

/* ---------- fake system ---------- */
if (getenv('UNDASH_DEV_MODE') === 'api') { define('UND_MODE', 'api'); $state .= '-api'; putenv('UNRAID_API_URL=http://tower.test/graphql'); putenv('UNRAID_API_KEY=dev-key'); }
define('UND_DIR', $state . '/flash');
define('UND_TMP', $state . '/tmp');
define('UND_ROOT', $repo . '/src');
$fs = $state . '/fs';
@mkdir("$fs/proc/net", 0777, true); @mkdir("$fs/etc", 0777, true); @mkdir("$fs/var/local/emhttp", 0777, true); @mkdir("$fs/sys/class/hwmon/hwmon0", 0777, true);
@mkdir("$fs/var/lib/docker", 0777, true); @mkdir("$fs/mnt/user/appdata", 0777, true); @mkdir("$fs/boot/config/plugins", 0777, true);
$t = microtime(true) * 100;
$stat = sprintf("cpu  %d 0 %d %d 0 0 0 0 0 0\n", $t * 12 + 8000 * sin($t / 900), $t * 4, $t * 140 - 8000 * sin($t / 900));
for ($i = 0; $i < 20; $i++) { $f = 0.2 + 0.8 * abs(sin($i * 1.7 + $t / 3000)); $stat .= sprintf("cpu%d %d 0 %d %d 0 0 0 0 0 0\n", $i, $t * $f, $t * 0.1, $t * (7 - $f)); }
file_put_contents("$fs/proc/stat", $stat);
file_put_contents("$fs/proc/meminfo", "MemTotal:       65536000 kB\nMemFree:         2000000 kB\nMemAvailable:   " . (int)(45000000 + 3000000 * sin($t / 2000)) . " kB\nSwapTotal:       0 kB\nSwapFree:        0 kB\n");
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
file_put_contents("$fs/var/local/emhttp/var.ini", "NAME=\"R1ZE\"\nmdState=\"STARTED\"\nmdResync=\"0\"\ncsrf_token=\"dev\"\n");
if (!is_file($state . '/flash/undash.cfg')) { @mkdir($state . '/flash', 0777, true); file_put_contents($state . '/flash/undash.cfg', "CONFIG_DIR=\"/mnt/user/appdata/undash\"\nPORT=\"9099\"\nBIND=\"0.0.0.0\"\nSTANDALONE=\"yes\"\n"); }
file_put_contents("$fs/var/local/emhttp/network.ini", "[eth0]\nIPADDR:0=\"192.168.1.10\"\n");
file_put_contents("$fs/var/local/emhttp/disks.ini", "[\"parity\"]\nname=\"parity\"\ndevice=\"sdb\"\nstatus=\"DISK_OK\"\ntemp=\"31\"\ntype=\"Parity\"\nfsSize=\"\"\n" .
  "[\"disk1\"]\nname=\"disk1\"\ndevice=\"sdc\"\nstatus=\"DISK_OK\"\ntemp=\"52\"\ntype=\"Data\"\nfsSize=\"11718885324\"\nfsFree=\"2343777064\"\nfsType=\"xfs\"\n" .
  "[\"disk2\"]\nname=\"disk2\"\ndevice=\"sdd\"\nstatus=\"DISK_OK\"\ntemp=\"*\"\nspundown=\"1\"\ntype=\"Data\"\nfsSize=\"7811939620\"\nfsFree=\"1453512040\"\nfsType=\"xfs\"\n" .
  "[\"cache\"]\nname=\"cache\"\ndevice=\"nvme0n1\"\nstatus=\"DISK_OK\"\ntemp=\"42\"\ntype=\"Cache\"\nfsSize=\"976761560\"\nfsFree=\"611329392\"\nfsType=\"btrfs\"\n");
file_put_contents("$fs/var/lib/docker/unraid-update-status.json", json_encode(['lscr.io/linuxserver/radarr:latest' => ['status' => 'false'], 'lscr.io/linuxserver/sonarr:latest' => ['status' => 'true'], 'plexinc/pms-docker:latest' => ['status' => 'true'], 'ghcr.io/gethomepage/homepage:latest' => ['status' => 'false']]));
file_put_contents("$fs/var/lib/docker/unraid-autostart", "radarr\nsonarr\nplex\n");
$GLOBALS['UND_FS'] = $fs;

/* ---------- fake commands ---------- */
$cont = function ($name, $image, $state, $port, $health = '', $restarts = 0) {
  return ['Id' => md5($name) . md5($name . 'x'), 'Name' => '/' . $name, 'Created' => gmdate('c', time() - 9000000), 'RestartCount' => $restarts,
    'State' => ['Status' => $state, 'StartedAt' => gmdate('c', time() - (crc32($name) % 900000) - 3600), 'ExitCode' => $state === 'exited' ? ($name === 'homepage' ? 0 : 137) : 0] + ($health ? ['Health' => ['Status' => $health, 'Log' => [['End' => gmdate('c'), 'ExitCode' => $health === 'unhealthy' ? 1 : 0, 'Output' => $health === 'unhealthy' ? 'curl: (7) Failed to connect to localhost port 2283' : 'ok']]]] : []),
    'Config' => ['Image' => $image, 'Env' => ['PUID=99', 'PGID=100', 'TZ=Asia/Kolkata', 'API_KEY=secret'], 'Labels' => ['net.unraid.docker.managed' => 'dockerman', 'net.unraid.docker.webui' => "http://[IP]:[PORT:$port]/", 'net.unraid.docker.icon' => 'https://cdn.jsdelivr.net/gh/selfhst/icons@main/png/' . explode('-', $name)[0] . '.png']],
    'Image' => 'sha256:' . md5($image), 'Mounts' => [['Source' => '/mnt/user/appdata/' . $name, 'Destination' => '/config', 'RW' => true]],
    'HostConfig' => ['NetworkMode' => 'bridge', 'RestartPolicy' => ['Name' => 'no'], 'PortBindings' => ["$port/tcp" => [['HostIp' => '', 'HostPort' => (string)$port]]]], 'NetworkSettings' => ['Networks' => ['bridge' => ['IPAddress' => '172.17.0.' . (crc32($name) % 90 + 2)]]]];
};
$containers = [$cont('radarr', 'lscr.io/linuxserver/radarr', 'running', 7878), $cont('sonarr', 'lscr.io/linuxserver/sonarr', 'running', 8989), $cont('plex', 'plexinc/pms-docker', 'running', 32400, 'healthy'),
  $cont('homepage', 'ghcr.io/gethomepage/homepage:latest', 'exited', 3000), $cont('qbittorrent', 'lscr.io/linuxserver/qbittorrent', 'paused', 8080), $cont('immich', 'ghcr.io/immich-app/immich-server:release', 'running', 2283, 'unhealthy', 3),
  $cont('prowlarr', 'lscr.io/linuxserver/prowlarr', 'running', 9696), $cont('bazarr', 'lscr.io/linuxserver/bazarr', 'running', 6767), $cont('tautulli', 'lscr.io/linuxserver/tautulli', 'running', 8181),
  $cont('sabnzbd', 'lscr.io/linuxserver/sabnzbd', 'exited', 8085), $cont('adguard', 'adguard/adguardhome', 'running', 3001)];
$ds = $state . '/docker-state.json';
$dstate = is_file($ds) ? json_decode(file_get_contents($ds), true) : [];
foreach ($containers as &$c) {
  $n = ltrim($c['Name'], '/'); if (isset($dstate[$n])) $c['State']['Status'] = $dstate[$n];
  if ($c['State']['Status'] === 'running') {   // cgroup v2 files, as on Unraid 7
    $cg = "$fs/sys/fs/cgroup/docker/" . $c['Id']; @mkdir($cg, 0777, true);
    $w = (crc32($n) % 50) / 10 + 0.2;
    file_put_contents("$cg/cpu.stat", 'usage_usec ' . (int)($t * 10000 * $w * 0.2) . "\n");
    file_put_contents("$cg/memory.current", (string)(crc32($n . 'm') % 2000 * 1048576 + 80 * 1048576));
    file_put_contents("$cg/memory.stat", "inactive_file 10485760\n");
  }
} unset($c);
$vs = $state . '/vm-state.json';
$vstate = is_file($vs) ? json_decode(file_get_contents($vs), true) : ['Windows 11' => 'running', 'Home Assistant' => 'shut off', 'Ubuntu Server' => 'paused'];

$GLOBALS['UND_EXEC_MOCK'] = function ($argv) use ($containers, $ds, $dstate, $vs, $vstate) {
  $cmd = basename($argv[0]);
  if ($cmd === 'docker') {
    if ($argv[1] === 'ps') return ['out' => implode("\n", array_map(function ($c) {
      $st = $c['State']['Status']; $h = $c['State']['Health']['Status'] ?? '';
      $status = $st === 'running' ? 'Up ' . (int)((time() - strtotime($c['State']['StartedAt'])) / 3600) . ' hours' . ($h ? " ($h)" : '') : ($st === 'paused' ? 'Up 2 days (Paused)' : 'Exited (' . $c['State']['ExitCode'] . ') 3 hours ago');
      return $c['Id'] . '|' . ltrim($c['Name'], '/') . '|' . $st . '|' . $status . '|' . $c['Config']['Image'];
    }, $containers))];
    if ($argv[1] === 'inspect') { $want = array_slice($argv, 2); return ['out' => json_encode(array_values(array_filter($containers, function ($c) use ($want) { return in_array($c['Id'], $want, true) || in_array(ltrim($c['Name'], '/'), $want, true); })))]; }
    if ($argv[1] === 'stats') { $o = ''; foreach ($containers as $c) if ($c['State']['Status'] === 'running') $o .= json_encode(['Name' => ltrim($c['Name'], '/'), 'CPUPerc' => rand(0, 900) / 100 . '%', 'MemUsage' => rand(80, 2000) . 'MiB / 62.5GiB', 'MemPerc' => '1.2%']) . "\n"; return ['out' => $o]; }
    if ($argv[1] === 'logs') { $o = ''; for ($i = 0; $i < 60; $i++) $o .= gmdate('Y-m-d\TH:i:s', time() - 600 + $i * 10) . '.123456789Z [Info] ' . $argv[5] . ": line $i " . ($i % 7 ? 'everything is fine' : 'RSS sync completed, 3 releases found') . "\n"; return ['out' => $o, 'err' => gmdate('Y-m-d\TH:i:s', time() - 30) . ".5Z [Warn] a warning on stderr\n"]; }
    $map = ['start' => 'running', 'stop' => 'exited', 'restart' => 'running', 'pause' => 'paused', 'unpause' => 'running'];
    if (isset($map[$argv[1]])) { $dstate[$argv[2]] = $map[$argv[1]]; file_put_contents($ds, json_encode($dstate)); return ['out' => $argv[2]]; }
  }
  if ($cmd === 'virsh') {
    if ($argv[1] === 'list') { $o = " Id   Name              State\n--------------------------------------\n"; $i = 1; foreach ($vstate as $n => $st) $o .= sprintf(" %-4s %-17s %s\n", $st === 'running' || $st === 'paused' ? $i++ : '-', $n, $st); return ['out' => $o]; }
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
  $img = function ($t) { return [['coverType' => 'poster', 'remoteUrl' => 'https://picsum.photos/seed/' . rawurlencode($t) . '/200/300']]; };
  $series = function ($t) use ($img) { return ['title' => $t, 'titleSlug' => strtolower(preg_replace('/\W+/', '-', $t)), 'images' => $img($t)]; };
  if (substr($p, -8) === '/graphql') return $j(dev_graphql(json_decode((string)($o['body'] ?? ''), true), $o['headers']['x-api-key'] ?? ''));
  switch (true) {
    case $p === '/api/v3/system/status' || $p === '/api/v1/system/status': return $j(['version' => strpos($p, 'v3') ? '5.14.0.9383' : '1.30.2.4939']);
    case $p === '/api/v3/health' || $p === '/api/v1/health':
      return $j(strpos($url, ':8989') !== false || strpos($url, 'sonarr') !== false ? [['type' => 'warning', 'source' => 'IndexerStatusCheck', 'message' => 'Indexers unavailable due to failures: NZBgeek']] : []);
    case $p === '/api/v3/queue' && isset($q['page']):
      return $j(['totalRecords' => 2, 'records' => strpos($url, 'includeSeries') !== false ? [['title' => 'Bleach.S01E14.1080p', 'trackedDownloadStatus' => 'warning', 'trackedDownloadState' => 'importPending', 'statusMessages' => [['messages' => ['No files found are eligible for import']]]]] : []]);
    case $p === '/api/v3/history' && isset($q['includeMovie']):
      $r = []; foreach (['The Paradise', 'Dastaar', 'Jailer 2', 'Runner', 'Toxic', 'Khalifa: The Ruler', 'Sinners', 'Weapons'] as $i => $t) $r[] = ['movieId' => $i, 'date' => gmdate('c', time() - ($i * 2 + 1) * 86400), 'quality' => ['quality' => ['name' => 'Bluray-1080p']], 'movie' => ['title' => $t, 'year' => 2025, 'titleSlug' => strtolower(preg_replace('/\W+/', '-', $t)), 'images' => $img($t)]];
      return $j(['records' => $r]);
    case $p === '/api/v3/history' && isset($q['includeEpisode']):
      $r = []; foreach (['Bleach', 'Has Fallen', 'A Wild Last Boss Appeared!', 'Black Clover', 'Tokyo Revengers', 'Cupertino', 'Slow Horses'] as $i => $t) $r[] = ['episodeId' => $i, 'date' => gmdate('c', time() - ($i * 9 + 3) * 3600), 'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'series' => $series($t), 'episode' => ['seasonNumber' => 1 + $i % 3, 'episodeNumber' => 3 + $i, 'title' => 'Episode ' . (3 + $i)]];
      return $j(['records' => $r]);
    case $p === '/api/v1/status': return $j(['version' => '2.7.3', 'updateAvailable' => false]);
    case $p === '/api/system/status': return $j(['data' => ['bazarr_version' => '1.5.3']]);
    case $p === '/api/system/health': return $j(['data' => []]);
    case $p === '/api/v2/transfer/info': return $j(['dl_info_speed' => 6815744, 'up_info_speed' => 429600, 'connection_status' => 'connected']);
    case $p === '/api/v2/torrents/stop' || $p === '/api/v2/torrents/start': return ['code' => 200, 'body' => '', 'json' => null, 'err' => '', 'headers' => []];
    case $p === '/api' && ($q['mode'] ?? '') === 'queue' && strpos($url, 'apikey') !== false && strpos($url, 'down.invalid') === false:
      return $j(['queue' => ['paused' => false, 'kbpersec' => '10240', 'timeleft' => '0:12:40', 'noofslots_total' => 3, 'slots' => [['filename' => 'Some.Show.S01E02.1080p.WEB', 'percentage' => '64', 'timeleft' => '0:04:10', 'status' => 'Downloading', 'mb' => '2100'], ['filename' => 'Another.Movie.2024.2160p', 'percentage' => '0', 'timeleft' => '0:08:30', 'status' => 'Queued', 'mb' => '18000']]]]);
    case $p === '/api' && ($q['mode'] ?? '') === 'history': return $j(['history' => ['noofslots' => 1284]]);
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
    case $p === '/api/v2/torrents/info':
      $t = []; for ($i = 0; $i < 1808; $i++) $t[] = ['name' => 'Seed ' . $i, 'state' => $i < 3 ? 'uploading' : 'stalledUP', 'progress' => 1, 'dlspeed' => 0, 'upspeed' => $i < 3 ? 143200 : 0];
      $t[] = ['name' => 'Linux.Distro.ISO.Collection', 'state' => 'downloading', 'progress' => 0.42, 'dlspeed' => 5242880, 'eta' => 1260, 'size' => 9e9];
      $t[] = ['name' => 'Some.Old.Torrent', 'state' => 'stalledDL', 'progress' => 0.87, 'dlspeed' => 0, 'eta' => 8640000, 'size' => 2e9];
      $t[] = ['name' => 'Paused.One', 'state' => 'stoppedDL', 'progress' => 0.1, 'dlspeed' => 0, 'eta' => 8640000, 'size' => 1e9];
      return $j($t);
    case $p === '/api': return $j(['error' => 'API Key Incorrect']);
    case substr($p, -13) === '/Items/Counts': return $j(['MovieCount' => 173, 'SeriesCount' => 151, 'EpisodeCount' => 5570, 'SongCount' => 0]);
    case substr($p, -9) === '/Sessions': return $j([['UserName' => 'Sarah', 'DeviceName' => 'Living Room TV', 'Client' => 'Emby Theater', 'PlayState' => ['PositionTicks' => 31200000000, 'IsPaused' => false, 'PlayMethod' => 'Transcode'],
      'NowPlayingItem' => ['Name' => 'Dune: Part Two', 'ProductionYear' => 2024, 'RunTimeTicks' => 99600000000, 'Width' => 3840], 'TranscodingInfo' => ['Bitrate' => 12000000]], ['UserName' => 'idle', 'DeviceName' => 'Phone']]);
    /* Seerr requests: two pending, a few others; approve / decline change the state */
    case $p === '/api/v1/request' && isset($q['filter']):
      $sf = sys_get_temp_dir() . '/undash-dev/seerr.json'; $st = json_decode((string)@file_get_contents($sf), true) ?: [];
      $all = [[11, 1, 2, 'movie', 693134, 'Alex', 2], [12, 1, 2, 'tv', 1399, 'Marcus', 5], [9, 2, 3, 'movie', 98, 'Sarah', 30], [8, 2, 5, 'movie', 438631, 'Alex', 50], [7, 3, 1, 'tv', 1396, 'Guest', 70]];
      $res = [];
      foreach ($all as $r) { $status = $st[$r[0]] ?? $r[1]; if ($q['filter'] === 'pending' && $status !== 1) continue;
        $res[] = ['id' => $r[0], 'status' => $status, 'type' => $r[3], 'is4k' => $r[0] === 12, 'createdAt' => gmdate('c', time() - $r[6] * 3600), 'requestedBy' => ['displayName' => $r[5]],
                  'media' => ['tmdbId' => $r[4], 'mediaType' => $r[3], 'status' => $status === 2 ? $r[2] : 2], 'seasons' => $r[3] === 'tv' ? [['seasonNumber' => 1], ['seasonNumber' => 2]] : []]; }
      return $j(['pageInfo' => ['results' => count($res)], 'results' => $res]);
    case $p === '/api/v1/search':
      $all = [[693134, 'movie', 'Dune: Part Two', '2024-02-27', 5], [438631, 'movie', 'Dune', '2021-09-15', 5], [841, 'movie', 'Dune', '1984-12-14', 1], [90228, 'tv', 'Dune: Prophecy', '2024-11-17', 3],
              [1399, 'tv', 'Game of Thrones', '2011-04-17', 4], [27205, 'movie', 'Inception', '2010-07-15', 1], [1396, 'tv', 'Breaking Bad', '2008-01-20', 2]];
      $res = [['id' => 9, 'mediaType' => 'person', 'name' => 'Denis Villeneuve']];
      $st = json_decode((string)@file_get_contents(sys_get_temp_dir() . '/undash-dev/seerr-new.json'), true) ?: [];
      foreach ($all as $x) if (stripos($x[2], (string)($q['query'] ?? '')) !== false) $res[] = ['id' => $x[0], 'mediaType' => $x[1], $x[1] === 'tv' ? 'name' : 'title' => $x[2],
        $x[1] === 'tv' ? 'firstAirDate' : 'releaseDate' => $x[3], 'posterPath' => '/dev' . $x[0] . '.jpg', 'overview' => 'A dev search result.', 'mediaInfo' => ['status' => $st[$x[0]] ?? $x[4]]];
      return $j(['page' => 1, 'results' => $res]);
    case $p === '/api/v1/request' && $method === 'POST':
      $b = json_decode((string)($o['body'] ?? ''), true) ?: [];
      $sf = sys_get_temp_dir() . '/undash-dev/seerr-new.json'; $st = json_decode((string)@file_get_contents($sf), true) ?: [];
      if (($st[$b['mediaId'] ?? 0] ?? 1) > 1) return $j(['message' => 'Request already exists'], 409);
      $st[(int)($b['mediaId'] ?? 0)] = 2; @file_put_contents($sf, json_encode($st)); return $j(['id' => 99, 'status' => 1], 201);
    case preg_match('#^/api/v1/request/(\d+)/(approve|decline)$#', $p, $m) === 1 && $method === 'POST':
      $sf = sys_get_temp_dir() . '/undash-dev/seerr.json'; $st = json_decode((string)@file_get_contents($sf), true) ?: [];
      $st[$m[1]] = $m[2] === 'approve' ? 2 : 3; @file_put_contents($sf, json_encode($st)); return $j(['id' => (int)$m[1]]);
    case preg_match('#^/api/v1/(movie|tv)/(\d+)$#', $p, $m) === 1:
      $t = [693134 => ['Dune: Part Two', '2024-02-27'], 1399 => ['Game of Thrones', '2011-04-17'], 98 => ['Gladiator', '2000-05-01'], 438631 => ['Dune', '2021-09-15'], 1396 => ['Breaking Bad', '2008-01-20']][(int)$m[2]] ?? ['Unknown', ''];
      return $j([$m[1] === 'tv' ? 'name' : 'title' => $t[0], $m[1] === 'tv' ? 'firstAirDate' : 'releaseDate' => $t[1], 'posterPath' => '/dev' . $m[2] . '.jpg']);
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
      return $j(array_map(function ($e) use ($series) { $e['series'] = $series($e['series']['title']); $e['title'] = 'Episode ' . $e['episodeNumber']; return $e; }, [['airDateUtc' => $d(1), 'series' => ['title' => 'Cupertino'], 'seasonNumber' => 1, 'episodeNumber' => 4, 'hasFile' => false], ['airDateUtc' => $d(1), 'series' => ['title' => 'MobLand'], 'seasonNumber' => 2, 'episodeNumber' => 1, 'hasFile' => false],
        ['airDateUtc' => $d(1), 'series' => ['title' => 'Tulsa King'], 'seasonNumber' => 3, 'episodeNumber' => 8, 'hasFile' => false], ['airDateUtc' => $d(2), 'series' => ['title' => 'Black Clover'], 'seasonNumber' => 5, 'episodeNumber' => 2, 'hasFile' => false],
        ['airDateUtc' => $d(2), 'series' => ['title' => 'A Wild Last Boss Appeared!'], 'seasonNumber' => 1, 'episodeNumber' => 5, 'hasFile' => true], ['airDateUtc' => $d(4), 'series' => ['title' => 'Dexter: Resurrection'], 'seasonNumber' => 2, 'episodeNumber' => 1, 'hasFile' => false]]));
    case $p === '/api/v3/calendar':
      return $j(array_map(function ($m) use ($img) { $m['images'] = $img($m['title']); $m['year'] = 2026; $m['titleSlug'] = 'x'; return $m; }, [['title' => 'Avatar Aang: The Last Airbender', 'physicalRelease' => $d(4), 'hasFile' => true], ['title' => 'The End of Oak Street', 'physicalRelease' => $d(5), 'hasFile' => true],
        ['title' => 'Ramayana: Rise of a Legend', 'inCinemas' => $d(6), 'hasFile' => false], ['title' => 'How to Rob a Bank', 'inCinemas' => $d(13), 'hasFile' => false], ['title' => 'The Odyssey', 'physicalRelease' => $d(19), 'digitalRelease' => $d(19), 'hasFile' => false]]));
    case $p === '/control/stats': return $j(['num_dns_queries' => 16947, 'num_blocked_filtering' => 2973, 'num_replaced_safebrowsing' => 0, 'avg_processing_time' => 0.045284]);
    case $p === '/api/tokens': return $j(['token' => 'devtoken', 'expires' => gmdate('c', time() + 86400)]);
    case $p === '/api/nginx/proxy-hosts': return $j(array_merge(array_fill(0, 7, ['enabled' => 1]), array_fill(0, 5, ['enabled' => 0])));
    case $p === '/api/server/statistics': return $j(['photos' => 4206, 'videos' => 311, 'usage' => 54 * 1073741824, 'usageByUser' => [[], []]]);
    case strpos($p, '/api/v1/devices/') === 0: return $j(['battery.charge' => 100, 'ups.load' => 9, 'ups.status' => 'OL']);
  }
  return $j(['error' => 'not found'], 404);
};

/* endpoint health: mostly 200, some redirects, one 404, one refused (stable per address) */
$GLOBALS['UND_HEALTH_MOCK'] = function ($targets) {
  $out = [];
  foreach ($targets as $i => $t) {
    $n = crc32($t['hostport']) % 13;
    $code = $n === 0 ? 0 : ($n === 1 ? 404 : ($n < 4 ? 302 : ($n === 4 ? 401 : 200)));
    $out[$i] = ['code' => $code, 'ms' => $code ? 3 + $n * 2 : null, 'err' => $code ? '' : 'Connection refused'];
  }
  usleep(150000);
  return $out;
};

/* a fake Unraid GraphQL API (Docker mode: UNDASH_MODE=api), answering with the shapes of the official schema */
function dev_graphql($req, $key) {
  if ($key !== 'dev-key') return ['errors' => [['message' => 'Invalid API key', 'extensions' => ['code' => 'UNAUTHENTICATED']]], 'data' => null];
  $q = (string)($req['query'] ?? ''); $d = [];
  $vs = sys_get_temp_dir() . '/undash-dev/gql-state.json';
  $st = is_file($vs) ? json_decode(file_get_contents($vs), true) : ['plex' => 'RUNNING', 'radarr' => 'RUNNING', 'homepage' => 'EXITED', 'vm' => 'RUNNING'];
  if (preg_match('/mutation/', $q)) {
    $id = (string)($req['variables']['id'] ?? '');
    if (preg_match('/docker \{ (\w+)\(/', $q, $m)) { $n = str_replace('ct:', '', $id); $st[$n] = ['start' => 'RUNNING', 'stop' => 'EXITED', 'restart' => 'RUNNING', 'pause' => 'PAUSED', 'unpause' => 'RUNNING'][$m[1]] ?? $st[$n]; file_put_contents($vs, json_encode($st)); return ['data' => ['docker' => [$m[1] => ['id' => $id, 'state' => $st[$n]]]]]; }
    if (preg_match('/vm \{ (\w+)\(/', $q, $m)) { $st['vm'] = ['start' => 'RUNNING', 'stop' => 'SHUTOFF', 'forceStop' => 'SHUTOFF', 'reboot' => 'RUNNING', 'pause' => 'PAUSED', 'resume' => 'RUNNING'][$m[1]]; file_put_contents($vs, json_encode($st)); return ['data' => ['vm' => [$m[1] => true]]]; }
  }
  $t = microtime(true);
  if (strpos($q, 'info {') !== false) $d['info'] = ['os' => ['hostname' => 'tower', 'uptime' => gmdate('c', time() - 300000), 'kernel' => '6.18.58-Unraid'], 'cpu' => ['brand' => 'Intel Core i5-14500', 'threads' => 20], 'versions' => ['core' => ['unraid' => '7.3.4', 'api' => '4.37.7']], 'primaryNetwork' => ['name' => 'br0', 'ipAddress' => '192.168.1.10']];
  if (strpos($q, 'metrics {') !== false) $d['metrics'] = ['cpu' => ['percentTotal' => 12 + 6 * sin($t / 5), 'cpus' => array_map(function ($i) use ($t) { return ['percentTotal' => abs(40 * sin($i + $t / 7))]; }, range(0, 19))],
    'memory' => ['total' => 67108864000, 'used' => 30e9, 'available' => 37e9, 'swapTotal' => 0, 'swapUsed' => 0, 'percentTotal' => 45], 'temperature' => ['sensors' => [['type' => 'CPU_PACKAGE', 'current' => ['value' => 41]]], 'summary' => ['hottest' => ['current' => ['value' => 41]]]],
    'network' => [['name' => 'br0', 'rxSec' => 3e6 + 1e6 * sin($t / 4), 'txSec' => 4e5]]];
  if (strpos($q, 'array {') !== false) $d['array'] = ['state' => 'STARTED', 'capacity' => ['kilobytes' => ['free' => '1', 'used' => '1', 'total' => '2']], 'parityCheckStatus' => ['running' => false, 'progress' => 0, 'status' => 'COMPLETED'],
    'parities' => [['name' => 'parity', 'device' => 'sdb', 'status' => 'DISK_OK', 'temp' => 33, 'numErrors' => '0', 'fsSize' => null, 'fsFree' => null, 'color' => 'GREEN_ON', 'isSpinning' => true, 'type' => 'PARITY']],
    'disks' => [['name' => 'disk1', 'device' => 'sdc', 'status' => 'DISK_OK', 'temp' => 36, 'numErrors' => '0', 'fsSize' => '11718885324', 'fsFree' => '2343777064', 'fsType' => 'xfs', 'color' => 'GREEN_ON', 'isSpinning' => true, 'type' => 'DATA']],
    'caches' => [['name' => 'cache', 'device' => 'nvme0n1', 'status' => 'DISK_OK', 'temp' => 44, 'numErrors' => '0', 'fsSize' => '976761560', 'fsFree' => '611329392', 'fsType' => 'btrfs', 'color' => 'GREEN_ON', 'isSpinning' => true, 'type' => 'CACHE']]];
  if (strpos($q, 'vars {') !== false) $d['vars'] = ['name' => 'Tower'];
  if (strpos($q, 'upsDevices') !== false) return ['errors' => [['message' => 'Forbidden resource', 'extensions' => ['code' => 'FORBIDDEN']]], 'data' => null];
  if (strpos($q, 'docker {') !== false) { $c = []; foreach (['plex' => 'plexinc/pms-docker', 'radarr' => 'lscr.io/linuxserver/radarr', 'homepage' => 'ghcr.io/gethomepage/homepage'] as $n => $im) $c[] = ['id' => 'ct:' . $n, 'names' => ['/' . $n], 'image' => $im, 'imageId' => 'sha256:abc', 'created' => time() - 9e6, 'state' => $st[$n], 'status' => $st[$n] === 'RUNNING' ? 'Up 3 days' . ($n === 'plex' ? ' (healthy)' : '') : ($st[$n] === 'PAUSED' ? 'Up 3 days (Paused)' : 'Exited (0) 2 hours ago'), 'autoStart' => true, 'iconUrl' => 'https://cdn.jsdelivr.net/gh/selfhst/icons@main/png/' . $n . '.png', 'webUiUrl' => 'http://192.168.1.10:7878/', 'isUpdateAvailable' => $n === 'radarr', 'isRebuildReady' => false, 'ports' => [['ip' => '0.0.0.0', 'privatePort' => 7878, 'publicPort' => 7878, 'type' => 'TCP']], 'hostConfig' => ['networkMode' => 'bridge'], 'mounts' => [['Source' => '/mnt/user/appdata/' . $n, 'Destination' => '/config', 'RW' => true]], 'networkSettings' => ['Networks' => ['bridge' => ['IPAddress' => '172.17.0.5']]]];
    $d['docker'] = strpos($q, 'logs(') !== false ? ['logs' => ['lines' => [['timestamp' => gmdate('c'), 'message' => 'hello from the API log']]]] : ['containers' => $c]; }
  if (strpos($q, 'vms {') !== false) $d['vms'] = ['domains' => [['id' => 'vm:1', 'name' => 'Windows 11', 'state' => $st['vm']]]];
  return ['data' => $d];
}

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
