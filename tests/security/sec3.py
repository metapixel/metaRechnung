"""Tests für Dateianhänge beim Rechnungsversand (ab 1.4.0). Frische Instanz, SMTP-Sink laufend (RG_MAILS)."""
import sys, os, re, glob, time, email, io, zipfile
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
from email import policy
label = sys.argv[1]
MAILS = os.environ.get('RG_MAILS', '/tmp/mails')
s = authed()
s.post('/index.php?p=customer_edit', {'csrf': page_token(s, '/index.php?p=customer_edit'), 'name': 'Beispiel GmbH', 'email': 'erika@example.com', 'street': 'Hauptstr. 5', 'zip': '12345', 'city': 'Musterstadt', 'country': 'DE'})
cid = sql('select max(id) from customers')
t = page_token(s, '/index.php?p=invoice_edit')
r = s.post('/index.php?p=invoice_edit', {'csrf': t, 'customer_id': cid, 'issue_date': '2026-10-24', 'due_date': '2026-11-07', 'desc[]': ['Satz'], 'qty[]': ['1'], 'unit[]': ['C62'], 'price[]': ['100'], 'vat[]': ['19']})
iid = re.search(r'id=(\d+)', r.h('location')).group(1)
s.post(f'/index.php?p=invoice&id={iid}', {'csrf': page_token(s, f'/index.php?p=invoice&id={iid}'), 'action': 'issue'})

def mails(): return sorted(glob.glob(MAILS + '/*.eml'))
def send(files, to='kunde@example.com', csrf=True, extra=None):
    d = {'action': 'send', 'to': to, 'message': 'Hallo'}
    if csrf: d['csrf'] = page_token(s, f'/index.php?p=invoice&id={iid}')
    if extra: d.update(extra)
    n0 = len(mails())
    r = s.req('POST', f'/index.php?p=invoice&id={iid}', data=d, files=files if files else [], timeout=120) if files else s.post(f'/index.php?p=invoice&id={iid}', d)
    time.sleep(0.4)
    fl = flash_of(s.get(f'/index.php?p=invoice&id={iid}').body)
    return r, fl, len(mails()) - n0

def parse(path):
    raw = open(path, 'rb').read().split(b'\n', 1)[1]   # ENVELOPE-Zeile entfernen
    return email.message_from_bytes(raw, policy=policy.default)

for f in mails(): os.remove(f)
before = set(os.popen(f'find {APP}/storage -type f').read().split())
pdf = b'%PDF-1.4\n%Korrekturabzug\n' + b'x' * 500
png = open('/dev/urandom', 'rb').read(0) + b'\x89PNG\r\n\x1a\n' + b'0' * 300
zbuf = io.BytesIO()
with zipfile.ZipFile(zbuf, 'w') as z: z.writestr('a.txt', 'hallo')
zipb = zbuf.getvalue()

# --- S1 gültige Anhänge
r, fl, n = send([('attachments[]', 'Korrektur 1.pdf', pdf, 'application/pdf'), ('attachments[]', 'Vorschau.png', png, 'image/png'), ('attachments[]', 'Daten.zip', zipb, 'application/zip')])
ok = n == 1
if ok:
    m = parse(mails()[-1]); parts = {p.get_filename(): p for p in m.iter_attachments()}
    ok = set(parts) == {'RE-2026-0001.pdf', 'Korrektur 1.pdf', 'Vorschau.png', 'Daten.zip'} and parts['Korrektur 1.pdf'].get_content() == pdf and parts['Daten.zip'].get_content() == zipb \
        and parts['Vorschau.png'].get_content_type() == 'image/png'
rec('S1', 'H', 'Gültige Anhänge (PDF, PNG, ZIP) werden unverändert mit der Rechnungs-PDF versendet', ok, fl)
rec('S2', 'M', 'Erfolgsmeldung nennt die Zahl der zusätzlichen Anhänge', '3 zusätzlichen Anhängen' in fl, fl)

