/**
 * Home's layout once the reader has categories ([data-home].is-categories-first):
 * the shelves Home had before (Continue reading, Continue listening,
 * Favourites, Want to read — [data-home-optional]) are hidden by CSS unless
 * turned back on under "Customise Home" ([data-home-toggle]). The choice is
 * this browser's (localStorage, ./categories-logic.js HOME_STORAGE_KEY);
 * the page works the same without storage (everything off).
 */
import { HOME_STORAGE_KEY, parseHomeToggles, setHomeToggle } from './categories-logic';

function read() {
    try { return parseHomeToggles(localStorage.getItem(HOME_STORAGE_KEY)); } catch (_) { return parseHomeToggles(null); }
}

function write(toggles) {
    try { localStorage.setItem(HOME_STORAGE_KEY, JSON.stringify(toggles)); } catch (_) { /* private window */ }
}

function apply(root, toggles) {
    root.querySelectorAll('[data-home-optional]').forEach((section) => {
        section.classList.toggle('is-shown', toggles[section.dataset.homeOptional] === true);
    });
    root.querySelectorAll('[data-home-toggle]').forEach((box) => {
        box.checked = toggles[box.dataset.homeToggle] === true;
    });
}

export function initHomeLayout() {
    const root = document.querySelector('[data-home].is-categories-first');
    if (!root || root.dataset.layoutBound === '1') return;
    root.dataset.layoutBound = '1';
    let toggles = read();
    apply(root, toggles);
    root.querySelectorAll('[data-home-toggle]').forEach((box) => {
        box.addEventListener('change', () => {
            toggles = setHomeToggle(toggles, box.dataset.homeToggle, box.checked);
            write(toggles);
            apply(root, toggles);
        });
    });
}
