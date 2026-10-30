<?php
/*
 * UnDash - where settings live. Two files in the configuration folder (mirrored to the flash drive):
 *
 *   config.yaml  everything you set up: layout, apps, theme, ignored alerts, custom CSS, the Unraid API address.
 *                It never holds a secret: an app's key, user name or password is a reference such as {{RADARR_API_KEY}}.
 *   .env         the values behind those references, plus the Unraid API key and the Docker version's password hash.
 *
 * Alert history and backups are data, not settings: they go in data/ (not mirrored).
 * Older versions kept config.json, secrets.json, custom.css and ignores.json; they are converted on first use (see
 * und_store_migrate) and moved to data/legacy/.
 */

const UND_ENV_RESERVED = ['UNRAID_API_KEY', 'UNDASH_PASSWORD_HASH', 'UNDASH_AUTH'];
const UND_CRED_FIELDS = ['key' => 'API_KEY', 'username' => 'USERNAME', 'password' => 'PASSWORD'];

function und_data_dir() { $d = und_store_dir() . '/data'; @mkdir($d, 0700, true); return $d; }

/* ---------- YAML: a small writer and a reader for what it writes (plus ordinary hand edits) ---------- */

function und_yaml_emit($v, $indent = 0) {
  $pad = str_repeat('  ', $indent);
  if (!is_array($v) && !is_object($v)) return und_yaml_scalar_out($v);
  $a = (array)$v;
  $list = $a === [] ? is_array($v) && !is_object($v) : array_keys($a) === range(0, count($a) - 1);
  if ($a === []) return $list ? '[]' : '{}';
  $out = '';
  foreach ($a as $k => $x) {
    $key = $list ? '-' : und_yaml_key_out((string)$k) . ':';
    if ((is_array($x) || is_object($x)) && (array)$x !== []) {
      $inner = und_yaml_emit($x, $indent + 1);
      if ($list && !und_yaml_is_list($x)) {
        // "- key: value" with the following keys aligned under the first
        $lines = explode("\n", rtrim($inner, "\n"));
        $out .= $pad . '- ' . ltrim($lines[0]) . "\n";
        foreach (array_slice($lines, 1) as $l) $out .= $l . "\n";
      } else $out .= $pad . $key . "\n" . $inner;
    } elseif (is_string($x) && strpos($x, "\n") !== false) {
      $out .= $pad . $key . " |-\n";
      foreach (explode("\n", $x) as $l) $out .= ($l === '' ? '' : $pad . '  ' . $l) . "\n";
    } else $out .= $pad . $key . ' ' . und_yaml_emit($x) . "\n";
  }
  return $out;
}
function und_yaml_is_list($x) { $a = (array)$x; return !is_object($x) && ($a === [] || array_keys($a) === range(0, count($a) - 1)); }
function und_yaml_key_out($k) { return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_. -]*$/', $k) && substr($k, -1) !== ' ' ? $k : und_yaml_quote($k); }
function und_yaml_quote($s) { return '"' . strtr($s, ['\\' => '\\\\', '"' => '\\"', "\t" => '\\t', "\r" => '\\r', "\n" => '\\n']) . '"'; }
function und_yaml_scalar_out($v) {
  if ($v === null) return 'null';
  if ($v === true) return 'true';
  if ($v === false) return 'false';
  if (is_int($v)) return (string)$v;
  if (is_float($v)) return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') ?: '0';
  $s = (string)$v;
  // plain only when it reads back as the same string
  $plain = $s !== '' && preg_match('/^[A-Za-z_\/.][A-Za-z0-9 _\/.:@()+,-]*$/', $s) && substr($s, -1) !== ' ' && strpos($s, ': ') === false && strpos($s, ' #') === false
    && !in_array(strtolower($s), ['true', 'false', 'yes', 'no', 'on', 'off', 'null', '~'], true);
  return $plain ? $s : und_yaml_quote($s);
}

