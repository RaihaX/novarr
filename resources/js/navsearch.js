/**
 * Navbar search field → command palette trigger.
 *
 * The field stays a real GET form to /search (works without JS, and the full
 * results page is unchanged). With JS, clicking it or typing into it opens
 * the palette (resources/js/palette.js), carrying over anything typed; Enter
 * on an empty field also opens it. Focus alone does not open it, so focus can
 * return here when the palette closes without bouncing straight back in.
 */
export function initNavSearch() {
    const input = document.getElementById('navSearch');
    if (!input || input.dataset.paletteBound) return;
    input.dataset.paletteBound = '1';

    const open = (initial = '') => {
        const N = window.Novarr;
        if (!N?.openPalette) return false;
        const text = initial || input.value;
        input.value = '';
        N.openPalette(text, { opener: input });
        return true;
    };

    input.addEventListener('mousedown', (e) => {
        if (open()) e.preventDefault();
    });

    input.addEventListener('keydown', (e) => {
        if (e.metaKey || e.ctrlKey || e.altKey || e.isComposing) return;
        if (e.key.length === 1 && e.key !== ' ') {
            // A printable character: open with it as the first letter.
            if (open(input.value + e.key)) e.preventDefault();
        } else if (e.key === 'Enter' || e.key === 'ArrowDown') {
            if (open()) e.preventDefault();
        }
    });
}
