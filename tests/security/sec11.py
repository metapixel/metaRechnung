"""Tests für den CSV-Import von Kunden (ab 1.10.0). Frische Instanz."""
import sys, os, re, glob, time, html as H
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()
TMPD = APP + '/storage/tmp'
def temps(): return glob.glob(TMPD + '/import-*.csv')
def upload(csv, c=None, name='k.csv', delimiter='auto', csrf=True):
    c = c or s
    d = {'action': 'upload', 'delimiter': delimiter}
    if csrf: d['csrf'] = page_token(c, '/index.php?p=customer_import')
    return c.req('POST', '/index.php?p=customer_import', data=d, files=[('csv', name, csv if isinstance(csv, bytes) else csv.encode('utf-8'), 'text/csv')])
def page(c=None): return (c or s).get('/index.php?p=customer_import').body
def automap(body):
    return re.findall(r'name="map\[(\d+)\]".*?<option value="([a-z_]*)" selected', body, re.S)
def post(action, body_or_map, c=None, has_header=True, mode='skip', csrf=True):
    c = c or s
    d = {'action': action, 'mode': mode}
    if has_header: d['has_header'] = '1'
    for i, f in body_or_map.items(): d[f'map[{i}]'] = f
    if csrf: d['csrf'] = page_token(c, '/index.php?p=customer_import')
    return c.post('/index.php?p=customer_import', d)
def default_map(body): return {int(i): f for i, f in automap(body)}
def count(): return int(sql('select count(*) from customers'))

# Bestand
s.post('/index.php?p=customer_edit', {'csrf': page_token(s, '/index.php?p=customer_edit'), 'name': 'Bestand GmbH', 'contact': 'Altkontakt', 'email': 'Bestand@Example.com', 'street': 'Altstr. 1', 'zip': '11111', 'city': 'Altstadt', 'country': 'AT', 'vat_id': '', 'buyer_reference': ''})
base = count()

# --- Zugriff / CSRF / Vorlage
r = anon.get('/index.php?p=customer_import'); r2 = anon.req('POST', '/index.php?p=customer_import', data={'action': 'upload'}, files=[('csv', 'a.csv', b'Name;Strasse\nX;Y', 'text/csv')])
rec('I1', 'H', 'Anonym: Importseite nicht erreichbar, Upload wirkungslos', 'name="pass"' in r.body and count() == base and not temps(), (r.status, r2.status))
r = upload('Name;Straße;PLZ;Ort\nX;Y;1;Z', csrf=False)
rec('I2', 'H', 'Upload ohne CSRF-Token → 400, nichts zwischengespeichert', r.status == 400 and not temps(), r.status)
t = s.get('/index.php?p=customer_import&template=1')
rec('I3', 'L', 'Vorlage: CSV mit UTF-8-BOM, Kopfzeile mit Pflichtfeldern, Download-Header', t.raw.startswith(b'\xef\xbb\xbfName;') and 'text/csv' in t.h('content-type') and 'attachment' in t.h('content-disposition') and 'PLZ' in t.body, t.h('content-type'))
rec('I4', 'L', 'Kundenliste verlinkt den CSV-Import', 'p=customer_import' in s.get('/index.php?p=customers').body)

# --- Hauptimport (UTF-8 mit BOM, Semikolon)
csv1 = '﻿' + '\n'.join([
 'Firma;Kontaktperson;E-Mail-Adresse;Straße;Postleitzahl;Stadt;Land;USt-IdNr;Leitweg-ID',
 'Müller & Söhne;Erika Müller;mueller@example.com;Hauptstr. 5;12345;Musterstadt;;DE123456789;',
 '"Firma; mit Semikolon ""und Anführung""";;;Weg 2;54321;Köln;Österreich;;04011000-12345-67',
 'Doppelt GmbH;;bestand@example.com;Neustr. 9;22222;Neustadt;DE;;',
 'Kaputt AG;;kein-email;Weg 3;33333;Ort;DE;;',
 'Ohne Straße GmbH;;;;44444;Ort;DE;;',
 '=SUM(A1)+1;;;Formelweg 1;55555;Formelort;DE;;',
 '<script>alert(1)</script>;;;Skriptweg 1;66666;Skriptort;DE;;',
 'Müller & Söhne;;mueller@example.com;Hauptstr. 5;12345;Musterstadt;;;',
 'Falschland AG;;;Weg 4;77777;Ort;Mordor;;',
 'Lang AG;;;' + 'x' * 200 + ';88888;Ort;DE;;',
 ''])
