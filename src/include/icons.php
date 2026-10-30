<?php
/*
 * UnDash - icon search for the icon picker. The browser may only talk to UnDash (connect-src 'self'),
 * so the two icon lists are fetched here, kept for a week and searched on the server:
 *   selfh.st icons     index.json   -> sh-NAME (sh-NAME.png when there is no SVG)
 *   dashboard-icons    tree.json    -> NAME.svg / NAME.png
 * Only names are kept; the images themselves load from the CDN as before.
 */

define('UND_ICON_SH', 'https://cdn.jsdelivr.net/gh/selfhst/icons@main/index.json');
define('UND_ICON_DI', 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/tree.json');

/** ['sh' => [[ref, name, tags, svg], ...], 'di' => [[name, ext], ...]], fetched at most once a week. */
function und_icon_index() {
  $f = und_tmp('icon-index.json');
  $old = und_read_json($f, []);
  if (!empty($old['at']) && time() - (int)$old['at'] < 7 * 86400 && !empty($old['sh'])) return $old;
  $lock = und_tmp('icon-index.lock');
  // a failed download is not retried for 10 minutes; the old list (if any) is used meanwhile
  if (is_file($lock) && time() - filemtime($lock) < 600) { if ($old) return $old; throw new Exception('The icon list could not be downloaded. Try again in a few minutes.'); }
  @touch($lock);
  $sh = []; $di = [];
  try {
    foreach ((array)und_get(UND_ICON_SH, ['timeout' => 20]) as $x) {
      $ref = (string)($x['Reference'] ?? '');
      if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,80}$/', $ref)) continue;
      $sh[] = [$ref, und_clip((string)($x['Name'] ?? $ref), 80), und_clip(strtolower((string)($x['Tags'] ?? '')), 200), ($x['SVG'] ?? '') === 'Yes' ? 1 : 0];
    }
    $t = (array)und_get(UND_ICON_DI, ['timeout' => 20]);
    $svg = array_flip(array_map(function ($n) { return preg_replace('/\.svg$/', '', (string)$n); }, (array)($t['svg'] ?? [])));
    foreach ((array)($t['png'] ?? []) as $n) {
      $n = preg_replace('/\.png$/', '', (string)$n);
      if (preg_match('/^[a-z0-9][a-z0-9._-]{0,80}$/', $n)) $di[] = [$n, isset($svg[$n]) ? 'svg' : 'png'];
    }
  } catch (Exception $e) {
    if ($old) return $old;
    throw new Exception('The icon list could not be downloaded: ' . $e->getMessage());
  }
  if (!$sh && !$di) { if ($old) return $old; throw new Exception('The icon list came back empty.'); }
  $idx = ['at' => time(), 'sh' => $sh, 'di' => $di];
  und_write_json($f, $idx);
  @unlink($lock);
  return $idx;
}

/** Up to $max icons for a search: [['v' => value for the Icon field, 'n' => name, 's' => source], ...], best matches first. */
function und_icon_search($q, $max = 60) {
  $q = strtolower(trim((string)$q));
  $q = preg_replace('/[^a-z0-9 ._-]/', '', $q);
  if ($q === '') return ['icons' => []];
  $idx = und_icon_index();
  $key = str_replace([' ', '_', '.'], '-', $q);
  $score = function ($ref, $name, $tags) use ($q, $key) {
    $n = strtolower($name);
    if ($ref === $key || $n === $q) return 0;
    if (strpos($ref, $key) === 0 || strpos($n, $q) === 0) return 1;
    if (strpos($ref, $key) !== false || strpos($n, $q) !== false) return 2;
    if ($tags !== '' && strpos($tags, $q) !== false) return 3;
    return -1;
  };
  $hits = []; $seen = [];
  foreach ($idx['sh'] as $x) {
    $s = $score($x[0], $x[1], $x[2]);
    if ($s < 0) continue;
    $seen[$x[0]] = 1;
    $hits[] = [$s, strlen($x[0]), ['v' => 'sh-' . $x[0] . ($x[3] ? '' : '.png'), 'n' => $x[1], 's' => 'selfh.st']];
  }
  foreach ($idx['di'] as $x) {
    if (isset($seen[$x[0]])) continue;           // the same icon is already listed from selfh.st
    $s = $score($x[0], $x[0], '');
    if ($s < 0) continue;
    $hits[] = [$s + 0.5, strlen($x[0]), ['v' => $x[0] . '.' . $x[1], 'n' => $x[0], 's' => 'dashboard-icons']];
  }
  usort($hits, function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: strcmp($a[2]['v'], $b[2]['v']); });
  return ['icons' => array_column(array_slice($hits, 0, max(1, min(200, (int)$max))), 2), 'more' => count($hits) > $max];
}
