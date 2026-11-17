// Manga reader: the pure pieces (no DOM, no globals), unit-tested in
// tests/js/manga-reader.test.js. Pages are 1-based throughout; `count` is the
// chapter's number of pages.

export const MODES = ['rtl', 'ltr', 'vertical'];
export const FITS = ['width', 'height', 'original'];
export const MODE_LABELS = { rtl: 'Right to left', ltr: 'Left to right', vertical: 'Vertical' };
export const FIT_LABELS = { width: 'Fit width', height: 'Fit height', original: 'Original size' };

/** How many pages ahead / behind the paged view keeps decoded. */
export const PRELOAD_AHEAD = 3;
export const PRELOAD_BEHIND = 1;

const clampInt = (n, min, max) => Math.min(max, Math.max(min, Math.round(n)));

export function normaliseMode(mode) {
    return MODES.includes(mode) ? mode : 'ltr';
}

export function nextMode(mode) {
    return MODES[(MODES.indexOf(normaliseMode(mode)) + 1) % MODES.length];
}

export function nextFit(fit) {
    return FITS[(FITS.indexOf(fit) + 1) % FITS.length] || FITS[0];
}

/**
 * Page → the 0–100 progress the server stores. Page 1 of N is at least 1
 * (opened = started); only the last page is 100 (finished), so a page short
 * of the end never rounds up to "done" on a long chapter.
 */
export function pageToPercent(page, count) {
    if (!(count > 0)) return 0;
    const p = clampInt(page, 1, count);
    if (p >= count) return 100;
    return Math.min(99, Math.max(1, Math.round((p / count) * 100)));
}

/** Stored progress → the page it stands for (1..count). */
export function percentToPage(progress, count) {
    if (!(count > 0)) return 1;
    return Math.max(1, Math.min(count, Math.round((progress / 100) * count)));
}

/**
 * The page to open on.
 *  - `?page=N` / `?page=last` wins;
 *  - else an unfinished chapter (progress 1–99, not read) resumes: the exact
 *    page this device saved when it still matches the server's progress
 *    (another device may have moved on since), else the page the percent
 *    stands for;
 *  - a read chapter, one at 100, or one never opened starts at page 1.
 */
export function resumePage({ query = null, progress = null, read = false, count = 0, local = null } = {}) {
    if (!(count > 0)) return 1;
    if (query !== null && query !== undefined && query !== '') {
        if (String(query).toLowerCase() === 'last') return count;
        const n = parseInt(query, 10);
        if (!Number.isNaN(n)) return Math.max(1, Math.min(count, n));
    }
    const pct = progress === null || progress === undefined ? NaN : Number(progress);
    if (read || !(pct >= 1 && pct <= 99)) return 1;
    if (local && Number(local.pct) === pct) {
        const p = parseInt(local.page, 10);
        if (p >= 1 && p <= count) return p;
    }
    return percentToPage(pct, count);
}

/**
 * Should this percent be sent? Never lower than what the server already has
 * (opening a chapter mid-way, rounding, re-reading a finished one), unless
 * the reader has moved backwards in this visit. Never the same value twice.
 */
export function shouldSaveProgress(pct, stored, movedBack = false) {
    if (stored === null || stored === undefined) return true;
    if (pct === stored) return false;
    return pct > stored || !!movedBack;
}

/**
 * A tap zone → an action. Paged modes: in rtl the LEFT third turns to the
 * next page, in ltr the right third does; the centre toggles the chrome.
 * Vertical (scrolled) mode: every tap toggles the chrome.
 */
export function zoneAction(zone, mode) {
    if (zone === 'center' || mode === 'vertical') return 'chrome';
    if (zone === 'left') return mode === 'rtl' ? 'next' : 'prev';
    if (zone === 'right') return mode === 'rtl' ? 'prev' : 'next';
    return null;
}

/** Which third of the width an x coordinate falls in. */
export function zoneAt(x, width) {
    if (!(width > 0)) return 'center';
    if (x < width / 3) return 'left';
    if (x > (width * 2) / 3) return 'right';
    return 'center';
}

