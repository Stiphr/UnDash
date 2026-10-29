#!/bin/sh
# UnDash container start: check /config, first-run tasks, then serve the dashboard on UNDASH_PORT.
set -e
PORT="${UNDASH_PORT:-9099}"
case "$PORT" in ''|*[!0-9]*) echo "UNDASH_PORT must be a number" >&2; exit 1 ;; esac

if ! touch /config/.write-test 2>/dev/null; then
  echo "UnDash: /config is not writable by uid $(id -u). On Unraid: chown -R 99:100 the appdata folder you mapped to /config." >&2
  exit 1
fi
rm -f /config/.write-test

# copies plugin settings on the very first start (if mounted), creates the setup code, prints what to do next
php -d auto_prepend_file= /usr/local/bin/firstrun.php

# the alert history: one check a minute in the background
( while true; do php -d auto_prepend_file= -d display_errors=0 -d log_errors=1 -d error_log=/dev/stderr /app/scripts/alertcheck.php; sleep 60; done ) &

echo "UnDash listening on port $PORT (inside the container)"
# PHP's built-in server prints every connection; only errors and UnDash's own messages are kept
php -d auto_prepend_file= -d expose_php=0 -d display_errors=0 -d log_errors=1 -d error_log=/dev/stderr \
  -S "0.0.0.0:$PORT" -t /app/standalone /app/standalone/router.php 2>&1 | grep -vE '(Accepted|Closing)$|\[[0-9]+\]: [A-Z]+ /'
