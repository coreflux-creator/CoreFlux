/**
 * CoreFlux PWA service worker.
 *
 * Strategy:
 *   • App shell (HTML/JS/CSS bundles): cache-first, fall through to network.
 *   • API requests (/api/*): network-only — never cache business data.
 *   • Static assets: cache-first.
 *
 * Bumping CACHE_VERSION invalidates all caches. The Vite build hash in
 * the bundle filename already provides invalidation for JS/CSS, so we
 * primarily rely on this SW for offline shell + faster repeat loads.
 */

const CACHE_VERSION = 'coreflux-BAnYgEuO';
const APP_SHELL = [
  '/',
  '/spa.php',
  '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => cache.addAll(APP_SHELL).catch(() => null))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // API / auth — never cache.
  if (url.pathname.startsWith('/api/')) return;

  // Bundles + spa-assets — cache-first.
  if (url.pathname.startsWith('/spa-assets/') || url.pathname.endsWith('.js') || url.pathname.endsWith('.css')) {
    event.respondWith(
      caches.open(CACHE_VERSION).then(async (cache) => {
        const cached = await cache.match(req);
        if (cached) return cached;
        try {
          const fresh = await fetch(req);
          if (fresh.ok) cache.put(req, fresh.clone());
          return fresh;
        } catch (e) {
          return cached || Response.error();
        }
      })
    );
    return;
  }

  // HTML navigations — network-first with cached fallback.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/spa.php'))
    );
  }
});
