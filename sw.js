// Minimaler Service Worker: nur die App-Hülle cachen. Rechnungsdaten kommen immer frisch vom Server.
const CACHE = 'metarechnung-shell-v4';
const SCOPE = self.registration.scope;
const SHELL = ['offline.html', 'style.css', 'app.js', 'icons/icon-192.png', 'icons/icon-512.png'].map((p) => new URL(p, SCOPE).href);

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(SHELL[0])));
    return;
  }
  const u = new URL(req.url);
  const key = u.origin + u.pathname;   // ?v=… (Cache-Busting) ignorieren
  if (SHELL.includes(key)) {
    // Netzwerk zuerst (nach einem Update sofort die neue app.js/style.css), Cache nur als Offline-Rückfall
    e.respondWith(
      fetch(req).then((res) => {
        if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(key, copy)); }
        return res;
      }).catch(() => caches.match(key))
    );
  }
});
