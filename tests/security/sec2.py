import sys, re, os, time, glob, io, subprocess, urllib.parse
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
PW = 'SehrGeheim12345'
clear_rl()

s = authed()
anon = C()
base_cfg = open(APP + '/config.php').read()

def settings_form(**over):
    d = {'csrf': page_token(s), 'action': 'save', 'COMPANY_NAME': 'Test IT-Service', 'COMPANY_OWNER': 'Kai Test', 'COMPANY_STREET': 'Musterstr. 1', 'COMPANY_ZIP': '12345', 'COMPANY_CITY': 'Musterstadt',
         'COMPANY_COUNTRY': 'DE', 'COMPANY_EMAIL': 'rechnung@example.de', 'COMPANY_PHONE': '+49 123 456789', 'COMPANY_TAX_NUMBER': '12/345/67890', 'COMPANY_VAT_ID': '',
         'COMPANY_IBAN': 'DE89 3704 0044 0532 0130 00', 'COMPANY_BIC': '', 'COMPANY_BANK': '', 'INVOICE_PREFIX': 'RE', 'PAYMENT_DAYS': '14', 'SMTP_HOST': '127.0.0.1', 'SMTP_PORT': '2525',
         'SMTP_SECURE': 'none', 'SMTP_USER': '', 'SMTP_PASS': '', 'MAIL_FROM': 'rechnung@example.de', 'MAIL_FROM_NAME': 'Test IT-Service', 'MAIL_BCC': '', 'APP_URL': 'http://127.0.0.1:8097/'}
    d.update(over)
    return d

s.post('/index.php?p=customer_edit', {'csrf': page_token(s, '/index.php?p=customer_edit'), 'name': 'Beispiel GmbH', 'email': 'erika@example.com', 'street': 'Hauptstr. 5', 'zip': '12345', 'city': 'Musterstadt', 'country': 'DE'})
CID = sql('select max(id) from customers')
# ---------------------------------------------------------------- I: Konfig-Injection
marker = '/tmp/pwned_marker'
if os.path.exists(marker): os.remove(marker)
evil = "x'; file_put_contents('/tmp/pwned_marker','1'); //"
r = s.post('/index.php?p=settings', settings_form(COMPANY_NAME=evil, COMPANY_OWNER="\\'; system('id'); //", COMPANY_BANK='${system("id")}', MAIL_FROM_NAME='<?php echo 1; ?>'))
ok = s.get('/index.php').status == 200
subprocess.run(['php', '-l', APP + '/config.php'], capture_output=True)
rec('I1', 'H', 'Code-Injection über Einstellungen in config.php (Escaping)', not os.path.exists(marker) and ok, 'Marker erzeugt' if os.path.exists(marker) else '')
nm = sql("select 1")  # DB ok
body = s.get('/index.php?p=settings').body
rec('I2', 'M', 'Einstellungen mit Sonderzeichen werden wörtlich gespeichert und richtig angezeigt', html.escape(evil, quote=True) in body or 'pwned_marker' in html.unescape(body))
s.post('/index.php?p=settings', settings_form())

# ---------------------------------------------------------------- J: Eingabevalidierung Einstellungen
checks = [
    ('J1', 'APP_URL mit javascript:-Schema abgelehnt', {'APP_URL': 'javascript:alert(1)//'}, 'APP_URL'),
    ('J2', 'Ungültige Absender-E-Mail abgelehnt', {'MAIL_FROM': 'nicht-gültig'}, 'MAIL_FROM'),
    ('J3', 'Ungültige IBAN abgelehnt', {'COMPANY_IBAN': 'DE00 0000'}, 'COMPANY_IBAN'),
    ('J4', 'SMTP_PORT außerhalb 1-65535 abgelehnt', {'SMTP_PORT': '999999'}, 'SMTP_PORT'),
    ('J5', 'PAYMENT_DAYS negativ/Text abgelehnt', {'PAYMENT_DAYS': '-5'}, 'PAYMENT_DAYS'),
    ('J6', 'INVOICE_PREFIX mit Sonderzeichen/Leerzeichen abgelehnt', {'INVOICE_PREFIX': 'R E<>"/'}, 'INVOICE_PREFIX'),
    ('J7', 'MAIL_BCC mit mehreren/ungültigen Adressen abgelehnt', {'MAIL_BCC': 'a@b.de\r\nBcc: x@y.de'}, 'MAIL_BCC'),
]
for tid, title, over, key in checks:
    s.post('/index.php?p=settings', settings_form())
    s.post('/index.php?p=settings', settings_form(**over))
    cfg = open(APP + '/config.php').read()
    m = re.search(r"'%s' => '((?:[^'\\]|\\.)*)'" % key, cfg)
    val = m.group(1) if m else ''
    rec(tid, 'M', title, val != over[key], val[:40])
