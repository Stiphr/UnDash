<?php
/*
 * UnDash container first-run tasks (run by entrypoint.sh at every start, idempotent):
 *  - when /config has no settings yet and the plugin's settings folder is mounted at /import (read-only), copy
 *    config.json, secrets.json and custom.css from it. Nothing that already exists in /config is overwritten.
 *  - print the one-time setup code when no password is configured yet.
 */
require_once '/app/include/lib.php';
require_once '/app/include/auth.php';
echo 'UnDash ' . UND_VERSION . " (Docker, Unraid API mode)\n";
if (!is_file('/config/config.json') && is_dir('/import')) {
  $n = 0;
  foreach (['config.json', 'secrets.json', 'custom.css'] as $f) if (is_file("/import/$f") && !is_file("/config/$f") && @copy("/import/$f", "/config/$f")) { $n++; if ($f === 'secrets.json') @chmod("/config/$f", 0600); }
  echo $n ? "Imported $n settings file(s) from /import (the plugin's settings). Unmount /import now if you like; it is only read once.\n" : "/import is mounted but holds no UnDash settings.\n";
}
$c = und_api_conf();
if ($c['url'] === '' || $c['key'] === '') echo "The Unraid API is not configured yet: set UNRAID_API_URL and UNRAID_API_KEY, or enter them in Settings > Connection.\n";
$code = und_setup_code_ensure();
if ($code !== null) {
  echo "------------------------------------------------------------\n";
  echo " No password is set yet. Open UnDash in a browser and use\n";
  echo " this one-time setup code:  $code\n";
  echo " (or set UNDASH_PASSWORD in the container settings)\n";
  echo "------------------------------------------------------------\n";
}
