/**
 * Trackie — Service Worker
 * - App shell precache + offline fallback
 * - Static assets: stale-while-revalidate (ignores ?v= cache-busting)
 * - Navigations: network-first → offline.html
 * - API & cross-origin: network-first / runtime cache
 *
 * Scope is derived from the SW's own location, so it works whether the app
 * is served from "/" (live) or "/Trackie/" (local) without edits.
 */
const VERSION = 'trackie-v5';   // bumped: Phase 6-7 (analytics, weekly review, landing page, CSP)
const STATIC  = `static-${VERSION}`;
const RUNTIME = `runtime-${VERSION}`;
const PAGES   = `pages-${VERSION}`;   // last-seen HTML pages for offline viewing

// Base path the SW is registered under, e.g. "/" or "/Trackie/"
const BASE = self.location.pathname.replace(/sw\.js$/, '');

/* Pages load the .min builds via assetUrl(), so those are what must be primed
   for offline — precaching app.css/app.js meant caching files nothing ever
   requests while the files actually served went uncached. Both variants are
   listed because assetUrl() falls back to the unminified source whenever a
   build is missing or stale, and cache.add() failures are swallowed
   individually below, so listing a file that doesn't exist costs nothing. */
const PRECACHE = [
  `${BASE}offline.html`,
  `${BASE}assets/css/app.min.css`,
  `${BASE}assets/js/app.min.js`,
  `${BASE}assets/css/dashboard.min.css`,
  `${BASE}assets/css/app.css`,
  `${BASE}assets/js/app.js`,
  `${BASE}assets/css/dashboard.css`,
  `${BASE}assets/images/icon-192.png`,
  `${BASE}assets/images/logo.png`,
  `${BASE}manifest.json`,
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC)
      // addAll is atomic — use individual puts so one 404 doesn't abort install
      .then(cache => Promise.all(
        PRECACHE.map(url => cache.add(url).catch(() => null))
      ))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => ![STATIC, RUNTIME, PAGES].includes(k)).map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;            // never cache POST/PUT/DELETE

  const url = new URL(req.url);

  // Browser extensions (chrome-extension:// …) also pass through here, and
  // the Cache API rejects any non-http(s) scheme.
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

  // Video/audio stream as 206 range responses, which the Cache API refuses;
  // caching whole clips would also bloat storage. Let the browser handle them.
  if (req.headers.has('range') || req.destination === 'video' || req.destination === 'audio') return;

  // Never cache dynamic API calls — always hit the network.
  if (url.pathname.includes('/api/')) return;

  // Page navigations: network-first; cache each successful page so it can be
  // viewed offline later; when offline, serve the last-seen copy of THIS page,
  // else any cached page, else the offline screen. (Read-only offline view —
  // see MD/OFFLINE.md for the write-sync roadmap.)
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then(res => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(PAGES).then(c => c.put(req, copy));
          }
          return res;
        })
        .catch(async () => {
          return (await caches.match(req, { ignoreSearch: true }))
              || (await caches.match(`${BASE}offline.html`));
        })
    );
    return;
  }

  // Same-origin assets: network-first so deploys are picked up immediately,
  // falling back to cache when offline. ignoreSearch so a cached "app.css"
  // still answers "app.css?v=123" when there's no connection.
  if (url.origin === self.location.origin) {
    event.respondWith(
      fetch(req).then(res => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(STATIC).then(cache => cache.put(req, copy));
        }
        return res;
      }).catch(() => caches.match(req, { ignoreSearch: true }))
    );
    return;
  }

  // Cross-origin (CDN fonts/libs): cache-first runtime cache.
  event.respondWith(
    caches.open(RUNTIME).then(async cache => {
      const cached = await cache.match(req);
      if (cached) return cached;
      try {
        const res = await fetch(req);
        if (res && (res.ok || res.type === 'opaque')) cache.put(req, res.clone());
        return res;
      } catch {
        return cached || Response.error();
      }
    })
  );
});

// Allow the page to tell a waiting SW to activate immediately.
self.addEventListener('message', e => {
  if (e.data === 'SKIP_WAITING') self.skipWaiting();
});
