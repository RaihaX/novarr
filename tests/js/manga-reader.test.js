// Manga reader (resources/js/manga-reader) pure pieces — run with `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import {
    pageToPercent, percentToPage, resumePage, shouldSaveProgress,
    zoneAction, zoneAt, keyAction, swipeAction, pageToSlider, sliderToPage,
    preloadWindow, nextMode, normaliseMode, nextFit, defaultFit, clampPan, zoomAt, pageLabel,
} from '../../resources/js/manga-reader/logic.js';
import { createProgress, readLocalPosition } from '../../resources/js/manga-reader/progress.js';

// ---- page <-> percent ----

test('pageToPercent: page 1 is at least 1, the last page is 100, nothing else rounds up to 100', () => {
    assert.equal(pageToPercent(1, 1), 100, 'a one-page chapter: its only page is the last');
    assert.equal(pageToPercent(1, 12), 8);
    assert.equal(pageToPercent(6, 12), 50);
    assert.equal(pageToPercent(11, 12), 92);
    assert.equal(pageToPercent(12, 12), 100);
    assert.equal(pageToPercent(1, 250), 1, 'round(0.4) would be 0');
    assert.equal(pageToPercent(1, 300), 1);
    assert.equal(pageToPercent(249, 250), 99, 'round(99.6) capped below 100');
    assert.equal(pageToPercent(299, 300), 99);
    assert.equal(pageToPercent(300, 300), 100);
    assert.equal(pageToPercent(0, 10), 10, 'clamped to page 1');
    assert.equal(pageToPercent(99, 10), 100, 'clamped to the last page');
    assert.equal(pageToPercent(1, 0), 0, 'no pages');
});

test('percentToPage stays within 1..count', () => {
    assert.equal(percentToPage(0, 12), 1);
    assert.equal(percentToPage(1, 12), 1);
    assert.equal(percentToPage(50, 12), 6);
    assert.equal(percentToPage(99, 12), 12);
    assert.equal(percentToPage(100, 12), 12);
    assert.equal(percentToPage(1, 250), 3);
    assert.equal(percentToPage(50, 1), 1);
    assert.equal(percentToPage(50, 0), 1);
});

test('every page of a chapter up to 100 pages survives page -> percent -> page', () => {
    for (const n of [1, 2, 3, 12, 34, 99, 100]) {
        for (let p = 1; p < n; p++) {
            assert.equal(percentToPage(pageToPercent(p, n), n), p, `page ${p} of ${n}`);
        }
    }
});

// ---- resume ----

test('resumePage: ?page= wins (number or last), clamped', () => {
    assert.equal(resumePage({ query: 'last', count: 34 }), 34);
    assert.equal(resumePage({ query: 'LAST', count: 34, progress: 20 }), 34);
    assert.equal(resumePage({ query: '5', count: 34, progress: 80 }), 5);
    assert.equal(resumePage({ query: '500', count: 34 }), 34);
    assert.equal(resumePage({ query: '0', count: 34 }), 1);
    assert.equal(resumePage({ query: 'abc', count: 34, progress: 50 }), 17, 'junk falls through to progress');
});

test('resumePage: progress 1–99 resumes, 0 / 100 / read / none start at page 1', () => {
    assert.equal(resumePage({ progress: null, count: 34 }), 1);
    assert.equal(resumePage({ progress: 0, count: 34 }), 1);
    assert.equal(resumePage({ progress: 1, count: 34 }), 1);
    assert.equal(resumePage({ progress: 1, count: 300 }), 3);
    assert.equal(resumePage({ progress: 35, count: 34 }), 12);
    assert.equal(resumePage({ progress: 99, count: 34 }), 34);
    assert.equal(resumePage({ progress: 100, count: 34 }), 1);
    assert.equal(resumePage({ progress: 50, read: true, count: 34 }), 1);
    assert.equal(resumePage({ progress: 50, count: 0 }), 1);
});

test('resumePage: this device\'s exact page wins only while it matches the server percent', () => {
    // 250 pages: page 101 is 40%, and 40% maps back to page 100.
    assert.equal(resumePage({ progress: 40, count: 250 }), 100);
    assert.equal(resumePage({ progress: 40, count: 250, local: { page: 101, pct: 40 } }), 101);
    // Another device moved on: the server percent no longer matches.
    assert.equal(resumePage({ progress: 60, count: 250, local: { page: 101, pct: 40 } }), 150);
    // A bogus saved page is ignored.
    assert.equal(resumePage({ progress: 40, count: 250, local: { page: 999, pct: 40 } }), 100);
});

