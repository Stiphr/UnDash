<?php
/* UnDash (Docker) - keeps the Unraid API's slow reads fresh while the dashboard is open (UND_API_SLOW in unraid_api.php).
 * Started once by the entrypoint. It only asks the API while a page has been asking for data in the last minute. */
if (!defined('UND_ROOT')) define('UND_ROOT', dirname(__DIR__));
require_once UND_ROOT . '/include/lib.php';
while (true) {
  @touch(und_tmp('apipoll.beat'));
  clearstatcache();
  if (time() - (int)@filemtime(und_tmp('viewer')) < 60) und_api_poll_due();
  sleep(1);
}