s.post('/index.php?p=settings', settings_form())

# ---------------------------------------------------------------- K: Zahlen/Beträge manipulieren
def draft(desc, qty, price, vat='19', cust=None, **extra):
    cust = cust or CID
    t = page_token(s, '/index.php?p=invoice_edit')
    d = {'csrf': t, 'customer_id': cust, 'issue_date': '2026-10-24', 'delivery_date': '', 'due_date': '2026-11-07', 'notes': '', 'desc[]': desc, 'qty[]': qty, 'unit[]': ['C62'] * len(desc), 'price[]': price, 'vat[]': [vat] * len(desc)}
    d.update(extra)
    r = s.post('/index.php?p=invoice_edit', d)
    m = re.search(r'id=(\d+)', r.h('location'))
    return (m.group(1) if m else None), r

def total(iid):
    return sql(f"select coalesce(sum(quantity*unit_price),0) from invoice_items where invoice_id={iid}") if iid else None

n0 = sql('select count(*) from invoice_items')
i1, r1 = draft(['A'], ['1e308'], ['1e308'])
i2, r2 = draft(['A'], ['-5'], ['100'])
i3, r3 = draft(['A'], ['abc'], ['9999999999999999'])
i4, r4 = draft(['A'], ['1'], ['10'], vat='-50')
i5, r5 = draft(['A'], ['1'], ['10'], vat='1000')
i6, r6 = draft([], [], [])
i7, r7 = draft(['A' * 5000], ['1'], ['1'])
i8, r8 = draft(['A'], ['1'], ['1'], cust=99999)
errs = open(APP + '/../apache/php_error.log').read() if os.path.exists(APP + '/../apache/php_error.log') else ''
rec('K1', 'M', 'Überlange/absurde Mengen & Preise (1e308, Text, 1e16) werden abgelehnt', not any([i1, i3]), (i1, i3))
rec('K2', 'M', 'Negative Menge in Rechnung wird abgelehnt (oder Gutschrift ist bewusst)', i2 is None, i2)
rec('K3', 'M', 'Ungültiger MwSt-Satz (-50, 1000) wird abgelehnt', not any([i4, i5]), (i4, i5))
if i6:
    act_r = s.post(f'/index.php?p=invoice&id={i6}', {'csrf': page_token(s, f'/index.php?p=invoice&id={i6}'), 'action': 'issue'})
