<?php
/*
 * UnDash - sign-in handoff to the standalone port. This file is served by Unraid's nginx, so only a browser that is
 * logged in to Unraid reaches it. It creates a one-time token (valid 60 s) and sends the browser to the standalone port
 * on the same host name, where the token is swapped for an UnDash session.
 */
require_once '/usr/local/emhttp/plugins/undash/include/lib.php';
require_once '/usr/local/emhttp/plugins/undash/include/auth.php';
$loc = und_loc();
$host = und_req_host();
header('Cache-Control: no-store');
if ($loc['STANDALONE'] !== 'yes' || $host === '') { header('Location: /UnDash', true, 303); exit; }
header('Location: http://' . $host . ':' . (int)$loc['PORT'] . '/?t=' . und_handoff_create(), true, 303);
