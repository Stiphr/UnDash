#!/usr/bin/php
<?php
/* UnDash - background Unraid OS update check (started by the API when the cached result is older than the configured interval). */
require_once '/usr/local/emhttp/plugins/undash/include/lib.php';
$r = und_os_check();
@unlink(und_tmp('os-check.lock'));
echo $r['available'] ? 'Unraid ' . $r['latest'] . " is available\n" : ($r['error'] ?: 'Unraid ' . $r['installed'] . " is up to date\n");
