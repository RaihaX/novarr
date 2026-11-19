/**
 * Reader requests page (resources/views/requests/index.blade.php): the DOM
 * glue. Every list and action goes through the JSON API with the web
 * session (App\Http\Middleware\IdentifyReader signs the call in, as for
 * /api/v1/novels/{id}/check); the decisions are in ./requests-logic.js.
 *
 *   A. search      POST /api/v1/requests/searches, then GET …/{id} every 1.5 s
 *                  request a result: POST /api/v1/requests {search_id, key, note}
 *                  free text:        POST /api/v1/requests {title, medium, link, note}
 *   B. mine        GET /api/v1/requests?scope=mine&page=; cancel: DELETE …/{id}
 *   C. review      (only when the list answers can_decide) GET ?scope=all&status=pending;
 *                  POST …/{id}/approve | decline | rerank; decided: GET ?scope=all
 *
 * Errors show the API's `message` verbatim. Bound per Turbo visit; every
 * listener, timer and fetch is dropped when the page is left.
 */
import { Modal } from 'bootstrap';
import {
    POLL_MS, resultAction, sourceLine, skippedLine, searchingLine, statusLabel,
    pendingUsedLine, orderCandidates, defaultCandidate, selectable, candidateDetail,
    rankingInProgress, retryAfterSeconds, relativeTime, oldestFirst, decidedOnly,
} from './requests-logic';

/** Ranking re-checks: every RANK_POLL_MS, for at most RANK_POLL_MAX tries. */
const RANK_POLL_MS = 5000;
const RANK_POLL_MAX = 120;

/** Pages of pending requests loaded for the review list (50 each). */
const REVIEW_PAGES = 4;

const MEDIUM_LABEL = { novel: 'Novel', manga: 'Manga' };

/** JSON API call with the session cookie: {ok, status, data, headers}. Throws only on network errors / abort. */
async function api(method, url, body, signal) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    };
    const init = { method, headers, credentials: 'same-origin', signal };
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }
    const res = await fetch(url, init);
    const data = res.status === 204 ? {} : await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data, headers: res.headers };
}

/** The API's message, or a fallback naming the status. */
function messageOf(r, fallback) {
    return (r && r.data && typeof r.data.message === 'string' && r.data.message) || `${fallback} (${r ? r.status : 'network'}).`;
}

function networkMessage(err, fallback) {
    if (navigator.onLine === false) return 'You are offline. Try again when you have a connection.';
    return err && err.message ? `${fallback}: ${err.message}` : fallback;
}

const sleep = (ms, signal) => new Promise((resolve, reject) => {
    const t = setTimeout(resolve, ms);
    signal?.addEventListener('abort', () => { clearTimeout(t); reject(new DOMException('Aborted', 'AbortError')); }, { once: true });
});

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
};

const toast = (msg, type = 'info') => window.Novarr?.showToast(msg, type);

/** Title steps by length, as the cover component (≤18 / ≤44 / longer). */
const titleStep = (name) => name.length <= 18 ? 'l' : (name.length <= 44 ? 'm' : 's');

/** Fill a cloned <x-cover> with a title, author and (optional) image. */
function fillCover(cover, { title, author, coverUrl }) {
    const t = cover.querySelector('.cover-title');
    if (t) {
        t.textContent = title.length > 90 ? `${title.slice(0, 88)}…` : title;
        t.className = `cover-title cover-title-${titleStep(title)}`;
    }
    const a = cover.querySelector('.cover-author');
    if (a) a.textContent = author || '';
    cover.querySelector('.cover-count')?.remove();
    if (!coverUrl) return;
    const img = document.createElement('img');
    // The title is printed next to every cover: the image is decorative.
    img.alt = '';
    img.loading = 'lazy';
    img.decoding = 'async';
    img.addEventListener('error', () => { img.remove(); cover.classList.remove('has-image'); });
    img.src = coverUrl;
    cover.classList.add('has-image');
    cover.insertBefore(img, cover.querySelector('.cover-type')?.nextSibling || null);
}

/** Clamp a synopsis to three lines with a More / Less toggle when it overflows. */
function clampSynopsis(synopsis, text, after) {
    if (!text) { synopsis.remove(); return; }
    synopsis.textContent = text;
    requestAnimationFrame(() => {
        if (!synopsis.isConnected || synopsis.scrollHeight <= synopsis.clientHeight + 1) return;
        const toggle = el('button', 'disc-more', 'More');
        toggle.type = 'button';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.addEventListener('click', () => {
            const expanded = synopsis.classList.toggle('is-expanded');
            toggle.textContent = expanded ? 'Less' : 'More';
            toggle.setAttribute('aria-expanded', String(expanded));
        });
        (after || synopsis).after(toggle);
    });
}

/** A 0 / 500 counter under a textarea. */
function bindCounter(textarea, counter, signal) {
    const update = () => { counter.textContent = `${textarea.value.length} / ${textarea.maxLength > 0 ? textarea.maxLength : 500}`; };
    textarea.addEventListener('input', update, { signal });
    update();
    return update;
}

