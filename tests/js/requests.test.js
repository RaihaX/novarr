// Reader requests page decisions (resources/js/requests-logic.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    resultAction, sourceLine, skippedLine, searchingLine, statusLabel, pendingUsedLine,
    orderCandidates, defaultCandidate, selectable, candidateDetail, rankingInProgress,
    retryAfterSeconds, relativeTime, oldestFirst, decidedOnly,
    SLOW_AFTER_MS, DEFAULT_RETRY_SECONDS, MAX_RETRY_SECONDS,
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
    assert.deepEqual(resultAction({ in_library: false, requested: { status: 'approved', by_me: false } }),
        { kind: 'requested', label: 'Requested', href: null, disabled: true });
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
