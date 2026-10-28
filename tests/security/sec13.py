"""Tests: Auswertung (ab 1.11.0). Frische Instanz."""
import sys, os, re
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()
r = anon.get('/index.php?p=reports')
rec('R1', 'H', 'Anonym: Auswertung nicht erreichbar', 'name="pass"' in r.body and 'Zahlungsverhalten' not in r.body, r.status)
pg = s.get('/index.php?p=reports').body
rec('R2', 'M', 'Leere Instanz: Hinweis statt Fehler, Navigation zeigt „Auswertung“', 'Noch keine ausgestellten Rechnungen' in pg and 'p=reports' in pg and 'aria-current="page"' in pg)
rec('R3', 'M', 'Keine PHP-Fehler bei leerer Auswertung', open('/home/claude/work/sec/apache/php_error.log').read().strip() == '')
# Daten anlegen: Kunde, 3 Rechnungen (1 pünktlich, 1 verspätet, 1 offen/überfällig) per SQL + Positionen
sql("insert into customers (name,street,zip,city,country) values ('Kunde <A> & Co','S 1','11111','Ort','DE'),('Kunde B','S 2','22222','Ort','DE')")
c1 = int(sql("select min(id) from customers where name like 'Kunde <A>%'")); c2 = int(sql("select id from customers where name='Kunde B'"))
def inv(num, cust, issue, due, status, paid, price):
    sql(f"insert into invoices (number,customer_id,status,issue_date,due_date,paid_at,public_token) values ('{num}',{cust},'{status}','{issue}','{due}',{paid and repr(paid) or 'NULL'},'tok{num}')")
    i = int(sql(f"select id from invoices where number='{num}'"))
    sql(f"insert into invoice_items (invoice_id,pos,description,quantity,unit,unit_price,vat_rate) values ({i},1,'Leistung',1,'C62',{price},19)")
    return i
inv('T1', c1, '2026-01-10', '2026-01-24', 'paid', '2026-01-20', 100)
inv('T2', c1, '2026-02-01', '2026-02-15', 'paid', '2026-02-25', 200)
inv('T3', c1, '2026-03-01', '2026-03-15', 'paid', '2026-03-10', 300)
inv('T4', c2, '2026-03-05', '2026-03-19', 'issued', None, 400)
inv('T5', c2, '2025-06-05', '2025-06-19', 'issued', None, 1000)
sql("insert into invoices (number,customer_id,status,issue_date,due_date,public_token) values (NULL,%d,'draft','2026-04-01','2026-04-15',NULL)" % c1)
pg = re.sub(r'>\s+<', '><', s.get('/index.php?p=reports&year=2026').body)
rec('R4', 'H', 'Jahressumme 2026 netto = 1.000,00 €, Entwurf zählt nicht', '1.000,00 €' in pg and re.search(r'Summe 2026</th><td class="r"><b>4</b>', pg), '')
rec('R5', 'M', 'Monatstabelle: März 700,00 € (300 + 400)', re.search(r'März</th><td class="r">2</td><td class="r">700,00 €', pg) is not None)
rec('R6', 'M', 'Jahresübersicht enthält 2025 mit 1.000,00 €', re.search(r'year=2025">2025</a></th><td class="r">1</td><td class="r">1.000,00 €', pg) is not None)
rec('R7', 'H', 'Pünktlichkeit Kunde A: 3 bezahlt, 67 % pünktlich, Ø Dauer 14,3 und Ø Verzug 10,0 Tage, Einstufung „meist pünktlich“', re.search(r'Kunde &lt;A&gt; &amp; Co</a></th><td class="r">3</td><td class="r">67 %</td><td class="r">14,3 Tage</td><td class="r">10,0 Tage</td>.*?meist pünktlich', pg, re.S) is not None, '')
rec('R8', 'H', 'Kundenname wird maskiert (kein ungefiltertes <A>)', 'Kunde <A>' not in pg)
rec('R9', 'M', 'Kunde B: 2 überfällige Forderungen, „zu wenig Daten“', re.search(r'Kunde B</a></th><td class="r">0</td><td class="r">–</td><td class="r">–</td><td class="r">–</td><td class="r">2 \(', pg) is not None and 'zu wenig Daten' in pg)
rec('R10', 'M', 'Diagramm vorhanden, mit Textalternative und Tabelle', '<svg class="chart"' in pg and 'role="img"' in pg and 'aria-label="Säulendiagramm' in pg)
bad = []
for y in ('abc', '1999', '2026\'--', '99999999999999999999', '-1', '2026&year=2025', '%00'):
    rr = s.get('/index.php?p=reports&year=' + y); bad.append((y, rr.status < 500 and 'Umsatz' in rr.body))
rec('R11', 'H', 'Manipulierter year-Parameter: kein 500, Rückfall auf gültiges Jahr', all(b[1] for b in bad), bad)
rr = s.get('/index.php?p=reports&year[]=1')
rec('R12', 'M', 'year als Array: kein 500', rr.status < 500, rr.status)
# Storno senkt Umsatz
sql("update invoices set cancels_id=NULL where 0")
i4 = int(sql("select id from invoices where number='T4'"))
sql(f"insert into invoices (number,customer_id,status,cancels_id,issue_date,due_date,public_token) values ('ST-T4',{c2},'issued',{i4},'2026-04-02','2026-04-02','tokst4')")
s4 = int(sql("select id from invoices where number='ST-T4'"))
sql(f"insert into invoice_items (invoice_id,pos,description,quantity,unit,unit_price,vat_rate) values ({s4},1,'Storno',-1,'C62',400,19)")
pg = re.sub(r'>\s+<', '><', s.get('/index.php?p=reports&year=2026').body)
rec('R13', 'H', 'Stornorechnung mindert den Umsatz im Storno-Monat (April −400,00 €, Jahr 600,00 €), storniertes T4 zählt nicht als offen', re.search(r'April</th><td class="r">1</td><td class="r">-400,00 €', pg) is not None and re.search(r'Summe 2026</th><td class="r"><b>5</b></td><td class="r"><b>600,00 €', pg) is not None and re.search(r'Kunde B</a></th><td class="r">0</td>.*?<td class="r">–</td><td', pg, re.S) is not None, '')
pe = open('/home/claude/work/sec/apache/php_error.log').read()
rec('R14', 'M', 'Keine PHP-Fehler im Log', pe.strip() == '', pe[:300])
save_results(label)
