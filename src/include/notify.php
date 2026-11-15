<?php
/*
 * UnDash - push alerts: the background check (scripts/alertcheck.php) tells Discord, Telegram, ntfy or Gotify when a
 * problem starts and ends, so nobody has to keep the dashboard open.
 *
 * Addresses and tokens are in .env, never in config.yaml or the browser. config.yaml holds only `notify` (which alerts,
 * and whether to say when a problem ends). Ignored alerts are not sent. Nothing is sent to anyone until a channel is set up.
 */

/** channel => [name, [field => [.env name, label, secret?, required?]]] */
const UND_NOTIFY_CHANNELS = [
  'discord'  => ['Discord', ['url' => ['NOTIFY_DISCORD_URL', 'Webhook address', true, true]]],
  'telegram' => ['Telegram', ['token' => ['NOTIFY_TELEGRAM_TOKEN', 'Bot token', true, true], 'chat' => ['NOTIFY_TELEGRAM_CHAT', 'Chat ID', false, true]]],
  'ntfy'     => ['ntfy', ['url' => ['NOTIFY_NTFY_URL', 'Topic address', true, true], 'token' => ['NOTIFY_NTFY_TOKEN', 'Access token (optional)', true, false]]],
  'gotify'   => ['Gotify', ['url' => ['NOTIFY_GOTIFY_URL', 'Server address', false, true], 'token' => ['NOTIFY_GOTIFY_TOKEN', 'App token', true, true]]],
];
const UND_NOTIFY_LEVELS = ['info' => 0, 'warn' => 1, 'bad' => 2];
const UND_NOTIFY_HOLD = 50;          // seconds a problem must last: it has to show in two checks in a row
const UND_NOTIFY_QUIET = 1800;       // the same problem is not sent again for this long, so a flapping container stays one message
const UND_NOTIFY_LINES = 10;

function und_notify_file() { return und_data_dir() . '/notify-state.json'; }

function und_notify_prefs() {
  $p = (array)(und_doc()['notify'] ?? []);
  return ['min' => isset(UND_NOTIFY_LEVELS[$p['min'] ?? '']) ? $p['min'] : 'warn', 'resolved' => !array_key_exists('resolved', $p) || !empty($p['resolved'])];
}

/** The channels that are set up, with their values. Server side only. */
function und_notify_conf() {
  $env = und_env(); $out = [];
  foreach (UND_NOTIFY_CHANNELS as $id => $c) {
    $v = []; $ok = true;
    foreach ($c[1] as $f => $x) { $v[$f] = (string)($env[$x[0]] ?? ''); if ($x[3] && $v[$f] === '') $ok = false; }
    if ($ok) $out[$id] = $v;
  }
  return $out;
}

/** For the page: what is set up, and how the last send went. Secret values never leave the server. */
function und_notify_view() {
  $on = und_notify_conf(); $s = und_read_json(und_notify_file(), []); $channels = [];
  foreach (UND_NOTIFY_CHANNELS as $id => $c) {
    $fields = [];
    foreach ($c[1] as $f => $x) $fields[] = ['id' => $f, 'label' => $x[1], 'secret' => $x[2], 'required' => $x[3], 'set' => isset($on[$id]) && $on[$id][$f] !== '', 'value' => !$x[2] && isset($on[$id]) ? $on[$id][$f] : ''];
    $channels[] = ['id' => $id, 'name' => $c[0], 'on' => isset($on[$id]), 'fields' => $fields];
  }
  return ['channels' => $channels, 'prefs' => und_notify_prefs(), 'last' => ['at' => (int)($s['at'] ?? 0), 'error' => (string)($s['error'] ?? '')]];
}

