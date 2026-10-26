"""Tests für das PDF-Design (ab 1.8.0). Frische Instanz."""
import sys, os, re, subprocess, tempfile, zlib, struct
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()
tmp = tempfile.mkdtemp()
CFG = APP + '/config.php'
def cfg(): return open(CFG).read()
def stored():
    m = re.search(r"'PDF_DESIGN' => '((?:[^'\\]|\\.)*)'", cfg()); return m.group(1) if m else ''
def txt(raw):
    p = os.path.join(tmp, 't.pdf'); open(p, 'wb').write(raw)
    return subprocess.run(['pdftotext', '-layout', p, '-'], capture_output=True, text=True).stdout
def fonts(raw):
    p = os.path.join(tmp, 'f.pdf'); open(p, 'wb').write(raw)
    return subprocess.run(['pdffonts', p], capture_output=True, text=True).stdout
BASE = dict(font='dejavusanscondensed', size='normal', accent='#333333', table='lines', sender='right', title_accent='1', addrline='1', footer='1', closing='', footer_text='')
def post(action='save', csrf=True, client=None, **kw):
    c = client or s
    d = dict(BASE); d.update(kw); d['action'] = action
    d = {k: v for k, v in d.items() if v is not None}
    if csrf: d['csrf'] = page_token(c, '/index.php?p=pdf_design')
    return c.post('/index.php?p=pdf_design', d)
def wait(): import time; time.sleep(2.2)   # opcache für config.php

# --- Zugriff / CSRF
pg = anon.get('/index.php?p=pdf_design'); anon.post('/index.php?p=pdf_design', dict(BASE, action='save'))
rec('P1', 'H', 'Anonym: Seite nicht erreichbar, Speichern wirkungslos', 'name="pass"' in pg.body and stored() == '', pg.status)
r = post(csrf=False, font='dejavusans')
rec('P2', 'H', 'Speichern ohne CSRF-Token → 400, nichts gespeichert', r.status == 400 and stored() == '', r.status)
page = s.get('/index.php?p=pdf_design').body
rec('P3', 'M', 'Seite: Gruppen mit fieldset/legend, Farbfeld mit Label, 4 Vorlagen, Vorschau-Schaltfläche', page.count('<fieldset>') >= 4 and 'for="accent"' in page and page.count('name="template"') == 4 and 'value="preview"' in page)
# --- Speichern
r = post(font='dejavuserifcondensed', size='large', accent='#1D4ED8', table='zebra', sender='left', closing='ALT-TEXT <b>fett</b>', footer_text='HRB 12345 & Co', pagenum='1')
wait()
rec('P4', 'H', 'Gültiges Design wird gespeichert (Farbe normalisiert) und erscheint wieder im Formular', r.status in (302, 303) and '#1d4ed8' in stored() and 'dejavuserifcondensed' in stored() and 'checked' in re.search(r'value="zebra"[^>]*', s.get('/index.php?p=pdf_design').body).group(0), (r.status, stored()[:80]))
# --- Ablehnung
before = stored()
bad = {'Schrift': dict(font='comic'), 'Schrift CSS': dict(font='x;} body{display:none'), 'Größe': dict(size='gigantisch'), 'Tabelle': dict(table='<script>'), 'Absender': dict(sender='mitte'),
       'Farbe name': dict(accent='red'), 'Farbe url': dict(accent='url(http://evil/x)'), 'Farbe Länge': dict(accent='#12345'), 'Farbe Umbruch': dict(accent='#123456\n'),
       'Farbe Array': {'accent[]': '#111111', 'accent': None}, 'Text zu lang': dict(closing='a' * 501), 'Fuß zu lang': dict(footer_text='b' * 301), 'Text Array': {'closing[]': 'x', 'closing': None}}
fails = []
for k, kw in bad.items():
    r = post(**kw); wait() if False else None
    if r.status >= 500 or stored() != before or r.status in (302, 303): fails.append((k, r.status))
