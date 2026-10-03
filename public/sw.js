/**
 * Novarr service worker.
 *
 * Strategy:
 *  - Static assets (/build, /storage, icons): cache-first, refreshed in the
 *    background (stale-while-revalidate). Hashed /build/ files that are no
 *    longer in the current Vite manifest — and that no cached page still
 *    loads — are purged on activate (and at most every few hours while
 *    running), so old bundles don't pile up per deploy.
 *  - Navigations (HTML pages, incl. Turbo visits): network-first with a
 *    timeout, so a dead-slow connection falls back to the cached copy instead
 *    of hanging; then a generic offline page.
 *  - Chapter and novel pages you open (/chapters/*, /novels/*) are cached
 *    automatically, capped at PAGE_CACHE_MAX entries (oldest dropped first).
 *  - Explicit "Download for offline": the page posts CACHE_URLS and the
 *    worker pre-fetches a whole novel's chapters into OFFLINE_CACHE. That
 *    cache is NOT versioned and never trimmed — a service worker update must
 *    not delete what the reader chose to download.
 *  - Only GET requests are cached; POSTs (read marks, commands) always hit
 *    the network. Offline read-marks are queued client-side (see offline.js).
 *
 * CACHE_VERSION is bumped by hand on any change here. It can't be derived from
 * the Vite manifest hash: this file is served as-is from public/ (no build
 * step), and browsers only install a new worker when its bytes change, so a
 * version computed at runtime would never trigger an update. Build assets are
 * kept in sync with the manifest by pruneBuildAssets() instead.
 */
const CACHE_VERSION = 'novarr-v4';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const PAGE_CACHE = `${CACHE_VERSION}-pages`;
const OFFLINE_CACHE = 'novarr-offline'; // explicitly-downloaded novels (unversioned)
const LEGACY_OFFLINE_CACHE = /^novarr-v\d+-offline$/; // pre-v4 name, migrated on activate
const LEGACY_PAGE_CACHE = /^novarr-v\d+-pages$/; // older versions' page caches, migrated on activate
const OFFLINE_URL = '/offline';
const MANIFEST_URL = '/build/manifest.json';

const PAGE_CACHE_MAX = 60;
const NETWORK_TIMEOUT_MS = 4500;
const PRUNE_INTERVAL_MS = 6 * 60 * 60 * 1000;

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
    event.waitUntil((async () => {
        await migrateLegacyOfflineCaches();
        await migrateLegacyPageCaches();
        const keys = await caches.keys();
        await Promise.all(
            keys
                .filter((k) => !k.startsWith(`${CACHE_VERSION}-`)
                    && k !== OFFLINE_CACHE
                    // Legacy caches are only removed once migrated (a failed
                    // migration is retried on the next activate).
                    && !LEGACY_OFFLINE_CACHE.test(k)
                    && !LEGACY_PAGE_CACHE.test(k))
                .map((k) => caches.delete(k))
        );
        await pruneBuildAssets();
        await self.clients.claim();
    })());
});

const isLegacyPageCache = (k) => LEGACY_PAGE_CACHE.test(k) && k !== PAGE_CACHE;

/** Move downloads from versioned offline caches (novarr-v3-offline…) into OFFLINE_CACHE. */
async function migrateLegacyOfflineCaches() {
    const legacy = (await caches.keys()).filter((k) => LEGACY_OFFLINE_CACHE.test(k));
    if (!legacy.length) return;
    const target = await caches.open(OFFLINE_CACHE);
    for (const name of legacy) {
        try {
            const old = await caches.open(name);
            for (const req of await old.keys()) {
                if (await target.match(req)) continue;
                const res = await old.match(req);
                if (res) await target.put(req, res);
            }
            await caches.delete(name);
        } catch (e) {
            // Leave the legacy cache in place; caches.match() still finds it.
        }
    }
}

/**
 * Carry auto-cached chapter/novel pages from older page caches
 * (novarr-v3-pages…) into PAGE_CACHE — the most recently stored first, never
 * more than PAGE_CACHE_MAX in total.
 */
async function migrateLegacyPageCaches() {
    const legacy = (await caches.keys()).filter(isLegacyPageCache);
    if (!legacy.length) return;
    const target = await caches.open(PAGE_CACHE);
    for (const name of legacy) {
        try {
            const old = await caches.open(name);
            const reqs = (await old.keys()).filter((req) => isAutoCachedPage(new URL(req.url)));
            // keys() is oldest → newest; keep the newest that still fit.
            const room = PAGE_CACHE_MAX - (await target.keys()).length;
            const keep = room > 0 ? reqs.slice(-room) : [];
            for (const req of keep) {
                if (await target.match(req)) continue;
                const res = await old.match(req);
                if (res) await target.put(req, res);
            }
            await caches.delete(name);
        } catch (e) {
            // Left in place; caches.match() still finds it, retried next activate.
        }
    }
}

let lastPrune = 0;

