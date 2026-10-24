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

  document.getElementById('addrow').addEventListener('click', function () {
    var tr = body.lastElementChild.cloneNode(true);
    tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    tr.querySelector('[name="qty[]"]').value = '1';
    body.appendChild(tr);
    recalc();
  });
  body.addEventListener('click', function (e) {
    if (e.target.classList.contains('rm') && body.children.length > 1) { e.target.closest('tr').remove(); recalc(); }
  });
  body.addEventListener('input', recalc);
  recalc();
})();
