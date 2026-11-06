"""Tests: Leerzustände (Rechnungsübersicht ohne Rechnungen, Kundenfeld ohne Kunden) ab 1.19.2. Frische Instanz ohne Kunden."""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed()
b = s.get('/index.php?p=invoices').body
rec('E1', 'M', 'Leere Rechnungsübersicht zeigt nur „Noch keine Rechnungen.“ (kein Kundenhinweis)', 'Noch keine Rechnungen.' in b and 'Lege zuerst' not in b)
for p in ['invoice_edit', 'offer_edit', 'recurring_edit']:
    b = s.get('/index.php?p=' + p).body
    rec('E2', 'M', f'{p} ohne Kunden: Hinweis „Lege zuerst einen Kunden an“ statt Auswahlfeld', 'Noch keine Kunden. Lege zuerst einen' in b and 'name="customer_id"' not in b)
s.post('/index.php?p=customer_edit', {'name': 'Ein Kunde', 'street': 's 1', 'zip': '12345', 'city': 'c', 'country': 'DE', 'email': 'k@example.com', 'csrf': page_token(s, '/index.php?p=customer_edit')})
for p in ['invoice_edit', 'offer_edit', 'recurring_edit']:
    b = s.get('/index.php?p=' + p).body
    rec('E3', 'M', f'{p} mit Kunden: Auswahlfeld, kein Hinweis', 'name="customer_id"' in b and 'Noch keine Kunden' not in b)
b = s.get('/index.php?p=invoices').body
rec('E4', 'M', 'Mit Kunden, aber ohne Rechnungen: weiterhin nur „Noch keine Rechnungen.“', 'Noch keine Rechnungen.' in b and 'Lege zuerst' not in b)

# --- Einheiten Monat(e) und Jahr(e) (ab 1.19.3)
import re, subprocess, tempfile, glob, datetime
tmp = tempfile.mkdtemp(); iso = datetime.date.today().isoformat()
cid = sql('select max(id) from customers')
p = '/index.php?p=invoice_edit'
opts = s.get(p).body
rec('U1', 'M', 'Einheitenliste enthält Monat(e) und Jahr(e) (MON/ANN)', 'value="MON"' in opts and 'Monat(e)' in opts and 'value="ANN"' in opts and 'Jahr(e)' in opts)
r = s.post(p, {'csrf': page_token(s, p), 'customer_id': cid, 'issue_date': iso, 'due_date': '', 'delivery_date': '', 'notes': '', 'desc[]': ['Hosting', 'Domain'], 'qty[]': ['12', '3'], 'unit[]': ['MON', 'ANN'], 'price[]': ['10,00', '15,00'], 'vat[]': ['19', '19']})
iid = re.search(r'id=(\d+)', r.h('location')).group(1); pp = f'/index.php?p=invoice&id={iid}'
s.post(pp, {'csrf': page_token(s, pp), 'action': 'issue'})
b = s.get(pp).body
rec('U2', 'M', 'Rechnungsansicht zeigt „12 Monat(e)“ und „3 Jahr(e)“', '12 Monat(e)' in b and '3 Jahr(e)' in b)
pdf = s.get(f'/index.php?p=pdf&id={iid}').raw; open(tmp + '/t.pdf', 'wb').write(pdf)
t = subprocess.run(['pdftotext', '-layout', tmp + '/t.pdf', '-'], capture_output=True, text=True).stdout
rec('U3', 'M', 'PDF zeigt Monat(e) und Jahr(e) in der Mengenspalte', re.search(r'12\s+Monat\(e\)', t) is not None and re.search(r'3\s+Jahr\(e\)', t) is not None, t[:200])
tok = sql(f'select public_token from invoices where id={iid}'); anon = C()
x = anon.get(f'/status.php?t={tok}&dl=xrechnung').body
rec('U4', 'H', 'XRechnung-XML enthält unitCode MON und ANN', 'unitCode="MON"' in x and 'unitCode="ANN"' in x, re.findall(r'unitCode="[A-Z0-9]+"', x))
open(tmp + '/x.xml', 'w', encoding='utf-8').write(x)
out = subprocess.run(f'cd /home/claude/work/kosit && java -jar v.jar -s cfg/scenarios.xml -r cfg -o {tmp}/kout {tmp}/x.xml', shell=True, capture_output=True, text=True, timeout=180)
rep = glob.glob(f'{tmp}/kout/*report.xml'); ok = 'acceptable' in (out.stdout + out.stderr).lower() or (bool(rep) and 'ACCEPTABLE' in open(rep[0]).read())
rec('U5', 'H', 'KoSIT-Validator akzeptiert die XRechnung mit MON/ANN', ok, (out.stdout + out.stderr)[-200:])
