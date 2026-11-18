// "Latest position" prompt + device identity (resources/js/device.js,
// resources/js/reader/latest-position.js) — run with `yarn test:js`.
import { test, afterEach } from 'node:test';
import assert from 'node:assert/strict';

import {
    deviceNameFrom, generateDeviceId, initDevice, readCookie, deviceHeaders,
    appendDeviceFields, toHeaderValue, isValidDeviceId, resetDeviceForTests,
    DEVICE_KEY, HEADER_ID, HEADER_NAME,
} from '../../resources/js/device.js';
import {
    shouldOffer, relativeTime, promptText, positionLabel, chapterIdFromUrl,
    markLocalWrite, localWriteAge, lastWriteKey, declinedKey, rememberAnswered, readDeclined,
    markInReaderNav, consumeInReaderNav, markAccepted, consumeAccepted, planLatest,
} from '../../resources/js/reader/latest-position.js';
import { createProgress } from '../../resources/js/manga-reader/progress.js';

// ---- Helpers ----
function storage(init = {}) {
    const m = new Map(Object.entries(init));
    return {
        getItem: (k) => (m.has(k) ? m.get(k) : null),
        setItem: (k, v) => { m.set(k, String(v)); },
        removeItem: (k) => { m.delete(k); },
        dump: () => Object.fromEntries(m),
    };
}
const blocked = {
    getItem: () => { throw new Error('SecurityError'); },
    setItem: () => { throw new Error('SecurityError'); },
    removeItem: () => { throw new Error('SecurityError'); },
};

/** document.cookie: the setter takes "name=value; attrs", the getter lists name=value pairs. */
function cookieDoc(initial = {}) {
    const jar = new Map(Object.entries(initial));
    const written = [];
    return {
        written,
        jar,
        get cookie() { return [...jar].map(([k, v]) => `${k}=${v}`).join('; '); },
        set cookie(str) {
            written.push(str);
            const [pair] = str.split(';');
            const eq = pair.indexOf('=');
            jar.set(pair.slice(0, eq).trim(), pair.slice(eq + 1).trim());
        },
    };
}

const UA = {
    iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
    ipadOld: 'Mozilla/5.0 (iPad; CPU OS 12_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/12.1.2 Mobile/15E148 Safari/604.1',
    mac: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
    android: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36',
    windows: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
    linux: 'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0',
    chromeos: 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
};

afterEach(() => resetDeviceForTests());

// ---- Device name ----
test('device name from the user agent', () => {
    assert.equal(deviceNameFrom({ userAgent: UA.iphone, maxTouchPoints: 5 }), 'Web · iPhone');
    assert.equal(deviceNameFrom({ userAgent: UA.ipadOld, maxTouchPoints: 5 }), 'Web · iPad');
    assert.equal(deviceNameFrom({ userAgent: UA.android, maxTouchPoints: 5 }), 'Web · Android');
    assert.equal(deviceNameFrom({ userAgent: UA.mac, platform: 'MacIntel', maxTouchPoints: 0 }), 'Web · Mac');
    assert.equal(deviceNameFrom({ userAgent: UA.windows, platform: 'Win32' }), 'Web · Windows');
    assert.equal(deviceNameFrom({ userAgent: UA.linux, platform: 'Linux x86_64' }), 'Web · Linux');
    assert.equal(deviceNameFrom({ userAgent: UA.chromeos }), 'Web');
    assert.equal(deviceNameFrom({ userAgent: 'curl/8.0' }), 'Web');
    assert.equal(deviceNameFrom(), 'Web');
});

test('iPadOS (a Mac user agent with a touch screen) is an iPad', () => {
    assert.equal(deviceNameFrom({ userAgent: UA.mac, platform: 'MacIntel', maxTouchPoints: 5 }), 'Web · iPad');
});

test('the platform alone is enough (userAgentData.platform "macOS")', () => {
    assert.equal(deviceNameFrom({ userAgent: '', platform: 'macOS' }), 'Web · Mac');
    assert.equal(deviceNameFrom({ userAgent: '', platform: 'Windows' }), 'Web · Windows');
});

// ---- Device id ----
test('a generated id matches the contract and is w_-prefixed', () => {
    const id = generateDeviceId();
    assert.match(id, /^w_[0-9a-f]{24}$/);
    assert.ok(isValidDeviceId(id));
    assert.notEqual(generateDeviceId(), id);
    assert.ok(!isValidDeviceId('short'));
    assert.ok(!isValidDeviceId('has space here'));
    assert.ok(!isValidDeviceId('x'.repeat(65)));
});

