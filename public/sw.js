/**
 * Novarr service worker.
 *
 * Strategy:
 *  - Hashed build files (/build/assets/*): cache-first with no revalidation —
 *    the content hash in the name means a cached copy is never stale. Files
 *    that are no longer in the current Vite manifest — and that no cached
 *    page still loads — are purged on activate (and at most every few hours
 *    while running), so old bundles don't pile up per deploy.
 *  - Covers (/storage/*): the offline cache first (downloaded novels), then
 *    their own cache, capped at COVER_CACHE_MAX (oldest dropped first) and
 *    refreshed in the background at most once a day per cover.
 *  - Other static files (icons, the manifest…): stale-while-revalidate.
 *  - Navigations (HTML pages, incl. Turbo visits): network-first with a
 *    timeout, so a dead-slow connection falls back to the cached copy instead
 *    of hanging; then a generic offline page.
 *  - Chapter and novel pages you open (/chapters/*, /novels/*) are cached
 *    automatically, capped at PAGE_CACHE_MAX entries (oldest dropped first).
 *  - The app shell (SHELL_PAGES: Home = the manifest start_url, Downloads,
 *    the offline page) is precached at install and refreshed on every
 *    successful online visit, in the page cache but exempt from its cap.
 *  - Explicit "Download for offline": the page posts CACHE_URLS and the
 *    worker pre-fetches a whole novel's chapters into OFFLINE_CACHE. That
 *    cache is NOT versioned and never trimmed — a service worker update must
 *    not delete what the reader chose to download.
 *  - Only GET requests are cached; POSTs (read marks, commands) always hit
 *    the network. Offline read-marks are queued client-side (see offline.js).
 *  - Accounts (sign-in on): a redirected response (to /login) is never
 *    stored for a page, the shell or a download. Cached pages carry the
 *    signed-in reader's read flags, so the page script empties the page and
 *    download caches on sign-in and sign-out (offline.js clearAccountCaches).
 *
 * CACHE_VERSION is bumped by hand on any change here. It can't be derived from
 * the Vite manifest hash: this file is served as-is from public/ (no build
 * step), and browsers only install a new worker when its bytes change, so a
 * version computed at runtime would never trigger an update. Build assets are
 * kept in sync with the manifest by pruneBuildAssets() instead.
 */
const CACHE_VERSION = 'novarr-v6';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const PAGE_CACHE = `${CACHE_VERSION}-pages`;
// Covers are unversioned too, so a version bump doesn't throw away every
// cached cover (the cap and the daily refresh keep it fresh).
const COVER_CACHE = 'novarr-covers';
const OFFLINE_CACHE = 'novarr-offline'; // explicitly-downloaded novels (unversioned)
const LEGACY_OFFLINE_CACHE = /^novarr-v\d+-offline$/; // pre-v4 name, migrated on activate
const LEGACY_PAGE_CACHE = /^novarr-v\d+-pages$/; // older versions' page caches, migrated on activate
const LEGACY_STATIC_CACHE = /^novarr-v\d+-static$/; // older versions' asset caches, migrated on activate
const OFFLINE_URL = '/offline';
const MANIFEST_URL = '/build/manifest.json';
// Pages the installed app must open offline: the manifest start_url, the
// Downloads page and the offline fallback.
const SHELL_PAGES = ['/', '/library', OFFLINE_URL];

const PAGE_CACHE_MAX = 60;
const COVER_CACHE_MAX = 300;
const COVER_REFRESH_MS = 24 * 60 * 60 * 1000;
const COVER_FETCHED_AT = 'x-novarr-sw-fetched-at';
const NETWORK_TIMEOUT_MS = 4500;
const PRUNE_INTERVAL_MS = 6 * 60 * 60 * 1000;

self.addEventListener('install', (event) => {
    event.waitUntil(Promise.all([
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(['/icon-192.png', '/logo.svg']))
            .catch(() => {}),
        precacheShell(),
    ]));
    self.skipWaiting();
});

/**
 * Store the shell pages, plus the /build files they load, so the very first
 * offline launch is styled and scripted (the page that registered the worker
 * loaded its bundles before the worker controlled it).
 */
