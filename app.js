// Bestätigungsdialoge, Auswahl beim Klick, Service Worker (ohne Inline-Skripte, damit die CSP "script-src 'self'" greift)
document.addEventListener('submit', function (e) {
  var m = e.target.getAttribute && e.target.getAttribute('data-confirm');
  if (m && !window.confirm(m)) e.preventDefault();
});
document.addEventListener('click', function (e) {
  if (e.target.hasAttribute && e.target.hasAttribute('data-select')) e.target.select();
  var fill = e.target.closest && e.target.closest('[data-fill]');
  if (fill) { var f = document.getElementById(fill.getAttribute('data-fill')); if (f) { f.value = fill.getAttribute('data-value'); f.dispatchEvent(new Event('input', {bubbles: true})); } }
});
// Ganze Tabellenzeile klickbar (Tastatur und Screenreader nutzen weiter den echten Link in der Zeile)
document.addEventListener('click', function (e) {
  var row = e.target.closest && e.target.closest('tr[data-href]');
  if (!row || e.defaultPrevented || e.button !== 0 || e.target.closest('a, button, input, select, textarea, label')) return;
  var sel = window.getSelection && window.getSelection();
  if (sel && !sel.isCollapsed && row.contains(sel.anchorNode)) return;   // Text markieren soll nicht navigieren
  var url;
  try { url = new URL(row.getAttribute('data-href'), location.href); } catch (err) { return; }
  if (url.origin !== location.origin) return;
  if (e.ctrlKey || e.metaKey || e.shiftKey) window.open(url.href, '_blank', 'noopener'); else location.href = url.href;
});
if ('serviceWorker' in navigator && document.body.dataset.sw) {
  navigator.serviceWorker.register(document.body.dataset.sw);
}

(function () {
  var body = document.querySelector('#items tbody');
  if (!body) return;
  var kl = document.body.dataset.kl === '1';
  var fmt = function (n) { return n.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }); };

  function say(msg) { var l = document.getElementById('live'); if (l) { l.textContent = ''; setTimeout(function () { l.textContent = msg; }, 50); } }

  function recalc() {
    var net = 0, tax = 0, groups = {};
    body.querySelectorAll('tr').forEach(function (tr) {
      var q = parseFloat(tr.querySelector('[name="qty[]"]').value) || 0;
      var p = parseFloat(tr.querySelector('[name="price[]"]').value) || 0;
      var v = kl ? 0 : (parseFloat(tr.querySelector('[name="vat[]"]').value) || 0);
      var line = Math.round(q * p * 100) / 100;
      net += line;
      groups[v] = (groups[v] || 0) + line;
      tr.querySelector('.line').textContent = fmt(line);
    });
    Object.keys(groups).forEach(function (v) { tax += Math.round(groups[v] * parseFloat(v)) / 100; });
    document.getElementById('s-net').textContent = fmt(net);
    var t = document.getElementById('s-tax'); if (t) t.textContent = fmt(tax);
    document.getElementById('s-gross').textContent = fmt(net + tax);
  }

  // Neue Zeile: eine leere letzte Zeile wird wiederverwendet, sonst die letzte Zeile geklont
  function newRow() {
    var last = body.lastElementChild;
    var blank = !last.querySelector('[name="desc[]"]').value.trim() && !last.querySelector('[name="price[]"]').value.trim();
    if (blank) return last;
    var tr = last.cloneNode(true);
    tr.querySelectorAll('input, textarea').forEach(function (i) { i.value = ''; });
    tr.querySelector('[name="qty[]"]').value = body.parentNode.getAttribute('data-credit') ? '-1' : '1';
    body.appendChild(tr);
    return tr;
  }
  document.getElementById('addrow').addEventListener('click', function () {
    var tr = newRow(); recalc();
    tr.querySelector('[name="desc[]"]').focus();
    say('Position ' + body.children.length + ' hinzugefügt.');
  });

  // Artikel aus der Artikelliste als Position einfügen
  var art = document.getElementById('addarticle');
  if (art) {
    art.addEventListener('change', function () {
      var o = art.options[art.selectedIndex];
      if (!o || !o.value) return;
      var tr = newRow();
      tr.querySelector('[name="desc[]"]').value = o.dataset.desc || '';
      var nt = tr.querySelector('[name="note[]"]'); if (nt) nt.value = o.dataset.note || '';
      tr.querySelector('[name="qty[]"]').value = body.parentNode.getAttribute('data-credit') ? '-1' : '1';
      tr.querySelector('[name="price[]"]').value = o.dataset.price || '';
      var u = tr.querySelector('[name="unit[]"]'); if (u && o.dataset.unit) u.value = o.dataset.unit;
      var v = tr.querySelector('[name="vat[]"]');
      if (v && o.dataset.vat !== undefined) {
        v.value = o.dataset.vat;
        if (v.value !== o.dataset.vat) { var op = document.createElement('option'); op.value = op.textContent = o.dataset.vat; v.appendChild(op); v.value = o.dataset.vat; }
      }
      art.selectedIndex = 0;
      recalc();
      say('Artikel als Position ' + body.children.length + ' eingefügt: ' + (o.dataset.desc || ''));
      tr.querySelector('[name="qty[]"]').focus();
    });
  }
  body.addEventListener('click', function (e) {
    var rb = e.target.closest ? e.target.closest('.rm') : null;
    if (rb && body.children.length > 1) { rb.closest('tr').remove(); recalc(); say('Position entfernt.'); var d = body.querySelector('[name="desc[]"]'); if (d) d.focus(); }
  });
  body.addEventListener('input', recalc);
  recalc();
})();

