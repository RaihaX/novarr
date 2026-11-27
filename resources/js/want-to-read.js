/**
 * The built-in reading lists' toggles in the web UI — "Want to read" (the
 * bookmark) and "Favourites" (the heart). The DOM glue; the decisions are in
 * ./want-to-read-logic.js and ./favourite-logic.js.
 *
 * A button [data-want-to-read][data-novel] or [data-favourite][data-novel]
 * (aria-pressed = on the list) flips at once and saves through
 * PUT / DELETE /api/v1/novels/{id}/want-to-read (or /favourite) with the web
 * session; a failure puts it back and says why in a toast. Used by the
 * novel page's header buttons and the Want to read / Favourites pages' tiles
 * (a tile taken off stays, dimmed, so a second click puts it back).
 *
 * Bound per Turbo visit (turbo:load); the elements are new on every visit.
 */
import { api, messageOf, networkMessage } from './api';
import { wantRequest, wantAnnouncement, wantTitle } from './want-to-read-logic';
import { favouriteRequest, favouriteAnnouncement, favouriteLabel, favouriteTitle } from './favourite-logic';

const toast = (msg, type = 'info') => window.Novarr?.showToast?.(msg, type);

const TOGGLES = {
    wantToRead: {
        selector: '[data-want-to-read]',
        bound: 'wantBound',
        onClass: 'is-wanted',
        item: '[data-want-item]',
        keepTitle: 'data-want-keep-title',
        keepLabel: null,
        request: wantRequest,
        announcement: wantAnnouncement,
        title: wantTitle,
        label: null,
        failOn: 'Could not add it to Want to read',
        failOff: 'Could not remove it from Want to read',
    },
    favourite: {
        selector: '[data-favourite]',
        bound: 'favouriteBound',
        onClass: 'is-favourite',
        item: '[data-favourite-item]',
        keepTitle: 'data-favourite-keep-title',
        keepLabel: 'data-favourite-keep-label',
        request: favouriteRequest,
        announcement: favouriteAnnouncement,
        title: favouriteTitle,
        label: favouriteLabel,
        failOn: 'Could not add it to Favourites',
        failOff: 'Could not remove it from Favourites',
    },
};

function setPressed(kind, button, on) {
    button.setAttribute('aria-pressed', on ? 'true' : 'false');
    button.classList.toggle(kind.onClass, on);
    if (!button.hasAttribute(kind.keepTitle)) button.title = kind.title(on);
    if (kind.label && !button.hasAttribute(kind.keepLabel)) button.setAttribute('aria-label', kind.label(on));
    button.closest(kind.item)?.classList.toggle('is-removed', !on);
}

function announce(text) {
    const status = document.querySelector('[data-want-status]');
    if (!status) return;
    status.textContent = '';
    requestAnimationFrame(() => { status.textContent = text; });
}

function initButton(kind, button) {
    if (button.dataset[kind.bound]) return;
    button.dataset[kind.bound] = '1';

    button.addEventListener('click', async () => {
        if (button.dataset.busy) return;
        const on = button.getAttribute('aria-pressed') !== 'true';
        const request = kind.request(button.dataset.novel, on);
        if (!request) return;

        button.dataset.busy = '1';
        button.setAttribute('aria-busy', 'true');
        setPressed(kind, button, on);
        let failure = null;
        try {
            const r = await api(request.method, request.url);
            if (!r.ok) failure = messageOf(r, on ? kind.failOn : kind.failOff);
        } catch (err) {
            failure = networkMessage(err, 'Could not save');
        }
        delete button.dataset.busy;
        button.removeAttribute('aria-busy');

        if (failure) {
            setPressed(kind, button, !on);
            toast(failure, 'danger');
            return;
        }
        announce(kind.announcement(on, button.dataset.name));
    });
}

/** Binds both built-in lists' toggles on the page (per Turbo visit). */
export function initWantToRead() {
    for (const kind of Object.values(TOGGLES)) {
        document.querySelectorAll(kind.selector).forEach((button) => initButton(kind, button));
    }
}
