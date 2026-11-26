/**
 * Reader requests page (resources/views/requests/index.blade.php): the DOM
 * glue. Every list and action goes through the JSON API with the web
 * session (App\Http\Middleware\IdentifyReader signs the call in, as for
 * /api/v1/novels/{id}/check); the decisions are in ./requests-logic.js.
 *
 *   A. search      POST /api/v1/requests/searches, then GET …/{id} every 1.5 s
 *                  request a result: POST /api/v1/requests {search_id, key, note}
 *                  "Me too" (someone else asked): the same POST, no note
 *                  → 200 {joined: true}
 *                  free text:        POST /api/v1/requests {title, medium, link, note}
 *   A2. shelf      GET /api/v1/requests/browse?list=&page= (Discover manga)
 *                  request / "Me too": POST /api/v1/requests {list, page, key, note}
 *                  (the page the card came from); 410 browse_expired → reload the list
 *   B. mine        GET /api/v1/requests?scope=mine&page= (own and joined);
 *                  cancel / leave: DELETE …/{id}
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
    isFollower, mineAction, alsoWantedLine, requestedAfterLeaving,
    browseUrl, browseState, browseLoadingLine, shelfMetaLine, newResults, requestBody,
} from './requests-logic';

/** Ranking re-checks: every RANK_POLL_MS, for at most RANK_POLL_MAX tries. */
const RANK_POLL_MS = 5000;
const RANK_POLL_MAX = 120;

/** Pages of pending requests loaded for the review list (50 each). */
const REVIEW_PAGES = 4;

