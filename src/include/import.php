<?php
/*
 * UnDash - import from a gethomepage/homepage setup (services.yaml, settings.yaml and optionally its .env).
 * PHP on Unraid has no YAML extension, so this is a small parser for the YAML Homepage's files use:
 * block maps and lists, quoted and plain scalars, flow lists ["a", "b"], comments. Anchors and multi-line strings are not supported.
 */

function und_yaml_parse($text) {
  $lines = [];
  foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $raw) {
    if (strpos($raw, "\t") !== false) $raw = str_replace("\t", '    ', $raw);
    $l = und_yaml_strip_comment($raw);
    if (trim($l) === '' || trim($l) === '---') continue;
    $lines[] = [strlen($l) - strlen(ltrim($l, ' ')), rtrim(ltrim($l, ' '))];
  }
  $i = 0;
  return $lines ? und_yaml_block($lines, $i, $lines[0][0]) : [];
}

function und_yaml_strip_comment($l) {
  $q = '';
  for ($k = 0, $n = strlen($l); $k < $n; $k++) {
    $c = $l[$k];
    if ($q !== '') { if ($c === $q) $q = ''; continue; }
    if ($c === '"' || $c === "'") { if ($k === 0 || strpos(" :[,-", $l[$k - 1]) !== false) $q = $c; continue; }
    if ($c === '#' && ($k === 0 || $l[$k - 1] === ' ')) return substr($l, 0, $k);
  }
  return $l;
}

function und_yaml_block(array &$lines, &$i, $indent) {
  if ($i >= count($lines)) return null;
  if ($lines[$i][1] === '-' || strncmp($lines[$i][1], '- ', 2) === 0) {
    $out = [];
    while ($i < count($lines) && $lines[$i][0] === $indent && ($lines[$i][1] === '-' || strncmp($lines[$i][1], '- ', 2) === 0)) {
      $rest = ltrim(substr($lines[$i][1], 1), ' ');
      if ($rest === '') { $i++; $out[] = ($i < count($lines) && $lines[$i][0] > $indent) ? und_yaml_block($lines, $i, $lines[$i][0]) : null; continue; }
      if (und_yaml_is_key($rest)) {
        // "- key: value" starts a map whose keys sit at the column after "- "
        $lines[$i] = [$indent + (strlen($lines[$i][1]) - strlen($rest)), $rest];
        $out[] = und_yaml_block($lines, $i, $lines[$i][0]);
      } else { $out[] = und_yaml_scalar($rest); $i++; }
    }
    return $out;
  }
  $out = [];
  while ($i < count($lines) && $lines[$i][0] === $indent && und_yaml_is_key($lines[$i][1])) {
    list($k, $v) = und_yaml_split($lines[$i][1]);
    $i++;
    if ($v === '') {
      if ($i < count($lines) && ($lines[$i][0] > $indent || ($lines[$i][0] === $indent && strncmp($lines[$i][1], '-', 1) === 0))) $out[$k] = und_yaml_block($lines, $i, $lines[$i][0]);
      else $out[$k] = null;
    } else $out[$k] = und_yaml_scalar($v);
  }
  // skip anything indented deeper that did not belong to a key (malformed input): never loop forever
  while ($i < count($lines) && $lines[$i][0] > $indent) $i++;
  return $out;
}

function und_yaml_is_key($s) {
  if ($s === '' || $s[0] === '[' || ($s[0] === '{' && substr($s, 0, 2) !== '{{')) return false;
  if ($s[0] === '"' || $s[0] === "'") return preg_match('/^(["\']).*?\1\s*:(\s|$)/', $s) === 1;
  return preg_match('/^[^:]+?:(\s|$)/', $s) === 1 && substr($s, 0, 2) !== '{{';
}

function und_yaml_split($s) {
  if ($s[0] === '"' || $s[0] === "'") { preg_match('/^(["\'])(.*?)\1\s*:\s*(.*)$/', $s, $m); return [$m[2], trim($m[3])]; }
  $p = strpos($s, ': '); if ($p === false) $p = strlen($s) - 1;
  return [trim(substr($s, 0, $p)), trim((string)substr($s, $p + 1))];
}

