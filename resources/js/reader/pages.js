// "Pages" reading mode (Layout › Reading): Kindle-style page turns instead of
// free scrolling. The chapter stays one long scrolled document (so progress,
// highlights, read-aloud and continuous loading keep working unchanged); a
// page turn jumps the window by one screen, lined up so no line is ever cut
// in half: the line straddling the bottom edge is masked on this page and
// becomes the first line of the next one.
//
// Tap the left third = back, the right third = forward, the middle = show /
// hide the bar. Swipe left / right, ← →, Space, PageUp/PageDown and the
// mouse wheel turn pages too. Free scrolling is blocked while it is on.
import { onTap } from './tap.js';

/** Which part of the page a tap at clientX `x` on a `width`-wide screen is. */
export function tapZone(x, width) {
    if (x < width / 3) return 'prev';
    if (x > (width * 2) / 3) return 'next';
    return 'menu';
}

/**
 * Where the next page should start (a viewport y): the top of the line cut by
 * the page's bottom edge, so it is read whole on the next page; the edge
 * itself when nothing is cut. Never less than a quarter of a page forward (a
 * huge image or a bad measurement must not stall the reader).
 *
 * @param {number} top     the page's top edge (under the progress rail)
 * @param {number} bottom  the page's bottom edge
 * @param {{top:number,bottom:number}|null} cutLine the line box at `bottom`
 */
export function nextPageStart(top, bottom, cutLine) {
    const min = top + (bottom - top) / 4;
    if (isCut(bottom, cutLine) && cutLine.top > min) return cutLine.top;
    return bottom;
}

/** Line boxes are estimated (glyph box + line-height): a couple of px either way is "fits". */
export const CUT_TOLERANCE_PX = 3;
const isCut = (edge, line) => !!line && line.top < edge - CUT_TOLERANCE_PX && line.bottom > edge + CUT_TOLERANCE_PX;

/**
 * Where the page mask starts (a viewport y), or null for none: the top of
 * the line cut by the bottom edge, so a half line is never shown.
 */
export function maskStart(top, bottom, cutLine) {
    if (isCut(bottom, cutLine) && cutLine.top > top) return cutLine.top;
    return null;
}

/** The text line box at viewport point (x, y), or null (a gap between paragraphs, an image). */
function lineAt(x, y, root) {
    let node = null, offset = 0;
    if (document.caretPositionFromPoint) {
        const p = document.caretPositionFromPoint(x, y);
        if (p) { node = p.offsetNode; offset = p.offset; }
    } else if (document.caretRangeFromPoint) {
        const r = document.caretRangeFromPoint(x, y);
        if (r) { node = r.startContainer; offset = r.startOffset; }
    }
    if (!node || node.nodeType !== Node.TEXT_NODE || !root.contains(node)) return null;
    const range = document.createRange();
    const len = node.length;
    range.setStart(node, Math.max(0, Math.min(offset, len - 1)));
    range.setEnd(node, Math.min(len, Math.max(offset, 0) + 1));
    for (const rect of range.getClientRects()) {
        if (rect.height > 0 && rect.top <= y + 1 && rect.bottom >= y - 1) {
            // A whole line box: the rect of one character, widened vertically to
            // the line's line-height (getClientRects gives the glyph box).
            const lh = parseFloat(getComputedStyle(node.parentElement).lineHeight) || rect.height;
            const pad = Math.max(0, (lh - rect.height) / 2);
            return { top: rect.top - pad, bottom: rect.bottom + pad };
        }
    }
    return null;
}

