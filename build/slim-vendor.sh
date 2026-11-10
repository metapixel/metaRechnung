#!/usr/bin/env bash
# Verschlankt vendor/ (gemeinsam genutzt von make-release.sh und dem Docker-Build). Aufruf im Projektordner: build/slim-vendor.sh
set -euo pipefail
cd "$(dirname "$0")/.."
# vendor/ verschlanken: Tests, Doku, Git-Reste, nicht benötigte mPDF-Schriften (nur DejaVu bleibt)
find vendor -type d \( -iname tests -o -iname test -o -iname docs -o -iname doc -o -iname examples -o -name .git \) -prune -exec rm -rf {} +
find vendor -type f \( -name '*.md' -o -name 'phpunit*' -o -name '.gitattributes' -o -name '.gitignore' -o -name '*.dist' \) -delete
# Nur nötige mPDF-Schriften (DejaVu Sans, Sans Condensed, Serif Condensed) und Wörterbücher für Thai/Khmer/Lao entfernen
find vendor/mpdf/mpdf/ttfonts -type f ! -name 'DejaVuSans.ttf' ! -name 'DejaVuSans-*' ! -name 'DejaVuSansCondensed*' ! -name 'DejaVuSerifCondensed*' ! -name '.htaccess' -delete
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
