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
    // The previous version's asset cache: migrated, then pruned.
    await seed(fc, 'novarr-v4-static', [
        ['/build/assets/app-OLD.js', asset()],
        ['/build/assets/app-PAGE.css', asset()],
        ['/build/assets/app-STALE.js', asset()],
        ['/build/assets/app-NEW.js', asset()],
        ['/icon-192.png', asset()],
        ['/storage/covers/1.jpg', asset()],
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
    const pages = fc.paths('novarr-v6-pages');
    assert.equal(pages.length, 60);
    assert.equal(pages[0], '/chapters/111');
    assert.equal(pages.at(-1), '/chapters/170');
    assert.ok(!pages.includes('/settings'));
    // Old versioned caches are gone.
    assert.deepEqual([...fc.store.keys()].sort(), ['novarr-covers', 'novarr-offline', 'novarr-v6-pages', 'novarr-v6-static']);
    // Covers move to their own cache.
    assert.deepEqual(fc.paths('novarr-covers'), ['/storage/covers/1.jpg']);
    // Bundles: live + referenced by a cached page are kept; stale ones go.
    // Icons are not carried over (install precaches them again).
    assert.deepEqual(fc.paths('novarr-v6-static').sort(), [
        '/build/assets/app-NEW.js',
        '/build/assets/app-OLD.js',
        '/build/assets/app-PAGE.css',
    ]);
});

test('a slow navigation serves the cached page and still refreshes the cache', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const fc = fakeCaches();
        await seed(fc, 'novarr-v6-pages', [['/chapters/5', html('<title>old</title>')]]);

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
        const cached = await (await fc.api.open('novarr-v6-pages')).match(abs('/chapters/5'));
        assert.match(await cached.text(), /new/);
    } finally {
        mock.timers.reset();
    }
});

// ---- Helpers for request-driven scenarios ----

/** Dispatch a fetch event; resolves to { res, waits } once everything settled. */
async function dispatchFetch(handlers, path, init = {}) {
    const waits = [];
    let responded;
    handlers.fetch({
        request: new Request(abs(path), init),
        respondWith: (p) => { responded = p; },
        waitUntil: (p) => { waits.push(p); },
    });
    const res = responded ? await responded : undefined;
    await Promise.all(waits);
    // Waits registered while respondWith was pending (cover refresh/store).
    await Promise.all(waits);
    return { res, waits };
}

const navigate = (handlers, path) => dispatchFetch(handlers, path, { headers: { accept: 'text/html' } });

async function runActivate(handlers) {
    let done;
    handlers.activate({ waitUntil: (p) => { done = p; } });
    await done;
}

async function runInstall(handlers) {
    let done;
    handlers.install({ waitUntil: (p) => { done = p; } });
    await done;
}

const pageWith = (hash) => html(`<link rel="stylesheet" href="/build/assets/app-${hash}.css"><script type="module" src="/build/assets/app-${hash}.js"></script>`);
const manifestFor = (hash) => new Response(JSON.stringify({
    'resources/js/app.js': { file: `assets/app-${hash}.js` },
    'resources/css/app.scss': { file: `assets/app-${hash}.css` },
}));

test('shell pages keep their bundles across a deploy and outlive the page-cache cap', async () => {
    const fc = fakeCaches();
    let hash = 'AAA';
    const requested = [];
    const fetch = async (req) => {
        const path = new URL(urlOf(req), ORIGIN).pathname;
        requested.push(path);
        if (path === '/build/manifest.json') return manifestFor(hash);
        if (path.startsWith('/build/')) return asset();
        if (path.endsWith('.png') || path.endsWith('.svg')) return asset();
        return pageWith(hash);
    };
    const handlers = loadWorker({ caches: fc.api, fetch });

    // Install precaches the shell and the bundles it loads.
    await runInstall(handlers);
    await runActivate(handlers);
    assert.deepEqual(fc.paths('novarr-v6-pages'), ['/', '/library', '/offline']);
    assert.ok(fc.paths('novarr-v6-static').includes('/build/assets/app-AAA.css'));
    assert.ok(fc.paths('novarr-v6-static').includes('/build/assets/app-AAA.js'));

    // Deploy: new hashes. The reader opens lots of chapters (more than the
    // cap) while the shell is never revisited.
    hash = 'BBB';
    for (let i = 1; i <= 70; i++) await navigate(handlers, `/chapters/${i}`);
    await dispatchFetch(handlers, '/build/assets/app-BBB.css');
    await runActivate(handlers); // prune against the new manifest

    const pages = fc.paths('novarr-v6-pages');
    for (const shell of ['/', '/library', '/offline']) assert.ok(pages.includes(shell), `${shell} kept`);
    assert.equal(pages.filter((p) => p.startsWith('/chapters/')).length, 60);
    const statics = fc.paths('novarr-v6-static');
    assert.ok(statics.includes('/build/assets/app-AAA.css'), 'shell CSS survives the prune');
    assert.ok(statics.includes('/build/assets/app-AAA.js'), 'shell JS survives the prune');

    // An online visit refreshes the shell copy to the new build.
    await navigate(handlers, '/library');
    const lib = await (await fc.api.open('novarr-v6-pages')).match(abs('/library'));
    assert.match(await lib.text(), /app-BBB\.css/);
});

