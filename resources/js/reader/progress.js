// Reading position (local restore + cross-device sync), minutes left, the
// progress rail and the reader bar's auto-hide.
import { sectionTop, sectionHeight } from './navigation.js';

const posKey = id => 'reader_pos_' + id;
const WPM = 230;   // honest-ish adult reading pace for web fiction

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
        if (lastSynced.id === id && Math.abs(lastSynced.pct - pct) < 5 && pct < 98) return;
        lastSynced = { id, pct };
        // A beacon survives the page going away, but offline it is simply
        // dropped: park the write in the offline queue instead.
        if (onPageHide && navigator.sendBeacon && navigator.onLine !== false) {
            const form = new FormData();
            form.append('progress', String(pct));
            navigator.sendBeacon(`/chapters/${id}/progress`, form);
            return;
        }
        window.Novarr?.queuedFetch?.(`/chapters/${id}/progress`, { method: 'POST', body: { progress: pct } }).catch(() => {});
    }
    ctx.syncProgress = syncProgress;

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

    // ---- Reader bar auto-hide: slides away on scroll-down, returns on
    // scroll-up, near the top, or a tap in the middle of the page. The
    // progress rail (inside the chrome, under the bar) stays visible.
    // Never hides while the Aa popover or the contents drawer is open.
    const chromeEl = document.getElementById('readerChrome');
    const tocPanelEl = document.getElementById('tocPanel');
    let chromeLastY = window.scrollY;
    function setChromeHidden(hidden) {
        if (hidden && (!ctx.settingsPop.classList.contains('d-none') || tocPanelEl?.classList.contains('show'))) hidden = false;
        chromeEl.classList.toggle('is-autohidden', hidden);
    }
    ctx.setChromeHidden = setChromeHidden;
    function updateChromeAutoHide() {
        const y = window.scrollY;
        const dy = y - chromeLastY;
        if (y < 80) setChromeHidden(false);
        else if (dy > 6) setChromeHidden(true);
        else if (dy < -6) setChromeHidden(false);
        if (Math.abs(dy) > 6 || y < 80) chromeLastY = y;
    }
    // Centre tap toggles the bar (outside focus mode — focus mode has its own
    // tap-to-peek in navigation.js). Taps on links/controls/selections are ignored.
    ctx.sectionsEl.addEventListener('click', (e) => {
        if (document.body.classList.contains('reader-focus')) return;
        if (e.target.closest('a, button, input, select, textarea')) return;
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;
        const band = window.innerHeight;
        if (e.clientY < band * 0.25 || e.clientY > band * 0.75) return;
        setChromeHidden(!chromeEl.classList.contains('is-autohidden'));
    }, { signal });
    window.addEventListener('scroll', onScroll, { passive: true, signal });
    window.addEventListener('resize', onScroll, { passive: true, signal });
    window.addEventListener('pagehide', () => syncProgress(sections[ctx.currentIdx].id, currentProgressPct(), true), { signal });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') syncProgress(sections[ctx.currentIdx].id, currentProgressPct(), true);
    }, { signal });

    // Restore: local position wins (freshest on this device), else the synced
    // server position from another device. Legacy pixel keys still honoured.
    (function restorePosition() {
        let pct = parseInt(localStorage.getItem(posKey(state.id)) || '', 10);
        if (isNaN(pct)) pct = state.progress;
        const legacyPx = parseInt(localStorage.getItem('reader_scroll_' + state.id) || '0', 10);
        localStorage.removeItem('reader_scroll_' + state.id);

        requestAnimationFrame(() => {
            if (pct !== null && !isNaN(pct) && pct > 2 && pct < 98) {
                const s = sections[0];
                window.scrollTo(0, Math.max(0, sectionTop(s) + (pct / 100) * sectionHeight(s) - window.innerHeight));
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
