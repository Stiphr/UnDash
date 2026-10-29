<?php
/*
 * UnDash - core helpers.
 * Written for PHP 7.4+ : no match, str_contains, nullsafe or named arguments.
 *
 * Test seams (in-process only, never read from the environment or the web):
 *   $GLOBALS['UND_EXEC_MOCK'](array $argv) -> result array or null to run for real
 *   $GLOBALS['UND_FS'] (string)            -> prefix for /proc, /sys, /etc and /var paths (dev harness)
 *   $GLOBALS['UND_SPAWN_MOCK'](array $argv, $log)
 */

if (!defined('UND_LIB_LOADED')) {
define('UND_LIB_LOADED', 1);

define('UND_VERSION', '@@VERSION@@');
define('UND_NAME',    'UnDash');
/*
 * Two ways to run the same code:
 *   plugin  (default) inside Unraid: reads /proc and Unraid's state files, runs docker / virsh / plugin directly.
 *   api     the Docker image (UNDASH_MODE=api): everything about the server comes from the official Unraid GraphQL API
 *           (include/unraid_api.php); nothing on the host is touched directly. Settings live in UNDASH_CONFIG (/config).
 */
if (!defined('UND_MODE')) define('UND_MODE', getenv('UNDASH_MODE') === 'api' ? 'api' : 'plugin');
if (!defined('UND_ROOT')) define('UND_ROOT',    UND_MODE === 'api' ? dirname(__DIR__) : '/usr/local/emhttp/plugins/undash');
if (!defined('UND_DIR')) define('UND_DIR',     UND_MODE === 'api' ? rtrim(getenv('UNDASH_CONFIG') ?: '/config', '/') : '/boot/config/plugins/undash');
if (!defined('UND_TMP')) define('UND_TMP',     '/tmp/undash');
define('UND_DM',      '/usr/local/emhttp/plugins/dynamix.docker.manager');
define('UND_UPDSTATUS', '/var/lib/docker/unraid-update-status.json');
define('UND_KEEP',    '__KEEP__');     // stands in for a stored secret in everything sent to the browser

/** System paths go through here so the dev harness can point them at fixtures. */
function und_path($p) { return isset($GLOBALS['UND_FS']) ? $GLOBALS['UND_FS'] . $p : $p; }
function und_read($p) { $s = @file_get_contents(und_path($p)); return $s === false ? '' : $s; }

function und_has($s, $p)    { return $p === '' || strpos((string)$s, $p) !== false; }
function und_starts($s, $p) { return strncmp((string)$s, $p, strlen($p)) === 0; }

/* ---------- JSON files ---------- */

function und_read_json($file, $default) {
  if (!is_file($file)) return $default;
  $d = json_decode((string)@file_get_contents($file), true);
  return is_array($d) ? $d : $default;
}

/** Atomic write; skipped when nothing changed (the flash drive has limited write cycles). */
function und_write_json($file, $data, $private = false) {
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
  if ($json === false) return false;
  if (is_file($file) && @file_get_contents($file) === $json) return true;
  @mkdir(dirname($file), 0755, true);
  $tmp = $file . '.tmp' . getmypid();
  if (@file_put_contents($tmp, $json) === false) return false;
  if ($private) @chmod($tmp, 0600);
  if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
  return true;
}

function und_tmp($name) { @mkdir(UND_TMP, 0755, true); return UND_TMP . '/' . $name; }

/* ---------- running commands (no shell, argument arrays only) ---------- */

/** Returns ['code'=>int,'out'=>string,'err'=>string,'timeout'=>bool]. Output is capped, the command is killed on timeout. */
function und_run(array $argv, $timeout = 30, $maxBytes = 2097152) {
  if (isset($GLOBALS['UND_EXEC_MOCK']) && is_callable($GLOBALS['UND_EXEC_MOCK'])) {
    $r = call_user_func($GLOBALS['UND_EXEC_MOCK'], $argv);
    if (is_array($r)) return $r + ['code' => 0, 'out' => '', 'err' => '', 'timeout' => false];
  }
  $res = ['code' => -1, 'out' => '', 'err' => '', 'timeout' => false];
  $p = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($p)) { $res['err'] = 'could not start ' . basename((string)$argv[0]); return $res; }
  @fclose($pipes[0]);
  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);
  $deadline = microtime(true) + max(1, $timeout);
  $open = [1 => $pipes[1], 2 => $pipes[2]];
  while ($open) {
    $left = $deadline - microtime(true);
    if ($left <= 0) { $res['timeout'] = true; break; }
    $r = array_values($open); $w = null; $e = null;
    if (@stream_select($r, $w, $e, 0, (int)min(200000, $left * 1000000)) === false) break;
    foreach ($open as $k => $h) {
      $chunk = @fread($h, 65536);
      if ($chunk !== false && $chunk !== '') { $key = $k === 1 ? 'out' : 'err'; if (strlen($res[$key]) < $maxBytes) $res[$key] .= $chunk; }
      if (feof($h)) { @fclose($h); unset($open[$k]); }
    }
  }
  if ($res['timeout']) { @proc_terminate($p, 9); }
  foreach ($open as $h) @fclose($h);
  $code = @proc_close($p);
  $res['code'] = $res['timeout'] ? 124 : $code;
  return $res;
}

