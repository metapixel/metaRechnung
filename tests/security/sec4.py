"""Tests für die Artikelverwaltung (ab 1.4.0). Frische Instanz."""
import sys, os, re, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()

def art(desc='Beratung', price='90', unit='HUR', vat='19', aid=None, csrf=True, client=None, **extra):
    c = client or s
    d = {'description': desc, 'unit': unit, 'unit_price': price, 'vat_rate': vat}
    if csrf: d['csrf'] = page_token(c, '/index.php?p=article_edit' + (f'&id={aid}' if aid else ''))
    d.update(extra)
    return c.post('/index.php?p=article_edit' + (f'&id={aid}' if aid else ''), d)
def count(): return int(sql('select count(*) from articles'))

# --- Zugriff
pg = [anon.get(u) for u in ['/index.php?p=articles', '/index.php?p=article_edit']]
rec('T1', 'H', 'Artikelseiten ohne Login nicht erreichbar (Anmeldeformular statt Inhalt)', all('name="pass"' in p.body and 'Neuer Artikel' not in p.body and 'Bezeichnung' not in p.body for p in pg))
n0 = count(); anon.post('/index.php?p=article_edit', {'description': 'x', 'unit_price': '1'})
rec('T2', 'H', 'Anonymes Anlegen eines Artikels wirkungslos', count() == n0)
# --- CRUD
r = art('Beratung <b>pro Stunde</b>', '90,50', 'HUR', '19')
aid = sql('select max(id) from articles')
rec('T3', 'M', 'Artikel anlegen (Komma als Dezimaltrenner) funktioniert', count() == 1 and sql(f'select unit_price from articles where id={aid}') == '90.50' and r.status in (302, 303), (r.status, flash_of(s.get('/index.php?p=articles').body)))
art('Beratung Pro', '95', 'HUR', '19', aid=aid)
rec('T4', 'M', 'Artikel bearbeiten', sql(f'select description from articles where id={aid}') == 'Beratung Pro' and sql(f'select unit_price from articles where id={aid}') == '95.00')
# --- Validierung
bad = {'leer': dict(desc=''), 'negativ-Menge-ähnlich 1e9': dict(price='1e9'), 'Text': dict(price='abc'), 'zu groß': dict(price='99999999'), 'MwSt negativ': dict(vat='-5'), 'MwSt 1000': dict(vat='1000'), 'zu lang': dict(desc='x' * 501), 'Array': dict()}
fails = []
for k, kw in bad.items():
    n0 = count()
    if k == 'Array':
        s.post('/index.php?p=article_edit', {'csrf': page_token(s, '/index.php?p=article_edit'), 'description[]': ['a'], 'unit_price[]': ['1'], 'vat_rate': '19'})
    else:
        art(**kw)
    if count() != n0: fails.append(k)
rec('T5', 'M', 'Ungültige Werte (leer, 1e9, Text, MwSt −5/1000, >500 Zeichen, Arrays) werden abgelehnt', not fails, fails)
# --- Injection
n0 = count()
art("x'); DROP TABLE articles;-- ", '1')
rec('T6', 'H', 'SQL-Injection in Bezeichnung: wörtlich gespeichert, Tabelle intakt', count() == n0 + 1 and sql("select count(*) from articles where description like 'x%DROP TABLE%'") == '1')
xss = '"><script>alert(1)</script><img src=x onerror=alert(1)>'
art(xss, '1')
pages = [s.get('/index.php?p=articles').body, s.get('/index.php?p=invoice_edit').body, s.get('/index.php?p=article_edit&id=' + sql('select max(id) from articles')).body]
rec('T7', 'H', 'Stored XSS in Artikelbezeichnung: Liste, Rechnungsformular (data-Attribut) und Bearbeiten maskiert', all('<script>alert(1)' not in p and '<img src=x' not in p and '"><' not in p.split('data-desc="')[-1][:0] for p in pages) and any('&lt;script&gt;' in p for p in pages))
# --- CSRF
n0 = count(); r = art('csrf', csrf=False)
rec('T8', 'H', 'Anlegen ohne CSRF-Token → 400', r.status == 400 and count() == n0, r.status)
r = s.post(f'/index.php?p=article_edit&id={aid}', {'description': 'gehackt', 'unit_price': '1', 'vat_rate': '19', 'csrf': 'a' * 32})
rec('T9', 'H', 'Bearbeiten mit falschem Token → 400, Daten unverändert', r.status == 400 and sql(f'select description from articles where id={aid}') == 'Beratung Pro')
r = s.post(f'/index.php?p=article_edit&id={aid}', {'action': 'delete'})
rec('T10', 'H', 'Löschen ohne Token → 400, Artikel bleibt', r.status == 400 and sql(f'select count(*) from articles where id={aid}') == '1')
s.get(f'/index.php?p=article_edit&id={aid}&action=delete')
rec('T11', 'M', 'GET löst kein Löschen aus', sql(f'select count(*) from articles where id={aid}') == '1')
# --- Rechnung nutzt Artikel; Löschen ändert Rechnungen nicht
s.post('/index.php?p=customer_edit', {'csrf': page_token(s, '/index.php?p=customer_edit'), 'name': 'K GmbH', 'email': 'k@example.com', 'street': 's', 'zip': '1', 'city': 'c', 'country': 'DE'})
cid = sql('select max(id) from customers')
form = s.get('/index.php?p=invoice_edit').body
rec('T12', 'M', 'Rechnungsformular bietet die Artikel zum Einfügen an', 'id="addarticle"' in form and 'Beratung Pro' in form)
r = s.post('/index.php?p=invoice_edit', {'csrf': page_token(s, '/index.php?p=invoice_edit'), 'customer_id': cid, 'issue_date': '2026-10-24', 'due_date': '2026-11-07', 'desc[]': ['Beratung Pro'], 'qty[]': ['2'], 'unit[]': ['HUR'], 'price[]': ['95'], 'vat[]': ['19']})
iid = re.search(r'id=(\d+)', r.h('location')).group(1)
s.post(f'/index.php?p=article_edit&id={aid}', {'csrf': page_token(s, f'/index.php?p=article_edit&id={aid}'), 'action': 'delete'})
rec('T13', 'M', 'Artikel löschen funktioniert und lässt bestehende Rechnungspositionen unverändert', sql(f'select count(*) from articles where id={aid}') == '0' and sql(f"select description from invoice_items where invoice_id={iid}") == 'Beratung Pro')
rec('T14', 'M', 'Nicht vorhandener Artikel (id=99999) liefert saubere Fehlermeldung, kein 500er', s.get('/index.php?p=article_edit&id=99999').status < 500)
r = s.get('/index.php?p=article_edit&id=' + "1%20OR%20SLEEP(3)")
rec('T15', 'H', 'SQL-Injection über id-Parameter ohne Verzögerung', r.elapsed < 2.5 and r.status < 500, f'{r.elapsed:.1f}s')
errs = open(APP + '/../apache/php_error.log').read()
rec('T16', 'M', 'Keine PHP-Fehler/Warnungen im Log', not re.search(r'Fatal|Uncaught|TypeError|Warning', errs), re.findall(r'.*(?:Fatal|Warning).*', errs)[:2])
save_results(label + '_part4')

