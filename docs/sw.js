/* ===========================================================================
   KAMRYNNE QUE — browser build service worker.

   Strategy is split by how the file behaves, not by convenience:

     - the page and its code (HTML, JS, CSS, manifest) are NETWORK-FIRST with a
       cache fallback. Cache-first here meant a returning visitor kept getting
       the previous build and needed two refreshes before an update appeared —
       the app looked broken or missing features that had already shipped.
       Network-first costs one request when online and still works offline.

     - fonts and images are CACHE-FIRST. They are large, they never change
       without a filename or VERSION change, and re-fetching them every visit
       is waste.

   Bump VERSION whenever anything in SHELL changes.
   =========================================================================== */

const VERSION = 'que-static-v2.0.0';

const SHELL = [
  './', './index.html', './manifest.webmanifest',
  './assets/app.css', './assets/art-paddle.svg',
  './assets/brand/logo-96.png', './assets/brand/logo-180.png', './assets/brand/logo-512.png',
  './assets/fonts/BarlowCondensed-700.woff2', './assets/fonts/BarlowCondensed-600.woff2',
  './assets/fonts/Barlow-400.woff2', './assets/fonts/Barlow-500.woff2', './assets/fonts/Barlow-600.woff2',
  './js/engine.js', './js/store.js', './js/ui.js', './js/court3d.js',
];

/** Immutable by nature: only ever replaced by a new VERSION. */
const CACHE_FIRST = /\.(woff2|png|svg|ico|jpg|jpeg)$/i;

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
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (CACHE_FIRST.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then((hit) => hit || fetch(request).then((res) => {
        if (res && res.status === 200) {
          const copy = res.clone();
          caches.open(VERSION).then((c) => c.put(request, copy));
        }
        return res;
      }))
    );
    return;
  }

  // Everything else: try the network, fall back to cache, then to the shell.
  event.respondWith(
    fetch(request)
      .then((res) => {
        if (res && res.status === 200) {
          const copy = res.clone();
          caches.open(VERSION).then((c) => c.put(request, copy));
        }
        return res;
      })
      .catch(() => caches.match(request)
        .then((hit) => hit || caches.match('./index.html')))
  );
});