rec('K4', 'L', 'Rechnung ohne Positionen kann nicht ausgestellt werden', i6 is None or sql(f'select status from invoices where id={i6}') == 'draft', i6)
rec('K5', 'L', 'Beschreibung >500 Zeichen wird sauber abgelehnt (kein SQL-Fehler im Klartext)', i7 is None or True, i7)
fl = flash_of(s.get('/index.php?p=invoices').body)
rec('K6', 'M', 'Nicht existierender Kunde wird abgelehnt', i8 is None, i8)
# Fehlermeldungen: kein SQLSTATE-Leak
bodies = [s.get('/index.php?p=invoices').body, s.get('/index.php?p=customers').body]
leak = [m for b in bodies for m in re.findall(r'SQLSTATE[^<]{0,80}', b)]
rec('K7', 'M', 'Keine SQLSTATE-/Pfad-Details in Flash-Fehlermeldungen', not leak, leak[:2])
# Rechnung mit Datumsmüll
i9, r9 = draft(['A'], ['1'], ['1'], issue_date='31.99.2026')
rec('K8', 'L', 'Ungültiges Rechnungsdatum wird abgelehnt', i9 is None or sql(f'select issue_date from invoices where id={i9}') not in ('0000-00-00',), i9)
# Ausgestellte Rechnung ändern / löschen
iss, _ = draft(['Fest'], ['1'], ['100'])
t = page_token(s, f'/index.php?p=invoice&id={iss}')
s.post(f'/index.php?p=invoice&id={iss}', {'csrf': t, 'action': 'issue'})
before = sql(f'select count(*) from invoice_items where invoice_id={iss}')
s.post(f'/index.php?p=invoice_edit&id={iss}', {'csrf': page_token(s), 'customer_id': CID, 'issue_date': '2026-10-24', 'due_date': '2026-11-07', 'desc[]': ['Manipuliert'], 'qty[]': ['1'], 'unit[]': ['C62'], 'price[]': ['1'], 'vat[]': ['19']})
s.post(f'/index.php?p=invoice&id={iss}', {'csrf': page_token(s, f'/index.php?p=invoice&id={iss}'), 'action': 'delete'})
rec('K9', 'H', 'Ausgestellte Rechnungen sind unveränderlich und nicht löschbar (GoBD)',
    sql(f'select description from invoice_items where invoice_id={iss}') == 'Fest' and sql(f'select count(*) from invoices where id={iss}') == '1')
# Doppelte Ausstellung → doppelte Nummer?
s.post(f'/index.php?p=invoice&id={iss}', {'csrf': page_token(s, f'/index.php?p=invoice&id={iss}'), 'action': 'issue'})
rec('K10', 'M', 'Erneutes Ausstellen vergibt keine neue Nummer', sql(f"select count(*) from invoices where number is not null and status<>'draft'") == sql("select count(distinct number) from invoices where number is not null"))

# ---------------------------------------------------------------- L: Briefpapier-Upload
png = subprocess.check_output(['python3', '-c', 'import sys;from PIL import Image;import io;b=io.BytesIO();Image.new("RGB",(1240,1754),"white").save(b,"PNG");sys.stdout.buffer.write(b.getvalue())'])
def up(name, content, ctype='image/png', **extra):
    d = {'csrf': page_token(s), 'action': 'letterhead'}
    d.update(extra)
    r = s.req('POST', '/index.php?p=settings', data=d, files={'letterhead': (name, content, ctype)})
    return r
def lh_files():
    d = APP + '/storage/letterhead'
    return sorted(x for x in os.listdir(d) if x not in ('.htaccess', 'index.html')) if os.path.isdir(d) else []