// CSS-Editor (Seite „Darstellung“): CodeMirror ersetzt die Textarea, Änderungen wirken sofort als Vorschau
(function () {
  var ta = document.querySelector('textarea[data-css-editor]');
  if (!ta) return;
  var live = document.getElementById('custom-css');
  function preview(v) { if (live) live.textContent = v; }
  var ed = null;
  if (typeof window.metaCssEditor === 'function') ed = window.metaCssEditor(ta, preview);
  ta.addEventListener('input', function () { preview(ta.value); });
  var btn = document.getElementById('css-insert'), tpl = document.getElementById('css-template');
  if (btn && tpl) btn.addEventListener('click', function () {
    var cur = ta.value.trim();
    var next = cur ? tpl.content.textContent + '\n\n' + cur : tpl.content.textContent;
    if (ed) ed.set(next); else { ta.value = next; }
    preview(next);
    if (ed) ed.focus(); else ta.focus();
  });
})();

// Live-Suche statt langem Auswahlfeld: <select data-combo> wird zu einem Suchfeld mit Trefferliste.
// Ohne JavaScript bleibt das normale Auswahlfeld stehen; abgeschickt wird weiterhin das <select>.
(function () {
  var seq = 0;
  document.querySelectorAll('select[data-combo]').forEach(function (sel) {
    var items = [], seen = {};
    Array.prototype.forEach.call(sel.options, function (o) { if (o.value !== '') { seen[o.text] = (seen[o.text] || 0) + 1; } });
    Array.prototype.forEach.call(sel.options, function (o) {
      if (o.value === '') return;
      items.push({ id: o.value, label: seen[o.text] > 1 ? o.text + ' (Nr. ' + o.value + ')' : o.text });
    });
    var uid = 'combo' + (++seq), req = sel.required, active = -1, shown = [];
    var wrap = document.createElement('div'); wrap.className = 'combo';
    var inp = document.createElement('input');
    inp.type = 'text'; inp.autocomplete = 'off'; inp.spellcheck = false; inp.required = req;
    inp.setAttribute('role', 'combobox'); inp.setAttribute('aria-autocomplete', 'list');
    inp.setAttribute('aria-expanded', 'false'); inp.setAttribute('aria-controls', uid);
    inp.placeholder = 'Tippen zum Suchen …';
    var list = document.createElement('ul'); list.id = uid; list.className = 'combo-list'; list.setAttribute('role', 'listbox'); list.hidden = true;
    var live = document.createElement('span'); live.className = 'sr'; live.setAttribute('aria-live', 'polite');
    wrap.appendChild(inp); wrap.appendChild(list); wrap.appendChild(live);
    sel.parentNode.insertBefore(wrap, sel);
    sel.hidden = true; sel.required = false; sel.tabIndex = -1; sel.setAttribute('aria-hidden', 'true');
    var cur = sel.options[sel.selectedIndex];
    if (cur && cur.value !== '') { var c0 = items.filter(function (i) { return i.id === cur.value; })[0]; if (c0) inp.value = c0.label; }

    function validity() {
      inp.setCustomValidity(sel.value === '' && (req || inp.value !== '') ? 'Bitte einen Eintrag aus der Liste wählen.' : '');
    }
    function close() { list.hidden = true; inp.setAttribute('aria-expanded', 'false'); inp.removeAttribute('aria-activedescendant'); active = -1; }
    function pick(i) { sel.value = i.id; inp.value = i.label; validity(); close(); sel.dispatchEvent(new Event('change', { bubbles: true })); }
    function mark(n) {
      var lis = list.children;
      if (active >= 0 && lis[active]) { lis[active].setAttribute('aria-selected', 'false'); }
      active = n;
      if (n >= 0 && lis[n]) { lis[n].setAttribute('aria-selected', 'true'); inp.setAttribute('aria-activedescendant', lis[n].id); lis[n].scrollIntoView({ block: 'nearest' }); }
      else inp.removeAttribute('aria-activedescendant');
    }
    function render() {
      var words = inp.value.toLowerCase().split(/\s+/).filter(Boolean);
      shown = items.filter(function (i) { var l = i.label.toLowerCase(); return words.every(function (w) { return l.indexOf(w) !== -1; }); });
      list.textContent = '';
      shown.slice(0, 60).forEach(function (i, n) {
        var li = document.createElement('li'); li.id = uid + '-' + n; li.setAttribute('role', 'option'); li.setAttribute('aria-selected', 'false'); li.textContent = i.label;
        li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
        list.appendChild(li);
      });
      if (!shown.length) { var e = document.createElement('li'); e.className = 'none'; e.textContent = 'Kein Treffer'; list.appendChild(e); }
      list.hidden = false; inp.setAttribute('aria-expanded', 'true'); active = -1;
      live.textContent = shown.length ? shown.length + ' Treffer' : 'Kein Treffer';
      if (shown.length && words.length) mark(0);
    }
    inp.addEventListener('input', function () { sel.value = ''; validity(); render(); });
    inp.addEventListener('focus', function () { if (list.hidden) { inp.select(); render(); } });
    inp.addEventListener('click', function () { if (list.hidden) render(); });
    inp.addEventListener('blur', function () {
      var t = inp.value.trim().toLowerCase();
      var ex = items.filter(function (i) { return i.label.toLowerCase() === t; });
      if (sel.value === '' && ex.length === 1) { sel.value = ex[0].id; inp.value = ex[0].label; }
      validity(); close();
    });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault(); if (list.hidden) render();
        var n = Math.min(shown.length, 60);
        if (n) mark(e.key === 'ArrowDown' ? (active + 1) % n : (active <= 0 ? n - 1 : active - 1));
      } else if (e.key === 'Enter') {
        if (!list.hidden && active >= 0 && shown[active]) { e.preventDefault(); pick(shown[active]); }
        else if (!list.hidden && shown.length === 1) { e.preventDefault(); pick(shown[0]); }
      } else if (e.key === 'Escape' && !list.hidden) { e.preventDefault(); close(); }
    });
    validity();
    if (sel.getAttribute('data-combo') === 'focus' && sel.value === '') inp.focus();
  });
})();

