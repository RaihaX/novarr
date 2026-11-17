// Vertical (webtoon) view: every page in one column, no gaps, lazy images in
// boxes sized from the JSON so nothing jumps; the current page is tracked by
// an IntersectionObserver; the end card follows the last image (a button,
// never an automatic jump to the next chapter).
import { buildEndCard, buildPageError } from './endcard.js';

export function createVerticalView(o) {
    const { stage, pages, chapter, next, novelUrl, novelName, retryIcon, reducedMotion } = o;
    const count = pages.length;

    const abort = new AbortController();
    const signal = abort.signal;

    stage.replaceChildren();
    stage.className = 'mr-stage mr-vertical';
    stage.tabIndex = -1;   // focusable, so the arrow keys scroll it natively

    const strip = document.createElement('div');
    strip.className = 'mr-strip';
    const boxes = pages.map((meta, i) => {
        const p = i + 1;
        const box = document.createElement('div');
        box.className = 'mr-vpage';
        box.dataset.page = String(p);
        const im = document.createElement('img');
        im.className = 'mr-img';
        im.alt = `Page ${p}`;
        im.loading = 'lazy';
        im.decoding = 'async';
        im.draggable = false;
        if (meta.width && meta.height) {
            im.width = meta.width;
            im.height = meta.height;
            box.style.aspectRatio = `${meta.width} / ${meta.height}`;
        } else {
            box.classList.add('no-size');
        }
        const err = buildPageError(p, () => {
            err.hidden = true;
            im.removeAttribute('src');
            im.src = meta.url;
        }, retryIcon);
        im.addEventListener('error', () => { err.hidden = false; }, { signal });
        im.addEventListener('load', () => { err.hidden = true; box.classList.remove('no-size'); }, { signal });
        im.src = meta.url;
        box.append(im, err);
        return box;
    });
    const endEl = buildEndCard({ chapter, next, novelUrl, novelName });
    strip.append(...boxes, endEl);
    stage.append(strip);

    let page = 1;
    let reported = null;

    // Current page: the one crossing a thin band 35% down the viewport.
    const bandIo = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            const p = parseInt(entry.target.dataset.page, 10);
            if (p && p !== page) {
                page = p;
                report(true);
            }
        }
    }, { root: stage, rootMargin: '-35% 0px -64% 0px', threshold: 0 });
    boxes.forEach((b) => bandIo.observe(b));

    // Read: the last image at least half on screen, or the end card reached
    // (a strip taller than the screen can never be half visible).
    const lastIo = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            const isEnd = entry.target === endEl;
            if ((isEnd && entry.isIntersecting) || (!isEnd && entry.intersectionRatio >= 0.5)) {
                o.onLastSeen?.();
            }
        }
    }, { root: stage, threshold: [0, 0.5, 1] });
    if (boxes.length) lastIo.observe(boxes[boxes.length - 1]);
    lastIo.observe(endEl);

    let userScrolled = false;
    function report(user) {
        if (reported === page) return;
        reported = page;
        o.onPage(page, { user: user && userScrolled });
    }

    // Scroll direction drives the chrome's auto-hide, as in the text reader.
    let lastY = 0;
    let raf = false;
    stage.addEventListener('scroll', () => {
        if (raf) return;
        raf = true;
        requestAnimationFrame(() => {
            raf = false;
            const y = stage.scrollTop;
            const dy = y - lastY;
            if (y < 80) o.onScrollDir?.('top');
            else if (dy > 6) o.onScrollDir?.('down');
            else if (dy < -6) o.onScrollDir?.('up');
            if (Math.abs(dy) > 6 || y < 80) lastY = y;
        });
    }, { passive: true, signal });
    // Only scrolling the reader does counts as moving (not the restore jump).
    for (const type of ['wheel', 'touchmove', 'keydown', 'pointerdown']) {
        stage.addEventListener(type, () => { userScrolled = true; }, { passive: true, signal });
    }

    // A tap anywhere toggles the chrome (no page-turn zones in a scroll).
    stage.addEventListener('click', (e) => {
        if (e.target.closest('a, button, input')) return;
        const sel = window.getSelection?.();
        if (sel && !sel.isCollapsed) return;
        o.onAction('chrome', { gesture: true });
    }, { signal });

    const behavior = () => (reducedMotion() ? 'auto' : 'smooth');

    function scrollToPage(p, { smooth = false, user = false } = {}) {
        p = Math.max(1, Math.min(count, p));
        if (user) userScrolled = true;
        const top = boxes[p - 1].offsetTop;
        stage.scrollTo({ top, behavior: smooth ? behavior() : 'auto' });
        page = p;
        report(user);
    }

    return {
        show: (p, { user = false } = {}) => scrollToPage(p, { user }),
        next() {
            if (page >= count) { userScrolled = true; stage.scrollTo({ top: endEl.offsetTop, behavior: behavior() }); return; }
            scrollToPage(page + 1, { smooth: true, user: true });
        },
        prev: () => scrollToPage(page - 1, { smooth: true, user: true }),
        first: () => scrollToPage(1, { smooth: true, user: true }),
        last: () => scrollToPage(count, { smooth: true, user: true }),
        screen(dir) {
            userScrolled = true;
            stage.scrollBy({ top: dir * stage.clientHeight * 0.9, behavior: behavior() });
        },
        current: () => page,
        get atEnd() { return false; },
        setFit() { /* vertical is always fit-width (max ~900px) */ },
        focus() { stage.focus({ preventScroll: true }); },
        destroy() {
            abort.abort();
            bandIo.disconnect();
            lastIo.disconnect();
            stage.replaceChildren();
            stage.removeAttribute('tabindex');
        },
    };
}
