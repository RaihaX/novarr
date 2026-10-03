/**
 * Shell colour theme: system (default) → light → dark.
 *
 * The first paint is handled by the inline script in layouts/app.blade.php
 * (it mirrors resolve() below), so there is no flash. This module keeps the
 * theme right afterwards:
 *   · <html> attributes survive Turbo visits, so the theme is re-applied on
 *     every render (the reader <-> shell transition flips it);
 *   · the OS preference is followed live while the choice is "system";
 *   · the theme-color meta tracks the active ground.
 *
 * The reader (chapters.show, rendered chromeless) owns its own theme setting
 * and writes data-bs-theme on <body>. On that page the shell yields: <html>
 * mirrors the reader's choice instead of the shell preference, so html-level
 * colour (overscroll, scrollbars, color-scheme) matches the reading surface.
 *
 * Attributes on <html>:
 *   data-bs-theme   "dark" | "light"  — Bootstrap + the --nv-* tokens
 *   data-theme      same value, a stable hook for non-Bootstrap code
 *   data-theme-pref "system" | "light" | "dark"  — drives the toggle's icon
 */

export const THEME_KEY = 'novarr_theme';
const PREFS = ['system', 'light', 'dark'];
const LABELS = { system: 'System', light: 'Light', dark: 'Dark' };

// Grounds for the theme-color meta (browser UI / iOS status bar).
const META_COLOR = { dark: '#0F1216', light: '#F7F8FA' };

export function readPref() {
    try {
        const v = localStorage.getItem(THEME_KEY);
        return PREFS.includes(v) ? v : 'system';
    } catch (_) {
        return 'system';
    }
}

function writePref(pref) {
    try {
        if (pref === 'system') localStorage.removeItem(THEME_KEY);
        else localStorage.setItem(THEME_KEY, pref);
    } catch (_) { /* private mode / blocked storage: in-memory only */ }
}

function systemPrefersLight() {
    try {
        return window.matchMedia('(prefers-color-scheme: light)').matches;
    } catch (_) {
        return false;
    }
}

/** "system" | "light" | "dark" → "light" | "dark". */
export function resolve(pref, prefersLight = systemPrefersLight()) {
    if (pref === 'light' || pref === 'dark') return pref;
    return prefersLight ? 'light' : 'dark';
}

/** Next step of the toggle cycle. */
export function nextPref(pref) {
    return PREFS[(PREFS.indexOf(pref) + 1) % PREFS.length] || 'system';
}

let memoryPref = null; // used when storage is unavailable

function currentPref() {
    return memoryPref ?? readPref();
}

function isReader(body = document.body) {
    return !!body && body.classList.contains('is-chromeless');
}

function setMeta(color) {
    let meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'theme-color';
        document.head.appendChild(meta);
    }
    if (meta.content !== color) meta.content = color;
}

function syncToggles(pref) {
    const next = nextPref(pref);
    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
        btn.setAttribute('aria-label', `Theme: ${LABELS[pref]}. Switch to ${LABELS[next]}`);
        btn.title = `Theme: ${LABELS[pref]}`;
        const label = btn.querySelector('[data-theme-label]');
        if (label) label.textContent = LABELS[pref];
    });
}

/**
 * Apply the theme to <html>. `body` is the body about to be shown (Turbo
 * passes the incoming one in turbo:before-render).
 */
export function applyTheme(body = document.body) {
    const root = document.documentElement;
    const pref = currentPref();
    let mode;

    if (isReader(body)) {
        // Yield to the reader: mirror what it set (or will set) on <body>.
        const own = body.getAttribute('data-bs-theme');
        let readerPref = null;
        try { readerPref = localStorage.getItem('reader_theme'); } catch (_) { /* ignore */ }
        mode = own || (readerPref && readerPref !== 'dark' ? 'light' : 'dark');
    } else {
        mode = resolve(pref);
    }

    root.setAttribute('data-bs-theme', mode);
    root.setAttribute('data-theme', mode);
    root.setAttribute('data-theme-pref', pref);

    if (isReader(body) && body === document.body) {
        // Sepia/light/dark reader grounds: read the painted colour.
        const bg = getComputedStyle(body).backgroundColor;
        setMeta(bg && bg !== 'rgba(0, 0, 0, 0)' ? bg : META_COLOR[mode]);
    } else {
        setMeta(META_COLOR[mode]);
    }
    syncToggles(pref);
    return mode;
}

/** Set (and persist) the shell preference; returns the resolved mode. */
export function setTheme(pref) {
    if (!PREFS.includes(pref)) pref = 'system';
    memoryPref = pref;
    writePref(pref);
    return applyTheme();
}

export function getTheme() {
    return { pref: currentPref(), mode: document.documentElement.getAttribute('data-bs-theme') };
}

export function cycleTheme() {
    return setTheme(nextPref(currentPref()));
}

let readerObserver = null;

function watchReaderBody() {
    readerObserver?.disconnect();
    readerObserver = null;
    if (!isReader() || typeof MutationObserver === 'undefined') return;
    // The reader flips body[data-bs-theme] / its theme class from its own
    // settings popover; follow it.
    readerObserver = new MutationObserver(() => applyTheme());
    readerObserver.observe(document.body, { attributes: true, attributeFilter: ['data-bs-theme', 'class'] });
}

export function initTheme() {
    // Delegated, so toggles in swapped-in bodies work without re-binding.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest?.('[data-theme-toggle]');
        if (!btn) return;
        e.preventDefault();
        cycleTheme();
    });

    try {
        window.matchMedia('(prefers-color-scheme: light)')
            .addEventListener('change', () => { if (currentPref() === 'system') applyTheme(); });
    } catch (_) { /* old Safari: no live follow */ }

    // Before Turbo paints the next page, set <html> for *that* page so the
    // reader ↔ shell hop never flashes the wrong ground.
    document.addEventListener('turbo:before-render', (e) => {
        const newBody = e.detail?.newBody;
        if (newBody) applyTheme(newBody);
    });
    document.addEventListener('turbo:load', () => {
        applyTheme();
        watchReaderBody();
    });

    // Another tab changed the preference.
    window.addEventListener('storage', (e) => {
        if (e.key === THEME_KEY) { memoryPref = null; applyTheme(); }
    });
}