/** Reads YAML: block maps and lists, quoted and plain scalars, [] and {} (also short flow lists), | and |- blocks, comments. */
function und_yaml_read($text) {
  $raw = preg_split('/\r\n|\r|\n/', (string)$text);
  $lines = [];
  for ($n = 0; $n < count($raw); $n++) {
    $r = str_replace("\t", '    ', $raw[$n]);
    $l = und_yaml_strip_comment($r);
    if (trim($l) === '' || trim($l) === '---') continue;
    $ind = strlen($l) - strlen(ltrim($l, ' ')); $body = rtrim(ltrim($l, ' '));
    // a block scalar: "key: |" or "key: |-" takes the following more-indented lines verbatim
    if (preg_match('/^(.*?:)\s*\|([-+]?)$/', $body, $m) || preg_match('/^(-)\s*\|([-+]?)$/', $body, $m)) {
      $blk = []; $min = null;
      while ($n + 1 < count($raw)) {
        $nx = str_replace("\t", '    ', $raw[$n + 1]);
        $ni = strlen($nx) - strlen(ltrim($nx, ' '));
        if (trim($nx) !== '' && $ni <= $ind) break;
        $n++; $blk[] = $nx;
        if (trim($nx) !== '') $min = $min === null ? $ni : min($min, $ni);
      }
      $txt = implode("\n", array_map(function ($b) use ($min) { return (string)substr($b, (int)$min); }, $blk));
      $txt = $m[2] === '-' ? rtrim($txt, "\n") : rtrim($txt, "\n") . "\n";
      $lines[] = [$ind, $m[1] . ' ' . und_yaml_quote($txt)];
      continue;
    }
    $lines[] = [$ind, $body];
  }
  $i = 0;
  $out = $lines ? und_yaml_block($lines, $i, $lines[0][0]) : [];
  return und_yaml_fix($out);
}
/** {} written for an empty map comes back from the shared parser as the string "{}". */
function und_yaml_fix($v) {
  if ($v === '{}') return [];
  if (is_array($v)) foreach ($v as $k => $x) $v[$k] = und_yaml_fix($x);
  return $v;
}

/* ---------- .env ---------- */

function und_env_text(array $env) {
  $out = "# UnDash secrets: API keys, user names and passwords. Keep this file private.\n# config.yaml refers to these by name, e.g. {{RADARR_API_KEY}}.\n";
  ksort($env);
  foreach ($env as $k => $v) {
    $v = (string)$v;
    $out .= $k . '=' . ($v === '' || preg_match('/^[A-Za-z0-9_.\/:@+,-]*$/', $v) ? $v : '"' . strtr($v, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r']) . '"') . "\n";
  }
  return $out;
}
function und_env_read($text) {
  $out = [];
  foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $l) {
    if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $l, $m)) continue;
    $v = trim($m[2]);
    if ($v !== '' && $v[0] === '"' && substr($v, -1) === '"' && strlen($v) > 1) $v = strtr(substr($v, 1, -1), ['\\n' => "\n", '\\r' => "\r", '\\"' => '"', '\\\\' => '\\']);
    elseif ($v !== '' && $v[0] === "'" && substr($v, -1) === "'" && strlen($v) > 1) $v = substr($v, 1, -1);
    elseif (($p = strpos($v, ' #')) !== false) $v = rtrim(substr($v, 0, $p));
    $out[strtoupper($m[1])] = $v;
  }
  return $out;
}

/* ---------- the document (config.yaml) and the secrets (.env), cached per request ---------- */

function &und_store_cache() { static $c = null; return $c; }
function und_store_reset() { $c = &und_store_cache(); $c = null; }

/** Parsed config.yaml and .env; read again whenever either file changed (another request, a hand edit, a restore). */
function und_store_load() {
  und_store_migrate();
  $y = und_file('config.yaml'); $e = und_file('.env');
  clearstatcache();
  $sig = $y . '|' . @filemtime($y) . '|' . @filesize($y) . '|' . $e . '|' . @filemtime($e) . '|' . @filesize($e) . '|' . md5((string)@file_get_contents($y) . (string)@file_get_contents($e));
  $c = &und_store_cache();
  if ($c !== null && $c['sig'] === $sig) return $c;
  $doc = is_file($y) ? und_yaml_read((string)@file_get_contents($y)) : [];
  $c = ['doc' => is_array($doc) ? $doc : [], 'env' => und_env_read((string)@file_get_contents($e)), 'sig' => $sig];
  return $c;
}
function und_doc() { return und_store_load()['doc']; }
function und_env() { return isset($GLOBALS['UND_TEST_ENV']) ? $GLOBALS['UND_TEST_ENV'] : und_store_load()['env']; }

