# Änderungsprotokoll

Die aktuelle Versionsnummer steht in der Datei `VERSION` (und unten in der App sowie unter Einstellungen → System).

## 1.14.1
- **Angebote nachträglich änderbar:** Auch ausgestellte (versendete, angenommene, umgewandelte) Angebote lassen sich bearbeiten. Die Nummer bleibt, das PDF wird mit dem beim Ausstellen eingefrorenen Firmen-/Design-Stand neu erzeugt; ein bereits aus dem Angebot erstellter Rechnungsentwurf bleibt unverändert. Ein ausgestelltes Angebot braucht mindestens eine Position. Die Änderung steht im Protokoll. (Rechnungen bleiben nach dem Ausstellen unveränderlich.)

## 1.14.0
- **Angebote** (neuer Menüpunkt): eigener Belegtyp mit eigener Nummer (`AN-2026-0001`, Präfix einstellbar), **Gültig-bis-Datum** (Standard 30 Tage, einstellbar), Positionen wie bei Rechnungen (inkl. Artikelauswahl), PDF-Vorschau, Ausstellen (Nummer + unveränderliches PDF, Stand von Firma/Design/Texten wird eingefroren) und Versand per Mail mit PDF.
  Status: Entwurf → offen → angenommen / abgelehnt; „abgelaufen“ ergibt sich automatisch aus dem Gültigkeitsdatum. Duplizieren und Löschen von Entwürfen wie bei Rechnungen.
- **In Rechnung wandeln:** ein Klick legt aus dem Angebot einen **Rechnungsentwurf** an (Kunde und Positionen, Hinweis „Gemäß Angebot …“, Zahlungsziel nach Einstellung) und setzt das Angebot auf „angenommen“. Beide verlinken sich gegenseitig; pro Angebot ist eine Umwandlung möglich (wird der Rechnungsentwurf gelöscht, geht es wieder). Abgelehnte Angebote müssen erst wieder auf „offen“ gesetzt werden.
- **Textvorlagen** (Einstellungen → Textvorlagen): E-Mail-Texte für Rechnung, Angebot, **Zahlungserinnerung** und Export-Link (jeweils Betreff und Text) sowie Texte auf dem PDF (Einleitung über den Positionen, Zahlungshinweis der Rechnung, Einleitung und Schlusstext des Angebots).
  **Platzhalter:** `{nummer}` `{datum}` `{faellig}` `{betrag}` `{netto}` `{kunde}` `{ansprechpartner}` `{anrede}` `{firma}` `{absender}` `{gueltig_bis}` `{link}` (Schreibweise egal; nicht zutreffende Werte bleiben leer). Unbekannte Platzhalter werden beim Speichern abgelehnt, leere Mail-Texte fallen auf den Standard zurück, „Zurücksetzen“ stellt alle Standardtexte her.
  Die Mailformulare sind mit diesen Vorlagen vorbelegt und weiterhin änderbar; auch der Betreff ist jetzt editierbar. Die PDF-Texte werden – wie Firmendaten und Design – beim Ausstellen **eingefroren**; ältere Rechnungen behalten den bisherigen Text.
- **Zahlungserinnerung:** bei überfälligen Rechnungen erscheint ein Formular (Betreff und Text aus der Vorlage, Rechnungs-PDF im Anhang). Zahl und Datum der Erinnerungen stehen an der Rechnung. Für bezahlte, stornierte und Stornorechnungen gibt es keine Erinnerung.
- **Protokoll:** neue Bereiche „Angebote“ (anlegen, ändern, ausstellen, senden, annehmen/ablehnen, umwandeln, duplizieren, löschen), „Zahlungserinnerung versendet“ und „Textvorlagen geändert“.
- **Update:** neue Tabellen `offers` und `offer_items`, neue Spalten `invoices.offer_id`, `reminded_at`, `reminder_count` (Schema-Version 9), werden automatisch angelegt. Angebots-PDFs liegen in `storage/offers/` – bitte ins Backup aufnehmen. Die Navigation bricht wegen des zusätzlichen Menüpunkts schon bei schmaleren Fenstern um.

