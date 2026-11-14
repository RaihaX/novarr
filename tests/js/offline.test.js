// Offline read-state queue (resources/js/offline.js) — run with `yarn test:js`.
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

// ---- Minimal in-memory IndexedDB + browser globals ----
const stores = new Map();
let nextId = 1;

function request(fn) {
    const req = { onsuccess: null, onerror: null, result: undefined, error: null };
    setTimeout(() => {
        try {
            req.result = fn();
            req.onsuccess?.();
        } catch (e) {
            req.error = e;
            req.onerror?.();
        }
    }, 0);
    return req;
}

function objectStore(name) {
    const data = stores.get(name);
    return {
        add: (v) => request(() => { const id = nextId++; data.set(id, { ...v, id }); return id; }),
        put: (v) => request(() => { data.set(v.id, v); return v.id; }),
        get: (k) => request(() => data.get(k)),
        getAll: () => request(() => [...data.values()].sort((a, b) => a.id - b.id)),
        delete: (k) => request(() => { data.delete(k); }),
        count: () => request(() => data.size),
    };
}

const db = {
    objectStoreNames: { contains: (n) => stores.has(n) },
    createObjectStore: (n) => { stores.set(n, new Map()); },
    transaction: () => ({ objectStore }),
};

globalThis.indexedDB = {
    open: () => {
        const req = { result: db, onupgradeneeded: null, onsuccess: null, onerror: null };
        setTimeout(() => { req.onupgradeneeded?.(); req.onsuccess?.(); }, 0);
        return req;
    },
};
Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true, writable: true });
globalThis.window = globalThis;
globalThis.window.addEventListener ??= () => {};
const events = [];
globalThis.dispatchEvent = (e) => { events.push(e); return true; };

let responder = () => ({ ok: true, status: 200, json: async () => ({ success: true }) });
const fetched = [];
const sent = []; // { url, body } of every request, body JSON-parsed
globalThis.fetch = async (url, opts) => {
    fetched.push(url);
    sent.push({ url, body: opts?.body ? JSON.parse(opts.body) : null });
    await new Promise((r) => setTimeout(r, 5));
    return responder(url, opts);
};

const offline = await import('../../resources/js/offline.js');

const queue = () => [...(stores.get('queue')?.values() || [])];

async function seed(items) {
    await offline.offlineQueueSize(); // opens the db / creates stores
    stores.get('queue').clear();
    for (const it of items) {
        const id = nextId++;
        stores.get('queue').set(id, { id, method: 'POST', body: null, ts: Date.now(), ...it });
    }
}

const local = new Map();
globalThis.localStorage = {
    getItem: (k) => (local.has(k) ? local.get(k) : null),
    setItem: (k, v) => { local.set(k, String(v)); },
};

beforeEach(() => {
    fetched.length = 0;
    sent.length = 0;
    events.length = 0;
    navigator.onLine = true;
    responder = () => ({ ok: true, status: 200, json: async () => ({ success: true }) });
});

test('4xx entries are dropped, the rest replay', async () => {
    await seed([{ url: '/a' }, { url: '/gone' }, { url: '/b' }]);
    responder = (url) => (url === '/gone' ? { ok: false, status: 404 } : { ok: true, status: 200 });
    const flushed = await offline.flushQueue();
    assert.equal(flushed, 2);
    assert.deepEqual(fetched, ['/a', '/gone', '/b']);
    assert.equal(queue().length, 0);
});

test('5xx stops the replay and keeps the entry and everything after it', async () => {
    await seed([{ url: '/a' }, { url: '/err' }, { url: '/b' }]);
    responder = (url) => (url === '/err' ? { ok: false, status: 503 } : { ok: true, status: 200 });
    assert.equal(await offline.flushQueue(), 1);
    assert.deepEqual(queue().map((i) => i.url), ['/err', '/b']);
});

test('a network failure stops the replay', async () => {
    await seed([{ url: '/a' }, { url: '/b' }]);
    responder = () => { throw new TypeError('Failed to fetch'); };
    assert.equal(await offline.flushQueue(), 0);
    assert.equal(queue().length, 2);
});

