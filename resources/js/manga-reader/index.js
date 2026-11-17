// Manga chapter reader (resources/views/chapters/manga.blade.php).
//
// Its own Vite entry, loaded with @vite from the manga view, mounted on each
// Turbo visit through the same install plumbing as the text reader
// (reader/install.js). Reuses the text reader's pieces where they fit: the
// reading theme (reader_theme + prefs.applyTheme), the chapter drawer
// (toc.js), dialog plumbing, the shortcut typing guard and the wake lock.
import { installReader } from '../reader/install.js';
import { createDialogs, reducedMotion } from '../reader/dialogs.js';
import { createPrefs, applyTheme } from '../reader/prefs.js';
import { isTypingTarget } from '../reader/shortcuts.js';
import { createWakeLock } from '../reader/wakelock.js';
import { initToc } from '../reader/toc.js';
import {
    FIT_LABELS, MODE_LABELS, normaliseMode, nextMode, nextFit, defaultFit,
    resumePage, keyAction, pageToSlider, sliderToPage, pageLabel,
} from './logic.js';
import { createProgress, readLocalPosition } from './progress.js';
import { createPagedView } from './paged.js';
import { createVerticalView } from './vertical.js';
import { chapterUrl } from './endcard.js';

const FIT_KEY = 'manga_fit';
const THEMES = ['dark', 'sepia', 'light'];
const THEME_LABELS = { dark: 'Dark', sepia: 'Sepia', light: 'Light' };
const WAKE_IDLE_MS = 10 * 60 * 1000;