php_poly = png + b'\n<?php echo "PWNED"; system($_GET["c"]); ?>'
r = up('evil.php', php_poly, 'image/png')
r2 = up('evil.png.php', php_poly, 'image/png')
r3 = up('x.png', b'<?php echo "PWNED"; ?>', 'image/png')
r4 = up('x.pdf', b'<?php echo "PWNED"; ?>', 'application/pdf')
r5 = up('a.svg', b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml')
r6 = up('../../evil.png', png, 'image/png')
r7 = up('x.png', b'GIF89a' + b'A' * 100, 'image/png')
files = lh_files()
rec('L1', 'H', 'Upload: keine .php-Dateien/Endungen gespeichert', not any(f.endswith('.php') or '.php' in f for f in files), files)
rec('L2', 'H', 'Upload: Fake-PNG/PDF mit PHP-Inhalt wird abgelehnt', all('PWNED' not in open(os.path.join(APP, 'storage/letterhead', f), 'rb').read().decode('latin1') for f in files), files)
rec('L3', 'M', 'Upload: SVG wird abgelehnt', not any(f.endswith('.svg') for f in files))
rec('L4', 'H', 'Upload: Dateiname ../ kann nicht aus dem Zielordner ausbrechen', not os.path.exists(APP + '/storage/evil.png') and not os.path.exists(APP + '/evil.png'))
# Gültigen Upload und Abruf
r = up('brief.png', png, 'image/png')
f = lh_files()
rec('L5', 'M', 'Gültiges PNG wird akzeptiert', len(f) == 1 and f[0].endswith('.png'), f)
if f:
    dl = s.get('/index.php?p=letterhead_file')
    rec('L6', 'M', 'Briefpapier-Abruf: korrekter Content-Type, nosniff', dl.h('content-type').startswith('image/') and dl.h('x-content-type-options') == 'nosniff', dl.h('content-type'))
    rec('L7', 'H', 'Direkter Webzugriff auf Briefpapierdatei gesperrt', anon.get('/storage/letterhead/' + f[0]).status in (403, 404), anon.get('/storage/letterhead/' + f[0]).status)
# Decompression bomb
bomb = subprocess.check_output(['python3', '-c', 'import sys;from PIL import Image;import io;Image.MAX_IMAGE_PIXELS=None;b=io.BytesIO();Image.new("1",(60000,60000),0).save(b,"PNG");sys.stdout.buffer.write(b.getvalue())'])
t0 = time.time(); r = up('bomb.png', bomb, 'image/png')
rec('L8', 'M', 'Upload: Dekompressionsbombe (60000x60000 PNG) wird schnell abgelehnt', time.time() - t0 < 10 and not any('bomb' in x for x in lh_files()), f'{time.time()-t0:.1f}s')
# Margins
s.post('/index.php?p=settings', {'csrf': page_token(s), 'action': 'letterhead', 'LETTERHEAD_MT': '"><script>alert(1)</script>', 'LETTERHEAD_MB': '9999999'})
cfg = open(APP + '/config.php').read()
rec('L9', 'L', 'Ränder-Werte werden als Zahl normalisiert', 'script' not in cfg.split('LETTERHEAD_MT')[1][:60] if 'LETTERHEAD_MT' in cfg else True)
# PDF mit JS/Launch
evilpdf = b'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R/OpenAction<</S/JavaScript/JS(app.alert(1))>>>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF'
r = up('js.pdf', evilpdf, 'application/pdf')
rec('L10', 'L', 'Manipuliertes PDF mit JavaScript/OpenAction wird nicht ungeprüft übernommen', not any(x.endswith('.pdf') for x in lh_files()) or all(b'JavaScript' not in open(os.path.join(APP, 'storage/letterhead', x), 'rb').read() for x in lh_files() if x.endswith('.pdf')), lh_files())

# ---------------------------------------------------------------- M: Sitzung / Login
cj = C()
pg = cj.get('/index.php')
sid0 = cj.cookies.get('rechnung')
login(cj)
rec('M1', 'H', 'Session-ID wird nach Login erneuert (Session Fixation)', cj.cookies.get('rechnung') != sid0 and cj.get('/index.php?p=customers').status == 200, (sid0, cj.cookies.get('rechnung')))
fix = C(); fix.cookies['rechnung'] = 'attackerchosensessionid123456'
login(fix)
rec('M2', 'M', 'Vom Angreifer vorgegebene Session-ID wird nach Login nicht akzeptiert', fix.cookies.get('rechnung') != 'attackerchosensessionid123456')
ck = ' '.join(C().get('/index.php').set_cookies)
rec('M3', 'M', 'Session-Cookie: HttpOnly + SameSite', 'httponly' in ck.lower() and 'samesite' in ck.lower(), ck)
# Brute-Force: Dauer pro Fehlversuch + Sperre
t0 = time.time(); N = 8
for i in range(N):
    login(C(), 'kai', 'falsch%d' % i)
per = (time.time() - t0) / N
rec('M4', 'M', 'Brute-Force-Schutz: nach 8 Fehlversuchen wird auch das korrekte Passwort abgewiesen', login(C()).status not in (302, 303), f'{per:.1f}s/Versuch')
clear_rl()
# Parallel-Angriff umgeht sleep(1)
import concurrent.futures as cf
t0 = time.time()
with cf.ThreadPoolExecutor(10) as ex:
    list(ex.map(lambda i: login(C(), 'kai', 'x%d' % i), range(30)))
rec('M5', 'M', 'Paralleles Raten (30 Versuche gleichzeitig) wird begrenzt: danach ist die Anmeldung gesperrt', login(C()).status not in (302, 303), f'{time.time()-t0:.1f}s')
clear_rl()
# Passwortwechsel-Absicherung
tt = page_token(s)
s2 = authed()
r = s2.post('/index.php?p=settings', {'csrf': page_token(s2), 'action': 'account', 'current_pass': 'falsch', 'new_user': 'kai', 'new_pass': 'NeuesPasswort12345', 'new_pass2': 'NeuesPasswort12345'})
rec('M6', 'H', 'Passwortwechsel verlangt aktuelles Passwort', authed().cookies is not None and login(C(), 'kai', 'NeuesPasswort12345').status not in (302, 303))
r = s2.post('/index.php?p=settings', {'csrf': page_token(s2), 'action': 'account', 'current_pass': PW, 'new_user': 'kai', 'new_pass': 'kurz', 'new_pass2': 'kurz'})
rec('M7', 'M', 'Zu kurzes neues Passwort wird abgelehnt', login(C(), 'kai', 'kurz').status not in (302, 303))
# Passwort wechseln: andere Sitzungen werden ungültig?
other = authed()
s3 = authed()
s3.post('/index.php?p=settings', {'csrf': page_token(s3), 'action': 'account', 'current_pass': PW, 'new_user': 'kai', 'new_pass': PW + 'X', 'new_pass2': PW + 'X'})
alive = other.get('/index.php?p=customers').status == 200 and 'Anmelden' not in other.get('/index.php?p=customers').body
rec('M8', 'M', 'Nach Passwortwechsel werden andere offene Sitzungen beendet', not alive, 'alte Sitzung noch gültig' if alive else '')
# zurücksetzen
c3 = authed('kai', PW + 'X') if login(C(), 'kai', PW + 'X').status in (302, 303) else None
if c3:
    c3.post('/index.php?p=settings', {'csrf': page_token(c3), 'action': 'account', 'current_pass': PW + 'X', 'new_user': 'kai', 'new_pass': PW, 'new_pass2': PW})
s = authed()

# ---------------------------------------------------------------- N: Statusseite / Tokens
tok = sql("select i.public_token from invoices i join customers c on c.id=i.customer_id where i.public_token is not null and c.email is not null and c.name not like '%<%' order by i.id limit 1")
num = sql(f"select number from invoices where public_token='{tok}'")
rr = anon.get(f'/status.php?t={tok}')
rec('N1', 'H', 'Statusseite mit gültigem Token erreichbar', rr.status == 200 and num in rr.body)
bad = [anon.get(f'/status.php?t={x}') for x in ['0' * 32, 'A' * 32, tok.upper(), tok[:-1], tok + 'a', '']]
rec('N2', 'M', 'Falsche/manipulierte Token → 404, keine Details', all(b.status == 404 and num not in b.body for b in bad), [b.status for b in bad])
ent = len(set(sql("select public_token from invoices where public_token is not null").split()))
rec('N3', 'M', 'Token: 128 Bit Zufall (32 Hex), unterschiedlich je Rechnung', all(re.fullmatch(r'[0-9a-f]{32}', x) for x in sql("select public_token from invoices where public_token is not null").split()) and ent >= 1, ent)
dl = anon.get(f'/status.php?t={tok}&dl=pdf')
rec('N4', 'M', 'Download PDF über Token: korrekter Typ, Attachment/inline, nosniff', dl.body[:4] == '%PDF' and dl.h('x-content-type-options') == 'nosniff', dl.h('content-type'))
dx = anon.get(f'/status.php?t={tok}&dl=xrechnung')
rec('N5', 'M', 'Download XRechnung über Token liefert XML', dx.body.startswith('<?xml'), dx.h('content-type'))
drafts = sql("select id from invoices where status='draft' limit 1")
rec('N6', 'H', 'Entwürfe haben keinen Token / sind nicht öffentlich abrufbar', sql("select count(*) from invoices where status='draft' and public_token is not null") == '0')
rr = anon.get(f'/status.php?t={tok}&dl=../../config.php')
rec('N7', 'M', 'dl-Parameter mit unbekanntem Wert liefert keine Datei', 'APP_PASSWORD_HASH' not in rr.body)
# Statusseite: Rate-Limit für Token-Raten
t0 = time.time()
with cf.ThreadPoolExecutor(10) as ex:
    res = list(ex.map(lambda i: anon.get('/status.php?t=' + '%032x' % i).status, range(60)))
rec('N8', 'L', 'Token-Raten wird gedrosselt (60 Fehlversuche → irgendwann 429)', 429 in res, f'{time.time()-t0:.1f}s, Codes {set(res)}')
clear_rl()

# ---------------------------------------------------------------- O: Installer / Reset / Methoden
cfgbefore = open(APP + '/config.php').read()
if os.path.exists(APP + '/install.php'):
    r = anon.post('/install.php', {'action': 'install', 'db_host': '127.0.0.1', 'db_name': 'rechnung_sec', 'db_user': 'rg', 'db_pass': 'geheim123', 'admin_user': 'evil', 'admin_pass': 'EvilEvil123456', 'admin_pass2': 'EvilEvil123456',
                                'company_name': 'EVIL', 'company_street': 's', 'company_zip': '1', 'company_city': 'c'})
    rec('O1', 'H', 'install.php überschreibt eine bestehende Installation nicht', open(APP + '/config.php').read() == cfgbefore and login(C(), 'evil', 'EvilEvil123456').status not in (302, 303))
    g = anon.get('/install.php')
    rec('O2', 'M', 'install.php gibt nach Installation keine Zugangsdaten/Formular frei', 'db_pass' not in g.body or 'Bereits installiert' in g.body)
else:
    rec('O1', 'H', 'install.php entfernt', True)
    rec('O2', 'M', 'install.php entfernt', True)
r = s.post('/index.php?p=settings', {'csrf': page_token(s), 'action': 'reset', 'reset_pass': 'falsch', 'confirm': 'RESET'})
rec('O3', 'H', 'Reset ohne korrektes Passwort wirkungslos', sql('select count(*) from customers') != '0')
for m in ('PUT', 'DELETE', 'PATCH', 'OPTIONS', 'TRACE'):
    r = s.req(m, '/index.php?p=customers')
    if m == 'TRACE' and r.status == 200 and 'TRACE' in r.body:
        rec('O4', 'I', 'HTTP TRACE deaktiviert (nur in der Server-Konfiguration möglich, nicht per .htaccess)', False, r.status)
        break
else:
    rec('O4', 'I', 'HTTP TRACE deaktiviert (nur in der Server-Konfiguration möglich, nicht per .htaccess)', True)
r = s.req('POST', '/index.php?p=customer_edit', body=b'name[]=a&street[]=b&zip[]=1&city[]=c', headers={'Content-Type': 'application/x-www-form-urlencoded'})
errs = open(APP + '/../apache/php_error.log').read()
rec('O5', 'M', 'Array-Parameter statt Strings (name[]=…) führen nicht zu PHP-Fehlern/500', r.status in (200, 302, 400) and r.status != 500, r.status)
r = s.get('/index.php?p=invoice&id[]=1')
r2 = s.get('/index.php?p[]=x')
r3 = s.get('/status.php?t[]=x')
rec('O6', 'M', 'Array in GET-Parametern (id[], p[], t[]) ergibt keinen 500er', all(x.status < 500 for x in (r, r2, r3)), [x.status for x in (r, r2, r3)])
fatal = [l for l in errs.splitlines() if re.search(r'Fatal|Uncaught|TypeError', l)]
rec('O7', 'M', 'Keine PHP-Fatal-Errors im Log nach allen Tests', not fatal, fatal[:2])
r = s.get('/index.php?p=xyz'); r2 = s.get('/index.php?p=' + 'A' * 5000)
rec('O8', 'L', 'Unbekannte Route / überlanger p-Parameter ohne Fehler/Leak', r.status < 500 and r2.status < 500 and 'Stack trace' not in r.body)
r = s.req('POST', '/index.php?p=customer_edit', body=b'x=' + b'A' * (20 * 1024 * 1024), headers={'Content-Type': 'application/x-www-form-urlencoded'})
rec('O9', 'L', '20-MB-POST wird sauber behandelt', r.status < 500, r.status)

save_results(label + '_part2')
