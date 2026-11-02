<?php
/* UnDash (Docker) - keeps the Unraid API's slow reads fresh while the dashboard is open (UND_API_SLOW in unraid_api.php).
 * Started once by the entrypoint. With no page open in the last minute it asks far less often (UND_API_IDLE), so the next page still opens at once. */
if (!defined('UND_ROOT')) define('UND_ROOT', dirname(__DIR__));
require_once UND_ROOT . '/include/lib.php';
while (true) {
  @touch(und_tmp('apipoll.beat'));
  clearstatcache();
  und_api_poll_due(time() - (int)@filemtime(und_tmp('viewer')) < 60);
  sleep(1);
}
