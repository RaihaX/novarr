/**
 * "Search Usenet & torrents" widget — the DOM glue; the decisions are in
 * ./download-finder-logic.js. One Blade partial
 * (resources/views/downloads/_finder.blade.php) renders
 * [data-download-finder] with data-kind / data-request-id / data-novel-id:
 *
 *   - the search form posts POST /downloads/search and fills the results
 *     table (title, indexer + protocol badge, size, age or seeders, why);
 *   - "Grab" posts POST /downloads/grab {search_id, guid} and adds the grab
 *     to the list under it;
 *   - each grab in that list ([data-grab-id], also the ones the page was
 *     rendered with) is polled through GET /downloads/grabs/{id} until it
 *     is imported or failed: "Downloading 43%", "Finished — ready to
 *     review", "Failed: …".
 *
 * Bound per Turbo visit (turbo:load); timers stop when the page goes.
 */
import { api, messageOf, networkMessage } from './api';
import { searchBody, humanSize, ageOrSeeders, grabButton, autoNote, statusLine, isActive, pollDelay, eta } from './download-finder-logic';

const toast = (msg, type = 'info') => window.Novarr?.showToast?.(msg, type);
const timers = new Set();

function el(tag, attrs = {}, text) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') node.className = v;
        else node.setAttribute(k, v === true ? '' : String(v));
    }
    if (text !== undefined) node.textContent = text;
    return node;
}

function renderGrab(item, grab) {
    item.dataset.grabId = grab.id;
    item.dataset.status = grab.status;
    const line = item.querySelector('[data-grab-status]');
    if (line) {
        const extra = grab.status === 'downloading' ? eta(grab.eta_seconds) : '';
        line.textContent = statusLine(grab) + (extra ? ` · ${extra}` : '');
        line.classList.toggle('dl-status-failed', grab.status === 'failed');
        line.classList.toggle('dl-status-done', grab.status === 'imported');
    }
    const bar = item.querySelector('[data-grab-progress]');
    if (bar) {
        bar.value = Number(grab.progress) || 0;
        bar.hidden = grab.status !== 'downloading';
    }
    const next = item.querySelector('[data-grab-next]');
    if (next) {
        if (grab.next_url) { next.href = grab.next_url; next.hidden = false; } else next.hidden = true;
    }
}

function grabItem(grab) {
    const li = el('li', { class: 'dl-grab', 'data-grab-id': grab.id, 'data-status': grab.status });
    const body = el('div', { class: 'dl-grab-body' });
    body.append(el('span', { class: 'dl-grab-title' }, grab.title));
    body.append(el('span', { class: 'dl-grab-status', 'data-grab-status': true, role: 'status' }));
    li.append(body);
    const bar = el('progress', { class: 'dl-grab-bar', max: 100, 'data-grab-progress': true, 'aria-label': 'Download progress' });
    li.append(bar);
    li.append(el('a', { class: 'btn btn-sm btn-secondary', 'data-grab-next': true, hidden: true }, grab.kind === 'audiobook' ? 'Listen' : 'Open'));
    renderGrab(li, grab);
    return li;
}

function watch(item, attempt = 0) {
    if (!item.isConnected || !isActive(item.dataset.status)) return;
    const timer = setTimeout(async () => {
        timers.delete(timer);
        if (!item.isConnected) return;
        try {
            const r = await api('GET', `/downloads/grabs/${item.dataset.grabId}`);
            if (r.ok && r.data.grab) renderGrab(item, r.data.grab);
        } catch (err) { /* offline for a moment: ask again */ }
        watch(item, attempt + 1);
    }, pollDelay(attempt));
    timers.add(timer);
}

