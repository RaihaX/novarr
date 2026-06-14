/**
 * Novarr service worker.
 *
 * Strategy:
 *  - Static assets (/build, /storage, icons): cache-first, refreshed in the
 *    background (stale-while-revalidate).
 *  - Navigations (HTML pages, incl. Turbo visits): network-first so you get
 *    fresh pages online; falls back to the cached copy offline, then a
 *    generic offline page.
 *  - Chapters you open are cached automatically, so they're readable offline.
 *  - Explicit "Download for offline" (pass 2): the page posts CACHE_URLS and
 *    the worker pre-fetches a whole novel's chapters into OFFLINE_CACHE.
 *  - Only GET requests are cached; POSTs (read marks, commands) always hit
 *    the network. Offline read-marks are queued client-side (see offline.js).
 *
 * Bump CACHE_VERSION on any change here to roll the caches over.
 */
const CACHE_VERSION = 'novarr-v2';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const PAGE_CACHE = `${CACHE_VERSION}-pages`;
const OFFLINE_CACHE = `${CACHE_VERSION}-offline`; // explicitly-downloaded novels
const OFFLINE_URL = '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll([
            OFFLINE_URL,
            '/library',
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
    // caches.match() searches every cache, so explicitly-downloaded chapters
    // in OFFLINE_CACHE are found here when the network is gone.
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

// ---- Explicit offline downloads (pass 2) ----
// The page drives these via postMessage; the worker fetches each URL straight
// from the network (SW-initiated fetches don't re-enter the fetch handler) and
// stores them in OFFLINE_CACHE.
self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type === 'CACHE_URLS' && Array.isArray(data.urls)) {
        event.waitUntil(cacheUrls(data.urls, event.source));
    } else if (data.type === 'REMOVE_URLS' && Array.isArray(data.urls)) {
        event.waitUntil(removeUrls(data.urls));
    }
});

async function cacheUrls(urls, client) {
    const cache = await caches.open(OFFLINE_CACHE);
    let done = 0;
    for (const url of urls) {
        try {
            const res = await fetch(url, { credentials: 'same-origin' });
            if (res.ok) await cache.put(url, res.clone());
        } catch (e) {
            // Skip failures; a partial download is still useful.
        }
        done += 1;
        client?.postMessage({ type: 'CACHE_PROGRESS', done, total: urls.length });
    }
    client?.postMessage({ type: 'CACHE_COMPLETE', total: urls.length });
}

async function removeUrls(urls) {
    const cache = await caches.open(OFFLINE_CACHE);
    await Promise.all(urls.map((url) => cache.delete(url)));
}