# --- S3 Ablehnung gefährlicher/unerlaubter Dateien: es darf keine Mail rausgehen
bad = {
    'PHP': ('shell.php', b'<?php system($_GET[0]); ?>', 'application/x-php'),
    'Doppelendung': ('x.pdf.php', pdf, 'application/pdf'),
    'PHP als PDF': ('x.pdf', b'<?php echo 1; ?>' + b'x' * 50, 'application/pdf'),
    'HTML': ('seite.html', b'<html><script>alert(1)</script></html>', 'text/html'),
    'SVG': ('bild.svg', b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'),
    'EXE': ('setup.exe', b'MZ' + b'\0' * 100, 'application/octet-stream'),
    'Shell': ('run.sh', b'#!/bin/sh\nid\n', 'text/x-sh'),
    'JS': ('a.js', b'alert(1)', 'text/javascript'),
    'htaccess': ('.htaccess', b'php_value auto_prepend_file x', 'text/plain'),
    'ohne Endung': ('README', b'text', 'text/plain'),
    'TXT mit PHP': ('notiz.txt', b'<?php phpinfo(); ?>', 'text/plain'),
    'TXT binär': ('bin.txt', b'ab\0cd', 'text/plain'),
    'PNG-Endung, PDF-Inhalt': ('x.png', pdf, 'image/png'),
    'leer': ('leer.pdf', b'', 'application/pdf'),
    'Nullbyte im Namen': ('x.php\x00.pdf', pdf, 'application/pdf'),
}
fails = []
for k, (name, content, ct) in bad.items():
    r, fl, n = send([('attachments[]', name, content, ct)])
    if n != 0: fails.append(k)
rec('S3', 'H', 'Unerlaubte/gefälschte Dateien werden abgelehnt, es wird keine Mail gesendet', not fails, fails)

# --- S4 Anzahl/Größe
r, fl, n = send([('attachments[]', f'{i}.pdf', pdf, 'application/pdf') for i in range(6)])
rec('S4', 'M', 'Mehr als 5 Anhänge werden abgelehnt', n == 0 and 'höchstens' in fl, fl)
big = b'%PDF-1.4\n' + b'0' * (1800 * 1024)
r, fl, n = send([('attachments[]', f'{i}.pdf', big, 'application/pdf') for i in range(3)])
rec('S5', 'M', 'Zu große Gesamtsumme/Datei (3 × 1,8 MB über Server-/App-Limit) wird abgelehnt oder gesendet, nie abgeschnitten', n == 0 or all(len(p.get_content()) == len(big) for p in parse(mails()[-1]).iter_attachments() if p.get_filename() != 'RE-2026-0001.pdf'), fl)
r, fl, n = send([('attachments[]', 'riesig.pdf', b'%PDF-1.4\n' + b'0' * (6 * 1024 * 1024), 'application/pdf')])
rec('S6', 'M', 'Datei über 5 MB wird abgelehnt', n == 0 and fl != '', fl)
r, fl, n = send([('attachments[]', 'enorm.pdf', b'%PDF-1.4\n' + b'0' * (12 * 1024 * 1024), 'application/pdf')])
rec('S7', 'M', 'Anfrage über post_max_size (12 MB): saubere Meldung, kein 500er, keine Mail, Weiterleitung auf dieselbe Seite', n == 0 and r.status in (302, 303) and 'zu groß' in fl and f'id={iid}' in r.h('location'), (r.status, r.h('location'), fl))

# --- S8 Namens-Injection
for f in mails(): os.remove(f)
evil = 'x"\r\nContent-Type: text/html\r\nBcc: attacker@evil.example\r\n\r\n<script>alert(1)</script>.pdf'
r, fl, n = send([('attachments[]', evil, pdf, 'application/pdf'), ('attachments[]', '../../etc/passwd.pdf', pdf, 'application/pdf'), ('attachments[]', 'RE-2026-0001.pdf', pdf, 'application/pdf')])
if n == 1:
    raw = open(mails()[-1], 'rb').read().decode('latin1'); m = parse(mails()[-1])
    names = [p.get_filename() for p in m.iter_attachments()]
    hdr_bad = bool(re.search(r'^Bcc:', raw, re.M)) or 'attacker@evil' in raw.split('ENVELOPE')[0] or 'attacker@evil' in raw.split('\n', 1)[0]
    ok = not hdr_bad and all('/' not in x and '\r' not in x and '\n' not in x for x in names) and len(set(names)) == len(names)
    rec('S8', 'H', 'Dateinamen mit CRLF/Pfaden/Quotes/Doppelnamen: keine Header-Injection, sichere und eindeutige Namen', ok, names)
else:
    rec('S8', 'H', 'Dateinamen mit CRLF/Pfaden/Quotes/Doppelnamen: keine Header-Injection, sichere und eindeutige Namen', fl != '' and n == 0, fl)

# --- S9 CSRF / Auth / Persistenz
r, fl, n = send([('attachments[]', 'a.pdf', pdf, 'application/pdf')], csrf=False)
rec('S9', 'H', 'Versand mit Anhang ohne CSRF-Token wird abgelehnt (400)', r.status == 400 and n == 0, r.status)
anon = C()
n0 = len(mails())
anon.req('POST', f'/index.php?p=invoice&id={iid}', data={'action': 'send', 'to': 'x@example.com', 'csrf': 'a'}, files=[('attachments[]', 'a.pdf', pdf, 'application/pdf')])
rec('S10', 'H', 'Anonymer Versand mit Anhang erzeugt keine Mail', len(mails()) == n0)
after = set(os.popen(f'find {APP} -newer {APP}/config.php -type f ! -path "*/ratelimit/*" ! -name "*.log"').read().split())
newfiles = set(os.popen(f'find {APP}/storage -type f').read().split()) - before
rec('S12', 'M', 'storage/ enthält nach dem Versand nur die Rechnungs-PDF (keine Upload-Reste)', all('invoices/' in x or 'ratelimit' in x or x.endswith('schema_version') for x in newfiles), sorted(newfiles)[:5])
# --- S13 ohne Anhänge wie bisher
for f in mails(): os.remove(f)
r, fl, n = send(None)
rec('S13', 'M', 'Versand ohne Zusatzanhänge funktioniert wie bisher (nur Rechnungs-PDF)', n == 1 and [p.get_filename() for p in parse(mails()[-1]).iter_attachments()] == ['RE-2026-0001.pdf'], fl)
# --- S14 Array-Manipulation des Upload-Felds
r = s.req('POST', f'/index.php?p=invoice&id={iid}', data={'csrf': page_token(s, f'/index.php?p=invoice&id={iid}'), 'action': 'send', 'to': 'kunde@example.com'}, files=[('attachments', 'a.pdf', pdf, 'application/pdf')])
rec('S14', 'M', 'Einzel-Upload im Mehrfachfeld (attachments statt attachments[]) führt nicht zu einem 500er', r.status < 500, r.status)
errs = open(APP + '/../apache/php_error.log').read()
rec('S15', 'M', 'Keine PHP-Fatal-Errors/Warnungen im Log', not re.search(r'Fatal|Uncaught|TypeError|Warning', errs), re.findall(r'.*(?:Fatal|Warning).*', errs)[:2])
save_results(label + '_part3')
