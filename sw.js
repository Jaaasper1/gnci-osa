// GNC-OSA Portal service worker.
// Pages (login, voting, complaints) are NEVER cached - they are private and must always be live.
// Only static assets are cached, plus an offline fallback page.
const VERSION = 'osa-pwa-v17';
const PRECACHE = ['offline.html', 'assets/css/style.css', 'assets/img/osa-logo.jpg'];

// Built-in English fallback: used if offline.html is missing from the server or was not cached, so users never fall back to the browser's own (localized) offline page.
const OFFLINE_FALLBACK = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Offline - GNC-OSA Portal</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#6B1A2A;color:#fff;font-family:system-ui,sans-serif;text-align:center;padding:24px}h1{font-size:22px;margin:0 0 8px}p{opacity:.85;line-height:1.5;margin:0 0 20px}button{background:#fff;color:#6B1A2A;border:0;border-radius:8px;padding:12px 24px;font-size:15px;font-weight:600;cursor:pointer}</style></head><body><div><h1>No internet connection</h1><p>An internet connection is needed for voting, complaints, and login. Please try again once you are connected.</p><button onclick="location.reload()">Try again</button></div></body></html>';
function offlineResponse() {
  return caches.match('offline.html').then(r => r || new Response(OFFLINE_FALLBACK, { headers: { 'Content-Type': 'text/html; charset=utf-8' } }));
}

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION).then(c => Promise.all(PRECACHE.map(u => c.add(u).catch(() => {})))));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;                       // forms / votes always go to the network
  const url = new URL(req.url);

  // Page navigations: network only, offline page if the network fails.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => offlineResponse()));
    return;
  }

  if (url.origin !== location.origin) return;             // CDN fonts/icons: let the browser handle them
  if (/\/(uploads|includes)\//.test(url.pathname)) return; // private uploads never cached
  if (/\.(mp4|webm|php)$/i.test(url.pathname)) return;     // big video and dynamic endpoints stay live
  if (/\/assets\/icons\//.test(url.pathname) || /manifest\.webmanifest$/.test(url.pathname)) return; // icons + manifest always fresh

  // Static assets: stale-while-revalidate.
  if (/\/assets\//.test(url.pathname)) {
    e.respondWith(caches.open(VERSION).then(cache =>
      cache.match(req).then(hit => {
        const net = fetch(req).then(res => { if (res.ok) cache.put(req, res.clone()); return res; }).catch(() => hit);
        return hit || net;
      })));
  }
});