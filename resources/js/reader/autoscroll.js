// ---- Auto-scroll ----
import { setPlayBtn } from './chrome.js';

export function initAutoScroll(ctx) {
    const { signal, sections } = ctx;
    let scrollSpeed = Math.min(150, Math.max(10, parseInt(localStorage.getItem('reader_scrollspeed') || '40', 10)));
    const autoScrollToggle = document.getElementById('autoScrollToggle');
    const speedLabel = document.getElementById('autoScrollSpeedLabel');
    let autoScrollOn = false, autoScrollRaf = null, autoScrollLastTs = null, autoScrollAcc = 0;
    ctx.isAutoScrolling = () => autoScrollOn;

    function reflectAutoScroll() {
        setPlayBtn(autoScrollToggle, autoScrollOn ? 'Stop' : 'Start', autoScrollOn);
        autoScrollToggle.setAttribute('aria-pressed', autoScrollOn ? 'true' : 'false');
        speedLabel.textContent = scrollSpeed + ' px/s';
    }

    function autoScrollStep(ts) {
        if (!autoScrollOn) return;
        if (autoScrollLastTs !== null) {
            autoScrollAcc += ((ts - autoScrollLastTs) / 1000) * scrollSpeed;
            if (autoScrollAcc >= 1) {
                const px = Math.floor(autoScrollAcc);
                autoScrollAcc -= px;
                window.scrollBy(0, px);
            }
            // End of everything loaded and nothing more coming → stop.
            if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 2) {
                const last = sections[sections.length - 1];
                if (!last.next || ctx.store.prefs.autoNext !== '1' || ctx.autoLoadStopped) { stopAutoScroll(); return; }
            }
        }
        autoScrollLastTs = ts;
        autoScrollRaf = requestAnimationFrame(autoScrollStep);
    }

    function startAutoScroll() {
        autoScrollOn = true;
        autoScrollLastTs = null;
        autoScrollAcc = 0;
        reflectAutoScroll();
        autoScrollRaf = requestAnimationFrame(autoScrollStep);
        ctx.updateWakeLock();
    }
    function stopAutoScroll() {
        if (!autoScrollOn) return;
        autoScrollOn = false;
        cancelAnimationFrame(autoScrollRaf);
        reflectAutoScroll();
        ctx.updateWakeLock();
    }
    ctx.stopAutoScroll = stopAutoScroll;

    autoScrollToggle.addEventListener('click', () => autoScrollOn ? stopAutoScroll() : startAutoScroll(), { signal });
    ctx.playbackBar.querySelectorAll('[data-scrollspeed]').forEach(btn => btn.addEventListener('click', () => {
        scrollSpeed = Math.min(150, Math.max(10, scrollSpeed + (btn.dataset.scrollspeed === '+' ? 10 : -10)));
        localStorage.setItem('reader_scrollspeed', scrollSpeed);
        reflectAutoScroll();
    }, { signal }));
    // Any manual scroll intent pauses auto-scroll.
    ['wheel', 'touchmove'].forEach(ev => document.addEventListener(ev, stopAutoScroll, { passive: true, signal }));
    reflectAutoScroll();
}
