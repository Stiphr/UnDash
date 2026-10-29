<?php
/* Runs standalone/router.php inside a sandbox for tests/router_test.php (php -S 127.0.0.1:PORT tests/router_harness.php). */
$SB = getenv('UND_SANDBOX');
define('UND_DIR', "$SB/flash");
define('UND_TMP', "$SB/tmp");
define('UND_ROOT', dirname(__DIR__) . '/src');
$GLOBALS['UND_FS'] = "$SB/fs";
$GLOBALS['UND_PHPSESS_DIR'] = "$SB/php";
$GLOBALS['UND_EXEC_MOCK'] = function ($argv) { return ['code' => 1, 'err' => 'not available in tests']; };
require UND_ROOT . '/standalone/router.php';
