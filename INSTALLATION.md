# metaRechnung – Installation auf dem Webspace

**Voraussetzungen:** PHP 8.1 oder neuer (Erweiterungen pdo_mysql, mbstring, gd, dom, xml, simplexml, zip),
eine leere MySQL/MariaDB-Datenbank, HTTPS (für die PWA-Installation), Schreibrechte im Zielordner.

## Schritte
1. Alle Dateien dieses Ordners per FTP/Dateimanager in ein Verzeichnis deines Webspace hochladen,
   z. B. `rechnung/` (erreichbar unter `https://deine-domain.de/rechnung/`). Auch das Hauptverzeichnis geht.
   `vendor.zip` bleibt dabei **gezippt** – der Installer entpackt es selbst (spart dir tausende Einzeldateien beim Upload).
2. Im Browser `https://deine-domain.de/rechnung/install.php` öffnen und das Formular ausfüllen
   (Datenbank, Login, Firmendaten, Mail). Der Installer legt die Tabellen an und schreibt `config.php`.
3. Auf der Abschlussseite **„install.php jetzt löschen“** klicken (entfernt auch `vendor.zip`).
4. Anmelden und loslegen. Zum Installieren als App: Seite im Browser öffnen → „Zum Startbildschirm hinzufügen“ / „Installieren“.

## Update einer bestehenden Installation
1. Aus `rechnung-webspace-update-<Version>.zip` alle Dateien in den bestehenden Ordner hochladen und überschreiben.
   **Nicht überschreiben bzw. löschen:** `config.php`, `storage/`.
   **Sonderfall Version 1.2.1:** Der Ordner `vendor/` wurde aktualisiert (Sicherheitsfix in `symfony/validator`). Den alten Ordner `vendor/` auf dem
   Webspace vorher **komplett löschen** und den neuen aus dem Update-Paket hochladen (nicht mit dem alten zusammenführen).
2. Die App einmal im Browser öffnen: Die Datenbank wird automatisch aktualisiert (neue Spalten, Statuslinks für bereits ausgestellte Rechnungen).
3. Unter **Einstellungen** prüfen, ob Telefon, E-Mail, IBAN und Steuernummer/USt-IdNr. eingetragen sind (nötig für XRechnung).

## Sicherheit
Siehe `SECURITY.md`. Wichtig: HTTPS verwenden, `install.php` nach der Installation löschen, `APP_URL` (Einstellungen) setzen, Server-Schutz der
Ordner `src/`, `storage/`, `vendor/` prüfen (der Installer testet das).

## Testbetrieb und Reset
In `config.php` steht die Variable `'TEST_MODE'`. Bei `'1'` zeigt die App ein Banner „TESTBETRIEB“ und schaltet unter **Einstellungen** eine Reset-Funktion frei:
Sie löscht alle Rechnungen samt PDFs (optional auch die Kunden), danach beginnt die Nummerierung wieder bei 1. Einstellungen und Login bleiben erhalten.
Zur Sicherheit verlangt der Reset dein Passwort und die Eingabe von `RESET`.
- Neuinstallation: Der Installer hat dafür eine Checkbox (Standard: aus).
- Bestehende Installation: In `config.php` die Zeile `'TEST_MODE' => '1',` ergänzen. Fehlt die Variable, ist der Testbetrieb aus.
- **Vor dem Echtbetrieb auf `'0'` setzen** (oder die Zeile löschen), damit niemand versehentlich Rechnungen löschen kann.

## Briefpapier
Unter **Einstellungen → Briefpapier** kannst du ein A4-Briefpapier hochladen: PDF (am besten als „PDF 1.4“/kompatibel exportiert) oder PNG/JPG
(empfohlen 2480 × 3508 px, maximal 5 MB). Es dient als Hintergrund für neu ausgestellte Rechnungen; bei mehrseitigen Rechnungen wird es auf jeder Seite wiederholt.
- Die Seitenränder (oben/unten/links/rechts in mm) bestimmen, wo der Rechnungstext steht. Mit „Vorschau“ prüfst du das Ergebnis mit einer Beispielrechnung.
- Absenderblock und Fußzeile der App lassen sich ausblenden, wenn sie schon auf dem Briefpapier stehen. Steuernummer/USt-IdNr. und Bankverbindung müssen auf der Rechnung erscheinen.
- Bereits ausgestellte Rechnungen bleiben unverändert (die PDFs werden beim Ausstellen erzeugt und abgelegt).
- Die Datei liegt in `storage/letterhead/` und ist per `.htaccess` gegen Webzugriff gesperrt.
- Ist das Upload-Limit deines Hosters kleiner als die Datei (steht auf der Einstellungsseite), das Bild verkleinern.

