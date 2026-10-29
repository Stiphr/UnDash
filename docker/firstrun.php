<?php
/*
 * UnDash container first-run tasks (run by entrypoint.sh at every start, idempotent):
 *  - when /config has no settings yet and the plugin's settings folder is mounted at /import (read-only), copy
 *    config.yaml and .env from it (or the files of older versions, which are converted on first use). Nothing that
 *    already exists in /config is overwritten.
 *  - convert settings of older versions (config.json, secrets.json, ...) into config.yaml and .env.
 *  - print the one-time setup code when no password is configured yet.
 */
require_once '/app/include/lib.php';
require_once '/app/include/auth.php';
echo 'UnDash ' . UND_VERSION . " (Docker, Unraid API mode)\n";
if (!is_file('/config/config.yaml') && !is_file('/config/config.json') && is_dir('/import')) {
  $n = 0;
  $files = is_file('/import/config.yaml') ? ['config.yaml', '.env'] : ['config.json', 'secrets.json', 'custom.css', 'ignores.json'];
  foreach ($files as $f) if (is_file("/import/$f") && !is_file("/config/$f") && @copy("/import/$f", "/config/$f")) { $n++; if ($f === '.env' || $f === 'secrets.json') @chmod("/config/$f", 0600); }
  echo $n ? "Imported $n settings file(s) from /import (the plugin's settings). Unmount /import now if you like; it is only read once.\n" : "/import is mounted but holds no UnDash settings.\n";
}
if (is_file('/config/config.json') && !is_file('/config/config.yaml')) {
  und_load_config();
  if (is_file('/config/config.yaml')) echo "Settings converted to config.yaml and .env. The old files are kept in /config/data/legacy.\n";
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