r = upload(csv1)
tf = temps()
rec('I5', 'H', 'Upload: Datei wird mit zufälligem Namen in storage/tmp abgelegt (0600), Weiterleitung zur Vorschau, noch nichts importiert', r.status in (302, 303) and len(tf) == 1 and re.fullmatch(r'import-[0-9a-f]{24}\.csv', os.path.basename(tf[0])) and count() == base and (os.stat(tf[0]).st_mode & 0o777) == 0o600, (r.status, tf))
pv = page()
am = default_map(pv)
rec('I6', 'H', 'Spalten werden anhand der Überschriften automatisch zugeordnet (Firma, Kontaktperson, E-Mail-Adresse, Postleitzahl, Stadt, USt-IdNr, Leitweg-ID …)', am == {0: 'name', 1: 'contact', 2: 'email', 3: 'street', 4: 'zip', 5: 'city', 6: 'country', 7: 'vat_id', 8: 'buyer_reference'}, am)
cnt = re.search(r'<b>(\d+)</b> neu · <b>(\d+)</b> zu aktualisieren · <b>(\d+)</b> Duplikate[^<]*· <b>(\d+)</b> fehlerhaft', pv)
rec('I7', 'H', 'Vorschau zählt: 4 neu (Müller, Semikolon-Firma, Formel, Skript), 2 Duplikate (Bestand per E-Mail-Groß/Klein, doppelt in Datei), 4 fehlerhaft (E-Mail, Straße fehlt, Land, zu lang)', cnt and [int(x) for x in cnt.groups()] == [4, 0, 2, 4], cnt and cnt.groups())
rec('I8', 'M', 'Fehlerhafte Zeilen werden mit Zeilennummer und Grund aufgelistet', all(x in pv for x in ['Zeile 5:', 'E-Mail-Adresse ungültig', 'Straße + Nr. fehlt', 'Land „Mordor“ unbekannt', 'zu lang']), '')
rec('I9', 'H', 'Vorschau schreibt nichts in die Datenbank und escapt Inhalte (kein ungefiltertes <script>)', count() == base and '<script>alert(1)</script>' not in pv and '&lt;script&gt;alert(1)&lt;/script&gt;' in pv)
r = post('preview', default_map(pv))
rec('I10', 'M', '„Vorschau aktualisieren“ ändert nichts an der Datenbank', r.status == 200 and count() == base)
r = post('import', default_map(pv))
rec('I11', 'H', 'Import: genau 4 Kunden angelegt, Weiterleitung zur Liste mit Zusammenfassung, Zwischendatei gelöscht', count() == base + 4 and r.status in (302, 303) and not temps(), (count(), r.status, temps()))
rows = sql("select name,contact,email,street,zip,city,country,ifnull(vat_id,''),ifnull(buyer_reference,'') from customers order by id").split('\n')
rec('I12', 'H', 'Daten korrekt gespeichert (Umlaute, & , Semikolon/Anführung im Namen, Land „Österreich“→AT, Leerland→DE, Leitweg-ID)', any(r_.startswith('Müller & Söhne\tErika Müller\tmueller@example.com\tHauptstr. 5\t12345\tMusterstadt\tDE\tDE123456789') for r_ in rows) and any(r_.startswith('Firma; mit Semikolon "und Anführung"') and '\tAT\t' in r_ and r_.endswith('04011000-12345-67') for r_ in rows), rows)
rec('I13', 'H', 'Formel- und Skript-Inhalte werden unverändert als Text gespeichert', sql("select count(*) from customers where name='=SUM(A1)+1'") == '1' and sql("select count(*) from customers where name='<script>alert(1)</script>'") == '1')
lst = s.get('/index.php?p=customers').body
rec('I14', 'H', 'Kundenliste maskiert die Skript-Eingabe', '<script>alert(1)</script>' not in lst and '&lt;script&gt;' in lst)
rec('I15', 'M', 'Bestandskunde blieb unverändert (Duplikat übersprungen)', sql("select contact,city from customers where email='Bestand@Example.com'") == 'Altkontakt\tAltstadt')
rec('I16', 'M', 'Danach keine Import-Sitzung mehr: Seite zeigt wieder den Upload', 'type="file"' in page() and '1. Spalten zuordnen' not in page())

# --- Windows-1252, Komma-Trennung, Aktualisieren
csv2 = 'Name,Strasse,PLZ,Ort,E-Mail,Ansprechpartner\nBäcker Größe,Königsweg 1,10115,Berlin,baecker@example.com,\nBestand GmbH,Neuweg 7,11111,Neustadt,,\n'.encode('cp1252')
upload(csv2); pv = page()
rec('I17', 'H', 'Windows-1252-Datei mit Komma-Trennung wird erkannt (Umlaute korrekt in der Vorschau)', 'Bäcker Größe' in pv and 'Königsweg 1' in pv and default_map(pv).get(1) == 'street', default_map(pv))
post('import', default_map(pv), mode='update')
rec('I18', 'H', 'Modus „aktualisieren“: Bestandskunde (Name+PLZ) bekommt neue Straße/Ort; leere Zellen (E-Mail, Ansprechpartner) überschreiben nichts; Land bleibt AT', sql("select street,city,contact,email,country from customers where name='Bestand GmbH'") == 'Neuweg 7\tNeustadt\tAltkontakt\tBestand@Example.com\tAT' and sql("select count(*) from customers where name='Bestand GmbH'") == '1', sql("select street,city,contact,email,country from customers where name='Bestand GmbH'"))
rec('I19', 'M', 'Neuer Kunde mit Umlauten aus Windows-1252 korrekt gespeichert', sql("select count(*) from customers where name='Bäcker Größe' and street='Königsweg 1'") == '1')