test('entries older than 30 days are dropped without a request', async () => {
    await seed([{ url: '/old', ts: Date.now() - offline.QUEUE_TTL_MS - 1000 }, { url: '/new' }]);
    assert.equal(await offline.flushQueue(), 1);
    assert.deepEqual(fetched, ['/new']);
    assert.equal(queue().length, 0);
});

test('concurrent flushes share one replay — nothing is sent twice', async () => {
    await seed([{ url: '/a' }, { url: '/b' }, { url: '/c' }]);
    const [x, y, z] = await Promise.all([offline.flushQueue(), offline.flushQueue(), offline.flushQueue()]);
    assert.deepEqual([x, y, z], [3, 3, 3]);
    assert.deepEqual(fetched, ['/a', '/b', '/c']);
});

test('pending count is exposed and broadcast', async () => {
    await seed([{ url: '/a' }, { url: '/b' }]);
    assert.equal(await offline.offlineQueueSize(), 2);
    navigator.onLine = false;
    await offline.flushQueue();
    await new Promise((r) => setTimeout(r, 20));
    const last = events.at(-1);
    assert.equal(last.type, offline.QUEUE_EVENT);
    assert.equal(last.detail.size, 2);
});

test('queuedFetch parks the write when the network fails', async () => {
    await seed([]);
    responder = () => { throw new TypeError('Failed to fetch'); };
    const res = await offline.queuedFetch('/chapters/1/toggle-read');
    assert.deepEqual(res, { success: true, queued: true });
    assert.equal(queue().length, 1);
    assert.equal(offline.isRetryableStatus(500), true);
    assert.equal(offline.isRetryableStatus(419), false);
});

test('a newer reading position replaces the queued one for the same chapter', async () => {
    await seed([]);
    navigator.onLine = false;
    await offline.queuedFetch('/chapters/5/progress', { body: { progress: 10 } });
    await offline.queuedFetch('/chapters/6/toggle-read');
    await offline.queuedFetch('/chapters/7/progress', { body: { progress: 30 } });
    await offline.queuedFetch('/chapters/5/progress', { body: { progress: 40 } });

    // Chapter 5's position moved to the end (after the toggle queued in
    // between); chapter 7's is untouched.
    assert.deepEqual(queue().map((i) => [i.url, i.body?.progress ?? null]), [
        ['/chapters/6/toggle-read', null],
        ['/chapters/7/progress', 30],
        ['/chapters/5/progress', 40],
    ]);
});

test('quick successive saves coalesce even when enqueued concurrently', async () => {
    await seed([]);
    navigator.onLine = false;
    await Promise.all([10, 20, 30].map((progress) => offline.queuedFetch('/chapters/9/progress', { body: { progress } })));
    assert.deepEqual(queue().map((i) => i.body.progress), [30]);
});

test('a read mark is not queued twice for the same chapter', async () => {
    await seed([]);
    navigator.onLine = false;
    await offline.queuedFetch('/chapters/5/progress', { body: { read: true } });
    await offline.queuedFetch('/chapters/5/progress', { body: { progress: 50 } });
    await offline.queuedFetch('/chapters/5/progress', { body: { read: true } });
    await offline.queuedFetch('https://novarr.test/chapters/bulk-read', { body: { ids: [8], read: true } });
    await offline.queuedFetch('/chapters/bulk-read', { body: { ids: [8], read: true } });
    // Toggles are not idempotent: both stay.
    await offline.queuedFetch('/chapters/4/toggle-read');
    await offline.queuedFetch('/chapters/4/toggle-read');
    assert.deepEqual(queue().map((i) => i.url), [
        '/chapters/5/progress',
        '/chapters/5/progress',
        'https://novarr.test/chapters/bulk-read',
        '/chapters/4/toggle-read',
        '/chapters/4/toggle-read',
    ]);
});

test('a read mark is queued again after an unread for that chapter', async () => {
    await seed([]);
    navigator.onLine = false;
    await offline.queuedFetch('/chapters/bulk-read', { body: { ids: [3], read: true } });
    await offline.queuedFetch('/chapters/bulk-read', { body: { ids: [3], read: false } });
    await offline.queuedFetch('/chapters/bulk-read', { body: { ids: [3], read: true } });
    assert.deepEqual(queue().map((i) => i.body.read), [true, false, true]);
});

