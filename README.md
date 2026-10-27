# metaRechnung für Webspace

Schlanke, selbst gehostete Rechnungsverwaltung in PHP/MySQL für ganz normalen Webspace (kein Docker, kein SSH nötig).

- Kunden, Rechnungen mit Positionen, fortlaufende Nummern (`RE-2026-0001`), Entwürfe, Duplizieren, Kleinunternehmer-Modus
- **ZUGFeRD/Factur-X** (PDF/A-3 mit eingebetteter XML) und **XRechnung 3.0**, mit dem KoSIT-Validator geprüft
- **CSV-Import** bestehender Kundendaten (Vorschau, Spaltenzuordnung, Dublettenerkennung)
- **Stornorechnungen** (eigener Nummernkreis, Bezug auf die Originalrechnung, XML-Typ 384, Teilstorno)
- **PDF-Design**: Schrift, Akzentfarbe, Tabellenstil, Vorlagen und Vorschau; wird beim Ausstellen festgehalten
- **GiroCode** (EPC-QR) auf Rechnung und Statusseite: Banking-Apps füllen die Überweisung automatisch aus
- Rechnungsversand per Mail (SMTP oder `mail()`), Link zu einer öffentlichen **Statusseite** mit Zahlungsstatus und Downloads
- Briefpapier-Upload (PDF/PNG/JPG), Einstellungsseite, Testmail, Testbetrieb mit Reset
- **PWA** (installierbar), responsives Layout
- Einbenutzer-Anmeldung mit CSRF-Schutz, Brute-Force-Sperre, CSP – siehe [SECURITY.md](SECURITY.md)

## Installation (Endanwender)
Das fertige Paket `rechnung-webspace-<Version>.zip` aus den [Releases](../../releases) laden, entpacken, per FTP hochladen und `install.php` aufrufen.
Ausführlich: [INSTALLATION.md](INSTALLATION.md).

## Aus dem Quellcode
Der Quellcode enthält **kein** `vendor/`. Für ein lauffähiges Paket:

```bash
build/make-release.sh        # benötigt PHP ≥ 8.1, Composer, zip → dist/rechnung-webspace-<Version>.zip
```

Für die Entwicklung genügt `composer install` im Projektordner und ein lokaler PHP-Server mit MySQL (`install.php` im Browser aufrufen).

## Aufbau
| Pfad | Inhalt |
|---|---|
| `index.php`, `status.php` | Router (angemeldet) und öffentliche Statusseite |
| `cron.php` | Erzeugt fällige wiederkehrende Rechnungen (per Cronjob, CLI oder URL mit Token) |
| `src/` | Klassen (`Invoices`, `Einvoice`, `Mailer`, `Letterhead`, `RateLimit`, …) und Views |
| `install.php` | Einmal-Installer (löscht sich selbst) |
| `storage/` | Laufzeitdaten (PDFs, Briefpapier, Zähler) – nicht im Repository, per `.htaccess` gesperrt |
| `assets/css-editor.js` | CSS-Editor (CodeMirror 6, vorgebaut; Quelle und Build-Anleitung in `build/editor/`) |
| `tests/security/` | Dynamische Sicherheitstests (`sec1`–`sec7`, Python, laufende Test-Instanz) |
| `tests/unit/` | Logik-Tests ohne Datenbank (`php tests/unit/recurring_test.php`) |
| `tests/a11y/` | Barrierefreiheits-Prüfung mit axe-core (WCAG 2.2 AA) |
| `build/` | Paketbau |

## Wichtige Hinweise
- **Nie einchecken:** `config.php` (Passwort-Hash, Datenbank- und SMTP-Zugang) und `storage/` – `.gitignore` schützt davor.
- Die XRechnung-/ZUGFeRD-Ausgabe ersetzt keine steuerliche Beratung; Pflichtangaben (Steuernummer/USt-IdNr., IBAN, Kontakt) müssen in den Einstellungen stehen.
- Lizenz: **GNU General Public License, Version 2 oder (nach Wahl) jede spätere Version** (GPL-2.0-or-later), siehe [LICENSE](LICENSE).
  Das passt zu mPDF (GPL-2.0); Drittbibliotheken siehe [NOTICE.md](NOTICE.md).

## Changelog
[CHANGELOG.md](CHANGELOG.md)
