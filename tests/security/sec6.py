"""Tests für wiederkehrende Rechnungen und cron.php (ab 1.5.0). Frische Instanz, SMTP-Sink (RG_MAILS) laufend."""
import sys, os, re, glob, subprocess, threading, datetime
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
MAILS = os.environ.get('RG_MAILS', '/tmp/mails')
clear_rl()
s = authed(); anon = C()
today = datetime.date.today()
def iso(d): return d.isoformat()
def mails(): return sorted(glob.glob(MAILS + '/*.eml'))
def cfgtoken(): return re.search(r"'CRON_TOKEN' => '([0-9a-f]*)'", open(APP + '/config.php').read()).group(1)
def cli():
    p = subprocess.run(['runuser', '-u', 'www-data', '--', 'php', APP + '/cron.php'], capture_output=True, text=True, timeout=120)
    return p.returncode, p.stdout + p.stderr
def n_inv(): return int(sql('select count(*) from invoices'))
def rows(q): return [l.split('\t') for l in sql(q).split('\n') if l]

# Kunden
def cust(name, email):
    s.post('/index.php?p=customer_edit', {'csrf': page_token(s, '/index.php?p=customer_edit'), 'name': name, 'email': email, 'street': 's 1', 'zip': '12345', 'city': 'c', 'country': 'DE'})
    return sql('select max(id) from customers')
c_mail = cust('Mail GmbH', 'kunde@example.com'); c_nomail = cust('Ohne Mail AG', '')

