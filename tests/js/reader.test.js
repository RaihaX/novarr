// Chapter reader (resources/js/reader) pure pieces — run with `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

import { createPrefs } from '../../resources/js/reader/prefs.js';
import { chLabel } from '../../resources/js/reader/format.js';
import { findCurrentIdx } from '../../resources/js/reader/navigation.js';
import { applyFooterState } from '../../resources/js/reader/footer.js';
import { shortcutBlocked, isTypingTarget, SHORTCUTS } from '../../resources/js/reader/shortcuts.js';
import { createWakeLock } from '../../resources/js/reader/wakelock.js';

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

function fakeEl() {
    const classes = new Set();
    const attrs = {};
    return {
        dataset: {},
        textContent: '',
        attrs,
        classList: {
            toggle: (c, on) => { (on ?? !classes.has(c)) ? classes.add(c) : classes.delete(c); },
            contains: (c) => classes.has(c),
        },
        setAttribute: (k, v) => { attrs[k] = String(v); },
    };
}

// A keydown target: `closest(sel)` answers for the selectors we care about.
function target({ typing = false, inToc = false, editable = false } = {}) {
    return {
        isContentEditable: editable,
        closest: (sel) => {
            if (sel.includes('input') && typing) return {};
            if (sel === '#tocPanel' && inToc) return {};
            return null;
        },
    };
}
const key = (k, extra = {}) => ({ key: k, altKey: false, metaKey: false, ctrlKey: false, shiftKey: false, target: target(), ...extra });

// ---- Preferences ----
test('prefs fall back to the defaults', () => {
    const { prefs } = createPrefs(storage(), 7);
    assert.deepEqual(prefs, {
        autoNext: '1', font: 19, measure: 68, theme: 'dark', family: 'read',
        lineHeight: '1.75', margin: 'm', justify: '0',
    });
});

test('prefs load stored values, clamp and step them, and migrate legacy keys', () => {
    const { prefs } = createPrefs(storage({
        reader_font: '40', reader_lineheight: '1.8', reader_width: 'wide', reader_autonext: '0',
    }), 7);
    assert.equal(prefs.font, 24);              // clamped to FONT_MAX
    assert.equal(prefs.lineHeight, '1.75');    // old "Normal"
    assert.equal(prefs.measure, 76);           // legacy width bucket
    assert.equal(prefs.autoNext, '0');

    assert.equal(createPrefs(storage({ reader_measure: '61' }), 7).prefs.measure, 62);   // 2ch steps
    assert.equal(createPrefs(storage({ reader_measure: 'x', reader_width: 'wide' }), 7).prefs.measure, 68);
});

test('a per-novel override wins over the globals and receives the writes', () => {
    const s = storage({ reader_theme: 'sepia', reader_font: '18', reader_novel_7: JSON.stringify({ theme: 'light', font: '22' }) });
    const store = createPrefs(s, 7);
    assert.equal(store.prefs.theme, 'light');
    assert.equal(store.prefs.font, 22);
    assert.ok(store.perNovel);

    store.persist('family', 'serif');
    assert.equal(JSON.parse(s.dump().reader_novel_7).family, 'serif');
    assert.equal(s.getItem('reader_family'), null);
    // Behavioural prefs stay global even with an override.
    store.persist('autoNext', '0');
    assert.equal(s.getItem('reader_autonext'), '0');

    // Another novel only sees the globals.
    assert.equal(createPrefs(s, 8).prefs.theme, 'sepia');
});

test('turning the per-novel override on snapshots, off restores the globals', () => {
    const s = storage({ reader_theme: 'sepia' });
    const store = createPrefs(s, 3);
    store.setPerNovel(true);
    assert.equal(JSON.parse(s.getItem('reader_novel_3')).theme, 'sepia');
    store.persist('theme', 'light');
    assert.equal(s.getItem('reader_theme'), 'sepia');
    assert.equal(store.prefs.theme, 'light');

    store.setPerNovel(false);
    assert.equal(s.getItem('reader_novel_3'), null);
    assert.equal(store.perNovel, null);
    assert.equal(store.prefs.theme, 'sepia');
    store.persist('font', 21);
    assert.equal(s.getItem('reader_font'), '21');
    assert.equal(store.prefs.font, 21);
});

