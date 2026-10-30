"""Tests: Angebote, Textvorlagen mit Platzhaltern, Zahlungserinnerung (ab 1.14.0). Frische Instanz, SMTP-Sink (RG_MAILS) laufend."""
import sys, os, re, glob, datetime, subprocess, email, email.policy
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
MAILS = os.environ.get('RG_MAILS', '/tmp/mails')
clear_rl()
s = authed(); anon = C()
today = datetime.date.today(); iso = today.isoformat(); yd = (today - datetime.timedelta(days=1)).isoformat()
y = today.year
def mails(): return sorted(glob.glob(MAILS + '/*.eml'), key=lambda f: (os.path.getmtime(f), f))
def last_mail():
    m = email.message_from_bytes(open(mails()[-1], 'rb').read(), policy=email.policy.default)
    body = m.get_body(preferencelist=('plain',)).get_content()
    atts = [(p.get_filename(), p.get_content_type(), p.get_payload(decode=True)) for p in m.iter_attachments()]
    return m, body, atts
def cust(name, **kw):
    d = {'name': name, 'street': 's 1', 'zip': '12345', 'city': 'c', 'country': 'DE', 'email': 'k@example.com', 'contact': 'Erika Muster'}; d.update(kw)
    d['csrf'] = page_token(s, '/index.php?p=customer_edit'); s.post('/index.php?p=customer_edit', d); return sql('select max(id) from customers')
def offer(cid, price='100,00', desc='Beratung', **kw):
    p = '/index.php?p=offer_edit'
    d = {'csrf': page_token(s, p), 'customer_id': cid, 'issue_date': iso, 'valid_until': '', 'notes': 'Hinweis A',
         'desc[]': [desc, 'Zweite Position'], 'qty[]': ['2', '1'], 'unit[]': ['HUR', 'LS'], 'price[]': [price, '50,00'], 'vat[]': ['19', '7']}
    d.update(kw)
    r = s.post(p, d); return int(sql('select max(id) from offers')), r
def act(oid, a, **kw):
    pp = f'/index.php?p=offer&id={oid}'
    return s.post(pp, dict({'csrf': page_token(s, pp), 'action': a}, **kw))
def iact(iid, a, **kw):
    pp = f'/index.php?p=invoice&id={iid}'
    return s.post(pp, dict({'csrf': page_token(s, pp), 'action': a}, **kw))
def pdftext(path):
    return subprocess.check_output(['pdftotext', '-layout', path, '-']).decode('utf-8', 'replace')

# --- Zugriff
for p in ('offers', 'offer_edit', 'offer&id=1', 'offer_pdf&id=1', 'templates'):
    r = anon.get('/index.php?p=' + p)
    if not ('name="pass"' in r.body and r.status == 200):
        rec('A1', 'H', 'Anonym: Angebote/Vorlagen nicht erreichbar', False, (p, r.status)); break
else:
    rec('A1', 'H', 'Anonym: Angebote, Angebots-PDF und Textvorlagen nur nach Login', True)
r = s.get('/index.php?p=offers')
rec('A2', 'M', 'Angebotsliste leer: Hinweis, Neu-Button, Menüpunkt „Angebote“', r.status == 200 and 'Noch keine Angebote' in r.body and 'p=offer_edit' in r.body and '>Angebote<' in r.body.replace('</svg>', ''), r.status)
rec('A3', 'M', 'Einstellungen verlinken die Textvorlagen und kennen Angebotspräfix/-gültigkeit', 'p=templates' in s.get('/index.php?p=settings').body and 'name="OFFER_PREFIX"' in s.get('/index.php?p=settings').body)

