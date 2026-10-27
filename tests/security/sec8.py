"""Tests für den GiroCode / EPC-QR (ab 1.7.0). Frische Instanz. Dekodiert den QR-Code aus PDF und Statusseite."""
import sys, os, re, base64, subprocess, tempfile
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
import cv2, numpy as np
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()
tmp = tempfile.mkdtemp()

def decode_img(path):
    img = cv2.imread(path)
    dets = (cv2.QRCodeDetectorAruco(), cv2.QRCodeDetector())
    for scale in (1, 2, 3):
        im = cv2.resize(img, None, fx=scale, fy=scale, interpolation=cv2.INTER_NEAREST) if scale > 1 else img
        for d in dets:
            txt, _, _ = d.detectAndDecode(im)
            if txt: return txt
    return ''
def pdf_qr(raw):
    p = os.path.join(tmp, 'x.pdf'); open(p, 'wb').write(raw)
    for dpi in (200, 150, 300, 250):
        for f in os.listdir(tmp):
            if f.startswith('pg'): os.remove(os.path.join(tmp, f))
        subprocess.run(['pdftoppm', '-r', str(dpi), '-png', p, os.path.join(tmp, 'pg')], check=True)
        for f in sorted(os.listdir(tmp)):
            if f.startswith('pg') and f.endswith('.png'):
                t = decode_img(os.path.join(tmp, f))
                if t: return t
    return ''
def svg_qr(body):
    m = re.search(r'<img src="(data:image/svg\+xml;base64,[A-Za-z0-9+/=]+)"', body)
    if not m: return None
    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        br = pw.chromium.launch(); pg = br.new_page(viewport={'width': 600, 'height': 600})
        pg.set_content(f'<body style="margin:0;background:#fff"><img src="{m.group(1)}" width="500" height="500" style="margin:40px"></body>')
        pg.screenshot(path=os.path.join(tmp, 's.png')); br.close()
    return decode_img(os.path.join(tmp, 's.png'))

t = page_token(s, '/index.php?p=customer_edit')
s.post('/index.php?p=customer_edit', {'csrf': t, 'name': 'Beispiel GmbH', 'contact': '', 'email': 'e@example.com', 'street': 'Hauptstr. 5', 'zip': '12345', 'city': 'Musterstadt', 'country': 'DE', 'vat_id': '', 'buyer_reference': ''})
cid = sql('select max(id) from customers')
def mk(price='85', qty='10'):
    d = {'csrf': page_token(s, '/index.php?p=invoice_edit'), 'customer_id': cid, 'issue_date': '2026-10-24', 'delivery_date': '', 'due_date': '2026-11-07', 'notes': '',
         'desc[]': ['Leistung'], 'qty[]': [qty], 'unit[]': ['HUR'], 'price[]': [price], 'vat[]': ['19']}
    r = s.post('/index.php?p=invoice_edit', d)
    iid = re.search(r'id=(\d+)', r.h('location')).group(1)
    s.post(f'/index.php?p=invoice&id={iid}', {'csrf': page_token(s, f'/index.php?p=invoice&id={iid}'), 'action': 'issue'})
    return iid
def tok(iid): return sql(f'select public_token from invoices where id={iid}')
def num(iid): return sql(f'select number from invoices where id={iid}')