/** Change part of config.yaml (top-level keys); null removes a key. */
function und_doc_write(array $parts) {
  $doc = und_doc();
  foreach ($parts as $k => $v) { if ($v === null) unset($doc[$k]); else $doc[$k] = $v; }
  unset($doc['schema']);       // version is the one that is written
  $order = ['version', 'general', 'appearance', 'dashboard', 'media', 'downloads', 'apps', 'docker', 'vms', 'groups', 'unraid', 'ignored_alerts', 'custom_css'];
  $sorted = [];
  foreach ($order as $k) if (array_key_exists($k, $doc)) $sorted[$k] = $doc[$k];
  foreach ($doc as $k => $v) if (!array_key_exists($k, $sorted)) $sorted[$k] = $v;
  $txt = "# UnDash settings. No secrets in here: keys, user names and passwords are in .env next to this file.\n"
       . "# Edit in Settings, or by hand (then reload the dashboard).\n" . und_yaml_emit($sorted);
  $ok = und_store_write('config.yaml', $txt);
  und_store_reset();
  return $ok;
}
function und_env_write(array $env) {
  $clean = [];
  foreach ($env as $k => $v) { $k = strtoupper((string)$k); if (preg_match('/^[A-Z_][A-Z0-9_]{0,63}$/', $k)) $clean[$k] = und_clip((string)$v, 4000); }
  $ok = und_store_write('.env', und_env_text($clean), true);
  und_store_reset();
  return $ok;
}

/* ---------- app credentials: references in config.yaml, values in .env ---------- */

function und_ref_name($v) { return preg_match('/^\{\{\s*([A-Za-z0-9_]+)\s*\}\}$/', (string)$v, $m) ? strtoupper($m[1]) : null; }

/** A readable, unused .env name for an app's credential: "qBittorrent Private" + key -> QBITTORRENT_PRIVATE_API_KEY. */
function und_cred_name($appName, $field, array $taken) {
  $base = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string)$appName), '_')) ?: 'APP';
  if (preg_match('/^[0-9]/', $base)) $base = 'APP_' . $base;
  $name = $base . '_' . UND_CRED_FIELDS[$field];
  for ($n = 2; isset($taken[$name]); $n++) $name = $base . '_' . $n . '_' . UND_CRED_FIELDS[$field];
  return $name;
}

/** Every name config.yaml refers to, anywhere ({{NAME}} in addresses, options and credential fields). */
function und_cfg_refs(array $cfg) {
  preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', json_encode($cfg['groups'] ?? []), $m);
  return array_flip(array_map('strtoupper', $m[1]));
}

/**
 * Apply credentials sent from Settings: for each app id and field, a new value (stored in .env under a readable name and
 * referenced from config.yaml), UND_KEEP (unchanged) or '' (removed). Values the config no longer refers to are dropped.
 */
function und_creds_apply(array $cfg, array $old, array $creds, array &$env) {
  $oldRefs = [];
  foreach ($old['groups'] ?? [] as $g) foreach ($g['services'] as $s) foreach (array_keys(UND_CRED_FIELDS) as $f) if ($r = und_ref_name($s[$f] ?? '')) $oldRefs[$s['id']][$f] = $r;
  foreach ($cfg['groups'] as &$g) foreach ($g['services'] as &$s) foreach (array_keys(UND_CRED_FIELDS) as $f) {
    $in = $creds[$s['id']][$f] ?? UND_KEEP;
    $ref = und_ref_name($s[$f] ?? '') ?: ($oldRefs[$s['id']][$f] ?? null);
    if ($in === UND_KEEP) { $s[$f] = $ref ? '{{' . $ref . '}}' : ''; continue; }
    if ((string)$in === '') { $s[$f] = ''; continue; }
    if ($r = und_ref_name($in)) { $s[$f] = '{{' . $r . '}}'; continue; }          // the user typed a reference to a shared value
    if (!$ref || in_array($ref, UND_ENV_RESERVED, true)) $ref = und_cred_name($s['name'], $f, $env + und_cfg_refs($cfg));
    $env[$ref] = und_clip((string)$in, 4000);
    $s[$f] = '{{' . $ref . '}}';
  }
  unset($g, $s);
  // a credential value nothing refers to any more goes (only credential-style names, never a shared value someone added)
  $used = und_cfg_refs($cfg);
  foreach ($oldRefs as $fields) foreach ($fields as $r) if (!isset($used[$r])) unset($env[$r]);
  return $cfg;
}

