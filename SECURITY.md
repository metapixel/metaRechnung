# Sicherheit

## Sicherheitslücke melden
Bitte **nicht** als öffentliches Issue, sondern über *Security → Report a vulnerability* (privates Melden) in diesem GitHub-Repository.
Der Betreiber sollte diese Funktion unter *Settings → Code security* aktivieren, ebenso Dependabot-Alerts und Dependabot-Security-Updates.

## Sicherheits-Audit Version 1.2.1
Geprüft wurde der gesamte Code (PHP, JavaScript, `.htaccess`, Installer) auf drei Wegen:

1. **Statische Analyse:** Psalm 5 mit Taint-Analyse (Eingaben → SQL/HTML/Shell/Dateisystem), Semgrep mit PHP-Regelsatz, manuelle Durchsicht aller Skripte.
2. **Abhängigkeiten:** Abgleich aller 29 installierten Pakete mit der FriendsOfPHP-Advisory-Datenbank, danach Update von `symfony/validator`.
3. **Dynamische Angriffstests:** 94 automatisierte Prüfungen gegen eine laufende Instanz (Apache + mod_php + MariaDB), siehe `tests/security/`.
   Jede Prüfung lief zuerst gegen die ungehärtete Version 1.2.0 und danach gegen 1.2.1.

Geprüfte Angriffsklassen: SQL-Injection (GET, POST, Second-Order, zeitbasiert), Stored/Reflected XSS (alle Ansichten, Formulare, PATH_INFO, Host-Header),
CSRF (inkl. Login/Logout), HTML-/XML-Injection in PDF und XRechnung (SSRF-Listener), Mail-Header-Injection und SMTP-Smuggling, Open Redirect,
Path Traversal, Config-Code-Injection (`config.php`), Datei-Upload (PHP-Polyglot, SVG, Dekompressionsbombe, bösartige PDFs, `../` im Dateinamen),
Sitzungen (Fixation, Cookie-Flags, Ablauf), Brute-Force, Token-Raten, Installer-Wiederaufruf, Zahlen-Manipulation (negativ, `1e308`), Array-Parameter,
Fehler-/Informationspreisgabe, direkter Dateizugriff auf `config.php`, `src/`, `storage/`, `vendor/`.

### Ergebnis
Ungehärtete Version 1.2.0: 19 von 92 Prüfungen auffällig. Davon waren 4 Fehlalarme bzw. Fehler im Test (Apache-Testkonfiguration ohne Standard-Schutz für `.htaccess`,
zu kleines Test-PNG, zu strenge Header-Prüfung, Erwartung an Entwürfe ohne Positionen). **15 echte Befunde**:

| Schwere | Befund | Behebung in 1.2.1 |
|---|---|---|
| hoch* | CSRF-Prüfung ließ ein POST ohne Token durch, solange die Sitzung noch kein Token erzeugt hatte (`hash_equals('', '')`) | Token wird mit der Sitzung erzeugt, leeres Token wird nie akzeptiert |
| mittel | Kein Brute-Force-Schutz (Anmeldung, Passwort-Bestätigung, Reset); parallele Versuche umgingen `sleep(1)` | Zähler je IP und global, 15 min Sperre |
| mittel | Alte Sitzungen blieben nach Passwortwechsel gültig | Fingerabdruck der Zugangsdaten in der Sitzung, 12 h Ablauf |
| mittel | Fehler-Redirect nutzte `REQUEST_URI` ungeprüft (`//host` = Open Redirect) | `safe_target()` lässt nur interne Pfade zu |
| mittel | Keine Content-Security-Policy | CSP mit `script-src 'self'`, Inline-Skripte entfernt |
| mittel | Keine Validierung: negative Mengen, MwSt −50 %/1000 %, SMTP-Port 999999 | Strenge Zahlen-/Bereichsprüfung, Längenlimits, E-Mail-/Länder-/USt-IdNr.-Format |
| niedrig | Login und Abmelden ohne CSRF-Schutz (Abmelden per GET-Link) | Token im Login-Formular, Abmelden nur per POST |
| niedrig | Statuslinks in Mails konnten über den Host-Header manipuliert werden | Installer setzt `APP_URL`, sonst Host-Validierung |
| niedrig | `VERSION`, `CHANGELOG.md`, `INSTALLATION.md` öffentlich abrufbar | `.htaccess` sperrt sie, außerdem `vendor/`, `src/`, `storage/`, TRACE |
| niedrig | Token-Raten auf der Statusseite unbegrenzt möglich | 30 Fehlversuche je 10 min, dann HTTP 429 |
| Info | HTTP TRACE aktiv | nur in der Serverkonfiguration abschaltbar (`TraceEnable Off`), siehe unten |

