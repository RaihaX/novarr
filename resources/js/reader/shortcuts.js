// Single-key reader shortcuts and the guard that keeps them out of the way.

/** e.key → action name. Shifted / modified keys never match (see the guard). */
export const SHORTCUTS = {
    ArrowLeft: 'prev', k: 'prev',
    ArrowRight: 'next', j: 'next',
    t: 'toc', a: 'settings', p: 'playback', f: 'focus',
};

const TYPING = 'input, textarea, select, [contenteditable]:not([contenteditable="false"])';

export function isTypingTarget(target) {
    if (!target) return false;
    if (target.isContentEditable) return true;
    return typeof target.closest === 'function' && !!target.closest(TYPING);
}

/**
 * Should a reader shortcut ignore this keydown? Yes when a modifier is held
 * (Alt+← is the browser's Back, Ctrl/⌘ combos belong to the browser), while
 * typing, while focus is in the chapter-list drawer, or while a dialog is open.
 */
export function shortcutBlocked(e, { dialogOpen = false } = {}) {
    if (e.altKey || e.metaKey || e.ctrlKey || e.shiftKey) return true;
    const t = e.target;
    if (isTypingTarget(t)) return true;
    if (t && typeof t.closest === 'function' && t.closest('#tocPanel')) return true;
    return !!dialogOpen;
}
