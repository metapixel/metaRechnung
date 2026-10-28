"""Tests: Änderungsprotokoll (ab 1.12.0). Frische Instanz, SMTP-Sink (RG_MAILS) laufend."""
import sys, os, re, datetime, subprocess, threading
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()
def log(q=''): return [l.split('\t') for l in sql("select actor,action,entity,ifnull(entity_id,''),summary,ip from audit_log " + q + " order by id").split('\n') if l]
def last(): return log('where id=(select max(id) from audit_log)')[0]
def n(): return int(sql('select count(*) from audit_log'))
def verify(c=None):
    c = c or s
    r = c.post('/index.php?p=audit', {'csrf': page_token(c, '/index.php?p=audit')})
    return flash_of(c.get('/index.php?p=audit').body) or flash_of(r.body)

# --- Zugriff
r = anon.get('/index.php?p=audit'); n0 = n()
r2 = anon.post('/index.php?p=audit', {})
rec('L1', 'H', 'Anonym: Protokoll nicht erreichbar', 'name="pass"' in r.body and 'Integrität' not in r.body and 'Protokoll' not in r.body.split('<main')[-1][:400], r.status)
rec('L2', 'H', 'Anmeldung wurde protokolliert (Benutzer kai, Aktion auth.login)', any(x[0] == 'kai' and x[1] == 'auth.login' for x in log()), log()[:2])
pg = s.get('/index.php?p=audit').body
rec('L3', 'M', 'Protokollseite zeigt Anzeigenamen statt Codes', 'Angemeldet' in pg and 'auth.login' not in pg)
rec('L4', 'M', 'Einstellungen verlinken das Protokoll', 'p=audit' in s.get('/index.php?p=settings').body)

# --- Fehlanmeldungen (durch Rate-Limit begrenzt) + XSS/Steuerzeichen im Benutzernamen
evil = '<img src=x onerror=alert(1)>\r\nFAKE'
for i in range(8):
    c = C(); login(c, evil if i == 0 else 'x%d' % i, 'falsch')
fl = [x for x in log() if x[1] == 'auth.login_failed']
rec('L5', 'H', 'Fehlversuche protokolliert, aber durch Rate-Limit begrenzt (nicht unbegrenzt schreibbar)', 1 <= len(fl) <= 6, len(fl))
rec('L6', 'H', 'Steuerzeichen im versuchten Benutzernamen werden entfernt (keine Zeilenfälschung)', all('\n' not in x[0] and '\r' not in x[0] for x in fl) and any(x[0].startswith('<img') for x in fl), [x[0] for x in fl][:2])
pg = s.get('/index.php?p=audit').body
rec('L7', 'H', 'Benutzername aus Fehlanmeldung wird maskiert ausgegeben (kein XSS)', '<img src=x' not in pg and '&lt;img src=x' in pg)
clear_rl(); s = authed()

# --- Kunden / Artikel
def cust(name, **kw):
    d = {'name': name, 'street': 's 1', 'zip': '12345', 'city': 'c', 'country': 'DE', 'email': ''}; d.update(kw)
    d['csrf'] = page_token(s, '/index.php?p=customer_edit'); s.post('/index.php?p=customer_edit', d); return sql('select max(id) from customers')
cid = cust('Prüf <b>GmbH</b>', email='k@example.com')
e = last()
rec('L8', 'H', 'Kunde angelegt → customer.create mit Name, Benutzer, IP', e[1] == 'customer.create' and e[0] == 'kai' and e[4] == 'Prüf <b>GmbH</b>' and e[5] != '' and e[3] == cid, e)
s.post(f'/index.php?p=customer_edit&id={cid}', {'csrf': page_token(s, f'/index.php?p=customer_edit&id={cid}'), 'name': 'Prüf GmbH', 'street': 's 1', 'zip': '12345', 'city': 'c', 'country': 'DE'})
rec('L9', 'M', 'Kunde geändert → customer.update', last()[1] == 'customer.update')
pg = s.get('/index.php?p=audit').body
rec('L10', 'H', 'Kundenname im Protokoll maskiert', '<b>GmbH</b>' not in pg and '&lt;b&gt;GmbH&lt;/b&gt;' in pg)
s.post('/index.php?p=article_edit', {'csrf': page_token(s, '/index.php?p=article_edit'), 'description': 'Beratung', 'unit': 'HUR', 'unit_price': '10', 'vat_rate': '19'})
aid = sql('select max(id) from articles'); a1 = last()
s.post(f'/index.php?p=article_edit&id={aid}', {'csrf': page_token(s, f'/index.php?p=article_edit&id={aid}'), 'action': 'duplicate'})
a2 = last()
s.post(f'/index.php?p=article_edit&id={aid}', {'csrf': page_token(s, f'/index.php?p=article_edit&id={aid}'), 'action': 'delete'})
rec('L11', 'M', 'Artikel: anlegen, duplizieren, löschen protokolliert', a1[1] == 'article.create' and a2[1] == 'article.duplicate' and last()[1] == 'article.delete' and last()[4] == 'Beratung', (a1, a2, last()))

