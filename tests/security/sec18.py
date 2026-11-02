"""Tests: Kundenansicht, VCF-Import, PayPal.me, 2FA (TOTP), eigene Schriften (ab 1.16.0). Frische Instanz, SMTP-Sink (RG_MAILS) laufend."""
import sys, os, re, glob, datetime, hmac, hashlib, struct, time, base64, html as _h
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()
today = datetime.date.today(); iso = today.isoformat()
def totp(secret, t=None, off=0):
    key = base64.b32decode(secret + '=' * (-len(secret) % 8))
    step = int((t or time.time()) // 30) + off
    mac = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = mac[19] & 15
    return '%06d' % ((struct.unpack('>I', mac[o:o + 4])[0] & 0x7fffffff) % 1000000)
def cust(name, **kw):
    d = {'name': name, 'street': 's 1', 'zip': '12345', 'city': 'c', 'country': 'DE', 'email': 'k@example.com', 'contact': 'Erika Muster'}; d.update(kw)
    d['csrf'] = page_token(s, '/index.php?p=customer_edit'); s.post('/index.php?p=customer_edit', d); return sql('select max(id) from customers')
def inv(cid, price='100,00', due=''):
    p = '/index.php?p=invoice_edit'
    s.post(p, {'csrf': page_token(s, p), 'customer_id': cid, 'issue_date': iso, 'due_date': due, 'delivery_date': '', 'notes': '', 'desc[]': ['L'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': [price], 'vat[]': ['19']})
    i = int(sql('select max(id) from invoices')); pp = f'/index.php?p=invoice&id={i}'
    s.post(pp, {'csrf': page_token(s, pp), 'action': 'issue'}); return i
def iact(i, a, **kw):
    pp = f'/index.php?p=invoice&id={i}'; return s.post(pp, dict({'csrf': page_token(s, pp), 'action': a}, **kw))
def offer(cid):
    p = '/index.php?p=offer_edit'
    s.post(p, {'csrf': page_token(s, p), 'customer_id': cid, 'issue_date': iso, 'valid_until': '', 'notes': '', 'desc[]': ['B'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': ['10,00'], 'vat[]': ['19']})
    return int(sql('select max(id) from offers'))
def save_settings(**kw):
    pg = s.get('/index.php?p=settings'); d = {'csrf': csrf_of(pg.body), 'action': 'save'}
    for m in re.finditer(r'<input[^>]*name="([A-Z_]+)"[^>]*value="([^"]*)"', pg.body): d[m.group(1)] = _h.unescape(m.group(2))
    d['SMTP_SECURE'] = 'none'; d.update(kw); return s.post('/index.php?p=settings', d)

# ===== Kundenansicht
c1 = cust('Alpha <b>GmbH</b>'); c2 = cust('Beta AG')
i1 = inv(c1); i2 = inv(c2, '200,00'); o1 = offer(c1)
n1 = sql(f'select number from invoices where id={i1}'); n2 = sql(f'select number from invoices where id={i2}'); 
lst = s.get('/index.php?p=customers').body
rec('K1', 'M', 'Kundenliste verlinkt auf die Kundenansicht', f'p=customer&amp;id={c1}' in lst and f'p=customer_edit&amp;id={c1}' not in lst, '')
pg = s.get(f'/index.php?p=customer&id={c1}')
rec('K2', 'H', 'Kundenansicht: Stammdaten, Rechnungen, Angebote, Zahlungsmoral; Menüpunkt „Kunden“ aktiv', pg.status == 200 and all(x in pg.body for x in ['Stammdaten', 'Rechnungen (1)', 'Angebote (1)', 'Zahlungsmoral']), pg.status)
rec('K3', 'H', 'Zeigt nur Belege dieses Kunden (eigene Rechnung/Angebot ja, fremde Rechnung nein)', n1 in pg.body and f'p=offer&amp;id={o1}' in pg.body and n2 not in pg.body, '')
rec('K4', 'H', 'Kundenname wird maskiert (kein ungefiltertes HTML)', '<b>GmbH</b>' not in pg.body and '&lt;b&gt;GmbH&lt;/b&gt;' in pg.body, '')
r = s.get('/index.php?p=customer&id=999999')
rec('K5', 'M', 'Unbekannter Kunde: Weiterleitung zur Liste statt Fehler', r.status in (302, 303, 404) and 'Fatal' not in r.body, r.status)
r = s.get('/index.php?p=customer&id=abc')
rec('K6', 'M', 'Ungültige ID bricht nicht ab', r.status in (302, 303, 404) and 'Fatal' not in r.body, r.status)
rec('K7', 'H', 'Ohne Anmeldung nicht erreichbar', anon.get(f'/index.php?p=customer&id={c1}').body.count('Stammdaten') == 0, '')
rec('K8', 'M', 'Bearbeiten-, Neues-Angebot- und Neue-Rechnung-Knopf vorhanden', f'p=customer_edit&amp;id={c1}' in pg.body and f'p=offer_edit&amp;customer={c1}' in pg.body and f'p=invoice_edit&amp;customer={c1}' in pg.body, '')
rec('K9', 'M', 'Offene Forderung wird im Kundenblock genannt', 'Offen: <b>1</b>' in pg.body, '')
iact(i1, 'paid', paid_at=iso)
pg = s.get(f'/index.php?p=customer&id={c1}')
rec('K10', 'M', 'Nach Zahlung: bezahlt, pünktlich, „zu wenig Daten“ (unter 3 Rechnungen)', 'bezahlt' in pg.body and '100 %' in pg.body and 'zu wenig Daten' in pg.body, '')
rp = s.get('/index.php?p=reports').body
rec('K11', 'M', 'Auswertung verlinkt Kunden auf die Kundenansicht und behält die Summen', f'p=customer&amp;id={c1}' in rp and 'Zahlungsverhalten der Kunden' in rp, '')

# ===== VCF-Import
def vcf_upload(content, name='kontakte.vcf', ctype='text/vcard'):
    return s.req('POST', '/index.php?p=customer_import', data={'csrf': page_token(s, '/index.php?p=customer_import'), 'action': 'upload'}, files=[('csv', name, content, ctype)])
def imp_page(): return s.get('/index.php?p=customer_import').body
def tmpfiles(): return glob.glob(APP + '/storage/tmp/import-*.csv')
def default_map(pv): return {int(m.group(1)): m.group(2) for m in re.finditer(r'name="map\[(\d+)\]".*?<option value="([a-z_]+)" selected', pv, re.S)}
vc = ('BEGIN:VCARD\r\nVERSION:3.0\r\nN:Mustermann;Erika;;Dr.;\r\nFN:Dr. Erika Mustermann\r\nORG:Beispiel\\, GmbH;Abt\r\nitem1.EMAIL;TYPE=HOME:privat@vcf.example\r\nEMAIL;TYPE=WORK:erika@vcf.example\r\n'
      'ADR;TYPE=WORK:;;Musterstr. 1;Papenburg;;26871;Deutschland\r\nTEL:+49 123\r\nPHOTO;ENCODING=b;TYPE=JPEG:/9j/4AAQSkZJRgABAQ==\r\nEND:VCARD\r\n'
      'BEGIN:VCARD\r\nVERSION:2.1\r\nN;CHARSET=ISO-8859-1:M\xfcller;Hans\r\nFN;CHARSET=ISO-8859-1:Hans M\xfcller\r\nEMAIL:hans@vcf.example\r\nADR;HOME;ENCODING=QUOTED-PRINTABLE:;;Stra=C3=9Fe 2=\r\n a;K=C3=B6ln;;50667;DE\r\nEND:VCARD\r\n'
      'BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Nur Name\r\nEND:VCARD\r\n'
      'BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Lange Zeile\r\nADR:;;Stra\r\n ße 9;Berlin;;10115;DE\r\nEND:VCARD\r\n').encode('latin1')
n0 = int(sql('select count(*) from customers'))
rec('V4', 'M', 'Upload-Formular nennt vCard und akzeptiert .vcf', 'vCard' in imp_page() and '.vcf' in imp_page(), '')
r = vcf_upload(vc)
tf = tmpfiles()
rec('V1', 'H', 'VCF-Upload: Weiterleitung zur Vorschau, Zwischendatei mit Zufallsnamen, noch nichts importiert', r.status in (302, 303) and len(tf) == 1 and int(sql('select count(*) from customers')) == n0, (r.status, tf))
pv = imp_page()
rec('V2', 'H', 'Spalten werden automatisch zugeordnet (Name, Ansprechpartner, E-Mail, Straße, PLZ, Ort, Land)', default_map(pv) == {0: 'name', 1: 'contact', 2: 'email', 3: 'street', 4: 'zip', 5: 'city', 6: 'country'}, default_map(pv))
cnt = re.search(r'<b>(\d+)</b> neu · <b>(\d+)</b> zu aktualisieren · <b>(\d+)</b> Duplikate[^<]*· <b>(\d+)</b> fehlerhaft', pv)
rec('V3', 'H', 'Vorschau: 3 neu (Firma mit Ansprechpartner, Latin-1/QP-Karte, gefaltete Adresse), 1 fehlerhaft (nur Name)', cnt and [int(x) for x in cnt.groups()] == [3, 0, 0, 1], cnt and cnt.groups())
d = {'csrf': csrf_of(pv), 'action': 'import', 'has_header': '1', 'mode': 'skip'}
for i, f in default_map(pv).items(): d[f'map[{i}]'] = f
r = s.post('/index.php?p=customer_import', d)
rows = sql("select name,ifnull(contact,''),ifnull(email,''),street,zip,city,country from customers order by id desc limit 3").split('\n')
rec('V5', 'H', 'Import: 3 Kunden angelegt, Zwischendatei gelöscht', int(sql('select count(*) from customers')) == n0 + 3 and not tmpfiles(), (sql('select count(*) from customers'), tmpfiles()))
allr = '\n'.join(rows)
rec('V6', 'H', 'Firma wird Kunde, Person Ansprechpartner; Komma im Firmennamen, Land „Deutschland“→DE, bevorzugt geschäftliche E-Mail', 'Beispiel, GmbH\tDr. Erika Mustermann\terika@vcf.example\tMusterstr. 1\t26871\tPapenburg\tDE' in allr, rows)
rec('V7', 'H', 'Latin-1 und Quoted-Printable (inkl. weichem Zeilenumbruch): „Hans Müller“, „Straße 2 a“, „Köln“', 'Hans Müller\t\that' not in allr and 'Hans Müller\t\thans@vcf.example\tStraße 2 a\t50667\tKöln\tDE' in allr, rows)
rec('V8', 'M', 'Gefaltete Zeile (Zeilenfortsetzung) wird zusammengesetzt: „Straße 9“', 'Lange Zeile\t\t\tStraße 9\t10115\tBerlin\tDE' in allr, rows)
rec('V9', 'M', 'Foto/Telefon landen nicht in den Kundendaten', 'AAQSkZJ' not in allr and '+49 123' not in allr, '')
rec('V10', 'M', 'Audit: Import ist protokolliert', 'Kunden importiert' in s.get('/index.php?p=audit').body or sql("select count(*) from audit_log where action='customer.import'") != '0', '')
# erneut: Duplikate
vcf_upload(vc); pv = imp_page(); cnt = re.search(r'<b>(\d+)</b> neu · <b>(\d+)</b> zu aktualisieren · <b>(\d+)</b> Duplikate[^<]*· <b>(\d+)</b> fehlerhaft', pv)
rec('V11', 'M', 'Zweiter Import derselben Datei: 3 Duplikate, 0 neu', cnt and [int(x) for x in cnt.groups()] == [0, 0, 3, 1], cnt and cnt.groups())
s.post('/index.php?p=customer_import', {'csrf': csrf_of(pv), 'action': 'cancel'})
# Müll
r = vcf_upload(b'\x00\x01\x02 kein vcard')
rec('V12', 'M', 'Binärdatei mit .vcf-Endung wird abgelehnt, nichts zwischengespeichert', not tmpfiles() and int(sql('select count(*) from customers')) == n0 + 3, (r.status, tmpfiles()))
r = vcf_upload(b'BEGIN:VCARD\r\nVERSION:3.0\r\nFN:X\r\n')
rec('V13', 'L', 'Unvollständige vCard (ohne END) liefert keine Kontakte und keinen Absturz', 'Fatal' not in r.body and not tmpfiles(), r.status)
big = ('BEGIN:VCARD\nVERSION:3.0\nFN:Z\nADR:;;a;b;;12345;DE\nEND:VCARD\n' * 5100).encode()
r = vcf_upload(big)
rec('V14', 'M', 'Mehr als 5000 Kontakte werden abgelehnt', not tmpfiles(), r.status)
xss = b'BEGIN:VCARD\nVERSION:3.0\nORG:<script>alert(1)</script>\nFN:=SUM(1)\nADR:;;Weg 1;Ort;;12345;DE\nEND:VCARD\n'
vcf_upload(xss); pv = imp_page()
rec('V15', 'H', 'Vorschau maskiert Skript-Inhalte aus der vCard', '<script>alert(1)</script>' not in pv and '&lt;script&gt;' in pv, '')
s.post('/index.php?p=customer_import', {'csrf': csrf_of(pv), 'action': 'cancel'})
# CSV läuft weiter
csvr = s.req('POST', '/index.php?p=customer_import', data={'csrf': page_token(s, '/index.php?p=customer_import'), 'action': 'upload', 'delimiter': 'auto'}, files=[('csv', 'k.csv', 'Name;Straße;PLZ;Ort\nCSV Kunde;Weg 1;12345;Ort\n'.encode(), 'text/csv')])
pv = imp_page()
rec('V16', 'H', 'CSV-Import funktioniert unverändert weiter', csvr.status in (302, 303) and '<b>1</b> neu' in pv, '')
s.post('/index.php?p=customer_import', {'csrf': csrf_of(pv), 'action': 'cancel'})

# ===== PayPal.me
KL = "'KLEINUNTERNEHMER' => '1'" in open(APP + '/config.php').read()
def amt(net, i=None):
    kl = '"kl":true' in sql(f'select snapshot from invoices where id={i}') if i else True
    return '%.2f' % (net if kl else net * 1.19)
cp = cust('PayPal Kunde')
save_settings(PAYPAL_ME='')
ip0 = inv(cp, '100,00')
r0 = anon.get('/status.php?t=' + sql(f'select public_token from invoices where id={ip0}'))
rec('P1', 'H', 'Ohne PayPal.me-Name: keine PayPal-Elemente auf der Statusseite', r0.status == 200 and 'PayPal' not in r0.body, r0.status)
r = save_settings(PAYPAL_ME='https://www.paypal.me/MetaTest1/25')
rec('P2', 'H', 'Einstellung akzeptiert Name oder paypal.me-Link und speichert nur den Namen', "'PAYPAL_ME' => 'MetaTest1'" in open(APP + '/config.php').read(), '')
for bad in ['ab', 'x' * 21, 'a b c', 'evil/../x', '"><script>', 'javascript:alert(1)', 'https://evil.example/MetaTest1']:
    save_settings(PAYPAL_ME='MetaTest1'); save_settings(PAYPAL_ME=bad)
    ok = "'PAYPAL_ME' => 'MetaTest1'" in open(APP + '/config.php').read()
    rec('P3', 'H', f'Ungültiger PayPal.me-Name wird abgelehnt: {bad[:30]}', ok, '')
ip1 = inv(cp, '100,00'); tok = sql(f'select public_token from invoices where id={ip1}')
st = anon.get('/status.php?t=' + tok).body
rec('P4', 'H', 'Statusseite: „Per PayPal zahlen“ mit vorausgefülltem Betrag (Betrag brutto) und QR-Code', 'https://www.paypal.me/MetaTest1/' + amt(100, ip1) + 'EUR' in st and 'Per PayPal zahlen' in st and 'QR-Code zum Bezahlen per PayPal' in st, '')
rec('P5', 'M', 'Link öffnet extern mit rel=noopener noreferrer', 'rel="noopener noreferrer"' in st, '')
iact(ip1, 'paid', paid_at=iso)
rec('P6', 'M', 'Bezahlte Rechnung: kein PayPal-Link mehr', 'Per PayPal zahlen' not in anon.get('/status.php?t=' + tok).body, '')
pdfr = s.get(f'/index.php?p=pdf&id={ip1}'); pdfb = pdfr.raw if hasattr(pdfr, 'raw') else b''
rec('P7', 'H', 'Rechnungs-PDF enthält den PayPal-Link (als Link-Annotation) und bleibt PDF', pdfr.status == 200 and pdfb[:5] == b'%PDF-' and ('paypal.me/MetaTest1/' + amt(100, ip1) + 'EUR').encode() in pdfb.replace(b'\\', b''), pdfr.status)
# Einfrieren: Name ändern -> alte Rechnung behält
ip2 = inv(cp, '10,00'); tok2 = sql(f'select public_token from invoices where id={ip2}')
save_settings(PAYPAL_ME='AndererName9')
st2 = anon.get('/status.php?t=' + tok2).body
rec('P8', 'H', 'Beim Ausstellen eingefroren: spätere Namensänderung ändert alte Rechnungen nicht', 'paypal.me/MetaTest1/' + amt(10, ip2) + 'EUR' in st2 and 'AndererName9' not in st2, '')
ip3 = inv(cp, '10,00'); st3 = anon.get('/status.php?t=' + sql(f'select public_token from invoices where id={ip3}')).body
rec('P9', 'M', 'Neue Rechnung nutzt den neuen Namen', 'paypal.me/AndererName9/' + amt(10, ip3) + 'EUR' in st3, '')
# Storno: kein PayPal
save_settings(PAYPAL_ME='')

# ===== 2FA
sec = None
pg = s.get('/index.php?p=settings')
rec('T1', 'M', 'Einstellungen: Abschnitt 2FA mit „2FA einrichten“', 'id="zwei-faktor"' in pg.body and 'value="mfa_start"' in pg.body, '')
s.post('/index.php?p=settings', {'csrf': csrf_of(pg.body), 'action': 'mfa_start'})
pg = s.get('/index.php?p=settings')
m = re.search(r'<code>([A-Z2-7 ]{39})</code>', pg.body)
sec = m.group(1).replace(' ', '') if m else ''
rec('T2', 'H', 'Einrichtung zeigt QR-Code und Schlüssel (32 Zeichen Base32), noch nicht aktiv', len(sec) == 32 and 'data:image/svg+xml' in pg.body and "TOTP_SECRET' => '" not in open(APP + '/config.php').read().replace("'TOTP_SECRET' => ''", ''), len(sec))
r = s.post('/index.php?p=settings', {'csrf': csrf_of(pg.body), 'action': 'mfa_enable', 'code': '000000'})
rec('T3', 'H', 'Falscher Code bei der Einrichtung: 2FA bleibt aus', "'TOTP_SECRET' => ''" in open(APP + '/config.php').read() or "TOTP_SECRET" not in open(APP + '/config.php').read(), '')
pg = s.get('/index.php?p=settings')
r = s.post('/index.php?p=settings', {'csrf': csrf_of(pg.body), 'action': 'mfa_enable', 'code': totp(sec)})
pg = s.get('/index.php?p=settings')
codes = re.findall(r'<li><code>([A-Z0-9]{5}-[A-Z0-9]{5})</code></li>', pg.body)
cfg = open(APP + '/config.php').read()
rec('T4', 'H', 'Richtiger Code aktiviert 2FA und zeigt 10 Wiederherstellungscodes einmalig', f"'TOTP_SECRET' => '{sec}'" in cfg and len(codes) == 10 and len(set(codes)) == 10, (len(codes),))
rec('T5', 'H', 'Wiederherstellungscodes liegen nur als SHA-256-Hash in config.php, nicht im Klartext', all(c.replace('-', '') not in cfg for c in codes) and 'TOTP_RECOVERY' in cfg, '')
rec('T6', 'M', 'Codes werden nur einmal angezeigt (nächster Aufruf ohne Liste, Anzahl 10 übrig)', not re.findall(r'<li><code>[A-Z0-9]{5}-', s.get('/index.php?p=settings').body) and 'Noch <b>10</b>' in s.get('/index.php?p=settings').body, '')
rec('T7', 'M', 'Aktuelle Sitzung bleibt nach dem Aktivieren angemeldet', s.get('/index.php?p=customers').status == 200, '')
# Login-Ablauf
clear_rl()
def login_step1(c, pw='SehrGeheim12345'):
    pgl = c.get('/index.php'); return c.post('/index.php', {'user': 'kai', 'pass': pw, 'csrf': csrf_of(pgl.body)})
c = C(); r = login_step1(c)
rec('T8', 'H', 'Mit 2FA: Passwort allein meldet nicht an – es erscheint die Code-Abfrage', r.status == 200 and 'name="code"' in r.body and 'name="pass"' not in r.body and '<h1>Kunden</h1>' not in c.get('/index.php?p=customers').body, r.status)
r = c.post('/index.php', {'csrf': csrf_of(r.body), 'code': '123456'})
rec('T9', 'H', 'Falscher Code: abgelehnt, weiter Code-Abfrage, nicht angemeldet', 'name="code"' in r.body and 'falsch' in r.body and '<h1>Kunden</h1>' not in c.get('/index.php?p=customers').body, '')
last_used = int(re.search(r"'TOTP_LAST' => '(\d+)'", open(APP + '/config.php').read()).group(1))
good = totp(sec, off=1) if int(time.time() // 30) + 1 > last_used else totp(sec)
r = c.post('/index.php', {'csrf': csrf_of(c.get('/index.php').body), 'code': good})
rec('T10', 'H', 'Richtiger Code: Anmeldung erfolgreich', r.status in (302, 303) and 'name="pass"' not in c.get('/index.php?p=customers').body, r.status)
# Wiederverwendung
c2_ = C(); r = login_step1(c2_)
r = c2_.post('/index.php', {'csrf': csrf_of(r.body), 'code': good})
rec('T11', 'H', 'Bereits verwendeter Code (Replay) wird abgelehnt', 'name="code"' in r.body and r.status != 302, r.status)
# Wiederherstellungscode
c3_ = C(); r = login_step1(c3_)
rc = codes[0]
r = c3_.post('/index.php', {'csrf': csrf_of(r.body), 'code': rc.lower()})
rec('T12', 'H', 'Wiederherstellungscode (auch klein geschrieben) meldet an', r.status in (302, 303) and 'name="pass"' not in c3_.get('/index.php?p=customers').body, r.status)
c4_ = C(); r = login_step1(c4_)
r = c4_.post('/index.php', {'csrf': csrf_of(r.body), 'code': rc})
rec('T13', 'H', 'Wiederherstellungscode funktioniert nur einmal', 'name="code"' in r.body and r.status != 302, r.status)

# --- weitere 2FA-Prüfungen
def fresh_code():
    last = int(re.search(r"'TOTP_LAST' => '(\d+)'", open(APP + '/config.php').read()).group(1))
    for off in (0, 1, -1):
        if int(time.time() // 30) + off > last: return totp(sec, off=off)
    return None
clear_rl()
c5 = C(); r = login_step1(c5); tok = csrf_of(r.body)
for i in range(5):
    r = c5.post('/index.php', {'csrf': csrf_of(c5.get('/index.php').body) or tok, 'code': '%06d' % (111111 + i)})
r6 = c5.post('/index.php', {'csrf': csrf_of(c5.get('/index.php').body), 'code': fresh_code() or '000000'})
rec('T14', 'H', 'Nach 5 falschen Codes ist Schluss: auch ein richtiger Code wird nicht mehr angenommen (IP-Sperre)', r6.status in (429, 200) and '<h1>Kunden</h1>' not in c5.get('/index.php?p=customers').body, r6.status)
clear_rl()
c6 = C(); r = login_step1(c6)
r = c6.post('/index.php', {'code': fresh_code() or '000000'})
rec('T15', 'H', 'Code-Schritt ohne CSRF-Token wird abgelehnt', '<h1>Kunden</h1>' not in c6.get('/index.php?p=customers').body and r.status != 302, r.status)
c7 = C(); r = login_step1(c7)
r = c7.post('/index.php', {'csrf': csrf_of(r.body), 'action': 'cancel'})
rec('T16', 'L', '„Abbrechen“ im Code-Schritt führt zurück zur Passwortabfrage', 'name="pass"' in r.body, '')
rec('T17', 'M', 'Audit: Fehlversuch, Aktivierung und Wiederherstellungscode sind protokolliert', sql("select count(*) from audit_log where action='auth.mfa_failed'") != '0' and sql("select count(*) from audit_log where action='auth.mfa_enabled'") == '1' and sql("select count(*) from audit_log where action='auth.login' and summary like '%Wiederherstellungscode%'") != '0', '')
# deaktivieren braucht Passwort
pg = s.get('/index.php?p=settings')
s.post('/index.php?p=settings', {'csrf': csrf_of(pg.body), 'action': 'mfa_disable', 'current_pass': 'falsch'})
rec('T18', 'H', 'Deaktivieren mit falschem Passwort scheitert', f"'TOTP_SECRET' => '{sec}'" in open(APP + '/config.php').read(), '')
# Codes erneuern
s.post('/index.php?p=settings', {'csrf': csrf_of(s.get('/index.php?p=settings').body), 'action': 'mfa_regen', 'current_pass': 'SehrGeheim12345'})
new_codes = re.findall(r'<li><code>([A-Z0-9]{5}-[A-Z0-9]{5})</code></li>', s.get('/index.php?p=settings').body)
rec('T19', 'H', 'Neue Codes: 10 Stück, die alten sind ungültig', len(new_codes) == 10 and not set(new_codes) & set(codes), len(new_codes))
clear_rl()
c8 = C(); r = login_step1(c8); r = c8.post('/index.php', {'csrf': csrf_of(r.body), 'code': codes[5]})
rec('T20', 'H', 'Alter Wiederherstellungscode wird nicht mehr akzeptiert', r.status != 302 and 'name="code"' in r.body, r.status)
# Passwortwechsel-Fingerabdruck / Sitzung 
c9 = C(); r = login_step1(c9); r = c9.post('/index.php', {'csrf': csrf_of(r.body), 'code': new_codes[0]})
rec('T21', 'M', 'Anmeldung mit neuem Wiederherstellungscode klappt', r.status in (302, 303), r.status)
# Deaktivieren
s.post('/index.php?p=settings', {'csrf': csrf_of(s.get('/index.php?p=settings').body), 'action': 'mfa_disable', 'current_pass': 'SehrGeheim12345'})
cfg = open(APP + '/config.php').read()
rec('T22', 'H', 'Deaktivieren (mit Passwort) löscht Secret und Codes', "'TOTP_SECRET' => ''" in cfg and "'TOTP_RECOVERY' => '[]'" in cfg, '')
rec('T23', 'H', 'Andere Sitzungen enden beim Deaktivieren (Fingerabdruck), die eigene bleibt', 'name="pass"' in c9.get('/index.php?p=customers').body and s.get('/index.php?p=customers').status == 200, '')
clear_rl()
c10 = authed()
rec('T24', 'H', 'Ohne 2FA genügt wieder das Passwort', c10.get('/index.php?p=customers').status == 200 and 'name="code"' not in c10.get('/index.php').body, '')

# ===== Schriften
FD = '/usr/share/fonts/truetype/crosextra/'
reg = open(FD + 'Carlito-Regular.ttf', 'rb').read(); bold = open(FD + 'Carlito-Bold.ttf', 'rb').read()
def font_add(name, r=None, b=None, i=None, bi=None):
    fl = [(k, fn, v, 'font/ttf') for k, fn, v in (('font_r', 'r.ttf', r), ('font_b', 'b.ttf', b), ('font_i', 'i.ttf', i), ('font_bi', 'bi.ttf', bi)) if v is not None]
    return s.req('POST', '/index.php?p=settings', data={'csrf': page_token(s), 'action': 'font_add', 'font_name': name}, files=fl)
def cfg_fonts(): return re.search(r"'CUSTOM_FONTS' => '(.*?)',\n", open(APP + '/config.php').read(), re.S)
nf = lambda: len(glob.glob(APP + '/storage/fonts/*.ttf'))
pgs = s.get('/index.php?p=settings').body
rec('F1', 'M', 'Einstellungen: Abschnitt Schriften mit Upload-Formular', 'id="schriften"' in pgs and 'name="font_r"' in pgs, '')
font_add('Müll', r=b'kein font ' * 20)
rec('F2', 'H', 'Keine Schriftdatei (Text als .ttf) wird abgelehnt, nichts gespeichert', nf() == 0, nf())
cff = b'OTTO' + reg[4:]
font_add('CFF', r=cff)
rec('F3', 'H', 'OpenType mit CFF-Umrissen (OTTO) wird abgelehnt', nf() == 0, nf())
font_add('Bad<b>', r=reg)
rec('F4', 'H', 'Unzulässiger Schriftname (<b>) wird abgelehnt', nf() == 0, nf())
# fsType restricted
def patch_fstype(data, val):
    n = struct.unpack('>H', data[4:6])[0]
    for i in range(n):
        rec_ = data[12 + 16 * i:28 + 16 * i]
        if rec_[:4] == b'OS/2':
            off = struct.unpack('>I', rec_[8:12])[0]
            return data[:off + 8] + struct.pack('>H', val) + data[off + 10:]
    return data
font_add('Restricted', r=patch_fstype(reg, 2))
rec('F5', 'H', 'Schrift mit Einbettungsverbot (fsType Restricted) wird abgelehnt', nf() == 0 and 'Lizenz' in s.get('/index.php?p=settings').body + s.get('/index.php?p=settings').body, nf())
font_add('Groß', r=reg + b'\0' * (4 * 1024 * 1024 + 10))
rec('F6', 'M', 'Zu große Datei (> 4 MB) wird abgelehnt', nf() == 0, nf())
font_add('Ohne Regular', b=bold)
rec('F7', 'M', 'Ohne Regular-Schnitt wird abgelehnt', nf() == 0, nf())
font_add('Carlito Test', r=reg, b=bold)
cf = cfg_fonts()
key = re.search(r'(cf[0-9a-f]{8})', cf.group(1)).group(1) if cf else ''
rec('F8', 'H', 'Gültige Schrift (Regular + Fett) wird angenommen: 2 Dateien, Eintrag in der Konfiguration', key and nf() == 2, (key, nf()))
rec('F9', 'H', 'Dateien liegen mit zufälligen Namen in storage/fonts, direkt nicht abrufbar', all(re.fullmatch(r'[0-9a-f]{16}\.ttf', os.path.basename(f)) for f in glob.glob(APP + '/storage/fonts/*.ttf')) and anon.get('/storage/fonts/' + os.path.basename(glob.glob(APP + '/storage/fonts/*.ttf')[0])).status in (403, 404), '')
rec('F10', 'H', 'Doppelter Name wird abgelehnt', (font_add('carlito test', r=reg) and nf() == 2), nf())
fr = s.get(f'/index.php?p=font&k={key}&s=R')
rec('F11', 'H', 'Schrift für die Oberfläche wird angemeldet ausgeliefert (font/ttf, nosniff)', fr.status == 200 and fr.h('content-type') == 'font/ttf' and fr.raw[:4] == b'\x00\x01\x00\x00', fr.status)
rec('F12', 'H', 'Ohne Anmeldung nicht abrufbar', anon.get(f'/index.php?p=font&k={key}&s=R').raw[:4] != b'\x00\x01\x00\x00', '')
for kk, ss in (('../../config', 'R'), (key, '../R'), ('cf00000000', 'R'), (key, 'BI'), (key, 'I')):
    rr = s.get(f'/index.php?p=font&k={kk}&s={ss}')
    rec('F13', 'H', f'Ungültiger/fehlender Schnitt oder Schlüssel liefert 404: {kk[:14]}/{ss}', rr.status == 404 and rr.raw[:4] != b'\x00\x01\x00\x00', rr.status)
pd = s.get('/index.php?p=pdf_design').body
rec('F14', 'H', 'PDF-Design bietet die Schrift zur Auswahl an', f'value="{key}"' in pd and 'Carlito Test (eigene Schrift)' in pd, '')
# UI-Schrift
s.post('/index.php?p=settings', {'csrf': page_token(s), 'action': 'font_ui', 'ui_font': key})
pg = s.get('/index.php?p=customers').body
rec('F15', 'H', 'Oberfläche: @font-face mit eigener Adresse (kein externer Dienst) und font-family', f'@font-face{{font-family:"{key}"' in pg and f'?p=font&k={key}&s=R' in pg and 'http://' not in re.search(r'<style id="ui-font">(.*?)</style>', pg, re.S).group(1), '')
s.post('/index.php?p=settings', {'csrf': page_token(s), 'action': 'font_ui', 'ui_font': 'cf99999999'})
rec('F16', 'M', 'Unbekannte Oberflächenschrift wird nicht übernommen', f'font-family:"{key}"' in s.get('/index.php?p=customers').body, '')
rec('F17', 'M', 'Login-Seite lädt keine eigene Schrift (nicht angemeldet)', 'ui-font' not in anon.get('/index.php').body, '')
# PDF
dz = {'csrf': page_token(s, '/index.php?p=pdf_design'), 'action': 'save', 'font': key, 'size': 'normal', 'table': 'lines', 'sender': 'right', 'accent': '#333333', 'addrline': '1', 'footer': '1', 'closing': '', 'footer_text': ''}
s.post('/index.php?p=pdf_design', dz)
rec('F18', 'H', 'PDF-Design mit eigener Schrift speichern', f'"font":"{key}"' in open(APP + '/config.php').read().replace('\\', ''), '')
ic = cust('Font Kunde'); ifont = inv(ic, '50,00')
pdfr = s.get(f'/index.php?p=pdf&id={ifont}')
rec('F19', 'H', 'Rechnungs-PDF (PDF/A-3) bettet die eigene Schrift ein', pdfr.status == 200 and pdfr.raw[:5] == b'%PDF-' and b'Carlito' in pdfr.raw and b'FontFile2' in pdfr.raw, (pdfr.status, b'Carlito' in pdfr.raw))
ofont = offer(ic)
opp = f'/index.php?p=offer&id={ofont}'; s.post(opp, {'csrf': page_token(s, opp), 'action': 'issue'})
pdfo = s.get(f'/index.php?p=offer_pdf&id={ofont}')
rec('F20', 'M', 'Angebots-PDF nutzt die eigene Schrift ebenfalls', pdfo.status == 200 and b'Carlito' in pdfo.raw, pdfo.status)
rec('F21', 'H', 'Snapshot der Rechnung friert die Schrift ein', f'"font":"{key}"' in sql(f'select snapshot from invoices where id={ifont}').replace('\\', ''), '')
# Löschen
s.post('/index.php?p=settings', {'csrf': page_token(s), 'action': 'font_delete', 'key': key})
rec('F22', 'H', 'Schrift in Gebrauch (Oberfläche, Design, Beleg) lässt sich nicht löschen', nf() == 2, nf())
s.post('/index.php?p=settings', {'csrf': page_token(s), 'action': 'font_ui', 'ui_font': ''})
s.post('/index.php?p=pdf_design', dict(dz, font='dejavusanscondensed'))
s.post('/index.php?p=settings', {'csrf': page_token(s), 'action': 'font_delete', 'key': key})
rec('F23', 'H', 'Solange ausgestellte Belege die Schrift nutzen, bleibt sie geschützt', nf() == 2, nf())
pdf2 = s.get(f'/index.php?p=pdf&id={ifont}')
rec('F24', 'H', 'Ältere Rechnung behält ihre Schrift, obwohl das Design inzwischen wechselte', b'Carlito' in pdf2.raw, '')
# Fallback ohne Datei
for f in glob.glob(APP + '/storage/fonts/*.ttf'): os.rename(f, f + '.bak')
pdf3 = s.get(f'/index.php?p=pdf&id={ifont}')
rec('F25', 'H', 'Fehlt die Schriftdatei, entsteht trotzdem ein gültiges PDF (Rückfall auf Standardschrift)', pdf3.status == 200 and pdf3.raw[:5] == b'%PDF-', pdf3.status)
for f in glob.glob(APP + '/storage/fonts/*.bak'): os.rename(f, f[:-4])
rec('F26', 'M', 'Audit protokolliert Schriften', sql("select count(*) from audit_log where action='settings.fonts'") != '0', '')
save_results(label)