/* ---------- conversion from the old files ---------- */

/** Older versions: config.json + secrets.json (+ custom.css, ignores.json). Converted once; the old files go to data/legacy/. */
function und_store_migrate() {
  static $done = [];
  $dir = und_store_dir();
  if (isset($done[$dir]) && !is_file($dir . '/config.json') && !is_file(UND_DIR . '/config.json')) return;
  $done[$dir] = 1;
  $y = und_file('config.yaml'); $j = und_file('config.json');
  if (is_file($y) || !is_file($j)) return;
  $lock = und_config_lock();
  clearstatcache();
  if (is_file(und_file('config.yaml'))) return;
  $cfg = und_read_json($j, null);
  if (!is_array($cfg)) return;
  $sec = und_read_json(und_file('secrets.json'), []);
  $conv = und_store_convert(und_normalize(und_migrate($cfg)), (array)($sec['vars'] ?? []), (array)($sec['svc'] ?? []));
  $ign = und_read_json(und_file('ignores.json'), []);
  $doc = ['version' => UND_SCHEMA] + $conv['config']; unset($doc['schema']);
  if (!empty($sec['unraid']['url'])) $doc['unraid'] = ['url' => (string)$sec['unraid']['url']];
  if (is_array($ign) && $ign) $doc['ignored_alerts'] = array_values($ign);
  $css = (string)@file_get_contents(und_file('custom.css'));
  if (trim($css) !== '') $doc['custom_css'] = $css;
  $env = $conv['env'];
  if (!empty($sec['unraid']['key'])) $env['UNRAID_API_KEY'] = (string)$sec['unraid']['key'];
  $auth = und_read_json(UND_DIR . '/auth.json', []);
  if (!empty($auth['hash'])) $env['UNDASH_PASSWORD_HASH'] = (string)$auth['hash']; elseif (!empty($auth['open'])) $env['UNDASH_AUTH'] = 'open';
  und_store_write('.env', und_env_text($env), true);
  und_store_reset();
  $txt = "# UnDash settings. No secrets in here: keys, user names and passwords are in .env next to this file.\n# Edit in Settings, or by hand (then reload the dashboard).\n" . und_yaml_emit($doc);
  if (!und_store_write('config.yaml', $txt)) return;
  // keep the old files, out of the way (never deleted)
  foreach (array_unique([$dir, UND_DIR]) as $d) {
    $legacy = $d . '/data/legacy';
    foreach (['config.json', 'secrets.json', 'custom.css', 'ignores.json', 'auth.json'] as $f) if (is_file("$d/$f")) {
      @mkdir($legacy, 0700, true); @rename("$d/$f", "$legacy/$f");
    }
    if (is_dir("$d/backups")) { @mkdir("$d/data", 0700, true); if (!is_dir("$d/data/backups")) @rename("$d/backups", "$d/data/backups"); }
  }
  if (is_file($dir . '/alert-history.json')) @rename($dir . '/alert-history.json', und_data_dir() . '/alert-history.json');
  und_store_reset();
}

/**
 * Old variables and per-widget credentials -> clean references. Addresses and ports that were variables are written into
 * the apps directly; credentials move to per-app names (RADARR_API_KEY); whatever is still referenced keeps its value
 * under its name without a HOMEPAGE_VAR_ prefix. Unused variables are dropped.
 */