## Funktionen im Überblick
- **Einstellungen:** Firmendaten, Rechnungsnummern, Kleinunternehmer, Mail/SMTP, App-Adresse, Benutzername/Passwort ändern, Testmail.
  Änderungen gelten für neu ausgestellte Rechnungen; bereits ausgestellte behalten die Daten vom Zeitpunkt der Ausstellung.
- **Statusseite für Kunden:** Jede ausgestellte Rechnung hat einen geheimen Link (`status.php?t=…`), der automatisch in der Rechnungsmail steht.
  Der Kunde sieht dort „Zahlung eingegangen am …“ (sobald du die Rechnung als bezahlt markierst) und kann PDF (ZUGFeRD) und XRechnung (XML) laden.
- **XRechnung 3.0 (CII):** Download auch in der Rechnungsansicht. Voraussetzung: Telefon, E-Mail, IBAN, Steuernummer/USt-IdNr. des Ausstellers und
  E-Mail-Adresse des Kunden. Für öffentliche Auftraggeber im Kundenformular die Leitweg-ID als „Käuferreferenz“ eintragen.
- **Artikel:** Im Menü „Artikel“ wiederkehrende Leistungen anlegen; im Rechnungsentwurf per „Artikel einfügen …“ als Position übernehmen.
- **Zusätzliche Anhänge:** Beim Versand lassen sich bis zu 5 Dateien (z. B. Korrekturabzüge) an die Mail hängen; sie werden nicht gespeichert.
- **Logo statt Briefpapier:** Unter Einstellungen → Logo ein PNG/JPG hochladen; es erscheint oben auf neuen Rechnungen (Breite und Ausrichtung einstellbar).
  Ist ein Briefpapier hinterlegt, hat dieses Vorrang.
- **Wiederkehrende Rechnungen:** Im Menü „Wiederkehrend“ Vorlagen mit Kunde, Positionen, Intervall (monatlich, vierteljährlich, jährlich) und Startdatum anlegen.
  Ein täglicher Cronjob erzeugt zum Termin die Rechnung – als Entwurf (Standard), ausgestellt oder ausgestellt und per Mail versendet.
  In Positionen und Hinweistext ersetzen Platzhalter den Zeitraum, z. B. „Hosting {ZEITRAUM}“ → „Hosting Oktober 2026“ (`{MONAT}`, `{QUARTAL}`, `{JAHR}`, `{ZEITRAUM_VON}`, `{ZEITRAUM_BIS}` …; Liste im Formular).
  **Cronjob einrichten** (im Hosting-Panel, einmal täglich): `php /pfad/zur/installation/cron.php` oder, wenn nur URLs möglich sind,
  `wget -q -O /dev/null "https://deine-domain.de/rechnung/cron.php?token=…"` (das Token steht im Menü „Wiederkehrend“ und lässt sich dort erneuern oder abschalten).
  Ein Zeitraum wird nie doppelt abgerechnet; versäumte Läufe werden nachgeholt. Fehler beim Ausstellen/Versand stehen in der Liste.
- **Eigenes CSS:** Unter Einstellungen → „CSS-Editor öffnen“ lässt sich das Aussehen der Oberfläche anpassen (Farben über CSS-Variablen, Rundungen, Schriftgröße) – mit Syntaxhervorhebung und Live-Vorschau. Das CSS wird in `storage/custom.css` gespeichert und gilt auch für Login- und Kunden-Statusseite.
  Falls ein CSS die Seite unbenutzbar macht: `&nocss=1` an die Adresse anhängen (z. B. `?p=design&nocss=1`), dann ist es abgeschaltet und lässt sich korrigieren oder zurücksetzen.
- **Barrierefreiheit:** Tastaturbedienung mit „Zum Inhalt springen“-Link, sprechende Seitentitel, beschriftete Formularfelder und Tabellen, Fokus- und Kontrast-Stile (WCAG 2.2 AA mit axe-core geprüft).
- **PDF-Vorschau:** Entwürfe lassen sich vor dem Ausstellen als PDF ansehen (Nummer „ENTWURF“, nichts wird gespeichert).
- **Duplizieren:** Button „Als neue Rechnung duplizieren“ legt einen Entwurf mit Kunde, Positionen und Hinweistext an.

