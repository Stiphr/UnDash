<?php
/* A stand-in for TrueNAS for tests/truenas_test.php: JSON-RPC 2.0 over a TLS WebSocket at /api/current, answering with the shapes of the
 * API reference (api.truenas.com/v25.10). php tests/fake_truenas.php PORT LOGFILE. Every method called is written to LOGFILE. */
$port = (int)$argv[1]; $logf = $argv[2];
$pk = openssl_pkey_new(['private_key_bits' => 2048]);
$cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'truenas.test'], $pk), null, $pk, 2);
openssl_x509_export($cert, $crt); openssl_pkey_export($pk, $key);
$pem = tempnam(sys_get_temp_dir(), 'tn'); file_put_contents($pem, $crt . $key);
$srv = stream_socket_server("ssl://127.0.0.1:$port", $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['ssl' => ['local_cert' => $pem, 'allow_self_signed' => true, 'verify_peer' => false]]));
if (!$srv) { fwrite(STDERR, "$es\n"); exit(1); }

function frame($s, $text, $op = 1, $fin = true) {
  $n = strlen($text);
  fwrite($s, chr(($fin ? 0x80 : 0) | $op) . ($n < 126 ? chr($n) : ($n < 65536 ? chr(126) . pack('n', $n) : chr(127) . pack('J', $n))) . $text);
}
function bytes($s, $n) { $d = ''; while (strlen($d) < $n) { $p = fread($s, $n - strlen($d)); if ($p === false || ($p === '' && feof($s))) return null; $d .= $p; } return $d; }
function msg($s) {
  $h = bytes($s, 2); if ($h === null) return null;
  $op = ord($h[0]) & 15; $n = ord($h[1]) & 127;
  if ($n === 126) $n = unpack('n', bytes($s, 2))[1]; elseif ($n === 127) $n = unpack('J', bytes($s, 8))[1];
  $mask = bytes($s, 4); $d = $n ? bytes($s, $n) : ''; $o = '';
  for ($i = 0; $i < $n; $i++) $o .= $d[$i] ^ $mask[$i % 4];
  return [$op, $o];
}
function reply($s, $id, $res) { frame($s, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $res])); }
function fail($s, $id, $reason, $errname = 'EINVAL') { frame($s, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32001, 'message' => 'Method call error', 'data' => ['error' => 22, 'errname' => $errname, 'reason' => $reason]]])); }
function event($s, $coll, $fields) { frame($s, json_encode(['jsonrpc' => '2.0', 'method' => 'collection_update', 'params' => ['msg' => 'added', 'collection' => $coll, 'id' => 1, 'fields' => $fields]])); }

$apps = [
  ['name' => 'plex', 'id' => 'plex', 'state' => 'RUNNING', 'upgrade_available' => true, 'image_updates_available' => false, 'human_version' => '1.41_1.2', 'version' => '1.2', 'metadata' => ['icon' => 'https://icons.test/plex.png'], 'portals' => ['Web UI' => 'http://192.168.1.20:32400/web'],
   'active_workloads' => ['containers' => 1, 'images' => ['plexinc/pms-docker:latest'], 'used_ports' => [['container_port' => 32400, 'protocol' => 'tcp', 'host_ports' => [['host_port' => 32400, 'host_ip' => '0.0.0.0']]]],
     'container_details' => [['id' => 'c1', 'service_name' => 'plex', 'image' => 'plexinc/pms-docker:latest', 'state' => 'running']], 'volumes' => [['source' => '/mnt/tank/plex', 'destination' => '/config', 'mode' => 'rw', 'type' => 'bind']], 'networks' => [['Name' => 'ix-plex', 'Id' => 'n1', 'Labels' => []]]]],
  ['name' => 'sonarr', 'id' => 'sonarr', 'state' => 'STOPPED', 'upgrade_available' => false, 'image_updates_available' => true, 'human_version' => '4.0_1.1', 'version' => '1.1', 'metadata' => [], 'portals' => [], 'active_workloads' => ['containers' => 0, 'images' => [], 'used_ports' => [], 'container_details' => [], 'volumes' => [], 'networks' => []]],
  ['name' => 'broken', 'id' => 'broken', 'state' => 'CRASHED', 'upgrade_available' => false, 'image_updates_available' => false, 'human_version' => '1_1', 'version' => '1', 'metadata' => [], 'portals' => [], 'active_workloads' => ['containers' => 0, 'images' => [], 'used_ports' => [], 'container_details' => [], 'volumes' => [], 'networks' => []]],
];
$big = $apps; $big[0]['notes'] = str_repeat('x', 200000);      // far larger than one frame needs to be
$pools = [
  ['id' => 1, 'name' => 'tank', 'status' => 'ONLINE', 'healthy' => true, 'warning' => false, 'size' => 4000000000000, 'allocated' => 1000000000000, 'free' => 3000000000000, 'scan' => ['function' => 'SCRUB', 'state' => 'SCANNING', 'percentage' => 41.26, 'pause' => null, 'errors' => 0]],
  ['id' => 2, 'name' => 'fast', 'status' => 'DEGRADED', 'healthy' => false, 'warning' => false, 'size' => 1000000000000, 'allocated' => 500000000000, 'free' => 500000000000, 'scan' => ['function' => 'SCRUB', 'state' => 'FINISHED', 'percentage' => 100.0]],
];