# --- Zuordnung manipulieren
before = count()
upload('Name;Straße;PLZ;Ort\nA GmbH;S 1;1;O\n'); pv = page(); m = default_map(pv)
bad = []
r = post('import', {0: 'name', 1: 'name', 2: 'zip', 3: 'city'}); bad.append(('doppelt', count() == before and 'mehreren Spalten' in r.body))
r = post('import', {0: 'evil', 1: 'street', 2: 'zip', 3: 'city'}); bad.append(('unbekannt', count() == before and 'Ungültige Zuordnung' in r.body))
r = post('import', {0: 'name', 1: 'street', 2: 'zip'}); bad.append(('Pflichtfeld Ort', count() == before and 'Pflichtfeld' in r.body))
d = {'action': 'import', 'mode': 'skip', 'has_header': '1', 'csrf': page_token(s, '/index.php?p=customer_import'), 'map': 'name'}
r = s.post('/index.php?p=customer_import', d); bad.append(('Skalar statt Array', r.status < 500 and count() == before))
r = post('import', m, csrf=False); bad.append(('ohne CSRF', r.status == 400 and count() == before))
rec('I20', 'H', 'Manipulierte Zuordnung (doppelt, unbekanntes Feld, fehlendes Pflichtfeld, Skalar) und fehlendes CSRF-Token werden abgelehnt, nichts importiert', all(b[1] for b in bad), bad)
# Sitzungsbindung
other = authed()
pg2 = page(other)
r = post('import', m, c=other)
rec('I21', 'H', 'Andere Sitzung sieht die hochgeladene Datei nicht und kann sie nicht importieren', 'type="file"' in pg2 and count() == before and temps() != [], (r.status, count()))
# Abbrechen
post('cancel', {})
rec('I22', 'M', 'Abbrechen löscht die Zwischendatei', not temps() and 'type="file"' in page())

# --- Ohne Kopfzeile
upload('Neu ohne Kopf;Weg 9;99999;Kopfstadt\n'); pv = post('preview', {0: '', 1: '', 2: '', 3: ''}, has_header=False).body
rec('I23', 'M', 'Ohne Kopfzeile: Spalten heißen „Spalte n“, nichts vorbelegt', 'Spalte 1' in pv and not [x for x in default_map(pv).values() if x])
post('import', {0: 'name', 1: 'street', 2: 'zip', 3: 'city'}, has_header=False)
rec('I24', 'M', 'Ohne Kopfzeile: erste Zeile wird als Datensatz importiert', sql("select count(*) from customers where name='Neu ohne Kopf' and city='Kopfstadt'") == '1')

# --- Ungeeignete Dateien
bad = []
b0 = count()
for nm, data in (('leer', b''), ('binär', b'PK\x03\x04\x00\x00\x00binary\x00\x01'), ('nur Leerzeilen', b'\n\n\n'), ('zu viele Zeilen', ('Name;Straße;PLZ;Ort\n' + 'A;B;1;C\n' * 5001).encode()), ('zu groß', b'x' * (2 * 1024 * 1024 + 10)), ('zu viele Spalten', (';' * 60 + '\nx\n').encode())):
    r = upload(data, name=nm + '.csv')
    ok = r.status < 500 and not temps() and page().count('type="file"') == 1
    bad.append((nm, ok, r.status))
rec('I25', 'H', 'Leere, binäre, zu große, zu lange und spaltenreiche Dateien werden mit Meldung abgelehnt (kein 500, nichts zwischengespeichert)', all(b[1] for b in bad) and count() == b0, bad)
r = s.req('POST', '/index.php?p=customer_import', data={'action': 'upload', 'delimiter': 'auto', 'csrf': page_token(s, '/index.php?p=customer_import')})
rec('I26', 'M', 'Upload ohne Datei: Meldung, kein 500', r.status < 500 and not temps(), r.status)

# --- Größerer Import (Leistung)
big = 'Name;Straße;PLZ;Ort;E-Mail\n' + ''.join(f'Großkunde {i} GmbH;Weg {i};{10000 + i};Stadt;gk{i}@example.com\n' for i in range(3000))
t0 = time.time(); upload(big); pv = page(); post('import', default_map(pv)); dt = time.time() - t0
rec('I27', 'M', '3000 Kunden werden in unter 60 s importiert', sql("select count(*) from customers where name like 'Großkunde %'") == '3000' and dt < 60, round(dt, 1))
# zweiter Lauf = alles Duplikate
upload(big); pv = page()
c2 = re.search(r'<b>(\d+)</b> neu · <b>(\d+)</b> zu aktualisieren · <b>(\d+)</b> Duplikate', pv)
rec('I28', 'M', 'Erneuter Import derselben Datei: 0 neu, 3000 Duplikate (kein Import-Button)', c2 and [int(x) for x in c2.groups()] == [0, 0, 3000] and 'value="import"' not in pv, c2 and c2.groups())
post('cancel', {})
pe = open('/home/claude/work/sec/apache/php_error.log').read()
rec('I29', 'M', 'Keine PHP-Fehler/Warnungen im Log', pe.strip() == '', pe[:300])
save_results(label)
