/**
 * Novarr service worker.
 *
 * Strategy:
 *  - Static assets (/build, /storage, icons): cache-first, refreshed in the
 *    background (stale-while-revalidate).
 *  - Navigations (HTML pages, incl. Turbo visits): network-first so you get
 *    fresh pages online; falls back to the cached copy offline, then to a
 *    generic offline page.
 *  - Chapters you open are cached, so they're readable offline afterwards.
 *  - Only GET requests are cached; POSTs (read marks, commands) always hit
 *    the network.
 *
 * Bump CACHE_VERSION on any change here to roll the caches over.
 */
const CACHE_VERSION = 'novarr-v1';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const PAGE_CACHE = `${CACHE_VERSION}-pages`;
const OFFLINE_URL = '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll([
            OFFLINE_URL,
            '/icon-192.png',
            '/logo.svg',
        ])).catch(() => {})
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((k) => !k.startsWith(CACHE_VERSION)).map((k) => caches.delete(k))
        )).then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/storage/')
        || /\.(png|jpg|jpeg|webp|svg|ico|css|js|woff2?)$/.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Only handle same-origin GETs.
    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Static assets: cache-first, revalidate in the background.
    if (isStaticAsset(url)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(async (cache) => {
                const cached = await cache.match(request);
                const network = fetch(request).then((res) => {
                    if (res.ok) cache.put(request, res.clone());
                    return res;
                }).catch(() => cached);
                return cached || network;
            })
        );
        return;
    }

    // Navigations / pages: network-first, fall back to cache then offline page.
    if (request.mode === 'navigate' || request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(request).then((res) => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(PAGE_CACHE).then((cache) => cache.put(request, copy));
                }
                return res;
            }).catch(async () => {
                const cached = await caches.match(request);
                return cached || caches.match(OFFLINE_URL);
            })
        );
    }
});
