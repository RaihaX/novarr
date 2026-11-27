// A reliable "tap on the page" (anywhere on screen, margins included) for the reader (the bar toggle, pages-mode
// zones, focus-mode peek).
//
// Waiting for `click` was hit and miss on iPhone Safari: it sends no click
// for a tap that stops momentum scrolling, and is picky about clicks on plain
// text. So a tap is read from the pointer itself: down and up close together
// in place and time, with the page not scrolled in between. Nothing on the page
// toggles on `click` any more, so a tap never acts twice; links and controls
// are left alone entirely.

export const TAP_MAX_MOVE_PX = 10;
export const TAP_MAX_MS = 450;
export const TAP_MAX_SCROLL_PX = 4;

// Links and controls, and everything that is not the page: the reader bar,
// sheets, the playback bar, drawers, dialogs, the chapter footer's buttons.
const IGNORE = 'a, button, input, select, textarea, label, summary, [role="button"], mark.reader-highlight, '
    + '.reader-chrome, .reader-pop, .reader-playbar, .reader-backdrop, .reader-hlpop, .offcanvas, .offcanvas-backdrop, .modal, .toast, .navbar';

/** Is a pointer down → up a tap? `down`/`up` are {x, y, t, scrollY}. */
export function isTap(down, up) {
    if (!down || !up) return false;
    return Math.hypot(up.x - down.x, up.y - down.y) <= TAP_MAX_MOVE_PX
        && up.t - down.t <= TAP_MAX_MS
        && Math.abs(up.scrollY - down.scrollY) <= TAP_MAX_SCROLL_PX;
}

/**
 * Call `handler({x, y, target})` on each tap inside `el` (not on a link or
 * control, not ending a text selection). Returns nothing; listeners use `signal`.
 */
export function onTap(el, handler, { signal } = {}) {
    let down = null;
    const now = () => Date.now();

    el.addEventListener('pointerdown', (e) => {
        if (!e.isPrimary || (e.pointerType === 'mouse' && e.button !== 0) || e.target.closest?.(IGNORE)) { down = null; return; }
        down = { x: e.clientX, y: e.clientY, t: now(), scrollY: window.scrollY };
    }, { passive: true, signal });
    el.addEventListener('pointercancel', () => { down = null; }, { passive: true, signal });
    el.addEventListener('pointerup', (e) => {
        const start = down;
        down = null;
        if (!e.isPrimary || !isTap(start, { x: e.clientX, y: e.clientY, t: now(), scrollY: window.scrollY })) return;
        const selection = window.getSelection?.();
        if (selection && !selection.isCollapsed) return;
        handler({ x: e.clientX, y: e.clientY, target: e.target });
    }, { passive: true, signal });
}

/** A phone or tablet (touch is the main pointer): scrolling never brings the bar back there. */
export const isTouchFirst = () => typeof matchMedia === 'function' && matchMedia('(pointer: coarse)').matches;
