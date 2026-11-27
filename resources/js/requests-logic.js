/**
 * Reader requests page (resources/views/requests/index.blade.php) — the pure
 * decisions, no DOM: `yarn test:js` (tests/js/requests.test.js). The DOM glue
 * is resources/js/requests.js.
 *
 * Shapes are the JSON API's (App\Http\Controllers\Api\V1\RequestPresenter):
 * a search result {title, sources, in_library, novel_id, requested}, a
 * request {status, decline_reason, novel_id, candidates, also_wanted_by,
 * mine, followers, …}, an admin-view candidate {index, source_name, reason,
 * chapters_available, highest_chapter, gaps, updated_at, error}.
 */

/** How long before the searching line says sources are slow. */
export const SLOW_AFTER_MS = 10_000;

/** Poll interval of a running search. */
export const POLL_MS = 1500;

/** Retry-After when the header is missing or unreadable. */
export const DEFAULT_RETRY_SECONDS = 5;

/** Longest wait honoured from a Retry-After (a rate limit can say minutes). */
export const MAX_RETRY_SECONDS = 15 * 60;

/** Statuses of someone else's request a reader can still join ("Me too"). */
export const JOINABLE_STATUSES = ['pending', 'approved'];

/**
 * The one action a search result card offers.
 *   request          "Request" (opens the note dialog)
 *   open             "In library — Open" (links to the series)
 *   requested_by_me  "Requested by you" (disabled; asked or joined)
 *   join             "Me too" (someone else asked; joins their request)
 *   requested        "Requested" (disabled; someone else asked, too late to join)
 */
export function resultAction(result) {
    if (result && result.in_library && result.novel_id) {
        return { kind: 'open', label: 'In library — Open', href: `/novels/${result.novel_id}`, disabled: false };
    }
    if (result && result.in_library) {
        return { kind: 'requested', label: 'In library', href: null, disabled: true };
    }
    const requested = result && result.requested;
    if (requested) {
        if (requested.by_me) return { kind: 'requested_by_me', label: 'Requested by you', href: null, disabled: true };
        if (JOINABLE_STATUSES.includes(requested.status)) return { kind: 'join', label: 'Me too', href: null, disabled: false };
        return { kind: 'requested', label: 'Requested', href: null, disabled: true };
    }
    return { kind: 'request', label: 'Request', href: null, disabled: false };
}

/** Did the caller only join this request (someone else made it)? */
export function isFollower(request) {
    return Boolean(request) && request.mine === 'follower';
}

/**
 * The action a row of My requests offers besides Open: 'leave' for a
 * request the reader joined, 'cancel' for their own pending one, null.
 */
export function mineAction(request) {
    if (!request || !request.can_cancel) return null;
    if (isFollower(request)) return 'leave';
    return request.status === 'pending' ? 'cancel' : null;
}

/**
 * "Also wanted by Ana, Ben" (admin review: names) or "Also wanted by 2
 * readers" (names: false, or no names given); '' when nobody joined.
 */
export function alsoWantedLine(request, { names = true } = {}) {
    if (!request) return '';
    const list = Array.isArray(request.followers) ? request.followers : [];
    const count = Math.max(Number(request.also_wanted_by) || 0, list.length);
    if (count <= 0) return '';
    const shown = names ? list.map((f) => String((f && f.name) || '').trim()).filter(Boolean) : [];
    if (shown.length) {
        const more = count - shown.length;
        return `Also wanted by ${shown.join(', ')}${more > 0 ? ` +${more}` : ''}`;
    }
    return `Also wanted by ${count} reader${count === 1 ? '' : 's'}`;
}

/**
 * The search result's `requested` after the reader leaves / cancels a
 * request of the same work: still requested by someone else when others
 * want it (a follower left, or the request went to the next follower).
 */
export function requestedAfterLeaving(request) {
    if (!request) return null;
    const others = isFollower(request) || (Number(request.also_wanted_by) || 0) > 0;
    return others ? { status: request.status, by_me: false } : null;
}