test('/ is served from the cache when the network fails', async () => {
    const fc = fakeCaches();
    let online = true;
    const fetch = async () => {
        if (!online) throw new TypeError('Failed to fetch');
        return html('<title>home</title>');
    };
    const handlers = loadWorker({ caches: fc.api, fetch });
    await runInstall(handlers);

    online = false;
    const { res } = await navigate(handlers, '/');
    assert.match(await res.text(), /<title>home<\/title>/);
});

test('a cached hashed build asset is served with no network request', async () => {
    const fc = fakeCaches();
    await seed(fc, 'novarr-v6-static', [['/build/assets/app-X.js', asset()]]);
    let calls = 0;
    const handlers = loadWorker({ caches: fc.api, fetch: async () => { calls += 1; return asset(); } });

    const { res, waits } = await dispatchFetch(handlers, '/build/assets/app-X.js');
    assert.equal(await res.text(), 'x');
    assert.equal(calls, 0);
    assert.equal(waits.length, 0);

    // A miss is fetched once and stored.
    await dispatchFetch(handlers, '/build/assets/app-Y.js');
    await dispatchFetch(handlers, '/build/assets/app-Y.js');
    assert.equal(calls, 1);
});

test('covers are capped at 300, oldest first; downloaded covers come from the offline cache', async () => {
    const fc = fakeCaches();
    await seed(fc, 'novarr-offline', [['/storage/covers/dl.jpg', new Response('downloaded')]]);
    const fetched = [];
    const fetch = async (req) => { fetched.push(new URL(urlOf(req)).pathname); return new Response('net', { headers: { 'content-type': 'image/jpeg' } }); };
    const handlers = loadWorker({ caches: fc.api, fetch });

    for (let i = 1; i <= 305; i++) await dispatchFetch(handlers, `/storage/covers/${i}.jpg`);
    const covers = fc.paths('novarr-covers');
    assert.equal(covers.length, 300);
    assert.equal(covers[0], '/storage/covers/6.jpg');
    assert.equal(covers.at(-1), '/storage/covers/305.jpg');

    fetched.length = 0;
    const { res } = await dispatchFetch(handlers, '/storage/covers/dl.jpg');
    assert.equal(await res.text(), 'downloaded');
    assert.deepEqual(fetched, []);
    assert.deepEqual(fc.paths('novarr-offline'), ['/storage/covers/dl.jpg']);
    assert.ok(!fc.paths('novarr-covers').includes('/storage/covers/dl.jpg'));

    // A fresh cover is not refetched.
    await dispatchFetch(handlers, '/storage/covers/305.jpg');
    assert.deepEqual(fetched, []);
});

test('a cover older than a day is served from cache and refreshed once in the background', async () => {
    const fc = fakeCaches();
    const old = new Response('old', { headers: { 'x-novarr-sw-fetched-at': String(Date.now() - 2 * 24 * 3600 * 1000) } });
    await seed(fc, 'novarr-covers', [['/storage/covers/1.jpg', old]]);
    let calls = 0;
    const handlers = loadWorker({ caches: fc.api, fetch: async () => { calls += 1; return new Response('new'); } });

    const { res } = await dispatchFetch(handlers, '/storage/covers/1.jpg');
    assert.equal(await res.text(), 'old');
    assert.equal(calls, 1);
    const stored = await (await fc.api.open('novarr-covers')).match(abs('/storage/covers/1.jpg'));
    assert.equal(await stored.text(), 'new');

    await dispatchFetch(handlers, '/storage/covers/1.jpg');
    assert.equal(calls, 1, 'refreshed at most once a day');
});