// Einstellungen: Bereiche als Reiter (ohne JavaScript bleibt alles untereinander sichtbar)
(function () {
  var nav = document.getElementById('settings-tabs');
  if (!nav) return;
  var links = nav.querySelectorAll('[data-go]'), panes = document.querySelectorAll('[data-pane]'), hosts = document.querySelectorAll('[data-host]');
  var names = Array.prototype.map.call(links, function (a) { return a.getAttribute('data-go'); });
  function store(v) { try { if (v === undefined) return sessionStorage.getItem('settingsTab'); sessionStorage.setItem('settingsTab', v); } catch (e) { return null; } }
  function show(name) {
    if (names.indexOf(name) < 0) name = names[0];
    panes.forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== name; });
    hosts.forEach(function (f) { f.hidden = !f.querySelector('[data-pane]:not([hidden])'); });
    links.forEach(function (a) { if (a.getAttribute('data-go') === name) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); });
    store(name);
    return name;
  }
  function fromHash() {
    var h = decodeURIComponent(location.hash.replace(/^#/, ''));
    if (!h) return false;
    if (h.indexOf('t-') === 0) { show(h.slice(2)); return true; }
    var el = document.getElementById(h), pane = el && el.closest('[data-pane]');
    if (!pane) return false;
    show(pane.getAttribute('data-pane')); el.scrollIntoView(); return true;
  }
  nav.hidden = false;
  links.forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var n = show(a.getAttribute('data-go'));
      if (history.replaceState) history.replaceState(null, '', '#t-' + n);
    });
  });
  window.addEventListener('hashchange', fromHash);
  if (!fromHash()) show(store() || names[0]);
  // Ungültiges Feld in einem verborgenen Reiter: Reiter öffnen, damit die Browser-Meldung sichtbar wird
  document.addEventListener('invalid', function (e) {
    var pane = e.target.closest && e.target.closest('[data-pane]');
    if (pane && pane.hidden) { show(pane.getAttribute('data-pane')); e.target.focus(); }
  }, true);
})();