test('shouldSaveProgress never lowers the stored value unless the reader moved back', () => {
    assert.equal(shouldSaveProgress(8, null), true, 'nothing stored yet');
    assert.equal(shouldSaveProgress(50, 50), false, 'same value');
    assert.equal(shouldSaveProgress(42, 50), false, 'rounding / re-read on open');
    assert.equal(shouldSaveProgress(58, 50), true);
    assert.equal(shouldSaveProgress(42, 50, true), true, 'moved backwards');
    assert.equal(shouldSaveProgress(8, 100), false, 're-reading a finished chapter');
});

// ---- direction ----

test('tap zones: rtl LEFT is next, ltr RIGHT is next; centre (and any tap in vertical) is the chrome', () => {
    assert.equal(zoneAction('left', 'rtl'), 'next');
    assert.equal(zoneAction('right', 'rtl'), 'prev');
    assert.equal(zoneAction('left', 'ltr'), 'prev');
    assert.equal(zoneAction('right', 'ltr'), 'next');
    assert.equal(zoneAction('center', 'rtl'), 'chrome');
    assert.equal(zoneAction('left', 'vertical'), 'chrome');
    assert.equal(zoneAt(10, 900), 'left');
    assert.equal(zoneAt(450, 900), 'center');
    assert.equal(zoneAt(890, 900), 'right');
    assert.equal(zoneAt(5, 0), 'center');
});

const k = (key, extra = {}) => ({ key, shiftKey: false, ...extra });

test('keys: arrows follow the reading direction', () => {
    assert.equal(keyAction(k('ArrowLeft'), 'rtl'), 'next');
    assert.equal(keyAction(k('ArrowRight'), 'rtl'), 'prev');
    assert.equal(keyAction(k('ArrowLeft'), 'ltr'), 'prev');
    assert.equal(keyAction(k('ArrowRight'), 'ltr'), 'next');
    assert.equal(keyAction(k('ArrowDown'), 'rtl'), 'next');
    assert.equal(keyAction(k('ArrowDown'), 'vertical'), null, 'native scroll');
});

test('keys: space, paging, home/end, chapters, fullscreen, mode, escape', () => {
    assert.equal(keyAction(k(' '), 'ltr'), 'next');
    assert.equal(keyAction(k(' ', { shiftKey: true }), 'ltr'), 'prev');
    assert.equal(keyAction(k(' '), 'vertical'), 'screenDown');
    assert.equal(keyAction(k(' ', { shiftKey: true }), 'vertical'), 'screenUp');
    assert.equal(keyAction(k('PageDown'), 'rtl'), 'next');
    assert.equal(keyAction(k('PageUp'), 'rtl'), 'prev');
    assert.equal(keyAction(k('PageDown'), 'vertical'), 'screenDown');
    assert.equal(keyAction(k('Home'), 'rtl'), 'first');
    assert.equal(keyAction(k('End'), 'vertical'), 'last');
    assert.equal(keyAction(k('['), 'rtl'), 'prevChapter');
    assert.equal(keyAction(k(']'), 'rtl'), 'nextChapter');
    assert.equal(keyAction(k('f'), 'ltr'), 'fullscreen');
    assert.equal(keyAction(k('m'), 'ltr'), 'mode');
    assert.equal(keyAction(k('Escape'), 'ltr'), 'showChrome');
    assert.equal(keyAction(k('x'), 'ltr'), null);
});

test('swipes: a finger moving left reads forward in ltr and back in rtl', () => {
    assert.equal(swipeAction(-120, 5, 200, 'ltr'), 'next');
    assert.equal(swipeAction(120, 5, 200, 'ltr'), 'prev');
    assert.equal(swipeAction(120, 5, 200, 'rtl'), 'next');
    assert.equal(swipeAction(-120, 5, 200, 'rtl'), 'prev');
    assert.equal(swipeAction(-30, 0, 200, 'ltr'), null, 'too short');
    assert.equal(swipeAction(-120, 150, 200, 'ltr'), null, 'mostly vertical');
    assert.equal(swipeAction(-120, 0, 1500, 'ltr'), null, 'too slow');
    assert.equal(swipeAction(-120, 0, 200, 'vertical'), null);
});

