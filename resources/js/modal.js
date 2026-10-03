/**
 * Shared modal plumbing: a focus trap and bottom sheets.
 *
 *   window.Novarr.focusTrap(el)        trap Tab inside `el`, make everything
 *                                      outside it `inert`, remember the opener
 *   window.Novarr.releaseFocusTrap()   undo the most recent trap (or pass the
 *                                      element), returning focus to the opener
 *   window.Novarr.openSheet(el, opts)  show an `.nv-sheet` (see
 *                                      _components.scss) as an aria-modal
 *                                      dialog; Esc, the backdrop and any
 *                                      [data-sheet-close] inside close it
 *   window.Novarr.closeSheet(el)
 *
 * Declarative: <button data-sheet-open="#moreSheet"> opens a sheet and keeps
 * its aria-expanded in step. Traps and sheets nest (a stack); Esc closes only
 * the top one. Everything is torn down before Turbo swaps the page.
 */

const FOCUSABLE = [
    'a[href]', 'area[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])', 'textarea:not([disabled])', 'iframe', 'summary',
    '[contenteditable="true"]', '[tabindex]:not([tabindex="-1"])',
].join(',');

const traps = [];   // { el, opener, inerted, onKey }
const sheets = [];  // { el, opener, onClose }

export function focusableIn(el) {
    return [...el.querySelectorAll(FOCUSABLE)].filter((n) =>
        n.getAttribute('tabindex') !== '-1' && !n.closest('[hidden], [inert]') && n.getClientRects().length > 0);
}

/**
 * Trap focus in `el`. Every branch of the document outside `el` gets the
 * `inert` attribute (so screen readers and pointer/keyboard can't reach the
 * page behind); Tab and Shift+Tab wrap inside.
 */
