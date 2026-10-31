<?php
/*
 * UnDash - a new wallpaper every day from Wikimedia Commons, through its public API. It needs no key or account. Only photos
 * the Commons community has reviewed as "Quality images" are used, and they are free to use with their author and licence
 * named. One request a day: the day decides the page and the photo, and the answer is kept until the next day.
 */

const UND_WALL_TOPICS = ['nature' => 'nature landscape', 'city' => 'skyline', 'cars' => 'sports car', 'mountains' => 'mountains', 'ocean' => 'ocean coast', 'space' => 'milky way'];

/* ---------- your own wallpaper: one picture uploaded in Settings, kept in data/wallpaper and served by UnDash itself ---------- */

const UND_WALL_MAX_BYTES = 6 * 1048576;          // the browser shrinks big photos before sending them; this is the hard limit
const UND_WALL_MAX_SIDE = 8192;                   // pixels; larger pictures would cost a lot of memory to decode
const UND_WALL_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

function und_wall_custom_dir() { return und_data_dir() . '/wallpaper'; }

/** The uploaded picture, or null: ['file', 'ext', 'mime', 'v' (its mtime, for the URL), 'size']. */
function und_wall_custom() {
  foreach (UND_WALL_TYPES as $ext => $mime) {
    $f = und_wall_custom_dir() . '/custom.' . $ext;
    if (is_file($f)) { clearstatcache(true, $f); return ['file' => $f, 'ext' => $ext, 'mime' => $mime, 'v' => (int)@filemtime($f), 'size' => (int)@filesize($f)]; }
  }
  return null;
}

/** What the browser needs to know: whether there is a picture and its version (the URL changes when it does). Never the path. */
function und_wall_custom_view() { $w = und_wall_custom(); return $w ? ['set' => true, 'v' => $w['v'], 'size' => $w['size']] : ['set' => false, 'v' => 0, 'size' => 0]; }

/** The picture's type from its first bytes (the file name or MIME type the browser claims is not trusted). */
function und_wall_type($bytes) {
  $b = (string)$bytes;
  if (substr($b, 0, 3) === "\xFF\xD8\xFF") return 'jpg';
  if (substr($b, 0, 8) === "\x89PNG\r\n\x1a\n") return 'png';
  if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') return 'webp';
  return null;
}

/** Store an uploaded picture (base64 of a JPEG, PNG or WebP). Returns ['ok', 'v'] or ['error']. The old picture is replaced only once the new one is checked. */
function und_wall_upload($b64) {
  $b64 = preg_replace('#^data:[^;]+;base64,#', '', trim((string)$b64));
  if ($b64 === '') return ['error' => 'No picture was sent.'];
  if (strlen($b64) > UND_WALL_MAX_BYTES * 4 / 3 + 1024) return ['error' => 'The picture is larger than ' . (UND_WALL_MAX_BYTES / 1048576) . ' MB. Use a smaller one.'];
  $bytes = base64_decode($b64, true);
  if ($bytes === false || strlen($bytes) < 64) return ['error' => 'The picture could not be read.'];
  if (strlen($bytes) > UND_WALL_MAX_BYTES) return ['error' => 'The picture is larger than ' . (UND_WALL_MAX_BYTES / 1048576) . ' MB. Use a smaller one.'];
  $ext = und_wall_type($bytes);
  if ($ext === null) return ['error' => 'Only JPEG, PNG and WebP pictures work as a wallpaper.'];
  $dim = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bytes) : false;
  if (!is_array($dim) || (int)$dim[0] < 16 || (int)$dim[1] < 16) return ['error' => 'That file is not a picture UnDash can show.'];
  if ((int)$dim[0] > UND_WALL_MAX_SIDE || (int)$dim[1] > UND_WALL_MAX_SIDE) return ['error' => 'The picture is wider or taller than ' . UND_WALL_MAX_SIDE . ' pixels. Use a smaller one.'];
  $dir = und_wall_custom_dir();
  @mkdir($dir, 0700, true);
  $f = $dir . '/custom.' . $ext; $tmp = $f . '.tmp' . getmypid();
  if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $f)) { @unlink($tmp); return ['error' => 'The picture could not be saved. Check that the configuration folder is writable.']; }
  @chmod($f, 0600);
  foreach (UND_WALL_TYPES as $e => $m) if ($e !== $ext) @unlink($dir . '/custom.' . $e);
  clearstatcache(true, $f);
  return ['ok' => true, 'v' => (int)@filemtime($f), 'size' => strlen($bytes), 'width' => (int)$dim[0], 'height' => (int)$dim[1]];
}

function und_wall_remove() {
  foreach (UND_WALL_TYPES as $e => $m) @unlink(und_wall_custom_dir() . '/custom.' . $e);
  return ['ok' => true];
}

/** Sends the uploaded picture (GET /wallpaper on the standalone port, wall.php in the plugin): cached for good when the URL carries its version. */
function und_wall_serve() {
  $w = und_wall_custom();
  if (!$w) { http_response_code(404); header('Content-Type: text/plain'); echo "No wallpaper uploaded.\n"; return; }
  $etag = '"' . $w['v'] . '-' . $w['size'] . '"';
  header('Content-Type: ' . $w['mime']);
  header('X-Content-Type-Options: nosniff');
  header("Content-Security-Policy: default-src 'none'");
  header('ETag: ' . $etag);
  header('Cache-Control: ' . ((string)($_GET['v'] ?? '') === (string)$w['v'] ? 'private, max-age=31536000, immutable' : 'private, no-cache'));
  if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); return; }
  header('Content-Length: ' . $w['size']);
  readfile($w['file']);
}

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
