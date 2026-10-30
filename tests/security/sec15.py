"""Tests: Export für den Steuerberater (ab 1.13.0). Frische Instanz, SMTP-Sink (RG_MAILS) laufend."""
import sys, os, re, glob, io, zipfile, datetime, subprocess
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
MAILS = os.environ.get('RG_MAILS', '/tmp/mails')
clear_rl()
s = authed(); anon = C()
today = datetime.date.today(); iso = today.isoformat()
EXP = APP + '/storage/exports'
def mails(): return sorted(glob.glob(MAILS + '/*.eml'), key=lambda f: (os.path.getmtime(f), f))
def n_exp(): return int(sql('select count(*) from exports'))
def files(): return glob.glob(EXP + '/export-*.zip')
def tok(): return sql('select token from exports order by id desc limit 1')
def create(**kw):
    d = {'csrf': page_token(s, '/index.php?p=exports'), 'action': 'create', 'mode': 'custom', 'from': iso, 'to': iso, 'days': '14', 'with_xml': '1', 'mail_to': '', 'message': ''}
    d.update(kw)
    for k in [k for k, v in d.items() if v is None]: del d[k]
    return s.post('/index.php?p=exports', d)
def cust(name, **kw):
    d = {'name': name, 'street': 's 1', 'zip': '12345', 'city': 'c', 'country': 'DE', 'email': ''}; d.update(kw)
    d['csrf'] = page_token(s, '/index.php?p=customer_edit'); s.post('/index.php?p=customer_edit', d); return sql('select max(id) from customers')
