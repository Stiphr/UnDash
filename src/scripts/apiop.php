<?php
/* UnDash (Docker version) - a long Unraid API operation in the background: php apiop.php update <container id>. Output goes to the job log. */
require_once dirname(__DIR__) . '/include/lib.php';
if (($argv[1] ?? '') !== 'update' || !preg_match('/^[A-Za-z0-9:_.-]{1,200}$/', (string)($argv[2] ?? ''))) { fwrite(STDERR, "usage: apiop.php update <id>\n"); exit(2); }
echo "Asking Unraid to pull the new image and recreate the container…\n";
$r = und_gql('mutation ($id: PrefixedID!) { docker { updateContainer(id: $id) { id state status } } }', ['id' => $argv[2]], 1800);
if (isset($r['error'])) { echo 'error: ' . $r['error'] . "\n"; exit(1); }
$c = $r['data']['docker']['updateContainer'] ?? [];
echo 'Done. The container is ' . strtolower((string)($c['state'] ?? 'unknown')) . ' (' . ($c['status'] ?? '') . ").\n";
exit(0);
