// ---- Dialog plumbing: focus trap + inert background ----
// Prefers the shell's window.Novarr.focusTrap/releaseFocusTrap (looked up at
// open time). app.js is a module loaded in <head>, so it has always run before
// this one; the Tab-cycling fallback only matters if app.js failed to load.
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function trapFocus(el) {
    const N = window.Novarr;
    if (typeof N?.focusTrap === 'function') {
        N.focusTrap(el);
        return () => { if (typeof N.releaseFocusTrap === 'function') N.releaseFocusTrap(el); };
    }
    const onKey = (e) => {
        if (e.key !== 'Tab') return;
        const items = [...el.querySelectorAll(FOCUSABLE)].filter(n => n.offsetParent !== null || n === document.activeElement);
        if (!items.length) { e.preventDefault(); el.focus(); return; }
        const first = items[0], last = items[items.length - 1];
        const at = document.activeElement;
        if (e.shiftKey && (at === first || at === el || !el.contains(at))) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && (at === last || !el.contains(at))) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', onKey, true);
    return () => document.removeEventListener('keydown', onKey, true);
}

// Everything a modal sheet must hide from AT / pointer / Tab while open.
// Regions that contain the dialog itself are skipped automatically.
const READER_REGIONS = ['#readerToolbar', '.reader-col', '#readerPlayback', '#highlightPop'];

export function createDialogs() {
    const holds = new Set();

    function holdDialog(el, trigger, regions = READER_REGIONS) {
        const inerted = regions.map(sel => document.querySelector(sel))
            .filter(n => n && !n.contains(el) && !n.inert);
        inerted.forEach(n => { n.inert = true; });
        const hold = { el, trigger, inerted, release: trapFocus(el) };
        holds.add(hold);
        return hold;
    }

    // Returns null so callers can write `x = releaseDialog(x)`.
    function releaseDialog(hold, restoreFocus = true) {
        if (!hold || !holds.has(hold)) return null;
        holds.delete(hold);
        hold.inerted.forEach(n => { n.inert = false; });
        hold.release();
        // Back to the control that opened it — unless the user has already
        // moved focus somewhere deliberate outside the dialog.
        const at = document.activeElement;
        if (restoreFocus && hold.trigger?.isConnected && (!at || at === document.body || hold.el.contains(at))) {
            hold.trigger.focus({ preventScroll: true });
        }
        return null;
    }

    return { holds, holdDialog, releaseDialog };
}

export const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