function und_store_convert(array $cfg, array $vars, array $svc) {
  $vars = array_change_key_case(array_map('strval', $vars), CASE_UPPER);
  $env = [];
  $inline = function ($s) use ($vars) {
    return preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
      $n = strtoupper($m[1]);
      return isset($vars[$n]) && !und_var_is_secret($n) && !preg_match('/USER|LOGIN|MAIL/i', $n) ? $vars[$n] : $m[0];
    }, (string)$s);
  };
  foreach ($cfg['groups'] as &$g) foreach ($g['services'] as &$s) {
    $s['url'] = $inline($s['url']); $s['href'] = $inline($s['href']);
    foreach (array_keys(UND_CRED_FIELDS) as $f) {
      $val = (string)($svc[$s['id']][$f] ?? '');
      if ($r = und_ref_name($val)) $val = $vars[$r] ?? '';
      $s[$f] = '';
      if ($val === '') continue;
      $name = und_cred_name($s['name'], $f, $env);
      $env[$name] = $val; $s[$f] = '{{' . $name . '}}';
    }
  }
  unset($g, $s);
  // what is still referenced (a key inside an address, an option) keeps its value, without the old prefix
  foreach (array_keys(und_cfg_refs($cfg)) as $n) {
    if (isset($env[$n]) || !isset($vars[$n])) continue;
    $new = preg_replace('/^HOMEPAGE_VAR_/', '', $n);
    // the short name may already hold a credential: the same value shares it, a different one gets its own name
    for ($k = 2; $new !== $n && isset($env[$new]) && $env[$new] !== (string)$vars[$n]; $k++) $new = preg_replace('/^HOMEPAGE_VAR_/', '', $n) . '_' . $k;
    $env[$new] = $vars[$n];
    if ($new !== $n) $cfg = json_decode(str_replace(['{{' . $n . '}}', '{{ ' . $n . ' }}'], '{{' . $new . '}}', json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), true);
  }
  return ['config' => und_normalize($cfg), 'env' => $env];
}

/* ---------- what Settings sees ---------- */

/** Per app: whether a key / password is stored (never the value) and the user name (not a secret). */
function und_creds_view(array $cfg) {
  $env = und_env(); $out = [];
  foreach ($cfg['groups'] as $g) foreach ($g['services'] as $s) {
    $r = [];
    foreach (array_keys(UND_CRED_FIELDS) as $f) {
      $n = und_ref_name($s[$f] ?? '');
      $set = $n !== null && (string)($env[$n] ?? '') !== '';
      $r[$f] = $f === 'username' ? ($set ? (string)$env[$n] : '') : ($set ? UND_KEEP : '');
      $r[$f . '_ref'] = $n ?: '';
    }
    $out[$s['id']] = $r;
  }
  return (object)$out;
}

/** Shared values: .env entries that are not an app's own credential and not UnDash's own (addresses you reuse, a common key …). */
function und_shared_names(array $env, array $cfg) {
  $own = [];
  foreach ($cfg['groups'] as $g) foreach ($g['services'] as $s) foreach (array_keys(UND_CRED_FIELDS) as $f) if ($n = und_ref_name($s[$f] ?? '')) $own[$n] = 1;
  return array_values(array_filter(array_keys($env), function ($k) use ($own) { return !isset($own[$k]) && !in_array($k, UND_ENV_RESERVED, true); }));
}
function und_shared_view(array $cfg) {
  $env = und_env(); $used = und_cfg_refs($cfg); $out = [];
  foreach (und_shared_names($env, $cfg) as $k) {
    $secret = und_var_is_secret($k);
    $out[] = ['name' => $k, 'value' => $secret && $env[$k] !== '' ? UND_KEEP : $env[$k], 'secret' => $secret, 'used' => isset($used[$k])];
  }
  return $out;
}
/** The shared values the form sent replace the old set (UND_KEEP = unchanged); credentials and UnDash's own entries are untouched. */
function und_shared_apply(array $env, array $old, array $new, array $list) {
  // credentials of the new config (an app added in the same save) are not shared values, even though the old config did not know them
  $creds = array_flip(array_diff(array_keys($env), und_shared_names($env, $new)));
  $before = und_shared_names($env, $old);
  $keep = $env;
  foreach ($before as $k) if (!isset($creds[$k])) unset($env[$k]);
  foreach ($list as $v) {
    $n = strtoupper(trim((string)($v['name'] ?? '')));
    if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $n) || in_array($n, UND_ENV_RESERVED, true)) continue;
    $val = (string)($v['value'] ?? '');
    $env[$n] = $val === UND_KEEP ? (string)($keep[$n] ?? '') : und_clip($val, 4000);
  }
  return $env;
}
