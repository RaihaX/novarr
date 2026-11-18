// Sections (the rendered chapter + any continuously-loaded ones), chapter
// navigation, keyboard shortcuts, swipe and focus mode.
import { chLabel } from './format.js';
import { applyFooterState } from './footer.js';
import { SHORTCUTS, shortcutBlocked, isTypingTarget } from './shortcuts.js';
import { markInReaderNav } from './latest-position.js';

export const sectionTop = (s, scrollY = window.scrollY) => s.el.getBoundingClientRect().top + scrollY;
export const sectionHeight = s => s.el.offsetHeight || 1;

/** The section whose top has passed 40% of the viewport (the last such one). */
export function findCurrentIdx(sections, scrollY = window.scrollY, viewportHeight = window.innerHeight) {
    const ref = scrollY + viewportHeight * 0.4;
    let idx = 0;
    for (let i = 0; i < sections.length; i++) {
        if (sectionTop(sections[i], scrollY) <= ref) idx = i;
    }
    return idx;
}

export function initNavigation(ctx) {
    const { state, signal, dialogs: { holds } } = ctx;

    // ---- Sections: the initial chapter + any continuously-loaded ones ----
    // Each entry mirrors the per-page readerState so navigation and progress
    // always refer to the chapter actually under the viewport.
    const sectionsEl = document.getElementById('readerSections');
    const firstSectionEl = sectionsEl.querySelector('.reader-section');
    const sections = [{
        ...state,
        el: firstSectionEl,
        words: parseInt(firstSectionEl.dataset.words || '0', 10) || 0,
    }];
    ctx.sectionsEl = sectionsEl;
    ctx.sections = sections;
    ctx.currentIdx = 0;
    ctx.findCurrentIdx = () => findCurrentIdx(sections);

    const footer = {
        readThrough: document.getElementById('readThrough'),
        readToggle: document.getElementById('readToggle'),
    };
    const announcer = document.getElementById('readerAnnounce');

    function onSectionChange(idx) {
        ctx.currentIdx = idx;
        const cur = sections[idx];
        // Keep the address bar, the footer nav and the footer read controls
        // in sync with the chapter being read, so reloads/bookmarks land on
        // the right one and "Mark read" marks what you're looking at.
        history.replaceState(history.state, '', cur.url);
        setNavBlock(document.getElementById('navPrev'), cur.prev, '← Previous');
        setNavBlock(document.getElementById('navNext'), cur.next, 'Next →');
        applyFooterState(footer, cur);
        if (cur.title) document.title = cur.title;
        if (announcer) announcer.textContent = 'Now reading ' + chLabel(cur, 200);
    }
    ctx.onSectionChange = onSectionChange;

    function setNavBlock(el, target, dir) {
        if (!el) return;
        if (!target) { el.classList.add('d-none'); return; }
        el.classList.remove('d-none');
        el.href = target.url;
        el.querySelector('.reader-navblock-dir').textContent = dir;
        el.querySelector('.reader-navblock-label').textContent = chLabel(target);
    }

    // ---- Keyboard + swipe navigation, relative to the current section ----
    function visit(url) {
        if (!url) return;
        if (window.Turbo) window.Turbo.visit(url);
        else window.location.href = url;
    }
    ctx.visit = visit;
    // Next / previous chapter from inside the reader: not a fresh open, so
    // the destination does not offer the "latest position" prompt.
    function visitAdjacent(url) {
        if (!url) return;
        try { markInReaderNav(sessionStorage, url); } catch (e) { /* storage blocked */ }
        visit(url);
    }
    ctx.visitAdjacent = visitAdjacent;
    function goPrev() {
        const cur = sections[ctx.currentIdx];
        if (ctx.currentIdx > 0) {
            window.scrollTo({ top: sectionTop(sections[ctx.currentIdx - 1]), behavior: ctx.reducedMotion() ? 'auto' : 'smooth' });
        } else {
            visitAdjacent(cur.prev?.url);
        }
    }
    function goNext() {
        const cur = sections[ctx.currentIdx];
        if (ctx.currentIdx < sections.length - 1) {
            window.scrollTo({ top: sectionTop(sections[ctx.currentIdx + 1]), behavior: ctx.reducedMotion() ? 'auto' : 'smooth' });
        } else {
            visitAdjacent(cur.next?.url);
        }
    }
    ctx.goPrev = goPrev;
    ctx.goNext = goNext;

    // ---- Focus mode: hide chrome; tap the page to peek at it ----
    const focusBtn = document.getElementById('focusBtn');
    function applyFocus() {
        const on = localStorage.getItem('reader_focus') === '1';
        document.body.classList.toggle('reader-focus', on);
        document.body.classList.remove('chrome-peek');
        focusBtn.classList.toggle('is-active', on);
        focusBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    focusBtn.addEventListener('click', () => {
        const turningOn = localStorage.getItem('reader_focus') !== '1';
        localStorage.setItem('reader_focus', turningOn ? '1' : '0');
        applyFocus();
        if (turningOn && !localStorage.getItem('reader_focus_hint')) {
            localStorage.setItem('reader_focus_hint', '1');
            window.Novarr?.showToast('Focus mode on — tap the page to show the controls.', 'info');
        }
    });
    applyFocus();

    // Bar controls are hidden in focus mode; a shortcut that opens one of
    // them peeks the chrome first so focus can return to its button.
    const peek = () => {
        if (document.body.classList.contains('reader-focus')) document.body.classList.add('chrome-peek');
    };

    const dialogOpen = () => holds.size > 0 || !!document.querySelector('.offcanvas.show, .modal.show');
    const actions = {
        prev: goPrev,
        next: goNext,
        toc: () => { peek(); document.getElementById('tocBtn')?.click(); },
        settings: () => { peek(); document.getElementById('readerSettingsBtn')?.click(); },
        playback: () => { peek(); document.getElementById('playbackBtn')?.click(); },
        focus: () => focusBtn.click(),
    };

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            // Leave focus mode — the toolbar (and its Focus button) is hidden
            // there, so this is the keyboard way out. Earlier Esc handlers
            // (settings, playback, "?") preventDefault when they used it.
            if (e.defaultPrevented || !document.body.classList.contains('reader-focus')) return;
            if (isTypingTarget(e.target) || dialogOpen()) return;
            e.preventDefault();
            localStorage.setItem('reader_focus', '0');
            applyFocus();
            focusBtn.focus({ preventScroll: true });
            return;
        }
        const key = e.key?.length === 1 ? e.key.toLowerCase() : e.key;
        const action = SHORTCUTS[key];
        if (!action || shortcutBlocked(e, { dialogOpen: dialogOpen() })) return;
        if (key.length === 1) e.preventDefault();
        actions[action]();
    }, { signal });

    // Horizontal swipe on touch devices: left = next, right = previous.
    // Ignored when it starts on a control, while text is selected, or when
    // it's mostly vertical (normal scrolling).
    let touchStart = null;
    document.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 1 || e.target.closest('a, button, input, select, textarea, .offcanvas, .reader-pop, .reader-playbar, .reader-backdrop')) {
            touchStart = null;
            return;
        }
        touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY, t: Date.now() };
    }, { passive: true, signal });
    document.addEventListener('touchend', (e) => {
        if (!touchStart) return;
        const dx = e.changedTouches[0].clientX - touchStart.x;
        const dy = e.changedTouches[0].clientY - touchStart.y;
        const dt = Date.now() - touchStart.t;
        touchStart = null;
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;
        if (dt > 600 || Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 2) return;
        if (dx < 0) goNext(); else goPrev();
    }, { passive: true, signal });

    sectionsEl.addEventListener('click', (e) => {
        if (!document.body.classList.contains('reader-focus')) return;
        if (e.target.closest('a, button, input, select, textarea')) return;
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;
        document.body.classList.toggle('chrome-peek');
    });
}
