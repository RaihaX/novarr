// Self-hosted fonts (Fontsource), owned by the Performance stream.
//
// Always loaded (the brand faces, variable weight axis):
//   Geist        — UI face            ($font-ui)
//   Geist Mono   — counts, timestamps ($font-mono)
//   Literata     — reading face, roman + italic ($font-read)
// Only latin + latin-ext faces are emitted: vite.config.js strips the other
// script subsets (cyrillic, greek, vietnamese, symbols…) from Fontsource CSS,
// because Novarr's content is English. Characters outside those ranges fall
// back to the next family in the stack.
//
// Not loaded globally:
//   Inter                 — removed. The stack names plain 'Inter' (a locally
//                           installed copy), never the Fontsource 'Inter Variable'.
//   Atkinson Hyperlegible — the reader's "Legible" option; loaded on demand by
//                           loadReaderFonts() below.
import '@fontsource-variable/geist';
import '@fontsource-variable/geist-mono';
import '@fontsource-variable/literata';
import '@fontsource-variable/literata/wght-italic.css';

let readerFonts = null;

/**
 * Load the reader-only font faces (Atkinson Hyperlegible 400/700) once.
 * Only the @font-face CSS (~1 KB) is fetched here; the browser downloads a
 * woff2 file only when text is actually rendered in that family.
 * Safe to call repeatedly; resolves to false if the chunk can't be fetched
 * (e.g. offline before it was ever cached), so callers never need a catch.
 */
export function loadReaderFonts() {
    if (!readerFonts) {
        readerFonts = Promise.all([
            import('@fontsource/atkinson-hyperlegible/400.css'),
            import('@fontsource/atkinson-hyperlegible/700.css'),
        ]).then(() => true, () => {
            readerFonts = null; // allow a retry on the next call
            return false;
        });
    }
    return readerFonts;
}

// Exposed for inline Blade scripts (not part of the module graph).
window.loadReaderFonts = loadReaderFonts;

// Load them automatically whenever the reader is shown (first load or a
// Turbo visit), so the reader page needs no change to get the font.
document.addEventListener('turbo:load', () => {
    if (document.querySelector('[data-reader-page]')) loadReaderFonts();
});