i1 = mk()
want = ['BCD', '002', '1', 'SCT', 'COBADEFFXXX', 'Test IT-Service', 'DE89370400440532013000', 'EUR1011.50', '', '', 'Rechnung ' + num(i1)]
pdf = s.get(f'/index.php?p=pdf&id={i1}').raw
got = pdf_qr(pdf)
rec('G1', 'H', 'PDF enthält lesbaren GiroCode mit korrektem EPC-Inhalt (Empfänger, IBAN, BIC, Betrag, Zweck)', got.split('\n') == want, repr(got))
rec('G2', 'M', 'Payload ≤ 331 Byte, 11 Zeilen, Kopf BCD/002/1/SCT', len(got.encode()) <= 331 and len(got.split('\n')) == 11, len(got))
st = anon.get(f'/status.php?t={tok(i1)}')
sq = svg_qr(st.body)
rec('G3', 'H', 'Statusseite (offen): QR-Code identisch zum PDF-Inhalt', sq is not None and sq.split('\n') == want, repr(sq))
rec('G4', 'M', 'QR-<img> hat Alternativtext und Beschriftung', 'alt="GiroCode' in st.body and 'figcaption' in st.body)
csp = st.h('content-security-policy')
rec('G5', 'M', 'CSP erlaubt data:-Bilder, keine Inline-Skripte', 'img-src' in csp and 'data:' in csp and "script-src 'self'" in csp, csp)
# bezahlt → kein QR auf Statusseite
s.post(f'/index.php?p=invoice&id={i1}', {'csrf': page_token(s, f'/index.php?p=invoice&id={i1}'), 'action': 'paid', 'paid_at': '2026-10-25'})
stp = anon.get(f'/status.php?t={tok(i1)}')
rec('G6', 'H', 'Bezahlte Rechnung: kein GiroCode auf der Statusseite', 'Zahlung eingegangen' in stp.body and 'GiroCode' not in stp.body, sql(f'select status from invoices where id={i1}'))
# Beträge
i2 = mk(price='0.01', qty='1')
g = pdf_qr(s.get(f'/index.php?p=pdf&id={i2}').raw)
rec('G7', 'M', 'Kleinbetrag: Betrag wird mit Punkt und 2 Nachkommastellen kodiert', g.split('\n')[7:8] == ['EUR0.01'], repr(g))
i3 = mk(price='0', qty='1')
rec('G8', 'H', 'Rechnungsbetrag 0 → kein GiroCode (Statusseite und PDF-Text)', 'GiroCode' not in anon.get(f'/status.php?t={tok(i3)}').body and pdf_qr(s.get(f'/index.php?p=pdf&id={i3}').raw) == '')
i4 = mk(price='1234567.89', qty='3')
g = pdf_qr(s.get(f'/index.php?p=pdf&id={i4}').raw)
rec('G9', 'M', 'Großer Betrag korrekt kodiert (3×1234567,89 + 19 % = 4407407,37)', g.split('\n')[7:8] == ['EUR4407407.37'], repr(g))
# Einstellung aus (Config) → kein QR, PDF trotzdem ok
cfgp = APP + '/config.php'; orig = open(cfgp).read()
open(cfgp, 'w').write(orig.replace("'KLEINUNTERNEHMER' => '0',", "'KLEINUNTERNEHMER' => '0',\n  'GIROCODE' => '0',"))
import time; time.sleep(3.5)
i5 = mk()
rp = s.get(f'/index.php?p=pdf&id={i5}')
rec('G10', 'H', 'GIROCODE=0: weder im PDF noch auf der Statusseite ein QR-Code; PDF weiter gültig', rp.raw[:4] == b'%PDF' and pdf_qr(rp.raw) == '' and 'GiroCode' not in anon.get(f'/status.php?t={tok(i5)}').body)
st_ = s.get('/index.php?p=settings').body
rec('G11', 'M', 'Einstellungsseite: Checkbox GiroCode vorhanden und abgewählt', 'name="GIROCODE"' in st_ and not re.search(r'name="GIROCODE"[^>]*checked', st_))
open(cfgp, 'w').write(orig); time.sleep(3.5)
st_ = s.get('/index.php?p=settings').body
rec('G12', 'M', 'Standard: GiroCode aktiviert (Checkbox angehakt)', re.search(r'name="GIROCODE"[^>]*checked', st_) is not None)
# Einstellung speichern über das Formular
form = {}
for m in re.finditer(r'<input[^>]*name="([^"]+)"[^>]*>', st_):
    tag = m.group(0); n = m.group(1)
    if 'type="checkbox"' in tag:
        if 'checked' in tag and n != 'GIROCODE': form[n] = '1'
    elif 'type="submit"' not in tag and 'type="file"' not in tag:
        v = re.search(r'value="([^"]*)"', tag); form[n] = html.unescape(v.group(1)) if v else ''
for m in re.finditer(r'<select[^>]*name="([^"]+)"[^>]*>(.*?)</select>', st_, re.S):
    o = re.search(r'<option[^>]*value="([^"]*)"[^>]*selected', m.group(2)) or re.search(r'<option[^>]*value="([^"]*)"', m.group(2))
    form[m.group(1)] = o.group(1) if o else ''
form['csrf'] = csrf_of(st_); form['action'] = 'save'
r = s.post('/index.php?p=settings', form)
rec('G13', 'M', 'Speichern ohne Haken schaltet GIROCODE in config.php ab', "'GIROCODE' => '0'" in open(cfgp).read(), (r.status, flash_of(r.body)))
form['GIROCODE'] = '1'; form['csrf'] = page_token(s, '/index.php?p=settings')
s.post('/index.php?p=settings', form)
rec('G14', 'M', 'Speichern mit Haken schaltet GIROCODE wieder ein', "'GIROCODE' => '1'" in open(cfgp).read())
# Fehlerlog
pe = open('/home/claude/work/sec/apache/php_error.log').read()
rec('G15', 'M', 'Keine PHP-Fehler/Warnungen im Log', pe.strip() == '', pe[:200])
save_results(label)
