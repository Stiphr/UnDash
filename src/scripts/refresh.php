<?php
/* UnDash - rereads an app whose numbers are slow to work out, in the background, so no page waits for it (see und_w_prowlarr). */
if (!defined('UND_ROOT')) define('UND_ROOT', dirname(__DIR__));
require_once UND_ROOT . '/include/lib.php';
$id = (string)($argv[1] ?? '');
if (!preg_match('/^[a-f0-9]{8}$/', $id)) exit(1);
$GLOBALS['UND_FRESH'] = true;
und_fetch_service(und_load_config(), $id);
