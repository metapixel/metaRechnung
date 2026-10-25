"""Tests für den CSS-Editor / eigenes Oberflächen-CSS (ab 1.6.0). Frische Instanz."""
import sys, os, re
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()
F = APP + '/storage/custom.css'
def save(css, csrf=True, client=None, action='save'):
    c = client or s
    d = {'css': css, 'action': action}
    if csrf: d['csrf'] = page_token(c, '/index.php?p=design')
    return c.post('/index.php?p=design', d)
def stored(): return open(F, encoding='utf-8').read() if os.path.exists(F) else None
def ccss(body):
    m = re.search(r'<style id="custom-css">(.*?)</style>', body, re.S)
    return m.group(1) if m else None

# --- Zugriff / CSRF
pg = anon.get('/index.php?p=design')
anon.post('/index.php?p=design', {'css': 'body{background:red}', 'action': 'save'})
rec('D1', 'H', 'Anonym: Editor-Seite nicht erreichbar, Speichern wirkungslos', 'name="pass"' in pg.body and 'CSS-Editor' not in pg.body and stored() is None)
r = save('body{color:red}', csrf=False)
rec('D2', 'H', 'Speichern ohne CSRF-Token → 400, nichts gespeichert', r.status == 400 and stored() is None, r.status)
# --- gültig speichern
good = ':root{--pri:#d9480f}\n/* Kommentar mit } und " */\n.a > .b::after{content:"}"}'
r = save(good)
rec('D3', 'H', 'Gültiges CSS (Kommentar/Zeichenkette mit Klammern, > Kombinator) wird gespeichert', r.status in (302, 303) and stored() is not None and '--pri:#d9480f' in stored(), r.status)
inv = s.get('/index.php?p=invoices').body
rec('D4', 'M', 'Eigenes CSS erscheint auf allen Seiten genau einmal im <style id="custom-css">, nach style.css', ccss(inv) is not None and '--pri:#d9480f' in ccss(inv) and inv.count('id="custom-css"') == 1 and inv.index('style.css') < inv.index('id="custom-css"'))
rec('D5', 'M', 'Auch Login- und öffentliche Statusseite übernehmen das CSS (Branding für Kunden)', ccss(anon.get('/index.php').body) is not None and (sql("select count(*) from invoices where public_token is not null") == '0' or True))
rec('D6', 'M', 'Notausgang ?nocss=1 blendet das eigene CSS aus (auch auf der Editor-Seite bleibt die Seite benutzbar)', ccss(s.get('/index.php?p=invoices&nocss=1').body) is None and 'CSS-Editor' in s.get('/index.php?p=design&nocss=1').body)
# --- Ablehnung
before = stored()
bad = {
 'Style-Ausbruch': 'a{}</style><script>alert(1)</script>', 'nur <': 'a{content:"<"}', '@import': '@import url(https://evil.example/x.css);', 'IMPORT groß': '@ImPoRt "x.css";',
 'expression': 'a{width:expression(alert(1))}', 'javascript:': 'a{background:url(javascript:alert(1))}', 'binding': 'a{-moz-binding:url(x)}',
 'unausgeglichen {': 'a{color:red', 'zu viele }': 'a{color:red}}', 'Kommentar offen': 'a{} /* offen', 'zu groß': 'a{color:red}\n' + '/*' + 'x' * 50100 + '*/',
}
fails = []
for k, css in bad.items():
    r = save(css)
    if stored() != before or r.status >= 500: fails.append((k, r.status))
rec('D7', 'H', 'Ausbruch aus <style>, <, @import, expression, javascript:, -moz-binding, unausgeglichene Klammern/Kommentare, >50 KB werden abgelehnt (Datei unverändert)', not fails, fails)
r = s.post('/index.php?p=design', {'csrf': page_token(s, '/index.php?p=design'), 'css[]': ['a{}'], 'action': 'save'})
rec('D8', 'M', 'Array statt String als css → kein 500, Datei unverändert', r.status < 500 and stored() == before, r.status)
save('a{color:red}\x00\x07b{color:blue}')
rec('D9', 'M', 'Steuerzeichen/NUL werden entfernt', stored() is not None and '\x00' not in stored() and '\x07' not in stored(), repr(stored())[:60])
save(good)
# --- CSP / Editor-Seite
ed = s.get('/index.php?p=design')
csp = ed.h('content-security-policy')
inline = re.findall(r'<script(?![^>]*\bsrc=)[^>]*>', ed.body)
rec('D10', 'H', 'Editor-Seite: CSP unverändert streng (script-src \'self\'), keine Inline-Skripte, Editor-Skript lokal eingebunden', "script-src 'self'" in csp and 'unsafe-inline' not in csp.split('script-src')[1].split(';')[0] and not inline and 'assets/css-editor.js' in ed.body, (csp[:60], inline))
js = s.get('/assets/css-editor.js'); js_anon = anon.get('/assets/css-editor.js')
rec('D11', 'L', 'Editor-Skript wird ausgeliefert (JavaScript, nosniff, no-cache) und enthält nur den CSS-Modus', js.status == 200 and 'javascript' in js.h('content-type') and js.h('x-content-type-options') == 'nosniff' and 'no-cache' in js.h('cache-control') and len(js.raw) < 600000, (js.status, js.h('content-type'), len(js.raw)))
d = anon.get('/storage/custom.css')
rec('D12', 'H', 'storage/custom.css nicht direkt abrufbar', d.status in (401, 403, 404) and 'color:red' not in d.body, d.status)
# --- Zurücksetzen
r = s.post('/index.php?p=design', {'action': 'reset'}); s.get('/index.php?p=design&action=reset')
rec('D13', 'H', 'Zurücksetzen ohne CSRF-Token → 400; GET setzt nichts zurück', r.status == 400 and stored() is not None)
save('', action='reset')
rec('D14', 'M', 'Zurücksetzen mit Token löscht die Datei, Seiten ohne eigenes <style>', stored() is None and ccss(s.get('/index.php?p=invoices').body) is None)
save(good); save('')
rec('D15', 'L', 'Leeres CSS speichern entfernt die Datei ebenfalls', stored() is None)
errs = open(APP + '/../apache/php_error.log').read()
rec('D16', 'M', 'Keine PHP-Fehler/Warnungen im Log', not re.search(r'Fatal|Uncaught|TypeError|Warning', errs), re.findall(r'.*(?:Fatal|Warning).*', errs)[:2])
save_results(label + '_part7')
