// The "latest position" banner, shared by the text and manga readers.
//
// Non-blocking: a small bar fixed to the bottom edge (manga: under the top
// bar), so it never shifts the page or moves the scroll position, and the
// reader keeps working underneath. It does not take focus (the reader's own
// keys keep working); the message is announced through the reader's live
// region. Real buttons; Escape = "Stay here" unless a dialog/drawer is open
// (that Escape belongs to the dialog). No auto-dismiss.

let seq = 0;

/**
 * @param {object} o
 * @param {HTMLElement} o.host       the reader root; the banner is appended to it
 * @param {string} o.text            the question (promptText())
 * @param {() => void} o.onGo
 * @param {() => void} o.onStay
 * @param {AbortSignal} o.signal     this visit's lifetime
 * @param {HTMLElement} [o.announcer] an aria-live region
 * @param {() => boolean} [o.escBlocked] true while Escape belongs to something else
 * @returns {{ el: HTMLElement, close: () => void }}
 */
export function showLatestPrompt({ host, text, onGo, onStay, signal, announcer = null, escBlocked = () => false }) {
    const doc = host.ownerDocument;
    const id = 'readerLatestText' + (++seq);

    const el = doc.createElement('div');
    el.className = 'reader-latest';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'false');
    el.setAttribute('aria-labelledby', id);

    const p = doc.createElement('p');
    p.className = 'reader-latest-text';
    p.id = id;
    p.textContent = text;

    const actions = doc.createElement('div');
    actions.className = 'reader-latest-actions';
    const go = doc.createElement('button');
    go.type = 'button';
    go.className = 'reader-latest-btn is-primary';
    go.textContent = 'Go to latest';
    const stay = doc.createElement('button');
    stay.type = 'button';
    stay.className = 'reader-latest-btn';
    stay.textContent = 'Stay here';
    actions.append(go, stay);
    el.append(p, actions);

    const local = new AbortController();
    const opts = { signal: local.signal };
    let open = true;

    function close() {
        if (!open) return;
        open = false;
        const hadFocus = el.contains(doc.activeElement);
        local.abort();
        el.remove();
        if (hadFocus) host.focus?.({ preventScroll: true });
    }
    // Leaving the page (Turbo teardown) takes the banner with it.
    signal?.addEventListener('abort', close, { once: true });

    go.addEventListener('click', () => { close(); onGo(); }, opts);
    stay.addEventListener('click', () => { close(); onStay(); }, opts);
    // Capture: runs before the readers' own Escape handlers (focus mode,
    // manga "show chrome"), which skip a defaultPrevented event.
    doc.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || e.defaultPrevented || escBlocked()) return;
        e.preventDefault();
        close();
        onStay();
    }, { signal: local.signal, capture: true });

    host.appendChild(el);
    if (announcer) announcer.textContent = text;
    return { el, close };
}
