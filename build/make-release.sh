#!/usr/bin/env bash
# Baut die Installationspakete (vendor.zip + Voll- und Update-Paket) – benötigt PHP 8.1+, Composer, zip.
# Aufruf:  build/make-release.sh        → Ergebnis in dist/
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(tr -d '[:space:]' < VERSION)
OUT=dist; rm -rf "$OUT" .build; mkdir -p "$OUT" .build

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
composer audit --no-dev || { echo "composer audit meldet Sicherheitslücken – Release abgebrochen"; exit 1; }

# vendor/ verschlanken: Tests, Doku, Git-Reste, nicht benötigte mPDF-Schriften (nur DejaVu bleibt)
find vendor -type d \( -iname tests -o -iname test -o -iname docs -o -iname doc -o -iname examples -o -name .git \) -prune -exec rm -rf {} +
find vendor -type f \( -name '*.md' -o -name 'phpunit*' -o -name '.gitattributes' -o -name '.gitignore' -o -name '*.dist' \) -delete
if [ -d vendor/mpdf/mpdf/ttfonts ]; then
  find vendor/mpdf/mpdf/ttfonts -type f ! -name 'DejaVu*' ! -name '.htaccess' -delete
fi
# Rauchtest: alle benötigten Klassen müssen nach dem Verschlanken noch ladbar sein
php -r 'require "vendor/autoload.php"; foreach (["horstoeko\\zugferd\\ZugferdDocumentBuilder","Mpdf\\Mpdf","PHPMailer\\PHPMailer\\PHPMailer","setasign\\Fpdi\\Fpdi","Symfony\\Component\\Validator\\Validation"] as $c) { if (!class_exists($c)) { fwrite(STDERR, "Klasse fehlt: $c\n"); exit(1); } } echo "Rauchtest ok\n";'
rm -f vendor.zip; zip -qr9 vendor.zip vendor
cp vendor.zip .build/vendor.zip

FILES=(index.php status.php install.php app.js sw.js style.css offline.html manifest.webmanifest icons src VERSION CHANGELOG.md INSTALLATION.md SECURITY.md README.md .htaccess composer.json)
mkdir -p .build/full .build/update
for d in full update; do
  cp -a "${FILES[@]}" ".build/$d/"
  mkdir -p ".build/$d/storage"; cp storage/.htaccess storage/index.html ".build/$d/storage/"
done
cp vendor.zip .build/full/vendor.zip
cp -a vendor .build/update/vendor    # Update-Paket enthält vendor/ als Ordner (alten Ordner vorher löschen)
rm .build/update/install.php .build/update/storage -rf
(cd .build/full   && zip -qr9 "../../$OUT/rechnung-webspace-$VERSION.zip" .)
(cd .build/update && zip -qr9 "../../$OUT/rechnung-webspace-update-$VERSION.zip" .)
rm -rf .build vendor.zip
( cd "$OUT" && sha256sum *.zip > SHA256SUMS )
echo "Fertig:"; ls -la "$OUT"