export function focusTrap(el, { initialFocus = null, opener: given = null } = {}) {
    el.setAttribute('data-nv-trap', 'open');
    if (!el) return null;
    const active = document.activeElement;
    const opener = given || (active instanceof HTMLElement && active !== document.body ? active : null);

    const inerted = [];
    for (let node = el; node && node.parentElement && node !== document.body; node = node.parentElement) {
        for (const sib of node.parentElement.children) {
            if (sib === node || sib.inert || ['SCRIPT', 'STYLE', 'TEMPLATE', 'LINK', 'META'].includes(sib.tagName)) continue;
            sib.inert = true;
            inerted.push(sib);
        }
    }

    const onKey = (e) => {
        if (e.key !== 'Tab' || e.defaultPrevented) return;
        const items = focusableIn(el);
        if (!items.length) { e.preventDefault(); return; }
        const first = items[0];
        const last = items[items.length - 1];
        if (e.shiftKey && (document.activeElement === first || !el.contains(document.activeElement))) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    };
    el.addEventListener('keydown', onKey);
    traps.push({ el, opener, inerted, onKey });

    const target = (typeof initialFocus === 'string' ? el.querySelector(initialFocus) : initialFocus)
        || el.querySelector('[autofocus]') || focusableIn(el)[0] || el;
    if (target === el && !el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
    return el;
}

/** Undo the trap for `el` (default: the most recent), restoring focus. */
export function releaseFocusTrap(el = null, { restoreFocus = true } = {}) {
    const i = el ? traps.findLastIndex((t) => t.el === el) : traps.length - 1;
    if (i < 0) return;
    const [trap] = traps.splice(i, 1);
    trap.el.removeAttribute('data-nv-trap');
    trap.el.removeEventListener('keydown', trap.onKey);
    trap.inerted.forEach((n) => { n.inert = false; });
    // A trap opened while another was active re-inerted nothing new for the
    // outer one, but un-inerting may have exposed nodes the outer trap still
    // needs hidden: re-assert the remaining traps.
    traps.forEach((t) => t.inerted.forEach((n) => { n.inert = true; }));
    if (restoreFocus && trap.opener?.isConnected) trap.opener.focus({ preventScroll: true });
}

/** Close every open sheet and release every trap (used before the palette opens). */
export function closeAll({ restoreFocus = false } = {}) {
    let guard = 20;
    while (sheets.length && guard-- > 0) closeSheet(null, { restoreFocus });
    while (traps.length && guard-- > 0) releaseFocusTrap(null, { restoreFocus });
}

function reducedMotion() {
    try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (_) { return true; }
}

/**
 * Show a sheet. opts: { opener, initialFocus, onClose }.
 * Returns the element (or null if it was already open).
 */
export function openSheet(el, { opener = null, initialFocus = null, onClose = null } = {}) {
    if (typeof el === 'string') el = document.querySelector(el);
    if (!el || sheets.some((s) => s.el === el)) return null;

    clearTimeout(el._nvHideTimer);
    el.hidden = false;
    el.setAttribute('role', el.getAttribute('role') || 'dialog');
    el.setAttribute('aria-modal', 'true');
    void el.offsetWidth; // reflow so the panel transition runs
    el.classList.add('is-open');
    document.body.classList.add('nv-modal-open');

    const btn = opener || (document.activeElement instanceof HTMLElement ? document.activeElement : null);
    btn?.setAttribute?.('aria-expanded', 'true');
    sheets.push({ el, opener: btn, onClose });
    focusTrap(el, { initialFocus, opener: btn });
    el.dispatchEvent(new CustomEvent('nv:sheet-open', { bubbles: true }));
    return el;
}

export function closeSheet(el = null, { restoreFocus = true } = {}) {
    if (typeof el === 'string') el = document.querySelector(el);
    const i = el ? sheets.findLastIndex((s) => s.el === el) : sheets.length - 1;
    if (i < 0) return;
    const [sheet] = sheets.splice(i, 1);

    sheet.el.classList.remove('is-open');
    const hide = () => { if (!sheet.el.classList.contains('is-open')) sheet.el.hidden = true; };
    if (reducedMotion() || !restoreFocus) hide();
    else sheet.el._nvHideTimer = setTimeout(hide, 170);

    sheet.opener?.setAttribute?.('aria-expanded', 'false');
    releaseFocusTrap(sheet.el, { restoreFocus });
    if (!sheets.length) document.body.classList.remove('nv-modal-open');
    sheet.el.dispatchEvent(new CustomEvent('nv:sheet-close', { bubbles: true }));
    sheet.onClose?.();
}

export function isSheetOpen(el) {
    return sheets.some((s) => s.el === el);
}

export function initSheets() {
    // Esc closes the top sheet — capture phase, so page-level Esc handlers
    // (the reader's popovers) don't also act on it.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !sheets.length) return;
        e.preventDefault();
        e.stopPropagation();
        closeSheet();
    }, true);

    document.addEventListener('click', (e) => {
        const openBtn = e.target.closest?.('[data-sheet-open]');
        if (openBtn) {
            e.preventDefault();
            const target = document.querySelector(openBtn.getAttribute('data-sheet-open'));
            if (target && isSheetOpen(target)) closeSheet(target);
            else openSheet(target, { opener: openBtn });
            return;
        }
        const closer = e.target.closest?.('[data-sheet-close]');
        if (closer) {
            const sheet = closer.closest('.nv-sheet');
            if (sheet && isSheetOpen(sheet)) {
                // Links inside a sheet navigate; close without stealing focus back.
                const isLink = closer.matches('a[href]');
                if (!isLink) e.preventDefault();
                closeSheet(sheet, { restoreFocus: !isLink });
            }
        }
    });

    // Never let a sheet, its inert siblings or the scroll lock leak into the
    // Turbo snapshot or the next page.
    const teardown = () => {
        while (sheets.length) closeSheet(null, { restoreFocus: false });
        while (traps.length) releaseFocusTrap(null, { restoreFocus: false });
        document.body.classList.remove('nv-modal-open');
    };
    document.addEventListener('turbo:before-cache', teardown);
    document.addEventListener('turbo:before-render', teardown);
}
