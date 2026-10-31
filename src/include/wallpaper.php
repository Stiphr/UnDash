<?php
/*
 * UnDash - a new wallpaper every day from Pexels, through its official API (https://www.pexels.com/api/). The key stays in
 * .env and the search runs here, so the browser only gets the picture's address and who took it. One request a day:
 * the day decides the page and the photo, and the answer is kept in /config (or on the flash drive) until the next day.
 */

const UND_PEXELS_COLORS = ['', 'black', 'gray', 'white', 'red', 'orange', 'yellow', 'green', 'turquoise', 'blue', 'violet', 'pink', 'brown'];

function und_pexels_key($set = null) {
  if ($set === null) return (string)(und_env()['PEXELS_API_KEY'] ?? '');
  $lk = und_config_lock();
  $env = und_env();
  if ($set === '') unset($env['PEXELS_API_KEY']); else $env['PEXELS_API_KEY'] = und_clip(trim($set), 200);
  $ok = und_env_write($env);
  @unlink(und_pexels_file());
  return $ok;
}

function und_pexels_file() { return und_data_dir() . '/wallpaper.json'; }

/** Today's photo: ['url', 'by', 'by_url', 'link'], or ['error' => ...] (with yesterday's photo when there is one). */
function und_pexels(array $cfg, $day = null) {
  $p = (array)($cfg['appearance']['pexels'] ?? []);
  if (empty($p['on'])) return ['off' => true];
  $key = und_pexels_key();
  if ($key === '') return ['error' => 'Add a Pexels API key in Settings > Theme > Daily wallpaper.'];
  $day = $day ?: date('Y-m-d');
  $id = md5($day . '|' . ($p['query'] ?? '') . '|' . ($p['color'] ?? ''));
  $f = und_pexels_file();
  $c = und_read_json($f, []);
  if (($c['id'] ?? '') === $id && !empty($c['url'])) return $c;
  // a failed request is not repeated at every page load; the last photo stays up meanwhile
  $keep = !empty($c['url']) ? array_diff_key($c, ['fail' => 1, 'id' => 1]) : [];
  if (time() - (int)($c['fail'] ?? 0) < 3600) return $keep + ['error' => (string)($c['error'] ?? 'Pexels did not answer. Trying again within the hour.')];
  $lock = und_lock('wallpaper');
  if (!$lock) return $keep ?: ['error' => 'Loading the wallpaper…'];
  $seed = crc32($day);
  try {
    $photos = [];
    foreach ([$seed % 20 + 1, 1] as $page) {
      $q = ['query' => (string)($p['query'] ?? 'nature wallpaper'), 'orientation' => 'landscape', 'size' => 'large', 'per_page' => 40, 'page' => $page];
      if (!empty($p['color'])) $q['color'] = $p['color'];
      $j = und_get('https://api.pexels.com/v1/search?' . http_build_query($q), ['headers' => ['Authorization' => $key], 'timeout' => 15]);
      $photos = array_values(array_filter((array)($j['photos'] ?? []), function ($x) { return is_array($x) && preg_match('#^https://images\.pexels\.com/[^"\'<>\s()\\\\]+$#', (string)($x['src']['large2x'] ?? '')); }));
      if ($photos) break;
    }
    if (!$photos) throw new Exception('Pexels found no photos for that search.');
    $x = $photos[$seed % count($photos)];
    $web = function ($u) { return preg_match('#^https://www\.pexels\.com/#', (string)$u) ? (string)$u : 'https://www.pexels.com'; };
    $out = ['id' => $id, 'url' => (string)$x['src']['large2x'], 'by' => und_clip((string)($x['photographer'] ?? ''), 80), 'by_url' => $web($x['photographer_url'] ?? ''), 'link' => $web($x['url'] ?? '')];
    und_write_json($f, $out);
    und_unlock($lock);
    return $out;
  } catch (Throwable $e) {
    $msg = und_redact($e->getMessage(), [$key]);
    und_write_json($f, $keep + ['fail' => time(), 'error' => $msg]);
    und_unlock($lock);
    return $keep + ['error' => $msg];
  }
}