/** "On MangaDex, MangaSee +2" — the first `max` distinct source names. */
export function sourceLine(sources, max = 2) {
    const names = [];
    for (const s of sources || []) {
        const name = String((s && (s.source_name || s.source)) || (typeof s === 'string' ? s : '')).trim();
        if (name !== '' && !names.includes(name)) names.push(name);
    }
    if (!names.length) return '';
    const shown = names.slice(0, max).join(', ');
    const more = names.length - max;
    return `On ${shown}${more > 0 ? ` +${more}` : ''}`;
}

/** "Couldn't reach: X, Y." for the sources a search skipped; '' for none. */
export function skippedLine(skipped) {
    const names = [...new Set((skipped || []).map((s) => String((s && (s.source_name || s.source)) || '').trim()).filter(Boolean))];
    return names.length ? `Couldn’t reach: ${names.join(', ')}.` : '';
}

/** The searching line: "Searching 7 sources…", then the slow note after SLOW_AFTER_MS. */
export function searchingLine(sourceCount, elapsedMs) {
    if (elapsedMs >= SLOW_AFTER_MS) return 'Still searching — some sources are slow';
    const n = Number(sourceCount);
    if (Number.isFinite(n) && n > 0) return `Searching ${n} source${n === 1 ? '' : 's'}…`;
    return 'Searching…';
}

/**
 * A request's status for people: {label, tone, detail}. tone is the badge
 * class suffix (badge-pending, badge-accent, badge-success, badge-danger).
 */
export function statusLabel(request) {
    const status = request && request.status;
    switch (status) {
        case 'pending':
            return { label: 'Pending', tone: 'pending', detail: '' };
        case 'approved':
            return { label: 'Approved — downloading', tone: 'accent', detail: '' };
        case 'available':
            return { label: 'Available', tone: 'success', detail: '' };
        case 'declined': {
            const reason = String((request && request.decline_reason) || '').trim();
            return { label: 'Declined', tone: 'danger', detail: reason };
        }
        default:
            return { label: String(status || 'Unknown'), tone: 'muted', detail: '' };
    }
}

/** "3 of 5 pending requests used" (requests the reader only joined do not count); '' when there is no limit (the admin). */
export function pendingUsedLine(requests, limit) {
    if (limit === null || limit === undefined) return '';
    // Uploaded books have their own limit: only title requests count here.
    const used = (requests || []).filter((r) => r && r.status === 'pending' && !isFollower(r) && r.medium !== 'book').length;
    return `${used} of ${limit} pending request${limit === 1 ? '' : 's'} used`;
}

/**
 * The admin's shortlist, best first: the server's order, but candidates with
 * an `error` (the source did not answer) last. Stable.
 */
export function orderCandidates(candidates) {
    const list = Array.isArray(candidates) ? candidates.filter((c) => c && typeof c === 'object') : [];
    return [...list.filter((c) => !c.error), ...list.filter((c) => c.error)];
}

/** The candidate preselected: the first that can be chosen (no error); null when none. */
export function defaultCandidate(candidates) {
    const first = orderCandidates(candidates).find((c) => !c.error);
    return first ? Number(first.index) : null;
}

/** Is a candidate selectable? Errored candidates are shown but not choosable. */
export function selectable(candidate) {
    return Boolean(candidate) && !candidate.error;
}

/**
 * The muted detail line of a candidate: "184 chapters · up to ch. 190 ·
 * 3 gaps · updated 2 days ago". Missing parts are left out.
 */
export function candidateDetail(c, now = Date.now()) {
    if (!c) return '';
    const parts = [];
    if (c.chapters_available !== null && c.chapters_available !== undefined) {
        const n = Number(c.chapters_available);
        parts.push(`${n.toLocaleString('en')} chapter${n === 1 ? '' : 's'}`);
    } else if (c.chapters !== null && c.chapters !== undefined) {
        const n = Number(c.chapters);
        parts.push(`${n.toLocaleString('en')} chapter${n === 1 ? '' : 's'}`);
    }
    if (c.highest_chapter !== null && c.highest_chapter !== undefined && c.highest_chapter !== '') {
        parts.push(`up to ch. ${c.highest_chapter}`);
    }
    if (c.gaps !== null && c.gaps !== undefined) {
        const g = Number(c.gaps);
        parts.push(g === 0 ? 'no gaps' : `${g} gap${g === 1 ? '' : 's'}`);
    }
    if (c.updated_at) {
        const ago = relativeTime(c.updated_at, now);
        if (ago) parts.push(`updated ${ago}`);
    }
    return parts.join(' · ');
}