export function initRequestsPage() {
    const root = document.querySelector('[data-requests-page]');
    if (!root || root.dataset.bound === '1') return;
    root.dataset.bound = '1';

    const ac = new AbortController();
    const { signal } = ac;
    const teardown = () => {
        ac.abort();
        // A modal left open would leave its backdrop over the next page.
        root.querySelectorAll('.modal.show').forEach((m) => Modal.getInstance(m)?.hide());
        document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    };
    document.addEventListener('turbo:before-render', teardown, { once: true });
    document.addEventListener('turbo:before-cache', teardown, { once: true });

    let sourceCounts = {};
    try { sourceCounts = JSON.parse(root.dataset.sourceCounts || '{}') || {}; } catch (_) { sourceCounts = {}; }

    const $ = (id) => document.getElementById(id);
    const resultTpl = $('reqResultTpl');
    const rowTpl = $('reqRowTpl');

    // ---- Dialogs ---------------------------------------------------------------
    // Bootstrap modals: focus trapped inside, Esc / backdrop close, focus back
    // to the opener (as the confirm dialog).
    function dialog(id) {
        const node = $(id);
        const modal = Modal.getOrCreateInstance(node);
        const form = node.querySelector('form');
        const error = node.querySelector('.req-dialog-error');
        const submit = form.querySelector('[type="submit"]');
        let opener = null;
        node.addEventListener('hidden.bs.modal', () => {
            if (opener && opener.isConnected) opener.focus({ preventScroll: true });
        }, { signal });
        return {
            node, form, submit,
            open(focusSel) {
                opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                this.error('');
                this.busy(false);
                node.addEventListener('shown.bs.modal', () => node.querySelector(focusSel)?.focus(), { once: true, signal });
                modal.show();
            },
            close() { modal.hide(); },
            error(msg, link) {
                error.textContent = msg || '';
                if (msg && link) {
                    const a = el('a', 'alert-link ms-1', link.label);
                    a.href = link.href;
                    error.append(' ', a);
                }
                error.hidden = !msg;
            },
            busy(on) {
                submit.disabled = on;
                submit.setAttribute('aria-busy', on ? 'true' : 'false');
            },
            setOpener(node2) { opener = node2; },
        };
    }

    const noteDlg = dialog('reqNoteDialog');
    const freeDlg = dialog('reqFreeDialog');
    const declineDlg = dialog('reqDeclineDialog');
    const noteCount = bindCounter($('reqNoteText'), $('reqNoteCount'), signal);
    const freeCount = bindCounter($('reqFreeNote'), $('reqFreeCount'), signal);
    const declineCount = bindCounter($('reqDeclineReason'), $('reqDeclineCount'), signal);

    // ============================================================================
    // A. Search
    // ============================================================================
    const form = $('reqSearchForm');
    const queryEl = $('reqQuery');
    const searchBtn = $('reqSearchBtn');
    const statusEl = $('reqSearchStatus');
    const errorEl = $('reqSearchError');
    const resultsEl = $('reqResults');
    const skippedEl = $('reqSkipped');
    const mediumBtns = [...root.querySelectorAll('#reqMedium [data-medium]')];

    let medium = 'novel';
    let searching = false;
    let retryUntil = 0;
    let retryTimer = null;
    let lastSearch = null;   // {id, query, medium}
    const cards = new Map(); // result key → {card, result}

    mediumBtns.forEach((btn) => btn.addEventListener('click', () => {
        medium = btn.dataset.medium;
        mediumBtns.forEach((b) => {
            const on = b === btn;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }, { signal }));

    function showSearchError(msg) {
        errorEl.textContent = msg || '';
        errorEl.hidden = !msg;
    }

    function setStatus(html, text) {
        if (html) {
            statusEl.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
            statusEl.append(el('span', null, text));
        } else {
            statusEl.textContent = text || '';
        }
    }

    function syncSearchButton() {
        const waiting = Date.now() < retryUntil;
        searchBtn.disabled = searching || waiting;
        searchBtn.setAttribute('aria-busy', searching ? 'true' : 'false');
        searchBtn.textContent = searching ? 'Searching…' : 'Search';
    }

    function holdFor(seconds) {
        retryUntil = Date.now() + seconds * 1000;
        clearTimeout(retryTimer);
        retryTimer = setTimeout(() => { retryUntil = 0; syncSearchButton(); }, seconds * 1000);
        signal.addEventListener('abort', () => clearTimeout(retryTimer), { once: true });
        syncSearchButton();
    }

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        runSearch();
    }, { signal });

    async function runSearch() {
        const q = queryEl.value.trim().replace(/\s+/g, ' ');
        if (searching || Date.now() < retryUntil) return;
        if (q === '') {
            showSearchError('Type what to search for.');
            queryEl.focus();
            return;
        }
        showSearchError('');
        skippedEl.hidden = true;
        resultsEl.innerHTML = '';
        resultsEl.setAttribute('aria-busy', 'true');
        cards.clear();
        searching = true;
        syncSearchButton();
        const started = Date.now();
        const count = sourceCounts[medium];
        setStatus(true, searchingLine(count, 0));
        // The line changes after SLOW_AFTER_MS without waiting for a poll.
        const ticker = setInterval(() => setStatus(true, searchingLine(count, Date.now() - started)), 1000);
        const searchMedium = medium;

        try {
            const r = await api('POST', '/api/v1/requests/searches', { q, medium: searchMedium }, signal);
            if (r.status === 429) {
                setStatus(false, '');
                showSearchError(messageOf(r, 'Search refused'));
                holdFor(retryAfterSeconds(r.headers.get('Retry-After')));
                return;
            }
            if (!r.ok) {
                setStatus(false, '');
                showSearchError(messageOf(r, 'The search could not start'));
                return;
            }
            const id = r.data.search && r.data.search.id;
            let search = r.data.search || {};
            while (search.status === 'running') {
                await sleep(POLL_MS, signal);
                const p = await api('GET', `/api/v1/requests/searches/${encodeURIComponent(id)}`, undefined, signal);
                if (!p.ok) {
                    setStatus(false, '');
                    showSearchError(messageOf(p, 'The search was lost'));
                    return;
                }
                search = p.data.search || {};
            }
            setStatus(false, '');
            if (search.status !== 'done') {
                showSearchError(search.message || 'The search failed. Try again later.');
                return;
            }
            lastSearch = { id, query: search.query || q, medium: search.medium || searchMedium };
            renderResults(search);
        } catch (err) {
            if (signal.aborted) return;
            setStatus(false, '');
            showSearchError(networkMessage(err, 'The search failed'));
        } finally {
            clearInterval(ticker);
            if (!signal.aborted) {
                searching = false;
                resultsEl.setAttribute('aria-busy', 'false');
                syncSearchButton();
            }
        }
    }

    function renderResults(search) {
        const results = Array.isArray(search.results) ? search.results : [];
        resultsEl.innerHTML = '';
        const skipped = skippedLine(search.skipped_sources);
        skippedEl.textContent = skipped;
        skippedEl.hidden = skipped === '';

        if (!results.length) {
            setStatus(false, '');
            const li = el('li', 'novels-empty');
            li.append(el('span', 'novels-empty-title', 'Nothing matched on any source.'));
            li.append(el('p', 'novels-empty-body', 'Check the spelling, try the author’s name, or ask the owner by title.'));
            const actions = el('div', 'novels-empty-actions');
            const anyway = el('button', 'btn btn-secondary', 'Request it anyway');
            anyway.type = 'button';
            anyway.addEventListener('click', () => openFree({ title: search.query || '', medium: search.medium || medium }), { signal });
            actions.append(anyway);
            li.append(actions);
            resultsEl.append(li);
            return;
        }
        setStatus(false, `${results.length} result${results.length === 1 ? '' : 's'}`);
        results.forEach(renderResult);
    }

    function renderResult(result) {
        const card = resultTpl.content.firstElementChild.cloneNode(true);
        fillCover(card.querySelector('.cover'), { title: result.title, author: result.author, coverUrl: result.cover_url });
        const title = card.querySelector('.req-card-title');
        title.textContent = result.title;
        title.title = result.title;
        card.querySelector('.disc-author').textContent = result.author || 'Unknown author';
        card.querySelector('.req-sources').textContent = sourceLine(result.sources);
        clampSynopsis(card.querySelector('.disc-synopsis'), result.description, null);
        resultsEl.append(card);
        cards.set(result.key, { card, result });
        renderAction(result.key);
    }

    function renderAction(key) {
        const entry = cards.get(key);
        if (!entry) return;
        const { card, result } = entry;
        const slot = card.querySelector('.req-card-action');
        slot.innerHTML = '';
        const action = resultAction(result);
        if (action.kind === 'open') {
            const a = el('a', 'btn btn-sm btn-secondary req-action', action.label);
            a.href = action.href;
            a.setAttribute('aria-label', `${result.title} is in the library — open it`);
            slot.append(a);
            return;
        }
        const btn = el('button', `btn btn-sm ${action.kind === 'request' ? 'btn-primary' : 'btn-secondary'} req-action`, action.label);
        btn.type = 'button';
        if (action.disabled) {
            // aria-disabled, not disabled: it stays focusable, so focus can
            // land on it after the request dialog closes.
            btn.setAttribute('aria-disabled', 'true');
            btn.classList.add('is-inert');
        }
        if (action.kind === 'request') {
            btn.setAttribute('aria-label', `Request ${result.title}`);
            btn.addEventListener('click', () => openNote(key, btn), { signal });
        }
        slot.append(btn);
    }

    // ---- Request a result (note dialog) ----------------------------------------
    let noteKey = null;
    function openNote(key, opener) {
        const entry = cards.get(key);
        if (!entry) return;
        noteKey = key;
        $('reqNoteSubject').textContent = `${entry.result.title}${entry.result.author ? ` — ${entry.result.author}` : ''}`;
        $('reqNoteText').value = '';
        noteCount();
        noteDlg.open('#reqNoteText');
        noteDlg.setOpener(opener);
    }

    noteDlg.form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const entry = cards.get(noteKey);
        if (!entry || !lastSearch) return;
        noteDlg.busy(true);
        noteDlg.error('');
        const note = $('reqNoteText').value.trim();
        try {
            const r = await api('POST', '/api/v1/requests', { search_id: lastSearch.id, key: noteKey, ...(note ? { note } : {}) }, signal);
            if (r.ok) {
                entry.result.requested = { status: 'pending', by_me: true };
                renderAction(noteKey);
                noteDlg.setOpener(entry.card.querySelector('.req-action'));
                noteDlg.close();
                toast(`Requested “${entry.result.title}”. You’ll see here when the owner decides.`, 'success');
                refreshAfterChange();
                return;
            }
            const code = r.data && r.data.code;
            if (code === 'in_library') {
                entry.result.in_library = true;
                entry.result.novel_id = r.data.novel_id || entry.result.novel_id;
                renderAction(noteKey);
                noteDlg.setOpener(entry.card.querySelector('.req-action'));
                noteDlg.close();
                toast(messageOf(r, 'Already in the library'), 'warning');
                return;
            }
            if (code === 'already_requested') {
                entry.result.requested = { status: r.data.status || 'pending', by_me: Boolean(r.data.by_me) };
                renderAction(noteKey);
                noteDlg.setOpener(entry.card.querySelector('.req-action'));
                noteDlg.close();
                toast(messageOf(r, 'Already requested'), 'warning');
                return;
            }
            noteDlg.error(messageOf(r, 'The request was not made'));
        } catch (err) {
            if (!signal.aborted) noteDlg.error(networkMessage(err, 'The request was not made'));
        } finally {
            if (!signal.aborted) noteDlg.busy(false);
        }
    }, { signal });

    // ---- Request by title (free text) ------------------------------------------
    function openFree({ title = '', medium: m = medium } = {}) {
        $('reqFreeName').value = title;
        $('reqFreeLink').value = '';
        $('reqFreeNote').value = '';
        freeCount();
        freeDlg.form.querySelectorAll('input[name="medium"]').forEach((r) => { r.checked = r.value === m; });
        freeDlg.open(title ? '#reqFreeLink' : '#reqFreeName');
    }

    root.querySelectorAll('[data-req-freetext]').forEach((b) => b.addEventListener('click', () => {
        openFree({ title: queryEl.value.trim(), medium });
    }, { signal }));

    freeDlg.form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const title = $('reqFreeName').value.trim();
        const link = $('reqFreeLink').value.trim();
        const note = $('reqFreeNote').value.trim();
        const m = freeDlg.form.querySelector('input[name="medium"]:checked')?.value || 'novel';
        if (title === '') {
            freeDlg.error('Give the title of the series.');
            $('reqFreeName').focus();
            return;
        }
        freeDlg.busy(true);
        freeDlg.error('');
        try {
            const body = { title, medium: m, ...(link ? { link } : {}), ...(note ? { note } : {}) };
            const r = await api('POST', '/api/v1/requests', body, signal);
            if (r.ok) {
                freeDlg.close();
                toast(`Requested “${title}”. You’ll see here when the owner decides.`, 'success');
                refreshAfterChange();
                return;
            }
            const link2 = r.data && r.data.code === 'in_library' && r.data.novel_id
                ? { label: 'Open it', href: `/novels/${r.data.novel_id}` } : null;
            freeDlg.error(messageOf(r, 'The request was not made'), link2);
        } catch (err) {
            if (!signal.aborted) freeDlg.error(networkMessage(err, 'The request was not made'));
        } finally {
            if (!signal.aborted) freeDlg.busy(false);
        }
    }, { signal });

    // ============================================================================
    // Request rows (shared by B and C)
    // ============================================================================
    function baseRow(req, { showWho = false } = {}) {
        const row = rowTpl.content.firstElementChild.cloneNode(true);
        row.dataset.requestId = String(req.id);
        fillCover(row.querySelector('.cover'), { title: req.title, author: req.author, coverUrl: req.cover_url });

        const who = row.querySelector('.req-row-who');
        if (showWho) {
            const name = (req.requested_by && req.requested_by.name) || 'Someone';
            const when = relativeTime(req.created_at);
            who.textContent = `${name} asked${when ? ` ${when}` : ''}`;
            who.title = req.created_at ? new Date(req.created_at).toLocaleString() : '';
        } else {
            who.remove();
        }

        row.querySelector('.req-row-title').textContent = req.title;
        row.querySelector('.req-medium').textContent = MEDIUM_LABEL[req.medium] || req.medium;
        const status = statusLabel(req);
        const badge = row.querySelector('.req-status-badge');
        badge.textContent = status.label;
        badge.classList.add(`badge-${status.tone}`);

        const meta = row.querySelector('.req-row-meta');
        const parts = [];
        if (req.author) parts.push(req.author);
        if (!showWho && req.created_at) parts.push(`Requested ${relativeTime(req.created_at)}`);
        if (req.status !== 'pending' && req.decided_at) parts.push(`decided ${relativeTime(req.decided_at)}`);
        meta.textContent = parts.join(' · ');
        if (!parts.length) meta.remove();

        const detail = row.querySelector('.req-row-detail');
        if (status.detail) detail.textContent = `Reason: ${status.detail}`;
        else detail.remove();

        const note = row.querySelector('.req-row-note');
        if (req.note) {
            note.append(el('span', 'req-row-label', showWho ? 'Note: ' : 'Your note: '), document.createTextNode(req.note));
        } else {
            note.remove();
        }
        return row;
    }

    // ============================================================================
    // B. My requests
    // ============================================================================
    const mineEl = $('reqMine');
    const mineStatus = $('reqMineStatus');
    const pendingUsedEl = $('reqPendingUsed');
    const mineMoreWrap = $('reqMineMoreWrap');
    const mineMore = $('reqMineMore');
    let mine = [];
    let minePage = 1;
    let pendingLimit = null;
    let canDecide = false;

    async function loadMine(page = 1) {
        if (page === 1) mineStatus.textContent = mine.length ? '' : 'Loading…';
        mineMore.disabled = true;
        try {
            const r = await api('GET', `/api/v1/requests?scope=mine&page=${page}`, undefined, signal);
            if (!r.ok) {
                mineStatus.textContent = '';
                showListError(mineEl, messageOf(r, 'Your requests could not be loaded'));
                return;
            }
            minePage = page;
            mine = page === 1 ? r.data.requests : mine.concat(r.data.requests);
            pendingLimit = r.data.pending_limit ?? null;
            mineStatus.textContent = '';
            mineMoreWrap.hidden = !r.data.has_more;
            renderMine();
            if (r.data.can_decide && !canDecide) {
                canDecide = true;
                showReview();
            }
        } catch (err) {
            if (signal.aborted) return;
            mineStatus.textContent = '';
            showListError(mineEl, networkMessage(err, 'Your requests could not be loaded'));
        } finally {
            if (!signal.aborted) mineMore.disabled = false;
        }
    }
    mineMore.addEventListener('click', () => loadMine(minePage + 1), { signal });

    function showListError(list, msg) {
        list.innerHTML = '';
        const li = el('li', 'alert alert-danger req-list-error', msg);
        li.setAttribute('role', 'alert');
        list.append(li);
    }

    function emptyState(list, title, body) {
        const li = el('li', 'novels-empty req-empty');
        li.append(el('span', 'novels-empty-title', title));
        if (body) li.append(el('p', 'novels-empty-body', body));
        list.append(li);
    }

    function renderMine() {
        mineEl.innerHTML = '';
        pendingUsedEl.textContent = pendingUsedLine(mine, pendingLimit);
        if (!mine.length) {
            emptyState(mineEl, 'No requests yet.', 'Search above for something to read, or request it by title.');
            return;
        }
        mine.forEach((req) => mineEl.append(mineRow(req)));
    }

    function mineRow(req) {
        const row = baseRow(req);
        row.querySelector('.req-row-synopsis').remove();
        row.querySelector('.req-row-link').remove();
        row.querySelector('.req-row-decide').remove();
        const actions = row.querySelector('.req-row-actions');
        if (req.status === 'available' && req.novel_id) {
            const open = el('a', 'btn btn-sm btn-primary', 'Open');
            open.href = `/novels/${req.novel_id}`;
            open.setAttribute('aria-label', `Open ${req.title}`);
            actions.append(open);
        }
        if (req.status === 'pending' && req.can_cancel) {
            const cancel = el('button', 'btn btn-sm btn-ghost', 'Cancel');
            cancel.type = 'button';
            cancel.setAttribute('aria-label', `Cancel your request for ${req.title}`);
            cancel.addEventListener('click', () => cancelRequest(req, cancel), { signal });
            actions.append(cancel);
        }
        if (!actions.children.length) actions.remove();
        return row;
    }

    async function cancelRequest(req, btn) {
        const ok = await window.Novarr.confirmDialog(`Cancel your request for “${req.title}”? The owner will no longer see it.`, {
            title: 'Cancel request', confirmText: 'Cancel request', cancelText: 'Keep it', danger: true,
        });
        if (!ok || signal.aborted) return;
        btn.disabled = true;
        try {
            const r = await api('DELETE', `/api/v1/requests/${req.id}`, undefined, signal);
            if (!r.ok) {
                toast(messageOf(r, 'The request was not cancelled'), 'danger');
                btn.disabled = false;
                if (r.status === 409 || r.status === 404) loadMine(1);
                return;
            }
            mine = mine.filter((x) => x.id !== req.id);
            renderMine();
            // A result card of the same work can be requested again.
            for (const [key, entry] of cards) {
                if (entry.result.requested && entry.result.requested.by_me
                    && entry.result.medium === req.medium && entry.result.title.toLowerCase() === req.title.toLowerCase()) {
                    entry.result.requested = null;
                    renderAction(key);
                }
            }
            toast(`Cancelled your request for “${req.title}”.`, 'success');
            if (canDecide) loadReview();
            mineEl.querySelector('button, a')?.focus({ preventScroll: true });
        } catch (err) {
            if (signal.aborted) return;
            btn.disabled = false;
            toast(networkMessage(err, 'The request was not cancelled'), 'danger');
        }
    }

    function refreshAfterChange() {
        loadMine(1);
        if (canDecide) loadReview();
    }

    // ============================================================================
    // C. Requests to review (admin at home)
    // ============================================================================
    const reviewSec = $('reqReview');
    const reviewEl = $('reqReviewList');
    const reviewStatus = $('reqReviewStatus');
    const reviewCount = $('reqReviewCount');
    const reviewMoreWrap = $('reqReviewMoreWrap');
    const reviewMore = $('reqReviewMore');
    const tabBtns = [...root.querySelectorAll('#reqReviewTabs [data-review-tab]')];
    let tab = 'pending';
    let pending = [];
    let decided = [];
    let decidedPage = 1;
    const rankWatch = new Map(); // request id → timer

    function showReview() {
        reviewSec.hidden = false;
        loadReview();
    }

    tabBtns.forEach((btn) => btn.addEventListener('click', () => {
        tab = btn.dataset.reviewTab;
        tabBtns.forEach((b) => {
            const on = b === btn;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        loadReview();
    }, { signal }));

    function setCount(n) {
        reviewCount.textContent = n > 0 ? `(${n})` : '';
        // The nav badges (layout, tab bar's More sheet).
        document.querySelectorAll('[data-requests-badge]').forEach((b) => {
            if (b.classList.contains('nav-count')) {
                b.hidden = n <= 0;
                b.innerHTML = '';
                b.append(el('span', 'visually-hidden', ', '), document.createTextNode(String(n)), el('span', 'visually-hidden', ' pending'));
            } else {
                b.textContent = n > 0 ? `${n} pending` : '';
            }
        });
    }

    async function loadReview() {
        rankWatch.forEach((t) => clearTimeout(t));
        rankWatch.clear();
        reviewStatus.textContent = 'Loading…';
        reviewMoreWrap.hidden = true;
        try {
            if (tab === 'pending') {
                let all = [];
                for (let page = 1; page <= REVIEW_PAGES; page++) {
                    const r = await api('GET', `/api/v1/requests?scope=all&status=pending&page=${page}`, undefined, signal);
                    if (!r.ok) {
                        reviewStatus.textContent = '';
                        showListError(reviewEl, messageOf(r, 'Requests could not be loaded'));
                        return;
                    }
                    all = all.concat(r.data.requests);
                    if (!r.data.has_more) break;
                }
                if (tab !== 'pending') return;
                pending = oldestFirst(all);
                setCount(pending.length);
                reviewStatus.textContent = '';
                renderPending();
            } else {
                decidedPage = 1;
                decided = [];
                await loadDecided(1);
            }
        } catch (err) {
            if (signal.aborted) return;
            reviewStatus.textContent = '';
            showListError(reviewEl, networkMessage(err, 'Requests could not be loaded'));
        }
    }

    async function loadDecided(page) {
        reviewMore.disabled = true;
        const r = await api('GET', `/api/v1/requests?scope=all&page=${page}`, undefined, signal);
        reviewMore.disabled = false;
        if (tab !== 'decided') return;
        if (!r.ok) {
            reviewStatus.textContent = '';
            showListError(reviewEl, messageOf(r, 'Requests could not be loaded'));
            return;
        }
        decidedPage = page;
        decided = decided.concat(decidedOnly(r.data.requests));
        reviewStatus.textContent = '';
        reviewMoreWrap.hidden = !r.data.has_more;
        reviewEl.innerHTML = '';
        if (!decided.length) {
            emptyState(reviewEl, 'Nothing decided yet.', null);
            return;
        }
        decided.forEach((req) => {
            const row = baseRow(req, { showWho: true });
            row.querySelector('.req-row-synopsis').remove();
            row.querySelector('.req-row-decide').remove();
            renderLink(row, req);
            const actions = row.querySelector('.req-row-actions');
            if (req.novel_id) {
                const open = el('a', 'btn btn-sm btn-secondary', 'Open series');
                open.href = `/novels/${req.novel_id}`;
                open.setAttribute('aria-label', `Open ${req.title}`);
                actions.append(open);
            } else {
                actions.remove();
            }
            reviewEl.append(row);
        });
    }
    reviewMore.addEventListener('click', () => {
        loadDecided(decidedPage + 1).catch((err) => { if (!signal.aborted) toast(networkMessage(err, 'Requests could not be loaded'), 'danger'); });
    }, { signal });

    function renderPending() {
        reviewEl.innerHTML = '';
        if (!pending.length) {
            emptyState(reviewEl, 'Nothing to review.', 'New requests from readers appear here.');
            return;
        }
        pending.forEach((req) => reviewEl.append(reviewRow(req)));
    }

    /** The reader's link: plain text, and an explicit "Open link" (new tab, no referrer). Never fetched. */
    function renderLink(row, req) {
        const p = row.querySelector('.req-row-link');
        if (!req.link) { p.remove(); return; }
        p.append(el('span', 'req-row-label', 'Link: '), el('span', 'req-link-text', req.link), ' ');
        let safe = false;
        try { safe = ['http:', 'https:'].includes(new URL(req.link).protocol); } catch (_) { safe = false; }
        if (safe) {
            const a = el('a', 'req-open-link', 'Open link');
            a.href = req.link;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.setAttribute('referrerpolicy', 'no-referrer');
            a.setAttribute('aria-label', `Open link for ${req.title} in a new tab`);
            p.append(a);
        }
    }

    function reviewRow(req) {
        const row = baseRow(req, { showWho: true });
        clampSynopsis(row.querySelector('.req-row-synopsis'), req.description, null);
        renderLink(row, req);
        const decide = row.querySelector('.req-row-decide');
        const actions = row.querySelector('.req-row-actions');
        const errorBox = el('div', 'alert alert-danger req-row-error', '');
        errorBox.setAttribute('role', 'alert');
        errorBox.hidden = true;

        let chosen = () => ({});
        const cands = Array.isArray(req.candidates) ? req.candidates : [];
        if (cands.length) {
            chosen = renderCandidates(decide, req);
        } else {
            chosen = renderFreeTextApprove(decide, req);
        }

        const approve = el('button', 'btn btn-sm btn-primary', 'Approve');
        approve.type = 'button';
        approve.setAttribute('aria-label', `Approve ${req.title}`);
        const decline = el('button', 'btn btn-sm btn-ghost', 'Decline');
        decline.type = 'button';
        decline.setAttribute('aria-label', `Decline ${req.title}`);
        approve.addEventListener('click', () => approveRequest(req, row, approve, chosen, errorBox, false), { signal });
        decline.addEventListener('click', () => openDecline(req, decline), { signal });
        actions.append(approve, decline);
        actions.after(errorBox);
        return row;
    }

    /** The ranked shortlist as radios; returns the approve body for the choice. */
    function renderCandidates(box, req) {
        const fs = el('fieldset', 'req-cands');
        const legend = el('legend', 'req-cands-legend', 'Add it from');
        fs.append(legend);
        const ranking = rankingInProgress(req);
        if (ranking) {
            const note = el('p', 'req-ranking');
            note.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
            note.append(el('span', null, 'Ranking in progress… the sources are being checked.'));
            note.setAttribute('role', 'status');
            fs.append(note);
            watchRanking(req);
        }
        const list = el('ul', 'req-cands-list');
        list.setAttribute('role', 'list');
        const ordered = orderCandidates(req.candidates);
        const preselect = defaultCandidate(req.candidates);
        const name = `cand-${req.id}`;
        ordered.forEach((c) => {
            const li = el('li', `req-cand${selectable(c) ? '' : ' is-disabled'}`);
            const label = el('label', 'req-cand-label');
            const input = document.createElement('input');
            input.type = 'radio';
            input.name = name;
            input.value = String(c.index);
            input.disabled = !selectable(c);
            input.checked = c.index === preselect;
            const text = el('span', 'req-cand-text');
            text.append(el('span', 'req-cand-name', c.source_name || c.source || 'Source'));
            if (c.reason) text.append(el('span', 'req-cand-reason', c.reason));
            // The server's reason line already carries the numbers; the
            // detail line only stands in when there is no reason.
            const detail = c.error || c.reason ? '' : candidateDetail(c);
            if (detail) text.append(el('span', 'req-cand-detail', detail));
            label.append(input, text);
            li.append(label);
            list.append(li);
        });
        fs.append(list);
        if (req.medium === 'manga') {
            const rerank = el('button', 'disc-more req-rerank', 'Refresh ranking');
            rerank.type = 'button';
            rerank.disabled = ranking;
            rerank.addEventListener('click', () => rerankRequest(req, rerank), { signal });
            fs.append(rerank);
        }
        box.append(fs);
        return () => {
            const picked = fs.querySelector(`input[name="${name}"]:checked`);
            if (!picked) return { error: 'Choose a source to add it from.' };
            return { candidate: Number(picked.value) };
        };
    }

    /**
     * A free-text request: paste the series page to add (url, optional title),
     * or link a series already in the library (looked up by name through the
     * palette's /palette?q= novel search).
     */
    function renderFreeTextApprove(box, req) {
        const fs = el('fieldset', 'req-cands req-free-approve');
        fs.append(el('legend', 'req-cands-legend', 'Add it from'));
        const name = `how-${req.id}`;
        const modeRow = el('div', 'req-radio-row');
        const mkRadio = (value, text, checked) => {
            const l = el('label', 'req-radio');
            const i = document.createElement('input');
            i.type = 'radio'; i.name = name; i.value = value; i.checked = checked;
            l.append(i, ` ${text}`);
            modeRow.append(l);
            return i;
        };
        const urlMode = mkRadio('url', 'A series page', true);
        mkRadio('library', 'It’s already in the library', false);
        fs.append(modeRow);

        const urlBox = el('div', 'req-free-fields');
        const urlId = `reqUrl${req.id}`;
        const urlLabel = el('label', 'form-label', 'Series page URL');
        urlLabel.htmlFor = urlId;
        const url = document.createElement('input');
        url.type = 'url'; url.id = urlId; url.className = 'form-control'; url.inputMode = 'url';
        url.placeholder = 'Paste the series page to add'; url.maxLength = 2048;
        const titleId = `reqUrlTitle${req.id}`;
        const titleLabel = el('label', 'form-label', 'Title (optional)');
        titleLabel.htmlFor = titleId;
        const title = document.createElement('input');
        title.type = 'text'; title.id = titleId; title.className = 'form-control'; title.maxLength = 255;
        title.placeholder = req.title;
        urlBox.append(urlLabel, url, titleLabel, title);

        const libBox = el('div', 'req-free-fields');
        libBox.hidden = true;
        const findId = `reqFind${req.id}`;
        const findLabel = el('label', 'form-label', 'Series in the library');
        findLabel.htmlFor = findId;
        const find = document.createElement('input');
        find.type = 'search'; find.id = findId; find.className = 'form-control'; find.autocomplete = 'off';
        find.placeholder = 'Type a name…'; find.value = req.title;
        const matches = el('ul', 'req-cands-list req-lib-matches');
        matches.setAttribute('role', 'list');
        matches.setAttribute('aria-live', 'polite');
        libBox.append(findLabel, find, matches);
        fs.append(urlBox, libBox);
        box.append(fs);

        let timer = null;
        let looked = '';
        const lookup = async () => {
            const q = find.value.trim();
            if (q === looked) return;
            looked = q;
            matches.innerHTML = '';
            if (q.length < 2) return;
            try {
                const r = await api('GET', `/palette?q=${encodeURIComponent(q)}`, undefined, signal);
                if (find.value.trim() !== q) return;
                const novels = (r.ok && Array.isArray(r.data.novels)) ? r.data.novels : [];
                matches.innerHTML = '';
                if (!novels.length) {
                    matches.append(el('li', 'req-muted-line', 'No series in the library matches.'));
                    return;
                }
                novels.forEach((n, i) => {
                    const li = el('li', 'req-cand');
                    const label = el('label', 'req-cand-label');
                    const input = document.createElement('input');
                    input.type = 'radio'; input.name = `lib-${req.id}`; input.value = String(n.id); input.checked = i === 0;
                    const text = el('span', 'req-cand-text');
                    text.append(el('span', 'req-cand-name', n.name));
                    if (n.author) text.append(el('span', 'req-cand-detail', n.author));
                    label.append(input, text);
                    li.append(label);
                    matches.append(li);
                });
            } catch (_) { /* aborted or offline: the list stays empty */ }
        };
        find.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(lookup, 250); }, { signal });
        modeRow.addEventListener('change', () => {
            const lib = !urlMode.checked;
            urlBox.hidden = lib;
            libBox.hidden = !lib;
            if (lib) lookup();
        }, { signal });

        return () => {
            if (urlMode.checked) {
                const u = url.value.trim();
                if (!u) return { error: 'Paste the series page to add it from.', focus: url };
                return { url: u, ...(title.value.trim() ? { title: title.value.trim() } : {}) };
            }
            const picked = matches.querySelector('input:checked');
            if (!picked) return { error: 'Choose the series in the library.', focus: find };
            return { novel_id: Number(picked.value) };
        };
    }

    async function approveRequest(req, row, btn, chosen, errorBox, force) {
        const choice = chosen();
        errorBox.hidden = true;
        errorBox.innerHTML = '';
        if (choice.error) {
            errorBox.textContent = choice.error;
            errorBox.hidden = false;
            choice.focus?.focus();
            return;
        }
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        try {
            const r = await api('POST', `/api/v1/requests/${req.id}/approve`, { ...choice, ...(force ? { force: true } : {}) }, signal);
            if (r.ok) {
                toast(r.data.created
                    ? `Approved “${req.title}”: added to the library, chapters are downloading.`
                    : `Approved “${req.title}”: linked to the series in the library.`, 'success');
                removePending(req.id, row);
                if ((req.requested_by && mine.some((m) => m.id === req.id))) loadMine(1);
                return;
            }
            const code = r.data && r.data.code;
            if (code === 'duplicate') {
                // Same title from another source: say which, offer to add it anyway.
                errorBox.className = 'alert alert-warning req-row-error';
                errorBox.textContent = messageOf(r, 'Already in the library');
                const dup = r.data.duplicate;
                const wrap = el('div', 'req-row-error-actions');
                const anyway = el('button', 'btn btn-sm btn-secondary', 'Add anyway');
                anyway.type = 'button';
                anyway.addEventListener('click', () => approveRequest(req, row, btn, chosen, errorBox, true), { signal });
                wrap.append(anyway);
                if (dup && dup.id) {
                    const open = el('a', 'btn btn-sm btn-ghost', 'Open existing');
                    open.href = `/novels/${dup.id}`;
                    wrap.append(open);
                }
                errorBox.append(wrap);
                errorBox.hidden = false;
                anyway.focus({ preventScroll: true });
                return;
            }
            errorBox.className = 'alert alert-danger req-row-error';
            errorBox.textContent = messageOf(r, 'The request was not approved');
            errorBox.hidden = false;
            if (code === 'not_pending' || r.status === 404) loadReview();
        } catch (err) {
            if (signal.aborted) return;
            errorBox.className = 'alert alert-danger req-row-error';
            errorBox.textContent = networkMessage(err, 'The request was not approved');
            errorBox.hidden = false;
        } finally {
            if (!signal.aborted) {
                btn.disabled = false;
                btn.setAttribute('aria-busy', 'false');
            }
        }
    }

    function removePending(id, row) {
        const next = row.nextElementSibling || row.previousElementSibling;
        pending = pending.filter((p) => p.id !== id);
        row.remove();
        setCount(pending.length);
        if (!pending.length) renderPending();
        (next?.querySelector('button, a, input') || reviewSec.querySelector('[data-review-tab="pending"]'))?.focus({ preventScroll: true });
    }

    // ---- Decline -----------------------------------------------------------------
    let declining = null;
    function openDecline(req, opener) {
        declining = req;
        $('reqDeclineSubject').textContent = `${req.title} — asked by ${(req.requested_by && req.requested_by.name) || 'a reader'}`;
        $('reqDeclineReason').value = '';
        declineCount();
        declineDlg.open('#reqDeclineReason');
        declineDlg.setOpener(opener);
    }

    declineDlg.form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const req = declining;
        if (!req) return;
        declineDlg.busy(true);
        declineDlg.error('');
        const reason = $('reqDeclineReason').value.trim();
        try {
            const r = await api('POST', `/api/v1/requests/${req.id}/decline`, reason ? { reason } : {}, signal);
            if (!r.ok) {
                declineDlg.error(messageOf(r, 'The request was not declined'));
                if (r.data && r.data.code === 'not_pending') loadReview();
                return;
            }
            declineDlg.setOpener(null);
            declineDlg.close();
            toast(`Declined “${req.title}”.`, 'success');
            const row = reviewEl.querySelector(`[data-request-id="${req.id}"]`);
            if (row) removePending(req.id, row);
            if (mine.some((m) => m.id === req.id)) loadMine(1);
        } catch (err) {
            if (!signal.aborted) declineDlg.error(networkMessage(err, 'The request was not declined'));
        } finally {
            if (!signal.aborted) declineDlg.busy(false);
        }
    }, { signal });

    // ---- Ranking -----------------------------------------------------------------
    async function rerankRequest(req, btn) {
        btn.disabled = true;
        try {
            const r = await api('POST', `/api/v1/requests/${req.id}/rerank`, {}, signal);
            if (!r.ok) {
                toast(messageOf(r, 'The ranking was not refreshed'), 'danger');
                btn.disabled = false;
                return;
            }
            toast('Ranking the sources again. This can take a few minutes.', 'info');
            const fresh = r.data.request || req;
            watchRanking(fresh, req.candidates_ranked_at || '');
            const row = btn.closest('.req-row');
            const note = el('p', 'req-ranking');
            note.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
            note.append(el('span', null, 'Ranking in progress… the sources are being checked.'));
            note.setAttribute('role', 'status');
            row?.querySelector('.req-cands-legend')?.after(note);
        } catch (err) {
            if (signal.aborted) return;
            btn.disabled = false;
            toast(networkMessage(err, 'The ranking was not refreshed'), 'danger');
        }
    }

    /**
     * Re-read a request until its ranking finishes (candidates_ranked_at set,
     * or changed from `before`), then redraw its row in place.
     */
    function watchRanking(req, before = null) {
        if (rankWatch.has(req.id)) return;
        let tries = 0;
        const tick = async () => {
            tries++;
            try {
                const r = await api('GET', `/api/v1/requests/${req.id}`, undefined, signal);
                if (!r.ok) { rankWatch.delete(req.id); return; }
                const fresh = r.data.request;
                const done = fresh.candidates_ranked_at && fresh.candidates_ranked_at !== before;
                if (done || fresh.status !== 'pending') {
                    rankWatch.delete(req.id);
                    const i = pending.findIndex((p) => p.id === req.id);
                    const row = reviewEl.querySelector(`[data-request-id="${req.id}"]`);
                    if (fresh.status !== 'pending') {
                        if (row) removePending(req.id, row);
                        return;
                    }
                    if (i >= 0) pending[i] = fresh;
                    if (row && tab === 'pending') row.replaceWith(reviewRow(fresh));
                    return;
                }
            } catch (_) {
                if (signal.aborted) return;
            }
            if (tries >= RANK_POLL_MAX) { rankWatch.delete(req.id); return; }
            rankWatch.set(req.id, setTimeout(tick, RANK_POLL_MS));
        };
        rankWatch.set(req.id, setTimeout(tick, RANK_POLL_MS));
    }
    signal.addEventListener('abort', () => rankWatch.forEach((t) => clearTimeout(t)), { once: true });

    // ---- Start ---------------------------------------------------------------------
    syncSearchButton();
    loadMine(1);
}
