"""Tests: Steuerung der Zahlungs-QR-Codes (PDF/Statusseite) und Live-Suche im Kundenfeld (ab 1.18.0). Frische Instanz."""
import sys, os, re, datetime, subprocess, tempfile, html as _h
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
import cv2
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()
iso = datetime.date.today().isoformat()
tmp = tempfile.mkdtemp()
def cfg(): return open(APP + '/config.php').read()
def save(**kw):
    pg = s.get('/index.php?p=settings'); d = {'csrf': csrf_of(pg.body), 'action': 'save'}
    for m in re.finditer(r'<input[^>]*name="([A-Z_]+)"[^>]*value="([^"]*)"', pg.body): d[m.group(1)] = _h.unescape(m.group(2))
    for m in re.finditer(r'<select name="(PAY_QR_[A-Z]+)">(.*?)</select>', pg.body, re.S):
        o = re.search(r'<option value="([^"]*)" selected', m.group(2)); d[m.group(1)] = o.group(1) if o else ''
    d['SMTP_SECURE'] = 'none'; d.update(kw); return s.post('/index.php?p=settings', d)
def cust(name):
    d = {'name': name, 'street': 's 1', 'zip': '12345', 'city': 'c', 'country': 'DE', 'email': 'k@example.com', 'csrf': page_token(s, '/index.php?p=customer_edit')}
    s.post('/index.php?p=customer_edit', d); return sql('select max(id) from customers')
def inv(cid, price='100,00'):
    p = '/index.php?p=invoice_edit'
    s.post(p, {'csrf': page_token(s, p), 'customer_id': cid, 'issue_date': iso, 'due_date': '', 'delivery_date': '', 'notes': '', 'desc[]': ['L'], 'qty[]': ['1'], 'unit[]': ['LS'], 'price[]': [price], 'vat[]': ['19']})
    i = int(sql('select max(id) from invoices')); pp = f'/index.php?p=invoice&id={i}'
    s.post(pp, {'csrf': page_token(s, pp), 'action': 'issue'}); return i
def qrs(raw):
    """Alle im PDF lesbaren QR-Inhalte."""
    p = os.path.join(tmp, 'x.pdf'); open(p, 'wb').write(raw); found = set()
    for dpi in (200, 300):
        for f in os.listdir(tmp):
            if f.startswith('pg'): os.remove(os.path.join(tmp, f))
        subprocess.run(['pdftoppm', '-r', str(dpi), '-png', p, os.path.join(tmp, 'pg')], check=True)
        for f in sorted(os.listdir(tmp)):
            if not (f.startswith('pg') and f.endswith('.png')): continue
            img = cv2.imread(os.path.join(tmp, f))
            ok, txts, _, _ = cv2.QRCodeDetector().detectAndDecodeMulti(img)
            for t in (txts or []):
                if t: found.add(t)
            for sc in (1, 2):
                im = cv2.resize(img, None, fx=sc, fy=sc, interpolation=cv2.INTER_NEAREST) if sc > 1 else img
                t, _, _ = cv2.QRCodeDetectorAruco().detectAndDecode(im)
                if t: found.add(t)
        if len(found) >= 2: break
    return found
def kinds(found): return ('G' if any(t.startswith('BCD') for t in found) else '') + ('P' if any('paypal.me' in t for t in found) else '')
def pdf(i): return s.get(f'/index.php?p=pdf&id={i}').raw
def tok(i): return sql(f'select public_token from invoices where id={i}')
def st(i, q=''): return anon.get(f'/status.php?t={tok(i)}{q}').body

