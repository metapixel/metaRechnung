# Änderungsprotokoll

Die aktuelle Versionsnummer steht in der Datei `VERSION` (und unten in der App sowie unter Einstellungen → System).

## 1.6.0
- **CSS-Editor für die Oberfläche** (Einstellungen → „CSS-Editor öffnen“): CodeMirror 6 (nur CSS-Modus, lokal ausgeliefert, ~340 KB, wird nur auf dieser Seite geladen) mit Syntaxhervorhebung,
  Zeilennummern und **Live-Vorschau** direkt auf der Seite. „Vorlage einfügen“ zeigt die verfügbaren Design-Variablen (`--pri`, `--bg`, `--card`, `--r` …, auch für den dunklen Modus).
  Gespeichert wird in `storage/custom.css`; das CSS gilt für alle Seiten inkl. Login und Kunden-Statusseite. Zurücksetzen auf das Standard-Design jederzeit möglich.
- **Sicherheit/Bedienung:** Prüfung beim Speichern (kein `<`, kein `@import`/`expression`/`javascript:`, ausgeglichene Klammern, max. 50 KB), Content-Security-Policy unverändert,
  Notausgang `?nocss=1`. Tab verlässt den Editor (keine Tastaturfalle), Editor ist beschriftet und besteht die axe-Prüfung.
- Der Editor lässt sich mit `cd build/editor && npm install && npm run build` neu bauen. Neue Tests `sec7`.

## 1.5.4
- **Schlankeres `vendor/`**: Das Release-Skript entfernt nicht benötigte Teile der Bibliotheken – ungenutzte ZUGFeRD-Profile samt Schematron/XSLT-Validierungsdateien, nicht verwendete mPDF-Schriften und
  Thai/Khmer-Wörterbücher, Validator-Übersetzungen. Ergebnis: **20 MB statt 37 MB** auf dem Server, `vendor.zip` **4,5 MB statt 8 MB**; Funktionsumfang unverändert.
  Ein Rauchtest (`build/smoke.php`) erzeugt dabei echte XML-Dateien (EN 16931 und XRechnung), ein PDF/A-3 und prüft Mailer/FPDI; alle Sicherheitstests und der KoSIT-Validator laufen gegen die verschlankte Fassung.

## 1.5.3
- **PDF-Vorschau für Entwürfe**: In der Entwurfsansicht („PDF-Vorschau“, öffnet in neuem Tab) und im Bearbeiten-Formular („Speichern und PDF-Vorschau“) lässt sich die Rechnung vor dem Ausstellen
  so ansehen, wie sie als PDF aussehen wird (mit Logo/Briefpapier, Notizen, Summen; Nummer „ENTWURF“). Die Vorschau wird nur erzeugt und ausgeliefert – es wird nichts gespeichert und keine Rechnungsnummer vergeben.

## 1.5.2
- **Notizfeld für Artikel und Positionen**: Artikel haben eine optionale Notiz (Artikelpflege); im Rechnungsentwurf gibt es je Position ein Notizfeld, das beim Einfügen eines Artikels vorbelegt wird.
  Die Notiz steht unter der Beschreibung in der Rechnungsansicht, im PDF und als Artikelbeschreibung in ZUGFeRD/XRechnung (KoSIT-Validator: akzeptiert). Beim Duplizieren wird sie übernommen.
  In wiederkehrenden Rechnungen gelten die Platzhalter (`{ZEITRAUM}` …) auch in der Notiz; unbekannte Platzhalter werden abgelehnt. Datenbank-Migration v5 läuft automatisch.
- **Kein Cache-Leeren mehr nach Updates**: CSS/JS/Service-Worker werden mit Versions- und Änderungsstempel eingebunden (`app.js?v=…`), der Server verlangt für JS/CSS eine Prüfung bei jedem Aufruf
  (`Cache-Control: no-cache`, per ETag billig), und der Service Worker ignoriert den Stempel beim Offline-Cache. Ein normales Neuladen genügt.
- Installer schreibt die Schema-Version jetzt korrekt (5).

