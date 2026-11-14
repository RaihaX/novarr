// Footer read controls ("Mark to here", "Mark read") — they always act on the
// chapter currently under the viewport, which in continuous reading is not
// necessarily the one the page was rendered for.

export function applyReadUi(readToggle, read) {
    if (!readToggle) return;
    readToggle.classList.toggle('is-read', read);
    readToggle.textContent = read ? 'Read' : 'Mark read';
    readToggle.dataset.read = read ? '1' : '0';
    readToggle.setAttribute('aria-pressed', read ? 'true' : 'false');
}

/** Point both footer controls at `section` (an entry of the reader's sections). */
export function applyFooterState({ readThrough, readToggle }, section) {
    if (readThrough) readThrough.dataset.id = String(section.id);
    if (readToggle) {
        readToggle.dataset.id = String(section.id);
        applyReadUi(readToggle, !!section.read);
    }
}
