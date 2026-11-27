/**
 * Categories in the web UI — the DOM glue; the decisions are in
 * ./categories-logic.js. Saves through the JSON API with the web session
 * (./api.js).
 *
 *   openCategorySheet()  the "Set categories" sheet (a native <dialog>): a
 *                        checkbox row per category (hidden built-ins too),
 *                        tri-state for a mixed selection, "New category" at
 *                        the bottom. Used by the Library's Select mode (here)
 *                        and the Requests page's Browse cards (./requests.js).
 *   Library              [data-bulk-categories]: Set categories for the
 *                        selected series, one PUT /api/v1/lists/memberships.
 *   Home                 [data-home-first-category]: "Create your first
 *                        category" (POST /api/v1/lists, then reload).
 *
 * Bound per Turbo visit (turbo:load).
 */
import { api, messageOf, networkMessage } from './api';
import { triState, nextState, membershipDiff, appliedLine, ON, MIXED } from './categories-logic';
import { nameError } from './lists-logic';

const toast = (msg, type = 'info') => window.Novarr?.showToast?.(msg, type);

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
};

/** The reader's categories (GET /api/v1/lists), or null with a toast. */
export async function loadCategories() {
    try {
        const r = await api('GET', '/api/v1/lists');
        if (r.ok && Array.isArray(r.data.lists)) return r.data.lists;
        toast(messageOf(r, 'Your categories could not be loaded'), 'danger');
    } catch (err) {
        toast(networkMessage(err, 'Your categories could not be loaded'), 'danger');
    }
    return null;
}

/**
 * The sheet. rows: [{id, name, state}] (ON / OFF / MIXED). Resolves with
 * the rows as chosen (Apply), or null (Cancel / Esc). New categories made
 * from the sheet are created at once and come back ticked; `created` lists
 * them (so the caller can refresh its lists).
 */
export function openCategorySheet({ title = 'Set categories', subtitle = '', rows = [], applyText = 'Apply' } = {}) {
    return new Promise((resolve) => {
        const state = rows.map((r) => ({ ...r }));
        const created = [];
        const dlg = el('dialog', 'cat-sheet');
        dlg.setAttribute('aria-labelledby', 'catSheetTitle');
        const form = el('form', 'cat-sheet-form');
        form.method = 'dialog';
        const head = el('div', 'cat-sheet-head');
        head.append(el('h2', 'cat-sheet-title', title));
        head.lastChild.id = 'catSheetTitle';
        if (subtitle) head.append(el('p', 'cat-sheet-sub', subtitle));
        form.append(head);

        const list = el('ul', 'cat-sheet-list');
        const empty = el('p', 'cat-sheet-empty', 'No categories yet. Make one below.');
        function renderRows() {
            list.innerHTML = '';
            empty.hidden = state.length > 0;
            state.forEach((row) => {
                const li = el('li', 'cat-sheet-row');
                const label = el('label', 'cat-sheet-label');
                const box = el('input', 'form-check-input');
                box.type = 'checkbox';
                box.checked = row.state === ON;
                box.indeterminate = row.state === MIXED;
                box.setAttribute('aria-checked', row.state === MIXED ? 'mixed' : String(row.state === ON));
                box.addEventListener('change', () => {
                    row.state = nextState(row.state);
                    box.checked = row.state === ON;
                    box.indeterminate = false;
                    box.setAttribute('aria-checked', String(row.state === ON));
                });
                label.append(box, el('span', 'cat-sheet-name', row.name));
                if (row.kind) label.append(el('span', 'cat-sheet-kind', 'Built in'));
                li.append(label);
                list.append(li);
            });
        }
        renderRows();
        form.append(list, empty);

        // New category: made at once, then ticked.
        const add = el('div', 'cat-sheet-new');
        const input = el('input', 'form-control');
        input.type = 'text';
        input.maxLength = 40;
        input.placeholder = 'New category';
        input.setAttribute('aria-label', 'New category name');
        const addBtn = el('button', 'btn btn-secondary', 'Add');
        addBtn.type = 'button';
        const error = el('p', 'cat-sheet-error');
        error.setAttribute('role', 'alert');
        error.hidden = true;
        add.append(input, addBtn);
        form.append(add, error);

        async function createCategory() {
            const problem = nameError(input.value, state);
            if (problem) {
                error.textContent = problem.replace(/\blists?\b/g, (w) => (w === 'lists' ? 'categories' : 'category'));
                error.hidden = false;
                return;
            }
            addBtn.disabled = true;
            try {
                const r = await api('POST', '/api/v1/lists', { name: input.value });
                if (r.ok && r.data.list) {
                    state.push({ id: Number(r.data.list.id), name: r.data.list.name, kind: null, state: ON });
                    created.push(r.data.list);
                    input.value = '';
                    error.hidden = true;
                    renderRows();
                } else {
                    error.textContent = messageOf(r, 'The category was not made');
                    error.hidden = false;
                }
            } catch (err) {
                error.textContent = networkMessage(err, 'The category was not made');
                error.hidden = false;
            } finally {
                addBtn.disabled = false;
            }
        }
        addBtn.addEventListener('click', createCategory);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                createCategory();
            }
        });

        const foot = el('div', 'cat-sheet-foot');
        const cancel = el('button', 'btn btn-ghost', 'Cancel');
        cancel.type = 'button';
        cancel.value = 'cancel';
        const apply = el('button', 'btn btn-primary', applyText);
        apply.type = 'submit';
        apply.value = 'apply';
        foot.append(cancel, apply);
        form.append(foot);
        dlg.append(form);

        let result = null;
        cancel.addEventListener('click', () => dlg.close());
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            result = state.map((r) => ({ ...r }));
            dlg.close();
        });
        dlg.addEventListener('close', () => {
            dlg.remove();
            resolve(result === null ? null : Object.assign(result, { created }));
        }, { once: true });
        // A click on the backdrop (outside the form) cancels.
        dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });

        document.body.append(dlg);
        if (typeof dlg.showModal === 'function') dlg.showModal();
        else dlg.setAttribute('open', '');
        (list.querySelector('input') || input).focus();
    });
}