/**
 * A keydown → an action name (or null). `e` needs key and shiftKey only.
 * Arrow left/right follow the reading direction (rtl: ← is next).
 */
export function keyAction(e, mode) {
    const k = e.key;
    const paged = mode !== 'vertical';
    switch (k) {
        case 'ArrowLeft': return mode === 'rtl' ? 'next' : 'prev';
        case 'ArrowRight': return mode === 'rtl' ? 'prev' : 'next';
        case 'ArrowDown': return paged ? 'next' : null;   // vertical: native scroll
        case 'ArrowUp': return paged ? 'prev' : null;
        case ' ': case 'Spacebar': return e.shiftKey ? (paged ? 'prev' : 'screenUp') : (paged ? 'next' : 'screenDown');
        case 'PageDown': return paged ? 'next' : 'screenDown';
        case 'PageUp': return paged ? 'prev' : 'screenUp';
        case 'Home': return 'first';
        case 'End': return 'last';
        case '[': return 'prevChapter';
        case ']': return 'nextChapter';
        case 'f': case 'F': return e.shiftKey ? null : 'fullscreen';
        case 'm': case 'M': return e.shiftKey ? null : 'mode';
        case 'Escape': return 'showChrome';
        default: return null;
    }
}

/**
 * A horizontal swipe → next / prev. A finger moving left (dx < 0) turns
 * forward in ltr and back in rtl. Too short, too slow or mostly vertical
 * swipes are ignored (null). Vertical mode never pages by swipe.
 */
export function swipeAction(dx, dy, dt, mode) {
    if (mode === 'vertical') return null;
    if (dt > 700 || Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy) * 1.5) return null;
    const forward = mode === 'rtl' ? dx > 0 : dx < 0;
    return forward ? 'next' : 'prev';
}

/**
 * Page ↔ slider value. In rtl the slider runs right-to-left: its LEFT end
 * (min) is the last page, so dragging left reads forward.
 */
export function pageToSlider(page, count, mode) {
    const p = clampInt(page, 1, Math.max(1, count));
    return mode === 'rtl' ? Math.max(1, count) + 1 - p : p;
}

export function sliderToPage(value, count, mode) {
    const v = clampInt(Number(value), 1, Math.max(1, count));
    return mode === 'rtl' ? Math.max(1, count) + 1 - v : v;
}

/** Pages to keep decoded around `page`: the next three, then the previous one. */
export function preloadWindow(page, count, ahead = PRELOAD_AHEAD, behind = PRELOAD_BEHIND) {
    const out = [];
    for (let i = 1; i <= ahead; i++) if (page + i <= count) out.push(page + i);
    for (let i = 1; i <= behind; i++) if (page - i >= 1) out.push(page - i);
    return out;
}

/** Default fit for a viewport: fit-width on a portrait phone, fit-height otherwise. */
export function defaultFit(width, height) {
    return width < height && width < 768 ? 'width' : 'height';
}

export function pageLabel(page, count) {
    return `Page ${page} of ${count}`;
}

/** Wider than tall: a double-page spread. */
export function isSpread(w, h) {
    return w > 0 && h > 0 && w > h * 1.15;
}

/**
 * Keep a 2x-zoom pan offset inside the page: with transform-origin 0 0 and
 * scale `s`, a box of width w shows its right edge at tx = -(s-1)·w.
 */
export function clampPan(tx, ty, w, h, scale = 2) {
    const minX = -(scale - 1) * w;
    const minY = -(scale - 1) * h;
    // `|| 0`: no negative zero in a transform.
    return {
        x: Math.min(0, Math.max(minX, tx)) || 0,
        y: Math.min(0, Math.max(minY, ty)) || 0,
    };
}

/** The pan that keeps the point (x, y) of the box under the finger at `scale`. */
export function zoomAt(x, y, w, h, scale = 2) {
    return clampPan(-(scale - 1) * x, -(scale - 1) * y, w, h, scale);
}