\* Praktisch durch das Cookie-Attribut `SameSite=Lax` abgemildert, dennoch als hoch eingestuft, weil es der Kernschutz gegen CSRF ist.

Zusätzlich wurde `symfony/validator` 6.2.13 (CVE-2024-50343) auf 6.4.48 aktualisiert.

**Keine Befunde** gab es bei SQL-Injection (durchgehend Prepared Statements), Stored und Reflected XSS (alle Ausgaben maskiert), HTML/XML-Injection in PDF und
XRechnung (keine ausgehenden Requests), Mail-Header-Injection (PHPMailer entfernt Zeilenumbrüche; zusätzlich werden Steuerzeichen bereits bei der Eingabe entfernt),
Datei-Upload, Path Traversal, Session-Fixation und Code-Injection über Einstellungen.

Nach den Änderungen: alle 94 Prüfungen bestanden bis auf die Info zu TRACE. Die XRechnung wurde weiterhin erfolgreich mit dem KoSIT-Validator geprüft (ACCEPTABLE).

## Was nicht abgedeckt ist / bekannte Grenzen
- **mPDF** ist in der FriendsOfPHP-Datenbank nicht erfasst. Dependabot/`composer audit` (GitHub Advisory Database) im CI deckt das ab, dort wird es wöchentlich geprüft.
- **PDF/A-3** wurde nicht mit veraPDF geprüft, nur die eingebettete XML mit dem KoSIT-Validator.
- **Einbenutzer-App:** keine Rollen, keine Zwei-Faktor-Anmeldung. Wer mehr braucht, sollte den Zugriff zusätzlich per Basic-Auth oder VPN schützen.
- **`config.php`** enthält Datenbank- und SMTP-Zugangsdaten im Klartext (so üblich bei Webspace). Dateirechte möglichst `640`/`600`.
- **Installer:** Bis zur Installation kann jeder `install.php` aufrufen. Also gleich nach dem Hochladen installieren und `install.php` löschen.
- **SMTP-Server frei wählbar:** Wer eingeloggt ist, kann den Mailserver ändern (und damit Verbindungen vom Server aus auslösen). Für eine Einbenutzer-App gewollt.
- **Statuslink** ist ein geheimer 128-Bit-Link: Wer ihn hat, sieht Rechnung, PDF und XRechnung. Er steht in der Rechnungsmail.
- **Reverse Proxy:** Der Brute-Force-Schutz nutzt `REMOTE_ADDR`. Hinter einem Proxy sieht die App nur dessen Adresse, dann teilen sich alle Nutzer das Limit.
- **Serverseitig** (nicht per `.htaccess` lösbar): HTTPS erzwingen/HSTS, `TraceEnable Off`, `expose_php = Off`, `display_errors = Off`.
- **Nginx:** Die `.htaccess`-Regeln greifen nicht. Dort `src/`, `storage/`, `vendor/`, `config.php` und `*.md` in der Serverkonfiguration sperren.

## Sicherheitstests selbst ausführen
Siehe `tests/security/README.md`. Die Tests laufen nur gegen eine **eigene Testinstanz** und verändern Daten.

## Neue Funktionen ab 1.4.0 (mit eigenen Tests)
- **Mail-Anhänge** (`sec3`): Endungs-Allowlist, Inhaltsprüfung, Größenlimits, bereinigte Dateinamen, keine Speicherung.
- **Logo** (`sec5`): nur PNG/JPG (kein SVG), Typ am Inhalt erkannt, Bild wird neu kodiert (Anhänge/PHP-Code/Metadaten fallen weg), Dateiname serverseitig, Ordner per `.htaccess` gesperrt.
- **cron.php** (`sec6`): per Kommandozeile ohne Token, per HTTP nur mit geheimem 160-Bit-Token (`hash_equals`, Fehlversuche je IP begrenzt → 429, Token erneuerbar/abschaltbar); Läufe sind durch Dateisperre und Datenbank-Zeilensperre gegen Doppelausführung geschützt.
- **Eigenes CSS** (`sec7`): nur für angemeldete Nutzer mit CSRF-Token; abgelehnt werden `<` (kein Ausbruch aus `<style>`), `@import`, `expression()`, `javascript:`, unausgeglichene Klammern und > 50 KB. Die CSP bleibt unverändert (kein Inline-Skript, externe Ressourcen weiter gesperrt); Editor-Skript wird lokal ausgeliefert. Notausgang `?nocss=1`.
- **Platzhalter** sind eine feste Allowlist (kein Code, keine Rekursion); unbekannte Platzhalter werden beim Speichern abgelehnt.
