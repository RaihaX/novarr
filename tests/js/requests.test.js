// Reader requests page decisions (resources/js/requests-logic.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    resultAction, sourceLine, skippedLine, searchingLine, statusLabel, pendingUsedLine,
    orderCandidates, defaultCandidate, selectable, candidateDetail, rankingInProgress,
    retryAfterSeconds, relativeTime, oldestFirst, decidedOnly,
    isFollower, mineAction, alsoWantedLine, requestedAfterLeaving,
    browseUrl, browseState, browseLoadingLine, shelfMetaLine, newResults, requestBody,
    SLOW_AFTER_MS, DEFAULT_RETRY_SECONDS, MAX_RETRY_SECONDS, BROWSE_SLOW_AFTER_MS,
} from '../../resources/js/requests-logic.js';

const NOW = Date.parse('2026-11-19T12:00:00Z');

test('a result card offers one action from in_library / requested', () => {
    assert.deepEqual(resultAction({ in_library: false, requested: null }),
        { kind: 'request', label: 'Request', href: null, disabled: false });
    assert.deepEqual(resultAction({ in_library: true, novel_id: 12, requested: null }),
        { kind: 'open', label: 'In library — Open', href: '/novels/12', disabled: false });
    assert.equal(resultAction({ in_library: true, novel_id: 12, requested: { status: 'pending', by_me: true } }).kind, 'open',
        'in the library wins over requested');
    assert.deepEqual(resultAction({ in_library: false, requested: { status: 'pending', by_me: true } }),
        { kind: 'requested_by_me', label: 'Requested by you', href: null, disabled: true });
    assert.deepEqual(resultAction({ in_library: false, requested: { status: 'available', by_me: false } }),
        { kind: 'requested', label: 'Requested', href: null, disabled: true }, 'too late to join');
    assert.equal(resultAction({ in_library: true, novel_id: null }).disabled, true, 'no id to link to: inert');
    assert.equal(resultAction(null).kind, 'request');
});

test('the source line names two sources and counts the rest', () => {
    assert.equal(sourceLine([{ source_name: 'MangaDex' }]), 'On MangaDex');
    assert.equal(sourceLine([{ source_name: 'MangaDex' }, { source_name: 'MangaSee' }]), 'On MangaDex, MangaSee');
    assert.equal(sourceLine([{ source_name: 'MangaDex' }, { source_name: 'MangaSee' }, { source_name: 'A' }, { source_name: 'B' }]),
        'On MangaDex, MangaSee +2');
    assert.equal(sourceLine([{ source_name: 'X' }, { source_name: 'X' }, { source: 'raw_key' }]), 'On X, raw_key', 'distinct, key as fallback');
    assert.equal(sourceLine(['NovelBin', 'RoyalRoad']), 'On NovelBin, RoyalRoad', 'the reader view gives names');
    assert.equal(sourceLine([]), '');
    assert.equal(sourceLine(undefined), '');
});

test('skipped sources become one muted line', () => {
    assert.equal(skippedLine([{ source_name: 'MangaSee', reason: 'timeout' }, { source_name: 'Comick', reason: 'failed' }]),
        'Couldn’t reach: MangaSee, Comick.');
    assert.equal(skippedLine([]), '');
    assert.equal(skippedLine(null), '');
});

test('the searching line counts sources, then says some are slow', () => {
    assert.equal(searchingLine(7, 0), 'Searching 7 sources…');
    assert.equal(searchingLine(1, 2000), 'Searching 1 source…');
    assert.equal(searchingLine(null, 0), 'Searching…');
    assert.equal(searchingLine(7, SLOW_AFTER_MS - 1), 'Searching 7 sources…');
    assert.equal(searchingLine(7, SLOW_AFTER_MS), 'Still searching — some sources are slow');
});

test('status labels', () => {
    assert.deepEqual(statusLabel({ status: 'pending' }), { label: 'Pending', tone: 'pending', detail: '' });
    assert.deepEqual(statusLabel({ status: 'approved' }), { label: 'Approved — downloading', tone: 'accent', detail: '' });
    assert.deepEqual(statusLabel({ status: 'available' }), { label: 'Available', tone: 'success', detail: '' });
    assert.deepEqual(statusLabel({ status: 'declined', decline_reason: '  Not on any English source. ' }),
        { label: 'Declined', tone: 'danger', detail: 'Not on any English source.' });
    assert.deepEqual(statusLabel({ status: 'declined', decline_reason: null }), { label: 'Declined', tone: 'danger', detail: '' });
    assert.equal(statusLabel({ status: 'odd' }).tone, 'muted');
});

