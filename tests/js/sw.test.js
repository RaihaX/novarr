// Service worker (public/sw.js) — run with `yarn test:js`.
// Loads the classic worker script into a vm context with a fake Cache API.
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const ORIGIN = 'https://novarr.test';
const abs = (path) => ORIGIN + path;
const urlOf = (req) => (typeof req === 'string' ? new URL(req, ORIGIN).href : req.url);

function html(body) {
    return new Response(`<!doctype html><html><head>${body}</head></html>`, {
        headers: { 'content-type': 'text/html; charset=UTF-8' },
    });
}
const asset = () => new Response('x', { headers: { 'content-type': 'text/javascript' } });

/** Minimal Cache Storage: insertion-ordered, put re-appends (like the spec). */
function fakeCaches() {
    const store = new Map();
    const open = (name) => {
        if (!store.has(name)) store.set(name, new Map());
        const data = store.get(name);
        return {
            async match(req) { const r = data.get(urlOf(req)); return r ? r.clone() : undefined; },
            async put(req, res) { const u = urlOf(req); data.delete(u); data.set(u, res.clone()); },
            async delete(req) { return data.delete(urlOf(req)); },
            async keys() { return [...data.keys()].map((u) => new Request(u)); },
            async addAll() {},
        };
    };
    return {
        store,
        api: {
            open: async (name) => open(name),
            keys: async () => [...store.keys()],
            delete: async (name) => store.delete(name),
            match: async (req) => {
                for (const name of store.keys()) {
                    const hit = await open(name).match(req);
                    if (hit) return hit;
                }
                return undefined;
            },
        },
        paths: (name) => [...(store.get(name)?.keys() || [])].map((u) => new URL(u).pathname),
    };
}

function loadWorker({ caches, fetch }) {
    const handlers = {};
    const self = {
        location: { origin: ORIGIN },
        addEventListener: (type, fn) => { handlers[type] = fn; },
        skipWaiting() {},
        clients: { claim: async () => {} },
    };
    vm.runInNewContext(readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), {
        self, caches, fetch, URL, Request, Response, Headers, console,
        setTimeout: (...a) => setTimeout(...a),
        clearTimeout: (...a) => clearTimeout(...a),
    });
    return handlers;
}

async function seed(fc, name, entries) {
    const cache = await fc.api.open(name);
    for (const [path, res] of entries) await cache.put(abs(path), res);
}

test('activate migrates old caches and prunes only unreferenced stale bundles', async () => {
    const fc = fakeCaches();

    await seed(fc, 'novarr-v3-offline', [
        ['/chapters/1', html('<script type="module" src="/build/assets/app-OLD.js"></script>')],
    ]);
    const oldPages = [];
    for (let i = 1; i <= 70; i++) {
        oldPages.push([`/chapters/${100 + i}`, html(i === 70 ? `<link rel="stylesheet" href="${ORIGIN}/build/assets/app-PAGE.css">` : '')]);
    }
    oldPages.push(['/settings', html('')]); // not auto-cacheable — not migrated
    await seed(fc, 'novarr-v3-pages', oldPages);
    await seed(fc, 'novarr-v3-static', [['/build/assets/ancient.js', asset()]]);
    await seed(fc, 'novarr-v4-static', [
        ['/build/assets/app-OLD.js', asset()],
        ['/build/assets/app-PAGE.css', asset()],
        ['/build/assets/app-STALE.js', asset()],
        ['/build/assets/app-NEW.js', asset()],
        ['/icon-192.png', asset()],
    ]);

    const fetch = async (url) => {
        assert.equal(url, '/build/manifest.json');
        return new Response(JSON.stringify({ 'resources/js/app.js': { file: 'assets/app-NEW.js', css: [] } }));
    };
    const handlers = loadWorker({ caches: fc.api, fetch });

    let done;
    handlers.activate({ waitUntil: (p) => { done = p; } });
    await done;

    // Downloads survive under the unversioned name.
    assert.deepEqual(fc.paths('novarr-offline'), ['/chapters/1']);
    // Auto-cached pages carried over: the newest 60 chapter pages only.
    const pages = fc.paths('novarr-v4-pages');
    assert.equal(pages.length, 60);
    assert.equal(pages[0], '/chapters/111');
    assert.equal(pages.at(-1), '/chapters/170');
    assert.ok(!pages.includes('/settings'));
    // Old versioned caches are gone.
    assert.deepEqual([...fc.store.keys()].sort(), ['novarr-offline', 'novarr-v4-pages', 'novarr-v4-static']);
    // Bundles: live + referenced by a cached page are kept; the stale one goes.
    assert.deepEqual(fc.paths('novarr-v4-static').sort(), [
        '/build/assets/app-NEW.js',
        '/build/assets/app-OLD.js',
        '/build/assets/app-PAGE.css',
        '/icon-192.png',
    ]);
});

test('a slow navigation serves the cached page and still refreshes the cache', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const fc = fakeCaches();
        await seed(fc, 'novarr-v4-pages', [['/chapters/5', html('<title>old</title>')]]);

        let resolveNetwork;
        const fetch = () => new Promise((r) => { resolveNetwork = r; });
        const handlers = loadWorker({ caches: fc.api, fetch });

        const waits = [];
        let responded;
        handlers.fetch({
            request: new Request(abs('/chapters/5'), { headers: { accept: 'text/html' } }),
            respondWith: (p) => { responded = p; },
            // A real FetchEvent throws InvalidStateError for waitUntil()
            // calls made after respondWith() settled, so the refresh must be
            // registered during dispatch (asserted right below).
            waitUntil: (p) => { waits.push(p); },
        });
        assert.equal(waits.length, 1, 'cache refresh registered synchronously');

        mock.timers.tick(5000);
        const res = await responded;
        assert.match(await res.text(), /old/);

        resolveNetwork(html('<title>new</title>'));
        await Promise.all(waits);
        const cached = await (await fc.api.open('novarr-v4-pages')).match(abs('/chapters/5'));
        assert.match(await cached.text(), /new/);
    } finally {
        mock.timers.reset();
    }
});
