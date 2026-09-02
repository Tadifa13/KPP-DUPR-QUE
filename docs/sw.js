/* KAMRYNNE QUE — browser build service worker.
   Everything is static, so the whole app is precached and served cache-first.
   Once this has run, the app opens with no network at all. */
const VERSION = 'que-static-v1.1.0';
const SHELL = [
  './', './index.html', './manifest.webmanifest',
  './assets/app.css', './assets/art-paddle.svg',
  './assets/brand/logo-96.png', './assets/brand/logo-180.png', './assets/brand/logo-512.png',
  './assets/fonts/BarlowCondensed-700.woff2', './assets/fonts/BarlowCondensed-600.woff2',
  './assets/fonts/Barlow-400.woff2', './assets/fonts/Barlow-500.woff2', './assets/fonts/Barlow-600.woff2',
  './js/engine.js', './js/store.js', './js/ui.js', './js/court3d.js',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION)
    .then((c) => c.addAll(SHELL))
    .then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  if (url.origin !== self.location.origin) return;

  e.respondWith(
    caches.match(e.request).then((hit) => hit || fetch(e.request).then((res) => {
      if (res && res.status === 200) {
        const copy = res.clone();
        caches.open(VERSION).then((c) => c.put(e.request, copy));
      }
      return res;
    }).catch(() => caches.match('./index.html')))
  );
});