/** /build/ paths a cached HTML response loads (script/link/preload tags). */
async function referencedBuildAssets(res) {
    if (!res || !isHtml(res)) return [];
    const html = await res.text();
    return html.match(/\/build\/assets\/[^"'\s)<>?#]+/g) || [];
}

/**
 * Delete cached /build/ files that are neither in the current Vite manifest
 * nor referenced by a cached page. Downloaded and auto-cached chapters keep
 * the bundles they were rendered with, so they still load styled and
 * scripted offline after a deploy.
 */
async function pruneBuildAssets() {
    lastPrune = Date.now();
    try {
        const res = await fetch(MANIFEST_URL, { cache: 'no-store', credentials: 'same-origin' });
        if (!res.ok) return;
        const manifest = await res.json();
        const live = new Set();
        for (const entry of Object.values(manifest)) {
            if (entry.file) live.add(`/build/${entry.file}`);
            (entry.css || []).forEach((f) => live.add(`/build/${f}`));
            (entry.assets || []).forEach((f) => live.add(`/build/${f}`));
        }
        if (!live.size) return; // never wipe everything on an odd manifest

        const cache = await caches.open(STATIC_CACHE);
        const candidates = new Map(); // pathname -> cached request
        for (const req of await cache.keys()) {
            const { pathname } = new URL(req.url);
            if (pathname.startsWith('/build/') && !live.has(pathname)) {
                candidates.set(pathname, req);
            }
        }
        if (!candidates.size) return;

        // Spare anything a cached page still points at. Stops scanning as
        // soon as every candidate is accounted for.
        const pageCaches = (await caches.keys()).filter((k) => k === PAGE_CACHE
            || k === OFFLINE_CACHE
            || LEGACY_OFFLINE_CACHE.test(k)
            || isLegacyPageCache(k));
        scan:
        for (const name of pageCaches) {
            const pages = await caches.open(name);
            for (const req of await pages.keys()) {
                for (const path of await referencedBuildAssets(await pages.match(req))) {
                    candidates.delete(path);
                }
                if (!candidates.size) break scan;
            }
        }

        for (const req of candidates.values()) {
            await cache.delete(req);
        }
    } catch (e) {
        // Offline or no manifest — try again next time.
    }
}

function isStaticAsset(url) {
    return url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/storage/')
        || /\.(png|jpg|jpeg|webp|svg|ico|css|js|woff2?)$/.test(url.pathname);
}

/** Pages worth keeping for offline reading without an explicit download. */
function isAutoCachedPage(url) {
    return /^\/(chapters|novels)\/[^/]+/.test(url.pathname);
}

function isHtml(res) {
    return (res.headers.get('content-type') || '').includes('text/html');
}

let trimChain = Promise.resolve();

/** Store a page and drop the oldest entries beyond PAGE_CACHE_MAX (serialised). */
function putPage(request, response) {
    trimChain = trimChain.then(async () => {
        const cache = await caches.open(PAGE_CACHE);
        // Cache.put replaces an existing entry by appending it, so keys()
        // order is least- to most-recently stored.
        await cache.put(request, response);
        const keys = await cache.keys();
        const excess = keys.length - PAGE_CACHE_MAX;
        for (let i = 0; i < excess; i++) {
            await cache.delete(keys[i]);
        }
    }).catch(() => {});
    return trimChain;
}

const TIMED_OUT = Symbol('timed-out');

/** Resolves (never rejects, so no stray unhandled rejection) after ms. */
function timeout(ms) {
    return new Promise((resolve) => setTimeout(() => resolve(TIMED_OUT), ms));
}

function networkFirst(event, request, url) {
    const network = fetch(request);
    network.catch(() => {}); // handled below; avoid unhandled rejections after a timeout

    // Registered synchronously, while the fetch event is still dispatching:
    // calling waitUntil() later (after respondWith settled, e.g. when the
    // timeout served the cached copy) throws InvalidStateError, and the page
    // cache would never refresh on a slow connection. The clone is taken in
    // the first reaction to the response, before the page reads the body.
    if (isAutoCachedPage(url)) {
        event.waitUntil(
            network
                .then((res) => (res.ok && isHtml(res) ? putPage(request, res.clone()) : null))
                .catch(() => {})
        );
    }

    return respondNetworkFirst(request, network);
}

async function respondNetworkFirst(request, network) {
    try {
        const res = await Promise.race([network, timeout(NETWORK_TIMEOUT_MS)]);
        if (res !== TIMED_OUT) return res;
    } catch (e) {
        // Offline — fall through to the cache.
    }

    // Slow or offline: a cached copy wins if we have one…
    const cached = await caches.match(request);
    if (cached) return cached;
    // …otherwise keep waiting for a merely slow network.
    try {
        return await network;
    } catch (err) {
        return (await caches.match(OFFLINE_URL)) || Response.error();
    }
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
        event.respondWith(networkFirst(event, request, url));
        if (request.mode === 'navigate' && Date.now() - lastPrune > PRUNE_INTERVAL_MS) {
            event.waitUntil(pruneBuildAssets());
        }
    }
});

// ---- Explicit offline downloads ----
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
            // Tagged so the server knows this is a background download, not
            // the reader opening the chapter — it must not mark it read.
            // (Offline, the cached page queues the read-mark when opened.)
            const res = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Novarr-Fetch': 'offline' },
            });
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
    // Also clear the auto-cached copies so "remove download" really frees them.
    const [offline, pages] = await Promise.all([caches.open(OFFLINE_CACHE), caches.open(PAGE_CACHE)]);
    await Promise.all(urls.flatMap((url) => [offline.delete(url), pages.delete(url)]));
}