/**
 * Is a manga request's shortlist still being ranked? (made from a search, so
 * it has candidates, and no ranking has finished yet.)
 */
export function rankingInProgress(request) {
    return Boolean(request)
        && request.medium === 'manga'
        && Array.isArray(request.candidates) && request.candidates.length > 0
        && !request.candidates_ranked_at;
}

/**
 * Seconds to wait from a Retry-After header: delta-seconds or an HTTP date.
 * Missing / unreadable → DEFAULT_RETRY_SECONDS; clamped to 1..MAX_RETRY_SECONDS.
 */
export function retryAfterSeconds(header, now = Date.now()) {
    const raw = header === null || header === undefined ? '' : String(header).trim();
    let seconds = NaN;
    if (/^\d+$/.test(raw)) {
        seconds = parseInt(raw, 10);
    } else if (raw !== '') {
        const at = Date.parse(raw);
        if (!Number.isNaN(at)) seconds = Math.ceil((at - now) / 1000);
    }
    if (!Number.isFinite(seconds)) seconds = DEFAULT_RETRY_SECONDS;
    return Math.min(MAX_RETRY_SECONDS, Math.max(1, seconds));
}

/** "just now", "5 minutes ago", "3 days ago", "in 2 hours" (en). '' for a bad date. */
export function relativeTime(iso, now = Date.now()) {
    const at = Date.parse(iso);
    if (Number.isNaN(at)) return '';
    const diff = Math.round((at - now) / 1000);
    const abs = Math.abs(diff);
    if (abs < 45) return 'just now';
    const units = [
        ['year', 365 * 86400], ['month', 30 * 86400], ['week', 7 * 86400],
        ['day', 86400], ['hour', 3600], ['minute', 60],
    ];
    for (const [unit, size] of units) {
        if (abs >= size || unit === 'minute') {
            const n = Math.max(1, Math.round(abs / size));
            const word = `${n} ${unit}${n === 1 ? '' : 's'}`;
            return diff < 0 ? `${word} ago` : `in ${word}`;
        }
    }
    return '';
}

// ---- Discover shelf (GET /api/v1/requests/browse) ----------------------------

/** How long before the shelf's loading line says a first load is slow (a cold list takes ~10 s). */
export const BROWSE_SLOW_AFTER_MS = 6000;

/** The browse endpoint's URL; medium, list and page are left out when not given (the server's defaults). */
export function browseUrl({ medium = null, list = null, page = null } = {}) {
    const params = new URLSearchParams();
    if (medium !== null && medium !== undefined && String(medium) !== '') params.set('medium', String(medium));
    if (list !== null && list !== undefined && String(list) !== '') params.set('list', String(list));
    const n = Number(page);
    if (page !== null && page !== undefined && Number.isInteger(n) && n > 0) params.set('page', String(n));
    const qs = params.toString();
    return `/api/v1/requests/browse${qs ? `?${qs}` : ''}`;
}

/**
 * What a browse answer means for the shelf: 'hide' (feature off, or nothing
 * to show: no lists), 'ready' (render it), 'error' (show the message and a
 * Retry: 502 / 503 / 429 / anything else unexpected).
 */
export function browseState(status, data) {
    if (status === 404) return 'hide';
    if (status >= 200 && status < 300) {
        const lists = data && Array.isArray(data.lists) ? data.lists : [];
        return lists.length ? 'ready' : 'hide';
    }
    return 'error';
}

/** The shelf's loading line: "Loading…", then the slow note after BROWSE_SLOW_AFTER_MS. */
export function browseLoadingLine(elapsedMs) {
    return elapsedMs >= BROWSE_SLOW_AFTER_MS ? 'Still loading — the first load can take a few seconds' : 'Loading…';
}

/**
 * A shelf card's muted line from its first source: "Ongoing · 212 ch",
 * either part alone when the other is missing, '' when neither.
 */
export function shelfMetaLine(result) {
    const s = result && Array.isArray(result.sources) ? result.sources[0] : null;
    if (!s || typeof s !== 'object') return '';
    const parts = [];
    const status = String(s.status || '').trim().replace(/_/g, ' ');
    if (status) parts.push(status.charAt(0).toUpperCase() + status.slice(1));
    const n = Number(s.chapters);
    if (s.chapters !== null && s.chapters !== undefined && s.chapters !== '' && Number.isFinite(n) && n > 0) {
        parts.push(`${Math.floor(n).toLocaleString('en')} ch`);
    }
    return parts.join(' · ');
}