def inv(cid, price, vat='19'):
    p = '/index.php?p=invoice_edit'
    s.post(p, {'csrf': page_token(s, p), 'customer_id': cid, 'issue_date': iso, 'due_date': '', 'delivery_date': '', 'notes': '',
               'desc[]': ['Leistung'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': [price], 'vat[]': [vat]})
    i = int(sql('select max(id) from invoices')); pp = f'/index.php?p=invoice&id={i}'
    s.post(pp, {'csrf': page_token(s, pp), 'action': 'issue'}); return i

# --- Zugriff
r = anon.get('/index.php?p=exports'); r2 = anon.post('/index.php?p=exports', {'action': 'create', 'mode': 'prev_year'})
rec('E1', 'H', 'Anonym: Exportseite nicht erreichbar, Erstellen wirkungslos', 'name="pass"' in r.body and 'Neuen Export' not in r.body and n_exp() == 0, r.status)
r = create()
rec('E2', 'M', 'Ohne Rechnungen im Zeitraum: Meldung, nichts angelegt', 'keine ausgestellten Rechnungen' in r.body and n_exp() == 0 and not files(), r.status)
rec('E3', 'M', 'Einstellungen verlinken den Export', 'p=exports' in s.get('/index.php?p=settings').body)

# --- Daten
c1 = cust('Müller & Söhne GmbH'); c2 = cust('=HYPERLINK("http://evil.example","klick")'); c3 = cust('@SUM(1+1)')
i1 = inv(c1, '100,00', '19'); i2 = inv(c2, '200,00', '7'); i3 = inv(c3, '50,00', '19')
pp = f'/index.php?p=invoice&id={i1}'; s.post(pp, {'csrf': page_token(s, pp), 'action': 'paid', 'paid_at': iso})
s.post(pp, {'csrf': page_token(s, pp), 'action': 'cancel'}); sd = int(sql('select max(id) from invoices'))
ps = f'/index.php?p=invoice&id={sd}'; s.post(ps, {'csrf': page_token(s, ps), 'action': 'issue'})   # Storno ausstellen (Menge -1)
sn = sql(f'select number from invoices where id={sd}')
rec('E4', 'M', 'Testdaten: 3 Rechnungen + Stornorechnung ausgestellt', sql("select count(*) from invoices where status in ('issued','paid')") == '4' and sn.startswith('ST-'), sn)

# --- Erstellen
b = n_exp(); r = create()
rec('E5', 'H', 'Export erstellt: Datensatz, ZIP mit zufälligem Namen in storage/exports, Flash', n_exp() == b + 1 and len(files()) == 1 and re.fullmatch(r'export-[0-9a-f]{24}\.zip', os.path.basename(files()[0])) and 'Export erstellt (4 Belege)' in s.get('/index.php?p=exports').body + r.body, (r.status, files()))
zf = zipfile.ZipFile(files()[0]); names = zf.namelist()
pdfs = [x for x in names if x.startswith('rechnungen/')]; xmls = [x for x in names if x.startswith('xrechnung/')]
rec('E6', 'H', 'ZIP-Inhalt: 4 PDFs (echte PDFs), CSV, LIESMICH', len(pdfs) == 4 and all(zf.read(x)[:5] == b'%PDF-' for x in pdfs) and 'rechnungsausgangsbuch.csv' in names and 'LIESMICH.txt' in names, names)
rm = zf.read('LIESMICH.txt').decode('utf-8')
rec('E7', 'M', 'XML: vorhanden oder im LIESMICH als nicht erzeugbar vermerkt', len(xmls) == 4 or 'kein XRechnung-XML erzeugt' in rm, (len(xmls), rm[-200:]))
csv = zf.read('rechnungsausgangsbuch.csv'); txt = csv.decode('utf-8-sig'); lines = [l for l in txt.split('\r\n') if l]
rec('E8', 'H', 'CSV: UTF-8 mit BOM, Kopfzeile + 4 Zeilen, Semikolon, Dezimalkomma', csv.startswith(b'\xef\xbb\xbf"Rechnungsnummer";') and len(lines) == 5 and '119,00' in txt and '"214,00"' not in txt and '214,00' in txt, lines[:2])
rec('E9', 'H', 'CSV: Stornorechnung mit negativen Beträgen, Status/Art korrekt', re.search(r'"' + re.escape(sn) + r'".*"Stornorechnung".*-100,00.*-119,00.*"Storno"', txt) is not None and '"storniert"' in txt and '"offen"' in txt, [l[:80] for l in lines if sn in l])
rec('E10', 'H', 'CSV: Formelzeichen am Zellanfang werden entschärft (=, @)', "\"'=HYPERLINK(" in txt and "\"'@SUM(1+1)\"" in txt and ';"=HYPERLINK' not in txt and ';"@SUM' not in txt, '')
r7 = [l for l in lines if 'HYPERLINK' in l][0].split(';')
rec('E11', 'M', 'CSV: Steuersätze in eigenen Spalten (7 %-Rechnung: Netto 7 % 200,00, USt 7 % 14,00)', r7[9].strip('"') == '200,00' and r7[12].strip('"') == '14,00' and r7[10].strip('"') == '0,00', r7[8:16])
rec('E12', 'M', 'LIESMICH nennt Zeitraum, Anzahl und Summen', 'Anzahl Belege: 4' in rm and 'Summe netto' in rm and today.strftime('%d.%m.%Y') in rm, rm[:300])
sf = os.path.basename(files()[0])
rr = anon.get('/storage/exports/' + sf); rr2 = anon.get('/storage/exports/'); rr3 = anon.get('/storage/')
rec('E13', 'H', 'storage/exports ist von außen nicht abrufbar (ZIP, Verzeichnis)', rr.status in (403, 404) and rr.raw[:2] != b'PK' and rr2.status in (403, 404) and rr3.status in (403, 404), (rr.status, rr2.status, rr3.status))
perm = oct(os.stat(files()[0]).st_mode & 0o777)

# --- Öffentlicher Link
t = tok()
pg = anon.get('/export.php?t=' + t)
rec('E14', 'H', 'Landingpage mit gültigem Token: Zeitraum, Belege, Gültigkeit, Download-Button; noch kein Download gezählt', pg.status == 200 and 'Rechnungsexport' in pg.body and '4' in pg.body and 'dl=1' in pg.body and sql('select downloads from exports order by id desc limit 1') == '0' and 'noindex' in pg.h('x-robots-tag'), pg.status)
dl = anon.get('/export.php?t=' + t + '&dl=1')
rec('E15', 'H', 'Download: application/zip, sichere Dateiname-Header, Inhalt = gespeicherte Datei, Zähler = 1', dl.status == 200 and dl.h('content-type') == 'application/zip' and re.fullmatch(r'attachment; filename="metaRechnung-Export-\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.zip"', dl.h('content-disposition')) and dl.raw == open(files()[0], 'rb').read() and sql('select downloads from exports order by id desc limit 1') == '1', (dl.status, dl.h('content-disposition')))
lg = sql("select actor,action,ip from audit_log where action='export.download'")
rec('E16', 'H', 'Download wird im Protokoll festgehalten (Akteur „Download-Link“, IP)', lg.startswith('Download-Link\texport.download\t127.0.0.1'), lg)
clear_rl()
bad = []
for tk in ('0' * 32, 'a' * 31, t.upper(), t + 'x', "' OR 1=1 -- ", '../../config.php', t[:-1] + ('0' if t[-1] != '0' else '1')):
    q = anon.get('/export.php?t=' + urllib.parse.quote(tk)); bad.append((q.status, 'Rechnungsexport' not in q.body))
q = anon.get('/export.php?t[]=' + t); bad.append((q.status, 'Rechnungsexport' not in q.body)); q = anon.get('/export.php'); bad.append((q.status, True))
rec('E17', 'H', 'Ungültige/manipulierte Tokens: 404 ohne Inhalt, kein 500', all(x[0] == 404 and x[1] for x in bad), bad)
for i in range(32): anon.get('/export.php?t=' + '1' * 32)
rr = anon.get('/export.php?t=' + t)
rec('E18', 'H', 'Token-Raten wird begrenzt (429 nach vielen Fehlversuchen, auch mit gültigem Token)', rr.status == 429, rr.status)
clear_rl()
for i in range(21): anon.get('/export.php?t=' + t + '&dl=1')
rr = anon.get('/export.php?t=' + t + '&dl=1')
rec('E19', 'M', 'Wiederholte Downloads werden begrenzt (429), Protokoll wächst nicht unbegrenzt', rr.status == 429 and int(sql("select count(*) from audit_log where action='export.download'")) <= 21, (rr.status, sql("select count(*) from audit_log where action='export.download'")))
clear_rl()
sql(f"update exports set file='../../config.php' where token='{t}'")
rr = anon.get('/export.php?t=' + t + '&dl=1'); rr2 = anon.get('/export.php?t=' + t)
rec('E20', 'H', 'Manipulierter Dateiname in der DB (Pfad): nicht auslieferbar', rr.status == 404 and b'APP_PASSWORD_HASH' not in rr.raw and rr2.status == 404, (rr.status, rr2.status))
sql(f"update exports set file='{sf}' where token='{t}'")

# --- Mailversand
m0 = len(mails()); eid = sql('select max(id) from exports')
d = {'csrf': page_token(s, '/index.php?p=exports'), 'action': 'send', 'id': eid, 'to': 'steuer@example.com', 'message': 'Hallo Frau Beispiel,\n\nanbei der Export.'}
r = s.post('/index.php?p=exports', d)
ms = mails(); mail = open(ms[-1], encoding='utf-8', errors='replace').read() if len(ms) > m0 else ''
rec('E21', 'H', 'Mail mit Link und Gültigkeit versendet, Empfänger korrekt, KEIN Anhang', len(ms) == m0 + 1 and 'steuer@example.com' in mail and 'export.php?t=' + t in mail and 'gültig bis' in mail and 'application/zip' not in mail and 'Content-Disposition: attachment' not in mail and 'Hallo Frau Beispiel' in mail, (len(ms), mail[:200]))
rec('E22', 'M', 'Empfänger wird in der Liste angezeigt und für das nächste Mal gemerkt (EXPORT_EMAIL)', 'steuer@example.com' in s.get('/index.php?p=exports').body and "'EXPORT_EMAIL' => 'steuer@example.com'" in open(APP + '/config.php').read())
m1 = len(mails())
for bad_to in ('kein-mail', 'a@example.com\r\nBcc: evil@example.com', 'a@example.com,b@example.com', ''):
    d['to'] = bad_to; d['csrf'] = page_token(s, '/index.php?p=exports'); s.post('/index.php?p=exports', d)
rec('E23', 'H', 'Ungültige Adressen (Header-Injektion, mehrere, leer) werden abgelehnt, keine Mail', len(mails()) == m1, len(mails()) - m1)
d['to'] = 'steuer@example.com'; d['id'] = '99999'; d['csrf'] = page_token(s, '/index.php?p=exports'); r = s.post('/index.php?p=exports', d)
rec('E24', 'M', 'Unbekannte Export-ID beim Senden: Meldung, keine Mail, kein 500', r.status < 500 and len(mails()) == m1)

# --- Erstellen + sofort senden, Parameter-Prüfung
b = n_exp(); m2 = len(mails()); r = create(send='1', mail_to='sofort@example.com', message='Direkt', days='7')
rec('E25', 'H', 'Erstellen mit „sofort senden“: Export angelegt, Mail verschickt, 7 Tage Gültigkeit', n_exp() == b + 1 and len(mails()) == m2 + 1 and 'sofort@example.com' in open(mails()[-1], encoding='utf-8', errors='replace').read() and sql("select datediff(expires_at, created_at) from exports order by id desc limit 1") == '7', n_exp())
create(days='99999'); rec('E26', 'M', 'Manipulierte Gültigkeitsdauer fällt auf 14 Tage zurück', sql("select datediff(expires_at, created_at) from exports order by id desc limit 1") == '14')
b = n_exp(); res = []
for kw in (dict(mode='custom', **{'from': 'abc'}), dict(mode='custom', **{'from': '2026-02-30'}), dict(mode='custom', **{'from': iso, 'to': '2020-01-01'}), dict(mode='custom', **{'from': '2000-01-01', 'to': '2100-01-01'}), dict(mode='evil'), dict(mode='custom', **{'from': '2020-01-01', 'to': '2020-01-02'})):
    r = create(**kw); res.append((r.status < 500, n_exp() == b))
rec('E27', 'H', 'Ungültige Zeiträume (Datum, Ende vor Anfang, zu groß, unbekannter Modus, leer) werden abgelehnt', all(x[0] and x[1] for x in res), res)
r = s.post('/index.php?p=exports', {'csrf': page_token(s, '/index.php?p=exports'), 'action': 'create', 'mode[]': 'x', 'days[]': '1', 'from[]': 'a'})
rec('E28', 'M', 'Parameter als Arrays: kein 500', r.status < 500 and n_exp() == b, r.status)
res = []
for mode in ('prev_month', 'prev_quarter', 'prev_year'):
    nb = n_exp(); rr = create(mode=mode); res.append(('keine ausgestellten Rechnungen' in rr.body and n_exp() == nb) or n_exp() == nb + 1)
nb = n_exp(); rr = create(mode='year')
rec('E29', 'M', 'Vordefinierte Zeiträume: leere Zeiträume → Hinweis; „Laufendes Jahr“ erzeugt Export mit passender Bezeichnung', all(res) and n_exp() == nb + 1 and sql("select label from exports order by id desc limit 1").endswith('(bis heute)') and sql("select period_from from exports order by id desc limit 1") == str(today.year) + '-01-01', res)

# --- CSRF / Löschen / Ablauf
b = n_exp(); fb = len(files())
r1 = s.post('/index.php?p=exports', {'action': 'create', 'mode': 'custom', 'from': iso, 'to': iso}); r2 = s.post('/index.php?p=exports', {'action': 'delete', 'id': eid}); r3 = s.post('/index.php?p=exports', {'action': 'send', 'id': eid, 'to': 'x@example.com'})
rec('E30', 'H', 'Erstellen/Löschen/Senden ohne CSRF-Token → 400, nichts verändert', r1.status == r2.status == r3.status == 400 and n_exp() == b and len(files()) == fb, (r1.status, r2.status, r3.status))
d2 = sql('select token from exports where id=%s' % eid); fe = glob.glob(EXP + '/*.zip')
s.post('/index.php?p=exports', {'csrf': page_token(s, '/index.php?p=exports'), 'action': 'delete', 'id': eid})
rec('E31', 'H', 'Löschen entfernt Datensatz und Datei; der Link ist danach ungültig', sql(f'select count(*) from exports where id={eid}') == '0' and len(glob.glob(EXP + '/*.zip')) == len(fe) - 1 and anon.get('/export.php?t=' + d2).status == 404)
eid2 = sql('select max(id) from exports'); t2 = tok(); f2 = sql(f'select file from exports where id={eid2}')
sql(f"update exports set expires_at = now() - interval 1 hour where id={eid2}")
rr = anon.get('/export.php?t=' + t2); rr2 = anon.get('/export.php?t=' + t2 + '&dl=1')
rec('E32', 'H', 'Abgelaufener Link: Landingpage und Download liefern 404', rr.status == 404 and rr2.status == 404 and rr2.raw[:2] != b'PK', (rr.status, rr2.status))
pg_exp = s.get('/index.php?p=exports')
rec('E33', 'H', 'Abgelaufener Export bleibt in der Schonfrist (30 Tage) samt Datei erhalten, mit Hinweis auf Löschdatum und „Link reaktivieren“', sql(f'select count(*) from exports where id={eid2}') == '1' and os.path.exists(EXP + '/' + f2) and 'abgelaufen' in pg_exp.body and 'wird am' in pg_exp.body and 'Link reaktivieren' in pg_exp.body, f2)
def extend(i, days='14'): return s.post('/index.php?p=exports', {'csrf': page_token(s, '/index.php?p=exports'), 'action': 'extend', 'id': i, 'days': days})
r = extend(eid2, '7')
rec('E33a', 'H', 'Abgelaufenen Link verlängern: gilt ab jetzt 7 Tage, der bisherige Link funktioniert wieder (Landingpage + Download)', r.status == 302 and sql(f'select datediff(expires_at, now()) from exports where id={eid2}') in ('6', '7') and anon.get('/export.php?t=' + t2).status == 200 and anon.get('/export.php?t=' + t2 + '&dl=1').raw[:2] == b'PK', r.status)
rec('E33b', 'M', 'Meldung „Link verlängert – gültig bis …“ und Protokolleintrag export.extend', 'Link verlängert' in flash_of(s.get('/index.php?p=exports').body) and sql("select count(*) from audit_log where action='export.extend'") == '1', '')
d0 = sql(f'select expires_at from exports where id={eid2}'); extend(eid2, '30')
rec('E33c', 'M', 'Noch gültigen Link verlängern: Tage werden ans bisherige Ablaufdatum angehängt (7 + 30)', sql(f"select datediff(expires_at, '{d0}') from exports where id={eid2}") == '30', d0)
for _ in range(10): extend(eid2, '90')
rec('E33d', 'H', 'Mehrfaches Verlängern ist auf 180 Tage ab heute gedeckelt', int(sql(f'select datediff(expires_at, now()) from exports where id={eid2}')) <= 180, sql(f'select expires_at from exports where id={eid2}'))
extend(eid2, '99999'); rec('E33e', 'L', 'Manipulierte Tage (99999) bleiben im 180-Tage-Deckel', int(sql(f'select datediff(expires_at, now()) from exports where id={eid2}')) <= 180, '')
n_a = sql("select count(*) from audit_log where action='export.extend'")
r = anon.post('/index.php?p=exports', {'action': 'extend', 'id': eid2, 'days': '90'})
r2 = s.post('/index.php?p=exports', {'csrf': 'falsch', 'action': 'extend', 'id': eid2, 'days': '90'})
rec('E33f', 'H', 'Verlängern anonym oder ohne CSRF-Token wirkungslos', sql("select count(*) from audit_log where action='export.extend'") == n_a and r2.status in (400, 403), (r.status, r2.status))
r = extend(999999); rec('E33g', 'L', 'Unbekannte ID: Fehlermeldung, kein Absturz', r.status in (200, 302) and 'Export nicht gefunden' in r.body + s.get('/index.php?p=exports').body, r.status)
sql(f"update exports set expires_at = now() - interval 1 hour where id={eid2}")
create(); e4 = sql('select max(id) from exports'); f4 = sql(f'select file from exports where id={e4}'); os.unlink(EXP + '/' + f4); sql(f"update exports set expires_at = now() - interval 1 day where id={e4}")
r = extend(e4); rec('E33h', 'M', 'Verlängern ohne vorhandene Datei: verständliche Meldung, Link bleibt abgelaufen', 'existiert nicht mehr' in r.body + s.get('/index.php?p=exports').body and anon.get('/export.php?t=' + sql(f'select token from exports where id={e4}')).status == 404, r.status)
sql(f"update exports set expires_at = now() - interval 31 day where id in ({eid2},{e4})")
s.get('/index.php?p=exports')
rec('E33i', 'H', 'Nach der Schonfrist (> 30 Tage abgelaufen) werden Export und Datei gelöscht', sql(f'select count(*) from exports where id in ({eid2},{e4})') == '0' and not os.path.exists(EXP + '/' + f2), f2)
create(); e3 = sql('select max(id) from exports'); t3 = tok(); f3 = sql(f'select file from exports where id={e3}'); os.unlink(EXP + '/' + f3)
rr = anon.get('/export.php?t=' + t3); rr2 = anon.get('/export.php?t=' + t3 + '&dl=1')
rec('E34', 'M', 'Datei fehlt, DB-Zeile existiert: 404 statt Fehler 500', rr.status == 404 and rr2.status == 404, (rr.status, rr2.status))

# --- Protokoll / Migration / Fehler
acts = sql("select action from audit_log where action like 'export.%' group by action order by action").split('\n')
rec('E35', 'M', 'Protokoll enthält create, send, extend, delete, download', acts == ['export.create', 'export.delete', 'export.download', 'export.extend', 'export.send'], acts)
rec('E36', 'H', 'Protokoll-Kette bleibt intakt (auch bei Einträgen aus dem öffentlichen Skript)', 'lückenlos' in (s.post('/index.php?p=audit', {'csrf': page_token(s, '/index.php?p=audit')}) and flash_of(s.get('/index.php?p=audit').body)))
sql('DROP TABLE exports'); open(APP + '/storage/schema_version', 'w').write('7'); subprocess.run(['chown', 'www-data', APP + '/storage/schema_version'])
anon.get('/index.php'); time.sleep(1)
rec('E37', 'H', 'Update von v7: Tabelle exports wird angelegt, schema_version = aktuell', sql("select count(*) from information_schema.tables where table_schema=database() and table_name='exports'") == '1' and open(APP + '/storage/schema_version').read().strip() == SCHEMA_CUR)
pe = open('/home/claude/work/sec/apache/php_error.log').read()
rec('E38', 'M', 'Keine PHP-Fehler im Log', pe.strip() == '', pe[:300])
save_results(label)
