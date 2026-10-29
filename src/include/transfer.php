<?php
/*
 * UnDash - settings export and import.
 *
 * Export
 *   config  config.yaml as it is: layout, apps, theme, ignored alerts, custom CSS. It never holds a secret (credentials
 *           are references such as {{RADARR_API_KEY}}), so it is safe to share. Passwords or tokens someone typed straight
 *           into an address (user:pass@host, ?apikey=...) are replaced by {{REDACTED}}.
 *   env     .env: the values of those references (keys, user names, passwords). Keep it private.
 * Import accepts config.yaml, .env and export files of older UnDash versions (JSON), which are converted on the way in.
 */

function und_export($kind) {
  if ($kind === 'env') return ['name' => 'undash.env', 'type' => 'text/plain', 'text' => und_env_text(und_secrets()['vars'] + array_intersect_key(und_env(), ['UNRAID_API_KEY' => 1]))];
  $doc = und_doc();
  if (isset($doc['groups'])) foreach ($doc['groups'] as &$g) foreach ((array)($g['services'] ?? []) as $i => $sv) {
    $g['services'][$i]['url'] = und_redact_url((string)($sv['url'] ?? '')); $g['services'][$i]['href'] = und_redact_url((string)($sv['href'] ?? ''));
    // custom API headers may hold a key written in clear text
    if (isset($sv['options']['headers']) && is_array($sv['options']['headers'])) foreach ($sv['options']['headers'] as $hk => $hv)
      if (!und_ref_name($hv)) $g['services'][$i]['options']['headers'][$hk] = '{{REDACTED}}';
  }
  unset($g);
  if (!isset($doc['groups'])) { $full = json_decode(json_encode(und_load_config()), true); unset($full['schema']); $doc = ['version' => UND_SCHEMA] + $full + array_intersect_key($doc, ['unraid' => 1, 'ignored_alerts' => 1, 'custom_css' => 1]); }
  return ['name' => 'undash-config.yaml', 'type' => 'application/yaml', 'text' => "# UnDash settings, exported " . gmdate('Y-m-d') . " (version " . UND_VERSION . "). No secrets: they are in .env.\n" . und_yaml_emit($doc)];
}

/** Secrets written into a URL: user:password@host and token-like query parameters. Variables ({{NAME}}) are kept. */
function und_redact_url($u) {
  $u = preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@\s]+@#i', '$1', (string)$u);
  return preg_replace_callback('/([?&])([^=&#]+)=([^&#]*)/', function ($m) {
    if (!preg_match('/key|token|pass|secret|auth|sig|session/i', $m[2]) || preg_match('/^\{\{\s*[A-Za-z0-9_]+\s*\}\}$/', urldecode($m[3]))) return $m[0];
    return $m[1] . $m[2] . '={{REDACTED}}';
  }, $u);
}

/**
 * Check an uploaded file before anything is changed. Returns what it is and a summary for the preview, or an error.
 * Accepted: config.yaml, .env, and export files of older UnDash versions (JSON).
 */
function und_import_parse($raw) {
  if (!is_string($raw) || trim($raw) === '') return ['error' => 'The file is empty.'];
  if (strlen($raw) > 2097152) return ['error' => 'The file is larger than 2 MB; that is not an UnDash settings file.'];
  $t = ltrim($raw);
  if ($t[0] === '{') return und_import_parse_json($raw);
  // .env: only NAME=value lines (and comments)
  $lines = array_filter(preg_split('/\r\n|\r|\n/', $raw), function ($l) { return trim($l) !== '' && trim($l)[0] !== '#'; });
  if ($lines && count(array_filter($lines, function ($l) { return preg_match('/^\s*(export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=/', $l); })) === count($lines)) {
    $env = und_env_read($raw);
    return ['kind' => 'env', 'env' => $env, 'summary' => ['kind' => 'env', 'values' => count($env), 'names' => array_slice(array_keys($env), 0, 60)]];
  }
  $doc = und_yaml_read($raw);
  if (!is_array($doc) || !isset($doc['groups']) || !is_array($doc['groups'])) return ['error' => 'This is not an UnDash settings file (config.yaml, .env, or an export of an older version).'];
  if ((int)($doc['version'] ?? UND_SCHEMA) > UND_SCHEMA) return ['error' => 'This file comes from a newer UnDash (settings version ' . (int)$doc['version'] . '). Update UnDash first.'];
  $cfg = $doc; $cfg['schema'] = (int)($doc['version'] ?? UND_SCHEMA);
  $cfg = und_normalize(und_migrate($cfg));
  return und_import_summary(['kind' => 'config', 'config' => $cfg, 'css' => is_string($doc['custom_css'] ?? null) ? $doc['custom_css'] : null,
    'ignored' => is_array($doc['ignored_alerts'] ?? null) ? $doc['ignored_alerts'] : null, 'unraid_url' => (string)($doc['unraid']['url'] ?? ''), 'env' => []], $doc['groups']);
}