# --- Entwurf
c1 = cust('Angebots-Kunde GmbH'); 
o1, r = offer(c1)
st = sql(f'select status, number is null, datediff(valid_until, issue_date) from offers where id={o1}')
rec('O1', 'H', 'Angebotsentwurf angelegt: Status draft, keine Nummer, Gültigkeit 30 Tage', st == 'draft\t1\t30' and sql(f'select count(*) from offer_items where offer_id={o1}') == '2', st)
pg = s.get(f'/index.php?p=offer&id={o1}')
rec('O2', 'M', 'Detailseite: Positionen, Summen (netto 250,00 / USt 38,00+3,50 / brutto 291,50), Aktionen für Entwürfe', '250,00' in pg.body and '41,50' in pg.body and '291,50' in pg.body and 'Ausstellen' in pg.body and 'In Rechnung wandeln' not in pg.body, pg.status)
rec('O3', 'M', 'Angebotsentwurf erscheint in der Liste (Status „Entwurf“)', 'Entwurf' in s.get('/index.php?p=offers').body and 'Angebots-Kunde GmbH' in s.get('/index.php?p=offers').body)
n0 = sql('select count(*) from offers')
r = s.post('/index.php?p=offer_edit', {'csrf': page_token(s, '/index.php?p=offer_edit'), 'customer_id': c1, 'issue_date': iso, 'valid_until': yd, 'desc[]': ['x'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': ['1'], 'vat[]': ['19']})
rec('O4', 'M', '„Gültig bis“ vor dem Angebotsdatum wird abgelehnt (Meldung, nichts angelegt)', sql('select count(*) from offers') == n0 and 'liegt vor dem Angebotsdatum' in r.body + s.get('/index.php?p=offer_edit').body, r.status)
r = s.post('/index.php?p=offer_edit', {'csrf': page_token(s, '/index.php?p=offer_edit'), 'customer_id': '99999', 'issue_date': iso, 'valid_until': '', 'desc[]': ['x'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': ['1'], 'vat[]': ['19']})
rec('O5', 'M', 'Unbekannter Kunde wird abgelehnt', sql('select count(*) from offers') == n0, r.status)
r = s.post('/index.php?p=offer_edit', {'csrf': 'falsch', 'customer_id': c1, 'desc[]': ['x'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': ['1'], 'vat[]': ['19']})
rec('O6', 'H', 'Ohne gültiges CSRF-Token wird kein Angebot angelegt', sql('select count(*) from offers') == n0, r.status)
ov = anon.post('/index.php?p=offer&id=%s' % o1, {'action': 'issue'})
rec('O7', 'H', 'Anonym kann kein Angebot ausgestellt werden', sql(f'select status from offers where id={o1}') == 'draft', ov.status)
r = s.get(f'/index.php?p=offer_preview&id={o1}')
rec('O8', 'M', 'PDF-Vorschau des Entwurfs: PDF, nichts gespeichert, keine Nummer', r.status == 200 and r.raw[:5] == b'%PDF-' and r.h('content-type') == 'application/pdf' and sql(f'select number is null from offers where id={o1}') == '1', r.status)

# --- Ausstellen
act(o1, 'issue')
no1 = sql(f'select number from offers where id={o1}')
pdf1 = f'{APP}/storage/offers/{no1}.pdf'
rec('O9', 'H', f'Ausgestellt: Nummer AN-{y}-0001, Status open, Snapshot, PDF gespeichert', no1 == f'AN-{y}-0001' and sql(f'select status from offers where id={o1}') == 'open' and sql(f'select snapshot is not null from offers where id={o1}') == '1' and os.path.isfile(pdf1) and open(pdf1, 'rb').read(5) == b'%PDF-', no1)
tx = pdftext(pdf1)
rec('O10', 'H', 'Angebots-PDF: „Angebot“, Nummer, Gültigkeit, Einleitung und Schlusstext aus den Vorlagen, Positionen, Brutto – kein „Rechnung“', 'Angebot ' + no1 in tx and 'Gültig bis' in tx and 'Vielen Dank für Ihre Anfrage' in tx and 'Dieses Angebot ist gültig bis zum' in tx and 'Beratung' in tx and '291,50' in tx and 'Rechnungs-Nr' not in tx and 'Fällig' not in tx, tx[:300])
rec('O11', 'M', 'Angebots-PDF ist kein ZUGFeRD (keine eingebettete factur-x.xml)', b'factur-x' not in open(pdf1, 'rb').read(), '')
r = act(o1, 'issue'); rec('O12', 'M', 'Erneutes Ausstellen wirkungslos (Nummer bleibt)', sql(f'select number from offers where id={o1}') == no1, r.status)
r = s.get(f'/index.php?p=offer_edit&id={o1}')
rec('O13', 'H', 'Ausgestelltes Angebot ist nicht mehr bearbeitbar (Seite und POST)', r.status == 302 and 'Nur Entwürfe' in s.get('/index.php?p=offers').body + s.get(r.h('location') or '/').body, r.status)
s.post(f'/index.php?p=offer_edit&id={o1}', {'csrf': page_token(s, '/index.php?p=offer_edit'), 'customer_id': c1, 'issue_date': iso, 'valid_until': '', 'notes': 'GEÄNDERT', 'desc[]': ['geändert'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': ['1'], 'vat[]': ['19']})
rec('O14', 'H', 'POST auf ausgestelltes Angebot ändert nichts', sql(f'select count(*) from offer_items where offer_id={o1} and description=\'geändert\'') == '0' and 'GEÄNDERT' not in (sql(f'select notes from offers where id={o1}') or ''), '')
r = s.get(f'/index.php?p=offer_pdf&id={o1}')
rec('O15', 'M', 'offer_pdf liefert das gespeicherte PDF (inline, application/pdf)', r.status == 200 and r.raw == open(pdf1, 'rb').read() and 'inline' in r.h('content-disposition'), r.status)
o2, _ = offer(c1, price='10,00'); act(o2, 'issue'); no2 = sql(f'select number from offers where id={o2}')
rec('O16', 'M', 'Zweites Angebot bekommt fortlaufende Nummer 0002', no2 == f'AN-{y}-0002', no2)
r = act(999999, 'issue'); rec('O17', 'L', 'Unbekanntes Angebot: 404', s.get('/index.php?p=offer&id=999999').status == 404, '')

# --- Mail
m0 = len(mails())
pg = s.get(f'/index.php?p=offer&id={o1}')
mt = re.search(r'name="subject"[^>]*value="([^"]*)"', pg.body); mb = re.search(r'name="message"[^>]*>(.*?)</textarea>', pg.body, re.S)
import html as _h
subj_pre = _h.unescape(mt.group(1)) if mt else ''; body_pre = _h.unescape(mb.group(1)) if mb else ''
rec('M1', 'H', 'Mailformular vorbelegt aus Vorlage: Betreff, Anrede mit Ansprechpartner, Nummer, Betrag, Gültigkeit', subj_pre == f'Angebot {no1} von Test IT-Service' and 'Guten Tag Erika Muster' in body_pre and no1 in body_pre and '291,50' in body_pre and (today + datetime.timedelta(days=30)).strftime('%d.%m.%Y') in body_pre and '{' not in body_pre, (subj_pre, body_pre[:120]))
r = act(o1, 'send', to='k@example.com', subject=subj_pre, message=body_pre)
ok = len(mails()) == m0 + 1
if ok:
    m, body, atts = last_mail()
    rec('M2', 'H', 'Angebot per Mail: Betreff, Empfänger, Text, PDF-Anhang mit Nummer als Dateiname', m['Subject'] == subj_pre and 'k@example.com' in m['To'] and body.replace('\r\n', '\n').strip() == body_pre.replace('\r\n', '\n').strip() and len(atts) == 1 and atts[0][0] == no1 + '.pdf' and atts[0][2][:5] == b'%PDF-', (m['Subject'], [a[0] for a in atts]))
else:
    rec('M2', 'H', 'Angebot per Mail versendet', False, (len(mails()), m0))
rec('M3', 'M', 'sent_at gesetzt, Meldung in der Oberfläche', sql(f'select sent_at is not null from offers where id={o1}') == '1', '')
r = act(o1, 'send', to='kein-mail', subject='x', message='x')
rec('M4', 'M', 'Ungültige Empfängeradresse: nichts versendet', len(mails()) == m0 + 1, r.status)
act(o2, 'send', to='k@example.com', subject='Mein eigener Betreff\r\nBcc: evil@example.com', message='Text')
m, body, atts = last_mail()
rec('M5', 'H', 'Eigener Betreff wird übernommen, Zeilenumbruch im Betreff (Header-Injection) neutralisiert', len(mails()) == m0 + 2 and 'Mein eigener Betreff' in m['Subject'] and 'evil@example.com' not in str(m['Bcc'] or '') and 'evil@example.com' not in str(m['To']), m['Subject'])

# --- Status
act(o1, 'declined')
rec('S1', 'M', 'Als abgelehnt markiert (Datum gesetzt), Umwandeln nicht angeboten', sql(f'select status, decided_at is not null from offers where id={o1}') == 'declined\t1' and 'In Rechnung wandeln' not in s.get(f'/index.php?p=offer&id={o1}').body, '')
n_inv = sql('select count(*) from invoices'); r = act(o1, 'convert')
rec('S2', 'H', 'Abgelehntes Angebot lässt sich nicht umwandeln (auch nicht per direktem POST)', sql('select count(*) from invoices') == n_inv, r.status)
act(o1, 'reopen'); rec('S3', 'M', 'Wieder auf offen setzbar', sql(f'select status, decided_at is null from offers where id={o1}') == 'open\t1', '')
act(o1, 'accepted'); rec('S4', 'M', 'Als angenommen markiert', sql(f'select status from offers where id={o1}') == 'accepted', '')
d = sql(f"select count(*) from offers where id={o2}")
# Entwurf kann keinen Status bekommen
od, _ = offer(c1); act(od, 'accepted'); rec('S6', 'H', 'Entwurf lässt sich nicht annehmen oder umwandeln', sql(f'select status from offers where id={od}') == 'draft' and (act(od, 'convert') and sql('select count(*) from invoices') == n_inv), '')

# --- Umwandlung
r = act(o1, 'convert')
iid = int(sql('select max(id) from invoices'))
rec('C1', 'H', 'In Rechnung gewandelt: Entwurf mit Kunde, 2 Positionen (Menge/Preis/MwSt identisch), Bezug auf Angebot', sql('select count(*) from invoices') == str(int(n_inv) + 1) and sql(f'select status, customer_id, offer_id from invoices where id={iid}') == f'draft\t{c1}\t{o1}' and sql(f'select group_concat(concat(quantity,"/",unit_price,"/",vat_rate) order by pos) from invoice_items where invoice_id={iid}') == '2.000/100.00/19.00,1.000/50.00/7.00', r.h('location'))
rec('C2', 'M', 'Rechnungsentwurf: Hinweis „Gemäß Angebot …“, Fälligkeit nach Zahlungsziel', f'Gemäß Angebot {no1}' in (sql(f'select notes from invoices where id={iid}')) and sql(f'select datediff(due_date, issue_date) from invoices where id={iid}') == '14', '')
rec('C3', 'M', 'Angebot: angenommen und mit Rechnung verknüpft; Detailseite verlinkt die Rechnung, Rechnung das Angebot', sql(f'select status, invoice_id from offers where id={o1}') == f'accepted\t{iid}' and f'p=invoice&amp;id={iid}' in s.get(f'/index.php?p=offer&id={o1}').body and no1 in s.get(f'/index.php?p=invoice&id={iid}').body and 'Erstellt aus Angebot' in s.get(f'/index.php?p=invoice&id={iid}').body, '')
act(o1, 'convert'); rec('C4', 'H', 'Zweite Umwandlung desselben Angebots wird verhindert', sql('select count(*) from invoices') == str(int(n_inv) + 1), '')
iact(iid, 'issue'); inum = sql(f'select number from invoices where id={iid}')
rec('C5', 'M', 'Aus dem Angebot entstandene Rechnung lässt sich normal ausstellen', inum.startswith('RE-') and sql(f'select status from invoices where id={iid}') == 'issued', inum)
# Entwurf löschen -> Verknüpfung weg, erneut umwandeln möglich
o3, _ = offer(c1, price='5,00'); act(o3, 'issue'); act(o3, 'convert'); i3 = int(sql('select max(id) from invoices'))
iact(i3, 'delete')
rec('C6', 'H', 'Rechnungsentwurf gelöscht: Verknüpfung am Angebot entfällt, erneut umwandelbar', sql(f'select invoice_id is null from offers where id={o3}') == '1' and (act(o3, 'convert') and sql(f'select invoice_id is not null from offers where id={o3}') == '1'), '')
# abgelaufen
sql(f"update offers set valid_until='{yd}', status='open', invoice_id=NULL where id={o2}")
pgl = s.get('/index.php?p=offers'); pgo = s.get(f'/index.php?p=offer&id={o2}')
rec('C7', 'M', 'Abgelaufenes Angebot: Anzeigestatus „abgelaufen“ (Liste, Detail, Hinweis), Umwandeln weiter möglich', 'abgelaufen' in pgl.body and 'abgelaufen' in pgo.body and 'Gültigkeit dieses Angebots' in pgo.body and 'In Rechnung wandeln' in pgo.body, '')
dup = act(o1, 'duplicate'); od2 = int(sql('select max(id) from offers'))
rec('C8', 'M', 'Duplizieren: neuer Entwurf mit Positionen, ohne Nummer/Verknüpfung', sql(f'select status, number is null, invoice_id is null from offers where id={od2}') == 'draft\t1\t1' and sql(f'select count(*) from offer_items where offer_id={od2}') == '2', dup.h('location'))
act(od2, 'delete'); rec('C9', 'M', 'Entwurf löschen entfernt Angebot und Positionen; ausgestellte lassen sich nicht löschen', sql(f'select count(*) from offers where id={od2}') == '0' and (act(o1, 'delete') and sql(f'select count(*) from offers where id={o1}') == '1'), '')

# --- Escaping
c2 = cust('<script>alert(1)</script> & Co'); ox, _ = offer(c2, desc='<img src=x onerror=alert(1)>', notes='<b>fett</b>')
pgx = s.get(f'/index.php?p=offer&id={ox}')
rec('X1', 'H', 'Angebotsseite escaped Kundenname, Position und Notiz', '<script>alert(1)' not in pgx.body and '<img src=x' not in pgx.body and '<b>fett</b>' not in pgx.body and '&lt;script&gt;' in pgx.body, '')
act(ox, 'issue'); tx = pdftext(f"{APP}/storage/offers/{sql(f'select number from offers where id={ox}')}.pdf")
rec('X2', 'M', 'PDF enthält die Sonderzeichen als Text (nicht interpretiert)', '<img src=x' in tx and '<script>alert(1)</script> & Co' in tx, tx[:200])

# --- Textvorlagen
pt = s.get('/index.php?p=templates')
rec('T1', 'M', 'Vorlagenseite: Platzhalterliste (nummer, faellig, betrag, gueltig_bis …) und alle Felder mit Standardtexten', all(x in pt.body for x in ('{nummer}', '{faellig}', '{betrag}', '{netto}', '{gueltig_bis}', '{kunde}', '{link}', '{ansprechpartner}')) and 'name="mail_invoice_body"' in pt.body and 'name="mail_reminder_body"' in pt.body and 'name="pdf_offer_closing"' in pt.body and 'Rechnung {nummer} von {firma}' in pt.body, pt.status)
def tplpost(**kw):
    vals = {}
    for k in re.findall(r'name="(mail_[a-z_]+|pdf_[a-z_]+)"', pt.body):
        if k in vals: continue
        m = re.search(r'name="%s"[^>]*value="([^"]*)"' % k, pt.body) or re.search(r'name="%s"[^>]*>(.*?)</textarea>' % k, pt.body, re.S)
        vals[k] = _h.unescape(m.group(1)) if m else ''
    vals.update(kw); vals['csrf'] = page_token(s, '/index.php?p=templates'); return s.post('/index.php?p=templates', vals)
cfg0 = open(APP + '/config.php').read()
r = tplpost(mail_invoice_body='Hallo {kunde}, {betrg} bitte')
rec('T2', 'H', 'Unbekannter Platzhalter wird abgelehnt, config.php unverändert', 'unbekannter Platzhalter' in r.body and '{betrg}' in r.body and open(APP + '/config.php').read() == cfg0, r.status)
r = tplpost(mail_invoice_body='Hallo {kunde} ({ansprechpartner}), Rechnung {NUMMER} über {Betrag}, netto {netto}, fällig {faellig}, Datum {datum}, von {firma}/{absender}. {link} {gueltig_bis}|', mail_invoice_subject='Neu: {nummer}\r\nBcc: x@evil.example', pdf_invoice_intro='Leistungszeitraum laut Vereinbarung – Rechnung {nummer}.', pdf_invoice_payment='Zahlbar bis {faellig}.', pdf_offer_closing='Angebot bis {gueltig_bis}, Nr. {nummer}.')
rec('T3', 'H', 'Gültige Vorlagen werden gespeichert (config.php), Meldung', r.status == 302 and 'TPL_MAIL_INVOICE_BODY' in open(APP + '/config.php').read(), r.status)
pt2 = s.get('/index.php?p=templates')
rec('T4', 'M', 'Betreff: Zeilenumbruch zu Leerzeichen zusammengefasst (einzeilig)', 'value="Neu: {nummer} Bcc: x@evil.example"' in pt2.body, '')
c3 = cust('Vorlagen-Kunde', contact=''); 
def inv(cid, price='100,00', due=''):
    p = '/index.php?p=invoice_edit'
    s.post(p, {'csrf': page_token(s, p), 'customer_id': cid, 'issue_date': iso, 'due_date': due, 'delivery_date': '', 'notes': '', 'desc[]': ['Leistung'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': [price], 'vat[]': ['19']})
    return int(sql('select max(id) from invoices'))
ia = inv(c3); 
iact(ia, 'issue'); na = sql(f'select number from invoices where id={ia}')
pga = s.get(f'/index.php?p=invoice&id={ia}'); mb = re.search(r'name="message"[^>]*>(.*?)</textarea>', pga.body, re.S); body = _h.unescape(mb.group(1))
due_s = (today + datetime.timedelta(days=14)).strftime('%d.%m.%Y')
rec('T5', 'H', 'Rechnungsmail nutzt die eigene Vorlage mit allen Platzhaltern (leere Werte bleiben leer, Groß-/Kleinschreibung egal)', body.startswith(f'Hallo Vorlagen-Kunde (), Rechnung {na} über 119,00 €, netto 100,00 €, fällig {due_s}, Datum {today.strftime("%d.%m.%Y")}, von Test IT-Service/Kai Test. http') and body.endswith('|') and '{' not in body, body)
mt = re.search(r'name="subject"[^>]*value="([^"]*)"', pga.body)
rec('T6', 'M', 'Betrefffeld der Rechnungsmail aus Vorlage', _h.unescape(mt.group(1)) == f'Neu: {na} Bcc: x@evil.example', mt.group(1) if mt else None)
m0 = len(mails()); iact(ia, 'send', to='k@example.com', subject=_h.unescape(mt.group(1)), message=body)
m, mbody, atts = last_mail()
rec('T7', 'H', 'Versendete Rechnungsmail: Betreff aus Vorlage, ohne zusätzlichen Header, PDF angehängt', len(mails()) == m0 + 1 and m['Subject'] == f'Neu: {na} Bcc: x@evil.example' and m['Bcc'] is None and atts and atts[0][0] == na + '.pdf', m['Subject'])
tx = pdftext(f'{APP}/storage/invoices/{na}.pdf')
rec('T8', 'H', 'Rechnungs-PDF zeigt Einleitung und Zahlungshinweis aus den Vorlagen', f'Leistungszeitraum laut Vereinbarung – Rechnung {na}.' in tx and f'Zahlbar bis {due_s}.' in tx and 'unter Angabe der Rechnungsnummer' not in tx, tx[-600:])
rec('T9', 'H', 'Snapshot der Rechnung friert die PDF-Texte ein', 'Leistungszeitraum laut Vereinbarung' in sql(f'select snapshot from invoices where id={ia}').encode().decode('unicode_escape', 'ignore') or 'pdf_invoice_intro' in sql(f'select snapshot from invoices where id={ia}'), '')
# Änderung der Vorlage ändert alte Rechnung nicht
r = tplpost(pdf_invoice_intro='GANZ ANDERER TEXT', pdf_invoice_payment='Neuer Zahlungshinweis.')
pdf_old = open(f'{APP}/storage/invoices/{na}.pdf', 'rb').read()
os.remove(f'{APP}/storage/invoices/{na}.pdf')
tx2 = pdftext(f'{APP}/storage/invoices/{na}.pdf') if (s.get(f'/index.php?p=pdf&id={ia}') and os.path.isfile(f'{APP}/storage/invoices/{na}.pdf')) else ''
rec('T10', 'H', 'Neu gerenderte alte Rechnung nutzt weiter die beim Ausstellen eingefrorenen Texte', 'Leistungszeitraum laut Vereinbarung' in tx2 and 'GANZ ANDERER' not in tx2, tx2[-300:])
ib = inv(c3); iact(ib, 'issue'); nb = sql(f'select number from invoices where id={ib}'); txb = pdftext(f'{APP}/storage/invoices/{nb}.pdf')
rec('T11', 'H', 'Neue Rechnung nutzt die geänderten Texte', 'GANZ ANDERER TEXT' in txb and 'Neuer Zahlungshinweis.' in txb, txb[-300:])
iprev = inv(c3); rp = s.get(f'/index.php?p=draft_preview&id={iprev}')
rec('T12', 'M', 'Entwurfs-Vorschau erzeugt weiterhin ein PDF mit Vorlagentexten', rp.status == 200 and rp.raw[:5] == b'%PDF-', rp.status)
r = tplpost(mail_invoice_body='', mail_invoice_subject='', pdf_invoice_intro='')
pt3 = s.get('/index.php?p=templates')
rec('T13', 'M', 'Leere Mail-Texte fallen auf den Standard zurück, leere Einleitung bleibt leer', 'Rechnung {nummer} von {firma}' in pt3.body and 'anbei erhalten Sie die Rechnung {nummer}' in pt3.body and 'GANZ ANDERER' not in pt3.body, '')
r = tplpost(mail_offer_body='</textarea><script>alert(1)</script>')
pt4 = s.get('/index.php?p=templates')
rec('T14', 'H', 'Sonderzeichen in Vorlagen werden im Formular escaped', '<script>alert(1)' not in pt4.body and '&lt;/textarea&gt;' in pt4.body, '')
r = tplpost(mail_offer_body='x' * 5001)
rec('T15', 'M', 'Zu lange Vorlage wird abgelehnt', 'zu lang' in r.body, r.status)
r = tplpost(mail_offer_body='Neu {nummer}'); rs = s.post('/index.php?p=templates', {'csrf': page_token(s, '/index.php?p=templates'), 'action': 'reset'})
pt5 = s.get('/index.php?p=templates')
rec('T16', 'M', 'Zurücksetzen stellt alle Standardtexte wieder her', 'Neu {nummer}' not in pt5.body and 'vielen Dank für Ihre Anfrage' in pt5.body, rs.status)
ex = s.get('/index.php?p=exports')
rec('T17', 'M', 'Export-Mailtext kommt aus der Vorlage (Firma/Absender eingesetzt)', 'anbei der Zugang zu den Ausgangsrechnungen von Test IT-Service' in ex.body and '{' not in re.sub(r'<[^>]*>', '', ex.body.split('name="message"')[1].split('</textarea>')[0]) if 'name="message"' in ex.body else True, '')

# --- Zahlungserinnerung
ic = inv(c1, due=yd); iact(ic, 'issue'); nc = sql(f'select number from invoices where id={ic}')
pgc = s.get(f'/index.php?p=invoice&id={ic}')
rec('R1', 'H', 'Überfällige Rechnung: Erinnerungsformular, Betreff und Text aus der Vorlage', 'name="action" value="remind"' in pgc.body and f'Zahlungserinnerung zu Rechnung {nc}' in pgc.body and 'konnten wir bislang keinen Zahlungseingang' in _h.unescape(pgc.body), pgc.status)
pgn = s.get(f'/index.php?p=invoice&id={ia}')
rec('R2', 'H', 'Nicht überfällige Rechnung: kein Erinnerungsformular', 'value="remind"' not in pgn.body, '')
m0 = len(mails()); r = iact(ia, 'remind', to='k@example.com', subject='x', message='x')
rec('R3', 'H', 'Direkter POST für nicht überfällige Rechnung wird abgelehnt', len(mails()) == m0 and sql(f'select reminder_count from invoices where id={ia}') == '0', r.status)
mt = re.search(r'name="subject"[^>]*value="([^"]*)"', pgc.body[pgc.body.index('value="remind"'):]); mb = re.search(r'name="message"[^>]*>(.*?)</textarea>', pgc.body[pgc.body.index('value="remind"'):], re.S)
r = iact(ic, 'remind', to='k@example.com', subject=_h.unescape(mt.group(1)), message=_h.unescape(mb.group(1)))
ok = len(mails()) == m0 + 1
if ok:
    m, mbody, atts = last_mail()
    rec('R4', 'H', 'Erinnerung versendet: Betreff, Text (Fälligkeit, Betrag) und Rechnungs-PDF im Anhang', m['Subject'] == f'Zahlungserinnerung zu Rechnung {nc}' and f'Rechnung {nc}' in mbody and yd[8:10] + '.' + yd[5:7] + '.' + yd[:4] in mbody and atts and atts[0][0] == nc + '.pdf', m['Subject'])
else:
    rec('R4', 'H', 'Erinnerung versendet', False, (len(mails()), m0))
rec('R5', 'M', 'reminded_at und Zähler gesetzt; Seite zeigt „Bisher 1 Erinnerung“', sql(f'select reminder_count, reminded_at is not null from invoices where id={ic}') == '1\t1' and 'Bisher 1 Erinnerung' in s.get(f'/index.php?p=invoice&id={ic}').body, '')
iact(ic, 'remind', to='k@example.com', subject='Zweite', message='Zweite Erinnerung')
rec('R6', 'M', 'Zweite Erinnerung möglich, Zähler 2', sql(f'select reminder_count from invoices where id={ic}') == '2', '')
n_m = len(mails()); r = iact(ic, 'remind', to='keine-adresse', subject='x', message='x')
rec('R7', 'M', 'Ungültige Adresse: nichts versendet, Zähler unverändert', len(mails()) == n_m and sql(f'select reminder_count from invoices where id={ic}') == '2', '')
iact(ic, 'paid', paid_at=iso); n_m = len(mails()); iact(ic, 'remind', to='k@example.com', subject='x', message='x')
rec('R8', 'H', 'Bezahlte Rechnung: keine Erinnerung möglich', len(mails()) == n_m and sql(f'select reminder_count from invoices where id={ic}') == '2' and 'value="remind"' not in s.get(f'/index.php?p=invoice&id={ic}').body, '')
# Storno überfälliger Rechnung
id_ = inv(c1, due=yd); iact(id_, 'issue'); iact(id_, 'cancel'); sd_ = int(sql('select max(id) from invoices')); iact(sd_, 'issue')
n_m = len(mails()); iact(id_, 'remind', to='k@example.com', subject='x', message='x')
rec('R9', 'H', 'Stornierte Rechnung / Stornorechnung: keine Erinnerung', len(mails()) == n_m and 'value="remind"' not in s.get(f'/index.php?p=invoice&id={id_}').body and 'value="remind"' not in s.get(f'/index.php?p=invoice&id={sd_}').body, '')

# --- Einstellungen, Protokoll, Auswertung
cfgp = '/index.php?p=settings'
def save_settings(**kw):
    pg = s.get(cfgp); d = {'csrf': csrf_of(pg.body), 'action': 'save'}
    for m in re.finditer(r'<input[^>]*name="([A-Z_]+)"[^>]*value="([^"]*)"', pg.body):
        d[m.group(1)] = _h.unescape(m.group(2))
    d.update(kw); return s.post(cfgp, d)
r = save_settings(OFFER_PREFIX='A/N')
rec('P1', 'M', 'Ungültiges Angebotspräfix wird abgelehnt', 'Angebotspräfix' in r.body, r.status)
r = save_settings(OFFER_VALID_DAYS='0'); rec('P2', 'M', 'Gültigkeit 0 Tage wird abgelehnt', 'Gültigkeit von Angeboten' in r.body, r.status)
r = save_settings(OFFER_PREFIX='ANG', OFFER_VALID_DAYS='10')
oq, _ = offer(c1, valid_until=''); act(oq, 'issue')
rec('P3', 'H', 'Eigenes Präfix und Gültigkeit: Nummer ANG-…, gültig 10 Tage', sql(f'select number, datediff(valid_until, issue_date) from offers where id={oq}') == f'ANG-{y}-0001\t10', sql(f'select number, datediff(valid_until, issue_date) from offers where id={oq}'))
al = s.get('/index.php?p=audit&group=offer').body
rec('P4', 'M', 'Protokoll enthält Angebots-Ereignisse (ausgestellt, versendet, umgewandelt) und Zahlungserinnerung', all(x in s.get('/index.php?p=audit').body for x in ('Angebot ausgestellt', 'Angebot per E-Mail versendet', 'Angebot in Rechnung gewandelt', 'Zahlungserinnerung versendet', 'Textvorlagen geändert')), '')
vr = s.post('/index.php?p=audit', {'csrf': page_token(s, '/index.php?p=audit')}); vb = s.get('/index.php?p=audit').body
rec('P5', 'H', 'Protokoll-Prüfkette bleibt gültig', 'lückenlos und unverändert' in vb, re.sub(r'<[^>]+>', ' ', vb)[:200])
rep = s.get('/index.php?p=reports')
rec('P6', 'M', 'Angebote zählen nicht zum Umsatz (Auswertung unverändert erreichbar, kein Fehler)', rep.status == 200, rep.status)
rec('P7', 'M', 'Schema-Version 9, Tabellen offers/offer_items und Spalten vorhanden', open(APP + '/storage/schema_version').read().strip() == SCHEMA_CUR == '9' and sql("select count(*) from information_schema.columns where table_schema='%s' and ((table_name='invoices' and column_name in ('offer_id','reminded_at','reminder_count')) or table_name in ('offers','offer_items') and column_name='id')" % DB) == '5', '')
ofd = APP + '/storage/offers'
rr = anon.get('/storage/offers/' + os.path.basename(pdf1)); rr2 = anon.get('/storage/offers/')
rec('P8', 'H', 'storage/offers ist von außen nicht abrufbar', rr.status in (403, 404) and rr.raw[:4] != b'%PDF' and rr2.status in (403, 404), (rr.status, rr2.status))
save_results(label)