function und_bin($name) {
  foreach (['/usr/bin', '/usr/local/bin', '/bin', '/usr/sbin', '/sbin', '/usr/local/sbin'] as $d) {
    if (is_file("$d/$name") && is_executable("$d/$name")) return "$d/$name";
  }
  return $name;
}

/**
 * Start a long command in the background; its output goes to $log. A "<log>.done" file holding the exit code
 * is written when it finishes, so the UI can tell running from finished.
 */
function und_spawn(array $argv, $log) {
  if (isset($GLOBALS['UND_SPAWN_MOCK'])) { call_user_func($GLOBALS['UND_SPAWN_MOCK'], $argv, $log); return true; }
  @unlink($log . '.done');
  @file_put_contents($log, '');
  $cmd = '';
  foreach ($argv as $a) $cmd .= ($cmd === '' ? '' : ' ') . escapeshellarg($a);
  $sh = $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1; echo $? > ' . escapeshellarg($log . '.done');
  exec('nohup setsid /bin/bash -c ' . escapeshellarg($sh) . ' > /dev/null 2>&1 &');
  return true;
}

/** State of a background job started with und_spawn: idle | running | done (with exit code and output tail). */
function und_job($log, $tail = 16000) {
  clearstatcache();
  if (!is_file($log)) return ['state' => 'idle', 'code' => null, 'out' => ''];
  $out = (string)@file_get_contents($log);
  if (strlen($out) > $tail) $out = substr($out, -$tail);
  $out = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $out);
  $done = is_file($log . '.done');
  return ['state' => $done ? 'done' : 'running', 'code' => $done ? (int)trim((string)@file_get_contents($log . '.done')) : null, 'out' => und_redact($out), 'at' => (int)@filemtime($log)];
}

/* ---------- text ---------- */

/** Remove anything that looks like a secret from text that is shown. */
function und_redact($s, array $extra = []) {
  $s = (string)$s;
  foreach ($extra as $x) if (is_string($x) && strlen($x) >= 6) $s = str_replace($x, '[redacted]', $s);
  $s = preg_replace('#(https?://)[^/\s:@]+:[^/\s@]+@#i', '$1[redacted]@', $s);
  $s = preg_replace('/([?&](?:apikey|api_key|X-Plex-Token|token|key)=)[^&\s"]+/i', '$1[redacted]', $s);
  return $s;
}

function und_clip($s, $max) {
  $s = (string)$s;
  if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max - 1, 'UTF-8') . '…' : $s;
  return strlen($s) > $max ? substr($s, 0, $max - 3) . '...' : $s;
}

function und_valid_name($n) { return is_string($n) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_. +()\-]{0,127}$/', $n) === 1; }

/* ---------- Unraid ---------- */

function und_var_ini() { $v = @parse_ini_string(und_read('/var/local/emhttp/var.ini')); return is_array($v) ? $v : []; }

function und_api_mode() { return UND_MODE === 'api'; }

/** What this deployment can do; the UI hides what is not available instead of failing. */
function und_caps() {
  $p = !und_api_mode();
  return ['power' => $p, 'os_update' => $p, 'docker_check' => $p, 'docker_stats' => $p, 'docker_inspect' => true, 'vm_hibernate' => $p, 'vm_details' => $p,
          'vnc' => $p, 'storage_detail' => true, 'mode' => UND_MODE];
}

function und_os_version() {
  $v = @parse_ini_string(und_read('/etc/unraid-version'));
  return is_array($v) && !empty($v['version']) ? (string)$v['version'] : '';
}

function und_server_ip() {
  $n = @parse_ini_string(und_read('/var/local/emhttp/network.ini'), true);
  if (is_array($n)) foreach ($n as $sec) if (is_array($sec) && !empty($sec['IPADDR:0'])) return (string)$sec['IPADDR:0'];
  $ip = $_SERVER['SERVER_ADDR'] ?? '';
  return $ip !== '' ? $ip : '127.0.0.1';
}

$__ident = @parse_ini_string(und_read('/boot/config/ident.cfg'));
if (is_array($__ident) && !empty($__ident['timezone'])) @date_default_timezone_set($__ident['timezone']);
unset($__ident);

} // UND_LIB_LOADED

foreach (['config', 'transfer', 'system', 'docker', 'vm', 'services', 'import', 'ops', 'unraid_api', 'media', 'arr', 'downloads', 'ignores'] as $__m) {
  $__f = __DIR__ . '/' . $__m . '.php';
  if (is_file($__f)) require_once $__f;
}
unset($__m, $__f);
