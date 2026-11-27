// Reading position (local restore + cross-device sync), minutes left, the
// progress rail and the reader bar's auto-hide.
import { sectionTop, sectionHeight } from './navigation.js';
import { appendDeviceFields } from '../device.js';
import { markLocalWrite, isLookupChapter } from './latest-position.js';
import { onTap, isTouchFirst } from './tap.js';

const posKey = id => 'reader_pos_' + id;
const WPM = 230;   // honest-ish adult reading pace for web fiction

/** Scroll needed (px, from where the direction last changed) to hide / show the bar. */
export const HIDE_AFTER_PX = 48, SHOW_AFTER_PX = 24, TOP_ZONE_PX = 80;
/** After a tap shows the bar, scrolling can't hide it for this long. */
export const HOLD_AFTER_TAP_MS = 900;

/**
 * The bar's auto-hide decision for scroll position `y`: true = hide, false =
 * show, null = leave it. `s` ({anchorY, lastY, holdUntil}) is updated in
 * place: anchorY is where the scroll direction last changed.
 */
export function chromeDecision(s, y, { fromResize = false, now = 0, touch = false } = {}) {
    const dy = y - s.lastY;
    if ((dy > 0 && s.lastY < s.anchorY) || (dy < 0 && s.lastY > s.anchorY)) s.anchorY = s.lastY;   // direction changed
    s.lastY = y;
    if (y < TOP_ZONE_PX) { s.anchorY = y; return false; }
    if (fromResize) { s.anchorY = y; return null; }   // the browser's bar moving, not the reader
    if (y - s.anchorY > HIDE_AFTER_PX) return now < s.holdUntil ? null : true;
    // On a phone, scrolling up never brings it back (iPhone's bounce and
    // momentum reverse direction for a moment and made it flicker): a tap
    // does, or reaching the top.
    if (!touch && s.anchorY - y > SHOW_AFTER_PX) return false;
    return null;
}