## 1.13.0
- **Export für den Steuerberater** (Einstellungen → Export): Zeitraum wählen (letzter Monat/Quartal/Jahr, laufendes Jahr oder frei), daraus entsteht ein **ZIP** mit allen Rechnungen als PDF (ZUGFeRD), auf Wunsch zusätzlich XRechnung-XML, einer **CSV-Übersicht** (Rechnungsausgangsbuch: Nummer, Datum, Kunde, Netto je Steuersatz, USt, Brutto, Status, bezahlt am, Storno-Bezug; Semikolon, UTF-8, Dezimalkomma) und einer LIESMICH.
  Textzellen mit Formelzeichen am Anfang (=, +, -, @) werden in der CSV entschärft.
- Das ZIP liegt geschützt unter `storage/exports/` (zufälliger Dateiname, von außen nicht abrufbar). Abruf über einen **eigenen, geheimen Link** (`export.php?t=…`, ohne Login, befristet auf 7/14/30/90 Tage) mit Landingpage und Download-Button.
  Der Link lässt sich **per E-Mail** senden (ohne Anhang; Empfänger wird für das nächste Mal gemerkt) – direkt beim Erstellen oder später aus der Liste. Abgelaufene Exporte werden samt Datei automatisch gelöscht; vorher können sie jederzeit gelöscht (Link ungültig) werden.
- **Sicherheit:** Token-Abrufe und Downloads sind je IP begrenzt; ungültige, abgelaufene und manipulierte Tokens liefern nur „nicht gültig“. Erstellen, Senden, Löschen und jeder Download über den Link stehen im **Protokoll**.
- **Update:** neue Tabelle `exports` (Schema-Version 8), wird automatisch angelegt. Für den Mailversand muss die **APP_URL** gesetzt sein (Einstellungen → Adresse der App), damit der Link in der Mail stimmt.

## 1.12.0
- **Änderungsprotokoll** (Einstellungen → Protokoll): Wer hat wann was getan – Anmeldungen (auch fehlgeschlagene), Rechnungen (anlegen, ändern, ausstellen, versenden, bezahlt/offen, Storno, duplizieren, Entwurf löschen), Kunden (inkl. CSV-Import),
  Artikel, wiederkehrende Vorlagen und deren automatische Läufe (Akteur „cron“), Einstellungen (nur Feldnamen, keine Werte), Logo/Briefpapier, PDF-Design, eigenes CSS, Zugangsdaten.
  Jeder Eintrag enthält Zeit, Benutzer, IP-Adresse, Objekt (verlinkt) und Details; Filter nach Bereich, Suche und Seitenweise Anzeige.
- **Manipulationsschutz:** Die Einträge werden nur angehängt (die App kann sie weder ändern noch löschen) und sind über eine SHA-256-Hash-Kette verknüpft. „Integrität prüfen“ erkennt nachträglich geänderte oder mitten aus der Kette entfernte Einträge.
  Wer direkten Datenbankzugriff hat, kann die Kette allerdings neu berechnen oder am Ende kürzen – das Protokoll ersetzt keine Zugriffsbeschränkung auf die Datenbank.
- Passwörter, Einstellungswerte und Lesezugriffe werden nicht protokolliert. Fehlanmeldungen werden nur im Rahmen der bestehenden Sperre (Rate-Limit) geschrieben, das Protokoll kann also nicht von außen geflutet werden.
  Fällt das Protokoll einmal aus, läuft die eigentliche Aktion trotzdem weiter (Fehler im Server-Log).
- **Update:** neue Tabelle `audit_log` (Schema-Version 7), wird beim ersten Aufruf automatisch angelegt.

## 1.11.1
- **Auswertung:** Beim Überfahren (Maus) oder Fokussieren (Tastatur, Antippen) eines Monats im Diagramm erscheint der Nettoumsatz sofort über dem Balken; der Monat wird hervorgehoben. Für Screenreader trägt jeder Monat eine Beschriftung mit Betrag und Rechnungsanzahl.

## 1.11.0
- **Auswertung** (neuer Menüpunkt): **Umsatz je Monat** eines wählbaren Jahres (Säulendiagramm mit Textalternative und Tabelle, netto/brutto) und **Umsatz je Jahr** inklusive noch offener Beträge.
  Grundlage sind ausgestellte und bezahlte Rechnungen (keine Entwürfe); der Umsatz zählt im Monat des Rechnungsdatums, Stornorechnungen mindern ihn im Monat ihres Rechnungsdatums.
- **Zahlungsverhalten je Kunde:** Anzahl bezahlter Rechnungen, Anteil pünktlicher Zahlungen (Zahlungsdatum ≤ Fälligkeit), durchschnittliche Zahlungsdauer, durchschnittlicher Verzug bei verspäteten Zahlungen, aktuell überfällige Forderungen
  und eine Einstufung („pünktlich“ ab 90 %, „meist pünktlich“ ab 60 %, sonst „oft verspätet“; erst ab drei bezahlten Rechnungen). Stornierte Rechnungen und Stornorechnungen fließen nicht ein.
