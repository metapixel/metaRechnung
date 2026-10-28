"""Tests: Artikel duplizieren (ab 1.10.2). Frische Instanz."""
import sys, os, re
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seclib import *
label = sys.argv[1]
clear_rl()
s = authed(); anon = C()
def mk(desc, note, price='12.50', vat='7', unit='HUR'):
    s.post('/index.php?p=article_edit', {'csrf': page_token(s, '/index.php?p=article_edit'), 'description': desc, 'note': note, 'unit': unit, 'unit_price': price, 'vat_rate': vat})
    return int(sql("select max(id) from articles"))
a = mk('Beratung <b>"Tag"</b>', 'Notiz {ZEITRAUM}')
n0 = int(sql('select count(*) from articles'))
pg = s.get(f'/index.php?p=article_edit&id={a}').body
rec('A1', 'M', 'Detailansicht zeigt „Als neu duplizieren“ (nicht beim Anlegen)', 'Als neu duplizieren' in pg and 'Als neu duplizieren' not in s.get('/index.php?p=article_edit').body)
r = s.post(f'/index.php?p=article_edit&id={a}', {'csrf': page_token(s, f'/index.php?p=article_edit&id={a}'), 'action': 'duplicate'})
b = int(sql('select max(id) from articles'))
rec('A2', 'H', 'Duplizieren legt genau einen neuen Artikel an und leitet zu dessen Bearbeitung weiter', int(sql('select count(*) from articles')) == n0 + 1 and b != a and r.status in (302, 303) and f'id={b}' in r.h('location'), (r.status, r.h('location')))
row = lambda i: sql(f"select description,ifnull(note,''),unit,unit_price,vat_rate from articles where id={i}").split('\t')
ra, rb = row(a), row(b)
rec('A3', 'H', 'Kopie übernimmt Notiz, Einheit, Preis, MwSt; Bezeichnung mit „ (Kopie)“; Original unverändert', rb[0] == ra[0] + ' (Kopie)' and rb[1:] == ra[1:] and ra[0] == 'Beratung <b>"Tag"</b>', (ra, rb))
pb = s.get(f'/index.php?p=article_edit&id={b}').body
rec('A4', 'H', 'Bezeichnung der Kopie wird im Formular maskiert ausgegeben', '<b>"Tag"</b>' not in pb and '&lt;b&gt;' in pb)
rec('A5', 'M', 'Erfolgsmeldung nach dem Duplizieren sichtbar', 'Kopie angelegt' in pb)
long_ = mk('X' * 500, '')
n1 = int(sql('select count(*) from articles'))
s.post(f'/index.php?p=article_edit&id={long_}', {'csrf': page_token(s, f'/index.php?p=article_edit&id={long_}'), 'action': 'duplicate'})
rec('A6', 'M', 'Kopie eines 500-Zeichen-Namens bleibt ≤ 500 Zeichen (kein SQL-/500-Fehler)', int(sql('select count(*) from articles')) == n1 + 1 and int(sql('select max(char_length(description)) from articles')) <= 500)
n2 = int(sql('select count(*) from articles'))
r = s.post(f'/index.php?p=article_edit&id={a}', {'action': 'duplicate'})
rec('A7', 'H', 'Ohne CSRF-Token → 400, nichts angelegt', r.status == 400 and int(sql('select count(*) from articles')) == n2, r.status)
r = anon.post(f'/index.php?p=article_edit&id={a}', {'action': 'duplicate', 'csrf': 'x'})
rec('A8', 'H', 'Anonym: nichts angelegt', int(sql('select count(*) from articles')) == n2, r.status)
r = s.post('/index.php?p=article_edit&id=999999', {'csrf': page_token(s, '/index.php?p=articles') if False else page_token(s, f'/index.php?p=article_edit&id={a}'), 'action': 'duplicate'})
rec('A9', 'M', 'Unbekannte ID: Fehlermeldung, nichts angelegt, kein 500', r.status < 500 and int(sql('select count(*) from articles')) == n2, r.status)
r = s.get(f'/index.php?p=article_edit&id={a}&action=duplicate')
rec('A10', 'H', 'Duplizieren per GET ist wirkungslos', int(sql('select count(*) from articles')) == n2)
pe = open('/home/claude/work/sec/apache/php_error.log').read()
rec('A11', 'M', 'Keine PHP-Fehler im Log', pe.strip() == '', pe[:200])
save_results(label)
