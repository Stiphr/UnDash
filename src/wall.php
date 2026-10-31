<?php
/*
 * UnDash (plugin) - the wallpaper uploaded in Settings > Appearance. Served by Unraid's nginx, so only a browser that is
 * logged in to Unraid reaches it. The standalone port serves the same picture at /wallpaper (standalone/router.php).
 */
require_once '/usr/local/emhttp/plugins/undash/include/lib.php';
und_wall_serve();