def rpost(rid=None, csrf=True, client=None, **kw):
    c = client or s
    path = '/index.php?p=recurring_edit' + (f'&id={rid}' if rid else '')
    d = {'name': 'Hosting', 'customer_id': c_mail, 'frequency': 'month', 'period': 'previous', 'mode': 'draft',
         'next_run': iso(today + datetime.timedelta(days=1)), 'end_date': '', 'notes': '', 'mail_message': '', 'active': '1',
         'desc[]': ['Hosting {ZEITRAUM}'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': ['25,00'], 'vat[]': ['19']}
    d.update(kw)
    if csrf: d['csrf'] = page_token(c, path)
    return c.post(path, d)
def nrec(): return int(sql('select count(*) from recurring'))
def last_id(): return sql('select max(id) from recurring')

# --- Zugriff / CSRF
pg = [anon.get('/index.php?p=recurring'), anon.get('/index.php?p=recurring_edit')]
rec('R1', 'H', 'Seiten für wiederkehrende Rechnungen ohne Login nicht erreichbar', all('name="pass"' in p.body and 'Neue Vorlage' not in p.body and 'Cronjob' not in p.body for p in pg))
n0 = nrec(); anon.post('/index.php?p=recurring_edit', {'name': 'x'})
r = rpost(csrf=False)
rec('R2', 'H', 'Anlegen anonym bzw. ohne CSRF-Token wirkungslos (400)', nrec() == n0 and r.status == 400, r.status)
# --- Anlegen + Validierung
r = rpost(name='Hosting <b>Muster</b>')
rid = last_id()
rec('R3', 'M', 'Vorlage anlegen funktioniert (inkl. Komma-Preis)', nrec() == 1 and sql(f'select unit_price from recurring_items where recurring_id={rid}') == '25.00' and r.status in (302, 303), (r.status, sql('select count(*) from recurring')))
tomorrow = iso(today + datetime.timedelta(days=1))
bad = {
 'unbekannter Platzhalter': dict(**{'desc[]': ['Hosting {QUARTALL}']}),
 'Platzhalter in Notiz': dict(notes='x {NOPE}'),
 'Mail-Platzhalter in Position': {'desc[]': ['Rechnung {NUMMER}']},
 'Datum Vergangenheit': dict(next_run=iso(today - datetime.timedelta(days=1))),
 'Datum ungültig': dict(next_run='2026-02-31'), 'Datum Text': dict(next_run='morgen'),
 'Ende vor Start': dict(end_date=iso(today - datetime.timedelta(days=1)) if False else '2000-01-01'),
 'Intervall': dict(frequency='weekly'), 'Zeitraum': dict(period='x'), 'Modus': dict(mode='root'),
 'Kunde fehlt': dict(customer_id='99999'), 'Name leer': dict(name=' '), 'Name zu lang': dict(name='x' * 121),
 'keine Position': {'desc[]': ['']}, 'Preis 1e9': {'price[]': ['1e9']}, 'MwSt 50': {'vat[]': ['50']},
 'Send ohne Mail': dict(customer_id=c_nomail, mode='send'),
 'Notiz zu lang': dict(notes='x' * 5001),
 '51 Positionen': {'desc[]': ['p'] * 51, 'qty[]': ['1'] * 51, 'unit[]': ['LS'] * 51, 'price[]': ['1'] * 51, 'vat[]': ['19'] * 51},
}
fails = []
for k, kw in bad.items():
    n0 = nrec(); rpost(**kw)
    if nrec() != n0: fails.append(k)
rec('R4', 'H', 'Ungültige Eingaben (Platzhalter, Datum, Enums, Kunde, Preise, Länge, Versand ohne Mail) werden abgelehnt', not fails, fails)
n0 = nrec(); s.post('/index.php?p=recurring_edit', {'csrf': page_token(s, '/index.php?p=recurring_edit'), 'name[]': ['a'], 'customer_id[]': [c_mail], 'frequency[]': ['month'], 'next_run[]': [tomorrow]})
rec('R5', 'M', 'Array-Parameter statt Strings führen zu keinem Eintrag / keinem 500er', nrec() == n0)
# --- Injection / XSS
n0 = nrec(); rpost(name="x'); DROP TABLE recurring;-- ", **{'desc[]': ['<script>alert(1)</script>{JAHR}']}, notes='"><img src=x onerror=alert(1)>')
xid = last_id()
pages = [s.get('/index.php?p=recurring').body, s.get(f'/index.php?p=recurring_edit&id={xid}').body]
rec('R6', 'H', 'SQL-Injection/Stored-XSS in Name, Position, Notiz: Tabelle intakt, Ausgabe maskiert', nrec() == n0 + 1 and all('<script>alert(1)' not in p and '<img src=x' not in p for p in pages) and '&lt;script&gt;' in pages[1])
s.post(f'/index.php?p=recurring_edit&id={xid}', {'csrf': page_token(s, f'/index.php?p=recurring_edit&id={xid}'), 'action': 'delete'})
# --- Bearbeiten, CSRF beim Ändern/Löschen, GET ohne Wirkung
r = s.post(f'/index.php?p=recurring_edit&id={rid}', {'name': 'gehackt', 'csrf': 'a' * 32}); r2 = s.post(f'/index.php?p=recurring_edit&id={rid}', {'action': 'delete'})
s.get(f'/index.php?p=recurring_edit&id={rid}&action=delete')
rec('R7', 'H', 'Ändern/Löschen ohne gültiges Token → 400; GET löscht nichts', r.status == 400 and r2.status == 400 and sql(f'select count(*) from recurring where id={rid}') == '1' and 'gehackt' not in sql(f'select name from recurring where id={rid}'))
rpost(rid, name='Hosting', period='previous', next_run=tomorrow)
# --- cron.php: HTTP-Schutz
tok = cfgtoken()
r0 = anon.get('/cron.php'); r1 = anon.get('/cron.php?token=falsch'); r2 = anon.get('/cron.php?token[]=x'); r3 = anon.get('/cron.php?token=')
rec('R8', 'H', 'cron.php per HTTP ohne/mit falschem/leerem/Array-Token → 403, nichts erzeugt', all(x.status == 403 for x in (r0, r1, r2, r3)) and n_inv() == 0, [x.status for x in (r0, r1, r2, r3)])
rec('R9', 'M', 'Token ist zufällig (40 Hex-Zeichen) und steht nur in der geschützten Konfiguration', len(tok) == 40 and anon.get('/config.php').status in (403, 404) and tok not in anon.get('/').body)
codes = [anon.get('/cron.php?token=' + 'b' * 40).status for _ in range(12)]
rec('R10', 'H', 'Token-Raten wird nach 10 Fehlversuchen mit 429 gebremst – auch mit richtigem Token', 429 in codes and anon.get('/cron.php?token=' + tok).status == 429, codes)
clear_rl()
# --- nichts fällig
r = anon.get('/cron.php?token=' + tok)
rec('R11', 'M', 'Mit richtigem Token: „nichts fällig“ (text/plain), noch keine Rechnung', r.status == 200 and 'nichts fällig' in r.body and r.h('content-type').startswith('text/plain') and n_inv() == 0, (r.status, r.body[:80]))
rec('R12', 'M', 'Fällige Vorlage wird noch nicht erzeugt, wenn Termin in der Zukunft liegt (CLI)', cli()[0] == 0 and n_inv() == 0)
# --- Erzeugen: Platzhalter, Entwurf
tdy = iso(today)
sql(f"update recurring set next_run='{tdy}', anchor_day={today.day} where id={rid}")
sql(f"update recurring_items set description='Hosting {{ZEITRAUM}} ({{MONAT}} {{JAHR}}, Q{{QUARTAL}}, {{ZEITRAUM_VON}}-{{ZEITRAUM_BIS}}) {{foo}}' where recurring_id={rid}")
sql(f"update recurring set notes='Leistungszeitraum {{ZEITRAUM_VON}} bis {{ZEITRAUM_BIS}}' where id={rid}")
rc, out = cli()
prev = (today.replace(day=1) - datetime.timedelta(days=1))
expect_z = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'][prev.month-1] + f' {prev.year}'
d1 = sql('select description from invoice_items where invoice_id=(select max(id) from invoices)')
rec('R13', 'H', 'CLI erzeugt genau einen Entwurf; {ZEITRAUM}, {MONAT}, {JAHR}, {QUARTAL}, von/bis korrekt (Vormonat); unbekanntes {foo} bleibt', rc == 0 and n_inv() == 1 and d1.startswith(f'Hosting {expect_z} ({expect_z.split()[0]} {prev.year}, Q{(prev.month-1)//3+1}, 01.{prev.month:02d}.{prev.year}-') and d1.endswith('{foo}') and sql('select status from invoices') == 'draft', (rc, out[:200], d1))
rec('R14', 'M', 'Notiz-Platzhalter ersetzt; next_run +1 Monat, run_count=1, last_invoice_id gesetzt', 'Leistungszeitraum 01.' in sql('select notes from invoices') and sql(f'select run_count from recurring where id={rid}') == '1' and sql(f'select next_run>CURDATE() from recurring where id={rid}') == '1' and sql(f'select last_invoice_id from recurring where id={rid}') == sql('select max(id) from invoices'))
# --- Idempotenz / Parallelität
rc2, out2 = cli(); rc3, _ = cli()
rec('R15', 'H', 'Erneuter Aufruf erzeugt keine Doppelrechnung für denselben Zeitraum', n_inv() == 1 and 'nichts fällig' in out2, out2[:100])
sql(f"update recurring set next_run='{tdy}' where id={rid}")
res = []
def hit(): res.append(C().get('/cron.php?token=' + tok).status)
th = [threading.Thread(target=hit) for _ in range(6)] + [threading.Thread(target=cli) for _ in range(3)]
[t.start() for t in th]; [t.join() for t in th]
rec('R16', 'H', '9 gleichzeitige Cron-Aufrufe (HTTP + CLI) erzeugen für einen fälligen Zeitraum genau eine Rechnung', n_inv() == 2 and sql(f'select run_count from recurring where id={rid}') == '2', (n_inv(), res))
# --- Nachholen
sql('delete from invoices')
back = (today.replace(day=1) - datetime.timedelta(days=1)).replace(day=1)   # Monatsanfang des Vormonats
for _ in range(3): back = (back - datetime.timedelta(days=1)).replace(day=1)
sql(f"update recurring set next_run='{iso(back)}', anchor_day=1, run_count=0 where id={rid}")
rc, out = cli()
cnt = n_inv()
nxt = sql(f'select next_run from recurring where id={rid}')
rec('R17', 'M', 'Versäumte Läufe werden nachgeholt (je Monat eine Rechnung), danach liegt next_run in der Zukunft', cnt >= 4 and cnt <= 12 and nxt > iso(today), (cnt, nxt))
zs = sql("select description from invoice_items order by invoice_id").split('\n')
rec('R18', 'M', 'Nachgeholte Rechnungen tragen jeweils den Zeitraum ihres Termins (verschiedene Monate, lückenlos)', len(set(zs)) == len(zs) == cnt, zs[:3])
# --- Pausiert / Ende
sql('delete from invoices')
sql(f"update recurring set active=0, next_run='{tdy}' where id={rid}"); cli()
rec('R19', 'M', 'Pausierte Vorlage wird nicht ausgelöst', n_inv() == 0)
sql(f"update recurring set active=1, next_run='{tdy}', anchor_day={today.day}, end_date='{tdy}' where id={rid}"); cli()
rec('R20', 'M', 'Mit Enddatum = heute: letzte Rechnung wird erzeugt, danach ist die Vorlage beendet (inaktiv)', n_inv() == 1 and sql(f'select active from recurring where id={rid}') == '0')
# --- Ausstellen / Versenden
sql('delete from invoices'); sql('delete from recurring')
rpost(name='Auto-Ausstellen', mode='issue'); r_issue = last_id()
sql(f"update recurring set next_run='{tdy}', anchor_day={today.day} where id={r_issue}"); cli()
row = rows("select status, number, public_token is not null from invoices")
rec('R21', 'H', 'Modus „Ausstellen“: Rechnung hat Nummer, Token und PDF in storage/', len(row) == 1 and row[0][0] == 'issued' and row[0][1].startswith('RE-') and row[0][2] == '1' and os.path.exists(f'{APP}/storage/invoices/{row[0][1]}.pdf') or glob.glob(f'{APP}/storage/**/{row[0][1] if row else "x"}*.pdf', recursive=True), row)
sql('delete from invoices'); sql('delete from recurring')
for f in mails(): os.remove(f)
rpost(name='Auto-Senden', mode='send', mail_message='Hallo, {NUMMER} über {BETRAG} für {ZEITRAUM}, fällig {FAELLIG}. {FIRMA}'); r_send = last_id()
sql(f"update recurring set next_run='{tdy}', anchor_day={today.day} where id={r_send}"); cli()
ms = mails(); body = open(ms[0], encoding='utf-8', errors='replace').read() if ms else ''
row = rows("select number, sent_at is not null from invoices")
rec('R22', 'H', 'Modus „Senden“: Rechnung ausgestellt, Mail mit PDF-Anhang an Kunden, Platzhalter im Mailtext ersetzt, sent_at gesetzt', len(ms) == 1 and 'kunde@example.com' in body and 'application/pdf' in body and row and row[0][1] == '1' and '{NUMMER}' not in body and '{BETRAG}' not in body and row[0][0] in body.replace('=\n', ''), (len(ms), row))
# Kunde ohne Mail nachträglich → Versandfehler wird gemeldet, Rechnung bleibt, nächster Lauf geht weiter
sql('delete from invoices')
for f in mails(): os.remove(f)
sql(f"update customers set email='' where id={c_mail}")
sql(f"update recurring set next_run='{tdy}', anchor_day={today.day}, active=1 where id={r_send}")
rc, out = cli()
rec('R23', 'H', 'Versand ohne gültige Kunden-Mail: Fehler in last_error + Exit-Code 2, Rechnung bleibt ausgestellt, keine Mail, Termin rückt vor', rc == 2 and sql(f'select last_error from recurring where id={r_send}') != 'NULL' and 'E-Mail' in sql(f'select last_error from recurring where id={r_send}') and not mails() and sql('select status from invoices') == 'issued' and sql(f'select next_run>CURDATE() from recurring where id={r_send}') == '1', (rc, out[:150]))
sql(f"update customers set email='kunde@example.com' where id={c_mail}")
# --- Manuell auslösen aus der Oberfläche
sql('delete from invoices'); sql(f"update recurring set next_run='{tdy}', last_error=NULL where id={r_send}"); sql(f"update recurring set mode='draft' where id={r_send}")
r = s.post('/index.php?p=recurring', {'action': 'run_due'}); r_nt = s.post('/index.php?p=recurring', {'action': 'run_due', 'csrf': page_token(s, '/index.php?p=recurring')})
rec('R24', 'M', '„Fällige jetzt erzeugen“: ohne CSRF-Token 400, mit Token wird erzeugt', r.status == 400 and n_inv() == 1 and r_nt.status in (302, 303), (r.status, n_inv()))
# --- Token verwalten
old = cfgtoken()
s.post('/index.php?p=recurring', {'action': 'cron_token'})
rec('R25', 'H', 'Token erneuern ohne CSRF-Token wirkungslos', cfgtoken() == old)
s.post('/index.php?p=recurring', {'action': 'cron_token', 'csrf': page_token(s, '/index.php?p=recurring')})
new = cfgtoken(); clear_rl()
rec('R26', 'H', 'Neues Token gilt, altes Token ist ungültig', new != old and len(new) == 40 and C().get('/cron.php?token=' + old).status == 403 and C().get('/cron.php?token=' + new).status == 200)
s.post('/index.php?p=recurring', {'action': 'cron_token_off', 'csrf': page_token(s, '/index.php?p=recurring')}); clear_rl()
rec('R27', 'H', 'URL-Aufruf deaktiviert: weder leeres noch altes Token funktioniert, CLI läuft weiter', cfgtoken() == '' and C().get('/cron.php?token=').status == 403 and C().get('/cron.php').status == 403 and C().get('/cron.php?token=' + new).status == 403 and cli()[0] == 0)
s.post('/index.php?p=recurring', {'action': 'cron_token', 'csrf': page_token(s, '/index.php?p=recurring')})
# --- Löschen lässt Rechnungen stehen
n0 = n_inv(); s.post(f'/index.php?p=recurring_edit&id={r_send}', {'csrf': page_token(s, f'/index.php?p=recurring_edit&id={r_send}'), 'action': 'delete'})
rec('R28', 'M', 'Vorlage löschen entfernt Positionen mit, erzeugte Rechnungen bleiben', sql(f'select count(*) from recurring where id={r_send}') == '0' and sql(f'select count(*) from recurring_items where recurring_id={r_send}') == '0' and n_inv() == n0 > 0)
# --- Seiten rendern, kein Fehlerlog
form = s.get('/index.php?p=recurring_edit').body
lst = s.get('/index.php?p=recurring').body
rec('R29', 'L', 'Formular und Liste rendern (Platzhalterhilfe, Cron-Anleitung mit Token), Seitentitel gesetzt', '{ZEITRAUM}' in form and 'cron.php?token=' in lst and '<title>Wiederkehrende Rechnungen' in lst)
n0 = nrec(); rpost(**{'note[]': ['Hinweis {QUARTALL}']})
rec('R32', 'H', 'Unbekannter Platzhalter in der Positionsnotiz wird abgelehnt', nrec() == n0)
sql('delete from invoices')
rpost(name='Mit Notiz', **{'note[]': ['Leistung im {ZEITRAUM} ({ZEITRAUM_VON} – {ZEITRAUM_BIS}) <b>x</b>']}); rn = last_id()
sql(f"update recurring set next_run='{tdy}', anchor_day={today.day} where id={rn}"); cli()
nt = sql('select note from invoice_items where invoice_id=(select max(id) from invoices)')
rec('R33', 'H', 'Platzhalter in der Positionsnotiz werden beim Erzeugen ersetzt (Text bleibt unmaskiert gespeichert, Ausgabe maskiert)', re.match(r'Leistung im \w+ \d{4} \(01\.\d\d\.\d{4} – \d\d\.\d\d\.\d{4}\) <b>x</b>$', nt) is not None and '<b>x</b>' not in s.get('/index.php?p=invoice&id=' + sql('select max(id) from invoices')).body, nt)
pvn = s.get(f'/index.php?p=recurring_edit&id={rn}').body
rec('R34', 'L', 'Vorschau im Formular zeigt die aufgelöste Notiz', 'Leistung im ' in pvn.split('Vorschau der n')[-1] and '{ZEITRAUM}' not in pvn.split('Vorschau der n')[-1])
sql(f'delete from recurring where id={rn}')
rpost(name='Vorschau', frequency='quarter'); pid = last_id()
pv = s.get(f'/index.php?p=recurring_edit&id={pid}').body
rec('R31', 'L', 'Vorschau im Formular zeigt Termin und aufgelösten Zeitraum', re.search(r'Erstellung am \d\d\.\d\d\.\d{4}, Zeitraum \d\. Quartal \d{4}', pv) is not None and 'Hosting {ZEITRAUM}' not in pv.split('Vorschau der n')[-1])
errs = open(APP + '/../apache/php_error.log').read()
rec('R30', 'M', 'Keine PHP-Fehler/Warnungen im Log', not re.search(r'Fatal|Uncaught|TypeError|Warning|Deprecated', errs), re.findall(r'.*(?:Fatal|Warning|Deprecated).*', errs)[:2])
save_results(label + '_part6')
