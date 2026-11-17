// Mount-on-Turbo-visit plumbing shared by the text reader (reader/index.js)
// and the manga reader (manga-reader/index.js).
//
// A module runs once per document: Turbo re-inserting the same
// <script type="module"> on a later visit does not evaluate it again. So
// mounting is driven by turbo:load, plus one attempt at evaluation for the
// visit that first loaded the module (on a Turbo visit, turbo:load may fire
// before the module has arrived). Two guards keep it to exactly one reader
// per page:
//  - only roots matching `selector` are mounted (the text reader: roots
//    marked data-reader-module — a chapter page cached by the service worker
//    before the reader became a module carries its own inline copy of the
//    reader, and runs that);
//  - a tab left open across a deploy can evaluate a second copy of a module
//    (new hashed filename). The latest copy claims ownership on
//    `win[ownerKey]`; an older copy tears down what it mounted and stops
//    listening.

const TEXT_READER = {
    selector: '[data-reader-module]',
    ownerKey: '__novarrReaderOwner',
    claimEvent: 'novarr:reader-claimed',
};

export function installReader(doc, win, mountFn, options = {}) {
    const { selector, ownerKey, claimEvent } = { ...TEXT_READER, ...options };
    const owner = Symbol('novarr-reader');
    let active = null;   // { root, teardown } for the reader on screen

    const isOwner = () => win[ownerKey] === owner;

    // before-cache fires only when Turbo snapshots the page (the layout sets
    // turbo-cache-control: no-cache, so usually it won't); before-render
    // fires on every Turbo render that replaces this page.
    function leave() {
        if (!active) return;
        const { teardown } = active;
        active = null;
        teardown();
    }

    function boot() {
        if (!isOwner()) { retire(); return; }
        const root = doc.querySelector(selector);
        if (root === active?.root) return;
        // A previous reader that left without a Turbo render (shouldn't happen).
        leave();
        if (root) active = { root, teardown: mountFn(root) };
    }

    function claimed() {
        if (!isOwner()) retire();
    }

    // Superseded by a newer copy: release this page and go quiet for good.
    function retire() {
        leave();
        doc.removeEventListener('turbo:load', boot);
        doc.removeEventListener('turbo:before-cache', leave);
        doc.removeEventListener('turbo:before-render', leave);
        doc.removeEventListener(claimEvent, claimed);
    }

    win[ownerKey] = owner;
    // Older copies hear this synchronously and tear down before we mount.
    doc.dispatchEvent(new win.CustomEvent(claimEvent));
    doc.addEventListener('turbo:load', boot);
    doc.addEventListener('turbo:before-cache', leave);
    doc.addEventListener('turbo:before-render', leave);
    doc.addEventListener(claimEvent, claimed);
    boot();
    return { boot, leave, isOwner };
}