/** "Set categories" for these series: the sheet, then one memberships call. True when saved. */
export async function setCategoriesFor(novelIds, { title } = {}) {
    const lists = await loadCategories();
    if (!lists) return false;
    const initial = triState(lists, novelIds);
    const chosen = await openCategorySheet({
        title: title || `Set categories · ${novelIds.length} series`,
        rows: initial,
    });
    if (!chosen) return false;
    const diff = membershipDiff(initial, chosen);
    // Categories made in the sheet are new (not in initial): ticked → added.
    if (diff.add.length === 0 && diff.remove.length === 0) {
        toast(appliedLine(novelIds.length, diff));
        return false;
    }
    try {
        const r = await api('PUT', '/api/v1/lists/memberships', { novel_ids: novelIds.map(Number), add: diff.add, remove: diff.remove });
        if (r.ok) {
            toast(appliedLine(novelIds.length, diff), 'success');
            return true;
        }
        toast(messageOf(r, 'The categories were not saved'), 'danger');
    } catch (err) {
        toast(networkMessage(err, 'The categories were not saved'), 'danger');
    }
    return false;
}

function initLibrary() {
    const btn = document.querySelector('[data-bulk-categories]');
    if (!btn || btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', async () => {
        // The mobile list and the table both have a box per series: dedupe.
        const ids = [...new Set([...document.querySelectorAll('.novel-check:checked')].map((c) => Number(c.value)))];
        if (!ids.length) return;
        btn.disabled = true;
        try {
            if (await setCategoriesFor(ids)) {
                // The list filter's counts and a category view change: reload.
                if (window.Turbo) window.Turbo.visit(location.href, { action: 'replace' });
                else location.reload();
            }
        } finally {
            btn.disabled = false;
        }
    });
}

function initFirstCategory() {
    const form = document.querySelector('[data-home-first-category]');
    if (!form || form.dataset.bound === '1') return;
    form.dataset.bound = '1';
    const error = form.querySelector('.home-first-category-error');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = form.querySelector('input[name="name"]').value;
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            const r = await api('POST', '/api/v1/lists', { name });
            if (r.ok) {
                if (window.Turbo) window.Turbo.visit(location.href, { action: 'replace' });
                else location.reload();
                return;
            }
            error.textContent = messageOf(r, 'The category was not made');
            error.hidden = false;
        } catch (err) {
            error.textContent = networkMessage(err, 'The category was not made');
            error.hidden = false;
        } finally {
            button.disabled = false;
        }
    });
}

export function initCategories() {
    initLibrary();
    initFirstCategory();
}
