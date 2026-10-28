"""Tests für Stornorechnungen (ab 1.9.0). Frische Instanz. Prüft Ablauf, Nummernkreis, Verknüpfung, PDF, XML (KoSIT), Sperren und Migration."""
import sys, os, re, subprocess, tempfile, time, glob, json
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
MAILS = os.environ.get('RG_MAILS', '/home/claude/work/sec/mails')
clear_rl()
s = authed(); anon = C()
tmp = tempfile.mkdtemp()
CFG = APP + '/config.php'
def txt(raw):
    p = os.path.join(tmp, 't.pdf'); open(p, 'wb').write(raw)
    return subprocess.run(['pdftotext', '-layout', p, '-'], capture_output=True, text=True).stdout
def kosit(xml, name):
    p = os.path.join(tmp, name); open(p, 'w', encoding='utf-8').write(xml)
    out = subprocess.run(f'cd /home/claude/work/kosit && java -jar v.jar -s cfg/scenarios.xml -r cfg -o {tmp}/kout_{name} {p}', shell=True, capture_output=True, text=True, timeout=180)
    return 'acceptable' in (out.stdout + out.stderr).lower() or ('ACCEPTABLE' in open(glob.glob(f'{tmp}/kout_{name}/*report.xml')[0]).read() if glob.glob(f'{tmp}/kout_{name}/*report.xml') else False), (out.stdout + out.stderr)[-300:]

t0 = page_token(s, '/index.php?p=customer_edit')
s.post('/index.php?p=customer_edit', {'csrf': t0, 'name': 'Beispiel GmbH', 'contact': '', 'email': 'e@example.com', 'street': 'Hauptstr. 5', 'zip': '12345', 'city': 'Musterstadt', 'country': 'DE', 'vat_id': '', 'buyer_reference': ''})
s.post('/index.php?p=customer_edit', {'csrf': page_token(s, '/index.php?p=customer_edit'), 'name': 'Zweitkunde AG', 'contact': '', 'email': 'z@example.com', 'street': 'Weg 1', 'zip': '54321', 'city': 'Stadt', 'country': 'DE', 'vat_id': '', 'buyer_reference': ''})
cid = sql("select id from customers where name='Beispiel GmbH'"); cid2 = sql("select id from customers where name='Zweitkunde AG'")
def mk(issue=True, qty='10', price='85', desc='Webentwicklung'):
    d = {'csrf': page_token(s, '/index.php?p=invoice_edit'), 'customer_id': cid, 'issue_date': '2026-10-24', 'delivery_date': '', 'due_date': '2026-11-07', 'notes': '',
         'desc[]': [desc], 'qty[]': [qty], 'unit[]': ['HUR'], 'price[]': [price], 'vat[]': ['19']}
    r = s.post('/index.php?p=invoice_edit', d); iid = re.search(r'id=(\d+)', r.h('location')).group(1)
    if issue: s.post(f'/index.php?p=invoice&id={iid}', {'csrf': page_token(s, f'/index.php?p=invoice&id={iid}'), 'action': 'issue'})
    return iid
def act(iid, action, csrf=True, client=None, **kw):
    c = client or s
    d = {'action': action}; d.update(kw)
    if csrf: d['csrf'] = page_token(c, f'/index.php?p=invoice&id={iid}')
    return c.post(f'/index.php?p=invoice&id={iid}', d)
def storno_of(iid): return sql(f'select id from invoices where cancels_id={iid}')
def num(iid): return sql(f'select number from invoices where id={iid}')
def tok(iid): return sql(f'select public_token from invoices where id={iid}')
def edit_storno(sid, qty, price='85', customer=None, desc='Webentwicklung'):
    d = {'csrf': page_token(s, f'/index.php?p=invoice_edit&id={sid}'), 'customer_id': customer or cid, 'issue_date': '2026-10-27', 'delivery_date': '', 'due_date': '2026-10-27', 'notes': 'Storno-Test',
         'desc[]': [desc], 'qty[]': [qty], 'unit[]': ['HUR'], 'price[]': [price], 'vat[]': ['19']}
    return s.post(f'/index.php?p=invoice_edit&id={sid}', d)

