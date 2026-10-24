// Bestätigungsdialoge, Auswahl beim Klick, Service Worker (ohne Inline-Skripte, damit die CSP "script-src 'self'" greift)
document.addEventListener('submit', function (e) {
  var m = e.target.getAttribute && e.target.getAttribute('data-confirm');
  if (m && !window.confirm(m)) e.preventDefault();
});
document.addEventListener('click', function (e) {
  if (e.target.hasAttribute && e.target.hasAttribute('data-select')) e.target.select();
});
if ('serviceWorker' in navigator && document.body.dataset.sw) {
  navigator.serviceWorker.register(document.body.dataset.sw);
}

(function () {
  var body = document.querySelector('#items tbody');
  if (!body) return;
  var kl = document.body.dataset.kl === '1';
  var fmt = function (n) { return n.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }); };

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
    tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    tr.querySelector('[name="qty[]"]').value = '1';
    body.appendChild(tr);
    return tr;
  }
  document.getElementById('addrow').addEventListener('click', function () { newRow(); recalc(); });

  // Artikel aus der Artikelliste als Position einfügen
  var art = document.getElementById('addarticle');
  if (art) {
    art.addEventListener('change', function () {
      var o = art.options[art.selectedIndex];
      if (!o || !o.value) return;
      var tr = newRow();
      tr.querySelector('[name="desc[]"]').value = o.dataset.desc || '';
      tr.querySelector('[name="qty[]"]').value = '1';
      tr.querySelector('[name="price[]"]').value = o.dataset.price || '';
      var u = tr.querySelector('[name="unit[]"]'); if (u && o.dataset.unit) u.value = o.dataset.unit;
      var v = tr.querySelector('[name="vat[]"]');
      if (v && o.dataset.vat !== undefined) {
        v.value = o.dataset.vat;
        if (v.value !== o.dataset.vat) { var op = document.createElement('option'); op.value = op.textContent = o.dataset.vat; v.appendChild(op); v.value = o.dataset.vat; }
      }
      art.selectedIndex = 0;
      recalc();
    });
  }
  body.addEventListener('click', function (e) {
    if (e.target.classList.contains('rm') && body.children.length > 1) { e.target.closest('tr').remove(); recalc(); }
  });
  body.addEventListener('input', recalc);
  recalc();
})();