test('pending requests used, only with a limit', () => {
    const mine = [{ status: 'pending' }, { status: 'declined' }, { status: 'pending' }, { status: 'available' }];
    assert.equal(pendingUsedLine(mine, 5), '2 of 5 pending requests used');
    assert.equal(pendingUsedLine([], 5), '0 of 5 pending requests used');
    assert.equal(pendingUsedLine(mine, 1), '2 of 1 pending request used');
    assert.equal(pendingUsedLine(mine, null), '', 'the admin has no limit');
});

test('candidates keep the server order, errored ones last; the first good one is preselected', () => {
    const cands = [
        { index: 0, source_name: 'A', error: 'the source did not answer' },
        { index: 1, source_name: 'B' },
        { index: 2, source_name: 'C', error: 'timeout' },
        { index: 3, source_name: 'D' },
    ];
    assert.deepEqual(orderCandidates(cands).map((c) => c.index), [1, 3, 0, 2]);
    assert.equal(defaultCandidate(cands), 1);
    assert.equal(selectable(cands[0]), false);
    assert.equal(selectable(cands[1]), true);
    assert.equal(defaultCandidate([{ index: 0, error: 'x' }]), null, 'nothing choosable');
    assert.equal(defaultCandidate([]), null);
    assert.equal(defaultCandidate(['MangaDex']), null, 'the reader view (names) has no choices');
    assert.deepEqual(orderCandidates(null), []);
});

test('candidate detail line', () => {
    const c = { chapters_available: 184, highest_chapter: '190', gaps: 3, updated_at: '2026-11-17T12:00:00Z' };
    assert.equal(candidateDetail(c, NOW), '184 chapters · up to ch. 190 · 3 gaps · updated 2 days ago');
    assert.equal(candidateDetail({ chapters_available: 1, gaps: 0 }, NOW), '1 chapter · no gaps');
    assert.equal(candidateDetail({ chapters: 1200 }, NOW), '1,200 chapters', 'search count before ranking');
    assert.equal(candidateDetail({}, NOW), '');
    assert.equal(candidateDetail(null, NOW), '');
});

test('ranking is in progress for a manga request with candidates and no ranking yet', () => {
    assert.equal(rankingInProgress({ medium: 'manga', candidates: [{ index: 0 }], candidates_ranked_at: null }), true);
    assert.equal(rankingInProgress({ medium: 'manga', candidates: [{ index: 0 }], candidates_ranked_at: '2026-11-19T11:00:00Z' }), false);
    assert.equal(rankingInProgress({ medium: 'novel', candidates: [{ index: 0 }], candidates_ranked_at: null }), false, 'novels are not ranked');
    assert.equal(rankingInProgress({ medium: 'manga', candidates: [], candidates_ranked_at: null }), false, 'free text');
    assert.equal(rankingInProgress(null), false);
});

test('Retry-After: seconds or an HTTP date, a default, clamped', () => {
    assert.equal(retryAfterSeconds('15', NOW), 15);
    assert.equal(retryAfterSeconds(' 120 ', NOW), 120);
    assert.equal(retryAfterSeconds(new Date(NOW + 30_000).toUTCString(), NOW), 30);
    assert.equal(retryAfterSeconds(null, NOW), DEFAULT_RETRY_SECONDS);
    assert.equal(retryAfterSeconds('', NOW), DEFAULT_RETRY_SECONDS);
    assert.equal(retryAfterSeconds('soon', NOW), DEFAULT_RETRY_SECONDS);
    assert.equal(retryAfterSeconds('0', NOW), 1, 'at least a second');
    assert.equal(retryAfterSeconds(new Date(NOW - 60_000).toUTCString(), NOW), 1, 'a past date');
    assert.equal(retryAfterSeconds('999999', NOW), MAX_RETRY_SECONDS);
});

test('relative times', () => {
    assert.equal(relativeTime('2026-11-19T11:59:40Z', NOW), 'just now');
    assert.equal(relativeTime('2026-11-19T11:55:00Z', NOW), '5 minutes ago');
    assert.equal(relativeTime('2026-11-19T11:00:00Z', NOW), '1 hour ago');
    assert.equal(relativeTime('2026-11-16T12:00:00Z', NOW), '3 days ago');
    assert.equal(relativeTime('2026-10-01T12:00:00Z', NOW), '2 months ago');
    assert.equal(relativeTime('2026-11-19T14:00:00Z', NOW), 'in 2 hours');
    assert.equal(relativeTime('nonsense', NOW), '');
});