rec('P5', 'H', 'Ungültige Schrift/Größe/Tabelle/Absender/Farbe (inkl. url(), Umbruch, Array) und zu lange Texte werden abgelehnt, Konfiguration unverändert', not fails, fails)
# --- Vorlage laden: ändert nichts am Gespeicherten
r = s.post('/index.php?p=pdf_design', dict(BASE, csrf=page_token(s, '/index.php?p=pdf_design'), template='modern', closing='X'))
rec('P6', 'M', 'Vorlage „Modern“ füllt das Formular vor (Farbe #1d4ed8, farbiger Tabellenkopf) und speichert nichts', r.status == 200 and '#1d4ed8' in r.body and re.search(r'value="filled"[^>]*checked', r.body) and stored() == before and 'nicht gespeichert' in r.body, r.status)
r = s.post('/index.php?p=pdf_design', dict(BASE, csrf=page_token(s, '/index.php?p=pdf_design'), template='nope'))
rec('P7', 'M', 'Unbekannte Vorlage → Hinweis, kein 500, nichts gespeichert', r.status == 200 and stored() == before, r.status)
# --- Vorschau
r = post('preview', font='dejavusans', closing='VORSCHAU-SCHLUSS', footer_text='FUSS-ZUSATZ', pagenum='1', table='filled', accent='#1d4ed8')
t = txt(r.raw)
rec('P8', 'H', 'Vorschau liefert PDF mit Beispieldaten, Schlusstext, Fußzusatz, Seitenzahl – und speichert nichts', r.raw[:4] == b'%PDF' and 'Beispielleistung A' in t and 'VORSCHAU-SCHLUSS' in t and 'FUSS-ZUSATZ' in t and 'Seite 1 von 1' in t and stored() == before, (r.status, t[:100]))
r = post('preview', accent='nope')
rec('P9', 'M', 'Vorschau mit ungültiger Eingabe: kein PDF, Fehlermeldung, kein 500', r.raw[:4] != b'%PDF' and r.status < 500 and 'ungültig' in r.body.lower() or 'Format #rrggbb' in r.body, r.status)
# --- Alle Vorlagen/Schriften erzeugen gültige PDFs
ok = True; det = []
for font, fname in (('dejavusanscondensed', 'DejaVuSansCondensed'), ('dejavusans', 'DejaVuSans'), ('dejavuserifcondensed', 'DejaVuSerifCondensed')):
    for table in ('lines', 'rules', 'zebra', 'filled'):
        for size in ('small', 'large'):
            r = post('preview', font=font, table=table, size=size, sender='left' if size == 'small' else 'right', accent='#7f1d1d')
            good = r.raw[:4] == b'%PDF' and 'Beispielleistung C' in txt(r.raw) and fname in fonts(r.raw).replace('+', '').replace('-', '').replace(' ', '')
            if not good: ok = False; det.append((font, table, size, r.status))
rec('P10', 'H', 'Alle 3 Schriften × 4 Tabellenstile × 2 Größen erzeugen gültige PDFs mit der gewählten (eingebetteten) Schrift', ok, det[:3])
# --- Einfrieren beim Ausstellen
t0 = page_token(s, '/index.php?p=customer_edit')
s.post('/index.php?p=customer_edit', {'csrf': t0, 'name': 'Beispiel GmbH', 'contact': '', 'email': 'e@example.com', 'street': 'Hauptstr. 5', 'zip': '12345', 'city': 'Musterstadt', 'country': 'DE', 'vat_id': '', 'buyer_reference': ''})
cid = sql('select max(id) from customers')
def mk(issue=True):
    d = {'csrf': page_token(s, '/index.php?p=invoice_edit'), 'customer_id': cid, 'issue_date': '2026-10-24', 'delivery_date': '', 'due_date': '2026-11-07', 'notes': '',
         'desc[]': ['Leistung'], 'qty[]': ['2'], 'unit[]': ['HUR'], 'price[]': ['85'], 'vat[]': ['19']}
    r = s.post('/index.php?p=invoice_edit', d); iid = re.search(r'id=(\d+)', r.h('location')).group(1)
    if issue: s.post(f'/index.php?p=invoice&id={iid}', {'csrf': page_token(s, f'/index.php?p=invoice&id={iid}'), 'action': 'issue'})
    return iid
post(font='dejavusans', closing='ALT-TEXT-EINGEFROREN', footer_text='', table='filled', accent='#1d4ed8', pagenum=None); wait()
i1 = mk(); n1 = sql(f'select number from invoices where id={i1}')
snap = sql(f'select snapshot from invoices where id={i1}')
rec('P11', 'H', 'Beim Ausstellen wird die Gestaltung im Snapshot festgehalten', '"design"' in snap and 'ALT-TEXT-EINGEFROREN' in snap and '#1d4ed8' in snap, snap[-200:])
post(font='dejavusanscondensed', closing='NEU-TEXT-SPAETER', table='lines', accent='#333333'); wait()
for f in os.listdir(APP + '/storage/invoices'):
    if n1 in f: os.remove(APP + '/storage/invoices/' + f)