test('slider: ltr value is the page; rtl runs right to left', () => {
    assert.equal(pageToSlider(1, 34, 'ltr'), 1);
    assert.equal(pageToSlider(12, 34, 'ltr'), 12);
    assert.equal(pageToSlider(1, 34, 'rtl'), 34, 'page 1 sits at the right end');
    assert.equal(pageToSlider(34, 34, 'rtl'), 1);
    assert.equal(pageToSlider(12, 34, 'rtl'), 23);
    for (const mode of ['ltr', 'rtl', 'vertical']) {
        for (const p of [1, 2, 17, 33, 34]) {
            assert.equal(sliderToPage(pageToSlider(p, 34, mode), 34, mode), p, `${mode} page ${p}`);
        }
    }
    assert.equal(sliderToPage('1', 1, 'rtl'), 1, 'one page');
    assert.equal(sliderToPage(99, 34, 'rtl'), 1, 'out of range clamps');
});

test('preload window: the next three, then the previous one, never past the ends', () => {
    assert.deepEqual(preloadWindow(1, 34), [2, 3, 4]);
    assert.deepEqual(preloadWindow(10, 34), [11, 12, 13, 9]);
    assert.deepEqual(preloadWindow(33, 34), [34, 32]);
    assert.deepEqual(preloadWindow(34, 34), [33]);
    assert.deepEqual(preloadWindow(1, 1), []);
});

test('modes and fits cycle; unknown modes read as ltr', () => {
    assert.equal(nextMode('rtl'), 'ltr');
    assert.equal(nextMode('ltr'), 'vertical');
    assert.equal(nextMode('vertical'), 'rtl');
    assert.equal(normaliseMode('sideways'), 'ltr');
    assert.equal(nextFit('width'), 'height');
    assert.equal(nextFit('original'), 'width');
    assert.equal(defaultFit(390, 844), 'width', 'portrait phone');
    assert.equal(defaultFit(1440, 900), 'height');
    assert.equal(defaultFit(844, 390), 'height', 'landscape phone');
    assert.equal(pageLabel(12, 34), 'Page 12 of 34');
});

test('2x zoom keeps the tapped point under the finger and the pan inside the page', () => {
    assert.deepEqual(zoomAt(100, 200, 400, 800), { x: -100, y: -200 });
    assert.deepEqual(zoomAt(0, 0, 400, 800), { x: 0, y: 0 });
    assert.deepEqual(zoomAt(400, 800, 400, 800), { x: -400, y: -800 });
    assert.deepEqual(clampPan(50, -900, 400, 800), { x: 0, y: -800 });
});

// ---- progress delivery ----

// Node has a read-only global navigator: shadow it for these tests.
const realNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
function fakeGlobals() {
    const sent = [];
    globalThis.window = { Novarr: { queuedFetch: (url, opts) => { sent.push({ url, body: opts.body }); return Promise.resolve({}); } } };
    Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true, writable: true });
    return sent;
}
function restoreGlobals() {
    delete globalThis.window;
    if (realNavigator) Object.defineProperty(globalThis, 'navigator', realNavigator);
    else delete globalThis.navigator;
}

function storage() {
    const m = new Map();
    return { getItem: (key) => (m.has(key) ? m.get(key) : null), setItem: (key, v) => m.set(key, String(v)), removeItem: (key) => m.delete(key) };
}

test('progress: debounced, never lower on open, last page marks read once', async () => {
    const sent = fakeGlobals();
    const store = storage();
    const signal = new AbortController().signal;
    const p = createProgress({ chapterId: 7, url: '/chapters/7/progress', stored: 50, read: false, count: 12, signal, storage: store });

    p.pageShown(6);            // 50%: what the server has
    p.flush();
    assert.deepEqual(sent, []);
    assert.deepEqual(readLocalPosition(store, 7), { page: 6, pct: 50 });

    p.pageShown(7);
    p.pageShown(8);
    p.flush();
    assert.deepEqual(sent, [{ url: '/chapters/7/progress', body: { progress: 67 } }], 'one write for the latest page');

    p.pageShown(5);            // moved back: lower is fine now
    p.flush();
    assert.deepEqual(sent.at(-1).body, { progress: 42 });

    p.pageShown(12);           // last page: immediate read mark
    assert.deepEqual(sent.at(-1).body, { progress: 100, read: true });
    const before = sent.length;
    p.pageShown(12);
    p.markRead();
    assert.equal(sent.length, before, 'read sent once');
    restoreGlobals();
});

test('progress: a re-read of a finished chapter never lowers it; vertical holds the last page at 99', () => {
    const sent = fakeGlobals();
    const signal = new AbortController().signal;
    const reread = createProgress({ chapterId: 1, url: '/p', stored: 100, read: true, count: 4, signal, storage: storage() });
    reread.pageShown(1);
    reread.pageShown(2);
    reread.flush();
    reread.pageShown(4);
    assert.deepEqual(sent, []);

    const vertical = createProgress({ chapterId: 2, url: '/p', stored: null, read: false, count: 4, signal, storage: storage() });
    vertical.pageShown(4, { readAtEnd: false });
    vertical.flush();
    assert.deepEqual(sent.at(-1).body, { progress: 99 });
    vertical.markRead();
    assert.deepEqual(sent.at(-1).body, { progress: 100, read: true });
    restoreGlobals();
});