export function initPages(ctx) {
    const { signal, root: readerEl } = ctx;
    const sectionsEl = ctx.sectionsEl;
    const chromeEl = document.getElementById('readerChrome');
    const toolbarEl = document.getElementById('readerToolbar');

    const mask = document.createElement('div');
    mask.className = 'reader-page-mask d-none';
    mask.setAttribute('aria-hidden', 'true');
    readerEl.appendChild(mask);

    const on = () => ctx.store.prefs.pageMode === 'pages';
    ctx.isPaged = on;

    // The page: under the progress rail (the bar overlays it when shown, like
    // Kindle's menu), above the playback bar when that is open.
    function pageBox() {
        const rail = Math.max(0, (chromeEl?.offsetHeight || 0) - (toolbarEl?.offsetHeight || 0));
        const top = (parseFloat(getComputedStyle(chromeEl).top) || 0) + rail + 4;
        const playbar = document.body.classList.contains('reader-playbar-open') ? (ctx.playbackBar?.offsetHeight || 0) : 0;
        const bottom = visibleBottom() - playbar - 8;
        return { top, bottom };
    }
    // The bottom of what is actually visible: on iPhone Safari the address bar
    // sits over the bottom of the window and window.innerHeight can include
    // the part behind it, so a page would end under the bar and its last lines
    // were skipped. visualViewport is what the reader really sees.
    function visibleBottom() {
        const vv = window.visualViewport;
        const vvBottom = vv ? vv.offsetTop + vv.height : window.innerHeight;
        return Math.min(window.innerHeight, vvBottom);
    }
    // Probe the text column, not the gutter: the middle of the sections box.
    function probeX() {
        const r = sectionsEl.getBoundingClientRect();
        return Math.round(r.left + r.width / 2);
    }
    function cutLineAt(y) {
        const x = probeX();
        // Try the middle, then a little either side (a short last line, a gap).
        return lineAt(x, y, sectionsEl) || lineAt(x - 80, y, sectionsEl) || lineAt(x + 80, y, sectionsEl);
    }

    function remask() {
        if (!on()) { mask.classList.add('d-none'); return; }
        const { top, bottom } = pageBox();
        const start = maskStart(top, bottom, cutLineAt(bottom));
        if (start === null) { mask.classList.add('d-none'); return; }
        mask.style.top = Math.round(start) + 'px';
        mask.classList.remove('d-none');
    }
    ctx.remaskPage = remask;

    // Back pages are remembered (exact scroll positions), so going back shows
    // exactly the page you saw; jumps from elsewhere (contents, a link) reset it.
    let history = [];
    let landedAt = null;
    const atEnd = () => window.scrollY + visibleBottom() >= document.documentElement.scrollHeight - 2;

    function land(y) {
        window.scrollTo({ top: Math.max(0, Math.round(y)), behavior: 'instant' });
        landedAt = window.scrollY;
        ctx.setChromeHidden?.(true);
        requestAnimationFrame(remask);
    }

    function nextPage() {
        if (landedAt !== window.scrollY) history = [];
        if (atEnd()) { ctx.goNext(); return; }
        const { top, bottom } = pageBox();
        const start = nextPageStart(top, bottom, cutLineAt(bottom));
        history.push(window.scrollY);
        land(window.scrollY + (start - top));
    }

    function prevPage() {
        if (landedAt !== window.scrollY) history = [];
        if (history.length) { land(history.pop()); return; }
        if (window.scrollY <= 1) { ctx.goPrev(); return; }
        const { top, bottom } = pageBox();
        // One page up, then line up so the top line is whole.
        let y = Math.max(0, window.scrollY - (bottom - top));
        window.scrollTo({ top: y, behavior: 'instant' });
        const line = cutLineAt(top);
        if (isCut(top, line)) y = Math.max(0, window.scrollY - (top - line.top));
        land(y);
    }
    ctx.nextPage = nextPage;
    ctx.prevPage = prevPage;

    // ---- Taps: left third back, right third forward, middle = the bar ----
    onTap(document, ({ x }) => {
        if (!on()) return;
        const zone = tapZone(x, window.innerWidth);
        if (zone === 'next') nextPage();
        else if (zone === 'prev') prevPage();
        else if (document.body.classList.contains('reader-focus')) document.body.classList.toggle('chrome-peek');
        else ctx.toggleChrome?.();
    }, { signal });

    // ---- Keys: Space / PageDown / ↓ forward, Shift+Space / PageUp / ↑ back ----
    // (← → go through navigation.js's shortcuts, which ask ctx.isPaged.)
    document.addEventListener('keydown', (e) => {
        if (!on() || e.defaultPrevented || e.altKey || e.metaKey || e.ctrlKey) return;
        if (e.target.closest?.('input, textarea, select, [contenteditable], #tocPanel, .reader-pop, .reader-playbar')) return;
        if (document.querySelector('.offcanvas.show, .modal.show')) return;
        let dir = null;
        if (e.key === ' ' || e.key === 'Spacebar') dir = e.shiftKey ? -1 : 1;
        else if (e.key === 'PageDown' || e.key === 'ArrowDown') dir = 1;
        else if (e.key === 'PageUp' || e.key === 'ArrowUp') dir = -1;
        if (!dir) return;
        e.preventDefault();
        if (dir > 0) nextPage(); else prevPage();
    }, { signal });

    // ---- Wheel / trackpad: one page per gesture ----
    let wheelLock = 0;
    window.addEventListener('wheel', (e) => {
        if (!on() || e.ctrlKey || Math.abs(e.deltaY) < Math.abs(e.deltaX)) return;
        if (e.target.closest?.('.offcanvas, .reader-pop, .reader-playbar, .modal')) return;
        e.preventDefault();
        const now = Date.now();
        if (now < wheelLock || Math.abs(e.deltaY) < 4) return;
        wheelLock = now + 450;
        if (e.deltaY > 0) nextPage(); else prevPage();
    }, { passive: false, signal });

    // Re-line-up after anything that moves text: a resize, a font change.
    window.addEventListener('resize', () => { history = []; requestAnimationFrame(remask); }, { passive: true, signal });
    // Safari's bar showing / hiding changes the visible area without a resize.
    window.visualViewport?.addEventListener('resize', () => requestAnimationFrame(remask), { passive: true, signal });
    // A jump from elsewhere (contents, "latest position") ends on a fresh page.
    window.addEventListener('scroll', () => { if (on() && landedAt !== null && window.scrollY !== landedAt) requestAnimationFrame(remask); }, { passive: true, signal });

    function apply() {
        document.body.classList.toggle('reader-paged', on());
        history = [];
        landedAt = window.scrollY;
        remask();
    }
    ctx.applyPageMode = apply;
    apply();

    return function cleanup() {
        document.body.classList.remove('reader-paged');
        mask.remove();
    };
}
