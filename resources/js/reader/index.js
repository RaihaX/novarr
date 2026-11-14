// Chapter reader (resources/views/chapters/show.blade.php).
//
// Loaded with @vite from the reader view, so the hashed URL is in the page
// HTML and the service worker keeps it for offline chapters. A module runs
// once per document: Turbo re-inserting the same <script type="module"> on a
// later visit does not evaluate it again, and continuous reading never
// inserts the fetched page's scripts. So mounting is driven by turbo:load,
// plus one attempt at evaluation for the visit that first loaded the module
// (on a Turbo visit, turbo:load may fire before the module has arrived).
import { createDialogs, reducedMotion } from './dialogs.js';
import { createPrefs, applyPrefs } from './prefs.js';
import { initChrome } from './chrome.js';
import { initNavigation } from './navigation.js';
import { initProgress } from './progress.js';
import { initContinuous } from './continuous.js';
import { initToc } from './toc.js';
import { initReadState } from './readstate.js';
import { initHighlights } from './highlights.js';
import { initAutoScroll } from './autoscroll.js';
import { initTts } from './tts.js';
import { initAutoMark } from './automark.js';
import { createWakeLock } from './wakelock.js';

/**
 * Wire the reader inside `root` (#reader). Returns an idempotent teardown.
 */
export function mount(root) {
    const state = JSON.parse(document.getElementById('readerState').textContent);

    // ---- Per-visit lifecycle ----
    // Listeners on document / window outlive the page. One AbortController
    // per visit: every such listener passes { signal }, and teardown() aborts
    // them all, so handlers never pile up across visits (duplicate
    // arrow-key/swipe navigations, stale progress reports for a chapter
    // we've already left).
    const pageAbort = new AbortController();
    const signal = pageAbort.signal;

    const ctx = {
        root, state, signal, reducedMotion,
        dialogs: createDialogs(),
        store: createPrefs(localStorage, state.novelId),
    };
    ctx.applyPrefs = () => applyPrefs(root, ctx.store);

    // Keep the screen awake while auto-scroll or read-aloud is running.
    const wake = createWakeLock();
    ctx.updateWakeLock = () => wake.set(!!(ctx.isAutoScrolling?.() || ctx.isSpeaking?.()));
    document.addEventListener('visibilitychange', () => wake.visibilityChanged(), { signal });

    // Order matters: it is the order the listeners (Esc handlers especially)
    // were registered in when this was one inline script.
    initChrome(ctx);
    initNavigation(ctx);
    ctx.applyPrefs();
    const cleanups = [initProgress(ctx)];
    const continuousCleanup = initContinuous(ctx);
    cleanups.push(initToc(ctx));
    initReadState(ctx);
    const highlightsCleanup = initHighlights(ctx);
    initAutoScroll(ctx);
    initTts(ctx);
    initAutoMark(ctx);

    // ---- Teardown: leaving this page via Turbo ----
    // Runs on turbo:before-cache / turbo:before-render (see below), while
    // this page's DOM is still in place, so the final progress flush
    // measures the right chapter. Idempotent.
    return function teardown() {
        if (signal.aborted) return;
        try { ctx.syncProgress(ctx.sections[ctx.currentIdx].id, ctx.currentProgressPct()); } catch (e) { /* best effort */ }
        cleanups.forEach(fn => fn());
        highlightsCleanup();
        ctx.stopAutoScroll();
        ctx.ttsStopAll();
        wake.release();
        continuousCleanup();
        // Release every dialog hold (focus traps, inert) and body scroll locks,
        // so nothing leaks into the next page if we leave with a sheet open.
        [...ctx.dialogs.holds].forEach(h => ctx.dialogs.releaseDialog(h, false));
        document.body.classList.remove('reader-sheet-open', 'reader-playbar-open', 'reader-keys-open');
        try { window.bootstrap?.Offcanvas.getInstance(ctx.tocPanelNode)?.dispose(); } catch (e) { /* best effort */ }
        document.querySelectorAll('.offcanvas-backdrop').forEach(n => n.remove());
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        pageAbort.abort();   // removes every { signal } listener
    };
}

/**
 * Mount the reader on each Turbo visit to a chapter page. Two guards keep it
 * to exactly one reader per page:
 *  - only roots marked data-reader-module are mounted: a chapter page cached
 *    by the service worker before the reader became a module carries its own
 *    inline copy of the reader (and only data-reader-page), and runs that;
 *  - a tab left open across a deploy can evaluate a second copy of this
 *    module (new hashed filename). The latest copy claims ownership on
 *    `win`; an older copy tears down what it mounted and stops listening.
 * Exported (with injectable doc / win / mount) for tests/js/reader.test.js.
 */
export function installReader(doc, win, mountFn = mount) {
    const owner = Symbol('novarr-reader');
    let active = null;   // { root, teardown } for the reader on screen

    const isOwner = () => win.__novarrReaderOwner === owner;

    // before-cache fires only when Turbo snapshots the page (the layout sets
    // turbo-cache-control: no-cache, so usually it won't); before-render
    // fires on every Turbo render that replaces this page.
    function leave() {
        if (!active) return;
        const { teardown } = active;
        active = null;
        teardown();
    }

    function boot() {
        if (!isOwner()) { retire(); return; }
        const root = doc.querySelector('[data-reader-module]');
        if (root === active?.root) return;
        // A previous reader that left without a Turbo render (shouldn't happen).
        leave();
        if (root) active = { root, teardown: mountFn(root) };
    }

    function claimed() {
        if (!isOwner()) retire();
    }

    // Superseded by a newer copy: release this page and go quiet for good.
    function retire() {
        leave();
        doc.removeEventListener('turbo:load', boot);
        doc.removeEventListener('turbo:before-cache', leave);
        doc.removeEventListener('turbo:before-render', leave);
        doc.removeEventListener('novarr:reader-claimed', claimed);
    }

    win.__novarrReaderOwner = owner;
    // Older copies hear this synchronously and tear down before we mount.
    doc.dispatchEvent(new win.CustomEvent('novarr:reader-claimed'));
    doc.addEventListener('turbo:load', boot);
    doc.addEventListener('turbo:before-cache', leave);
    doc.addEventListener('turbo:before-render', leave);
    doc.addEventListener('novarr:reader-claimed', claimed);
    boot();
    return { boot, leave, isOwner };
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    installReader(document, window);
}