test('a corrupt per-novel snapshot is ignored', () => {
    const store = createPrefs(storage({ reader_novel_1: '{nope', reader_theme: 'light' }), 1);
    assert.equal(store.perNovel, null);
    assert.equal(store.prefs.theme, 'light');
});

// ---- Chapter label ----
test('chLabel prefixes the number only when the label lacks it', () => {
    assert.equal(chLabel({ chapter: 12, label: 'The Storm' }), 'Ch. 12 · The Storm');
    assert.equal(chLabel({ chapter: 12, label: 'Chapter 12 - The Storm' }), 'Chapter 12 - The Storm');
    assert.equal(chLabel({ chapter: '12.00', label: 'Ch. 12: Storm' }), 'Ch. 12: Storm');
    assert.equal(chLabel({ chapter: 12, label: '#12 Storm' }), '#12 Storm');
    // 120 is not 12.
    assert.equal(chLabel({ chapter: 12, label: 'Chapter 120' }), 'Ch. 12 · Chapter 120');
    assert.equal(chLabel({ chapter: 12.5, label: '' }), 'Chapter 12.5');
    assert.equal(chLabel({ chapter: 'Prologue', label: null }), 'Chapter Prologue');
});

test('chLabel truncates to the given length with an ellipsis', () => {
    const out = chLabel({ chapter: 3, label: 'A very long chapter title that keeps going on and on' });
    assert.equal(out.length, 40);
    assert.ok(out.endsWith('…'));
    assert.equal(chLabel({ chapter: 3, label: 'Short' }, 200), 'Ch. 3 · Short');
});

// ---- Current section ----
test('findCurrentIdx picks the last section whose top passed 40% of the viewport', () => {
    // Viewport-relative tops, as getBoundingClientRect reports them.
    const at = (...tops) => tops.map((top) => ({ el: { getBoundingClientRect: () => ({ top }) } }));
    const vh = 1000;   // reference line at 400px
    assert.equal(findCurrentIdx(at(0), 0, vh), 0);
    assert.equal(findCurrentIdx(at(-3000, 600), 3000, vh), 0);
    assert.equal(findCurrentIdx(at(-3000, 400), 3000, vh), 1);
    assert.equal(findCurrentIdx(at(-6000, -2000, 399, 2400), 6000, vh), 2);
    // Nothing has reached the line yet (page restored mid-scroll): first one.
    assert.equal(findCurrentIdx(at(900, 4000), 0, vh), 0);
});

// ---- Footer state (continuous reading) ----
test('applyFooterState points both footer controls at the section', () => {
    const readThrough = fakeEl();
    const readToggle = fakeEl();
    readThrough.dataset.id = '1';
    readToggle.dataset.id = '1';

    applyFooterState({ readThrough, readToggle }, { id: 2, read: true });
    assert.equal(readThrough.dataset.id, '2');
    assert.equal(readToggle.dataset.id, '2');
    assert.equal(readToggle.dataset.read, '1');
    assert.equal(readToggle.attrs['aria-pressed'], 'true');
    assert.ok(readToggle.classList.contains('is-read'));
    assert.equal(readToggle.textContent, 'Read');

    applyFooterState({ readThrough, readToggle }, { id: 3, read: false });
    assert.equal(readToggle.dataset.id, '3');
    assert.equal(readToggle.dataset.read, '0');
    assert.equal(readToggle.attrs['aria-pressed'], 'false');
    assert.ok(!readToggle.classList.contains('is-read'));
    assert.equal(readToggle.textContent, 'Mark read');

    // A pending chapter's page has no toggle; must not throw.
    applyFooterState({ readThrough, readToggle: null }, { id: 4 });
    assert.equal(readThrough.dataset.id, '4');
});

// ---- Shortcut guard ----
test('shortcuts fire on a plain key with nothing open', () => {
    assert.equal(shortcutBlocked(key('j')), false);
    assert.equal(shortcutBlocked(key('ArrowLeft')), false);
    for (const k of ['ArrowLeft', 'ArrowRight', 'j', 'k', 't', 'a', 'f', 'p']) assert.ok(SHORTCUTS[k], k);
    assert.equal(SHORTCUTS.j, 'next');
    assert.equal(SHORTCUTS.k, 'prev');
});

