// ---- Reader preferences (persisted in localStorage) ----
// Typography keys can be overridden per novel ("This novel only"): the
// override lives as a JSON snapshot under reader_novel_{id} and wins over the
// global keys while it exists. Behavioural prefs (autoNext) stay global.
export const PREF_KEYS = {
    font: 'reader_font', measure: 'reader_measure', theme: 'reader_theme',
    family: 'reader_family', lineHeight: 'reader_lineheight',
    margin: 'reader_margin', justify: 'reader_justify',
};
export const PREF_DEFAULTS = {
    font: '19', measure: '68', theme: 'dark', family: 'read',
    lineHeight: '1.75', margin: 'm', justify: '0',
};
// Handoff §"State Management": fontSize 15–24px, measure 56–80ch.
export const FONT_MIN = 15, FONT_MAX = 24;
export const MEASURE_MIN = 56, MEASURE_MAX = 80, MEASURE_STEP = 2;
// Legacy width buckets → a measure in ch, so nobody's saved column width is lost.
const LEGACY_WIDTH = { narrow: '56', medium: '68', wide: '76', full: '80' };

export function clampNum(v, min, max, fallback) {
    const n = parseFloat(v);
    return isNaN(n) ? fallback : Math.min(max, Math.max(min, n));
}

/**
 * The preference store for one novel. `storage` is localStorage (any object
 * with getItem/setItem/removeItem). `prefs` is mutated in place, so callers
 * can hold on to it.
 */
export function createPrefs(storage, novelId) {
    const perNovelKey = 'reader_novel_' + novelId;
    let perNovel = null;
    try { perNovel = JSON.parse(storage.getItem(perNovelKey) || 'null'); } catch (e) { perNovel = null; }

    // Behavioural, global (not per novel): auto-load the next chapter, and
    // scroll vs Kindle-style pages (reader/pages.js).
    const prefs = {
        autoNext: storage.getItem('reader_autonext') || '1',
        pageMode: storage.getItem('reader_pagemode') === 'pages' ? 'pages' : 'scroll',
    };

    function load() {
        for (const [k, sk] of Object.entries(PREF_KEYS)) {
            prefs[k] = storage.getItem(sk) ?? PREF_DEFAULTS[k];
        }
        // Migrations from the pre-redesign reader.
        if (storage.getItem('reader_measure') === null) {
            const legacy = LEGACY_WIDTH[storage.getItem('reader_width')];
            if (legacy) prefs.measure = legacy;
        }
        if (perNovel) Object.assign(prefs, perNovel);
        if (prefs.lineHeight === '1.8') prefs.lineHeight = '1.75';   // old "Normal"
        prefs.font = clampNum(prefs.font, FONT_MIN, FONT_MAX, 19);
        prefs.measure = Math.round(clampNum(prefs.measure, MEASURE_MIN, MEASURE_MAX, 68) / MEASURE_STEP) * MEASURE_STEP;
    }
    load();

    function persist(k, v) {
        // font/measure stay numeric in memory (they're stepped); storage is strings.
        prefs[k] = (k === 'font' || k === 'measure') ? parseInt(v, 10) : String(v);
        const stored = String(v);
        if (k === 'autoNext') { storage.setItem('reader_autonext', stored); return; }
        if (k === 'pageMode') { storage.setItem('reader_pagemode', stored); return; }
        if (perNovel) {
            perNovel[k] = stored;
            storage.setItem(perNovelKey, JSON.stringify(perNovel));
        } else {
            storage.setItem(PREF_KEYS[k], stored);
        }
    }

    /** Turn the per-novel override on (snapshot current typography) or off. */
    function setPerNovel(on) {
        if (on) {
            perNovel = {};
            for (const k of Object.keys(PREF_KEYS)) perNovel[k] = String(prefs[k]);
            storage.setItem(perNovelKey, JSON.stringify(perNovel));
        } else {
            storage.removeItem(perNovelKey);
            perNovel = null;
            load();
        }
    }

    return {
        prefs, load, persist, setPerNovel,
        get perNovel() { return perNovel; },
    };
}

const families = {
    read: "var(--rd-font-read)",
    sans: "var(--bs-body-font-family)",
    serif: "Georgia, 'Times New Roman', serif",
    legible: "'Atkinson Hyperlegible', var(--bs-body-font-family)",
};
const margins = { s: '0px', m: '12px', l: '28px' };

/**
 * The reading theme (dark / sepia / light) recolours the whole page via a
 * body class, so focus mode and the page gutters match the reading surface.
 * Shared with the manga reader, which uses the same reader_theme setting.
 */
export function applyTheme(theme, body = document.body) {
    body.classList.remove('reader-theme-sepia', 'reader-theme-light');
    if (theme === 'sepia' || theme === 'light') {
        body.classList.add('reader-theme-' + theme);
    }
    body.setAttribute('data-bs-theme', theme === 'dark' ? 'dark' : 'light');
}

export function applyPrefs(readerEl, store) {
    const { prefs } = store;
    // Typography is driven by custom properties on the reader root; the
    // stylesheet owns the rest, so nothing needs restyling per section.
    readerEl.style.setProperty('--rd-size', prefs.font + 'px');
    readerEl.style.setProperty('--rd-measure', prefs.measure + 'ch');
    readerEl.style.setProperty('--rd-family', families[prefs.family] || families.read);
    readerEl.style.setProperty('--rd-line-h', prefs.lineHeight);
    readerEl.style.setProperty('--rd-gutter', margins[prefs.margin] ?? margins.m);
    readerEl.style.setProperty('--rd-align', prefs.justify === '1' ? 'justify' : 'start');
    readerEl.style.setProperty('--rd-hyphens', prefs.justify === '1' ? 'auto' : 'manual');

    applyTheme(prefs.theme);

    document.getElementById('fontSizeLabel').textContent = prefs.font + 'px';
    document.getElementById('measureLabel').textContent = prefs.measure + 'ch';

    // reflect active buttons + announce state to assistive tech
    const reflect = (sel, key, val) => document.querySelectorAll(sel).forEach(b => {
        const on = b.dataset[key] === val;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    reflect('#themeGroup [data-theme]', 'theme', prefs.theme);
    reflect('#familyGroup [data-family]', 'family', prefs.family);
    reflect('#lineHeightGroup [data-lineheight]', 'lineheight', prefs.lineHeight);
    reflect('#autoNextGroup [data-autonext]', 'autonext', prefs.autoNext);
    reflect('#pageModeGroup [data-pagemode]', 'pagemode', prefs.pageMode);
    reflect('#marginGroup [data-margin]', 'margin', prefs.margin);
    reflect('#justifyGroup [data-justify]', 'justify', prefs.justify);
    document.getElementById('perNovelPrefs').checked = !!store.perNovel;
}