/** Stores one channel. An empty secret keeps the stored one. */
function und_notify_set($id, array $vals) {
  if (!isset(UND_NOTIFY_CHANNELS[$id])) return ['error' => 'Unknown channel.'];
  $env = und_env(); $new = $env;
  foreach (UND_NOTIFY_CHANNELS[$id][1] as $f => $x) {
    $v = trim((string)($vals[$f] ?? ''));
    if ($v === '' && $x[2]) $v = (string)($env[$x[0]] ?? '');
    if ($v === '' && $x[3]) return ['error' => $x[1] . ' is needed.'];
    if ($v !== '') $new[$x[0]] = $v;
  }
  $bad = und_notify_check($id, $new);
  if ($bad !== '') return ['error' => $bad];
  return und_env_write($new) ? ['ok' => true] : ['error' => 'The channel could not be saved. Check that the configuration folder is writable.'];
}
function und_notify_check($id, array $env) {
  $get = function ($f) use ($id, $env) { return (string)($env[UND_NOTIFY_CHANNELS[$id][1][$f][0]] ?? ''); };
  if ($id === 'discord' && !preg_match('#^https://(discord|discordapp|ptb\.discord|canary\.discord)\.com/api/webhooks/\d+/[\w-]+#', $get('url'))) return 'That is not a Discord webhook address. Copy it from the channel\'s Integrations > Webhooks.';
  if ($id === 'telegram' && !preg_match('/^\d+:[\w-]{20,}$/', $get('token'))) return 'That is not a Telegram bot token. BotFather gives it to you, in the form 123456:ABC-DEF…';
  if ($id === 'telegram' && !preg_match('/^(-?\d+|@\w{4,})$/', $get('chat'))) return 'The chat ID is a number, or @name for a public channel.';
  if (in_array($id, ['ntfy', 'gotify'], true) && !preg_match('#^https?://[^\s/]+#i', $get('url'))) return 'The address must start with http:// or https://.';
  if ($id === 'ntfy' && !preg_match('#^https?://[^\s/]+/[^\s/]+/?$#i', $get('url'))) return 'Use the whole topic address, for example https://ntfy.sh/my-server-alerts.';
  return '';
}
function und_notify_off($id) {
  if (!isset(UND_NOTIFY_CHANNELS[$id])) return ['error' => 'Unknown channel.'];
  $env = und_env();
  foreach (UND_NOTIFY_CHANNELS[$id][1] as $x) unset($env[$x[0]]);
  return und_env_write($env) ? ['ok' => true] : ['error' => 'The channel could not be removed.'];
}
function und_notify_save_prefs($min, $resolved) {
  if (!isset(UND_NOTIFY_LEVELS[$min])) return ['error' => 'Unknown level.'];
  return und_doc_write(['notify' => ['min' => $min, 'resolved' => (bool)$resolved]]) ? ['ok' => true] : ['error' => 'The setting could not be saved.'];
}

/** The HTTP request for one message: [url, options]. $bad = a problem started (rather than ended). */
function und_notify_request($id, array $c, $title, $text, $bad) {
  $ascii = trim(preg_replace('/[^\x20-\x7E]/', '', $title));      // ntfy's Title header
  $json = ['Content-Type' => 'application/json'];
  if ($id === 'discord') return [$c['url'], ['headers' => $json, 'verify' => true, 'body' => json_encode(['username' => 'UnDash', 'content' => und_clip('**' . $title . "**\n" . $text, 1900)])]];
  if ($id === 'telegram') return ['https://api.telegram.org/bot' . $c['token'] . '/sendMessage', ['headers' => $json, 'verify' => true, 'body' => json_encode(['chat_id' => $c['chat'], 'text' => und_clip($title . "\n" . $text, 3900), 'disable_web_page_preview' => true])]];
  if ($id === 'ntfy') return [rtrim($c['url'], '/'), ['headers' => ['Title' => $ascii, 'Priority' => $bad ? 'high' : 'default', 'Tags' => $bad ? 'warning' : 'white_check_mark'] + ($c['token'] !== '' ? ['Authorization' => 'Bearer ' . $c['token']] : []), 'body' => $text]];
  return [rtrim($c['url'], '/') . '/message', ['headers' => $json + ['X-Gotify-Key' => $c['token']], 'body' => json_encode(['title' => $title, 'message' => $text, 'priority' => $bad ? 8 : 3])]];
}

/** Sends to one channel. Returns '' or a readable error that never holds an address or token. */
function und_notify_send($id, array $c, $title, $text, $bad) {
  list($url, $o) = und_notify_request($id, $c, $title, $text, $bad);
  $r = und_http('POST', $url, $o + ['timeout' => 8]);
  $name = UND_NOTIFY_CHANNELS[$id][0];
  if ($r['err'] !== '') return $name . ': ' . und_redact(str_replace(array_filter(array_values($c)), '***', $r['err']));
  if ($r['code'] < 200 || $r['code'] >= 300) return $name . ' answered HTTP ' . $r['code'] . ($r['code'] === 401 || $r['code'] === 403 || $r['code'] === 404 ? '. Check the address and token.' : '.');
  return '';
}

