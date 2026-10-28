<?php
/*
 * UnDash - settings export and import.
 *
 * Export file: {"undash_export": 1, "schema": 2, "kind": "full"|"redacted", "created": unix time, "config": {...}, "css": "...", "vars": {...}, "secrets": {...}}
 *   full      layout, preferences, appearance, custom CSS, every variable and every widget credential (a complete backup; keep it private)
 *   redacted  the same without anything secret: credentials are dropped, secret-looking variables are emptied, and passwords or
 *             tokens written straight into URLs (user:pass@host, ?apikey=...) are replaced by {{REDACTED}}
 * Neither contains anything but UnDash's own settings.
 */

function und_export($kind) {
  $cfg = und_load_config(); $sec = und_secrets();
  $out = ['undash_export' => 1, 'schema' => UND_SCHEMA, 'kind' => $kind === 'full' ? 'full' : 'redacted', 'created' => time(), 'version' => UND_VERSION,
          'config' => $cfg, 'css' => und_load_css()];
  if ($out['kind'] === 'full') { $out['vars'] = (object)$sec['vars']; $out['secrets'] = (object)$sec['svc']; return $out; }
  $vars = [];
  foreach ($sec['vars'] as $k => $v) $vars[$k] = und_var_is_secret($k) ? '' : und_redact_url((string)$v);
  $out['vars'] = (object)$vars;
  foreach ($out['config']['groups'] as &$g) foreach ($g['services'] as &$s) {
    $s['url'] = und_redact_url($s['url']); $s['href'] = und_redact_url($s['href']);
    // custom API headers may hold a key written in clear text
    if (isset($s['options']['headers']) && is_array($s['options']['headers'])) foreach ($s['options']['headers'] as $hk => $hv) {
      if (!preg_match('/^\{\{\s*[A-Za-z0-9_]+\s*\}\}$/', (string)$hv)) $s['options']['headers'][$hk] = '{{REDACTED}}';
    }
  }
  unset($g, $s);
  return $out;
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
 * Check an uploaded export before anything is changed. Returns a summary for the preview, or an error.
 * Only UnDash export files (and older UnDash layout files, which are plain config objects) are accepted.
 */
function und_import_parse($raw) {
  if (!is_string($raw) || $raw === '') return ['error' => 'The file is empty.'];
  if (strlen($raw) > 2097152) return ['error' => 'The file is larger than 2 MB; that is not an UnDash export.'];
  $j = json_decode($raw, true, 64);
  if (!is_array($j)) return ['error' => 'The file is not valid JSON.'];
  if (isset($j['undash_export'])) {
    if ((int)$j['undash_export'] !== 1) return ['error' => 'This export comes from a newer UnDash; update the plugin first.'];
    if ((int)($j['schema'] ?? 0) > UND_SCHEMA) return ['error' => 'This export uses settings schema ' . (int)$j['schema'] . '; this UnDash understands up to ' . UND_SCHEMA . '. Update the plugin first.'];
    if (!isset($j['config']) || !is_array($j['config']) || !isset($j['config']['groups']) || !is_array($j['config']['groups'])) return ['error' => 'The export has no layout in it.'];
    $file = ['kind' => $j['kind'] === 'full' ? 'full' : 'redacted', 'config' => $j['config'], 'css' => is_string($j['css'] ?? null) ? $j['css'] : null,
             'vars' => isset($j['vars']) && is_array($j['vars']) ? $j['vars'] : [], 'secrets' => isset($j['secrets']) && is_array($j['secrets']) ? $j['secrets'] : [], 'created' => (int)($j['created'] ?? 0)];
  } elseif (isset($j['groups']) && is_array($j['groups'])) {
    $file = ['kind' => 'layout', 'config' => $j, 'css' => null, 'vars' => [], 'secrets' => [], 'created' => 0];
  } else return ['error' => 'This is not an UnDash settings file.'];
  foreach ($file['vars'] as $k => $v) if (!is_string($k) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $k) || !is_scalar($v)) return ['error' => 'The variables in the file are malformed.'];
  foreach ($file['secrets'] as $id => $c) if (!preg_match('/^[a-f0-9]{8}$/', (string)$id) || !is_array($c)) return ['error' => 'The credentials in the file are malformed.'];
  $cfg = und_normalize(und_migrate($file['config']));
  $file['config'] = $cfg;
  $n = 0; foreach ($cfg['groups'] as $g) $n += count($g['services']);
  $dropped = 0; foreach ($j['config']['groups'] as $g) $dropped += is_array($g) ? count((array)($g['services'] ?? [])) : 0;
  $file['summary'] = ['kind' => $file['kind'], 'created' => $file['created'], 'groups' => count($cfg['groups']), 'widgets' => $n, 'skipped' => max(0, $dropped - $n),
    'theme' => $cfg['appearance']['theme'], 'accent' => $cfg['appearance']['accent'], 'refresh' => $cfg['general']['refresh'], 'css' => $file['css'] !== null ? strlen($file['css']) : null,
    'vars' => count($file['vars']), 'credentials' => count($file['secrets'])];
  return $file;
}

/**
 * Apply a checked import. A backup of the current settings is taken first, so it can be undone from the backup list.
 * Credentials: a full export replaces them; a redacted or layout-only file keeps the stored ones for widgets with the same id
 * and keeps stored values of variables the file leaves empty.
 */
function und_import_apply($raw) {
  $f = und_import_parse($raw);
  if (isset($f['error'])) return $f;
  $bk = und_backup('before import');
  $old = und_secrets();
  $ids = und_service_ids($f['config']);
  $vars = $old['vars'];
  foreach ($f['vars'] as $k => $v) { $k = strtoupper($k); if ((string)$v !== '' || !isset($vars[$k])) $vars[$k] = und_clip((string)$v, 2000); }
  $svc = [];
  foreach ($ids as $id) {
    $c = $f['kind'] === 'full' && isset($f['secrets'][$id]) ? $f['secrets'][$id] : ($old['svc'][$id] ?? []);
    foreach (['key', 'password', 'username'] as $k) if (isset($c[$k]) && is_scalar($c[$k]) && (string)$c[$k] !== '') $svc[$id][$k] = und_clip((string)$c[$k], 2000);
  }
  if (!und_save_config($f['config'], false)) return ['error' => 'Could not write the configuration directory.'];
  und_save_secrets(['vars' => $vars, 'svc' => $svc]);
  if ($f['css'] !== null) und_save_css($f['css'], false);
  return ['ok' => true, 'backup' => $bk];
}
