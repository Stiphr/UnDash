<?php
/* UnDash (Docker) - per-container CPU and memory while a dashboard is open (und_api_stats_read in unraid_api.php). Started
 * once by the entrypoint. The API runs `docker stats` for as long as it is listened to, so it is left alone when nobody is looking. */
if (!defined('UND_ROOT')) define('UND_ROOT', dirname(__DIR__));
require_once UND_ROOT . '/include/lib.php';
while (true) {
  clearstatcache();
  $ok = true;
  if (time() - (int)@filemtime(und_tmp('viewer')) < 60) $ok = und_api_stats_read() !== null;
  sleep($ok ? UND_STATS_EVERY : 4 * UND_STATS_EVERY);
}
