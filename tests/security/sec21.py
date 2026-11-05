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