/**
 * A page of the shelf without the works already shown (a title can move
 * between pages while the reader pages through), nor repeats within it.
 */
export function newResults(results, shownKeys) {
    const seen = new Set(shownKeys || []);
    const out = [];
    for (const r of results || []) {
        if (!r || r.key === null || r.key === undefined || seen.has(r.key)) continue;
        seen.add(r.key);
        out.push(r);
    }
    return out;
}

/**
 * POST /api/v1/requests body for a card, by where it came from:
 *   {type: 'search', searchId}  → {search_id, key}
 *   {type: 'shelf', list, page, medium?} → {medium?, list, page, key}
 * plus the note when there is one.
 */
export function requestBody(origin, key, note = '') {
    const base = origin && origin.type === 'shelf'
        ? { ...(origin.medium ? { medium: origin.medium } : {}), list: origin.list, page: Number(origin.page) || 1, key }
        : { search_id: origin ? origin.searchId : null, key };
    const text = String(note || '').trim();
    return text ? { ...base, note: text } : base;
}

/** Review list: pending requests oldest first (the API answers newest first). */
export function oldestFirst(requests) {
    return [...(requests || [])].sort((a, b) => {
        const d = Date.parse(a.created_at) - Date.parse(b.created_at);
        return d !== 0 && !Number.isNaN(d) ? d : Number(a.id) - Number(b.id);
    });
}

/** The decided tab: approved / available / declined only. */
export function decidedOnly(requests) {
    return (requests || []).filter((r) => r && r.status !== 'pending');
}

// ---- The medium picker -----------------------------------------------------------

/** The picker's media, in its order; the first is the default. */
export const MEDIA = ['book', 'novel', 'manga', 'comic'];

/** localStorage key of the reader's last medium. */
export const MEDIUM_STORAGE_KEY = 'novarr.requests.medium';

/** The medium to start on: the page's ?medium= when offered, else the remembered one, else books. */
export function initialMedium(stored, asked = null) {
    if (MEDIA.includes(asked)) return asked;
    return MEDIA.includes(stored) ? stored : MEDIA[0];
}

/** The Discover shelf's noun for a medium ("Discover books"). */
export function mediumNoun(medium) {
    return { book: 'books', novel: 'web novels', manga: 'manga', comic: 'comics' }[medium] || 'series';
}

/**
 * The search box for a medium: its placeholder, accessible label, hint line
 * (null: no hint) and longest text. A book search also takes a pasted
 * Goodreads / Amazon / … link (App\Books\BookLinkReader), so it is longer.
 */
export function searchBox(medium) {
    if (medium === 'book') {
        return {
            placeholder: 'Title, author, ISBN — or paste a Goodreads / Amazon link',
            label: 'Title, author or ISBN to search for, or a Goodreads, Amazon, Hardcover, Google Books or Open Library link',
            hint: 'New release not found? Paste its Goodreads, Amazon, Hardcover, Google Books or Open Library page.',
            maxLength: 2048,
        };
    }
    if (medium === 'comic') {
        return {
            placeholder: 'Series, title or ISBN — or paste a Goodreads / Amazon link',
            label: 'Comic series, graphic novel title or ISBN to search for, or a Goodreads, Amazon, Hardcover, Google Books or Open Library link',
            hint: 'Comic series and graphic novels. Not found? Paste its Goodreads or Amazon page, or request it by title.',
            maxLength: 2048,
        };
    }
    return { placeholder: 'Title or author', label: 'Title or author to search for', hint: null, maxLength: 100 };
}

/** A result's series line: "Scion #1", "Riftwake" (no number), or '' (none). */
export function seriesLine(result) {
    const series = result && typeof result.series === 'string' ? result.series.trim() : '';
    if (!series) return '';
    const index = result.series_index === null || result.series_index === undefined ? '' : String(result.series_index).trim();
    return index ? `${series} #${index}` : series;
}

// ---- My requests: filter, age, paging -------------------------------------------