function und_yaml_scalar($v) {
  $v = trim($v);
  if ($v === '' || $v === '~' || $v === 'null') return null;
  if ($v[0] === '"') { $e = strrpos($v, '"'); return stripcslashes((string)substr($v, 1, ($e ?: strlen($v)) - 1)); }
  if ($v[0] === "'") return str_replace("''", "'", (string)substr($v, 1, (strrpos($v, "'") ?: strlen($v)) - 1));
  if ($v[0] === '[') {
    $in = trim(substr($v, 1, (strrpos($v, ']') ?: strlen($v)) - 1));
    if ($in === '') return [];
    preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^,]+/', $in, $m);
    return array_map('und_yaml_scalar', array_map('trim', $m[0]));
  }
  if (in_array(strtolower($v), ['true', 'yes', 'on'], true)) return true;
  if (in_array(strtolower($v), ['false', 'no', 'off'], true)) return false;
  if (preg_match('/^-?\d+$/', $v)) return (int)$v;
  if (preg_match('/^-?\d+\.\d+$/', $v)) return (float)$v;
  return $v;
}

/* ---------- Homepage -> UnDash ---------- */

function und_env_parse($text) {
  $out = [];
  foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $l) {
    if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $l, $m)) continue;
    $v = trim($m[2]);
    if ($v !== '' && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
    elseif (($p = strpos($v, ' #')) !== false) $v = rtrim(substr($v, 0, $p));
    $out[strtoupper($m[1])] = $v;
  }
  return $out;
}

/** A Homepage customapi "field" (a nested map like {response: {data: {items: {0: title}}}}) as a dot path. */
function und_hp_path($f) {
  $parts = [];
  while (is_array($f) && count($f) === 1) { $k = array_keys($f)[0]; $parts[] = $k; $f = $f[$k]; }
  if (is_scalar($f) && $f !== '') $parts[] = (string)$f;
  return implode('.', $parts);
}

