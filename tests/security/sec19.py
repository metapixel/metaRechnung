"""Tests: frei gestaltbare Nummernkreise mit Variablen, Startnummer für den Umstieg (ab 1.17.0). Frische Instanz."""
import sys, os, re, datetime, html as _h
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed()
today = datetime.date.today(); iso = today.isoformat(); Y = today.strftime('%Y'); YY = today.strftime('%y'); MM = today.strftime('%m')
def cfg(): return open(APP + '/config.php').read()
def save(**kw):
    pg = s.get('/index.php?p=settings'); d = {'csrf': csrf_of(pg.body), 'action': 'save'}
    for m in re.finditer(r'<input[^>]*name="([A-Z_]+)"[^>]*value="([^"]*)"', pg.body): d[m.group(1)] = _h.unescape(m.group(2))
    d['SMTP_SECURE'] = 'none'; d.update(kw); return s.post('/index.php?p=settings', d)
def cust(name):
    d = {'name': name, 'street': 's 1', 'zip': '12345', 'city': 'c', 'country': 'DE', 'email': 'k@example.com', 'csrf': page_token(s, '/index.php?p=customer_edit')}
    s.post('/index.php?p=customer_edit', d); return sql('select max(id) from customers')
cid = cust('Nummern Kunde')
def inv(date=iso, price='100,00'):
    p = '/index.php?p=invoice_edit'
    s.post(p, {'csrf': page_token(s, p), 'customer_id': cid, 'issue_date': date, 'due_date': '', 'delivery_date': '', 'notes': '', 'desc[]': ['L'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': [price], 'vat[]': ['19']})
    i = int(sql('select max(id) from invoices')); pp = f'/index.php?p=invoice&id={i}'
    s.post(pp, {'csrf': page_token(s, pp), 'action': 'issue'}); return i
def num(i): return sql(f'select ifnull(number,"") from invoices where id={i}')
def preview(label_):
    b = s.get('/index.php?p=settings').body
    m = re.search(r'<th scope="row">' + label_ + r'</th>.*?<td[^>]*><b>([^<]*)</b></td>', b, re.S); return m.group(1) if m else None
def val(name):
    m = re.search(r'name="%s"[^>]*value="([^"]*)"' % name, s.get('/index.php?p=settings').body); return _h.unescape(m.group(1)) if m else None

pg = s.get('/index.php?p=settings').body
rec('N1', 'M', 'Einstellungen: Abschnitt Nummernkreise mit Standardformaten und Vorschau', 'id="nummernkreise"' in pg and val('INVOICE_FORMAT') == 'RE-{JAHR}-{NR}' and val('CANCEL_FORMAT') == 'ST-{JAHR}-{NR}' and val('OFFER_FORMAT') == 'AN-{JAHR}-{NR}' and preview('Rechnungen') == f'RE-{Y}-0001', (val('INVOICE_FORMAT'), preview('Rechnungen')))
i1 = inv()
rec('N2', 'H', 'Standardformat unverändert: erste Rechnung RE-JJJJ-0001', num(i1) == f'RE-{Y}-0001', num(i1))
rec('N3', 'M', 'Vorschau zeigt danach die nächste Nummer', preview('Rechnungen') == f'RE-{Y}-0002', preview('Rechnungen'))
# Umstieg
save(INVOICE_NEXT='341')
rec('N4', 'H', 'Startnummer 341 festgelegt: Vorschau RE-JJJJ-0341', preview('Rechnungen') == f'RE-{Y}-0341', preview('Rechnungen'))
i2 = inv()
rec('N5', 'H', 'Nächste Rechnung bekommt 341', num(i2) == f'RE-{Y}-0341', num(i2))
rec('N6', 'H', 'Startnummer ist verbraucht (Feld leer), danach 342', val('INVOICE_NEXT') == '' and num(inv()) == f'RE-{Y}-0342', val('INVOICE_NEXT'))
save(INVOICE_NEXT='5')
rec('N7', 'H', 'Zu niedrige Startnummer (5) senkt den Zähler nie unter vergebene Nummern', preview('Rechnungen') == f'RE-{Y}-0343' and num(inv()) == f'RE-{Y}-0343', preview('Rechnungen'))
rec('N8', 'M', 'Verbrauchte Startnummer auch hier geleert', val('INVOICE_NEXT') == '', val('INVOICE_NEXT'))
# anderes Format, anderes Jahr
i_old = inv('2025-06-15')
rec('N9', 'H', 'Rechnung mit Datum im Vorjahr zählt im Vorjahres-Kreis (RE-2025-0001), kein Einfluss auf das laufende Jahr', num(i_old) == 'RE-2025-0001' and preview('Rechnungen') == f'RE-{Y}-0344', (num(i_old), preview('Rechnungen')))
save(INVOICE_FORMAT='RE{JJ}{MONAT}-{NR}', INVOICE_PAD='3')
rec('N10', 'H', 'Eigenes Format mit Jahr/Monat und 3 Stellen: Vorschau RE<JJ><MM>-001', preview('Rechnungen') == f'RE{YY}{MM}-001', preview('Rechnungen'))
i3 = inv()
rec('N11', 'H', 'Ausgestellt als RE<JJ><MM>-001; frühere Belege behalten ihre Nummer', num(i3) == f'RE{YY}{MM}-001' and num(i1) == f'RE-{Y}-0001' and num(i2) == f'RE-{Y}-0341', num(i3))
save(INVOICE_FORMAT='{NR}', INVOICE_PAD='6', INVOICE_NEXT='1000')
i4 = inv()
rec('N12', 'H', 'Format {NR} ohne Datum, Start 1000, 6 Stellen: 001000', num(i4) == '001000', num(i4))
rec('N13', 'M', 'Zählung läuft ohne Datumsvariable durch (001001)', num(inv()) == '001001', '')
save(INVOICE_FORMAT='Rechnung_{jahr}.{nr}', INVOICE_PAD='2', INVOICE_NEXT='')
rec('N14', 'M', 'Variablen sind unabhängig von Groß-/Kleinschreibung', num(inv()) == f'Rechnung_{Y}.01', '')
# Storno und Angebot unabhängig
save(INVOICE_FORMAT='RE-{JAHR}-{NR}', INVOICE_PAD='4', CANCEL_FORMAT='GS{JJ}-{NR}', CANCEL_PAD='3', OFFER_FORMAT='ANG-{JAHR}-{NR}', OFFER_NEXT='77')
pc = f'/index.php?p=invoice&id={i1}'
s.post(pc, {'csrf': page_token(s, pc), 'action': 'cancel'})
sid = int(sql(f'select id from invoices where cancels_id={i1}'))
rec('N15', 'M', 'Formate je Belegart sind unabhängig (Vorschau Storno GS<JJ>-001, Angebot ANG-JJJJ-0077)', preview('Stornorechnungen') == f'GS{YY}-001' and preview('Angebote') == f'ANG-{Y}-0077', (preview('Stornorechnungen'), preview('Angebote')))
po = '/index.php?p=offer_edit'
s.post(po, {'csrf': page_token(s, po), 'customer_id': cid, 'issue_date': iso, 'valid_until': '', 'notes': '', 'desc[]': ['B'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': ['10,00'], 'vat[]': ['19']})
oid = int(sql('select max(id) from offers')); op = f'/index.php?p=offer&id={oid}'; s.post(op, {'csrf': page_token(s, op), 'action': 'issue'})
rec('N16', 'H', 'Angebot nutzt eigenes Format und eigene Startnummer: ANG-JJJJ-0077', sql(f'select number from offers where id={oid}') == f'ANG-{Y}-0077' and val('OFFER_NEXT') == '', sql(f'select number from offers where id={oid}'))
# Ablehnungen
before = cfg()
bad = {
    'ohne {NR}': dict(INVOICE_FORMAT='RE-{JAHR}'), 'zweimal {NR}': dict(INVOICE_FORMAT='{NR}-{NR}'), 'unbekannte Variable': dict(INVOICE_FORMAT='RE-{FIRMA}-{NR}'),
    'Sonderzeichen': dict(INVOICE_FORMAT='RE/{NR}'), 'Leerzeichen': dict(INVOICE_FORMAT='RE {NR}'), 'Skript': dict(INVOICE_FORMAT='<b>{NR}'), 'Anführung': dict(INVOICE_FORMAT='R"E-{NR}'),
    'zu lang': dict(INVOICE_FORMAT='A' * 30 + '-{NR}', INVOICE_PAD='6'), 'NR neben Jahr': dict(INVOICE_FORMAT='RE-{JAHR}{NR}'), 'NR neben Ziffer': dict(INVOICE_FORMAT='RE-2026{NR}'),
    'Stellen 0': dict(INVOICE_PAD='0'), 'Stellen 11': dict(INVOICE_PAD='11'), 'Stellen Text': dict(INVOICE_PAD='x'),
    'Start 0': dict(INVOICE_NEXT='0'), 'Start Text': dict(INVOICE_NEXT='abc'), 'Start negativ': dict(INVOICE_NEXT='-5'), 'Start riesig': dict(INVOICE_NEXT='1' + '0' * 12),
    'Format Backslash': dict(CANCEL_FORMAT='ST\\{NR}'), 'Angebot ungültig': dict(OFFER_FORMAT='AN-{NR}-{x}'),
}
for k, kw in bad.items():
    cur = cfg(); r = save(**kw)
    rec('N17', 'H', f'Ungültige Eingabe wird abgelehnt, Konfiguration unverändert: {k}', cfg() == cur and r.status == 200, r.status)
rec('N18', 'M', 'Fehlermeldung nennt Grund (Variable/Zeichen)', 'unbekannte Variable' in save(INVOICE_FORMAT='RE-{FIRMA}-{NR}').body and 'genau einmal' in save(INVOICE_FORMAT='RE-{JAHR}').body, '')
# gültige Randfälle
for f_ in ['{NR}', 'RE.{NR}', 'A_{JJ}-{NR}', '{JAHR}-{MONAT}-{TAG}-{NR}']:
    cur = cfg(); save(INVOICE_FORMAT=f_, INVOICE_PAD='4')
    rec('N19', 'M', f'Gültiges Format wird übernommen: {f_}', val('INVOICE_FORMAT') == f_, val('INVOICE_FORMAT'))
save(INVOICE_FORMAT='RE-{JAHR}-{NR}', INVOICE_PAD='4')
# Eindeutigkeit / Reihenfolge
a, b2 = inv(), inv()
na, nb = num(a), num(b2)
rec('N20', 'H', 'Aufeinanderfolgende Rechnungen: lückenlos aufsteigend und eindeutig', int(nb.split('-')[-1]) == int(na.split('-')[-1]) + 1 and sql('select count(*)-count(distinct number) from invoices where number is not null') == '0', (na, nb))
# PDF-Dateiname
rec('N21', 'M', 'Rechnungs-PDF zur Nummer wird ausgeliefert', s.get(f'/index.php?p=pdf&id={a}').raw[:5] == b'%PDF-', '')
rec('N22', 'M', 'Protokoll: Änderung der Nummernkreise steht im Änderungsprotokoll', 'INVOICE_FORMAT' in sql("select group_concat(summary) from audit_log where action='settings.save'"), '')
# Zahlenformat im Mailtext-Platzhalter bleibt
save(INVOICE_FORMAT='X{NR}-{JJ}'); ic = inv()
rec('N23', 'M', 'Weiteres gültiges Format (X{NR}-{JJ}) wird beim Ausstellen verwendet', re.fullmatch(r'X\d{4}-' + YY, num(ic)) is not None, num(ic))
save_results(label)