## Sicherheit
- Den Installer direkt nach dem Hochladen ausführen und danach löschen.
- `src/`, `storage/`, `vendor/` sind per `.htaccess` gesperrt (Apache). Der Installer prüft das und warnt, wenn die Ordner von außen
  erreichbar sind (z. B. bei nginx-Hosting). Dann den Zugriff im Hosting-Panel sperren oder den Support fragen.
- `config.php` enthält Zugangsdaten und wird nie ausgeliefert (PHP-Datei). Nicht weitergeben.

## Betrieb
- **Backup:** Datenbank und Ordner `storage/invoices/` (die ausgestellten PDFs, aufbewahrungspflichtig) regelmäßig sichern.
- **Einstellungen ändern** (Firmendaten, SMTP, Kleinunternehmer): `config.php` bearbeiten.
- **Mailversand:** SMTP empfohlen; ohne SMTP-Server wird PHP `mail()` des Webspace genutzt (kann im Spam landen).
- **Aktualisieren:** Dateien überschreiben, aber `config.php`, `storage/` und `vendor/` behalten.

## Hinweise zu ZUGFeRD
- Erzeugt wird PDF/A-3 mit eingebettetem `factur-x.xml` (Profil EN 16931). Das eingebettete XML sowie die XRechnung wurden mit dem offiziellen
  KoSIT-Validator (XRechnung-Konfiguration 3.0.2) für Regelbesteuerung (19/7/0 %) und Kleinunternehmer geprüft: akzeptiert.
  Die PDF/A-3-Konformität der PDF-Hülle selbst ist nicht mit einem PDF/A-Validator (z. B. veraPDF) geprüft. Bitte stimme die Steuerlogik mit dem Steuerberater ab.
- Nicht enthalten: Reverse-Charge, innergemeinschaftliche Lieferungen, Rabatte, Gutschriften/Stornos, Anzahlungen, XRechnung/Leitweg-ID.

## Angebote, Textvorlagen und Zahlungserinnerung

- **Angebote** (Menü „Angebote“): Nummer `AN-JJJJ-NNNN`, Präfix und Standard-Gültigkeit unter Einstellungen → Rechnungen. Die ausgestellten Angebots-PDFs liegen in `storage/offers/` (nicht öffentlich abrufbar) – bitte wie `storage/invoices/` sichern. „In Rechnung wandeln“ erzeugt einen Rechnungsentwurf.
- **Textvorlagen** (Einstellungen → Textvorlagen) werden in `config.php` gespeichert (Schlüssel `TPL_…`). Platzhalter in geschweiften Klammern, z. B. `{nummer}`, `{faellig}`, `{betrag}`, `{link}`; die Liste steht auf der Seite selbst.
- **Zahlungserinnerung:** erscheint an überfälligen Rechnungen; Versand wie die Rechnungsmail (SMTP bzw. `mail()`), mit der Rechnung als PDF im Anhang.

## Export für den Steuerberater und Protokoll

- **Steuerberater und Zusatzunterlagen:** Unter Einstellungen → Steuerberater lässt sich ein Standard-Ansprechpartner hinterlegen. Beim Export können Kontoauszüge und Rechnungseingang (je als ZIP) sowie weitere Unterlagen (ZIP/PDF) beigelegt werden; sie landen im Ordner `zusatzunterlagen/` des Export-ZIP. Das Upload-Limit des Webspace (`upload_max_filesize`, `post_max_size`) begrenzt die Dateigröße.
- **Einstellungen → Export:** erzeugt ein ZIP (Rechnungs-PDFs, optional XRechnung-XML, CSV-Übersicht) in `storage/exports/` und einen geheimen, befristeten Abruf-Link (`export.php`). Für den Link in der E-Mail muss unter „Adresse der App“ die **APP_URL** gesetzt sein. Die PHP-Erweiterung **zip** wird benötigt. Abgelaufene Exporte löscht die App beim nächsten Aufruf der Exportseite selbst.
- **Einstellungen → Protokoll:** Änderungsprotokoll mit Prüfsummen-Kette (Tabelle `audit_log`). Es wird nie automatisch gelöscht. „Integrität prüfen“ zeigt, ob Einträge nachträglich verändert wurden.
- Beim Update werden die Tabellen `audit_log` und `exports` beim ersten Aufruf automatisch angelegt.
