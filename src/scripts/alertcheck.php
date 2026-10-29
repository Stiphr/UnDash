<?php
/* UnDash - one alert check for the history (run every minute by rc.undash in the plugin, by the entrypoint in Docker). */
if (!defined('UND_ROOT')) define('UND_ROOT', dirname(__DIR__));
require_once UND_ROOT . '/include/lib.php';
und_alerts_check();