async function precacheShell() {
    try {
        const [pages, assets] = await Promise.all([caches.open(PAGE_CACHE), caches.open(STATIC_CACHE)]);
        const wanted = new Set();
        for (const path of SHELL_PAGES) {
            try {
                const res = await fetch(path, { credentials: 'same-origin' });
                // A redirected response can't answer a navigation later.
                if (!res.ok || res.redirected || !isHtml(res)) continue;
                await pages.put(path, res.clone());
                (await referencedBuildAssets(res)).forEach((p) => wanted.add(p));
            } catch (e) {
                // Offline at install — the next online visit stores it.
            }
        }
        for (const path of wanted) {
            if (await assets.match(path)) continue;
            try {
                const res = await fetch(path, { credentials: 'same-origin' });
                if (res.ok) await assets.put(path, res);
            } catch (e) {
                // Fetched again on first use.
            }
        }
    } catch (e) {
        // Cache Storage unavailable — nothing to precache into.
    }
}

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        await migrateLegacyOfflineCaches();
        await migrateLegacyPageCaches();
        await migrateLegacyStaticCaches();
        const keys = await caches.keys();
        await Promise.all(
            keys
                .filter((k) => !k.startsWith(`${CACHE_VERSION}-`)
                    && k !== OFFLINE_CACHE
                    && k !== COVER_CACHE
                    // Legacy caches are only removed once migrated (a failed
                    // migration is retried on the next activate).
                    && !LEGACY_OFFLINE_CACHE.test(k)
                    && !LEGACY_PAGE_CACHE.test(k)
                    && !LEGACY_STATIC_CACHE.test(k))
                .map((k) => caches.delete(k))
        );
        await pruneBuildAssets();
        await self.clients.claim();
    })());
});

const isLegacyPageCache = (k) => LEGACY_PAGE_CACHE.test(k) && k !== PAGE_CACHE;
const isLegacyStaticCache = (k) => LEGACY_STATIC_CACHE.test(k) && k !== STATIC_CACHE;

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
            // (Shell pages precached at install don't count against the cap.)
            const room = PAGE_CACHE_MAX - (await target.keys()).filter((req) => !isShellPage(new URL(req.url))).length;
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

/**
 * Carry build files and covers from older asset caches (novarr-v4-static…):
 * downloaded and auto-cached pages still load the bundles they were rendered
 * with, so those must outlive the version bump (pruneBuildAssets drops the
 * ones nothing references). Covers move to COVER_CACHE, newest first, within
 * its cap. Shell pages (the offline fallback, Downloads) move to PAGE_CACHE
 * unless install already stored fresh copies — install may have run during a
 * deploy's maintenance window (503s), and the offline page must not be lost.
 * Icons are re-fetched instead.
 */