## 1.5.1
- **Fix: „Artikel einfügen“ tat nach einem Update nichts.** Der Service Worker lieferte die App-Dateien (`app.js`, `style.css`) immer aus dem Cache, sodass nach einem Update
  weiter das alte Skript lief. Jetzt wird zuerst das Netzwerk gefragt (Cache nur noch offline) und der Cache neu benannt; nach dem Update gilt das sofort.
  Falls es bei dir noch hakt: Seite einmal mit Strg+F5 neu laden.

## 1.5.0
- **Wiederkehrende Rechnungen** (Menü „Wiederkehrend“): Vorlagen mit Kunde, Positionen, Intervall (monatlich, vierteljährlich, jährlich), Startdatum und optionalem Ende.
  `cron.php` erzeugt fällige Rechnungen – wahlweise als Entwurf (Standard), automatisch ausgestellt oder ausgestellt und per E-Mail versendet.
  Aufruf per Kommandozeile oder per URL mit geheimem Token (erneuerbar/abschaltbar; Fehlversuche werden gebremst).
  Ein Zeitraum wird nie doppelt abgerechnet (Transaktion mit Zeilensperre, auch bei gleichzeitigen Aufrufen); versäumte Läufe werden nachgeholt (höchstens 12 je Aufruf).
- **Platzhalter** in Positionen, Hinweistext und Mailtext: `{ZEITRAUM}`, `{ZEITRAUM_VON}`, `{ZEITRAUM_BIS}`, `{MONAT}`, `{MONAT_KURZ}`, `{MONAT_NR}`, `{QUARTAL}`, `{JAHR}`, `{INTERVALL}`, `{DATUM}`
  (im Mailtext zusätzlich `{NUMMER}`, `{BETRAG}`, `{FAELLIG}`, `{FIRMA}`, `{STATUSLINK}`). Abrechnungszeitraum wählbar: zurückliegend, laufend oder kommend. Unbekannte Platzhalter werden beim Speichern abgelehnt.
- **Logo** als Alternative zum Briefpapier (Einstellungen → Logo): PNG/JPG, wird neu kodiert (keine Metadaten, keine Transparenz), Breite und Ausrichtung einstellbar.
- **Barrierefreiheit** (WCAG 2.2 AA, geprüft mit axe-core in Hell- und Dunkelmodus): Skip-Link, aussagekräftige Seitentitel, Landmarken, Tabellenüberschriften mit `scope` und Beschriftungen,
  Labels für alle Positionsfelder, `role="alert"` für Fehlermeldungen, Live-Ansagen beim Hinzufügen/Entfernen von Positionen, Fokus-Stile, Unterstreichung von Links im Text,
  Hochkontrast-Modus, mobiles Menü ohne Seitwärtsscrollen.
- Datenbank-Migration v4 (Tabellen `recurring`, `recurring_items`, Token `CRON_TOKEN`) läuft automatisch.
- Neue Tests: `sec5` (Logo), `sec6` (wiederkehrende Rechnungen/Cron), `tests/unit` (Zeiträume), `tests/a11y` (axe-core).

## 1.4.0
- **Zusätzliche Anhänge beim Rechnungsversand** (z. B. Korrekturabzüge): bis zu 5 Dateien, je 5 MB, gesamt 10 MB. Erlaubt sind PDF, Bilder (PNG/JPG/GIF/WebP),
  ZIP, Office-/OpenDocument-Dateien, TXT und CSV. Dateiendung und Inhalt werden geprüft, Dateinamen bereinigt; die Dateien werden nicht auf dem Server gespeichert.
- **Artikelverwaltung** (Menü „Artikel“): wiederkehrende Leistungen mit Beschreibung, Einheit, Preis und MwSt. anlegen und im Rechnungsentwurf per Auswahlfeld
  „Artikel einfügen …“ als Position übernehmen (Preis und Text bleiben danach frei änderbar).
- Datenbank-Migration v3 (Tabelle `articles`) läuft beim ersten Aufruf automatisch.
- Neue Sicherheitstests `sec3` (Anhänge) und `sec4` (Artikel).

## 1.3.1
- GitHub-Symbol (SVG) mit Link zum Repository in der Fußleiste (nicht auf der öffentlichen Statusseite).

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