test('the id is generated once, persisted, and mirrored to cookies', () => {
    const store = storage();
    const doc = cookieDoc();
    const nav = { userAgent: UA.iphone, maxTouchPoints: 5 };
    const first = initDevice({ storage: store, doc, nav, force: true });
    assert.match(first.id, /^w_/);
    assert.equal(first.name, 'Web · iPhone');
    assert.equal(store.getItem(DEVICE_KEY), first.id);
    assert.equal(readCookie(doc.cookie, 'novarr_device'), first.id);
    assert.equal(readCookie(doc.cookie, 'novarr_device_name'), 'Web · iPhone');
    // URL-encoded, site-wide, two years, Lax.
    const nameCookie = doc.written.find((c) => c.startsWith('novarr_device_name='));
    assert.equal(nameCookie, `novarr_device_name=Web%20%C2%B7%20iPhone; path=/; max-age=${2 * 365 * 24 * 3600}; SameSite=Lax`);

    // Next page load: same id.
    const again = initDevice({ storage: store, doc: cookieDoc(), nav, force: true });
    assert.equal(again.id, first.id);
});

test('cleared localStorage recovers the id from the cookie', () => {
    const doc = cookieDoc({ novarr_device: 'w_0123456789abcdef01234567' });
    const store = storage();
    const d = initDevice({ storage: store, doc, nav: { userAgent: UA.mac }, force: true });
    assert.equal(d.id, 'w_0123456789abcdef01234567');
    assert.equal(store.getItem(DEVICE_KEY), d.id);
});

test('an invalid stored id is replaced', () => {
    const store = storage({ [DEVICE_KEY]: 'bad id!' });
    const d = initDevice({ storage: store, doc: null, nav: null, force: true });
    assert.match(d.id, /^w_[0-9a-f]{24}$/);
});

test('blocked storage and cookies still give an identity', () => {
    const doc = { get cookie() { throw new Error('blocked'); }, set cookie(v) { throw new Error('blocked'); } };
    const d = initDevice({ storage: blocked, doc, nav: { userAgent: UA.windows }, force: true });
    assert.ok(isValidDeviceId(d.id));
    assert.equal(d.name, 'Web · Windows');
    assert.equal(initDevice(), d); // cached for the page
});

// ---- Writes carry the device ----
test('fetch headers carry the id and a UTF-8 name', () => {
    const h = deviceHeaders({ id: 'w_0123456789abcdef01234567', name: 'Web · Mac' });
    assert.equal(h[HEADER_ID], 'w_0123456789abcdef01234567');
    // Every code unit is a byte (a valid ByteString), and the bytes are UTF-8.
    assert.ok([...h[HEADER_NAME]].every((c) => c.charCodeAt(0) <= 0xff));
    assert.equal(Buffer.from(h[HEADER_NAME], 'latin1').toString('utf8'), 'Web · Mac');
    assert.equal(toHeaderValue('Web'), 'Web');
    assert.deepEqual(deviceHeaders(null), {});
    assert.deepEqual(deviceHeaders({ id: 'nope', name: 'x' }), {});
});

test('beacon form fields carry device and device_name', () => {
    const form = appendDeviceFields(new FormData(), { id: 'w_0123456789abcdef01234567', name: 'Web · iPad' });
    assert.equal(form.get('device'), 'w_0123456789abcdef01234567');
    assert.equal(form.get('device_name'), 'Web · iPad');
});

test('manga reader writes carry the device: beacon fields, fallback fetch headers', async (t) => {
    const realNav = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const beacons = [];
    const fetches = [];
    Object.defineProperty(globalThis, 'navigator', {
        value: { onLine: true, userAgent: UA.android, sendBeacon: (url, form) => { beacons.push({ url, form }); return true; } },
        configurable: true, writable: true,
    });
    globalThis.window = {};   // no window.Novarr: the plain-fetch fallback
    t.mock.method(globalThis, 'fetch', async (url, opts) => { fetches.push({ url, opts }); return { ok: true }; });
    t.after(() => {
        delete globalThis.window;
        if (realNav) Object.defineProperty(globalThis, 'navigator', realNav);
    });
    resetDeviceForTests();
    const me = initDevice({ storage: storage(), doc: null, nav: globalThis.navigator, force: true });

    const writes = [];
    const ac = new AbortController();
    const p = createProgress({
        chapterId: 9, url: '/chapters/9/progress', stored: null, read: false, count: 10,
        signal: ac.signal, storage: storage(), onWrite: () => writes.push(1),
    });
    p.pageShown(4);
    p.flush(true);                     // pagehide → beacon
    assert.equal(beacons.length, 1);
    assert.equal(beacons[0].form.get('progress'), '40');
    assert.equal(beacons[0].form.get('device'), me.id);
    assert.equal(beacons[0].form.get('device_name'), 'Web · Android');

    p.markRead();                      // plain fetch
    await Promise.resolve();
    assert.equal(fetches.length, 1);
    assert.equal(fetches[0].opts.headers[HEADER_ID], me.id);
    assert.equal(Buffer.from(fetches[0].opts.headers[HEADER_NAME], 'latin1').toString('utf8'), 'Web · Android');
    assert.ok(writes.length >= 2, 'local write bookkeeping fired');
});

