"""Tests für das Firmenlogo (ab 1.5.0). Frische Instanz."""
import sys, os, re, zlib, struct
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()

def png(w, h, rgba=(200, 30, 30, 255), extra=b''):
    row = b'\x00' + bytes(rgba) * w
    raw = zlib.compress(row * h)
    def ch(t, d): return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + ch(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0)) + ch(b'IDAT', raw) + ch(b'IEND', b'') + extra

def post(files=None, csrf=True, client=None, **kw):
    c = client or s
    d = {'action': 'logo', 'logo_width': '50', 'logo_align': 'right'}
    d.update(kw)
    if csrf: d['csrf'] = page_token(c, '/index.php?p=settings')
    return c.req('POST', '/index.php?p=settings', data=d, files=files if files is not None else [])
def cfg(): return open(APP + '/config.php').read()
def has_logo(): return "'LOGO_FILE' => 'logo." in cfg()
def files_on_disk(): return sorted(os.listdir(APP + '/storage/logo')) if os.path.isdir(APP + '/storage/logo') else []

# --- Zugriff / CSRF
r = anon.post('/index.php?p=settings', {'action': 'logo'}); rg = anon.get('/index.php?p=logo_file')
rec('L1', 'H', 'Anonym: Logo-Upload wirkungslos, Logo-Datei nicht abrufbar', not has_logo() and 'name="pass"' in rg.body or rg.status in (302, 401, 403, 404), (r.status, rg.status))
r = post([('logo', 'a.png', png(40, 20), 'image/png')], csrf=False)
rec('L2', 'H', 'Upload ohne CSRF-Token → 400, nichts gespeichert', r.status == 400 and not has_logo() and not files_on_disk(), r.status)
# --- gültiger Upload
r = post([('logo', 'firma.png', png(300, 100, (10, 120, 200, 128)), 'image/png')])
rec('L3', 'H', 'Gültiges PNG (mit Transparenz) wird gespeichert, Dateiname serverseitig festgelegt', has_logo() and files_on_disk()[-2:] == ['index.html', 'logo.png'] or 'logo.png' in files_on_disk(), (r.status, files_on_disk(), flash_of(s.get('/index.php?p=settings').body)))
lf = s.get('/index.php?p=logo_file')
rec('L4', 'M', 'Logo wird mit korrektem Content-Type an angemeldete Nutzer ausgeliefert', lf.status == 200 and lf.h('content-type') == 'image/png' and lf.raw[:4] == b'\x89PNG', lf.h('content-type'))
d = C().get('/storage/logo/logo.png')
rec('L5', 'H', 'Direktzugriff auf storage/logo/logo.png gesperrt', d.status in (401, 403, 404) and d.raw[:4] != b'\x89PNG', d.status)
# --- Ablehnung
def rejected(name, content, ctype, **kw):
    before = files_on_disk(); cf = cfg()
    post([('logo', name, content, ctype)], **kw)
    return files_on_disk() == before and cfg() == cf
bad = {
 'SVG': ('x.svg', b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'),
 'PHP als png': ('x.png', b'<?php system($_GET[0]); ?>', 'image/png'),
 'HTML als png': ('x.png', b'<html><script>alert(1)</script>', 'image/png'),
 'PDF': ('x.png', b'%PDF-1.4\n%%EOF', 'image/png'),
 'zu große Pixel': ('big.png', png(3100, 10), 'image/png'),
 'abgeschnitten': ('t.png', png(50, 50)[:40], 'image/png'),
 'zu groß (MB)': ('m.png', png(10, 10, extra=b'\0' * (2 * 1024 * 1024 + 10)), 'image/png'),
}
fails = [k for k, (n, c, t) in bad.items() if not rejected(n, c, t)]
rec('L6', 'H', 'SVG, PHP/HTML/PDF mit Bild-Endung, Übergröße (Pixel/MB) und kaputte Bilder werden abgelehnt', not fails, fails)
# --- PHP-Polyglot: wird neu kodiert, Payload verschwindet
poly = png(60, 30, extra=b'<?php echo "PWNED"; ?>')
post([('logo', 'p.png', poly, 'image/png')])
stored = open(APP + '/storage/logo/logo.png', 'rb').read() if os.path.exists(APP + '/storage/logo/logo.png') else b''
rec('L7', 'H', 'PNG mit angehängtem PHP-Code: Bild wird neu kodiert, Payload entfernt', stored[:4] == b'\x89PNG' and b'<?php' not in stored and b'PWNED' not in stored)
r = post([('logo', '../../evil.php', png(10, 10), 'image/png')])
rec('L8', 'H', 'Dateiname mit Pfadangabe (../../evil.php) hat keinen Einfluss', not os.path.exists(APP + '/evil.php') and not os.path.exists(APP + '/storage/evil.php') and all(f in ('logo.png', 'logo.jpg', '.htaccess', 'index.html') for f in files_on_disk()), files_on_disk())
# --- Einstellungen validieren
post(logo_width='9999', logo_align='<script>')
c = cfg()
rec('L9', 'M', 'Breite wird auf 10–120 mm begrenzt, Ausrichtung nur links/rechts', "'LOGO_WIDTH' => '120'" in c and "'LOGO_ALIGN' => 'right'" in c, re.findall(r"'LOGO_\w+' => '[^']*'", c))
post(logo_width='abc', logo_align='left')
c = cfg()
rec('L10', 'L', 'Ungültige Breite → Standard 50, Ausrichtung links übernommen', "'LOGO_WIDTH' => '50'" in c and "'LOGO_ALIGN' => 'left'" in c)
# --- PDF
pv = s.get('/index.php?p=letterhead_preview')
rec('L11', 'H', 'Rechnungs-PDF enthält das Logo und bleibt gültiges PDF/A-3 mit eingebettetem XML', pv.raw[:4] == b'%PDF' and b'/Subtype /Image' in pv.raw and b'factur-x.xml' in pv.raw and b'pdfaid' in pv.raw, (pv.status, len(pv.raw)))
open('/tmp/logo_preview.pdf', 'wb').write(pv.raw)
# --- Löschen
r = s.post('/index.php?p=settings', {'action': 'logo_delete'})
rec('L12', 'H', 'Logo löschen ohne CSRF-Token → 400, Logo bleibt', r.status == 400 and has_logo(), r.status)
s.post('/index.php?p=settings', {'action': 'logo_delete', 'csrf': page_token(s, '/index.php?p=settings')})
rec('L13', 'M', 'Logo löschen entfernt Datei und Konfiguration', not has_logo() and not [f for f in files_on_disk() if f.startswith('logo.')] and s.get('/index.php?p=logo_file').status == 404)
errs = open(APP + '/../apache/php_error.log').read()
rec('L14', 'M', 'Keine PHP-Fehler/Warnungen im Log', not re.search(r'Fatal|Uncaught|TypeError|Warning', errs), re.findall(r'.*(?:Fatal|Warning).*', errs)[:2])
save_results(label + '_part5')