test('a download that was redirected (signed out → /login) is not stored as the chapter', async () => {
    const fc = fakeCaches();
    const fetch = async (req) => {
        const path = new URL(urlOf(req), ORIGIN).pathname;
        const res = html('<title>Sign in</title>');
        // A followed redirect: what fetch() returns after a 302 to /login.
        Object.defineProperty(res, 'redirected', { value: path === '/chapters/2' });
        return res;
    };
    const handlers = loadWorker({ caches: fc.api, fetch });
    let done;
    handlers.message({
        data: { type: 'CACHE_URLS', urls: ['/chapters/1', '/chapters/2'] },
        source: { postMessage: () => {} },
        waitUntil: (p) => { done = p; },
    });
    await done;

    assert.deepEqual(fc.paths('novarr-offline'), ['/chapters/1']);
});

test('a download also caches the /build files its pages load, once per asset', async () => {
    const fc = fakeCaches();
    await seed(fc, 'novarr-v6-static', [['/build/assets/app-X.css', asset()]]);
    const requested = [];
    const fetch = async (req) => {
        const path = new URL(urlOf(req), ORIGIN).pathname;
        requested.push(path);
        if (path.startsWith('/build/')) return asset();
        // Tags carry extra attributes (data-turbo-track) like the real markup.
        return html('<link rel="stylesheet" href="/build/assets/app-X.css" data-turbo-track="reload" />'
            + '<script type="module" src="/build/assets/app-X.js" data-turbo-track="reload"></script>'
            + `<script type="module" src="${ORIGIN}/build/assets/index-R.js" data-turbo-track="reload"></script>`);
    };
    const handlers = loadWorker({ caches: fc.api, fetch });

    const messages = [];
    let done;
    handlers.message({
        data: { type: 'CACHE_URLS', urls: ['/chapters/1', '/chapters/2', '/chapters/3'] },
        source: { postMessage: (m) => messages.push(m) },
        waitUntil: (p) => { done = p; },
    });
    await done;

    assert.deepEqual(fc.paths('novarr-offline'), ['/chapters/1', '/chapters/2', '/chapters/3']);
    assert.deepEqual(fc.paths('novarr-v6-static').sort(), [
        '/build/assets/app-X.css', '/build/assets/app-X.js', '/build/assets/index-R.js',
    ]);
    // Each new asset fetched once for the whole run; the cached CSS not at all.
    assert.deepEqual(requested.filter((p) => p.startsWith('/build/')).sort(), [
        '/build/assets/app-X.js', '/build/assets/index-R.js',
    ]);
    assert.deepEqual({ ...messages.at(-1) }, { type: 'CACHE_COMPLETE', total: 3 });
});

test('the old static cache\'s offline page survives an install during maintenance', async () => {
    const fc = fakeCaches();
    await seed(fc, 'novarr-v4-static', [
        ['/offline', html('<title>old offline</title>')],
        ['/library', html('<title>old library</title>')],
        ['/build/assets/app-OLD.js', asset()],
    ]);
    await seed(fc, 'novarr-covers', [['/storage/covers/9.jpg', asset()]]);
    // Maintenance mode: every page is a 503; the manifest is unavailable too.
    const fetch = async () => new Response('down', { status: 503, headers: { 'content-type': 'text/html' } });
    const handlers = loadWorker({ caches: fc.api, fetch });

    await runInstall(handlers);
    await runActivate(handlers);

    assert.deepEqual(fc.paths('novarr-v6-pages').sort(), ['/library', '/offline']);
    assert.ok(!fc.store.has('novarr-v4-static'));
    assert.deepEqual(fc.paths('novarr-covers'), ['/storage/covers/9.jpg'], 'covers survive activate');

    // Offline, a page we never cached falls back to the carried-over page.
    const offline = loadWorker({ caches: fc.api, fetch: async () => { throw new TypeError('Failed to fetch'); } });
    const { res } = await navigate(offline, '/novels/77');
    assert.match(await res.text(), /old offline/);
});

test('a fresh shell page from install is not replaced by the old static cache copy', async () => {
    const fc = fakeCaches();
    await seed(fc, 'novarr-v4-static', [['/offline', html('<title>old offline</title>')]]);
    const handlers = loadWorker({ caches: fc.api, fetch: async (req) => {
        const path = new URL(urlOf(req), ORIGIN).pathname;
        if (path === '/build/manifest.json') return manifestFor('N');
        return html('<title>new</title>');
    } });
    await runInstall(handlers);
    await runActivate(handlers);
    const page = await (await fc.api.open('novarr-v6-pages')).match(abs('/offline'));
    assert.match(await page.text(), /new/);
});