function und_hp_convert($servicesYaml, $settingsYaml = '', $envText = '') {
  $warn = [];
  $svcs = und_yaml_parse($servicesYaml);
  if (!is_array($svcs) || !$svcs) return ['error' => 'services.yaml could not be read (expected a list of groups).'];
  $settings = $settingsYaml !== '' ? und_yaml_parse($settingsYaml) : [];
  $layout = is_array($settings['layout'] ?? null) ? $settings['layout'] : [];
  $env = und_env_parse($envText);
  $direct = ['radarr', 'sonarr', 'lidarr', 'readarr', 'bazarr', 'prowlarr', 'overseerr', 'autobrr', 'qbittorrent', 'sabnzbd', 'emby', 'jellyfin', 'plex', 'tautulli', 'adguard', 'pihole', 'npm', 'immich', 'peanut'];
  $alias = ['jellyseerr' => 'overseerr', 'nginxproxymanager' => 'npm'];
  $groups = []; $secrets = []; $calendars = [];
  foreach ($svcs as $gEntry) {
    if (!is_array($gEntry)) continue;
    foreach ($gEntry as $gName => $items) {
      $lay = is_array($layout[$gName] ?? null) ? $layout[$gName] : [];
      $g = ['id' => und_id(), 'name' => (string)$gName, 'columns' => (int)($lay['columns'] ?? 4) ?: 4, 'header' => !isset($lay['header']) || !empty($lay['header']), 'services' => []];
      foreach ((array)$items as $sEntry) {
        if (!is_array($sEntry)) continue;
        foreach ($sEntry as $sName => $def) {
          if (!is_array($def)) $def = [];
          if (isset($def[0])) { $warn[] = "\"$sName\" in \"$gName\" is a nested group; nested groups are not supported, skipped."; continue; }
          $w = is_array($def['widget'] ?? null) ? $def['widget'] : null;
          $s = ['id' => und_id(), 'name' => (string)$sName, 'type' => 'link', 'icon' => (string)($def['icon'] ?? ''), 'href' => (string)($def['href'] ?? ''), 'url' => '', 'fields' => [], 'options' => []];
          $sec = [];
          if ($w) {
            $t = strtolower((string)($w['type'] ?? ''));
            $t = $alias[$t] ?? $t;
            foreach (['key', 'username', 'password'] as $f) if (isset($w[$f]) && $w[$f] !== '') $sec[$f] = (string)$w[$f];
            if (isset($w['url'])) $s['url'] = (string)$w['url'];
            if (isset($w['fields']) && is_array($w['fields'])) $s['fields'] = array_values(array_map('strval', $w['fields']));
            if (in_array($t, $direct, true)) {
              $s['type'] = $t;
              if (in_array($t, ['emby', 'jellyfin'], true) && isset($w['enableBlocks']) && !$w['enableBlocks']) $s['options']['hide_blocks'] = true;
            } elseif ($t === 'glances') {
              $m = (string)($w['metric'] ?? 'info');
              $s['type'] = $m === 'cpu' ? 'cpu' : ($m === 'memory' ? 'memory' : (strpos($m, 'fs') === 0 || strpos($m, 'disk') === 0 ? 'storage' : 'system_info'));
              $s['url'] = '';
              if (!in_array($m, ['info', 'cpu', 'memory'], true)) $warn[] = "\"$sName\": Glances metric \"$m\" is shown with UnDash's built-in " . $s['type'] . ' card.';
            } elseif ($t === 'calendar') {
              $s['type'] = 'calendar';
              $s['options'] = ['max_events' => (int)($w['maxEvents'] ?? 8), 'previous_days' => (int)($w['previousDays'] ?? 0), 'days_ahead' => 30, 'integrations' => []];
              $calendars[] = [count($groups), count($g['services']), (array)($w['integrations'] ?? [])];
            } elseif ($t === 'customapi') {
              $s['type'] = 'customapi';
              $maps = [];
              foreach ((array)($w['mappings'] ?? []) as $m) {
                if (!is_array($m)) continue;
                $x = ['field' => und_hp_path($m['field'] ?? ''), 'label' => (string)($m['label'] ?? ''), 'format' => (string)($m['format'] ?? 'text')];
                if (isset($m['scale'])) $x['scale'] = $m['scale'];
                if (is_array($m['additionalField'] ?? null)) $x['additional'] = ['field' => und_hp_path($m['additionalField']['field'] ?? ''), 'format' => (string)($m['additionalField']['format'] ?? 'text'), 'scale' => $m['additionalField']['scale'] ?? 1];
                $maps[] = $x;
              }
              $s['options'] = ['display' => ($w['display'] ?? '') === 'list' ? 'list' : 'block', 'method' => (string)($w['method'] ?? 'GET'), 'headers' => is_array($w['headers'] ?? null) ? $w['headers'] : new stdClass(), 'mappings' => $maps];
            } else {
              $warn[] = "\"$sName\": widget type \"$t\" is not supported yet, imported as a link.";
              $s['url'] = '';
            }
          }
          if ($sec) $secrets[$s['id']] = $sec;
          $g['services'][] = $s;
        }
      }
      $groups[] = $g;
    }
  }
  foreach ($calendars as $c) {
    list($gi, $si, $ints) = $c;
    foreach ($ints as $in) {
      if (!is_array($in)) continue;
      foreach ($groups as $gg) foreach ($gg['services'] as $ss) {
        if (strcasecmp($ss['name'], (string)($in['service_name'] ?? '')) === 0 && (empty($in['service_group']) || strcasecmp($gg['name'], (string)$in['service_group']) === 0)) {
          $groups[$gi]['services'][$si]['options']['integrations'][] = ['service' => $ss['id'], 'unmonitored' => !empty($in['params']['unmonitored'])];
        }
      }
    }
    if (!$groups[$gi]['services'][$si]['options']['integrations']) $warn[] = '"' . $groups[$gi]['services'][$si]['name'] . '": its Radarr/Sonarr service was not found.';
  }
  // every {{VAR}} the import refers to, with a value from the .env when one was given
  $all = json_encode([$groups, $secrets]);
  preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $all, $m);
  $vars = [];
  foreach (array_unique(array_map('strtoupper', $m[1])) as $n) $vars[$n] = $env[$n] ?? null;
  foreach ($vars as $n => $v) if ($v === null && preg_match('/SERVER_IP$/', $n)) $vars[$n] = und_server_ip();
  $count = 0; foreach ($groups as $gg) $count += count($gg['services']);
  $cfg = und_load_config();
  $g = $cfg['general'];
  if (!empty($settings['title'])) $g['title'] = (string)$settings['title'];
  return ['config' => ['general' => $g, 'groups' => $groups], 'secrets' => $secrets, 'vars' => $vars, 'warnings' => $warn, 'groups' => count($groups), 'services' => $count];
}

/** Save an import: replaces the layout; keeps stored variable values unless the .env gave a new one. */
function und_hp_apply(array $conv) {
  $old = und_secrets();
  $vars = $old['vars'];
  foreach ($conv['vars'] as $n => $v) if ($v !== null || !isset($vars[$n])) $vars[$n] = $v === null ? (string)($vars[$n] ?? '') : (string)$v;
  und_save_config($conv['config']);
  $saved = und_load_config();
  $ids = [];
  foreach ($saved['groups'] as $g) foreach ($g['services'] as $s) $ids[] = $s['id'];
  $svc = [];
  foreach ($conv['secrets'] as $id => $c) if (in_array($id, $ids, true)) $svc[$id] = $c;
  und_save_secrets(['vars' => $vars, 'svc' => $svc]);
  return ['ok' => true];
}