while (true) {
  $c = @stream_socket_accept($srv, 3600);
  if (!$c) continue;
  $head = ''; while (($l = fgets($c)) !== false && $l !== "\r\n") $head .= $l;
  if (strpos($head, 'GET /api/current ') !== 0 || !preg_match('/Sec-WebSocket-Key: (\S+)/i', $head, $m)) { fwrite($c, "HTTP/1.1 404 Not Found\r\n\r\n"); fclose($c); continue; }
  fwrite($c, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . base64_encode(sha1($m[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)) . "\r\n\r\n");
  frame($c, 'hello', 9);
  $in = false;
  while (($f = msg($c)) !== null) {
    if ($f[0] === 8) break;
    if ($f[0] !== 1) { if ($f[0] === 10) file_put_contents($logf, "pong\n", FILE_APPEND); continue; }
    $j = json_decode($f[1], true); $id = $j['id']; $mth = (string)$j['method']; $p = $j['params'];
    file_put_contents($logf, $mth . ' ' . ($mth === 'auth.login_ex' ? $p[0]['mechanism'] . ' ' . $p[0]['username'] : json_encode($p)) . "\n", FILE_APPEND);
    if ($mth === 'auth.login_ex') {
      $in = $p[0]['mechanism'] === 'API_KEY_PLAIN' && $p[0]['api_key'] === 'tn-key-1' && $p[0]['username'] === 'dash';
      reply($c, $id, ['response_type' => $in ? 'SUCCESS' : 'AUTH_ERR']);
      continue;
    }
    if (!$in) { fail($c, $id, 'Not authenticated', 'EACCES'); continue; }
    switch ($mth) {
      case 'system.info': reply($c, $id, ['version' => 'TrueNAS-SCALE-25.10.1', 'hostname' => 'nas', 'model' => 'AMD EPYC 7302', 'cores' => 16, 'physical_cores' => 8, 'physmem' => 68719476736, 'uptime_seconds' => 90061.5]); break;
      case 'pool.query': reply($c, $id, $pools); break;
      case 'disk.query': reply($c, $id, [['name' => 'sda', 'devname' => 'sda', 'model' => 'WD Red', 'size' => 4000000000000, 'pool' => 'tank', 'type' => 'HDD'], ['name' => 'sdb', 'devname' => 'sdb', 'model' => 'WD Red', 'size' => 4000000000000, 'pool' => null, 'type' => 'HDD']]); break;
      case 'disk.temperatures': reply($c, $id, ['sda' => 52, 'sdb' => null]); break;
      case 'alert.list': reply($c, $id, [['uuid' => 'aaaaaaaaaaaaaaaa', 'level' => 'CRITICAL', 'klass' => 'ZpoolDegraded', 'formatted' => 'Pool <b>fast</b> is DEGRADED &amp; needs help', 'dismissed' => false], ['uuid' => 'bbbb', 'level' => 'WARNING', 'klass' => 'X', 'formatted' => 'old news', 'dismissed' => true]]); break;
      case 'app.query':
        // a large answer comes in pieces, with a report in the middle of them
        $t = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $big]); $a = substr($t, 0, 70000);
        frame($c, $a, 1, false); frame($c, 'mid', 9); frame($c, substr($t, 70000), 0, true);
        break;
      case 'vm.query': reply($c, $id, [['id' => 3, 'name' => 'Win 11', 'vcpus' => 2, 'memory' => 4096, 'autostart' => true, 'status' => ['state' => 'RUNNING', 'pid' => 100, 'domain_state' => 'RUNNING']], ['id' => 4, 'name' => 'Off', 'vcpus' => 1, 'memory' => 2048, 'autostart' => false, 'status' => ['state' => 'STOPPED', 'pid' => null, 'domain_state' => 'SHUTOFF']]]); break;
      case 'core.subscribe':
        reply($c, $id, 'sub-' . $id);
        if (strpos($p[0], 'reporting.realtime') === 0) event($c, 'reporting.realtime', ['cpu' => ['cpu' => ['usage' => 12.34, 'temp' => 48.0], 'cpu0' => ['usage' => 10.0, 'temp' => null], 'cpu1' => ['usage' => 20.0, 'temp' => null]],
          'memory' => ['arc_size' => 1, 'arc_free_memory' => 1, 'arc_available_memory' => 1, 'physical_memory_total' => 1000, 'physical_memory_available' => 250],
          'interfaces' => ['enp1s0' => ['link_state' => 'LINK_STATE_UP', 'speed' => 1000, 'received_bytes_rate' => 111.0, 'sent_bytes_rate' => 222.0, 'received_bytes' => 9000, 'sent_bytes' => 9000], 'eno2' => ['link_state' => 'LINK_STATE_DOWN', 'speed' => 0, 'received_bytes_rate' => 0, 'sent_bytes_rate' => 0, 'received_bytes' => 99999999, 'sent_bytes' => 0], 'br0' => ['link_state' => 'LINK_STATE_UP', 'speed' => 1000, 'received_bytes_rate' => 1.0, 'sent_bytes_rate' => 1.0, 'received_bytes' => 5, 'sent_bytes' => 5]],
          'disks' => ['busy' => 0, 'read_bytes' => 0, 'write_bytes' => 0, 'read_ops' => 0, 'write_ops' => 0], 'zfs' => ['demand_accesses_per_second' => 1], 'pools' => []]);
        elseif (strpos($p[0], 'app.stats') === 0) event($c, 'app.stats', [['app_name' => 'plex', 'cpu_usage' => 7, 'memory' => 2048, 'networks' => [], 'blkio' => ['read' => 0, 'write' => 0]]]);
        elseif (strpos($p[0], 'app.container_log_follow') === 0) { event($c, 'app.container_log_follow', ['data' => "line one\n", 'timestamp' => '2026-11-02T10:00:00.000Z']); event($c, 'app.container_log_follow', ['data' => "\x1b[31mline two\x1b[0m\n", 'timestamp' => null]); }
        break;
      case 'core.unsubscribe': reply($c, $id, null); break;
      case 'core.get_jobs': reply($c, $id, [['id' => $p[0][0][2], 'state' => $p[0][0][2] === 77 ? 'FAILED' : 'SUCCESS', 'result' => null, 'error' => 'it broke']]); break;
      case 'app.start': if ($p[0] === 'refuse') { fail($c, $id, 'Not authorized', 'EACCES'); break; } reply($c, $id, $p[0] === 'sonarr' ? 12 : null); break;
      case 'app.stop': $p[0] === 'plex' ? reply($c, $id, null) : fail($c, $id, 'it cannot stop'); break;
      case 'app.upgrade': case 'app.pull_images': case 'vm.start': case 'vm.stop': case 'vm.poweroff': case 'vm.restart': case 'vm.suspend': case 'vm.resume': reply($c, $id, null); break;
      default: fail($c, $id, 'Method does not exist', 'ENOMETHOD');
    }
  }
  fclose($c);
}
