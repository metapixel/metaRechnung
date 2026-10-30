"""Tests: Steuerberater-Stammdaten und Zusatzunterlagen im Export (ab 1.15.0). Frische Instanz, SMTP-Sink (RG_MAILS) laufend."""
import sys, os, re, glob, io, zipfile, datetime, email, email.policy, html as _h
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
def zbytes(files):
    b = io.BytesIO()
    with zipfile.ZipFile(b, 'w', zipfile.ZIP_DEFLATED) as z:
        for k, v in files.items(): z.writestr(k, v)
    return b.getvalue()
def save_settings(**kw):
    pg = s.get('/index.php?p=settings'); d = {'csrf': csrf_of(pg.body), 'action': 'save'}
    for m in re.finditer(r'<input[^>]*name="([A-Z_]+)"[^>]*value="([^"]*)"', pg.body): d[m.group(1)] = _h.unescape(m.group(2))
    d['SMTP_SECURE'] = 'none'; d.update(kw); return s.post('/index.php?p=settings', d)
def create(files=None, **kw):
    d = {'csrf': page_token(s, '/index.php?p=exports'), 'action': 'create', 'mode': 'custom', 'from': iso, 'to': iso, 'days': '14', 'with_xml': '1', 'mail_to': '', 'message': ''}
    d.update(kw)
    return s.req('POST', '/index.php?p=exports', data=d, files=files or [])
def cust(name):
    d = {'name': name, 'street': 's 1', 'zip': '12345', 'city': 'c', 'country': 'DE', 'email': 'k@example.com', 'csrf': page_token(s, '/index.php?p=customer_edit')}
    s.post('/index.php?p=customer_edit', d); return sql('select max(id) from customers')