# --- Notizfeld (ab 1.5.2)
import subprocess, glob
s = authed()
r = art('Notiz-Artikel', '10', 'LS', '19', note='Zeile 1\nZeile 2 <b>fett</b> {ZEITRAUM}')
nid = sql("select max(id) from articles")
rec('T17', 'M', 'Artikel-Notiz wird gespeichert (Zeilenumbruch, Platzhalter bleibt als Text)', sql(f"select count(*) from articles where id={nid} and note like 'Zeile 1%{{ZEITRAUM}}'") == '1')
n0 = count(); art('x', '1', note='y' * 1001)
rec('T18', 'M', 'Notiz über 1000 Zeichen wird abgelehnt', count() == n0)
form = s.get('/index.php?p=invoice_edit').body
ed = s.get(f'/index.php?p=article_edit&id={nid}').body
rec('T19', 'H', 'Notiz maskiert im Rechnungsformular (data-note) und in der Artikelpflege (kein <b> im Markup)', 'data-note="Zeile 1' in form and '<b>fett</b>' not in form and '<b>fett</b>' not in ed and '&lt;b&gt;fett' in ed)
cid2 = sql('select max(id) from customers')
r = s.post('/index.php?p=invoice_edit', {'csrf': page_token(s, '/index.php?p=invoice_edit'), 'customer_id': cid2, 'issue_date': '2026-10-24', 'due_date': '2026-11-07', 'desc[]': ['Pos A', 'Pos B'], 'note[]': ['Notiztext A\nzweite Zeile <i>x</i>', ''], 'qty[]': ['1', '1'], 'unit[]': ['C62', 'C62'], 'price[]': ['10', '5'], 'vat[]': ['19', '19']})
inv = re.search(r'id=(\d+)', r.h('location')).group(1)
rec('T20', 'M', 'Positionsnotiz wird beim Entwurf gespeichert, leere Notiz → NULL', sql(f"select count(*) from invoice_items where invoice_id={inv} and note like 'Notiztext A%'") == '1' and sql(f"select count(*) from invoice_items where invoice_id={inv} and note is null") == '1')
r = s.post('/index.php?p=invoice_edit', {'csrf': page_token(s, '/index.php?p=invoice_edit'), 'customer_id': cid2, 'issue_date': '2026-10-24', 'due_date': '2026-11-07', 'desc[]': ['P'], 'note[]': ['z' * 1001], 'qty[]': ['1'], 'unit[]': ['C62'], 'price[]': ['1'], 'vat[]': ['19']})
rec('T21', 'M', 'Positionsnotiz > 1000 Zeichen wird abgelehnt', sql("select count(*) from invoice_items where note like 'zzzz%'") == '0')
shown = s.get(f'/index.php?p=invoice&id={inv}').body
rec('T22', 'H', 'Notiz in der Rechnungsansicht maskiert angezeigt', 'Notiztext A' in shown and '<i>x</i>' not in shown and '&lt;i&gt;x' in shown)
s.post(f'/index.php?p=invoice&id={inv}', {'action': 'issue', 'csrf': page_token(s, f'/index.php?p=invoice&id={inv}')})
pdf = s.get(f'/index.php?p=pdf&id={inv}')
open('/tmp/note_inv.pdf', 'wb').write(pdf.raw)
txt = subprocess.run(['pdftotext', '/tmp/note_inv.pdf', '-'], capture_output=True, text=True).stdout
xml = s.get(f'/index.php?p=xrechnung&id={inv}').body
rec('T23', 'H', 'Notiz erscheint im PDF unter der Position und in der XRechnung (Artikelbeschreibung), maskiert', 'Notiztext A' in txt and 'zweite Zeile' in txt and 'Notiztext A' in xml and '<i>x</i>' not in xml, (len(pdf.raw), 'Notiztext A' in xml))
dup = s.post(f'/index.php?p=invoice&id={inv}', {'action': 'duplicate', 'csrf': page_token(s, f'/index.php?p=invoice&id={inv}')})
rec('T24', 'M', 'Duplizieren übernimmt die Positionsnotiz', sql("select count(*) from invoice_items where note like 'Notiztext A%'") == '2', dup.status)
save_results(label + '_part4b')
