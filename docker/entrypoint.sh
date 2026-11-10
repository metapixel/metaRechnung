#!/bin/sh
# Entrypoint von metaRechnung. Befehle: web (Standard) | cron | install | <beliebiger Befehl>
set -eu

export METARECHNUNG_CONFIG="${METARECHNUNG_CONFIG:-/data/config.php}"

prepare() {
    mkdir -p /data/storage/invoices /data/storage/offers /data/storage/tmp
    if [ ! -w /data ] || [ ! -w /data/storage ]; then
        echo "metaRechnung: /data ist für den Container-Benutzer (UID $(id -u)) nicht beschreibbar." >&2
        echo "  Im Compose-Setup passiert das nicht; bei eigenem Bind-Mount: chown -R $(id -u) <Ordner>" >&2
        exit 1
    fi
    if [ ! -f "$METARECHNUNG_CONFIG" ]; then
        php /app/docker/install-cli.php
    fi
}

cmd="${1:-web}"
case "$cmd" in
    web)
        prepare
        php-fpm -D
        exec nginx -g 'daemon off;'
        ;;
    cron)
        # Ersetzt den Cronjob: erzeugt fällige wiederkehrende Rechnungen (Intervall in Sekunden, Standard 15 Minuten)
        # Die Erst-Einrichtung übernimmt der web-Container; hier nur auf die config.php warten
        until [ -f "$METARECHNUNG_CONFIG" ]; do sleep 5; done
        interval="${CRON_INTERVAL:-900}"
        while true; do
            php /app/cron.php || echo "metaRechnung: cron.php endete mit Fehler" >&2
            sleep "$interval"
        done
        ;;
    install)
        prepare
        ;;
    *)
        exec "$@"
        ;;
esac
