/**
 * Offline library + read-state sync queue (PWA pass 2).
 *
 * Two responsibilities:
 *  1. Download a novel for offline reading — fetch its chapter manifest and
 *     ask the service worker to pre-cache every chapter page + cover. A record
 *     of what's downloaded lives in IndexedDB so the offline library can render
 *     with no connection.
 *  2. Queue read-state writes (mark read / mark-to-here) made while offline and
 *     replay them when the connection returns. iOS Safari has no Background
 *     Sync, so the flush is driven from the page on `online` + next app open.
 */

import { deviceHeaders } from './device.js';

const DB_NAME = 'novarr-offline';
const DB_VERSION = 1;

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains('novels')) {
                db.createObjectStore('novels', { keyPath: 'id' });
            }
            if (!db.objectStoreNames.contains('queue')) {
                db.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

function idbReq(store, mode, fn) {
    return openDb().then((db) => new Promise((resolve, reject) => {
        const os = db.transaction(store, mode).objectStore(store);
        const r = fn(os);
        r.onsuccess = () => resolve(r.result);
        r.onerror = () => reject(r.error);
    }));
}

const idbPut = (store, value) => idbReq(store, 'readwrite', (os) => os.put(value));
const idbAdd = (store, value) => idbReq(store, 'readwrite', (os) => os.add(value));
const idbGet = (store, key) => idbReq(store, 'readonly', (os) => os.get(key));
const idbGetAll = (store) => idbReq(store, 'readonly', (os) => os.getAll());
const idbDelete = (store, key) => idbReq(store, 'readwrite', (os) => os.delete(key));

// ---- Service worker messaging ----

async function activeWorker() {
    if (!('serviceWorker' in navigator)) return null;
    const reg = await navigator.serviceWorker.ready;
    return reg.active;
}

function sendUrlsToSw(type, urls, onProgress) {
    return new Promise(async (resolve, reject) => {
        const sw = await activeWorker();
        if (!sw) {
            reject(new Error('Offline storage is unavailable here.'));
            return;
        }
        const onMsg = (e) => {
            const d = e.data || {};
            if (d.type === 'CACHE_PROGRESS' && onProgress) onProgress(d.done, d.total);
            if (d.type === 'CACHE_COMPLETE') {
                navigator.serviceWorker.removeEventListener('message', onMsg);
                resolve(d.total);
            }
        };
        navigator.serviceWorker.addEventListener('message', onMsg);
        sw.postMessage({ type, urls });
    });
}

// ---- Public: downloads / library ----

/**
 * Download some or all of a novel's chapters for offline reading.
 * `opts` selects the range:
 *   { scope: 'all' }                       every downloaded chapter
 *   { scope: 'unread' }                    only unread chapters
 *   { scope: 'unread-next', limit: 100 }   the next N unread (reading order)
 *   { scope: 'range', from, to }           a chapter-number range
 * Downloads merge into any existing offline copy (union by chapter id).
 */
export async function downloadNovel(id, opts = {}, onProgress) {
    // Started before the first await, while the click's user activation is
    // still live (Firefox asks the reader). Not awaited: a pending prompt
    // must not hold up the download.
    requestPersistence().catch(() => {});

    const params = new URLSearchParams();
    if (opts.scope === 'unread' || opts.scope === 'unread-next') params.set('unread', '1');
    if (opts.scope === 'unread-next') params.set('limit', String(opts.limit || 100));
    if (opts.scope === 'range') {
        if (opts.from !== undefined && opts.from !== null && opts.from !== '') params.set('from', String(opts.from));
        if (opts.to !== undefined && opts.to !== null && opts.to !== '') params.set('to', String(opts.to));
    }
    const qs = params.toString();

    const res = await fetch(`/novels/${id}/offline-manifest${qs ? `?${qs}` : ''}`, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('Could not load the chapter list.');
    const manifest = await res.json();

    if (!manifest.chapters.length) throw new Error('No matching chapters to download.');

    const urls = manifest.chapters.map((c) => c.url);
    urls.unshift(manifest.url);                 // the novel page itself
    if (manifest.cover) urls.push(manifest.cover);

    await sendUrlsToSw('CACHE_URLS', urls, onProgress);

    // Merge with any previously-downloaded chapters for this novel.
    const existing = await idbGet('novels', id);
    const byId = new Map();
    (existing?.chapters || []).forEach((c) => byId.set(c.id, c));
    manifest.chapters.forEach((c) => byId.set(c.id, c));
    const merged = [...byId.values()].sort(
        (a, b) => (a.book - b.book) || (parseFloat(a.chapter) - parseFloat(b.chapter))
    );

    await idbPut('novels', {
        id: manifest.id,
        name: manifest.name,
        author: manifest.author,
        cover: manifest.cover,
        url: manifest.url,
        chapters: merged,
        chapterCount: merged.length,
        downloadedAt: Date.now(),
    });
    return { ...manifest, addedCount: manifest.chapters.length, cachedCount: merged.length };
}

export async function removeNovel(id) {
    const rec = await idbGet('novels', id);
    if (rec) {
        const urls = [rec.url, ...(rec.chapters || []).map((c) => c.url)];
        if (rec.cover) urls.push(rec.cover);
        const sw = await activeWorker();
        sw?.postMessage({ type: 'REMOVE_URLS', urls });
    }
    await idbDelete('novels', id);
}

// ---- Public: storage persistence / quota ----

/** localStorage key holding the outcome of our persist() request. */
export const PERSIST_KEY = 'novarr:storage-persist';

function rememberPersist(result) {
    try {
        localStorage.setItem(PERSIST_KEY, result);
    } catch (e) {
        // Private mode / blocked storage: the Downloads page re-checks live.
    }
    return result;
}

function rememberedPersist() {
    try {
        return localStorage.getItem(PERSIST_KEY);
    } catch (e) {
        return null;
    }
}

/**
 * Ask the browser to exempt this origin's storage from eviction, so
 * downloads survive storage pressure. Asked once (the first download);
 * resolves to 'granted' | 'denied' | 'unsupported' and remembers it.
 */
export async function requestPersistence() {
    const storage = typeof navigator !== 'undefined' ? navigator.storage : undefined;
    if (!storage?.persist) return rememberPersist('unsupported');
    if (storage.persisted && await storage.persisted()) return rememberPersist('granted');
    const asked = rememberedPersist();
    if (asked === 'denied' || asked === 'granted') return asked;
    return rememberPersist((await storage.persist()) ? 'granted' : 'denied');
}

/**
 * Storage used/quota and persistence state for the Downloads page.
 * `persistence` is 'granted' | 'denied' | 'unsupported' | 'unknown' (not
 * asked yet); usage/quota are bytes, or null when estimate() is missing.
 */
export async function storageStatus() {
    const storage = typeof navigator !== 'undefined' ? navigator.storage : undefined;
    let usage = null;
    let quota = null;
    if (storage?.estimate) {
        try {
            ({ usage = null, quota = null } = await storage.estimate());
        } catch (e) {
            // Leave unknown.
        }
    }

    let persistence;
    if (!storage?.persist || !storage?.persisted) {
        persistence = 'unsupported';
    } else {
        let granted = false;
        try {
            granted = await storage.persisted();
        } catch (e) {
            granted = false;
        }
        // A grant can be withdrawn (site data cleared): the live answer wins.
        persistence = granted ? 'granted' : (rememberedPersist() === 'denied' ? 'denied' : 'unknown');
    }
    return { usage, quota, persistence };
}

export const getLibrary = () => idbGetAll('novels');
export const getNovel = (id) => idbGet('novels', id);
export const isDownloaded = (id) => idbGet('novels', id).then((r) => !!r);

// ---- Public: read-state sync queue ----

/** Queued writes older than this are dropped instead of replayed. */
export const QUEUE_TTL_MS = 30 * 24 * 60 * 60 * 1000;

/** Fired on `window` whenever the queue size may have changed: detail = { size }. */
export const QUEUE_EVENT = 'novarr:offline-queue';

const idbCount = (store) => idbReq(store, 'readonly', (os) => os.count());

/** Number of read-state writes waiting to be replayed. */
export async function offlineQueueSize() {
    try {
        return await idbCount('queue');
    } catch (e) {
        return 0;
    }
}

async function emitQueueSize() {
    if (typeof window === 'undefined' || typeof window.dispatchEvent !== 'function') return;
    const size = await offlineQueueSize();
    window.dispatchEvent(new CustomEvent(QUEUE_EVENT, { detail: { size } }));
}

const pathOf = (url) => {
    try {
        return new URL(url, 'http://localhost').pathname;
    } catch (e) {
        return String(url);
    }
};

const PROGRESS_PATH = /^\/chapters\/(\d+)\/progress$/;
const CHAPTER_PATH = /^\/chapters\/(\d+)\//;
const BULK_READ_PATH = '/chapters/bulk-read';
/** Read-state endpoints whose replayed writes carry their `age`. */
const READ_STATE_PATH = /^\/chapters\/(\d+\/(progress|toggle-read|read-through)|bulk-read)$/;

/** A scroll-position save: only `progress`, no read mark. */
function isProgressWrite(url, body) {
    return PROGRESS_PATH.test(pathOf(url))
        && body != null && body.progress !== undefined && !body.read;
}

/** An idempotent "mark read" (progress endpoint or bulk-read with read:true). */
function isReadMark(url, body) {
    const path = pathOf(url);
    return body?.read === true && (PROGRESS_PATH.test(path) || path === BULK_READ_PATH);
}

/** Chapter ids a queued write touches (from the path, or bulk-read ids). */
function chaptersOf(item) {
    const path = pathOf(item.url);
    if (path === BULK_READ_PATH) return (item.body?.ids || []).map(String);
    const m = path.match(CHAPTER_PATH);
    return m ? [m[1]] : [];
}

const sameRequest = (item, url, method, body) => pathOf(item.url) === pathOf(url)
    && item.method === method
    && JSON.stringify(item.body ?? null) === JSON.stringify(body ?? null);

let enqueueChain = Promise.resolve();

/**
 * Park a write. A newer scroll position for the same chapter replaces the
 * queued one (removed, then appended, so it still replays after anything
 * queued in between); a read mark identical to one already queued, with
 * nothing touching that chapter queued after it, is not added twice.
 * Everything else keeps plain queue order. Serialised so two quick saves
 * can't both miss each other.
 */
function enqueue(url, method, body) {
    const run = enqueueChain.then(async () => {
        const items = await idbGetAll('queue');
        if (isProgressWrite(url, body)) {
            for (const item of items) {
                if (item.method === method && pathOf(item.url) === pathOf(url) && isProgressWrite(item.url, item.body)) {
                    await idbDelete('queue', item.id);
                }
            }
        } else if (isReadMark(url, body)) {
            const target = { url, body };
            const idx = items.findIndex((item) => sameRequest(item, url, method, body));
            if (idx !== -1) {
                const touched = new Set(chaptersOf(target));
                const changedSince = items.slice(idx + 1).some((item) => !isProgressWrite(item.url, item.body)
                    && chaptersOf(item).some((c) => touched.has(c)));
                if (!changedSince) return;
            }
        }
        await idbAdd('queue', { url, method, body, ts: Date.now() });
    });
    enqueueChain = run.catch(() => {});
    return run.then(() => {
        emitQueueSize();
        return { success: true, queued: true };
    });
}

/**
 * The body to replay. Read-state writes (progress, toggle-read, read-through,
 * bulk-read) carry `age`: whole milliseconds since they were queued. The
 * server dates the write now - age, so its order against other writes holds
 * whatever this device's clock says (a relative age needs no clock sync).
 */
export function replayBody(item, now = Date.now()) {
    if (READ_STATE_PATH.test(pathOf(item.url)) && item.ts) {
        return { ...(item.body || {}), age: Math.max(0, Math.round(now - item.ts)) };
    }
    return item.body;
}

/**
 * Fetch a read-state write, queuing it for later replay if we're offline.
 * Resolves with the server JSON, or `{ success: true, queued: true }` when it
 * was parked in the offline queue. Only network failures are queued — an
 * HTTP error response means the server saw the request, so replaying it
 * later would not help.
 */
export async function queuedFetch(url, { method = 'POST', body = null } = {}) {
    if (!navigator.onLine) {
        return enqueue(url, method, body);
    }
    // This browser's device rides on every write (the server's "latest
    // position"); a replay below sends it again from the same browser.
    const headers = { Accept: 'application/json', ...deviceHeaders() };
    if (body) headers['Content-Type'] = 'application/json';
    let res;
    try {
        res = await fetch(url, {
            method,
            headers,
            body: body ? JSON.stringify(body) : undefined,
        });
    } catch (err) {
        // fetch() only rejects on a network failure (incl. "online" lie-fi).
        return enqueue(url, method, body);
    }
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
}

/**
 * Should a replayed request that got this HTTP status be retried later?
 * 5xx (server trouble), 408 (timeout) and 429 (rate limited) are transient;
 * any other 4xx will never succeed (deleted chapter, validation error…).
 */
export function isRetryableStatus(status) {
    return status >= 500 || status === 408 || status === 429;
}

async function replayQueue() {
    if (!navigator.onLine) return 0;
    const items = await idbGetAll('queue');
    const now = Date.now();
    let flushed = 0;
    for (const item of items) {
        if (!item.ts || now - item.ts > QUEUE_TTL_MS) {
            console.warn('[offline] dropping expired queued request', item.method, item.url);
            await idbDelete('queue', item.id);
            continue;
        }
        let res;
        try {
            const headers = { Accept: 'application/json', ...deviceHeaders() };
            const body = replayBody(item);
            if (body) headers['Content-Type'] = 'application/json';
            res = await fetch(item.url, {
                method: item.method,
                headers,
                body: body ? JSON.stringify(body) : undefined,
            });
        } catch (err) {
            break; // network gone again — keep the rest for the next flush
        }
        if (res.ok) {
            await idbDelete('queue', item.id);
            flushed += 1;
        } else if (isRetryableStatus(res.status)) {
            break; // server trouble — retry this (and keep order) later
        } else {
            console.warn(`[offline] dropping queued request rejected with HTTP ${res.status}`, item.method, item.url);
            await idbDelete('queue', item.id);
        }
    }
    return flushed;
}

let inFlight = null;

/**
 * Replay queued writes in order. Network failures and 5xx stop the replay
 * (kept for later); other 4xx responses and entries older than 30 days are
 * dropped. Concurrent calls share one in-flight replay, and when the Web
 * Locks API exists only one tab replays at a time, so nothing is sent twice.
 */
export function flushQueue() {
    if (inFlight) return inFlight;
    const run = (typeof navigator !== 'undefined' && navigator.locks?.request)
        ? navigator.locks.request('novarr-offline-flush', () => replayQueue())
        : replayQueue();
    inFlight = Promise.resolve(run)
        .then((flushed) => {
            if (flushed > 0 && window.Novarr?.showToast) {
                window.Novarr.showToast(`Synced ${flushed} reading update${flushed > 1 ? 's' : ''}.`, 'success');
            }
            return flushed;
        })
        .catch(() => 0)
        .finally(() => {
            inFlight = null;
            emitQueueSize();
        });
    return inFlight;
}

let initialised = false;

/** Wire up automatic queue flushing. Safe to call once per page load. */
export function initOffline() {
    if (initialised || !('indexedDB' in window)) return;
    initialised = true;
    // Expose the pending count for page glue (e.g. the Library page); also
    // broadcast as the `novarr:offline-queue` window event.
    window.Novarr = window.Novarr || {};
    window.Novarr.offlineQueueSize = offlineQueueSize;
    window.Novarr.storageStatus = storageStatus;
    flushQueue();
    window.addEventListener('online', () => flushQueue());
}