/** An export of an older UnDash (JSON with config, vars and per-widget credentials): converted like the old files. */
function und_import_parse_json($raw) {
  $j = json_decode($raw, true, 64);
  if (!is_array($j)) return ['error' => 'The file is not valid JSON.'];
  if (isset($j['undash_export'])) {
    if ((int)($j['schema'] ?? 0) > UND_SCHEMA) return ['error' => 'This export uses settings schema ' . (int)$j['schema'] . '; this UnDash understands up to ' . UND_SCHEMA . '. Update UnDash first.'];
    if (!isset($j['config']['groups']) || !is_array($j['config']['groups'])) return ['error' => 'The export has no layout in it.'];
    $cfg = $j['config']; $vars = is_array($j['vars'] ?? null) ? $j['vars'] : []; $sec = is_array($j['secrets'] ?? null) ? $j['secrets'] : [];
  } elseif (isset($j['groups']) && is_array($j['groups'])) { $cfg = $j; $vars = []; $sec = []; }
  else return ['error' => 'This is not an UnDash settings file.'];
  foreach ($vars as $k => $v) if (!is_string($k) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $k) || !is_scalar($v)) return ['error' => 'The variables in the file are malformed.'];
  foreach ($sec as $id => $c) if (!preg_match('/^[a-f0-9]{8}$/', (string)$id) || !is_array($c)) return ['error' => 'The credentials in the file are malformed.'];
  $conv = und_store_convert(und_normalize(und_migrate($cfg)), $vars, $sec);
  return und_import_summary(['kind' => 'config', 'legacy' => true, 'config' => $conv['config'], 'css' => is_string($j['css'] ?? null) ? $j['css'] : null, 'ignored' => null,
    'unraid_url' => (string)($j['unraid']['url'] ?? ''), 'env' => array_filter($conv['env'], function ($v) { return $v !== ''; })], $cfg['groups']);
}

function und_import_summary(array $file, $rawGroups) {
  $cfg = $file['config'];
  $n = 0; foreach ($cfg['groups'] as $g) $n += count($g['services']);
  $dropped = 0; foreach ((array)$rawGroups as $g) $dropped += is_array($g) ? count((array)($g['services'] ?? [])) : 0;
  $file['summary'] = ['kind' => $file['kind'], 'legacy' => !empty($file['legacy']), 'groups' => count($cfg['groups']), 'widgets' => $n, 'skipped' => max(0, $dropped - $n),
    'theme' => $cfg['appearance']['theme'], 'refresh' => $cfg['general']['refresh'], 'css' => $file['css'] !== null ? strlen($file['css']) : null, 'values' => count($file['env'])];
  return $file;
}

/**
 * Apply a checked import; a backup is taken first, so it can be undone from the backup list.
 *   config  replaces config.yaml (layout, apps, theme ...). Your .env stays; the Unraid API address is only filled in when none is set.
 *   env     adds the values to .env (a value in the file replaces the one stored under the same name).
 */
function und_import_apply($raw) {
  $f = und_import_parse($raw);
  if (isset($f['error'])) return $f;
  und_config_lock();
  $bk = und_backup('before import');
  if ($f['kind'] === 'env') {
    $env = und_env();
    foreach ($f['env'] as $k => $v) $env[$k] = $v;
    und_env_write($env);
    return ['ok' => true, 'backup' => $bk];
  }
  if (!und_save_config($f['config'], false)) return ['error' => 'Could not write the configuration directory.'];
  $parts = [];
  if ($f['css'] !== null) $parts['custom_css'] = trim(und_sanitize_css($f['css'])) === '' ? null : und_sanitize_css($f['css']);
  if ($f['ignored'] !== null) $parts['ignored_alerts'] = $f['ignored'] ? und_ignore_clean($f['ignored']) : null;
  if ($f['unraid_url'] !== '' && preg_match('#^https?://#i', $f['unraid_url']) && (string)(und_doc()['unraid']['url'] ?? '') === '') $parts['unraid'] = ['url' => und_clip($f['unraid_url'], 300)];
  if ($parts) und_doc_write($parts);
  // values from the file fill empty ones; a {{NAME}} the config uses but .env lacks gets an empty entry, so Settings lists it to fill in
  $env = und_env(); $before = $env;
  foreach ($f['env'] as $k => $v) if (!isset($env[$k]) || $env[$k] === '') $env[$k] = $v;
  foreach (array_keys(und_cfg_refs($f['config'])) as $n) if (!isset($env[$n]) && $n !== 'UNRAID_IP') $env[$n] = '';
  if ($env !== $before) und_env_write($env);
  return ['ok' => true, 'backup' => $bk];
}
