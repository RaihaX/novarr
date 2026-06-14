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

export async function downloadNovel(id, onProgress) {
    const res = await fetch(`/novels/${id}/offline-manifest`, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('Could not load the chapter list.');
    const manifest = await res.json();

    const urls = manifest.chapters.map((c) => c.url);
    urls.unshift(manifest.url);                 // the novel page itself
    if (manifest.cover) urls.push(manifest.cover);

    await sendUrlsToSw('CACHE_URLS', urls, onProgress);

    await idbPut('novels', {
        id: manifest.id,
        name: manifest.name,
        author: manifest.author,
        cover: manifest.cover,
        url: manifest.url,
        chapters: manifest.chapters,
        chapterCount: manifest.chapterCount,
        downloadedAt: Date.now(),
    });
    return manifest;
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

export const getLibrary = () => idbGetAll('novels');
export const getNovel = (id) => idbGet('novels', id);
export const isDownloaded = (id) => idbGet('novels', id).then((r) => !!r);

// ---- Public: read-state sync queue ----

/**
 * Fetch a read-state write, queuing it for later replay if we're offline.
 * Resolves with the server JSON, or `{ success: true, queued: true }` when it
 * was parked in the offline queue.
 */
export async function queuedFetch(url, { method = 'POST', body = null } = {}) {
    const headers = { Accept: 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    try {
        const res = await fetch(url, {
            method,
            headers,
            body: body ? JSON.stringify(body) : undefined,
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.json();
    } catch (err) {
        if (!navigator.onLine) {
            await idbAdd('queue', { url, method, body, ts: Date.now() });
            return { success: true, queued: true };
        }
        throw err;
    }
}

/** Replay queued writes in order. Stops at the first failure (likely offline again). */
export async function flushQueue() {
    if (!navigator.onLine) return 0;
    const items = await idbGetAll('queue');
    let flushed = 0;
    for (const item of items) {
        try {
            const headers = { Accept: 'application/json' };
            if (item.body) headers['Content-Type'] = 'application/json';
            const res = await fetch(item.url, {
                method: item.method,
                headers,
                body: item.body ? JSON.stringify(item.body) : undefined,
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            await idbDelete('queue', item.id);
            flushed += 1;
        } catch (err) {
            break;
        }
    }
    if (flushed > 0 && window.Novarr?.showToast) {
        window.Novarr.showToast(`Synced ${flushed} reading update${flushed > 1 ? 's' : ''}.`, 'success');
    }
    return flushed;
}

let initialised = false;

/** Wire up automatic queue flushing. Safe to call once per page load. */
export function initOffline() {
    if (initialised || !('indexedDB' in window)) return;
    initialised = true;
    flushQueue();
    window.addEventListener('online', () => flushQueue());
}