// ---- shouldOffer ----
const ME = 'w_me000000000';
const P = (over = {}) => ({
    chapter_id: 124, chapter_label: 'Chapter 124: The Gate', chapter_number: '124',
    progress: 40, downloaded: true, device_id: 'k_kindle01', device_name: 'Kindle',
    at: '2026-11-18T02:10:00+00:00', age_seconds: 7200, url: '/chapters/124', ...over,
});
const base = (over = {}) => ({ position: P(), deviceId: ME, chapterId: 120, ...over });

test('offer: another device, another chapter', () => {
    assert.equal(shouldOffer(base()), true);
});

test('no offer: no position / same device / null device / no own id', () => {
    assert.equal(shouldOffer(base({ position: null })), false);
    assert.equal(shouldOffer(base({ position: P({ device_id: ME }) })), false);
    assert.equal(shouldOffer(base({ position: P({ device_id: null }) })), false);
    assert.equal(shouldOffer(base({ deviceId: null })), false);
});

test('no offer: this exact position was declined; a newer one is offered again', () => {
    assert.equal(shouldOffer(base({ declinedAt: '2026-11-18T02:10:00+00:00' })), false);
    assert.equal(shouldOffer(base({ declinedAt: '2026-11-17T22:00:00+00:00' })), true);
});

test('no offer: local progress newer than the position (relative ages)', () => {
    // Our last write 1 h ago, theirs 2 h ago → ours is newer.
    assert.equal(shouldOffer(base({ localWriteAgeMs: 3600_000 })), false);
    // Ours 3 h ago → theirs is newer.
    assert.equal(shouldOffer(base({ localWriteAgeMs: 3 * 3600_000 })), true);
    // Time since the page was served counts toward the position's age.
    assert.equal(shouldOffer(base({ position: P({ age_seconds: 10 }), localWriteAgeMs: 30_000, elapsedMs: 0 })), true);
    assert.equal(shouldOffer(base({ position: P({ age_seconds: 10 }), localWriteAgeMs: 30_000, elapsedMs: 25_000 })), false);
    // A local clock that jumped backwards (negative age) never prompts.
    assert.equal(shouldOffer(base({ localWriteAgeMs: -5000 })), false);
    // No local write at all.
    assert.equal(shouldOffer(base({ localWriteAgeMs: null })), true);
});

test('same chapter: only when 5 or more points apart', () => {
    const same = (pos, local) => shouldOffer(base({ chapterId: 124, position: P({ progress: pos }), localProgress: local }));
    assert.equal(same(40, 38), false);
    assert.equal(same(40, 36), false);
    assert.equal(same(40, 35), true);
    assert.equal(same(40, 45), true);
    assert.equal(same(40, null), true);   // never read here: 0
    assert.equal(same(3, null), false);
    assert.equal(same(null, 2), false);   // no progress: 0
    // chapter ids compare as strings / numbers alike
    assert.equal(shouldOffer(base({ chapterId: '124', position: P({ progress: 40 }), localProgress: 40 })), false);
});

test('no offer: chapter not downloaded, or not openable', () => {
    assert.equal(shouldOffer(base({ position: P({ downloaded: false }) })), false);
    assert.equal(shouldOffer(base({ canOpen: false })), false);
});

// ---- Text ----
test('relative time', () => {
    assert.equal(relativeTime(0), 'just now');
    assert.equal(relativeTime(59), 'just now');
    assert.equal(relativeTime(60), '1 minute ago');
    assert.equal(relativeTime(5 * 60 + 20), '5 minutes ago');
    assert.equal(relativeTime(3600), '1 hour ago');
    assert.equal(relativeTime(2 * 3600 + 59), '2 hours ago');
    assert.equal(relativeTime(86400 + 10), 'yesterday');
    assert.equal(relativeTime(3 * 86400), '3 days ago');
    assert.equal(relativeTime(90 * 86400), '3 months ago');
    assert.equal(relativeTime(800 * 86400), '2 years ago');
    assert.equal(relativeTime(-5), 'just now');
    assert.equal(relativeTime(null), 'just now');
});