function renderResults(root, data) {
    const table = root.querySelector('[data-finder-results]');
    const tbody = table.querySelector('tbody');
    const note = root.querySelector('[data-finder-note]');
    tbody.replaceChildren();
    table.hidden = data.results.length === 0;
    note.textContent = data.results.length === 0
        ? `Nothing found for “${data.query}”. Try fewer words, or the author's surname.`
        : `${data.results.length} ${data.results.length === 1 ? 'result' : 'results'} for “${data.query}”, best first.`;

    for (const result of data.results) {
        const tr = el('tr', { class: result.blocklisted ? 'dl-blocked' : null });
        const titleCell = el('td', { class: 'dl-title' });
        if (result.info_url) {
            const a = el('a', { href: result.info_url, target: '_blank', rel: 'noopener noreferrer' }, result.title);
            titleCell.append(a);
        } else {
            titleCell.append(document.createTextNode(result.title));
        }
        const auto = autoNote(result);
        if (auto) titleCell.append(el('span', { class: `dl-auto ${result.auto === 'ok' ? 'is-ok' : ''}` }, auto));
        tr.append(titleCell);

        const where = el('td', { class: 'dl-where' });
        where.append(el('span', { class: `dl-badge dl-badge-${result.protocol}` }, result.protocol === 'torrent' ? 'Torrent' : 'Usenet'));
        where.append(document.createTextNode(` ${result.indexer || ''}`));
        tr.append(where);
        tr.append(el('td', { class: 'mono-num' }, result.size ? humanSize(result.size) : '—'));
        tr.append(el('td', {}, ageOrSeeders(result)));
        tr.append(el('td', { class: 'dl-why' }, result.why));

        const button = grabButton(result);
        const cell = el('td');
        const btn = el('button', { type: 'button', class: 'btn btn-sm btn-primary', disabled: button.disabled, title: button.title }, button.label);
        btn.addEventListener('click', () => grab(root, data.search_id, result, btn));
        cell.append(btn);
        tr.append(cell);
        tbody.append(tr);
    }
}

async function grab(root, searchId, result, btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    btn.textContent = 'Sending…';
    let r;
    try {
        r = await api('POST', '/downloads/grab', { search_id: searchId, guid: result.guid });
    } catch (err) {
        toast(networkMessage(err, 'Could not grab it'), 'danger');
        btn.disabled = false;
        btn.textContent = 'Grab';
        return;
    }
    if (!r.ok) {
        toast(messageOf(r, 'Could not grab it'), 'danger');
        btn.disabled = false;
        btn.textContent = 'Grab';
        return;
    }
    btn.textContent = 'Grabbed';
    const list = root.querySelector('[data-finder-grabs]');
    list.hidden = false;
    const item = grabItem(r.data.grab);
    list.prepend(item);
    root.querySelector('[data-finder-grabs-title]')?.removeAttribute('hidden');
    toast(`Sent to ${result.protocol === 'torrent' ? 'Transmission' : 'NZBGet'}: ${result.title}`, 'success');
    watch(item);
}

function initFinder(root) {
    if (root.dataset.finderBound) return;
    root.dataset.finderBound = '1';
    const form = root.querySelector('[data-finder-form]');
    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('[type=submit]');
        const note = root.querySelector('[data-finder-note]');
        button.disabled = true;
        note.textContent = 'Searching every indexer… (this can take half a minute)';
        try {
            const r = await api('POST', '/downloads/search', searchBody({
                kind: root.dataset.kind,
                q: form.querySelector('[name=q]')?.value,
                requestId: root.dataset.requestId,
                novelId: root.dataset.novelId,
            }));
            if (!r.ok) note.textContent = messageOf(r, 'The search failed');
            else renderResults(root, r.data);
        } catch (err) {
            note.textContent = networkMessage(err, 'The search failed');
        }
        button.disabled = false;
    });
    root.querySelectorAll('[data-grab-id]').forEach((item) => watch(item));
}

export function initDownloadFinders() {
    document.querySelectorAll('[data-download-finder]').forEach(initFinder);
    // The Downloads page's queue: the same live status.
    document.querySelectorAll('[data-grab-watch] [data-grab-id]').forEach((item) => watch(item));
}

export function teardownDownloadFinders() {
    for (const timer of timers) clearTimeout(timer);
    timers.clear();
}