const MEDIUM_LABEL = { novel: 'Novel', manga: 'Manga', book: 'Book' };

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
    const shelfTpl = $('reqShelfTpl');
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

    // ---- Card registry -----------------------------------------------------------
    // A work can be in the search results and on the shelf (or on two shelf
    // lists) at once, under the same key: every card of a key is updated
    // together. An entry: {card, result, origin}; origin says what to post —
    // {type: 'search', searchId} or {type: 'shelf', list, page}.
    const cards = new Map(); // result key → Set of entries

    function register(entry) {
        if (!cards.has(entry.result.key)) cards.set(entry.result.key, new Set());
        cards.get(entry.result.key).add(entry);
        renderAction(entry);
    }

    function unregister(pred) {
        for (const [key, set] of cards) {
            for (const entry of set) if (pred(entry)) set.delete(entry);
            if (!set.size) cards.delete(key);
        }
    }

    /** Apply a change to every card of a key (the result objects differ per card) and redraw their actions. */
    function updateKey(key, patch) {
        for (const entry of cards.get(key) || []) {
            Object.assign(entry.result, patch);
            renderAction(entry);
        }
    }

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
        unregister((e) => e.origin.type === 'search');
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
            renderResults(search, id);
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

    function renderResults(search, searchId) {
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
        results.forEach((result) => renderResult(result, searchId));
    }

    function renderResult(result, searchId) {
        const card = resultTpl.content.firstElementChild.cloneNode(true);
        fillCover(card.querySelector('.cover'), { title: result.title, author: result.author, coverUrl: result.cover_url });
        const title = card.querySelector('.req-card-title');
        title.textContent = result.title;
        title.title = result.title;
        card.querySelector('.disc-author').textContent = result.author || 'Unknown author';
        card.querySelector('.req-sources').textContent = sourceLine(result.sources);
        clampSynopsis(card.querySelector('.disc-synopsis'), result.description, null);
        resultsEl.append(card);
        register({ card, result, origin: { type: 'search', searchId } });
    }

    function renderAction(entry) {
        const { card, result } = entry;
        const slot = card.querySelector('.req-card-action');
        slot.innerHTML = '';
        const action = resultAction(result);
        // A shelf card's cover does what its action does, for request / open.
        card.dataset.action = action.kind;
        if (action.kind === 'open') {
            const a = el('a', 'btn btn-sm btn-secondary req-action', action.label);
            a.href = action.href;
            a.setAttribute('aria-label', `${result.title} is in the library — open it`);
            slot.append(a);
            return;
        }
        const btn = el('button', `btn btn-sm ${action.kind === 'request' || action.kind === 'join' ? 'btn-primary' : 'btn-secondary'} req-action`, action.label);
        btn.type = 'button';
        if (action.disabled) {
            // aria-disabled, not disabled: it stays focusable, so focus can
            // land on it after the request dialog closes.
            btn.setAttribute('aria-disabled', 'true');
            btn.classList.add('is-inert');
        }
        if (action.kind === 'request') {
            btn.setAttribute('aria-label', `Request ${result.title}`);
            btn.addEventListener('click', () => openNote(entry, btn), { signal });
        }
        if (action.kind === 'join') {
            btn.setAttribute('aria-label', `Me too: join the request for ${result.title}`);
            btn.title = 'Someone already asked for this. Join their request to hear when it’s ready.';
            btn.addEventListener('click', () => joinResult(entry, btn), { signal });
        }
        slot.append(btn);
    }

    /**
     * A refusal of POST /api/v1/requests for a card: update every card of the
     * work, say why. A shelf list that changed (410) is reloaded. True when handled.
     */
    function applyRefusal(entry, r) {
        const code = r.data && r.data.code;
        const key = entry.result.key;
        if (code === 'in_library') {
            updateKey(key, { in_library: true, novel_id: r.data.novel_id || entry.result.novel_id });
        } else if (code === 'already_requested') {
            updateKey(key, { requested: { status: r.data.status || 'pending', by_me: Boolean(r.data.by_me) } });
        } else if (code === 'browse_expired' && entry.origin.type === 'shelf') {
            toast(messageOf(r, 'This list has changed. Reload it and try again'), 'warning');
            reloadShelfList(entry.origin.list);
            return true;
        } else {
            return false;
        }
        toast(messageOf(r, code === 'in_library' ? 'Already in the library' : 'Already requested'), 'warning');
        return true;
    }

    /** "Me too": join someone else's request for a result (no note: the owner already has the request). */
    async function joinResult(entry, btn) {
        if (btn.getAttribute('aria-busy') === 'true') return;
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        try {
            const r = await api('POST', '/api/v1/requests', requestBody(entry.origin, entry.result.key), signal);
            if (r.ok) {
                const status = (r.data.request && r.data.request.status) || (entry.result.requested && entry.result.requested.status) || 'pending';
                updateKey(entry.result.key, { requested: { status, by_me: true } });
                entry.card.querySelector('.req-action')?.focus({ preventScroll: true });
                toast(r.data.joined
                    ? `You joined the request for “${entry.result.title}”. You’ll hear when it’s ready.`
                    : `Requested “${entry.result.title}”. You’ll see here when the owner decides.`, 'success');
                refreshAfterChange();
                return;
            }
            if (applyRefusal(entry, r)) return;
            toast(messageOf(r, 'Could not join the request'), 'danger');
        } catch (err) {
            if (!signal.aborted) toast(networkMessage(err, 'Could not join the request'), 'danger');
        } finally {
            if (!signal.aborted && btn.isConnected) {
                btn.disabled = false;
                btn.setAttribute('aria-busy', 'false');
            }
        }
    }

    // ---- Request a result (note dialog) ----------------------------------------
    let noteEntry = null;
    const noteMeta = $('reqNoteMeta');
    const noteSynopsis = $('reqNoteSynopsis');
    function openNote(entry, opener) {
        noteEntry = entry;
        const { result } = entry;
        $('reqNoteSubject').textContent = `${result.title}${result.author ? ` — ${result.author}` : ''}`;
        // A search card shows its synopsis and sources itself; a shelf card
        // has no room for them, so the dialog does.
        const shelf = entry.origin.type === 'shelf';
        const meta = shelf ? [shelfMetaLine(result), sourceLine(result.sources)].filter(Boolean).join(' · ') : '';
        noteMeta.textContent = meta;
        noteMeta.hidden = meta === '';
        const synopsis = shelf ? String(result.description || '').trim() : '';
        noteSynopsis.textContent = synopsis;
        noteSynopsis.hidden = synopsis === '';
        noteSynopsis.scrollTop = 0;
        noteSynopsis.removeAttribute('tabindex');
        if (synopsis) {
            // A long synopsis scrolls: make it reachable from the keyboard then.
            noteDlg.node.addEventListener('shown.bs.modal', () => {
                if (noteSynopsis.scrollHeight > noteSynopsis.clientHeight + 1) noteSynopsis.tabIndex = 0;
            }, { once: true, signal });
        }
        $('reqNoteText').value = '';
        noteCount();
        noteDlg.open('#reqNoteText');
        noteDlg.setOpener(opener);
    }

    noteDlg.form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const entry = noteEntry;
        if (!entry) return;
        noteDlg.busy(true);
        noteDlg.error('');
        const note = $('reqNoteText').value.trim();
        try {
            const r = await api('POST', '/api/v1/requests', requestBody(entry.origin, entry.result.key, note), signal);
            if (r.ok) {
                updateKey(entry.result.key, { requested: { status: (r.data.request && r.data.request.status) || 'pending', by_me: true } });
                noteDlg.setOpener(entry.card.querySelector('.req-action'));
                noteDlg.close();
                toast(r.data.joined
                    ? `Someone had just asked for “${entry.result.title}”: you joined their request.`
                    : `Requested “${entry.result.title}”. You’ll see here when the owner decides.`, 'success');
                refreshAfterChange();
                return;
            }
            if (applyRefusal(entry, r)) {
                // A reloaded shelf list replaces the card: focus lands on the
                // list's first card when it is back (reloadShelfList).
                noteDlg.setOpener(entry.card.isConnected ? entry.card.querySelector('.req-action') : null);
                noteDlg.close();
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
                toast(r.data.joined
                    ? `Someone already asked for “${title}”: you joined their request.`
                    : `Requested “${title}”. You’ll see here when the owner decides.`, 'success');
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
    // A2. Discover manga (the shelf)
    // ============================================================================
    const shelfSec = $('reqDiscover');
    const shelfTabs = $('reqDiscoverTabs');
    const shelfStatus = $('reqDiscoverStatus');
    const shelfError = $('reqDiscoverError');
    const shelfErrorText = shelfError.querySelector('.req-discover-error-text');
    const shelfRetry = $('reqDiscoverRetry');
    const rail = $('reqRail');
    const railNav = $('reqRailNav');
    const railPrev = $('reqRailPrev');
    const railNext = $('reqRailNext');

    /** Cover silhouettes shown while a list loads (about a phone screen and a half). */
    const SKELETONS = 8;

    let shelfLists = [];      // [{id, name}] from the first answer
    let shelfList = null;     // the list on screen
    let shelfRetryFn = null;  // what Retry does: the load that failed
    let shelfRetryTimer = null;
    // Per list: {items: [li], entries: [entry], keys: Set, page, hasMore, loading: Promise|null}.
    // Kept for the visit, so switching back to a list is instant and its
    // cards keep their state (they stay in the registry while off screen).
    const shelf = new Map();

    const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    function shelfLoading(on, started = Date.now()) {
        clearInterval(shelfLoading.ticker);
        rail.setAttribute('aria-busy', on ? 'true' : 'false');
        if (!on) { shelfStatus.textContent = ''; return; }
        const paint = () => {
            shelfStatus.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
            shelfStatus.append(el('span', null, browseLoadingLine(Date.now() - started)));
        };
        paint();
        // The line only changes once (after BROWSE_SLOW_AFTER_MS): a slow
        // tick is enough, and repainting the same text is not re-announced.
        let last = browseLoadingLine(0);
        shelfLoading.ticker = setInterval(() => {
            const line = browseLoadingLine(Date.now() - started);
            if (line !== last) { last = line; paint(); }
        }, 500);
    }
    signal.addEventListener('abort', () => { clearInterval(shelfLoading.ticker); clearTimeout(shelfRetryTimer); }, { once: true });

    function showSkeletons() {
        const items = [];
        for (let i = 0; i < SKELETONS; i++) {
            const li = el('li', 'req-shelf-card disc-skeleton');
            li.setAttribute('aria-hidden', 'true');
            li.append(el('div', 'skeleton-cover'), el('div', 'skeleton-line'), el('div', 'skeleton-line short'));
            items.push(li);
        }
        rail.replaceChildren(...items);
        rail.scrollLeft = 0;
        syncArrows();
    }

    /** A failed load: the API's message and a Retry that runs the same load again (after Retry-After). */
    function shelfFail(r, err, retry) {
        shelfLoading(false);
        shelfErrorText.textContent = r ? messageOf(r, 'The list could not be loaded') : networkMessage(err, 'The list could not be loaded');
        shelfRetryFn = retry;
        clearTimeout(shelfRetryTimer);
        shelfRetry.disabled = false;
        if (r && (r.status === 429 || r.status === 503)) {
            shelfRetry.disabled = true;
            shelfRetryTimer = setTimeout(() => { shelfRetry.disabled = false; }, retryAfterSeconds(r.headers.get('Retry-After')) * 1000);
        }
        shelfError.hidden = false;
        shelfSec.hidden = false;
    }

    function clearShelfError() {
        shelfError.hidden = true;
        shelfRetryFn = null;
        clearTimeout(shelfRetryTimer);
    }

    shelfRetry.addEventListener('click', () => { const fn = shelfRetryFn; clearShelfError(); fn?.(); }, { signal });

    /** The feature is off or there is nothing to show: the section goes away. */
    function hideShelf() {
        shelfLoading(false);
        shelfSec.hidden = true;
    }

    /** One GET of the browse endpoint: {state, r}. Throws on network errors / abort. */
    async function fetchBrowse(list, page) {
        const r = await api('GET', browseUrl({ list, page }), undefined, signal);
        return { state: browseState(r.status, r.data), r };
    }

    function buildTabs() {
        shelfTabs.innerHTML = '';
        shelfTabs.hidden = shelfLists.length < 2;
        if (shelfLists.length < 2) return;
        for (const l of shelfLists) {
            const btn = el('button', 'btn btn-secondary', l.name || l.id);
            btn.type = 'button';
            btn.dataset.list = l.id;
            btn.addEventListener('click', () => showList(l.id), { signal });
            shelfTabs.append(btn);
        }
        syncTabs();
    }

    function syncTabs() {
        shelfTabs.querySelectorAll('[data-list]').forEach((b) => {
            const on = b.dataset.list === shelfList;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        const name = (shelfLists.find((l) => l.id === shelfList) || {}).name;
        rail.setAttribute('aria-label', name ? `${name} manga` : 'Manga to request');
    }

    /** Start the shelf: no parameters, the server picks the first list. */
    async function startShelf() {
        clearShelfError();
        // Skeletons straight away, unless the library has no manga source:
        // then the answer is all but sure to be "nothing to show", and the
        // section would flash in and out on every visit.
        if (sourceCounts.manga !== 0) {
            shelfSec.hidden = false;
            showSkeletons();
            shelfLoading(true);
        }
        try {
            const { state, r } = await fetchBrowse(null, null);
            if (state === 'hide') { hideShelf(); return; }
            if (state === 'error') { rail.replaceChildren(); syncArrows(); shelfFail(r, null, startShelf); return; }
            shelfLists = r.data.lists.filter((l) => l && l.id);
            shelfList = r.data.list || shelfLists[0].id;
            buildTabs();
            addPage(cacheFor(shelfList), r.data, shelfList);
            shelfSec.hidden = false;
            shelfLoading(false);
            renderList(shelfList);
        } catch (err) {
            if (signal.aborted) return;
            rail.replaceChildren();
            syncArrows();
            shelfFail(null, err, startShelf);
        }
    }

    function cacheFor(list) {
        if (!shelf.has(list)) shelf.set(list, { items: [], entries: [], keys: new Set(), page: 0, hasMore: false, loading: null, more: false });
        return shelf.get(list);
    }

    /** Add an answer's page to a list: new cards only (a title can move between pages). Returns the new cards. */
    function addPage(cache, data, list) {
        const page = Number(data.page) || cache.page + 1;
        const fresh = newResults(Array.isArray(data.results) ? data.results : [], cache.keys);
        const added = [];
        for (const result of fresh) {
            cache.keys.add(result.key);
            const entry = shelfCard(result, { type: 'shelf', list, page });
            cache.entries.push(entry);
            cache.items.push(entry.card);
            added.push(entry);
        }
        cache.page = Math.max(cache.page, page);
        cache.hasMore = Boolean(data.has_more);
        return added;
    }

    function shelfCard(result, origin) {
        const card = shelfTpl.content.firstElementChild.cloneNode(true);
        fillCover(card.querySelector('.cover'), { title: result.title, author: result.author, coverUrl: result.cover_url });
        const title = card.querySelector('.req-card-title');
        title.textContent = result.title;
        title.title = result.title;
        card.querySelector('.disc-author').textContent = result.author || 'Unknown author';
        card.querySelector('.req-shelf-meta').textContent = shelfMetaLine(result);
        // The cover is a second way to the action for Request / Open, not a
        // second tab stop: a click on it clicks the action.
        card.querySelector('.req-shelf-cover').addEventListener('click', () => {
            if (card.dataset.action === 'request' || card.dataset.action === 'open') card.querySelector('.req-action')?.click();
        }, { signal });
        const entry = { card, result, origin };
        register(entry);
        return entry;
    }

    /** The "More" tile at the end of the rail: a real button. */
    function moreTile(list) {
        const li = el('li', 'req-shelf-card req-shelf-more');
        const btn = el('button', 'req-more-tile', 'More');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Show more manga');
        btn.addEventListener('click', () => loadMore(list, btn), { signal });
        li.append(btn);
        return li;
    }

    function renderList(list) {
        const cache = shelf.get(list);
        if (!cache || list !== shelfList) return;
        const items = [...cache.items];
        if (cache.hasMore) items.push(moreTile(list));
        rail.replaceChildren(...items);
        rail.scrollLeft = 0;
        if (!cache.items.length && !cache.hasMore) shelfStatus.textContent = 'Nothing in this list right now.';
        syncArrows();
    }

    /** A list's page 1, from memory when it was loaded this visit. */
    async function showList(list) {
        const cache = shelf.get(list);
        // Already on screen, or on its way.
        if (list === shelfList && cache && (cache.loading || (cache.page > 0 && shelfError.hidden))) return;
        shelfList = list;
        syncTabs();
        clearShelfError();
        if (cache && cache.page > 0 && !cache.loading) {
            shelfLoading(false);
            renderList(list);
            return;
        }
        showSkeletons();
        shelfLoading(true);
        await loadFirst(list);
    }

    /**
     * Load a list's page 1 into memory, then draw it if the reader is still
     * on that list (an answer for a list they left is kept, not drawn).
     * One load per list at a time: switching back waits for the same one.
     */
    async function loadFirst(list) {
        const cache = cacheFor(list);
        if (!cache.loading) {
            cache.loading = (async () => {
                try {
                    const { state, r } = await fetchBrowse(list, 1);
                    if (state === 'ready') addPage(cache, r.data, list);
                    return { state, r };
                } catch (err) {
                    return { state: 'network', err };
                } finally {
                    cache.loading = null;
                }
            })();
        }
        const { state, r, err } = await cache.loading;
        if (signal.aborted || list !== shelfList) return;
        if (state === 'hide') { hideShelf(); return; }
        if (state === 'ready') { shelfLoading(false); renderList(list); return; }
        rail.replaceChildren();
        syncArrows();
        shelfFail(r || null, err, () => showList(list));
    }

    async function loadMore(list, btn) {
        const cache = shelf.get(list);
        if (!cache || cache.more || btn.getAttribute('aria-busy') === 'true') return;
        cache.more = true;
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        btn.textContent = 'Loading…';
        clearShelfError();
        const restore = () => {
            if (!btn.isConnected) return;
            btn.disabled = false;
            btn.setAttribute('aria-busy', 'false');
            btn.textContent = 'More';
        };
        try {
            const { state, r } = await fetchBrowse(list, cache.page + 1);
            if (signal.aborted) return;
            if (state === 'hide') { hideShelf(); return; }
            if (state === 'error') { restore(); shelfFail(r, null, () => btn.isConnected && loadMore(list, btn)); return; }
            const added = addPage(cache, r.data, list);
            if (list !== shelfList) return;
            // The reader left the list and came back meanwhile: the rail was
            // redrawn from memory without this tile; draw it again.
            if (!btn.isConnected) { renderList(list); return; }
            const tile = btn.closest('li');
            tile.before(...added.map((e) => e.card));
            if (cache.hasMore) {
                restore();
            } else {
                tile.remove();
            }
            syncArrows();
            // Keyboard and screen-reader users carry on from the first new card;
            // with nothing new, from the tile (or the last card when it went).
            const target = added[0]?.card.querySelector('.req-action')
                || (btn.isConnected ? btn : rail.querySelector('li:last-child .req-action'));
            target?.focus();
        } catch (err) {
            if (signal.aborted) return;
            restore();
            shelfFail(null, err, () => btn.isConnected && loadMore(list, btn));
        } finally {
            cache.more = false;
        }
    }

    /**
     * A list changed under the reader (410 browse_expired): forget what was
     * loaded and load page 1 again; on screen, focus its first card after.
     */
    async function reloadShelfList(list) {
        const cache = shelf.get(list);
        if (cache) {
            const gone = new Set(cache.entries);
            unregister((e) => gone.has(e));
            shelf.delete(list);
        }
        if (list !== shelfList) return;
        clearShelfError();
        showSkeletons();
        shelfLoading(true);
        await loadFirst(list);
        if (list === shelfList && !signal.aborted) rail.querySelector('.req-action')?.focus({ preventScroll: true });
    }

    // ---- The rail's arrows (mouse / trackpad; CSS hides them elsewhere) ----------
    function syncArrows() {
        const overflow = rail.scrollWidth > rail.clientWidth + 1;
        railNav.hidden = !overflow;
        railPrev.disabled = rail.scrollLeft <= 1;
        railNext.disabled = rail.scrollLeft + rail.clientWidth >= rail.scrollWidth - 1;
    }

    const scrollRail = (dir) => rail.scrollBy({ left: dir * rail.clientWidth * 0.9, behavior: reducedMotion() ? 'auto' : 'smooth' });
    railPrev.addEventListener('click', () => scrollRail(-1), { signal });
    railNext.addEventListener('click', () => scrollRail(1), { signal });

    let arrowFrame = 0;
    rail.addEventListener('scroll', () => {
        cancelAnimationFrame(arrowFrame);
        arrowFrame = requestAnimationFrame(syncArrows);
    }, { passive: true, signal });
    signal.addEventListener('abort', () => cancelAnimationFrame(arrowFrame), { once: true });

    if (typeof ResizeObserver === 'function') {
        const ro = new ResizeObserver(() => syncArrows());
        ro.observe(rail);
        signal.addEventListener('abort', () => ro.disconnect(), { once: true });
    } else {
        window.addEventListener('resize', syncArrows, { signal });
    }

    // Tabbing along the rail brings each card fully into view (the browser
    // alone may leave it half under the edge with snapping).
    rail.addEventListener('focusin', (e) => {
        const li = e.target.closest('li');
        if (li) li.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
    }, { signal });

    // ============================================================================
    // Request rows (shared by B and C)
    // ============================================================================
    /**
     * A request row. showWho: "Sam asked 3 days ago" (review lists). also:
     * the "Also wanted by …" line — 'names' (to review), 'count' (decided).
     */
    function baseRow(req, { showWho = false, also = null } = {}) {
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

        const alsoEl = row.querySelector('.req-row-also');
        const alsoText = also ? alsoWantedLine(req, { names: also === 'names' }) : '';
        if (alsoText) alsoEl.textContent = alsoText;
        else alsoEl.remove();

        const joined = row.querySelector('.req-row-joined');
        if (!showWho && isFollower(req)) joined.textContent = 'You joined this request';
        else joined.remove();

        const note = row.querySelector('.req-row-note');
        // A joined request's note is the requester's, not the reader's.
        if (req.note && (showWho || !isFollower(req))) {
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
        const action = mineAction(req);
        if (action === 'cancel') {
            const cancel = el('button', 'btn btn-sm btn-ghost', 'Cancel');
            cancel.type = 'button';
            cancel.setAttribute('aria-label', `Cancel your request for ${req.title}`);
            cancel.addEventListener('click', () => cancelRequest(req, cancel), { signal });
            actions.append(cancel);
        } else if (action === 'leave') {
            const leave = el('button', 'btn btn-sm btn-ghost', 'Leave');
            leave.type = 'button';
            leave.setAttribute('aria-label', `Leave the request for ${req.title}`);
            leave.addEventListener('click', () => cancelRequest(req, leave), { signal });
            actions.append(leave);
        }
        if (!actions.children.length) actions.remove();
        return row;
    }

    /** Cancel one's own request, or leave one joined (both DELETE …/{id}). */
    async function cancelRequest(req, btn) {
        const leaving = isFollower(req);
        const others = Number(req.also_wanted_by) || 0;
        let question;
        if (leaving) {
            question = `Leave the request for “${req.title}”? It stays for whoever asked for it; you won’t hear about it any more.`;
        } else if (others > 0) {
            question = `Cancel your request for “${req.title}”? ${others === 1 ? 'Another reader wants' : `${others} other readers want`} it too, so the request stays for them.`;
        } else {
            question = `Cancel your request for “${req.title}”? The owner will no longer see it.`;
        }
        const ok = await window.Novarr.confirmDialog(question, leaving
            ? { title: 'Leave request', confirmText: 'Leave', cancelText: 'Stay', danger: true }
            : { title: 'Cancel request', confirmText: 'Cancel request', cancelText: 'Keep it', danger: true });
        if (!ok || signal.aborted) return;
        btn.disabled = true;
        const failed = leaving ? 'You did not leave the request' : 'The request was not cancelled';
        try {
            const r = await api('DELETE', `/api/v1/requests/${req.id}`, undefined, signal);
            if (!r.ok) {
                toast(messageOf(r, failed), 'danger');
                btn.disabled = false;
                if (r.status === 409 || r.status === 404) loadMine(1);
                return;
            }
            mine = mine.filter((x) => x.id !== req.id);
            renderMine();
            // Every card of the same work (search results and the shelf):
            // requestable again, or "Me too" when others still want it.
            for (const set of cards.values()) {
                for (const entry of set) {
                    if (entry.result.requested && entry.result.requested.by_me
                        && entry.result.medium === req.medium && String(entry.result.title).toLowerCase() === String(req.title).toLowerCase()) {
                        entry.result.requested = requestedAfterLeaving(req);
                        renderAction(entry);
                    }
                }
            }
            toast(leaving ? `You left the request for “${req.title}”.` : `Cancelled your request for “${req.title}”.`, 'success');
            if (canDecide) loadReview();
            mineEl.querySelector('button, a')?.focus({ preventScroll: true });
        } catch (err) {
            if (signal.aborted) return;
            btn.disabled = false;
            toast(networkMessage(err, failed), 'danger');
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
            const row = baseRow(req, { showWho: true, also: 'count' });
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
        const row = baseRow(req, { showWho: true, also: 'names' });
        clampSynopsis(row.querySelector('.req-row-synopsis'), req.description, null);
        renderLink(row, req);
        const decide = row.querySelector('.req-row-decide');
        const actions = row.querySelector('.req-row-actions');
        const errorBox = el('div', 'alert alert-danger req-row-error', '');
        errorBox.setAttribute('role', 'alert');
        errorBox.hidden = true;

        // An uploaded book: matched and added on its own review page.
        if (req.review_url) {
            // A book asked for by title has no file yet: the owner finds and uploads it.
            const reviewLabel = req.wanted ? 'Upload the ePub' : 'Review and match';
            const review = el('a', 'btn btn-sm btn-primary', reviewLabel);
            review.href = req.review_url;
            review.setAttribute('aria-label', `${reviewLabel}: ${req.title}`);
            const decline = el('button', 'btn btn-sm btn-ghost', 'Decline');
            decline.type = 'button';
            decline.setAttribute('aria-label', `Decline ${req.title}`);
            decline.addEventListener('click', () => openDecline(req, decline), { signal });
            actions.append(review, decline);
            actions.after(errorBox);
            return row;
        }

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
    startShelf();
}