test('prompt text', () => {
    assert.equal(promptText(P()), 'Chapter 124: The Gate · 40% was read on Kindle 2 hours ago. Go there?');
    assert.equal(promptText(P({ chapter_label: null, progress: 100, device_name: null }), 300),
        'Chapter 124 was read on another device 5 minutes ago. Go there?');
    assert.equal(promptText(P({ progress: 0 })), 'Chapter 124: The Gate was read on Kindle 2 hours ago. Go there?');
    assert.equal(promptText(P({ progress: null })), 'Chapter 124: The Gate was read on Kindle 2 hours ago. Go there?');
    assert.equal(positionLabel({ chapter_number: null }), 'Another chapter');
});

test('chapter id from a URL', () => {
    assert.equal(chapterIdFromUrl('/chapters/42'), 42);
    assert.equal(chapterIdFromUrl('https://novarr.test/chapters/42?page=last'), 42);
    assert.equal(chapterIdFromUrl('/chapters/42/progress'), 42);
    assert.equal(chapterIdFromUrl('/novels/3'), null);
});

// ---- Local bookkeeping ----
test('last write, declined marker', () => {
    const s = storage();
    assert.equal(localWriteAge(s, 7, 10_000), null);
    markLocalWrite(s, 7, 4_000);
    assert.equal(s.getItem(lastWriteKey(7)), '4000');
    assert.equal(localWriteAge(s, 7, 10_000), 6_000);
    rememberAnswered(s, 7, P());
    assert.equal(s.getItem(declinedKey(7)), P().at);
    assert.equal(readDeclined(s, 7), P().at);
    // Blocked storage: no throw.
    markLocalWrite(blocked, 7);
    assert.equal(localWriteAge(blocked, 7), null);
});

test('in-reader navigation marker: one use, this chapter only, expires', () => {
    const s = storage();
    markInReaderNav(s, '/chapters/12', 1_000);
    assert.equal(consumeInReaderNav(s, 13, 2_000), false);
    assert.equal(consumeInReaderNav(s, 12, 2_000), false, 'consumed by the first open');
    markInReaderNav(s, 'https://x.test/chapters/12?page=last', 1_000);
    assert.equal(consumeInReaderNav(s, 12, 2_000), true);
    markInReaderNav(s, '/chapters/12', 1_000);
    assert.equal(consumeInReaderNav(s, 12, 1_000 + 6 * 60_000), false);
    assert.equal(consumeInReaderNav(null, 12), false);
});

test('accepted marker carries the target progress to the destination', () => {
    const s = storage();
    markAccepted(s, P({ progress: 73 }), 1_000);
    assert.equal(consumeAccepted(s, 120, 2_000), null, 'another chapter');
    markAccepted(s, P({ progress: 73 }), 1_000);
    assert.equal(consumeAccepted(s, 124, 2_000), 73);
    assert.equal(consumeAccepted(s, 124, 2_000), null, 'one use');
    markAccepted(s, P({ progress: null }), 1_000);
    assert.equal(consumeAccepted(s, 124, 2_000), 0);
});

test('planLatest: accepted / in-reader navigation / offline never offer', () => {
    const s = storage();
    const args = { position: P(), device: { id: ME }, chapterId: 120, novelId: 7, storage: s, now: 1e9 };
    assert.deepEqual(planLatest(args), { offer: true, sameChapter: false });
    assert.deepEqual(planLatest({ ...args, accepted: 40 }), { offer: false, sameChapter: false });
    assert.deepEqual(planLatest({ ...args, inReaderNav: true }), { offer: false, sameChapter: false });
    assert.deepEqual(planLatest({ ...args, online: false }), { offer: false, sameChapter: false });
    assert.deepEqual(planLatest({ ...args, chapterId: 124, localProgress: 10 }), { offer: true, sameChapter: true });
    // Another chapter with no URL to go to.
    assert.deepEqual(planLatest({ ...args, position: P({ url: null }) }), { offer: false, sameChapter: false });
    // Declined in storage.
    rememberAnswered(s, 7, P());
    assert.equal(planLatest(args).offer, false);
    // Our write is newer than theirs.
    const s2 = storage();
    markLocalWrite(s2, 7, 1e9 - 60_000);
    assert.equal(planLatest({ ...args, storage: s2 }).offer, false);
});

test('manga: "Go to latest" percent → page (0 → first, 100 → last)', async () => {
    const { pageForLatest } = await import('../../resources/js/manga-reader/index.js');
    assert.equal(pageForLatest(0, 10), 1);
    assert.equal(pageForLatest(null, 10), 1);
    assert.equal(pageForLatest(40, 10), 4);
    assert.equal(pageForLatest(99, 10), 10);
    assert.equal(pageForLatest(100, 10), 10);
    assert.equal(pageForLatest(50, 0), 1);
});