test('shortcuts ignore modified keys (Alt+← is browser Back)', () => {
    for (const mod of ['altKey', 'metaKey', 'ctrlKey', 'shiftKey']) {
        assert.equal(shortcutBlocked(key('ArrowLeft', { [mod]: true })), true, mod);
    }
});

test('shortcuts ignore typing, the contents drawer and open dialogs', () => {
    assert.equal(shortcutBlocked(key('t', { target: target({ typing: true }) })), true);
    assert.equal(shortcutBlocked(key('t', { target: target({ editable: true }) })), true);
    assert.equal(shortcutBlocked(key('ArrowRight', { target: target({ inToc: true }) })), true);
    assert.equal(shortcutBlocked(key('a'), { dialogOpen: true }), true);
    assert.equal(isTypingTarget(null), false);
    assert.equal(shortcutBlocked(key('a', { target: null })), false);
});

// ---- Wake lock ----
function fakeWakeLock() {
    const calls = { request: 0, release: 0 };
    let resolveNext = null;
    const api = {
        calls,
        deferred: false,
        request() {
            calls.request += 1;
            const sentinel = {
                listeners: {},
                addEventListener(n, f) { this.listeners[n] = f; },
                release() { calls.release += 1; this.listeners.release?.(); return Promise.resolve(); },
            };
            api.last = sentinel;
            if (!api.deferred) return Promise.resolve(sentinel);
            return new Promise((r) => { resolveNext = () => r(sentinel); });
        },
        resolve: () => resolveNext?.(),
    };
    return api;
}

test('wake lock is held while wanted and released when not', async () => {
    const wl = fakeWakeLock();
    const doc = { visibilityState: 'visible' };
    const lock = createWakeLock({ wakeLock: wl }, doc);
    await lock.set(true);
    assert.equal(lock.held, true);
    await lock.set(true);
    assert.equal(wl.calls.request, 1);   // no duplicate request
    lock.set(false);
    assert.equal(lock.held, false);
    assert.equal(wl.calls.release, 1);
});

test('wake lock re-acquires when the page becomes visible again', async () => {
    const wl = fakeWakeLock();
    const doc = { visibilityState: 'visible' };
    const lock = createWakeLock({ wakeLock: wl }, doc);
    await lock.set(true);
    // The browser drops the lock when the page is hidden.
    doc.visibilityState = 'hidden';
    wl.last.listeners.release();
    assert.equal(lock.held, false);
    await lock.visibilityChanged();
    assert.equal(wl.calls.request, 1);   // not while hidden
    doc.visibilityState = 'visible';
    await lock.visibilityChanged();
    assert.equal(wl.calls.request, 2);
    assert.equal(lock.held, true);
    // Once stopped, coming back does not take it again.
    lock.release();
    doc.visibilityState = 'hidden';
    doc.visibilityState = 'visible';
    await lock.visibilityChanged();
    assert.equal(wl.calls.request, 2);
});

test('a wake lock that resolves after release is let go at once', async () => {
    const wl = fakeWakeLock();
    wl.deferred = true;
    const lock = createWakeLock({ wakeLock: wl }, { visibilityState: 'visible' });
    const pending = lock.set(true);
    lock.release();
    wl.resolve();
    await pending;
    assert.equal(lock.held, false);
    assert.equal(wl.calls.release, 1);
});

test('wake lock is a silent no-op without the API or when refused', async () => {
    await createWakeLock({}, { visibilityState: 'visible' }).set(true);
    const refused = createWakeLock({ wakeLock: { request: () => Promise.reject(new Error('NotAllowedError')) } }, { visibilityState: 'visible' });
    await refused.set(true);
    assert.equal(refused.held, false);
});

// ---- The view loads the module and carries no inline reader logic ----

const root = new URL('../../', import.meta.url).pathname;
const view = readFileSync(join(root, 'resources/views/chapters/show.blade.php'), 'utf8');
const readerSrc = readdirSync(join(root, 'resources/js/reader'))
    .map((f) => readFileSync(join(root, 'resources/js/reader', f), 'utf8')).join('\n');