test('progress: nothing is sent after the visit ended', () => {
    const sent = fakeGlobals();
    const ac = new AbortController();
    const p = createProgress({ chapterId: 3, url: '/p', stored: null, read: false, count: 10, signal: ac.signal, storage: storage() });
    p.pageShown(2);
    ac.abort();
    p.flush();
    p.markRead();
    assert.deepEqual(sent, []);
    restoreGlobals();
});

// ---- wiring ----

const root = new URL('../../', import.meta.url).pathname;

test('the manga view loads its own Vite entry and the entry is built', () => {
    const view = readFileSync(join(root, 'resources/views/chapters/manga.blade.php'), 'utf8');
    assert.match(view, /@push\('scripts'\)\s*@vite\('resources\/js\/manga-reader\/index\.js'\)\s*@endpush/);
    assert.match(readFileSync(join(root, 'vite.config.js'), 'utf8'), /'resources\/js\/manga-reader\/index\.js'/);
    assert.match(readFileSync(join(root, 'resources/css/app.scss'), 'utf8'), /@import "manga-reader";/);
});

import { installMangaReader } from '../../resources/js/manga-reader/index.js';

test('the manga reader mounts #manga-reader once per page and does not claim the text reader', () => {
    const doc = new EventTarget();
    doc.roots = {};
    doc.querySelector = (sel) => doc.roots[sel] || null;
    const win = { CustomEvent };
    let mounts = 0;
    let teardowns = 0;
    const page = {};
    doc.roots['#manga-reader'] = page;
    installMangaReader(doc, win, () => { mounts += 1; return () => { teardowns += 1; }; });
    doc.dispatchEvent(new Event('turbo:load'));
    assert.equal(mounts, 1);
    doc.dispatchEvent(new Event('turbo:before-render'));
    assert.equal(teardowns, 1);
    assert.equal(win.__novarrReaderOwner, undefined, 'the text reader keeps its own owner key');
});

// ---- look-up opens, per-browser reading mode ----

import { isMangaLookup } from '../../resources/js/manga-reader/progress.js';
import { readModeOverride, modeOverrideKey } from '../../resources/js/manga-reader/index.js';

test('lookup: only the opened chapter, only when the page says so', () => {
    assert.equal(isMangaLookup({ lookup: '1', id: 9 }, 9), true);
    assert.equal(isMangaLookup({ lookup: '', id: 9 }, 9), false);
    assert.equal(isMangaLookup({ lookup: '1', id: 9 }, 10), false);
});

test('progress: a look-up posts no page progress and marks read with `lookup` at the end', () => {
    const sent = fakeGlobals();
    const store = storage();
    let writes = 0;
    const p = createProgress({ chapterId: 5, url: '/p', stored: null, read: false, count: 6, signal: new AbortController().signal, storage: store, lookup: true, onWrite: () => { writes += 1; } });
    p.pageShown(2);
    p.pageShown(4);
    p.flush();
    assert.deepEqual(sent, [], 'no percents');
    assert.equal(writes, 0, 'not a local write for the latest-position prompt');
    assert.deepEqual(readLocalPosition(store, 5), { page: 4, pct: 67 }, 'this device still remembers the page');
    p.pageShown(6);
    assert.deepEqual(sent, [{ url: '/p', body: { read: true, lookup: true } }]);
    p.markRead();
    assert.equal(sent.length, 1, 'once');

    const alreadyRead = createProgress({ chapterId: 6, url: '/p', stored: 100, read: true, count: 3, signal: new AbortController().signal, storage: storage(), lookup: true });
    alreadyRead.pageShown(3);
    assert.equal(sent.length, 1, 'a read chapter sends nothing');
    restoreGlobals();
});

test('reading mode override: per series, only real modes', () => {
    const s = storage();
    assert.equal(readModeOverride(s, 4), null);
    s.setItem(modeOverrideKey(4), 'vertical');
    assert.equal(readModeOverride(s, 4), 'vertical');
    assert.equal(readModeOverride(s, 5), null, 'another series keeps its default');
    s.setItem(modeOverrideKey(4), 'sideways');
    assert.equal(readModeOverride(s, 4), null);
    const blocked = { getItem: () => { throw new Error('blocked'); } };
    assert.equal(readModeOverride(blocked, 4), null);
});
