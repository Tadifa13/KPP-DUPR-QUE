/**
 * KAMRYNNE QUE — service worker.
 *
 * Bump VERSION whenever assets/app.css, assets/app.js or offline.html change.
 * The browser byte-compares this file on every navigation and installs a new
 * worker when it differs, so changing VERSION is what retires the old cache.
 *
 * Strategy:
 *   - shell assets  cache-first   (they never change without a VERSION bump)
 *   - GET pages     network-first, falling back to the last good copy, then
 *                   to offline.html
 *   - POSTs         never cached and never replayed. A write that did not
 *                   reach the server MUST NOT silently succeed — the organizer
 *                   is told to retry rather than being shown a fake result.
 */

const VERSION = 'que-v1.2.0';
const SHELL = [
  './offline.html',
  './assets/app.css',
  './assets/app.js',
  './assets/brand/logo-96.png',
  './assets/art-paddle.svg',
  './assets/fonts/BarlowCondensed-700.woff2',
  './assets/fonts/Barlow-400.woff2',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(VERSION)
      .then((cache) => cache.addAll(SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Only handle our own origin.
  if (url.origin !== self.location.origin) return;

  // A write must reach the server or fail loudly. Never queue, never fake it.
  if (request.method !== 'GET') {
    event.respondWith(
      fetch(request).catch(() => new Response(
        offlineWritePage(),
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
      ))
    );
    return;
  }

  // Shell assets: cache-first.
  if (/\.(css|js|svg|png|ico|woff2)$/.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then((hit) => hit || fetch(request).then((res) => {
        const copy = res.clone();
        caches.open(VERSION).then((c) => c.put(request, copy));
        return res;
      }))
    );
    return;
  }

  // Pages: network-first so the organizer always sees live state when online.
  event.respondWith(
    fetch(request)
      .then((res) => {
        if (res && res.status === 200) {
          const copy = res.clone();
          caches.open(VERSION).then((c) => c.put(request, copy));
        }
        return res;
      })
      .catch(() => caches.match(request).then((hit) => {
        if (hit) return hit;
        return caches.match('./offline.html');
      }))
  );
});

function offlineWritePage() {
  return '<!doctype html><meta charset="utf-8">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>Not saved — KAMRYNNE QUE</title>'
    + '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;'
    + 'background:#071c15;color:#e8f5ee;font:16px/1.6 -apple-system,BlinkMacSystemFont,'
    + '"Segoe UI",Roboto,sans-serif;padding:24px;text-align:center}'
    + 'div{max-width:34ch}h1{font-size:20px;margin:0 0 10px;color:#fcd34d}'
    + 'p{color:#9dc4b1;margin:0 0 18px}'
    + 'a{display:inline-block;min-height:46px;line-height:46px;padding:0 20px;'
    + 'border-radius:9px;background:#34d399;color:#04120d;font-weight:700;'
    + 'text-decoration:none}</style>'
    + '<div><h1>That did not save</h1>'
    + '<p>The server could not be reached, so this change was not recorded. '
    + 'Nothing was lost &mdash; go back and try again once you are connected.</p>'
    + '<a href="javascript:history.back()">Go back</a></div>';
}