- **Oberfläche:** Die Navigation bricht bereits unterhalb von 1180 px um (vorher lief sie bei mittleren Fensterbreiten über); Zeilenüberschriften in Tabellen werden nicht mehr als Spaltenkopf dargestellt.

## 1.10.2
- **Artikel:** In der Detailansicht eines Artikels gibt es „Als neu duplizieren“. Die Kopie übernimmt Notiz, Einheit, Preis und MwSt, die Bezeichnung bekommt den Zusatz „ (Kopie)“; danach öffnet sich die Kopie zum Anpassen.
- **Hintergrund:** Der Seitenhintergrund ist standardmäßig `#f4e9db` (helles Beige; Linien und Felder leicht warm getönt, Manifest angepasst). Der dunkle Modus bleibt unverändert; Texte erreichen weiterhin mindestens 4,5 : 1 Kontrast.

## 1.10.1
- **Neue Hausfarbe Pflaume** (angeglichen an die metaplay-App): Primärfarbe `#7a4589` (hell) bzw. `#c99bd9` (dunkel), Verlauf-Endton `#8a52a0` / `#b57fca`, helle Flächen `#f1e8f4` / `#2f1f2d`, Hintergründe und Linien mit leichtem Pflaumenstich (dunkel `#170d15`).
  Betrifft Oberfläche, Logo/App-Icons (SVG, PNG), `theme-color` und Manifest. Alle Textkontraste bleiben ≥ 4,5 : 1 (WCAG AA, mit axe geprüft). PDF-Vorlagen und bereits gespeicherte PDF-Designs bleiben unverändert.
  Eigene Anpassungen über Einstellungen → Design (CSS) überschreiben die Standardwerte weiterhin; die Variablenliste im Editor zeigt die neuen Werte.

## 1.10.0
- **CSV-Import für Kunden** (Kunden → „CSV-Import“): Datei hochladen (UTF-8 oder Windows-1252, Trennzeichen Semikolon/Komma/Tab wird erkannt, Download einer Vorlage), Spalten werden anhand der Überschriften automatisch zugeordnet und lassen sich ändern,
  Vorschau mit Zählern und Fehlerliste, erst nach Bestätigung wird importiert (in einer Transaktion). Pflichtfelder: Name, Straße, PLZ, Ort. Dubletten (gleiche E-Mail oder Name + PLZ, auch innerhalb der Datei) werden standardmäßig übersprungen;
  optional werden bestehende Kunden aktualisiert (leere Zellen überschreiben nichts). Fehlerhafte Zeilen werden mit Zeilennummer und Grund gemeldet und nicht importiert. Grenzen: 2 MB, 5000 Zeilen, 40 Spalten.
- **Statusseite/Login:** Das Logo sitzt wieder zentriert im hellen Headerbereich.

## 1.9.0
- **Stornorechnung**: Bei ausgestellten und bezahlten Rechnungen gibt es „Stornorechnung erstellen“. Daraus entsteht ein **Entwurf** mit den Positionen in negativer Menge (Einzelpreis bleibt positiv),
  gleichem Kunden und Bezug auf die Originalrechnung; er lässt sich vor dem Ausstellen anpassen (**Teilstorno**: Mengen verkleinern oder Positionen entfernen). Erst mit dem Ausstellen gilt die Rechnung als storniert.
- **Eigener Nummernkreis** `ST-JJJJ-NNNN` (Präfix unter Einstellungen → Rechnungen änderbar), fortlaufend und unabhängig von den Rechnungsnummern.
- **PDF und XML:** „Stornorechnung“ mit Hinweis auf die aufgehobene Rechnung, Erstattungshinweis statt Zahlungsaufforderung, kein GiroCode. Das XML (ZUGFeRD/XRechnung) hat den Dokumenttyp 384 (Rechnungskorrektur)
  und verweist auf die Originalnummer (BT-25); mit dem KoSIT-Validator geprüft.