async function migrateLegacyStaticCaches() {
    const legacy = (await caches.keys()).filter(isLegacyStaticCache);
    if (!legacy.length) return;
    const [assets, covers, pages] = await Promise.all([
        caches.open(STATIC_CACHE), caches.open(COVER_CACHE), caches.open(PAGE_CACHE),
    ]);
    for (const name of legacy) {
        try {
            const old = await caches.open(name);
            const reqs = await old.keys();
            const builds = reqs.filter((req) => new URL(req.url).pathname.startsWith('/build/'));
            const room = COVER_CACHE_MAX - (await covers.keys()).length;
            const coverReqs = room > 0
                ? reqs.filter((req) => isCover(new URL(req.url))).slice(-room)
                : [];
            const shell = reqs.filter((req) => isShellPage(new URL(req.url)));
            for (const [target, list] of [[assets, builds], [covers, coverReqs], [pages, shell]]) {
                for (const req of list) {
                    if (await target.match(req)) continue;
                    const res = await old.match(req);
                    if (!res || (target === pages && !isHtml(res))) continue;
                    await target.put(req, res);
                }
            }
            await caches.delete(name);
        } catch (e) {
            // Left in place, retried next activate.
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

        // Spare anything a cached page still points at — in every cache that
        // can hold HTML (pages incl. the shell, downloads, legacy caches not
        // yet migrated). Covers hold only images. Stops scanning as soon as
        // every candidate is accounted for.
        const pageCaches = (await caches.keys()).filter((k) => k !== COVER_CACHE);
        pageCaches.sort((a, b) => (b === PAGE_CACHE) - (a === PAGE_CACHE));
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

/** Content-hashed Vite output: immutable once cached. */
function isHashedBuildAsset(url) {
    return url.pathname.startsWith('/build/assets/');
}

/** Uploaded images (novel covers) under the public storage link. */
function isCover(url) {
    return url.pathname.startsWith('/storage/');
}

/** Pages worth keeping for offline reading without an explicit download. */
function isAutoCachedPage(url) {
    return /^\/(chapters|novels)\/[^/]+/.test(url.pathname);
}

/** App-shell pages: cached on every visit, never trimmed. Exact path, no query. */
function isShellPage(url) {
    return !url.search && SHELL_PAGES.includes(url.pathname);
}

function isHtml(res) {
    return (res.headers.get('content-type') || '').includes('text/html');
}

let trimChain = Promise.resolve();

/**
 * Store a response and drop the oldest entries beyond `max` (serialised, so
 * concurrent puts can't over- or under-trim). Entries `keep` matches are
 * neither counted nor evicted.
 */
function putCapped(cacheName, max, request, response, keep = () => false) {
    trimChain = trimChain.then(async () => {
        const cache = await caches.open(cacheName);
        // Cache.put replaces an existing entry by appending it, so keys()
        // order is least- to most-recently stored.
        await cache.put(request, response);
        const keys = (await cache.keys()).filter((req) => !keep(new URL(req.url)));
        const excess = keys.length - max;
        for (let i = 0; i < excess; i++) {
            await cache.delete(keys[i]);
        }
    }).catch(() => {});
    return trimChain;
}

/** Store a page; the oldest beyond PAGE_CACHE_MAX go, the shell never does. */
const putPage = (request, response) => putCapped(PAGE_CACHE, PAGE_CACHE_MAX, request, response, isShellPage);

/** Store a cover, stamped with when it was fetched (for the daily refresh). */
async function putCover(request, response) {
    const headers = new Headers(response.headers);
    headers.set(COVER_FETCHED_AT, String(Date.now()));
    const stamped = new Response(await response.blob(), {
        status: response.status,
        statusText: response.statusText,
        headers,
    });
    return putCapped(COVER_CACHE, COVER_CACHE_MAX, request, stamped);
}

/** When a cached cover was fetched: our stamp, else the server's Date header. */
function coverFetchedAt(res) {
    const stamp = Number(res.headers.get(COVER_FETCHED_AT));
    if (stamp) return stamp;
    const date = Date.parse(res.headers.get('date') || '');
    return Number.isNaN(date) ? 0 : date;
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
    if (isAutoCachedPage(url) || isShellPage(url)) {
        event.waitUntil(
            network
                .then((res) => (res.ok && !res.redirected && isHtml(res) ? putPage(request, res.clone()) : null))
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

    // File downloads (the Android app's APK at /app/android): straight to the
    // network, never answered from or stored in a cache, never the offline page.
    if (url.pathname.startsWith('/app/')) {
        return;
    }

    // Hashed build files: cache-first, never revalidated.
    if (isHashedBuildAsset(url)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(async (cache) => {
                const cached = await cache.match(request);
                if (cached) return cached;
                const res = await fetch(request);
                if (res.ok) await cache.put(request, res.clone());
                return res;
            })
        );
        return;
    }

    if (isCover(url)) {
        event.respondWith(respondCover(event, request));
        return;
    }

    // Other static assets: cache-first, revalidate in the background.
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

/** In-memory: last background refresh attempt per cover URL (throttles failures). */
const coverRefreshTried = new Map();

/**
 * Covers: a downloaded novel's copy in OFFLINE_CACHE wins (it is never
 * evicted); then COVER_CACHE, refreshed in the background once a day; then
 * the network, stored in COVER_CACHE.
 */
async function respondCover(event, request) {
    const downloaded = await (await caches.open(OFFLINE_CACHE)).match(request);
    if (downloaded) return downloaded;

    const cached = await (await caches.open(COVER_CACHE)).match(request);
    if (cached) {
        const now = Date.now();
        const tried = coverRefreshTried.get(request.url) || 0;
        if (now - coverFetchedAt(cached) > COVER_REFRESH_MS && now - tried > COVER_REFRESH_MS) {
            coverRefreshTried.set(request.url, now);
            event.waitUntil(
                fetch(request)
                    .then((res) => (res.ok ? putCover(request, res) : null))
                    .catch(() => {})
            );
        }
        return cached;
    }

    const res = await fetch(request);
    // Registered while respondWith is still pending, so waitUntil is allowed.
    if (res.ok) event.waitUntil(putCover(request, res.clone()));
    return res;
}

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
    // /build files the downloaded pages load (the reader module, its CSS…):
    // they only reach the cache otherwise if a chapter was opened online, so
    // a novel downloaded on a fresh device would open offline unscripted.
    // Each distinct asset is checked once per download run.
    const seenAssets = new Set();
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
            // Redirected: signed out meanwhile (sign-in on) — that is the
            // login page, never a stand-in for the chapter.
            if (res.ok && !res.redirected) {
                await cache.put(url, res.clone());
                await cacheBuildAssets(await referencedBuildAssets(res), seenAssets);
            }
        } catch (e) {
            // Skip failures; a partial download is still useful.
        }
        done += 1;
        client?.postMessage({ type: 'CACHE_PROGRESS', done, total: urls.length });
    }
    client?.postMessage({ type: 'CACHE_COMPLETE', total: urls.length });
}

/** Fetch into STATIC_CACHE the paths not cached yet (skipping any in `seen`). */
async function cacheBuildAssets(paths, seen) {
    const assets = await caches.open(STATIC_CACHE);
    for (const path of paths) {
        if (seen.has(path)) continue;
        seen.add(path);
        if (await assets.match(path)) continue;
        try {
            const res = await fetch(path, { credentials: 'same-origin' });
            if (res.ok) await assets.put(path, res);
        } catch (e) {
            // Fetched again on first use online.
        }
    }
}

async function removeUrls(urls) {
    // Also clear the auto-cached copies so "remove download" really frees them.
    const [offline, pages] = await Promise.all([caches.open(OFFLINE_CACHE), caches.open(PAGE_CACHE)]);
    await Promise.all(urls.flatMap((url) => [offline.delete(url), pages.delete(url)]));
}
