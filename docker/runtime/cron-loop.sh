#!/bin/sh
set -u

while :; do
    /usr/bin/php83 /app/bbuddy/cron.php || printf '%s\n' '[barcodebuddy] cron.php failed' >&2
    sleep 120
done
