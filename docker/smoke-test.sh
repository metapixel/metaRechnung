#!/usr/bin/env bash
# Rauchtest für das Docker-Setup (läuft in CI, lokal: docker build -t ghcr.io/metapixel/metarechnung:ci . && docker/smoke-test.sh)
set -euo pipefail
cd "$(dirname "$0")/.."
export METARECHNUNG_TAG=ci HTTP_PORT=18080 HTTP_BIND=127.0.0.1 COMPOSE_PROJECT_NAME=mrtest
TMP=$(mktemp -d); trap 'docker compose --env-file "$TMP/env" down -v >/dev/null 2>&1 || true; rm -rf "$TMP"' EXIT
cat > "$TMP/env" <<ENV
DB_PASS=$(head -c 24 /dev/urandom | base64 | tr -dc A-Za-z0-9)
ADMIN_USER=admin
ADMIN_PASSWORD=ein-langes-testpasswort
COMPANY_NAME=Testfirma
COMPANY_STREET=Teststr. 1
COMPANY_ZIP=12345
COMPANY_CITY=Teststadt
APP_URL=http://localhost:18080
TRUSTED_PROXIES=
ENV
cp "$TMP/env" .env.test-ci
DC=(docker compose --env-file .env.test-ci)
trap '"${DC[@]}" down -v >/dev/null 2>&1 || true; rm -rf "$TMP" .env.test-ci' EXIT
# compose liest env_file ".env" – für den Test darauf zeigen lassen
cp .env.test-ci .env
trap '"${DC[@]}" down -v >/dev/null 2>&1 || true; rm -rf "$TMP" .env.test-ci .env' EXIT

fail() { echo "FEHLER: $*" >&2; "${DC[@]}" logs --no-color | tail -60 >&2; exit 1; }
code() { curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:18080$1"; }

"${DC[@]}" up -d --wait --wait-timeout 180 || fail "Start/Healthcheck"
echo "✔ Container laufen und sind gesund"

body=$(curl -sL http://127.0.0.1:18080/)
echo "$body" | grep -qi 'metaRechnung' || fail "Anmeldeseite nicht erreichbar"
echo "✔ Anmeldeseite wird ausgeliefert"

for p in /style.css /app.js /manifest.webmanifest; do
  [ "$(code $p)" = 200 ] || fail "$p sollte 200 liefern"
done
for p in /style.css /; do
  curl -sIL "http://127.0.0.1:18080$p" | grep -qi '^x-frame-options: SAMEORIGIN' || fail "Sicherheits-Header fehlen bei $p"
done
echo "✔ statische Dateien erreichbar, Sicherheits-Header gesetzt"

for p in /config.php /src/Config.php /src/ /storage/ /vendor/autoload.php /install.php /docker/entrypoint.sh /VERSION /CHANGELOG.md /.env /Dockerfile /composer.json /cron.php.bak /data/config.php; do
  c=$(code $p); [ "$c" = 404 ] || fail "$p sollte 404 liefern, lieferte $c"
done
echo "✔ interne Dateien sind gesperrt"

[ "$(code '/cron.php')" = 403 ] || fail "cron.php ohne Token sollte 403 liefern"
echo "✔ cron.php verlangt ein Token"

for m in gd mbstring pdo_mysql zip dom SimpleXML; do
  "${DC[@]}" exec -T app php -m | grep -qix "$m" || fail "PHP-Erweiterung $m fehlt"
done
echo "✔ PHP-Erweiterungen vorhanden"

"${DC[@]}" exec -T app php /app/cron.php >/dev/null || fail "cron.php (CLI) schlug fehl"
"${DC[@]}" exec -T app test -f /data/config.php || fail "config.php liegt nicht im Volume"
[ "$("${DC[@]}" exec -T app stat -c %a /data/config.php | tr -d '\r')" = 640 ] || fail "config.php sollte Modus 640 haben"
"${DC[@]}" ps --status running --services | grep -qx cron || fail "cron-Container läuft nicht"
echo "✔ Cron und Konfiguration im Volume"

"${DC[@]}" restart app >/dev/null
for i in $(seq 1 30); do [ "$(code /)" = 200 ] || [ "$(code /)" = 302 ] && break; sleep 2; done
[ "$("${DC[@]}" logs --no-color app | grep -c 'Erst-Einrichtung abgeschlossen')" = 1 ] || fail "Erst-Einrichtung lief mehrfach"
echo "✔ Neustart behält die Konfiguration"
echo "Alle Docker-Tests bestanden."