function und_notify_all(array $conf, $title, $text, $bad) {
  $errs = [];
  foreach ($conf as $id => $c) { $e = und_notify_send($id, $c, $title, $text, $bad); if ($e !== '') $errs[] = $e; }
  return $errs;
}

/** One message from several alerts, so a bad minute is one notification and not twenty. */
function und_notify_text(array $lines) {
  $more = count($lines) - UND_NOTIFY_LINES;
  $show = array_map(function ($m) { return und_clip($m, 240); }, array_slice($lines, 0, UND_NOTIFY_LINES));
  return implode("\n", $show) . ($more > 0 ? "\n…and " . $more . ' more.' : '');
}

/**
 * Compares this check's alerts with what was already sent. A problem is sent once it has lasted two checks and ends the
 * same way; an alert of a part this check could not read ($scopes, as in und_alerts_track) stays open. A send that
 * fails on every channel is tried again at the next check.
 */
function und_notify_run(array $alerts, array $scopes, array $conf, array $prefs, array $rules, $server, $now = null) {
  $now = $now ?? time();
  $file = und_notify_file();
  $s = und_read_json($file, []);
  $open = (array)($s['open'] ?? []); $last = (array)($s['last'] ?? []); $before = json_encode([$open, $last]);
  $hidden = [];
  foreach ($rules as $r) $hidden[$r['key']] = 1;
  $floor = UND_NOTIFY_LEVELS[$prefs['min']];
  $now_on = []; $start = [];
  foreach ($alerts as $a) {
    $k = $a['cat'] . ':' . $a['res'];
    if (isset($hidden[$k]) || isset($hidden[$a['cat']]) || (UND_NOTIFY_LEVELS[$a['lv']] ?? 0) < $floor) { unset($open[$k]); continue; }   // ignored now: never reported as ended
    $now_on[$k] = 1;
    if (!isset($open[$k])) $open[$k] = ['cat' => $a['cat'], 'msg' => und_clip($a['msg'], 240), 'first' => $now, 'sent' => false];
    if (!$open[$k]['sent'] && $now - $open[$k]['first'] >= UND_NOTIFY_HOLD && $now - (int)($last[$k] ?? 0) >= UND_NOTIFY_QUIET) $start[] = $k;
  }
  $ended = [];
  foreach ($open as $k => $a) {
    if (isset($now_on[$k])) continue;
    $scope = strpos((string)$a['cat'], 'docker.') === 0 ? 'docker' : ($a['cat'] === 'disk.smart' ? 'smart' : 'system');
    if (!in_array($scope, $scopes, true)) continue;
    if ($a['sent'] && $prefs['resolved']) $ended[] = $k; else unset($open[$k]);
  }
  $err = (string)($s['error'] ?? ''); $at = (int)($s['at'] ?? 0); $name = $server !== '' ? $server : 'UnDash';
  $send = function ($keys, $title, $bad) use (&$open, $conf, &$err, &$at, $now) {
    if (!$keys) return false;
    $errs = und_notify_all($conf, $title, und_notify_text(array_map(function ($k) use ($open) { return $open[$k]['msg']; }, $keys)), $bad);
    $err = implode(' ', $errs);
    if (count($errs) === count($conf)) return false;
    $at = $now;
    return true;
  };
  if ($send($start, $name . ': ' . (count($start) > 1 ? count($start) . ' problems' : 'problem'), true)) foreach ($start as $k) { $open[$k]['sent'] = true; $last[$k] = $now; }
  if ($send($ended, $name . ': ' . (count($ended) > 1 ? count($ended) . ' problems ended' : 'problem ended'), false)) foreach ($ended as $k) unset($open[$k]);
  $last = array_filter($last, function ($t) use ($now) { return $now - $t < UND_NOTIFY_QUIET; });
  if (json_encode([$open, $last]) !== $before || $err !== (string)($s['error'] ?? '')) und_write_json($file, ['open' => (object)$open, 'last' => (object)$last, 'at' => $at, 'error' => $err], true);
  return ['started' => count($start), 'ended' => count($ended), 'error' => $err];
}