export function initProgress(ctx) {
    const { state, signal, sections, root: readerEl } = ctx;

    // Position is a percentage through the current chapter (device-independent),
    // stored locally for instant restore and synced to the server so another
    // device can resume in the same place.
    function currentProgressPct() {
        const cur = sections[ctx.currentIdx];
        const pct = ((window.scrollY + window.innerHeight - sectionTop(cur)) / sectionHeight(cur)) * 100;
        return Math.max(0, Math.min(100, Math.round(pct)));
    }
    ctx.currentProgressPct = currentProgressPct;

    // Minutes left = words still below the fold at ~230 wpm. Words come from a
    // server-side count of the chapter body (data-words on the section).
    function updateMinutesLeft(pct) {
        const cur = sections[ctx.currentIdx];
        const el = cur.el.querySelector('[data-mins]');
        if (!el) return;
        const words = cur.words || 0;
        if (!words) { el.textContent = '—'; return; }
        const remaining = Math.max(0, words * (1 - pct / 100));
        el.textContent = remaining < WPM / 2 ? 'Finished' : Math.max(1, Math.round(remaining / WPM)) + ' min left';
    }

    let lastSynced = { id: null, pct: -1 };
    function syncProgress(id, pct, onPageHide = false) {
        // Only ever report for this visit's own chapters, and only while this
        // page is the one on screen — a stale handler or timer from a page
        // we've left must not send progress for the wrong chapter.
        if (signal.aborted || !readerEl.isConnected || !sections.some(s => s.id === id)) return;
        // A chapter opened as a look-up is not "reading it here".
        if (isLookupChapter(state, id)) return;
        if (lastSynced.id === id && Math.abs(lastSynced.pct - pct) < 5 && pct < 98) return;
        lastSynced = { id, pct };
        noteWrite();
        // A beacon survives the page going away, but offline it is simply
        // dropped: park the write in the offline queue instead.
        if (onPageHide && navigator.sendBeacon && navigator.onLine !== false) {
            const form = new FormData();
            form.append('progress', String(pct));
            appendDeviceFields(form);
            navigator.sendBeacon(`/chapters/${id}/progress`, form);
            return;
        }
        window.Novarr?.queuedFetch?.(`/chapters/${id}/progress`, { method: 'POST', body: { progress: pct } }).catch(() => {});
    }
    ctx.syncProgress = syncProgress;

    // This browser's last progress write for the series, for the "latest
    // position" prompt's "local progress is newer" rule (latest-position.js).
    function noteWrite() {
        try { markLocalWrite(localStorage, state.novelId); } catch (e) { /* storage blocked */ }
    }

    const progressFill = document.getElementById('readProgressFill');
    let scrollSaveTimer = null;
    let syncTimer = null;
    function updateProgress() {
        const idx = ctx.findCurrentIdx();
        if (idx !== ctx.currentIdx) ctx.onSectionChange(idx);
        const cur = sections[ctx.currentIdx];
        const pct = currentProgressPct();

        progressFill.style.transform = `scaleX(${pct / 100})`;
        updateMinutesLeft(pct);

        clearTimeout(scrollSaveTimer);
        scrollSaveTimer = setTimeout(() => {
            if (pct >= 98) localStorage.removeItem(posKey(cur.id));
            else localStorage.setItem(posKey(cur.id), String(pct));
            if (!isLookupChapter(state, cur.id)) noteWrite();
        }, 250);

        if (!syncTimer) {
            syncTimer = setTimeout(() => {
                syncTimer = null;
                syncProgress(sections[ctx.currentIdx].id, currentProgressPct());
            }, 10000);
        }
    }

    // rAF-throttled: at most one progress update per frame (handoff §Interactions).
    let rafPending = false;
    function onScroll() {
        if (rafPending) return;
        rafPending = true;
        requestAnimationFrame(() => { rafPending = false; updateProgress(); updateChromeAutoHide(); });
    }

    // ---- Reader bar auto-hide: slides away after a deliberate scroll down,
    // returns on a deliberate scroll up, near the top, or a tap on the page.
    // The progress rail (inside the chrome, under the bar) stays visible.
    // Never hides while the Aa popover or the contents drawer is open.
    //
    // Phones made this flaky: the browser's own address bar sliding in/out
    // resizes the viewport and nudges scrollY by a few pixels, which read as
    // "scrolled down" and hid a bar the reader had just tapped up. So: the
    // distance is measured from where the direction last changed (not frame
    // to frame), a resize never hides it, and a tap holds it up briefly.
    const chromeEl = document.getElementById('readerChrome');
    const tocPanelEl = document.getElementById('tocPanel');
    const auto = { anchorY: window.scrollY, lastY: window.scrollY, holdUntil: 0 };
    function setChromeHidden(hidden) {
        if (hidden && (!ctx.settingsPop.classList.contains('d-none') || tocPanelEl?.classList.contains('show'))) hidden = false;
        chromeEl.classList.toggle('is-autohidden', hidden);
        auto.anchorY = auto.lastY = window.scrollY;
    }
    ctx.setChromeHidden = setChromeHidden;
    function toggleChrome() {
        const show = chromeEl.classList.contains('is-autohidden');
        setChromeHidden(!show);
        if (show) auto.holdUntil = Date.now() + HOLD_AFTER_TAP_MS;
    }
    ctx.toggleChrome = toggleChrome;
    function updateChromeAutoHide(fromResize = false) {
        if (ctx.isPaged?.()) return;   // pages mode: page turns and taps decide
        const y = window.scrollY;
        const decision = chromeDecision(auto, y, { fromResize, now: Date.now(), touch: isTouchFirst() });
        if (decision !== null) setChromeHidden(decision);
    }
    // Tap on the page toggles the bar (outside focus mode — focus mode has its
    // own tap-to-peek in navigation.js; pages mode has its own zones in
    // pages.js). Taps on links/controls/selections are ignored.
    onTap(document, () => {
        if (document.body.classList.contains('reader-focus') || ctx.isPaged?.()) return;
        toggleChrome();
    }, { signal });
    window.addEventListener('resize', () => requestAnimationFrame(() => { updateProgress(); updateChromeAutoHide(true); }), { passive: true, signal });
    window.addEventListener('scroll', onScroll, { passive: true, signal });
    window.addEventListener('pagehide', () => syncProgress(sections[ctx.currentIdx].id, currentProgressPct(), true), { signal });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') syncProgress(sections[ctx.currentIdx].id, currentProgressPct(), true);
    }, { signal });

    // Scroll the opened chapter (sections[0]) to a percentage: the bottom of
    // the viewport at pct% of the chapter, as currentProgressPct() measures.
    function scrollToProgress(pct) {
        const s = sections[0];
        window.scrollTo(0, Math.max(0, sectionTop(s) + (pct / 100) * sectionHeight(s) - window.innerHeight));
    }
    ctx.scrollToProgress = scrollToProgress;

    // Restore: local position wins (freshest on this device), else the synced
    // server position from another device. Legacy pixel keys still honoured.
    // The "latest position" plan (reader/latest.js) adjusts this:
    //  - "Go to latest" brought us here: open exactly at that position;
    //  - the prompt is offering another device's place in THIS chapter: do
    //    not also jump there unasked — only this device's own position.
    (function restorePosition() {
        const plan = ctx.latestPlan || {};
        const accepted = plan.accepted ?? null;
        let pct = parseInt(localStorage.getItem(posKey(state.id)) || '', 10);
        if (isNaN(pct) && !(plan.offer && plan.sameChapter)) pct = state.progress;
        const legacyPx = parseInt(localStorage.getItem('reader_scroll_' + state.id) || '0', 10);
        localStorage.removeItem('reader_scroll_' + state.id);

        requestAnimationFrame(() => {
            // Opened from the Highlights page: that passage, not the saved
            // place (reader/highlights.js; false when it isn't drawn here).
            if (ctx.revealFocusHighlight?.()) {
                updateProgress();
                return;
            }
            if (accepted !== null) {
                if (accepted > 2) scrollToProgress(accepted);
            } else if (pct !== null && !isNaN(pct) && pct > 2 && pct < 98) {
                scrollToProgress(pct);
            } else if (legacyPx > 200) {
                window.scrollTo(0, legacyPx);
            }
            updateProgress();
        });
    })();

    return function cleanup() {
        clearTimeout(scrollSaveTimer);
        clearTimeout(syncTimer);
        syncTimer = null;
    };
}