# --- Zugriff / CSRF / Voraussetzungen
i1 = mk(); n1 = num(i1)
r = act(i1, 'cancel', client=anon); r2 = act(i1, 'cancel', csrf=False)
rec('C1', 'H', 'Ohne Login bzw. ohne CSRF-Token kein Stornoentwurf', sql('select count(*) from invoices where cancels_id is not null') == '0' and r2.status == 400, (r.status, r2.status))
idr = mk(issue=False)
act(idr, 'cancel')
rec('C2', 'M', 'Entwürfe lassen sich nicht stornieren', sql('select count(*) from invoices where cancels_id is not null') == '0')
# --- Stornoentwurf
r = act(i1, 'cancel')
sid = storno_of(i1)
loc = r.h('location')
rec('C3', 'H', 'Storno-Aktion legt genau einen Entwurf mit Bezug an und leitet dorthin weiter', sid != '' and sql(f'select status from invoices where id={sid}') == 'draft' and f'id={sid}' in loc, (r.status, loc))
it = sql(f'select quantity,unit_price,vat_rate,description from invoices i join invoice_items t on t.invoice_id=i.id where i.id={sid}')
rec('C4', 'H', 'Positionen: Menge negativ (−10), Einzelpreis positiv (85), Steuersatz und Text übernommen; Kunde gleich', it.split('\t')[0:3] == ['-10.000', '85.00', '19.00'] and 'Webentwicklung' in it and sql(f'select customer_id from invoices where id={sid}') == cid, it)
rec('C5', 'M', 'Hinweistext nennt Originalnummer und Datum', f'Storno zu Rechnung {n1} vom 24.10.2026' in sql(f'select notes from invoices where id={sid}'))
act(i1, 'cancel'); act(i1, 'cancel')
rec('C6', 'H', 'Zweiter Stornoversuch erzeugt keinen weiteren Entwurf', sql(f'select count(*) from invoices where cancels_id={i1}') == '1')
pg = s.get(f'/index.php?p=invoice&id={i1}').body
rec('C7', 'M', 'Originalseite: Link zum Stornoentwurf, kein weiterer Stornobutton', f'id={sid}' in pg and 'Stornoentwurf' in pg and 'value="cancel"' not in pg)
# --- Stornoentwurf bearbeiten
r = edit_storno(sid, '0')
rec('C8', 'H', 'Menge 0 im Stornoentwurf wird abgelehnt', sql(f'select quantity from invoice_items where invoice_id={sid}') == '-10.000', r.status)
r = edit_storno(sid, '-10', price='-5')
rec('C9', 'H', 'Negativer Einzelpreis im Stornoentwurf wird abgelehnt', sql(f'select unit_price from invoice_items where invoice_id={sid}') == '85.00', r.status)
edit_storno(sid, '-10', customer=cid2)
rec('C10', 'H', 'Der Kunde einer Stornorechnung lässt sich nicht ändern (Manipulationsversuch ignoriert)', sql(f'select customer_id from invoices where id={sid}') == cid)
fp = s.get(f'/index.php?p=invoice_edit&id={sid}').body
rec('C11', 'M', 'Bearbeitungsformular: Kunde fix, Menge ohne min=0, Hinweis auf Teilstorno', 'type="hidden" name="customer_id"' in fp and 'name="qty[]"' in fp and not re.search(r'name="qty\[\]"[^>]*min="0"', fp) and 'Teilstorno' in fp)
r = act(sid, 'duplicate')
rec('C12', 'H', 'Eine Stornorechnung lässt sich nicht duplizieren', sql('select count(*) from invoices where cancels_id is null and status="draft"') == '1' and r.status in (302, 303), sql('select count(*) from invoices'))
# --- Positive Summe darf nicht ausgestellt werden
edit_storno(sid, '3')
act(sid, 'issue')
rec('C13', 'H', 'Stornorechnung mit positivem Gesamtbetrag wird nicht ausgestellt', sql(f'select status from invoices where id={sid}') == 'draft')
edit_storno(sid, '-10')
# --- Ausstellen
act(sid, 'issue')
ns = num(sid)
rec('C14', 'H', 'Ausgestellt mit eigenem Nummernkreis ST-JJJJ-NNNN, Fälligkeit = Rechnungsdatum', re.fullmatch(r'ST-\d{4}-0001', ns) is not None and sql(f'select due_date=issue_date from invoices where id={sid}') == '1' and sql(f'select status from invoices where id={sid}') == 'issued', ns)
rec('C15', 'M', 'Nummernkreis der Rechnungen bleibt unberührt (nächste Rechnung RE-…-0002)', num(mk()).endswith('-0002'))
pdf = s.get(f'/index.php?p=pdf&id={sid}').raw
t = txt(pdf)
rec('C16', 'H', 'PDF: „Stornorechnung ST-…“, Bezug auf Originalnummer, negative Menge/Beträge, Erstattungshinweis, kein „Fällig am“', f'Stornorechnung {ns}' in t and n1 in t and re.search(r'-10', t) and '-1.011,50' in t.replace('−', '-') and 'erstattet' in t and 'Fällig am' not in t, t[:600])
# GiroCode darf nicht erscheinen
import cv2
subprocess.run(['pdftoppm', '-r', '200', '-png', '-singlefile', os.path.join(tmp, 't.pdf'), os.path.join(tmp, 'g')], check=True)
img = cv2.imread(os.path.join(tmp, 'g.png'))
qr = cv2.QRCodeDetectorAruco().detectAndDecode(img)[0] or cv2.QRCodeDetector().detectAndDecode(img)[0]
rec('C17', 'H', 'Kein GiroCode auf der Stornorechnung', qr == '' and 'GiroCode' not in t, qr)
# --- XML
xr = s.get(f'/index.php?p=xrechnung&id={sid}')
rec('C18', 'H', 'XRechnung: Typcode 384, Bezug auf Originalrechnung (BT-25), negative Menge, negative Summen, keine Zahlungsfrist', xr.status == 200 and '<ram:TypeCode>384</ram:TypeCode>' in xr.body and f'<ram:IssuerAssignedID>{n1}</ram:IssuerAssignedID>' in xr.body and '<ram:BilledQuantity unitCode="HUR">-10' in xr.body and 'GrandTotalAmount>-1011.50' in xr.body and 'SpecifiedTradePaymentTerms' not in xr.body, xr.body[:200])
ok, out = kosit(xr.body, 'xr.xml')
rec('C19', 'H', 'KoSIT-Validator akzeptiert die XRechnung der Stornorechnung', ok, out)
pp = os.path.join(tmp, 's.pdf'); open(pp, 'wb').write(pdf)
subprocess.run(['pdfdetach', '-save', '1', '-o', os.path.join(tmp, 'fx.xml'), pp], capture_output=True)
fx = open(os.path.join(tmp, 'fx.xml'), encoding='utf-8').read() if os.path.exists(os.path.join(tmp, 'fx.xml')) else ''
ok2, out2 = kosit(fx, 'fx.xml') if fx else (False, 'keine XML')
rec('C20', 'H', 'KoSIT-Validator akzeptiert das in die PDF eingebettete ZUGFeRD-XML (EN 16931)', ok2 and '384' in fx, out2)
# --- Original danach
pg = s.get(f'/index.php?p=invoice&id={i1}').body
rec('C21', 'H', 'Originalseite: „storniert durch ST-…“, kein Zahlungseingang-Formular, kein Stornobutton', f'storniert durch' in pg and ns in pg and 'Als bezahlt markieren' not in pg and 'value="cancel"' not in pg and 'Korrigierte Rechnung' in pg)
act(i1, 'paid', paid_at='2026-10-27')
rec('C22', 'H', 'Stornierte Rechnung lässt sich nicht mehr als bezahlt markieren', sql(f'select status from invoices where id={i1}') == 'issued')
pgs = s.get(f'/index.php?p=invoice&id={sid}').body
rec('C23', 'M', 'Stornoseite: Bezug zur Originalrechnung, Badge „Storno“, kein Zahlungsformular, kein Duplizieren/Stornieren', n1 in pgs and 'badge cancel' in pgs and 'Als bezahlt markieren' not in pgs and 'value="duplicate"' not in pgs and 'value="cancel"' not in pgs)
act(sid, 'paid', paid_at='2026-10-27')
rec('C24', 'M', 'Stornorechnung lässt sich nicht als bezahlt markieren', sql(f'select status from invoices where id={sid}') == 'issued')
act(sid, 'cancel')
rec('C25', 'H', 'Eine Stornorechnung lässt sich nicht stornieren', sql(f'select count(*) from invoices where cancels_id={sid}') == '0')
lst = s.get('/index.php?p=invoices').body
rec('C26', 'M', 'Liste: Original „storniert“, Storno „Storno“, keine Überfälligkeit', lst.count('badge cancel') == 2 and 'überfällig' not in lst.split(n1)[1].split('</tr>')[0], lst.count('badge cancel'))
# --- Statusseiten
so = anon.get(f'/status.php?t={tok(i1)}').body
rec('C27', 'H', 'Statusseite der stornierten Rechnung: Hinweis „storniert“, keine IBAN, kein GiroCode, nicht „überfällig“', 'wurde storniert' in so and 'IBAN' not in so and 'GiroCode' not in so and 'überfällig' not in so and 'nicht eingegangen' not in so, so[:300])
ss = anon.get(f'/status.php?t={tok(sid)}').body
rec('C28', 'H', 'Statusseite der Stornorechnung: hebt Originalrechnung auf, nichts zu überweisen, kein GiroCode', 'hebt die Rechnung' in ss and n1 in ss and 'IBAN' not in ss and 'GiroCode' not in ss and 'Stornorechnung als PDF' in ss)
dl = anon.get(f'/status.php?t={tok(sid)}&dl=pdf')
rec('C29', 'M', 'PDF-Download über die Statusseite der Stornorechnung funktioniert', dl.raw[:4] == b'%PDF')
# --- Korrigierte Rechnung = Duplizieren der stornierten Rechnung
before = int(sql('select count(*) from invoices'))
r = act(i1, 'duplicate')
rec('C30', 'M', '„Korrigierte Rechnung erstellen“ legt eine normale Kopie als Entwurf an (ohne Stornobezug)', int(sql('select count(*) from invoices')) == before + 1 and sql('select cancels_id is null and status="draft" from invoices order by id desc limit 1') == '1', r.status)
# --- Teilstorno an bezahlter Rechnung
i2 = mk(qty='10', price='100'); act(i2, 'paid', paid_at='2026-10-27')
act(i2, 'cancel'); sid2 = storno_of(i2)
edit_storno(sid2, '-4', price='100'); act(sid2, 'issue')
t2 = txt(s.get(f'/index.php?p=pdf&id={sid2}').raw)
rec('C31', 'H', 'Bezahlte Rechnung: Teilstorno (−4 × 100 € netto) wird ausgestellt, Summe −476,00 €, Original bleibt „bezahlt“ mit Hinweis', num(sid2).startswith('ST-') and '-476,00' in t2.replace('−', '-') and sql(f'select status from invoices where id={i2}') == 'paid' and 'Zahlung wurde bereits verbucht' in s.get(f'/index.php?p=invoice&id={i2}').body, t2[-300:])
# --- Entwurf löschen gibt Neustart frei
i3 = mk(); act(i3, 'cancel'); sid3 = storno_of(i3); act(sid3, 'delete'); act(i3, 'cancel')
rec('C32', 'M', 'Gelöschter Stornoentwurf: Storno lässt sich neu anlegen', sql(f'select count(*) from invoices where cancels_id={i3}') == '1')
# --- Präfix aus Einstellung
orig = open(CFG).read()
open(CFG, 'w').write(orig.replace("'KLEINUNTERNEHMER' => '0',", "'KLEINUNTERNEHMER' => '0',\n  'CANCEL_PREFIX' => 'GS',")); time.sleep(2.5)
sid3 = storno_of(i3); act(sid3, 'issue')
rec('C33', 'M', 'Konfiguriertes Storno-Präfix (GS) wird für den Nummernkreis verwendet', re.fullmatch(r'GS-\d{4}-0001', num(sid3)) is not None, num(sid3))
open(CFG, 'w').write(orig); time.sleep(3.5)
st = s.get('/index.php?p=settings').body
rec('C34', 'L', 'Einstellungen: Feld „Präfix Stornorechnungen“ mit Standard ST', 'name="CANCEL_PREFIX"' in st and 'value="ST"' in st)
# --- Mail
for f in glob.glob(MAILS + '/*.eml'): os.remove(f)
act(sid, 'send', to='e@example.com', message='Hallo')
time.sleep(1.5)
em = ''.join(open(f, errors='replace').read() for f in glob.glob(MAILS + '/*.eml'))
rec('C35', 'M', 'Mail zur Stornorechnung: Betreff „Stornorechnung …“ mit PDF-Anhang', 'Subject: Stornorechnung ' + ns in em.replace('\r', '') and 'application/pdf' in em, em[:200])
# --- Migration von v5
sql('alter table invoices drop index uq_cancels'); sql('alter table invoices drop column cancels_id')
open(APP + '/storage/schema_version', 'w').write('5')
s.get('/index.php?p=invoices')
cols = sql("select count(*) from information_schema.columns where table_schema='rechnung_sec' and table_name='invoices' and column_name='cancels_id'")
rec('C36', 'H', 'Migration: Spalte cancels_id wird bei einer 1.8-Installation automatisch ergänzt, schema_version = aktuell (7)', cols == '1' and open(APP + '/storage/schema_version').read().strip() == '7', cols)
pe = open('/home/claude/work/sec/apache/php_error.log').read()
rec('C37', 'M', 'Keine PHP-Fehler/Warnungen im Log', pe.strip() == '', pe[:300])
save_results(label)