def inv(cid):
    p = '/index.php?p=invoice_edit'
    s.post(p, {'csrf': page_token(s, p), 'customer_id': cid, 'issue_date': iso, 'due_date': '', 'delivery_date': '', 'notes': '', 'desc[]': ['L'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': ['100,00'], 'vat[]': ['19']})
    i = int(sql('select max(id) from invoices')); pp = f'/index.php?p=invoice&id={i}'
    s.post(pp, {'csrf': page_token(s, pp), 'action': 'issue'}); return i
def last_export_zip():
    f = sql('select file from exports order by id desc limit 1'); return zipfile.ZipFile(f'{EXP}/{f}')

# --- Stammdaten
pg = s.get('/index.php?p=settings')
rec('A1', 'M', 'Einstellungen: Abschnitt „Steuerberater“ mit Name und E-Mail', 'name="TAX_ADVISOR_NAME"' in pg.body and 'name="TAX_ADVISOR_EMAIL"' in pg.body and 'id="steuerberater"' in pg.body)
cfg0 = open(APP + '/config.php').read()
r = save_settings(TAX_ADVISOR_EMAIL='keine-mail'); rec('A2', 'M', 'Ungültige Steuerberater-Adresse wird abgelehnt, config unverändert', 'Steuerberater: ungültige E-Mail' in r.body and open(APP + '/config.php').read() == cfg0, r.status)
r = save_settings(TAX_ADVISOR_NAME='x' * 121); rec('A3', 'L', 'Zu langer Name wird abgelehnt', 'zu lang' in r.body and open(APP + '/config.php').read() == cfg0, r.status)
r = save_settings(TAX_ADVISOR_NAME='Dr. Sabine <b>Steuer</b>', TAX_ADVISOR_EMAIL='sabine@steuer.example')
rec('A4', 'H', 'Gültige Werte werden gespeichert', r.status == 302 and "'TAX_ADVISOR_EMAIL' => 'sabine@steuer.example'" in open(APP + '/config.php').read(), r.status)
ex = s.get('/index.php?p=exports')
rec('A5', 'H', 'Export-Seite: Adresse vorausgefüllt, Ansprechpartner angezeigt und escaped, Anrede mit Namen in der Nachricht', 'value="sabine@steuer.example"' in ex.body and 'Standard-Ansprechpartner' in ex.body and '<b>Steuer</b>' not in ex.body and 'Guten Tag Dr. Sabine &lt;b&gt;Steuer&lt;/b&gt;,' in ex.body, ex.status)
rec('A6', 'M', 'Export-Formular ist ein Datei-Upload-Formular mit Feldern für Kontoauszüge, Rechnungseingang und weitere Unterlagen', 'enctype="multipart/form-data"' in ex.body and all(f'name="doc_{k}"' in ex.body for k in ('bank', 'incoming', 'other')) and 'Kontoauszüge' in ex.body and 'Rechnungseingang' in ex.body, '')

# --- Daten
c1 = cust('Export-Kunde'); inv(c1)
bank = zbytes({'2026-09.pdf': b'%PDF-1.4 fake bank', 'okt.csv': 'a;b\n1;2\n'}); incoming = zbytes({'rechnung1.pdf': b'%PDF-1.4 fake in'})
pdf = b'%PDF-1.4\n%fake\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF'
n0 = n_exp()
r = create(files=[('doc_bank', 'Kontoauszüge Q3.zip', bank, 'application/zip'), ('doc_incoming', 'ER 2026.ZIP', incoming, 'application/zip'), ('doc_other', '../../Jahres Abschluss?.pdf', pdf, 'application/pdf')])
rec('U1', 'H', 'Export mit 3 Zusatzunterlagen erstellt (Redirect, ein neuer Export)', r.status == 302 and n_exp() == n0 + 1, (r.status, re.sub(r'<[^>]+>', ' ', r.body)[:200]))
z = last_export_zip(); names = z.namelist()
rec('U2', 'H', 'ZIP enthält zusatzunterlagen/Kontoauszuege.zip, Rechnungseingang.zip und die PDF – byte-identisch, nicht entpackt', z.read('zusatzunterlagen/Kontoauszuege.zip') == bank and z.read('zusatzunterlagen/Rechnungseingang.zip') == incoming and any(n.startswith('zusatzunterlagen/') and n.endswith('.pdf') and z.read(n) == pdf for n in names) and not any('2026-09.pdf' in n or 'rechnung1.pdf' in n for n in names), names)
pn = [n for n in names if n.endswith('.pdf') and n.startswith('zusatzunterlagen/')][0]
rec('U3', 'H', 'Dateiname aus dem Upload wird bereinigt (kein Pfad, keine „..“, keine Sonderzeichen); keine Pfadflucht im ZIP', all('..' not in n and not n.startswith('/') and '\\' not in n and '?' not in n for n in names) and pn.count('/') == 1, pn)
rm = z.read('LIESMICH.txt').decode('utf-8')
rec('U4', 'M', 'LIESMICH nennt die beigelegten Unterlagen samt Originaldateinamen', 'zusatzunterlagen/' in rm and 'Kontoauszuege.zip' in rm and 'Kontoauszüge Q3.zip' in rm and 'Rechnungseingang.zip' in rm, rm[-500:])
rec('U5', 'M', 'Rechnungsinhalt unverändert: PDFs, CSV vorhanden', any(n.startswith('rechnungen/') for n in names) and 'rechnungsausgangsbuch.csv' in names, names)
au = sql("select summary from audit_log where action='export.create' order by id desc limit 1")
rec('U6', 'M', 'Protokoll nennt die beigelegten Unterlagen', '3 Unterlagen' in au and 'Kontoauszuege.zip' in au, au)
rec('U7', 'M', 'Meldung auf der Seite: 3 beigelegte Unterlagen', '3 beigelegte Unterlagen' in s.get('/index.php?p=exports').body, '')
tok = sql('select token from exports order by id desc limit 1'); dl = anon.get('/export.php?t=' + tok + '&dl=1')
rec('U8', 'H', 'Download über den Link liefert das ZIP samt Zusatzunterlagen', dl.status == 200 and 'zusatzunterlagen/Kontoauszuege.zip' in zipfile.ZipFile(io.BytesIO(dl.raw)).namelist(), dl.status)
rec('U9', 'L', 'Landingpage erwähnt beigelegte Unterlagen', 'zusatzunterlagen' in anon.get('/export.php?t=' + tok).body, '')

# --- Ablehnungen
def rej(tid, title, files, needle=None):
    n = n_exp(); r = create(files=files); body = r.body + s.get('/index.php?p=exports').body
    rec(tid, 'H', title, n_exp() == n and (needle is None or needle in body), (r.status, re.sub(r'<[^>]+>', ' ', r.body)[:160]))
rej('V1', 'Textdatei mit Endung .zip wird abgelehnt', [('doc_bank', 'x.zip', b'das ist kein zip', 'application/zip')], 'kein gültiges ZIP')
rej('V2', 'Leeres ZIP (ohne Einträge) wird abgelehnt', [('doc_bank', 'leer.zip', zipfile.ZipFile(io.BytesIO(), 'w').fp.getvalue() if False else b'PK\x05\x06' + b'\0' * 18, 'application/zip')], 'kein gültiges ZIP')
rej('V3', 'PDF im Feld Kontoauszüge (nur ZIP) wird abgelehnt', [('doc_bank', 'k.pdf', pdf, 'application/pdf')], 'Erlaubt ist nur ZIP')
rej('V4', 'Ausführbare Endung (.php / .exe) wird abgelehnt', [('doc_other', 'shell.php', b'<?php system($_GET[0]);', 'text/plain')], 'Erlaubt ist nur')
rej('V5', 'ZIP mit falscher Endung (.exe) wird abgelehnt', [('doc_other', 'x.exe', bank, 'application/octet-stream')], 'Erlaubt ist nur')
rej('V6', 'PDF-Endung mit ZIP-Inhalt wird abgelehnt', [('doc_other', 'x.pdf', bank, 'application/pdf')], 'kein gültiges PDF')
rej('V7', 'Leere Datei wird abgelehnt', [('doc_incoming', 'e.zip', b'', 'application/zip')])
big = b'PK\x03\x04' + b'\0' * (27 * 1024 * 1024)
rej('V8', 'Zu große Datei (> 25 MB) wird abgelehnt, nichts angelegt', [('doc_incoming', 'gross.zip', big, 'application/zip')])
n = n_exp(); r = anon.req('POST', '/index.php?p=exports', data={'action': 'create', 'mode': 'custom', 'from': iso, 'to': iso}, files=[('doc_bank', 'a.zip', bank, 'application/zip')])
rec('V9', 'H', 'Anonym: Upload wirkungslos', n_exp() == n, r.status)
n = n_exp(); r = s.req('POST', '/index.php?p=exports', data={'csrf': 'falsch', 'action': 'create', 'mode': 'custom', 'from': iso, 'to': iso}, files=[('doc_bank', 'a.zip', bank, 'application/zip')])
rec('V10', 'H', 'Ohne gültiges CSRF-Token: Upload wirkungslos', n_exp() == n and r.status in (400, 403), r.status)
r = create(files=[('doc_bank', 'ok.zip', bank, 'application/zip'), ('doc_other', 'bad.exe', b'MZ', 'application/octet-stream')])
rec('V11', 'H', 'Eine ungültige Datei unter mehreren verhindert den ganzen Export (kein Teilergebnis, keine Restdatei)', n_exp() == n and not glob.glob(EXP + '/*.part'), r.status)
r = create(); z = last_export_zip()
rec('V12', 'M', 'Export ohne Zusatzunterlagen funktioniert wie zuvor (kein Ordner zusatzunterlagen)', not any(n.startswith('zusatzunterlagen') for n in z.namelist()) and 'zusatzunterlagen' not in z.read('LIESMICH.txt').decode('utf-8'), r.status)
r = create(files=[('doc_other', 'zwei.zip', bank, 'application/zip')]); z = last_export_zip()
rec('V13', 'M', 'Einzelne optionale Datei (nur „Weitere Unterlagen“) genügt, Originalname bleibt (bereinigt)', 'zusatzunterlagen/zwei.zip' in z.namelist(), z.namelist())

# --- Mail
m0 = len(mails())
r = create(files=[('doc_bank', 'k.zip', bank, 'application/zip')], send='1', mail_to='sabine@steuer.example', message=_h.unescape(re.search(r'name="message"[^>]*>(.*?)</textarea>', s.get('/index.php?p=exports').body, re.S).group(1)))
ok = len(mails()) == m0 + 1
if ok:
    m = email.message_from_bytes(open(mails()[-1], 'rb').read(), policy=email.policy.default); body = m.get_body(preferencelist=('plain',)).get_content()
    rec('W1', 'H', 'Mail an den Steuerberater: Empfänger, Anrede mit Namen, Link vorhanden, kein Anhang (Unterlagen nur im Link-ZIP)', 'sabine@steuer.example' in m['To'] and body.startswith('Guten Tag Dr. Sabine <b>Steuer</b>,') and 'export.php?t=' in body and not list(m.iter_attachments()), body[:120])
else:
    rec('W1', 'H', 'Mail versendet', False, (len(mails()), m0))
# gespeicherte Adresse bleibt
rec('W2', 'L', 'Standard-Ansprechpartner bleibt erhalten (wird nicht durch zuletzt genutzte Adresse überschrieben)', "'TAX_ADVISOR_EMAIL' => 'sabine@steuer.example'" in open(APP + '/config.php').read(), '')
save_settings(TAX_ADVISOR_NAME='', TAX_ADVISOR_EMAIL='')
ex = s.get('/index.php?p=exports')
rec('W3', 'L', 'Ohne Steuerberater-Stammdaten: Hinweis auf die Einstellungen und neutrale Anrede', 'Tipp: Hinterlege den Steuerberater' in ex.body and 'Guten Tag,' in ex.body, '')
rec('W4', 'M', 'Unterlagen-Ordner im Export ist von außen nicht abrufbar (storage geschützt)', anon.get('/storage/exports/').status in (403, 404), '')
save_results(label)
