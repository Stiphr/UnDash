<?php
/*
 * UnDash - a new wallpaper every day from Wikimedia Commons, through its public API. It needs no key or account. Only photos
 * the Commons community has reviewed as "Quality images" are used, and they are free to use with their author and licence
 * named. One request a day: the day decides the page and the photo, and the answer is kept until the next day.
 */

const UND_WALL_TOPICS = ['nature' => 'nature landscape', 'city' => 'skyline', 'cars' => 'sports car', 'mountains' => 'mountains', 'ocean' => 'ocean coast', 'space' => 'milky way'];

function und_wall_file() { return und_data_dir() . '/wallpaper.json'; }

/** The search words for a daily wallpaper setting; custom words are reduced to letters and numbers, so they stay plain words. */
function und_wall_query(array $w) {
  if (($w['topic'] ?? '') !== 'custom') return UND_WALL_TOPICS[$w['topic'] ?? ''] ?? UND_WALL_TOPICS['nature'];
  return trim((string)preg_replace('/[^\p{L}\p{N}-]+/u', ' ', (string)($w['custom'] ?? ''))) ?: UND_WALL_TOPICS['nature'];
}

/** One search result as a wallpaper, or null when it is not a usable wide photo. */
function und_wall_photo($p) {
  $i = $p['imageinfo'][0] ?? null;
  if (!is_array($i) || (int)($i['width'] ?? 0) < 1.3 * (int)($i['height'] ?? 1)) return null;
  $url = (string)($i['thumburl'] ?? '');
  $page = (string)($i['descriptionurl'] ?? '');
  if (!preg_match('#^https://(upload|thumb)\.wikimedia\.org/[^"\'<>\s()\\\\]+$#', $url) || !preg_match('#^https://commons\.wikimedia\.org/wiki/[^"\'<>\s]+$#', $page)) return null;
  // the author and licence come as HTML, sometimes a whole table: the first line of its text is enough
  $text = function ($k) use ($i) { $t = html_entity_decode(strip_tags((string)($i['extmetadata'][$k]['value'] ?? '')), ENT_QUOTES, 'UTF-8'); return und_clip(trim((string)strtok(trim($t), "\n")), 60); };
  return ['url' => $url, 'by' => $text('Artist'), 'license' => $text('LicenseShortName'), 'link' => $page];
}

/** Today's photo: ['url', 'by', 'license', 'link'], or ['error' => ...] (with the last photo when there is one). */
function und_wall(array $cfg, $day = null) {
  $w = (array)($cfg['appearance']['daily'] ?? []);
  if (empty($w['on'])) return ['off' => true];
  $day = $day ?: date('Y-m-d');
  $words = und_wall_query($w);
  $id = md5($day . '|' . $words);
  $f = und_wall_file();
  $c = und_read_json($f, []);
  if (($c['id'] ?? '') === $id && !empty($c['url'])) return $c;
  // a failed request is not repeated at every page load; the last photo stays up meanwhile
  $keep = !empty($c['url']) ? array_diff_key($c, ['fail' => 1, 'id' => 1, 'error' => 1]) : [];
  if (($c['for'] ?? '') === $words && time() - (int)($c['fail'] ?? 0) < 3600) return $keep + ['error' => (string)($c['error'] ?? 'Wikimedia Commons did not answer. Trying again within the hour.')];
  $lock = und_lock('wallpaper');
  if (!$lock) return $keep ?: ['error' => 'Loading the wallpaper…'];
  $seed = crc32($day);
  try {
    $photos = [];
    foreach ([$seed % 30 * 50, 0] as $offset) {
      $j = und_get('https://commons.wikimedia.org/w/api.php?' . http_build_query(['action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrnamespace' => 6, 'gsrlimit' => 50, 'gsroffset' => $offset,
        'gsrsearch' => $words . ' hastemplate:"Quality image" filemime:image/jpeg filew:>2400', 'prop' => 'imageinfo', 'iiprop' => 'url|size|extmetadata', 'iiurlwidth' => 2560, 'iiextmetadatafilter' => 'Artist|LicenseShortName']),
        ['headers' => ['User-Agent' => 'UnDash (https://github.com/Stiphr/UnDash)'], 'timeout' => 15]);
      $photos = array_values(array_filter(array_map('und_wall_photo', array_values((array)($j['query']['pages'] ?? [])))));
      if ($photos) break;
    }
    if (!$photos) throw new Exception('No photos found for “' . $words . '”. Try other words.');
    $out = ['id' => $id] + $photos[$seed % count($photos)];
    und_write_json($f, $out);
    und_unlock($lock);
    return $out;
  } catch (Throwable $e) {
    $msg = $e->getMessage();
    und_write_json($f, $keep + ['fail' => time(), 'for' => $words, 'error' => $msg]);
    und_unlock($lock);
    return $keep + ['error' => $msg];
  }
}