test('review lists: pending oldest first, decided without pending', () => {
    const list = [
        { id: 3, status: 'pending', created_at: '2026-11-19T10:00:00Z' },
        { id: 2, status: 'declined', created_at: '2026-11-18T10:00:00Z' },
        { id: 1, status: 'pending', created_at: '2026-11-17T10:00:00Z' },
        { id: 4, status: 'pending', created_at: '2026-11-17T10:00:00Z' },
    ];
    assert.deepEqual(oldestFirst(list).map((r) => r.id), [1, 4, 2, 3]);
    assert.deepEqual(decidedOnly(list).map((r) => r.id), [2]);
    assert.deepEqual(oldestFirst(null), []);
});

test('Me too: someone else\'s pending or approved request can be joined; joined reads as requested by you', () => {
    assert.deepEqual(resultAction({ in_library: false, requested: { status: 'pending', by_me: false } }),
        { kind: 'join', label: 'Me too', href: null, disabled: false });
    assert.equal(resultAction({ in_library: false, requested: { status: 'approved', by_me: false } }).kind, 'join');
    assert.equal(resultAction({ in_library: false, requested: { status: 'declined', by_me: false } }).kind, 'requested');
    assert.equal(resultAction({ in_library: false, requested: { status: 'pending', by_me: true } }).label, 'Requested by you',
        'by_me is true for a follower too');
    assert.equal(resultAction({ in_library: true, novel_id: 4, requested: { status: 'pending', by_me: false } }).kind, 'open');
});

test('My requests: Leave for a joined request, Cancel for one\'s own pending one', () => {
    assert.equal(mineAction({ mine: 'follower', status: 'pending', can_cancel: true }), 'leave');
    assert.equal(mineAction({ mine: 'follower', status: 'approved', can_cancel: true }), 'leave');
    assert.equal(mineAction({ mine: 'follower', status: 'available', can_cancel: false }), null);
    assert.equal(mineAction({ mine: 'requester', status: 'pending', can_cancel: true }), 'cancel');
    assert.equal(mineAction({ mine: 'requester', status: 'approved', can_cancel: false }), null);
    assert.equal(mineAction({ status: 'pending', can_cancel: true }), 'cancel', 'an old server without `mine`');
    assert.equal(mineAction({ mine: 'requester', status: 'approved', can_cancel: true }), null, 'the admin may delete, but not from My requests');
    assert.equal(mineAction(null), null);
    assert.equal(isFollower({ mine: 'follower' }), true);
    assert.equal(isFollower({ mine: 'requester' }), false);
    assert.equal(isFollower({}), false);
});

test('joined requests do not count as pending requests used', () => {
    const mine = [{ status: 'pending', mine: 'requester' }, { status: 'pending', mine: 'follower' }, { status: 'pending' }];
    assert.equal(pendingUsedLine(mine, 5), '2 of 5 pending requests used');
});

test('also wanted by: names for review, a count for decided', () => {
    const req = { also_wanted_by: 2, followers: [{ id: 3, name: 'Ana' }, { id: 4, name: 'Ben' }] };
    assert.equal(alsoWantedLine(req), 'Also wanted by Ana, Ben');
    assert.equal(alsoWantedLine(req, { names: false }), 'Also wanted by 2 readers');
    assert.equal(alsoWantedLine({ also_wanted_by: 1, followers: null }), 'Also wanted by 1 reader', 'the reader view has no names');
    assert.equal(alsoWantedLine({ also_wanted_by: 3, followers: [{ id: 3, name: 'Ana' }] }), 'Also wanted by Ana +2');
    assert.equal(alsoWantedLine({ also_wanted_by: 0, followers: [] }), '');
    assert.equal(alsoWantedLine({}), '', 'an old server without the fields');
    assert.equal(alsoWantedLine(null), '');
});

test('after leaving or cancelling, the result card is joinable again only when others still want it', () => {
    assert.deepEqual(requestedAfterLeaving({ mine: 'follower', status: 'pending', also_wanted_by: 1 }), { status: 'pending', by_me: false });
    assert.deepEqual(requestedAfterLeaving({ mine: 'requester', status: 'pending', also_wanted_by: 2 }), { status: 'pending', by_me: false },
        'handed to the oldest follower');
    assert.equal(requestedAfterLeaving({ mine: 'requester', status: 'pending', also_wanted_by: 0 }), null);
    assert.equal(requestedAfterLeaving(null), null);
});

