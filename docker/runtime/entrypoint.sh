#!/usr/bin/env bash
set -Eeuo pipefail

log() {
    printf '[barcodebuddy] %s\n' "$*"
}

validate_id() {
    local name="$1"
    local value="$2"

    if [[ ! "$value" =~ ^[0-9]+$ ]] || (( value < 1 || value > 65535 )); then
        printf '%s must be an integer between 1 and 65535\n' "$name" >&2
        exit 1
    fi
}

PUID="${PUID:-1000}"
PGID="${PGID:-1000}"

validate_id PUID "$PUID"
validate_id PGID "$PGID"

if [[ "$(id -g barcodebuddy)" != "$PGID" ]]; then
    groupmod -o -g "$PGID" barcodebuddy
fi

if [[ "$(id -u barcodebuddy)" != "$PUID" ]]; then
    usermod -o -u "$PUID" -g barcodebuddy barcodebuddy
fi

# /home/barcodebuddy must exist before the scanner service starts: the scan
# dispatch runs "sudo -H -u barcodebuddy screen -dm php index.php" and screen 5
# keeps its session sockets in $HOME/.screen.
install -d -m 0755 -o barcodebuddy -g barcodebuddy \
    /config /config/data /run/php /home/barcodebuddy
chown -R barcodebuddy:barcodebuddy /config

if [[ -n "${TZ:-}" ]]; then
    if [[ ! "$TZ" =~ ^[A-Za-z0-9._+-]+(/[A-Za-z0-9._+-]+)*$ ]]; then
        printf 'TZ contains unsupported characters: %s\n' "$TZ" >&2
        exit 1
    fi
    printf 'date.timezone = "%s"\n' "$TZ" > /etc/php83/conf.d/98-timezone.ini
    log "Timezone set to $TZ"
fi

if [[ "${IGNORE_SSL_CA:-false}" == "true" ]]; then
    sed -i 's/const CURL_ALLOW_INSECURE_SSL_CA.*/const CURL_ALLOW_INSECURE_SSL_CA=true;/g' /app/bbuddy/config-dist.php
    log "SSL CA verification disabled by IGNORE_SSL_CA"
fi

if [[ "${IGNORE_SSL_HOST:-false}" == "true" ]]; then
    sed -i 's/const CURL_ALLOW_INSECURE_SSL_HOST.*/const CURL_ALLOW_INSECURE_SSL_HOST=true;/g' /app/bbuddy/config-dist.php
    log "SSL hostname verification disabled by IGNORE_SSL_HOST"
fi

if [[ "${ATTACH_BARCODESCANNER:-false}" == "true" ]]; then
    cp /usr/local/share/barcodebuddy/scanner.conf /etc/supervisor.d/barcode-scanner.ini
    log "Barcode scanner input enabled"
elif [[ -e /etc/supervisor.d/barcode-scanner.ini ]]; then
    unlink /etc/supervisor.d/barcode-scanner.ini
fi

exec "$@"