- **Barrierefreiheit:** Das Feld mit dem Statusseiten-Link hat jetzt eine Beschriftung für Screenreader.
- **Verknüpfung und Sperren:** Original und Storno verweisen aufeinander; die Originalrechnung zeigt „storniert“ (Liste, Detailseite, Kunden-Statusseite ohne Zahlungsdaten und GiroCode). Je Rechnung nur eine Stornorechnung;
  eine Stornorechnung lässt sich weder stornieren, duplizieren noch als bezahlt markieren; stornierte Rechnungen nicht als bezahlt. Ein gelöschter Stornoentwurf gibt das Storno wieder frei.
  An stornierten Rechnungen steht „Korrigierte Rechnung erstellen“ (Kopie als neuer Entwurf).
- Datenbank-Migration (Version 6) läuft automatisch. Neue Tests `sec10`. Hinweis: Die Software ersetzt keine steuerliche Beratung – bitte Umgang mit Stornos (z. B. bei bereits gezahlten Rechnungen) mit dem Steuerberater abstimmen.

## 1.8.2
- **Kunden-, Artikel- und Wiederkehrend-Listen: ganze Zeile klickbar** wie bei den Rechnungen; der Link „Bearbeiten“ entfällt. Der Name in der ersten Spalte ist der echte Link (Tastatur/Screenreader), die Spalte „Aktionen“ ist weggefallen.

## 1.8.1
- **Rechnungsübersicht: ganze Zeile klickbar.** Ein Klick irgendwo in der Zeile öffnet die Rechnung (Strg/Cmd/Umschalt-Klick: neuer Tab). Text per Ziehen markieren navigiert nicht; Tastatur und Screenreader nutzen weiterhin den Link in der Nummernspalte.

## 1.8.0
- **PDF-Design** (Einstellungen → „PDF-Design anpassen“): Schriftart (DejaVu Sans Condensed, DejaVu Sans, DejaVu Serif Condensed), Schriftgröße, **Akzentfarbe**, Tabellenstil
  (Linien, schlicht, Zebra, farbiger Kopf), Absender links/rechts, Absenderzeile, Fußzeile und Seitenzahl ein-/ausblendbar, optionaler **Schlusstext** und **Fußzeilen-Zusatz**.
  Vier **Vorlagen** (Klassisch = bisheriges Aussehen, Modern, Minimal, Elegant) füllen das Formular vor; eine **PDF-Vorschau** mit Beispieldaten zeigt das Ergebnis, ohne zu speichern.
  Hat man ein Logo hinterlegt, schlägt die App eine passende Akzentfarbe vor (automatisch abgedunkelt für gute Lesbarkeit); ein Kontrastwert zeigt, wie lesbar die Farbe auf Weiß ist.
- **Das Design wird beim Ausstellen festgehalten** (im Snapshot der Rechnung): Spätere Änderungen betreffen nur neue Rechnungen und Entwürfe; ältere Rechnungen werden bei Bedarf im bisherigen Aussehen neu erzeugt.
- **Sicherheit:** Es gibt nur Aufzählungen, Zahlen und `#rrggbb` – das erzeugte PDF-CSS enthält nie freien Text; Texte werden maskiert und von Steuerzeichen befreit; CSRF-geschützt.
- Das Paket wächst um ca. 2 MB (zusätzliche Schriften). Neue Tests `sec9` und `tests/unit/pdfdesign_test.php`; freies PDF-CSS ist bewusst noch nicht enthalten.

## 1.7.0
- **GiroCode (EPC-QR) zum Überweisen**: Auf der Rechnung (PDF) und auf der Kunden-Statusseite (nur solange unbezahlt) erscheint ein QR-Code, den Banking-Apps scannen können –
  Empfänger, IBAN, BIC, Betrag und Verwendungszweck („Rechnung RE-…“) werden automatisch in die SEPA-Überweisung übernommen.
  Er erscheint nur bei gültiger IBAN (Prüfsumme), Betrag in Euro zwischen 0,01 und 999.999.999,99 € und einem Inhalt ≤ 331 Byte (EPC-Standard, Version 002).
  Abschaltbar unter Einstellungen → Rechnungen („GiroCode“). Die Statusseite bindet ihn als Bild mit Alternativtext ein (CSP unverändert).
- Umsetzung: Bibliothek `mpdf/qrcode` (LGPL); Eingabewerte werden von Steuerzeichen und Backslashes bereinigt (keine Feld-Einschleusung in den QR-Inhalt); `vendor.zip` wächst nur um ca. 0,3 MB.
- Neue Tests `sec8` (der QR-Code wird aus gerendertem PDF und Statusseite wieder dekodiert) und `tests/unit/girocode_test.php`.

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