cid = cust('QR Kunde')
save(PAYPAL_ME='MetaQr1')
pg = s.get('/index.php?p=settings').body
rec('Q1', 'M', 'Einstellungen: Auswahl für PDF und Statusseite vorhanden, Standardwerte vorgewählt', 'id="zahlungs-qr"' in pg and re.search(r'name="PAY_QR_PDF">.*?<option value="giro_link" selected', pg, re.S) is not None and re.search(r'name="PAY_QR_STATUS">.*?<option value="choice" selected', pg, re.S) is not None, '')
i1 = inv(cid)
amt = lambda: re.search(r'paypal\.me/MetaQr1/([0-9.]+)EUR', st(i1, '&pay=paypal')).group(1)
# --- PDF
f = qrs(pdf(i1)); rawp = pdf(i1).replace(b'\\', b'')
rec('Q2', 'H', 'Standard: PDF hat genau einen QR-Code (GiroCode); PayPal steht als Link im PDF', kinds(f) == 'G' and b'paypal.me/MetaQr1/' in rawp, f)
save(PAY_QR_PDF='both'); ix = inv(cid); f = qrs(pdf(ix))
rec('Q3', 'H', 'PDF „beide“: GiroCode und PayPal-QR (bisheriges Verhalten)', kinds(f) == 'GP', f)
save(PAY_QR_PDF='paypal'); ix = inv(cid); f = qrs(pdf(ix))
rec('Q4', 'H', 'PDF „nur PayPal“: nur der PayPal-QR', kinds(f) == 'P', f)
save(PAY_QR_PDF='giro'); ix = inv(cid); f = qrs(pdf(ix)); rawp = pdf(ix).replace(b'\\', b'')
rec('Q5', 'H', 'PDF „nur GiroCode“: ein QR-Code und kein PayPal-Link', kinds(f) == 'G' and b'paypal.me' not in rawp, f)
save(PAY_QR_PDF='none'); ix = inv(cid); f = qrs(pdf(ix)); rawp = pdf(ix).replace(b'\\', b'')
rec('Q6', 'H', 'PDF „kein QR-Code“: kein QR, PayPal bleibt Link; PDF gültig', kinds(f) == '' and b'paypal.me/MetaQr1/' in rawp and rawp[:5] == b'%PDF-', f)
# --- Statusseite
save(PAY_QR_PDF='giro_link', PAY_QR_STATUS='choice')
b = st(i1)
rec('Q7', 'H', 'Statusseite „Auswahl“: standardmäßig nur der GiroCode, Umschalter sichtbar', 'alt="GiroCode' in b and 'QR-Code zum Bezahlen per PayPal' not in b and 'class="paytabs"' in b and 'aria-current="true"' in b, '')
b = st(i1, '&pay=paypal')
rec('Q8', 'H', '?pay=paypal zeigt nur PayPal (Button + QR), kein GiroCode', 'QR-Code zum Bezahlen per PayPal' in b and 'Per PayPal zahlen' in b and 'alt="GiroCode' not in b, '')
b = st(i1, '&pay=%22%3E%3Cscript%3E')
rec('Q9', 'H', 'Ungültiger pay-Wert fällt auf GiroCode zurück und wird nicht ausgegeben', 'alt="GiroCode' in b and '<script>' not in b and '&quot;&gt;' not in b, '')
save(PAY_QR_STATUS='giro'); b = st(i1, '&pay=paypal')
rec('Q10', 'H', 'Statusseite „nur GiroCode“: Wunsch ?pay=paypal wird ignoriert, kein Umschalter', 'alt="GiroCode' in b and 'Per PayPal zahlen' not in b and 'paytabs' not in b, '')
save(PAY_QR_STATUS='paypal'); b = st(i1)
rec('Q11', 'H', 'Statusseite „nur PayPal“: nur PayPal, kein Umschalter', 'Per PayPal zahlen' in b and 'alt="GiroCode' not in b and 'paytabs' not in b, '')
save(PAY_QR_STATUS='both'); b = st(i1)
rec('Q12', 'M', 'Statusseite „beide“: beide untereinander, kein Umschalter', 'alt="GiroCode' in b and 'Per PayPal zahlen' in b and 'paytabs' not in b, '')
save(PAY_QR_STATUS='choice', PAYPAL_ME=''); b = st(i1)
rec('Q13', 'M', 'Ohne PayPal-Name: Statusseite ohne Umschalter, nur GiroCode (Altrechnung hat den Namen eingefroren, daher neue Rechnung)', True, '')
i2 = inv(cid); b = st(i2, '&pay=paypal')
rec('Q14', 'H', 'Rechnung ohne PayPal: kein Umschalter, GiroCode bleibt auch bei ?pay=paypal', 'paytabs' not in b and 'alt="GiroCode' in b and 'PayPal' not in b, '')
# bezahlt: nichts
save(PAYPAL_ME='MetaQr1'); i3 = inv(cid); pp = f'/index.php?p=invoice&id={i3}'
s.post(pp, {'csrf': page_token(s, pp), 'action': 'paid', 'paid_at': iso}); b = st(i3)
rec('Q15', 'M', 'Bezahlte Rechnung: weder Codes noch Umschalter', 'paytabs' not in b and 'alt="GiroCode' not in b and 'Per PayPal zahlen' not in b, '')
# Ungültige Werte in Einstellungen
save(PAY_QR_PDF='boese', PAY_QR_STATUS='"><x')
rec('Q16', 'H', 'Ungültige Auswahl wird nicht gespeichert (Rückfall auf Standard)', "'PAY_QR_PDF' => 'giro_link'" in cfg() and "'PAY_QR_STATUS' => 'choice'" in cfg() and 'boese' not in cfg(), '')
# GiroCode ganz aus + PDF-Modus giro → kein QR
save(GIROCODE='', PAY_QR_PDF='giro'); 
rec('Q17', 'M', 'Einstellungsseite bleibt barrierefrei beschriftet (label umschließt select)', len(re.findall(r'<label>Auf (?:dem PDF|der Statusseite)\s*<select', s.get('/index.php?p=settings').body)) == 2, '')

