// Paged view (rtl / ltr): one page fitted to the viewport, tap zones, swipe,
// double-tap 2x zoom with drag-to-pan, the end card after the last page, and
// a small preload window (never the whole chapter).
import {
    preloadWindow, zoneAt, zoneAction, swipeAction, zoomAt, clampPan,
} from './logic.js';
import { buildEndCard, buildPageError } from './endcard.js';

const DOUBLE_TAP_MS = 280;
const TAP_SLOP = 10;

export function createPagedView(o) {
    const { stage, pages, chapter, next, novelUrl, novelName, retryIcon } = o;
    const count = pages.length;
    let mode = o.mode;
    let fit = o.fit;

    const abort = new AbortController();
    const signal = abort.signal;

    stage.replaceChildren();
    stage.className = 'mr-stage mr-paged';
    stage.removeAttribute('tabindex');

    const zoomEl = document.createElement('div');
    zoomEl.className = 'mr-zoom';
    const errorEl = buildPageError(1, () => retry(), retryIcon);
    const endEl = buildEndCard({ chapter, next, novelUrl, novelName });
    endEl.hidden = true;
    stage.append(zoomEl, errorEl, endEl);

    let page = 1;
    let atEnd = false;
    let img = null;
    const zoom = { on: false, x: 0, y: 0 };
    const preloaded = new Map();   // page -> Image

    function applyFit() {
        stage.classList.remove('fit-width', 'fit-height', 'fit-original');
        stage.classList.add('fit-' + fit);
        stage.classList.toggle('is-rtl', mode === 'rtl');
    }
    applyFit();

    function applyZoom() {
        zoomEl.style.transform = zoom.on ? `translate(${zoom.x}px, ${zoom.y}px) scale(2)` : '';
        stage.classList.toggle('is-zoomed', zoom.on);
    }
    function resetZoom() {
        zoom.on = false;
        zoom.x = 0;
        zoom.y = 0;
        applyZoom();
    }
    function toggleZoom(clientX, clientY) {
        if (zoom.on || atEnd) { resetZoom(); return; }
        const r = zoomEl.getBoundingClientRect();
        const p = zoomAt(clientX - r.left, clientY - r.top, r.width, r.height);
        zoom.on = true;
        zoom.x = p.x;
        zoom.y = p.y;
        applyZoom();
    }

    function scrollHome() {
        // Fit-height spreads / original size can overflow: start at the
        // reading edge (right in rtl).
        stage.scrollTop = 0;
        stage.scrollLeft = mode === 'rtl' ? stage.scrollWidth : 0;
    }

    function preload(around) {
        const want = new Set(preloadWindow(around, count));
        for (const [n, im] of preloaded) {
            if (!want.has(n)) { im.src = ''; preloaded.delete(n); }
        }
        for (const n of want) {
            if (preloaded.has(n)) continue;
            const im = new Image();
            im.decoding = 'async';
            im.src = pages[n - 1].url;
            preloaded.set(n, im);
        }
    }

    function buildImg(p) {
        const meta = pages[p - 1];
        const im = document.createElement('img');
        im.className = 'mr-img';
        im.alt = `Page ${p}`;
        im.decoding = 'async';
        im.draggable = false;
        if (meta.width && meta.height) {
            im.width = meta.width;
            im.height = meta.height;
        }
        im.addEventListener('error', () => {
            if (img !== im) return;
            errorEl.querySelector('.mr-error-text').textContent = `Page ${p} didn't load.`;
            errorEl.querySelector('.mr-retry').setAttribute('aria-label', `Retry page ${p}`);
            errorEl.hidden = false;
            stage.classList.add('has-error');
        });
        im.addEventListener('load', () => {
            if (img !== im) return;
            errorEl.hidden = true;
            stage.classList.remove('has-error');
        });
        im.src = meta.url;
        return im;
    }

    function retry() {
        if (!img) return;
        errorEl.hidden = true;
        stage.classList.remove('has-error');
        const src = pages[page - 1].url;
        img.removeAttribute('src');
        img.src = src;
    }

    /** Show page `p` (1..count). `user` = moved by the reader (not on open). */
    function show(p, { user = false } = {}) {
        p = Math.max(1, Math.min(count, p));
        resetZoom();
        atEnd = false;
        endEl.hidden = true;
        zoomEl.hidden = false;
        errorEl.hidden = true;
        stage.classList.remove('has-error', 'at-end');
        page = p;
        img = buildImg(p);
        zoomEl.replaceChildren(img);
        scrollHome();
        preload(p);
        o.onPage(p, { user });
    }

    function showEnd() {
        resetZoom();
        atEnd = true;
        zoomEl.hidden = true;
        errorEl.hidden = true;
        endEl.hidden = false;
        stage.classList.add('at-end');
        stage.scrollTop = 0;
        o.onEnd?.();
    }

    function nextPage() {
        if (atEnd) {
            if (next?.downloaded) o.onAction('nextChapter');
            return;
        }
        if (page < count) show(page + 1, { user: true });
        else showEnd();
    }
    function prevPage() {
        if (atEnd) { show(count, { user: true }); return; }
        if (page > 1) show(page - 1, { user: true });
        else o.onBeforeFirst?.();
    }

    // ---- Pointer input: taps (zones / double-tap zoom), swipes, pan ----
    let start = null;
    let pan = null;
    let pendingTap = null;

    const onControl = (t) => !!t.closest?.('a, button, input, select, textarea, .mr-end');

    stage.addEventListener('pointerdown', (e) => {
        if (!e.isPrimary || e.button > 0 || onControl(e.target)) { start = null; return; }
        start = { x: e.clientX, y: e.clientY, t: Date.now(), type: e.pointerType };
        if (zoom.on) {
            pan = { ox: zoom.x, oy: zoom.y };
            zoomEl.classList.add('is-panning');
            try { stage.setPointerCapture(e.pointerId); } catch (err) { /* not capturable */ }
        }
    }, { signal });

    stage.addEventListener('pointermove', (e) => {
        if (!start || !pan) return;
        const p = clampPan(pan.ox + e.clientX - start.x, pan.oy + e.clientY - start.y, zoomEl.offsetWidth, zoomEl.offsetHeight);
        zoom.x = p.x;
        zoom.y = p.y;
        applyZoom();
    }, { signal });

    function endPointer() {
        pan = null;
        zoomEl.classList.remove('is-panning');
    }

    stage.addEventListener('pointercancel', () => { start = null; endPointer(); }, { signal });

    stage.addEventListener('pointerup', (e) => {
        if (!start) return;
        const s = start;
        start = null;
        const dx = e.clientX - s.x;
        const dy = e.clientY - s.y;
        const dt = Date.now() - s.t;
        const wasPanning = !!pan;
        endPointer();
        const moved = Math.hypot(dx, dy) > TAP_SLOP;
        if (!moved && dt < 500) { tap(e.clientX, e.clientY, s.type); return; }
        if (wasPanning || s.type === 'mouse' || zoom.on) return;
        // A page wider than the screen scrolls sideways natively instead.
        if (stage.scrollWidth > stage.clientWidth + 2) return;
        const action = swipeAction(dx, dy, dt, mode);
        if (action) o.onAction(action, { gesture: true });
    }, { signal });

    function tap(x, y, type) {
        const r = stage.getBoundingClientRect();
        const zone = zoneAt(x - r.left, r.width);
        // Zoomed in, a tap only toggles the chrome (drag pans, double-tap zooms out).
        const run = () => o.onAction(zoom.on ? 'chrome' : zoneAction(zone, mode), { gesture: true });
        // Wait a moment to tell a double-tap / double-click (zoom) from a
        // tap — a mouse too, or a double-click would turn two pages first.
        const now = Date.now();
        if (pendingTap && now - pendingTap.t < DOUBLE_TAP_MS + 120 && Math.hypot(x - pendingTap.x, y - pendingTap.y) < 40) {
            clearTimeout(pendingTap.timer);
            pendingTap = null;
            toggleZoom(x, y);
            return;
        }
        if (pendingTap) clearTimeout(pendingTap.timer);
        pendingTap = { t: now, x, y, timer: setTimeout(() => { pendingTap = null; run(); }, DOUBLE_TAP_MS) };
    }

    return {
        show,
        next: nextPage,
        prev: prevPage,
        first: () => show(1, { user: true }),
        last: () => show(count, { user: true }),
        screen: (dir) => (dir > 0 ? nextPage() : prevPage()),
        current: () => page,
        get atEnd() { return atEnd; },
        setFit(f) { fit = f; resetZoom(); applyFit(); scrollHome(); },
        destroy() {
            abort.abort();
            if (pendingTap) clearTimeout(pendingTap.timer);
            for (const im of preloaded.values()) im.src = '';
            preloaded.clear();
            stage.replaceChildren();
        },
    };
}