/** localStorage, or an in-memory stand-in when site data is blocked. */
function safeStorage() {
    try {
        const s = window.localStorage;
        s.getItem('novarr');
        return s;
    } catch (e) {
        const m = new Map();
        return {
            getItem: (k) => (m.has(k) ? m.get(k) : null),
            setItem: (k, v) => { m.set(k, String(v)); },
            removeItem: (k) => { m.delete(k); },
        };
    }
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

export function mount(root) {
    const pageAbort = new AbortController();
    const signal = pageAbort.signal;
    const ds = root.dataset;
    const chapterId = parseInt(ds.chapterId, 10);
    const novelId = parseInt(ds.novelId, 10);
    const storage = safeStorage();

    const $ = (id) => document.getElementById(id);
    const stage = $('mrStage');
    const statusEl = $('mrStatus');
    const slider = $('mrSlider');
    const counter = $('mrCounter');
    const fitBtn = $('mrFit');
    const fsBtn = $('mrFullscreen');
    const themeBtn = $('mrTheme');
    const themeLabel = $('mrThemeLabel');
    const announce = $('mrAnnounce');
    const labelEl = $('mrChapterLabel');
    const modeBtns = [...root.querySelectorAll('.mr-modes [data-mode]')];
    const retryIcon = $('mrIconRetry');

    document.body.classList.add('mr-open');

    // ---- Reading theme: the text reader's setting (dark / sepia / light) ----
    const store = createPrefs(storage, novelId);
    function paintTheme() {
        applyTheme(store.prefs.theme);
        const t = THEMES.includes(store.prefs.theme) ? store.prefs.theme : 'dark';
        const nextT = THEMES[(THEMES.indexOf(t) + 1) % THEMES.length];
        themeLabel.textContent = THEME_LABELS[t];
        themeBtn.setAttribute('aria-label', `Reading theme: ${THEME_LABELS[t]}. Switch to ${THEME_LABELS[nextT]}`);
        themeBtn.title = `Theme: ${THEME_LABELS[t]}`;
    }
    paintTheme();
    themeBtn.addEventListener('click', () => {
        const t = THEMES.includes(store.prefs.theme) ? store.prefs.theme : 'dark';
        try { store.persist('theme', THEMES[(THEMES.indexOf(t) + 1) % THEMES.length]); } catch (e) { /* storage blocked */ }
        paintTheme();
    }, { signal });

    // ---- State ----
    let data = null;
    let count = 0;
    let mode = 'ltr';
    let fit = null;
    let view = null;
    let progress = null;
    let reportedOnce = false;

    try { fit = storage.getItem(FIT_KEY); } catch (e) { fit = null; }
    if (!['width', 'height', 'original'].includes(fit)) fit = null;
    const effectiveFit = () => fit || defaultFit(window.innerWidth, window.innerHeight);

    // ---- Chrome (top + bottom bars), auto-hiding like the text reader ----
    const tocPanel = $('tocPanel');
    function setChromeHidden(hidden) {
        if (hidden && (tocPanel?.classList.contains('show') || root.querySelector('.mr-chrome:focus-within'))) hidden = false;
        root.classList.toggle('is-chrome-hidden', hidden);
    }
    const chromeHidden = () => root.classList.contains('is-chrome-hidden');

    function visit(url) {
        if (!url) return;
        if (window.Turbo) window.Turbo.visit(url);
        else window.location.href = url;
    }

    // ---- Wake lock: held while reading, dropped after a long idle ----
    const wake = createWakeLock();
    let idleTimer = null;
    function poke() {
        wake.set(true);
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => wake.set(false), WAKE_IDLE_MS);
    }
    document.addEventListener('visibilitychange', () => wake.visibilityChanged(), { signal });
    for (const type of ['pointerdown', 'keydown', 'wheel']) {
        document.addEventListener(type, poke, { passive: true, signal });
    }

    // ---- Status overlay: loading, errors, empty chapter ----
    function showStatus(build) {
        statusEl.replaceChildren();
        build(statusEl);
        statusEl.hidden = false;
    }
    function hideStatus() {
        statusEl.hidden = true;
        statusEl.replaceChildren();
    }
    function link(href, text, cls = 'btn btn-secondary') {
        const a = document.createElement('a');
        a.href = href;
        a.className = cls;
        a.textContent = text;
        return a;
    }
    function para(text, cls) {
        const p = document.createElement('p');
        if (cls) p.className = cls;
        p.textContent = text;
        return p;
    }

    // ---- Page UI: slider, counter, announcer ----
    function paintPage(p) {
        slider.max = String(Math.max(1, count));
        slider.value = String(pageToSlider(p, count, mode));
        slider.setAttribute('aria-valuetext', pageLabel(p, count));
        counter.textContent = `${p} / ${count}`;
    }

    function onPage(p, { user = false } = {}) {
        paintPage(p);
        if (mode !== 'vertical') announce.textContent = pageLabel(p, count);
        // Progress: the page on open, then only pages the reader moved to (a
        // layout settling in vertical mode is not a move).
        if (!reportedOnce || user) {
            reportedOnce = true;
            progress?.pageShown(p, { readAtEnd: mode !== 'vertical' });
        }
    }

    // ---- Actions (keys, taps, swipes, buttons) ----
    function run(action) {
        if (!view) {
            if (action === 'showChrome' || action === 'chrome') setChromeHidden(action === 'chrome' ? !chromeHidden() : false);
            return;
        }
        switch (action) {
            case 'next': view.next(); if (mode !== 'vertical') setChromeHidden(true); break;
            case 'prev': view.prev(); if (mode !== 'vertical') setChromeHidden(true); break;
            case 'first': view.first(); break;
            case 'last': view.last(); break;
            case 'screenDown': view.screen(1); break;
            case 'screenUp': view.screen(-1); break;
            case 'prevChapter': if (data?.prev) visit(chapterUrl(data.prev.id)); break;
            case 'nextChapter': if (data?.next) visit(chapterUrl(data.next.id)); break;
            case 'fullscreen': toggleFullscreen(); break;
            case 'mode': setMode(nextMode(mode)); break;
            case 'showChrome': setChromeHidden(false); break;
            case 'chrome': setChromeHidden(!chromeHidden()); break;
            default: break;
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
        if (document.querySelector('.offcanvas.show, .modal.show')) return;
        if (isTypingTarget(e.target)) return;   // incl. the page slider
        if ((e.key === ' ' || e.key === 'Enter') && e.target.closest?.('a, button')) return;
        const action = keyAction(e, mode);
        if (!action) return;
        e.preventDefault();
        run(action);
    }, { signal });

    slider.addEventListener('input', () => {
        const p = sliderToPage(slider.value, count, mode);
        counter.textContent = `${p} / ${count}`;
        slider.setAttribute('aria-valuetext', pageLabel(p, count));
    }, { signal });
    slider.addEventListener('change', () => {
        if (!view) return;
        view.show(sliderToPage(slider.value, count, mode), { user: true });
    }, { signal });

    // ---- Reading mode (per series, saved on the server) ----
    function paintModes() {
        modeBtns.forEach((b) => {
            const on = b.dataset.mode === mode;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        root.dataset.mode = mode;
        fitBtn.hidden = mode === 'vertical';
    }

    function saveMode(m) {
        fetch(ds.modeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ mode: m }),
        }).then((r) => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
        }).catch(() => {
            window.Novarr?.showToast?.('Could not save the reading mode — it applies to this visit only.', 'warning');
        });
    }

    function setMode(m, { persist = true } = {}) {
        m = normaliseMode(m);
        if (m === mode && view) return;
        const keep = view ? view.current() : 1;
        mode = m;
        paintModes();
        if (data && count > 0) render(keep);
        if (persist) {
            saveMode(m);
            announce.textContent = MODE_LABELS[m];
        }
    }
    modeBtns.forEach((b) => b.addEventListener('click', () => setMode(b.dataset.mode), { signal }));

    // ---- Fit (per device) ----
    function paintFit() {
        const f = effectiveFit();
        fitBtn.textContent = FIT_LABELS[f];
        fitBtn.setAttribute('aria-label', `Page fit: ${FIT_LABELS[f].toLowerCase()}. Switch to ${FIT_LABELS[nextFit(f)].toLowerCase()}`);
    }
    fitBtn.addEventListener('click', () => {
        fit = nextFit(effectiveFit());
        try { storage.setItem(FIT_KEY, fit); } catch (e) { /* storage blocked */ }
        paintFit();
        view?.setFit(fit);
    }, { signal });
    paintFit();

    // ---- Fullscreen ----
    const fsSupported = !!(document.fullscreenEnabled && document.documentElement.requestFullscreen);
    fsBtn.hidden = !fsSupported;
    function toggleFullscreen() {
        if (!fsSupported) return;
        if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
        else document.documentElement.requestFullscreen().catch(() => {});
    }
    fsBtn.addEventListener('click', toggleFullscreen, { signal });
    document.addEventListener('fullscreenchange', () => {
        fsBtn.setAttribute('aria-pressed', document.fullscreenElement ? 'true' : 'false');
        fsBtn.classList.toggle('is-active', !!document.fullscreenElement);
    }, { signal });

    // ---- Views ----
    function render(startPage) {
        view?.destroy();
        const common = {
            stage, pages: data.pages, chapter: data.chapter, next: data.next,
            novelUrl: ds.novelUrl, novelName: ds.novelName, retryIcon, reducedMotion,
            onPage, onAction: run,
        };
        if (mode === 'vertical') {
            view = createVerticalView({
                ...common,
                onLastSeen: () => progress?.markRead(),
                onScrollDir: (dir) => setChromeHidden(dir === 'down'),
            });
            view.show(startPage);
            view.focus();
        } else {
            view = createPagedView({
                ...common,
                mode,
                fit: effectiveFit(),
                onBeforeFirst: () => { if (data.prev) visit(chapterUrl(data.prev.id, '?page=last')); },
            });
            view.show(startPage);
            root.focus({ preventScroll: true });
        }
    }

    function setup(json) {
        data = json;
        count = Array.isArray(json.pages) ? json.pages.length : 0;
        if (json.chapter?.label) labelEl.textContent = json.chapter.label;
        mode = normaliseMode(json.reading_mode);
        paintModes();

        if (count === 0) {
            root.classList.add('is-empty');
            setChromeHidden(false);
            showStatus((box) => {
                box.append(para("This chapter's pages haven't been downloaded yet.", 'mr-status-title'));
                box.append(para('They download in the background; check back soon, or read another chapter.', 'mr-status-sub'));
                const actions = document.createElement('div');
                actions.className = 'mr-status-actions';
                actions.append(link(ds.novelUrl, 'Back to ' + (ds.novelName || 'the novel'), 'btn btn-primary'));
                if (json.prev) actions.append(link(chapterUrl(json.prev.id), 'Previous: ' + json.prev.label));
                if (json.next) actions.append(link(chapterUrl(json.next.id), 'Next: ' + json.next.label));
                box.append(actions);
            });
            return;
        }
        root.classList.remove('is-empty');
        hideStatus();

        // ?page=N / ?page=last wins once, then leaves the address bar so a
        // reload resumes from the saved position instead.
        const url = new URL(window.location.href);
        const query = url.searchParams.get('page');
        if (query !== null) {
            url.searchParams.delete('page');
            history.replaceState(history.state, '', url.pathname + url.search + url.hash);
        }
        const start = resumePage({
            query,
            progress: json.chapter.progress,
            read: json.chapter.read,
            count,
            local: readLocalPosition(storage, chapterId),
        });

        progress = createProgress({
            chapterId, url: ds.progressUrl, stored: json.chapter.progress, read: json.chapter.read,
            count, signal, storage,
        });
        render(start);
        // Chrome starts visible; the first page turn hides it.
        setChromeHidden(false);
    }

    async function load() {
        showStatus((box) => {
            const sp = document.createElement('span');
            sp.className = 'spinner-border spinner-border-sm';
            sp.setAttribute('aria-hidden', 'true');
            box.append(sp, document.createTextNode(' Loading pages…'));
        });
        try {
            const res = await fetch(ds.pagesUrl, { headers: { Accept: 'application/json' }, signal });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const json = await res.json();
            if (signal.aborted) return;
            setup(json);
        } catch (err) {
            if (signal.aborted) return;
            setChromeHidden(false);
            showStatus((box) => {
                box.append(para("Couldn't load this chapter's pages.", 'mr-status-title'));
                box.append(para(navigator.onLine === false ? "You're offline." : 'The server did not answer as expected.', 'mr-status-sub'));
                const actions = document.createElement('div');
                actions.className = 'mr-status-actions';
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'btn btn-primary';
                retry.textContent = 'Retry';
                retry.addEventListener('click', load, { signal });
                actions.append(retry, link(ds.novelUrl, 'Back to ' + (ds.novelName || 'the novel')));
                box.append(actions);
                retry.focus({ preventScroll: true });
            });
        }
    }

    // ---- Chapter drawer: the text reader's toc.js ----
    const tocCleanup = initToc({
        state: { novelId },
        signal,
        sections: [{ id: chapterId }],
        currentIdx: 0,
        root,
        dialogs: createDialogs(),
        toggleSettings: () => {},
    });

    // ---- Progress delivery when the page goes away ----
    window.addEventListener('pagehide', () => progress?.flush(true), { signal });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') progress?.flush(true);
    }, { signal });

    poke();
    load();

    // ---- Teardown: leaving via Turbo (turbo:before-render) ----
    return function teardown() {
        if (signal.aborted) return;
        try { progress?.flush(); } catch (e) { /* best effort */ }
        view?.destroy();
        view = null;
        tocCleanup();
        clearTimeout(idleTimer);
        wake.release();
        try { window.bootstrap?.Offcanvas.getInstance(tocPanel)?.dispose(); } catch (e) { /* best effort */ }
        document.querySelectorAll('.offcanvas-backdrop').forEach((n) => n.remove());
        document.body.classList.remove('mr-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
        pageAbort.abort();
    };
}

/** Exported for tests: mount #manga-reader on each Turbo visit. */
export function installMangaReader(doc, win, mountFn = mount) {
    return installReader(doc, win, mountFn, {
        selector: '#manga-reader',
        ownerKey: '__novarrMangaReaderOwner',
        claimEvent: 'novarr:manga-reader-claimed',
    });
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    installMangaReader(document, window);
}

