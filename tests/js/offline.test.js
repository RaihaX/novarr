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
globalThis.fetch = async (url, opts) => {
    fetched.push(url);
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

beforeEach(() => {
    fetched.length = 0;
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