# --- Rechnungen
def draft():
    p = '/index.php?p=invoice_edit'
    s.post(p, {'csrf': page_token(s, p), 'customer_id': cid, 'issue_date': datetime.date.today().isoformat(), 'due_date': '', 'delivery_date': '', 'notes': '',
               'desc[]': ['Leistung'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': ['100,00'], 'vat[]': ['19']})
    return int(sql('select max(id) from invoices'))
def act(i, a, **kw):
    p = f'/index.php?p=invoice&id={i}'; d = {'csrf': page_token(s, p), 'action': a}; d.update(kw); return s.post(p, d)
i1 = draft(); c1 = last()
act(i1, 'issue'); c2 = last(); num = sql(f'select number from invoices where id={i1}')
act(i1, 'paid', paid_at='2026-10-20'); c3 = last()
act(i1, 'unpaid'); c4 = last()
act(i1, 'send', to='kunde@example.com', message='Hallo'); c5 = last()
act(i1, 'cancel'); c6 = last()
act(i1, 'duplicate'); c7 = last()
dd = int(sql('select max(id) from invoices')); act(dd, 'delete'); c8 = last()
rec('L12', 'H', 'Rechnungsentwurf angelegt und ausgestellt (Nummer im Protokoll)', c1[1] == 'invoice.create' and c2[1] == 'invoice.issue' and num in c2[4] and c2[3] == str(i1), (c1, c2))
rec('L13', 'H', 'bezahlt (mit Datum), offen, E-Mail-Versand (Empfänger) protokolliert', c3[1] == 'invoice.paid' and '2026-10-20' in c3[4] and c4[1] == 'invoice.unpaid' and c5[1] == 'invoice.send' and 'kunde@example.com' in c5[4], (c3, c4, c5))
rec('L14', 'H', 'Storno, Duplizieren, Entwurf löschen protokolliert', c6[1] == 'invoice.cancel' and num in c6[4] and c7[1] == 'invoice.duplicate' and c8[1] == 'invoice.delete', (c6, c7, c8))
pg = s.get('/index.php?p=audit').body
rows_ = re.findall(r'<tr>(.*?)</tr>', pg, re.S); del_ = [x for x in rows_ if 'Entwurf gelöscht' in x]
rec('L15', 'M', 'Objektlink auf die Rechnung vorhanden, in „Entwurf gelöscht“-Zeilen nicht', f'p=invoice&amp;id={i1}">#{i1}</a>' in pg and del_ and all('<a ' not in x.split('<td>Entwurf gelöscht')[0].split('</td>')[-3] if False else '#' + str(dd) + '</a>' not in x for x in del_), len(del_))

# --- Einstellungen: nur Feldnamen, keine Werte/Passwörter
pw = 'GanzNeuesPasswort-4711'
d = {'csrf': page_token(s), 'action': 'save', 'COMPANY_NAME': 'Test Firma', 'COMPANY_OWNER': 'Inhaber', 'COMPANY_STREET': 'Weg 1', 'COMPANY_ZIP': '12345', 'COMPANY_CITY': 'Ort', 'COMPANY_COUNTRY': 'DE',
     'COMPANY_IBAN': 'DE89370400440532013000', 'SMTP_PASS': 'smtp-geheim-999', 'INVOICE_PREFIX': 'RE', 'PAYMENT_DAYS': '14'}
s.post('/index.php?p=settings', d); e = last()
rec('L16', 'H', 'Einstellungen: nur Feldnamen im Protokoll, keine Werte (IBAN, SMTP-Passwort)', e[1] == 'settings.save' and 'COMPANY_IBAN' in e[4] and 'DE89' not in e[4] and 'smtp-geheim' not in e[4], e)
s.post('/index.php?p=settings', {'csrf': page_token(s), 'action': 'account', 'current_pass': 'SehrGeheim12345', 'new_user': 'kai2', 'new_pass': pw, 'new_pass2': pw}); e = last()
rec('L17', 'H', 'Zugangsdaten geändert: protokolliert ohne Passwort; neuer Benutzername wird Akteur', e[1] == 'auth.account' and pw not in e[4] and 'Passwort geändert' in e[4], e)
s2 = authed('kai2', pw)
s2.post('/index.php?p=customer_edit', {'csrf': page_token(s2, '/index.php?p=customer_edit'), 'name': 'Von kai2', 'street': 's', 'zip': '1', 'city': 'c', 'country': 'DE'})
rec('L18', 'M', 'Nach Umbenennung erscheint der neue Benutzername als Akteur', last()[0] == 'kai2' and last()[1] == 'customer.create', last())
s = s2
bad = sql(f"select count(*) from audit_log where summary like '%{pw}%' or summary like '%smtp-geheim%' or summary like '%DE89370%'")
rec('L19', 'H', 'Passwörter/Geheimnisse tauchen nirgends im Protokoll auf', bad == '0', bad)
s.post('/index.php?p=settings', {'csrf': page_token(s), 'action': 'account', 'current_pass': 'falsch', 'new_user': 'kai2', 'new_pass': 'x' * 12, 'new_pass2': 'x' * 12})
rec('L20', 'M', 'Falsches Passwort bei Zugangsänderung wird protokolliert', last()[1] == 'auth.password_failed')

# --- Filter, Suche, Paging, Parameter
r = s.get('/index.php?p=audit&g=customer').body
rec('L21', 'M', 'Filter „Kunden“ zeigt keine Rechnungsaktionen', 'Kunde angelegt' in r and 'Rechnung ausgestellt' not in r)
r = s.get('/index.php?p=audit&q=' + urllib.parse.quote(num)).body
rec('L22', 'M', 'Suche nach Rechnungsnummer findet Einträge', 'Rechnung ausgestellt' in r)
r = s.get('/index.php?p=audit&q=%25').body
rec('L23', 'M', 'LIKE-Platzhalter in der Suche wird wörtlich behandelt („%“ findet nichts)', 'Keine Einträge' in r)
bad = [s.get('/index.php?p=audit&' + q).status for q in ('page=abc', 'page=-5', 'page=99999999999999999999', 'g[]=x', 'q[]=x', "g=customer'--", 'page[]=1')]
rec('L24', 'H', 'Manipulierte Parameter führen nicht zu Fehler 500', all(b < 500 for b in bad), bad)
rec('L25', 'H', 'SQL-Injection über q wirkungslos', s.get("/index.php?p=audit&q=" + urllib.parse.quote("' OR 1=1 -- ")).body.count('<tr>') <= 1)
for i in range(110): sql(f"insert into audit_log (created_at,actor,ip,action,entity,summary,prev_hash,hash) values (now(),'t','','customer.update','customer','bulk{i}','x','y')")
r1 = s.get('/index.php?p=audit&q=bulk').body; r2 = s.get('/index.php?p=audit&q=bulk&page=2').body
rec('L26', 'M', 'Seitenweise Anzeige: 100 pro Seite, Seite 2 zeigt den Rest, Navigationslinks vorhanden', r1.count('<td>bulk') == 100 and r2.count('<td>bulk') == 10 and 'Ältere' in r1 and 'Neuere' in r2, (r1.count('<td>bulk'), r2.count('<td>bulk')))
sql("delete from audit_log where actor='t'")   # Testdaten entfernen (zerstört die Kette absichtlich nicht: Zeilen lagen am Ende)

# --- Integrität
sql("delete from audit_log where actor='t'")
ok = verify()
rec('L27', 'H', 'Integritätsprüfung: unveränderte Kette ist intakt', 'lückenlos' in ok, ok)
anid = sql('select id from audit_log order by id limit 1 offset 5')
sql(f"update audit_log set summary='manipuliert' where id={anid}")
bad = verify()
rec('L28', 'H', 'Nachträglich geänderter Eintrag wird erkannt (Nummer genannt)', 'ACHTUNG' in bad and f'Nr. {anid}' in bad, bad)
sql(f"update audit_log set summary=(select 'x') where id={anid}")
mid = sql('select id from audit_log order by id limit 1 offset 8')
sql(f"delete from audit_log where id={mid}")
bad = verify()
rec('L29', 'H', 'Gelöschter Eintrag mitten in der Kette wird erkannt', 'ACHTUNG' in bad, bad)
r = s.post('/index.php?p=audit', {})
rec('L30', 'H', 'Prüfung ohne CSRF-Token → 400', r.status == 400, r.status)

# --- Robustheit
sql('RENAME TABLE audit_log TO audit_log_x')
n0 = int(sql('select count(*) from customers')); cust('Trotz Protokollfehler')
ok_ = int(sql('select count(*) from customers')) == n0 + 1
sql('RENAME TABLE audit_log_x TO audit_log')
rec('L31', 'H', 'Fällt das Protokoll aus, funktioniert die eigentliche Aktion weiter (kein Abbruch)', ok_)
# Migration von v6: Tabelle fehlt, schema_version 6
sql('DROP TABLE audit_log'); open(APP + '/storage/schema_version', 'w').write('6'); subprocess.run(['chown', 'www-data', APP + '/storage/schema_version'])
anon.get('/index.php'); time.sleep(1)
rec('L32', 'H', 'Update von v6: Tabelle wird automatisch angelegt, schema_version = aktuell', sql("select count(*) from information_schema.tables where table_schema=database() and table_name='audit_log'") == '1' and open(APP + '/storage/schema_version').read().strip() == SCHEMA_CUR)
cust('Nach Migration'); rec('L33', 'M', 'Nach der Migration wird wieder protokolliert (Kette beginnt neu)', n() == 1 and last()[1] == 'customer.create' and sql('select prev_hash from audit_log') == '')

# --- Cron / parallele Schreiber
rp = '/index.php?p=recurring_edit'
s.post(rp, {'csrf': page_token(s, rp), 'name': 'Hosting', 'customer_id': cid, 'frequency': 'month', 'period': 'previous', 'mode': 'draft', 'next_run': datetime.date.today().isoformat(), 'end_date': '', 'notes': '', 'mail_message': '', 'active': '1',
            'desc[]': ['Hosting'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': ['25,00'], 'vat[]': ['19']})
cr = last()
tok = re.search(r"'CRON_TOKEN' => '([0-9a-f]*)'", open(APP + '/config.php').read()).group(1)
anon.get('/cron.php?token=' + tok); cr2 = last()
rec('L34', 'H', 'Vorlage angelegt; Cronlauf (HTTP) protokolliert mit Akteur „cron“', cr[1] == 'recurring.create' and cr2[1] == 'recurring.run' and cr2[0] == 'cron' and cr2[2] == 'invoice', (cr, cr2))
b0 = n(); errs = []
def worker(k):
    try:
        c = authed('kai2', pw)
        for j in range(3): c.post('/index.php?p=customer_edit', {'csrf': page_token(c, '/index.php?p=customer_edit'), 'name': f'Par{k}-{j}', 'street': 's', 'zip': '1', 'city': 'c', 'country': 'DE'})
    except Exception as ex: errs.append(str(ex))
th = [threading.Thread(target=worker, args=(k,)) for k in range(6)]
[t.start() for t in th]; [t.join() for t in th]
ok = verify()
rec('L35', 'H', 'Parallele Schreiber: 18 Einträge, Kette danach lückenlos', n() - b0 >= 18 and 'lückenlos' in ok and not errs, (n() - b0, ok, errs[:1]))
pe = open('/home/claude/work/sec/apache/php_error.log').read()
rec('L36', 'M', 'Keine PHP-Fehler im Log (außer dem erwarteten Ausfall-Test)', all(('audit' in l.lower() or not l.strip()) for l in pe.split('\n')), pe[:300])
save_results(label)