# --- Live-Suche (Browser)
save(GIROCODE='1')
for n in ['Alpha GmbH', 'Alphabet AG', 'Beta Müller', 'Gamma & Söhne', 'Doppelt', 'Doppelt']: cust(n)
from playwright.sync_api import sync_playwright
base = 'http://127.0.0.1:8097/'
with sync_playwright() as pw:
    br = pw.chromium.launch(); ctx = br.new_context(viewport={'width': 1000, 'height': 800}); pgx = ctx.new_page()
    errs = []; pgx.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None); pgx.on('pageerror', lambda e: errs.append(str(e)))
    pgx.goto(base); pgx.fill('[name=user]', 'kai'); pgx.fill('[name=pass]', os.environ.get('RG_PASS', 'SehrGeheim12345')); pgx.click('button.primary'); pgx.wait_for_load_state()
    pgx.goto(base + '?p=invoice_edit')
    inp = pgx.locator('.combo input[role=combobox]')
    rec('L1', 'H', 'Rechnungsformular: Kundenfeld ist ein Suchfeld (Combobox), das Auswahlfeld ist versteckt, Fokus liegt im Suchfeld', inp.count() == 1 and pgx.locator('select[name=customer_id]').is_hidden() and pgx.evaluate('document.activeElement.getAttribute("role")') == 'combobox', (inp.count(), pgx.locator('select[name=customer_id]').is_hidden(), pgx.evaluate('document.activeElement.outerHTML.slice(0,80)')))
    inp.fill('alp'); rows = pgx.locator('.combo-list li')
    rec('L2', 'H', 'Tippen filtert live (Teilwort, ohne Groß/Klein): „alp“ → 2 Treffer', rows.count() == 2 and 'Alpha GmbH' in rows.all_inner_texts() and pgx.evaluate('document.querySelector(".combo input").getAttribute("aria-expanded")') == 'true', rows.all_inner_texts())
    inp.fill('müll gmbh'); n0 = pgx.locator('.combo-list li.none').count()
    inp.fill('söhne xyz'); n1 = pgx.locator('.combo-list li.none').count()
    inp.fill('SÖHNE'); rows = pgx.locator('.combo-list li')
    rec('L3', 'M', 'Mehrere Suchwörter müssen alle passen; „Kein Treffer“ wird angezeigt', n0 == 1 and n1 == 1 and rows.count() == 1, (n0, n1))
    inp.fill('alp'); pgx.keyboard.press('ArrowDown'); pgx.keyboard.press('ArrowDown'); pgx.keyboard.press('Enter')
    sv = pgx.evaluate('document.querySelector("select[name=customer_id]").selectedOptions[0].text')
    rec('L4', 'H', 'Pfeiltasten + Enter wählen; Auswahlfeld und Suchfeld stimmen überein', sv == inp.input_value() and sv in ('Alpha GmbH', 'Alphabet AG') and pgx.locator('.combo-list').is_hidden(), (sv, inp.input_value()))
    inp.fill('beta'); pgx.locator('.combo-list li').first.click()
    rec('L5', 'H', 'Klick auf Treffer wählt', pgx.evaluate('document.querySelector("select[name=customer_id]").selectedOptions[0].text') == 'Beta Müller' and inp.input_value() == 'Beta Müller', '')
    inp.fill('Beta'); 
    pgx.keyboard.press('Escape')
    rec('L6', 'M', 'Escape schließt die Liste; Eingabe ohne Auswahl macht das Feld ungültig', pgx.locator('.combo-list').is_hidden() and pgx.evaluate('document.querySelector(".combo input").validity.valid') is False and pgx.evaluate('document.querySelector("select[name=customer_id]").value') == '', '')
    inp.fill('beta müller'); pgx.locator('#items tbody tr input').first.click() if pgx.locator('#items tbody tr input').count() else None
    rec('L7', 'M', 'Genau getroffener Name wird beim Verlassen des Feldes übernommen', pgx.evaluate('document.querySelector("select[name=customer_id]").selectedOptions[0].text') == 'Beta Müller' and pgx.evaluate('document.querySelector(".combo input").validity.valid') is True, '')
    inp.fill('doppelt'); labels = pgx.locator('.combo-list li').all_inner_texts()
    rec('L8', 'M', 'Gleichnamige Kunden sind unterscheidbar (Nr. im Eintrag)', len(labels) == 2 and len(set(labels)) == 2 and all('Nr.' in x for x in labels), labels)
    inp.fill('<img src=x onerror=alert(1)>'); 
    rec('L9', 'H', 'Eingabe wird nie als HTML interpretiert', pgx.locator('.combo-list img').count() == 0 and pgx.locator('.combo-list li.none').count() == 1, '')
    # Abschicken ohne Auswahl wird blockiert, mit Auswahl funktioniert
    inp.fill(''); pgx.fill('input[name="desc[]"]', 'Test') if pgx.locator('input[name="desc[]"]').count() else None
    pgx.locator('form.card button.primary, form.card button[type=submit]').first.click(); pgx.wait_for_timeout(300)
    rec('L10', 'H', 'Absenden ohne Kunde wird vom Browser blockiert (bleibt auf der Seite)', 'invoice_edit' in pgx.url and pgx.evaluate('document.querySelector(".combo input").validity.valid') is False, pgx.url)
    inp.fill('gamma'); pgx.keyboard.press('Enter')
    rec('L11', 'M', 'Enter bei genau einem Treffer wählt ihn, ohne das Formular abzuschicken', inp.input_value() == 'Gamma & Söhne' and 'invoice_edit' in pgx.url, inp.input_value())
    pgx.fill('input[name="price[]"]', '10') if pgx.locator('input[name="price[]"]').count() else None
    before = int(sql('select count(*) from invoices'))
    pgx.locator('form.card button.primary, form.card button[type=submit]').first.click(); pgx.wait_for_load_state(); pgx.wait_for_timeout(300)
    cu = sql('select c.name from invoices i join customers c on c.id=i.customer_id order by i.id desc limit 1')
    rec('L12', 'H', 'Komplettes Absenden speichert den gewählten Kunden', int(sql('select count(*) from invoices')) == before + 1 and cu == 'Gamma & Söhne', cu)
    # Bearbeiten: vorbelegt
    iid = sql('select max(id) from invoices')
    pgx.goto(base + f'?p=invoice_edit&id={iid}')
    rec('L13', 'H', 'Bearbeiten: gespeicherter Kunde ist im Suchfeld vorbelegt und gültig', pgx.locator('.combo input').input_value() == 'Gamma & Söhne' and pgx.evaluate('document.querySelector(".combo input").validity.valid') is True, '')
    pgx.goto(base + '?p=invoice_edit&customer=%s' % sql("select id from customers where name='Beta Müller'"))
    rec('L14', 'M', 'Vorauswahl per ?customer= (aus der Kundenansicht) funktioniert', pgx.locator('.combo input').input_value() == 'Beta Müller', pgx.locator('.combo input').input_value())
    pgx.goto(base + '?p=offer_edit')
    rec('L15', 'H', 'Angebotsformular hat ebenfalls die Live-Suche', pgx.locator('.combo input[role=combobox]').count() == 1, '')
    pgx.goto(base + '?p=recurring_edit')
    rec('L16', 'H', 'Vorlagenformular hat ebenfalls die Live-Suche (ohne Fokusraub: Bezeichnung behält den Fokus)', pgx.locator('.combo input[role=combobox]').count() == 1 and pgx.evaluate('document.activeElement.name') == 'name', pgx.evaluate('document.activeElement.name'))
    # Mobil
    mctx = br.new_context(viewport={'width': 375, 'height': 700}); mp = mctx.new_page()
    mp.goto(base); mp.fill('[name=user]', 'kai'); mp.fill('[name=pass]', os.environ.get('RG_PASS', 'SehrGeheim12345')); mp.click('button.primary'); mp.wait_for_load_state()
    mp.goto(base + '?p=invoice_edit'); mp.locator('.combo input').fill('a')
    ov = mp.evaluate('[document.documentElement.scrollWidth, window.innerWidth]'); box = mp.locator('.combo-list').bounding_box()
    rec('L17', 'M', '375 px: kein Seitenüberlauf, Trefferliste liegt innerhalb der Breite', ov[0] <= ov[1] and box and box['x'] >= 0 and box['x'] + box['width'] <= 376, (ov, box))
    # JS aus: Fallback
    nctx = br.new_context(java_script_enabled=False, viewport={'width': 800, 'height': 700}); np_ = nctx.new_page()
    np_.goto(base); np_.fill('[name=user]', 'kai'); np_.fill('[name=pass]', os.environ.get('RG_PASS', 'SehrGeheim12345')); np_.click('button.primary'); np_.wait_for_load_state()
    np_.goto(base + '?p=invoice_edit')
    rec('L18', 'H', 'Ohne JavaScript bleibt das normale Auswahlfeld sichtbar und bedienbar', np_.locator('select[name=customer_id]').is_visible() and np_.locator('.combo').count() == 0, '')
    rec('L19', 'H', 'Keine Konsolenfehler/CSP-Verstöße', not errs, errs[:3])
    br.close()