test('every replayed read-state write carries `age`, ms since it was queued', async (t) => {
    const now = Date.parse('2026-11-14T12:00:00Z');
    t.mock.method(Date, 'now', () => now);
    await seed([
        { url: '/chapters/5/progress', body: { progress: 40 }, ts: now - 60_000 },
        { url: 'https://novarr.test/chapters/bulk-read', body: { ids: [5], read: true }, ts: now - 50_000 },
        { url: '/chapters/6/toggle-read', body: null, ts: now - 40_000 },
        { url: '/chapters/7/read-through', body: null, ts: now - 30_000 },
        // A clock that moved backwards since: never a negative age.
        { url: '/chapters/8/progress', body: { progress: 3 }, ts: now + 5_000 },
        // Not a read-state write: sent as queued.
        { url: '/novels/1/bookmarks', body: { chapter_id: 5 }, ts: now - 10_000 },
    ]);
    assert.equal(await offline.flushQueue(), 6);
    assert.deepEqual(sent, [
        { url: '/chapters/5/progress', body: { progress: 40, age: 60_000 } },
        { url: 'https://novarr.test/chapters/bulk-read', body: { ids: [5], read: true, age: 50_000 } },
        { url: '/chapters/6/toggle-read', body: { age: 40_000 } },
        { url: '/chapters/7/read-through', body: { age: 30_000 } },
        { url: '/chapters/8/progress', body: { progress: 3, age: 0 } },
        { url: '/novels/1/bookmarks', body: { chapter_id: 5 } },
    ]);
    assert.equal(queue().length, 0);
});

test('offline: mark unread, then read to 40% — both replay in order, the position younger', async (t) => {
    let now = Date.parse('2026-11-14T09:00:00Z');
    t.mock.method(Date, 'now', () => now);
    await seed([]);
    navigator.onLine = false;
    await offline.queuedFetch('/chapters/5/toggle-read');
    now += 5 * 60_000;
    await offline.queuedFetch('/chapters/5/progress', { body: { progress: 40 } });

    now += 60 * 60_000; // reconnect an hour later
    navigator.onLine = true;
    assert.equal(await offline.flushQueue(), 2);
    assert.deepEqual(sent, [
        { url: '/chapters/5/toggle-read', body: { age: 65 * 60_000 } },
        { url: '/chapters/5/progress', body: { progress: 40, age: 60 * 60_000 } },
    ]);
});

test('an online write is sent without `age` (the server treats it as now)', async () => {
    await seed([]);
    await offline.queuedFetch('/chapters/5/progress', { body: { progress: 12 } });
    assert.deepEqual(sent, [{ url: '/chapters/5/progress', body: { progress: 12 } }]);
});

test('persistence is requested once and its outcome remembered', async () => {
    local.clear();
    let asked = 0;
    navigator.storage = {
        persisted: async () => false,
        persist: async () => { asked += 1; return false; },
        estimate: async () => ({ usage: 5 * 1024 * 1024, quota: 1024 * 1024 * 1024 }),
    };
    try {
        assert.equal(await offline.requestPersistence(), 'denied');
        assert.equal(await offline.requestPersistence(), 'denied');
        assert.equal(asked, 1);
        assert.equal(localStorage.getItem(offline.PERSIST_KEY), 'denied');
        assert.deepEqual(await offline.storageStatus(), {
            usage: 5 * 1024 * 1024, quota: 1024 * 1024 * 1024, persistence: 'denied',
        });

        navigator.storage.persisted = async () => true;
        assert.equal((await offline.storageStatus()).persistence, 'granted');
    } finally {
        delete navigator.storage;
    }
});

test('storage status without the Storage API reports unsupported', async () => {
    local.clear();
    assert.equal(await offline.requestPersistence(), 'unsupported');
    assert.deepEqual(await offline.storageStatus(), { usage: null, quota: null, persistence: 'unsupported' });
});
