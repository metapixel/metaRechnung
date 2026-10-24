# Änderungsprotokoll

Die aktuelle Versionsnummer steht in der Datei `VERSION` (und unten in der App sowie unter Einstellungen → System).

## 1.3.0
- Neues Erscheinungsbild, rein mit CSS und inline-SVG (keine Bibliotheken, keine externen Schriften/Skripte): Farbverlauf-Akzent, Karten mit Schatten,
  Pillen-Navigation mit Symbolen, abgesetzte Tabellenköpfe, farbige Status-Badges, Fokus-Ringe, weicher Dunkelmodus, mobiles Layout.
- Name der App durchgehend **metaRechnung** (Seitentitel, Logo, PWA-Name, Offline-Seite, Installer, Testmail, Dokumentation); neues Logo als SVG (`icons/icon.svg`) und neu erzeugte PWA-Icons.
- Versionshinweis in einer Fußleiste am unteren Fensterrand (nur für angemeldete Nutzer mit Version, auf Anmelde- und Statusseite nur der Name).

## 1.2.2
- Lizenz festgelegt: GPL-2.0-or-later (`LICENSE`, `NOTICE.md` mit Drittbibliotheken, Lizenzkopf in den PHP-Dateien).
- CI: Semgrep-Treffer geprüft (alles Fehlalarme: Ausgabe erzeugter PDF/XML-Dateien, Löschen serverseitig gebildeter Pfade) und mit Begründung markiert; Actions auf checkout@v5 und ubuntu-24.04.
- Repository bereinigt: Testdaten in `tests/security/` enthalten nur noch erfundene Beispieldaten; keine persönlichen Angaben im Quellcode.

## 1.2.1 – Sicherheits-Update
Ergebnis eines umfangreichen Sicherheits-Audits (statische Analyse mit Psalm-Taint-Analyse und Semgrep, Abgleich der Abhängigkeiten mit der
PHP-Advisory-Datenbank, 92 dynamische Angriffstests gegen eine laufende Instanz). Details: `SECURITY.md`.
- **CSRF**: Das Formular-Token wird jetzt für jede Sitzung sofort erzeugt und darf nie leer sein (vorher konnte ein POST ohne Token durchgehen,
  solange die Sitzung noch kein Token hatte). Auch Anmeldung und Abmelden sind CSRF-geschützt (Abmelden nur noch per POST).
- **Brute-Force-Schutz**: Fehlversuche bei Anmeldung, Passwortwechsel und Reset werden je IP (und insgesamt) gezählt und danach für 15 Minuten gesperrt;
  Token-Raten auf der Statusseite wird nach 30 Fehlversuchen gebremst (HTTP 429).
- **Sitzungen**: Nach Passwort-/Benutzerwechsel werden alle anderen Sitzungen ungültig; Sitzungen laufen nach 12 Stunden ab; Strict-Mode für Session-IDs.
- **Open Redirect / Header-Injection**: Weiterleitungen gehen nur noch auf interne Pfade (`//evil.example` oder Zeilenumbrüche werden verworfen).
- **Host-Header-Manipulation**: Der Installer trägt die App-Adresse (`APP_URL`) fest ein; ohne `APP_URL` wird der Host-Header validiert.
- **Content-Security-Policy** (`script-src 'self'`), `Permissions-Policy`, `Referrer-Policy: no-referrer` auf der Statusseite; keine Inline-Skripte mehr.
- **Eingabevalidierung**: Längenlimits, E-Mail/Land/USt-IdNr. beim Kunden; Mengen, Preise und MwSt-Sätze streng geprüft (keine negativen Mengen,
  keine Exponentialschreibweise, MwSt 0–30 %); Datumsfelder; SMTP-Host/-Port, Zahlungsziel, Rechnungspräfix, App-Adresse; Steuerzeichen werden entfernt.
- **.htaccess**: sperrt zusätzlich `VERSION`, `*.md`, `*.log`, versteckte Dateien sowie `vendor/`, `src/`, `storage/` (per mod_rewrite) und TRACE/TRACK.
- **Abhängigkeiten**: `symfony/validator` 6.2.13 → 6.4.48 (CVE-2024-50343), neu `symfony/polyfill-php83`.

## 1.2.0
- Versionsnummer im Paket (`VERSION`, Anzeige in der App, Dateiname der ZIPs, dieses Änderungsprotokoll).
- Testbetrieb: Option `'TEST_MODE' => '1'` in `config.php` schaltet in den Einstellungen einen Reset frei
  (löscht Rechnungen + PDFs, optional Kunden; Nummerierung beginnt wieder bei 1) und zeigt ein Banner „TESTBETRIEB“.
  Der Reset verlangt Passwort und die Eingabe von RESET. Der Installer hat dafür eine Checkbox (Standard: aus).
- Briefpapier: Upload (PDF, PNG, JPG; A4) in den Einstellungen mit einstellbaren Seitenrändern, optionalem Ausblenden von Absenderblock und Fußzeile, Vorschau mit Beispielrechnung und Entfernen-Funktion.

## 1.1.0
- Einstellungsseite (Firmendaten, Rechnungen, Mail/SMTP, App-Adresse, Benutzername/Passwort, Testmail).
- Öffentliche Statusseite je Rechnung (geheimer Link in der Rechnungsmail): Zahlungsstatus, Download PDF und XRechnung.
- XRechnung 3.0 (CII), mit dem KoSIT-Validator geprüft; Käuferreferenz/Leitweg-ID am Kunden.
- Rechnungen duplizieren; Zahlungsdatum beim Bezahlt-Markieren; Zahlung zurücknehmen.
- Snapshot: Ausgestellte Rechnungen behalten die Firmendaten vom Ausstellungszeitpunkt.
- Automatische Datenbank-Migration für Bestandsinstallationen.

## 1.0.0
- Erste Webspace-Version mit Installer: Kunden, Rechnungsentwürfe, Ausstellen mit ZUGFeRD (PDF/A-3, EN 16931), Mailversand, PWA.