test('the reader view loads resources/js/reader as a Vite entry, with no inline script but the state', () => {
    assert.match(view, /@push\('scripts'\)\s*@vite\('resources\/js\/reader\/index\.js'\)\s*@endpush/);
    const scripts = [...view.matchAll(/<script\b[^>]*>/g)].map((m) => m[0]);
    assert.deepEqual(scripts, ['<script type="application/json" id="readerState">']);
    assert.ok(!/\$prev->id\) \}\}">\s*$/m.test(view.split('@endpush')[0]), 'no prefetch of the previous chapter');
    assert.match(readFileSync(join(root, 'vite.config.js'), 'utf8'), /'resources\/js\/reader\/index\.js'/);
});

test('the reader keeps the shell focus-trap hook and inert plumbing', () => {
    assert.ok(readerSrc.includes('window.Novarr'));
    assert.ok(readerSrc.includes('focusTrap'));
    assert.ok(readerSrc.includes('.inert = true'));
});

test('the "?" panel lists exactly the single-key shortcuts that are bound', () => {
    const panel = view.slice(view.indexOf('id="readerKeys"'), view.indexOf('</dl>', view.indexOf('id="readerKeys"')));
    const listed = new Set([...panel.matchAll(/<kbd>([^<]+)<\/kbd>/g)].map((m) => m[1]));
    const letters = Object.keys(SHORTCUTS).filter((k) => k.length === 1);
    for (const k of letters) assert.ok(listed.has(k), `bound key ${k} is listed`);
    for (const k of listed) {
        assert.ok(letters.includes(k) || ['&larr;', '&rarr;', 'Esc', '?'].includes(k), `listed key ${k} is bound`);
    }
});

// ---- Exactly one reader per page ----

import { installReader } from '../../resources/js/reader/index.js';

/** A document stand-in: real events, and a querySelector over `roots`. */
function fakeDom() {
    const doc = new EventTarget();
    doc.roots = {};
    doc.querySelector = (sel) => doc.roots[sel] || null;
    const win = { CustomEvent };
    return { doc, win };
}

function counter() {
    const log = { mounts: 0, teardowns: 0 };
    log.mount = () => { log.mounts += 1; return () => { log.teardowns += 1; }; };
    return log;
}

test('the reader mounts only roots marked data-reader-module (old cached pages run their own script)', () => {
    const { doc, win } = fakeDom();
    const log = counter();
    installReader(doc, win, log.mount);
    // A page cached before the module existed: data-reader-page only.
    doc.roots['[data-reader-page]'] = {};
    doc.dispatchEvent(new Event('turbo:load'));
    assert.equal(log.mounts, 0);

    const page = {};
    doc.roots = { '[data-reader-page]': page, '[data-reader-module]': page };
    doc.dispatchEvent(new Event('turbo:load'));
    doc.dispatchEvent(new Event('turbo:load'));
    assert.equal(log.mounts, 1, 'repeated turbo:load on one page mounts once');
});

test('a second copy of the module (after a deploy) takes over; the first tears down and goes quiet', () => {
    const { doc, win } = fakeDom();
    const first = counter();
    const second = counter();
    const page1 = {};
    doc.roots = { '[data-reader-module]': page1 };
    installReader(doc, win, first.mount);
    assert.equal(first.mounts, 1);

    // Turbo visit to the next chapter: the old copy's turbo:load fires before
    // the new module (new hash) has evaluated.
    doc.dispatchEvent(new Event('turbo:before-render'));
    const page2 = {};
    doc.roots = { '[data-reader-module]': page2 };
    doc.dispatchEvent(new Event('turbo:load'));
    assert.equal(first.mounts, 2);
    installReader(doc, win, second.mount);
    assert.equal(first.teardowns, 2, 'old copy released page 2 before the new one mounted');
    assert.equal(second.mounts, 1);

    // From now on only the new copy reacts.
    doc.dispatchEvent(new Event('turbo:before-render'));
    doc.roots = { '[data-reader-module]': {} };
    doc.dispatchEvent(new Event('turbo:load'));
    assert.equal(first.mounts, 2);
    assert.equal(second.mounts, 2);
    assert.equal(second.teardowns, 1);
});

test('the reader view marks its root data-reader-module', () => {
    assert.match(view, /<div class="reader" id="reader" data-reader-page data-reader-module>/);
});