test('the browse URL leaves out what is not given', () => {
    assert.equal(browseUrl(), '/api/v1/requests/browse');
    assert.equal(browseUrl({ list: 'trending' }), '/api/v1/requests/browse?list=trending');
    assert.equal(browseUrl({ list: 'top', page: 3 }), '/api/v1/requests/browse?list=top&page=3');
    assert.equal(browseUrl({ list: 'a b&c', page: 2 }), '/api/v1/requests/browse?list=a+b%26c&page=2', 'encoded');
    assert.equal(browseUrl({ list: '', page: 0 }), '/api/v1/requests/browse', 'empty list, no page 0');
    assert.equal(browseUrl({ list: null, page: 'x' }), '/api/v1/requests/browse');
});

test('a browse answer shows, hides or errors', () => {
    assert.equal(browseState(200, { lists: [{ id: 'popular', name: 'Popular' }], results: [] }), 'ready');
    assert.equal(browseState(200, { lists: [], list: null, results: [] }), 'hide', 'nothing to show');
    assert.equal(browseState(200, {}), 'hide');
    assert.equal(browseState(404, { code: 'requests_disabled' }), 'hide');
    assert.equal(browseState(502, { code: 'browse_unavailable' }), 'error');
    assert.equal(browseState(503, { code: 'browse_busy' }), 'error');
    assert.equal(browseState(429, { code: 'throttled' }), 'error');
    assert.equal(browseState(500, null), 'error');
});

test('the shelf loading line turns slow after six seconds', () => {
    assert.equal(BROWSE_SLOW_AFTER_MS, 6000);
    assert.equal(browseLoadingLine(0), 'Loading…');
    assert.equal(browseLoadingLine(BROWSE_SLOW_AFTER_MS - 1), 'Loading…');
    assert.equal(browseLoadingLine(BROWSE_SLOW_AFTER_MS), 'Still loading — the first load can take a few seconds');
});

test('a shelf card\'s meta line: status and chapters of the first source', () => {
    assert.equal(shelfMetaLine({ sources: [{ status: 'ongoing', chapters: 212 }, { status: 'completed', chapters: 3 }] }), 'Ongoing · 212 ch');
    assert.equal(shelfMetaLine({ sources: [{ status: 'completed', chapters: null }] }), 'Completed');
    assert.equal(shelfMetaLine({ sources: [{ status: null, chapters: 1200 }] }), '1,200 ch');
    assert.equal(shelfMetaLine({ sources: [{ status: 'on_hiatus', chapters: 0 }] }), 'On hiatus', 'no chapters counted');
    assert.equal(shelfMetaLine({ sources: [{ status: '', chapters: '' }] }), '');
    assert.equal(shelfMetaLine({ sources: [] }), '');
    assert.equal(shelfMetaLine({}), '');
    assert.equal(shelfMetaLine(null), '');
});

test('a new shelf page drops works already shown and repeats', () => {
    const page = [{ key: 'a' }, { key: 'b' }, { key: 'c' }, { key: 'b' }, null, { key: null }];
    assert.deepEqual(newResults(page, new Set(['a'])).map((r) => r.key), ['b', 'c']);
    assert.deepEqual(newResults(page, ['a', 'b', 'c']), []);
    assert.deepEqual(newResults(page, null).map((r) => r.key), ['a', 'b', 'c']);
    assert.deepEqual(newResults(undefined, []), []);
});

test('the request body follows where the card came from', () => {
    assert.deepEqual(requestBody({ type: 'search', searchId: 's1' }, 'k1'), { search_id: 's1', key: 'k1' });
    assert.deepEqual(requestBody({ type: 'search', searchId: 's1' }, 'k1', '  please  '), { search_id: 's1', key: 'k1', note: 'please' });
    assert.deepEqual(requestBody({ type: 'shelf', list: 'top', page: 2 }, 'k2'), { list: 'top', page: 2, key: 'k2' });
    assert.deepEqual(requestBody({ type: 'shelf', list: 'top', page: 3 }, 'k2', 'hi'), { list: 'top', page: 3, key: 'k2', note: 'hi' });
    assert.deepEqual(requestBody({ type: 'shelf', list: 'popular' }, 'k3', '   '), { list: 'popular', page: 1, key: 'k3' }, 'blank note left out');
});