t1 = txt(s.get(f'/index.php?p=pdf&id={i1}').raw)
rec('P12', 'H', 'Ausgestellte Rechnung wird nach Designänderung (auch bei neu gerenderter PDF) mit dem eingefrorenen Design erzeugt', 'ALT-TEXT-EINGEFROREN' in t1 and 'NEU-TEXT-SPAETER' not in t1, t1[-300:])
i2 = mk(); t2 = txt(s.get(f'/index.php?p=pdf&id={i2}').raw)
rec('P13', 'M', 'Neu ausgestellte Rechnung verwendet das aktuelle Design', 'NEU-TEXT-SPAETER' in t2 and 'ALT-TEXT' not in t2)
i3 = mk(issue=False)
t3 = txt(s.get(f'/index.php?p=draft_preview&id={i3}').raw)
rec('P14', 'M', 'Entwurfsvorschau zeigt das aktuelle Design', 'NEU-TEXT-SPAETER' in t3)
# Altbestand: Snapshot ohne design → bisheriges Aussehen (kein Schlusstext)
i4 = mk(); n4 = sql(f'select number from invoices where id={i4}')
sn = sql(f'select snapshot from invoices where id={i4}')
import json
j = json.loads(sn); j.pop('design'); subprocess.run(['mysql', '-uroot', DB, '-e', "update invoices set snapshot=%s where id=%s" % ("'" + json.dumps(j, ensure_ascii=False).replace("'", "''").replace('\\', '\\\\') + "'", i4)], check=True)
for f in os.listdir(APP + '/storage/invoices'):
    if n4 in f: os.remove(APP + '/storage/invoices/' + f)
t4 = txt(s.get(f'/index.php?p=pdf&id={i4}').raw)
rec('P15', 'H', 'Ältere Rechnung ohne gespeichertes Design wird im bisherigen Aussehen neu erzeugt (kein Schlusstext, keine Seitenzahl)', 'NEU-TEXT-SPAETER' not in t4 and 'Seite 1 von' not in t4 and 'Leistung' in t4, t4[-200:])
# ZUGFeRD/GiroCode bleiben intakt
pdfp = os.path.join(tmp, 'z.pdf'); open(pdfp, 'wb').write(s.get(f'/index.php?p=pdf&id={i2}').raw)
det = subprocess.run(['pdfdetach', '-list', pdfp], capture_output=True, text=True).stdout
rec('P16', 'H', 'PDF enthält weiterhin die eingebettete factur-x.xml', 'factur-x.xml' in det, det)
# Zurücksetzen
r = s.post('/index.php?p=pdf_design', {'csrf': page_token(s, '/index.php?p=pdf_design'), 'action': 'reset'}); wait()
rec('P17', 'M', 'Zurücksetzen entfernt das gespeicherte Design', r.status in (302, 303) and stored() == '', stored())
# Logo-Farbvorschlag
def png(w, h, rgba):
    row = b'\x00' + bytes(rgba) * w; raw = zlib.compress(row * h)
    def ch(t, d): return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + ch(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0)) + ch(b'IDAT', raw) + ch(b'IEND', b'')
pg0 = s.get('/index.php?p=pdf_design').body
rec('P18', 'L', 'Ohne Logo kein Farbvorschlag', 'data-fill' not in pg0)
s.req('POST', '/index.php?p=settings', data={'action': 'logo', 'logo_width': '50', 'logo_align': 'right', 'csrf': page_token(s, '/index.php?p=settings')}, files=[('logo', 'l.png', png(60, 30, (255, 220, 0, 255)), 'image/png')])
pg1 = s.get('/index.php?p=pdf_design').body
m = re.search(r'data-fill="accent" data-value="(#[0-9a-f]{6})"', pg1)
def cr(h):
    c = [int(h[i:i+2], 16) / 255 for i in (1, 3, 5)]; c = [x / 12.92 if x <= .03928 else ((x + .055) / 1.055) ** 2.4 for x in c]; L = .2126 * c[0] + .7152 * c[1] + .0722 * c[2]; return 1.05 / (L + .05)
rec('P19', 'M', 'Mit gelbem Logo: Vorschlag ist ein abgedunkeltes Gelb mit Kontrast ≥ 4,5 zu Weiß', m is not None and cr(m.group(1)) >= 4.5 and int(m.group(1)[1:3], 16) > int(m.group(1)[5:7], 16), m and m.group(1))
# Fehlerlog
pe = open('/home/claude/work/sec/apache/php_error.log').read()
rec('P20', 'M', 'Keine PHP-Fehler/Warnungen im Log', pe.strip() == '', pe[:300])
save_results(label)
