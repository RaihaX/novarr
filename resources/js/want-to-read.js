/**
 * "Want to read" toggles in the web UI — the DOM glue; the decisions are in
 * ./want-to-read-logic.js. A button [data-want-to-read][data-novel]
 * (aria-pressed = on the list) flips at once and saves through
 * PUT / DELETE /api/v1/novels/{id}/want-to-read with the web session; a
 * failure puts it back and says why in a toast. Used by the novel page's
 * header button and the Want to read page's tiles (a tile taken off stays,
 * dimmed, so a second click puts it back).
 *
 * Bound per Turbo visit (turbo:load); the elements are new on every visit.
 */
import { api, messageOf, networkMessage } from './api';
import { wantRequest, wantAnnouncement, wantTitle } from './want-to-read-logic';

const toast = (msg, type = 'info') => window.Novarr?.showToast?.(msg, type);

function setPressed(button, want) {
    button.setAttribute('aria-pressed', want ? 'true' : 'false');
    button.classList.toggle('is-wanted', want);
    if (!button.hasAttribute('data-want-keep-title')) button.title = wantTitle(want);
    button.closest('[data-want-item]')?.classList.toggle('is-removed', !want);
}

function announce(button, text) {
    const status = document.querySelector('[data-want-status]');
    if (!status) return;
    status.textContent = '';
    requestAnimationFrame(() => { status.textContent = text; });
}

function initButton(button) {
    if (button.dataset.wantBound) return;
    button.dataset.wantBound = '1';

    button.addEventListener('click', async () => {
        if (button.dataset.busy) return;
        const want = button.getAttribute('aria-pressed') !== 'true';
        const request = wantRequest(button.dataset.novel, want);
        if (!request) return;

        button.dataset.busy = '1';
        button.setAttribute('aria-busy', 'true');
        setPressed(button, want);
        let failure = null;
        try {
            const r = await api(request.method, request.url);
            if (!r.ok) failure = messageOf(r, want ? 'Could not add it to Want to read' : 'Could not remove it from Want to read');
        } catch (err) {
            failure = networkMessage(err, 'Could not save');
        }
        delete button.dataset.busy;
        button.removeAttribute('aria-busy');

        if (failure) {
            setPressed(button, !want);
            toast(failure, 'danger');
            return;
        }
        announce(button, wantAnnouncement(want, button.dataset.name));
    });
}

export function initWantToRead() {
    document.querySelectorAll('[data-want-to-read]').forEach(initButton);
}
