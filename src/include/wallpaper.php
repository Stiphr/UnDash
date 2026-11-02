<?php
/*
 * UnDash - the wallpaper you add yourself: uploaded in Settings, or a picture file on the server named by its path.
 */

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
function und_wall_custom_view() {
  $w = und_wall_custom(); $l = und_wall_local((string)(und_load_config()['appearance']['wall_path'] ?? ''));
  return ($w ? ['set' => true, 'v' => $w['v'], 'size' => $w['size']] : ['set' => false, 'v' => 0, 'size' => 0]) + ['local' => isset($l['error']) ? 0 : $l['v']];
}

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

/* ---------- a picture named by its path: only inside these folders, and only a real picture, so the setting cannot read other files ---------- */
const UND_WALL_LOCAL_MAX = 30 * 1048576;

function und_wall_roots() {
  $r = [UND_DIR, '/wallpapers'];
  return array_values(array_filter(array_map('realpath', $r)));
}

/** ['file', 'ext', 'mime', 'v', 'size'], or ['error' => ...]. In the Docker version an appdata path on the server means the same file under /config. */
function und_wall_local($path) {
  $path = trim((string)$path);
  if ($path === '') return ['error' => 'Type the path of a picture.'];
  $path = preg_replace('#^/mnt/[^/]+/appdata/[^/]+/#', rtrim(UND_DIR, '/') . '/', $path);
  $outside = 'UnDash can only read its own appdata folder, or a folder mapped to /wallpapers in the container\'s settings.';
  $real = realpath($path);
  if ($real === false || !is_file($real)) return ['error' => 'No file at that path. ' . $outside];
  $inside = false;
  foreach (und_wall_roots() as $root) if (strpos($real, rtrim($root, '/') . '/') === 0) $inside = true;
  if (!$inside) return ['error' => $outside];
  $size = (int)@filesize($real);
  if ($size > UND_WALL_LOCAL_MAX) return ['error' => 'That picture is over 30 MB.'];
  $ext = und_wall_type((string)@file_get_contents($real, false, null, 0, 16));
  if (!$ext) return ['error' => 'That file is not a JPEG, PNG or WebP picture.'];
  return ['file' => $real, 'ext' => $ext, 'mime' => UND_WALL_TYPES[$ext], 'v' => (int)@filemtime($real), 'size' => $size];
}

/** Sends the uploaded picture (GET /wallpaper on the standalone port, wall.php in the plugin): cached for good when the URL carries its version. */
function und_wall_serve() {
  // the path comes from the saved settings, never from the request
  $w = isset($_GET['local']) ? und_wall_local((string)(und_load_config()['appearance']['wall_path'] ?? '')) : und_wall_custom();
  if (!$w || isset($w['error'])) { http_response_code(404); header('Content-Type: text/plain'); echo "No wallpaper.\n"; return; }
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