/** Rows shown at first, and added by each "Show more". */
export const MINE_PAGE = 10;

/** Ready / declined requests settled longer ago than this are "older" (collapsed in All). */
export const MINE_STALE_DAYS = 30;

/** The filter buttons, in order. */
export const MINE_FILTERS = [
    { id: 'active', label: 'In progress' },
    { id: 'ready', label: 'Ready to read' },
    { id: 'declined', label: 'Declined' },
    { id: 'all', label: 'All' },
];

/** A request's group: waiting (pending), approved (downloading), ready (available), declined, other. */
export function mineGroup(request) {
    switch (request && request.status) {
        case 'pending': return 'waiting';
        case 'approved': return 'approved';
        case 'available': return 'ready';
        case 'declined': return 'declined';
        default: return 'other';
    }
}

/** Whether a request is in a filter ('active' = waiting + approved). */
export function inMineFilter(request, filter) {
    const g = mineGroup(request);
    switch (filter) {
        case 'active': return g === 'waiting' || g === 'approved';
        case 'ready': return g === 'ready';
        case 'declined': return g === 'declined';
        default: return true;
    }
}

/** How many loaded requests each filter holds: {active, waiting, approved, ready, declined, all}. */
export function mineCounts(requests) {
    const counts = { active: 0, waiting: 0, approved: 0, ready: 0, declined: 0, all: 0 };
    for (const r of requests || []) {
        if (!r) continue;
        counts.all++;
        const g = mineGroup(r);
        if (g in counts) counts[g]++;
        if (g === 'waiting' || g === 'approved') counts.active++;
    }
    return counts;
}

/** The filter to open on: in progress when anything is, else ready to read, else all. */
export function defaultMineFilter(counts) {
    if (counts && counts.active > 0) return 'active';
    if (counts && counts.ready > 0) return 'ready';
    return 'all';
}

/** A filter button's text: "In progress (3)", "All (50+)" while more pages wait on the server; no count at 0. */
export function mineFilterLabel(filter, counts, hasMore = false) {
    const f = MINE_FILTERS.find((x) => x.id === filter);
    const label = f ? f.label : String(filter);
    const n = counts ? Number(counts[filter]) || 0 : 0;
    if (n === 0) return label;
    return `${label} (${n}${hasMore ? '+' : ''})`;
}

/** Whether a ready / declined request was settled more than MINE_STALE_DAYS ago (available_at, else decided_at). */
export function isStale(request, now = Date.now(), days = MINE_STALE_DAYS) {
    const g = mineGroup(request);
    if (g !== 'ready' && g !== 'declined') return false;
    const t = Date.parse((g === 'ready' && request.available_at) || request.decided_at || request.created_at || '');
    return !Number.isNaN(t) && now - t > days * 86_400_000;
}

/**
 * What the list shows for a filter: the first `limit` matching requests
 * (the API's order, newest first). In All, older ready / declined requests
 * are left out unless showOld (`older` counts them). `more`: matching rows
 * beyond the limit (already loaded).
 *
 * @returns {{items: object[], older: number, more: boolean, total: number}}
 */
export function mineView(requests, filter, { limit = MINE_PAGE, showOld = false, now = Date.now() } = {}) {
    let matching = (requests || []).filter((r) => r && inMineFilter(r, filter));
    let older = 0;
    if (filter === 'all' && !showOld) {
        const fresh = matching.filter((r) => !isStale(r, now));
        older = matching.length - fresh.length;
        matching = fresh;
    }
    return { items: matching.slice(0, Math.max(0, limit)), older, more: matching.length > limit, total: matching.length };
}

/** The empty line of a filter with nothing in it (given what the other filters hold). */
export function mineEmptyLine(filter, counts) {
    if (!counts || counts.all === 0) return { title: 'No requests yet.', body: 'Search above for something to read, or request it by title.' };
    switch (filter) {
        case 'active': return { title: 'Nothing in progress.', body: counts.ready > 0 ? 'See Ready to read for what has arrived.' : 'Search above to request something new.' };
        case 'ready': return { title: 'Nothing ready yet.', body: 'Requests the owner adds appear here.' };
        case 'declined': return { title: 'Nothing declined.', body: null };
        default: return { title: 'No recent requests.', body: null };
    }
}
