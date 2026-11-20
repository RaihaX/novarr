/**
 * Reading lists in the web UI — the DOM glue; the decisions are in
 * ./lists-logic.js. Everything saves through the JSON API with the web
 * session (./api.js):
 *
 *   A. novel page   [data-lists-menu]: the "Lists" menu. A tick saves at once
 *                   (PUT / DELETE /api/v1/lists/{id}/novels/{novel}) and shows
 *                   Saved or Failed on its row (a failed tick is undone);
 *                   "New list…" (or a one-tap starter when there are no lists
 *                   yet) creates the list (POST /api/v1/lists) and puts this
 *                   series in it.
 *   B. Library      [data-list-bar]: the chosen list's name, Rename (inline,
 *                   PATCH /api/v1/lists/{id}) and Delete list (confirmation
 *                   dialog, DELETE /api/v1/lists/{id}, then the Library
 *                   without the filter).
 *
 * Bound per Turbo visit (turbo:load); the elements are new on every visit.
 */
import { api, messageOf, networkMessage } from './api';
import { confirmDialog } from './confirm';
import {
    MAX_NAME, nameError, upsertList, startersFor, buttonLabel, toggleAnnouncement, urlWithoutList, cleanName,
} from './lists-logic';

const toast = (msg, type = 'info') => window.Novarr?.showToast?.(msg, type);

/** How long a row's "Saved" stays before it fades. */
const SAVED_MS = 1800;

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
};

/** One call, with network failures folded into {ok: false, message}. */
async function call(method, url, body, fallback) {
    try {
        const r = await api(method, url, body);
        return r.ok ? { ok: true, data: r.data } : { ok: false, status: r.status, message: messageOf(r, fallback) };
    } catch (err) {
        return { ok: false, status: 0, message: networkMessage(err, fallback) };
    }
}

// ---------------------------------------------------------------------------
// A. Novel page: the Lists menu
// ---------------------------------------------------------------------------

function initListMenu(root) {
    const novelId = Number(root.dataset.novel);
    const items = root.querySelector('[data-lists-items]');
    const startersBox = root.querySelector('[data-lists-starters]');
    const startersList = root.querySelector('[data-lists-starter-buttons]');
    const newBtn = root.querySelector('[data-lists-new]');
    const form = root.querySelector('[data-lists-new-form]');
    const input = form.querySelector('input');
    const error = root.querySelector('[data-lists-error]');
    const status = root.querySelector('[data-lists-status]');
    const toggle = root.querySelector('[data-lists-toggle]');
    const count = root.querySelector('[data-lists-count]');

    // State: [{id, name, in}], from the rendered rows.
    let lists = [...items.querySelectorAll('input[data-list-id]')].map((box) => ({
        id: Number(box.dataset.listId), name: box.dataset.listName, in: box.checked,
    }));

    const announce = (text) => { status.textContent = ''; requestAnimationFrame(() => { status.textContent = text; }); };
    const showError = (text) => { error.textContent = text || ''; error.hidden = !text; };

    function refreshButton() {
        const n = lists.filter((l) => l.in).length;
        count.textContent = n ? String(n) : '';
        count.hidden = n === 0;
        toggle.setAttribute('aria-label', buttonLabel(n));
        toggle.classList.toggle('is-listed', n > 0);
    }

    function renderStarters() {
        const offer = lists.length === 0 ? startersFor(lists) : [];
        startersBox.hidden = offer.length === 0;
        startersList.replaceChildren(...offer.map((name) => {
            const li = el('li');
            const b = el('button', 'lists-starter', name);
            b.type = 'button';
            b.dataset.starter = name;
            li.append(b);
            return li;
        }));
    }

    /** The row for a list (a checkbox in a label, and its saved / failed state). */
    function rowFor(list) {
        const li = el('li', 'lists-item');
        const label = el('label', 'lists-check');
        const box = el('input', 'form-check-input');
        box.type = 'checkbox';
        box.checked = list.in;
        box.dataset.listId = String(list.id);
        box.dataset.listName = list.name;
        label.append(box, el('span', 'lists-name', list.name));
        li.append(label, el('span', 'lists-state'));
        li.lastChild.dataset.listState = '';
        li.lastChild.setAttribute('aria-hidden', 'true');
        return li;
    }

    function renderRows() {
        items.replaceChildren(...lists.map(rowFor));
        items.hidden = lists.length === 0;
    }

    function setRowState(box, state) {
        const li = box.closest('.lists-item');
        const badge = li?.querySelector('[data-list-state]');
        if (!badge) return;
        clearTimeout(Number(badge.dataset.timer || 0));
        badge.className = 'lists-state' + (state ? ` is-${state}` : '');
        badge.textContent = state === 'saving' ? 'Saving…' : state === 'saved' ? 'Saved' : state === 'failed' ? 'Failed' : '';
        if (state === 'saved') {
            badge.dataset.timer = String(setTimeout(() => { badge.className = 'lists-state'; badge.textContent = ''; }, SAVED_MS));
        }
    }

    async function setMembership(box, on) {
        const id = Number(box.dataset.listId);
        const list = lists.find((l) => l.id === id);
        if (!list) return;
        showError('');
        // Busy, not disabled: a disabled box would drop keyboard focus to <body>.
        box.dataset.saving = '1';
        box.setAttribute('aria-busy', 'true');
        setRowState(box, 'saving');
        const r = await call(on ? 'PUT' : 'DELETE', `/api/v1/lists/${id}/novels/${novelId}`, undefined, 'Could not save');
        delete box.dataset.saving;
        box.removeAttribute('aria-busy');
        if (!box.isConnected) return;
        if (r.ok) {
            list.in = on;
            setRowState(box, 'saved');
            announce(toggleAnnouncement(list.name, on, true));
        } else {
            box.checked = !on; // undo the tick: the server did not change
            setRowState(box, 'failed');
            showError(r.message);
            announce(`${toggleAnnouncement(list.name, on, false)} ${r.message}`);
        }
        refreshButton();
    }

    /** Create a list and put this series in it; focus its row. */
    async function createAndAdd(rawName, { fromForm }) {
        const message = nameError(rawName, lists);
        if (message) {
            showError(message);
            if (fromForm) input.focus();
            return false;
        }
        showError('');
        const name = cleanName(rawName);
        const created = await call('POST', '/api/v1/lists', { name }, 'Could not create the list');
        if (!created.ok) {
            showError(created.message);
            announce(created.message);
            if (fromForm) input.focus();
            return false;
        }
        const list = { id: created.data.list.id, name: created.data.list.name, in: false };
        lists = upsertList(lists, list);
        renderRows();
        renderStarters();
        const box = items.querySelector(`input[data-list-id="${list.id}"]`);
        box.checked = true;
        box.focus();
        await setMembership(box, true);
        return true;
    }

    function openForm() {
        form.hidden = false;
        newBtn.setAttribute('aria-expanded', 'true');
        input.value = '';
        input.focus();
    }

    function closeForm({ focus }) {
        form.hidden = true;
        newBtn.setAttribute('aria-expanded', 'false');
        showError('');
        if (focus) newBtn.focus();
    }

    items.addEventListener('change', (e) => {
        const box = e.target;
        if (!(box instanceof HTMLInputElement) || !box.dataset.listId) return;
        // A second tick while the first is saving is ignored (and undone).
        if (box.dataset.saving) { box.checked = !box.checked; return; }
        setMembership(box, box.checked);
    });
    startersList.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-starter]');
        if (!b) return;
        b.disabled = true;
        if (!(await createAndAdd(b.dataset.starter, { fromForm: false }))) b.disabled = false;
    });
    newBtn.addEventListener('click', () => (form.hidden ? openForm() : closeForm({ focus: true })));
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        const ok = await createAndAdd(input.value, { fromForm: true });
        submit.disabled = false;
        if (ok) closeForm({ focus: false });
    });
    input.addEventListener('keydown', (e) => {
        // Escape closes the little form, not the whole menu.
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            closeForm({ focus: true });
        }
    });
    input.maxLength = MAX_NAME;

    renderStarters();
    refreshButton();
}

