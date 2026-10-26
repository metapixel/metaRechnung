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
# Nur nötige mPDF-Schriften (DejaVu Sans Condensed) und Wörterbücher für Thai/Khmer/Lao entfernen
find vendor/mpdf/mpdf/ttfonts -type f ! -name 'DejaVuSansCondensed*' ! -name '.htaccess' -delete
rm -f vendor/mpdf/mpdf/data/linebrdict{K,T,L}.dat
# ZUGFeRD: nur Profil EN 16931 (auch für XRechnung) bleibt; Schematron/XSLT/Schemas dienen nur der Validierung, die die App nicht nutzt
Z=vendor/horstoeko/zugferd
rm -rf "$Z/make" "$Z/build" "$Z/src/schema/xslt" "$Z/src/schema/schematron"
for p in basic basicwl extended minimum; do rm -rf "$Z/src/entities/$p" "$Z/src/yaml/$p" "$Z/src/validation/$p"; done
rm -f "$Z"/src/schema/FACTUR-X_{BASIC,BASIC-WL,EXTENDED,MINIMUM}*.xsd
# QR-Code (GiroCode): ein EPC-Code hat höchstens 331 Byte (= Version 14, Stufe M); Daten größerer Versionen entfallen
for v in $(seq 15 40); do rm -f vendor/mpdf/qrcode/data/modele$v.dat vendor/mpdf/qrcode/data/qrv${v}_*.dat; done
# Übersetzungen des Symfony-Validators (die App nutzt ihn nicht direkt)
rm -rf vendor/symfony/validator/Resources/translations
# Rauchtest: XML (EN16931 + XRechnung), PDF/A-3, Mailer, FPDI müssen nach dem Verschlanken funktionieren
php build/smoke.php vendor
rm -f vendor.zip; zip -qr9 vendor.zip vendor
cp vendor.zip .build/vendor.zip

FILES=(index.php status.php cron.php install.php app.js sw.js style.css assets offline.html manifest.webmanifest icons src VERSION CHANGELOG.md INSTALLATION.md SECURITY.md README.md LICENSE NOTICE.md .htaccess composer.json)
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
