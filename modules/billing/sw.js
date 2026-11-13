/*
 * Storefront service worker.
 *
 * It makes the site installable and shows a friendly page when the visitor is offline. It never keeps
 * pages: carts, invoices, accounts and tickets always come from the server. Only the shared look
 * (css, images, fonts the site itself serves) is kept, and refreshed in the background.
 */
const VERSION = 'gsw-pwa-1';
const SHELL = VERSION + '-shell';
const OFFLINE_URL = new URL('offline.html', self.registration.scope).href;
const ASSET_PATHS = ['/css/', '/images/', '/js/'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(SHELL).then((cache) => cache.add(OFFLINE_URL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key.indexOf('gsw-pwa-') === 0 && key.indexOf(VERSION) !== 0).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
    return;
  }

  const scopePath = new URL(self.registration.scope).pathname;
  const inAssets = ASSET_PATHS.some((p) => url.pathname.indexOf(scopePath.replace(/\/$/, '') + p) === 0);
  if (!inAssets) return;
  event.respondWith(
    caches.open(VERSION + '-assets').then((cache) =>
      cache.match(request).then((cached) => {
        const refresh = fetch(request).then((response) => {
          if (response && response.ok) cache.put(request, response.clone());
          return response;
        });
        return cached || refresh;
      })
    )
  );
});