// ---------------------------------------------------------------------------
// B. Library: the chosen list's bar (rename, delete)
// ---------------------------------------------------------------------------

function initListBar(bar) {
    const id = Number(bar.dataset.listId);
    const title = bar.querySelector('[data-list-title]');
    const renameBtn = bar.querySelector('[data-list-rename]');
    const deleteBtn = bar.querySelector('[data-list-delete]');
    const form = bar.querySelector('[data-list-rename-form]');
    const input = form.querySelector('input');
    const cancel = form.querySelector('[data-list-rename-cancel]');
    const error = bar.querySelector('[data-list-error]');
    const status = bar.querySelector('[data-list-status]');
    // The other lists' names (duplicate check), from the filter's options.
    const select = document.querySelector('select[name="list"]');
    const others = () => [...(select?.options || [])]
        .filter((o) => o.value && Number(o.value) !== id)
        .map((o) => ({ id: Number(o.value), name: o.dataset.name || o.textContent }));

    const showError = (text) => { error.textContent = text || ''; error.hidden = !text; };

    function openForm() {
        form.hidden = false;
        renameBtn.setAttribute('aria-expanded', 'true');
        input.value = title.textContent.trim();
        input.focus();
        input.select();
    }

    function closeForm() {
        form.hidden = true;
        renameBtn.setAttribute('aria-expanded', 'false');
        showError('');
        renameBtn.focus();
    }

    renameBtn.addEventListener('click', () => (form.hidden ? openForm() : closeForm()));
    cancel.addEventListener('click', closeForm);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { e.preventDefault(); closeForm(); }
    });
    input.maxLength = MAX_NAME;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const message = nameError(input.value, others());
        if (message) { showError(message); input.focus(); return; }
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        const r = await call('PATCH', `/api/v1/lists/${id}`, { name: cleanName(input.value) }, 'Could not rename the list');
        submit.disabled = false;
        if (!r.ok) { showError(r.message); input.focus(); return; }
        const name = r.data.list.name;
        title.textContent = name;
        bar.dataset.listName = name;
        const option = select?.querySelector(`option[value="${id}"]`);
        if (option) {
            option.dataset.name = name;
            option.textContent = `List: ${name} (${option.dataset.count})`;
        }
        status.textContent = `Renamed to ${name}.`;
        closeForm();
    });

    deleteBtn.addEventListener('click', async () => {
        const name = title.textContent.trim();
        const ok = await confirmDialog(`Delete the list “${name}”? The series in it stay in your library.`, {
            title: 'Delete list', confirmText: 'Delete list', cancelText: 'Keep it', danger: true,
        });
        if (!ok) { deleteBtn.focus(); return; }
        deleteBtn.disabled = true;
        const r = await call('DELETE', `/api/v1/lists/${id}`, undefined, 'Could not delete the list');
        if (!r.ok && r.status !== 404) {
            deleteBtn.disabled = false;
            showError(r.message);
            deleteBtn.focus();
            return;
        }
        toast(`Deleted the list “${name}”.`, 'success');
        const next = urlWithoutList(window.location.href);
        if (window.Turbo) window.Turbo.visit(next, { action: 'replace' });
        else window.location.assign(next);
    });
}

export function initLists() {
    document.querySelectorAll('[data-lists-menu]').forEach(initListMenu);
    document.querySelectorAll('[data-list-bar]').forEach(initListBar);
}
