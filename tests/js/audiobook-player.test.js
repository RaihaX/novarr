// The web audiobook player's decisions (resources/js/audiobook-player-logic.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    SPEEDS, totalDuration, clampPosition, partAt, positionOf, chapterAt, previousChapterTarget, nextChapterTarget,
    skip, nextSpeed, normalizeSpeed, startSleepTimer, sleepTimerDue, sleepRemaining, formatTime, humanDuration,
    resumePosition, progressBody, saveDue, sortBookmarks, bookmarkLabel, keyAction, SLEEP_END_OF_CHAPTER,
} from '../../resources/js/audiobook-player-logic.js';

// Three parts: 0–60 s, 60–150 s, 150–180 s.
const parts = [
    { index: 0, start_ms: 0, duration_ms: 60000 },
    { index: 1, start_ms: 60000, duration_ms: 90000 },
    { index: 2, start_ms: 150000, duration_ms: 30000 },
];
const chapters = [
    { index: 0, title: 'Opening', start_ms: 0, end_ms: 45000 },
    { index: 1, title: 'Middle', start_ms: 45000, end_ms: 120000 },
    { index: 2, title: 'End', start_ms: 120000, end_ms: 180000 },
];

test('the whole book is the end of its last part', () => {
    assert.equal(totalDuration(parts), 180000);
    assert.equal(totalDuration([]), 0);
    assert.equal(totalDuration(undefined), 0);
    assert.equal(clampPosition(-5, 1000), 0);
    assert.equal(clampPosition(5000, 1000), 1000);
});

test('a whole-book position maps to its part and back', () => {
    assert.deepEqual(partAt(parts, 0), { index: 0, offsetMs: 0 });
    assert.deepEqual(partAt(parts, 59999), { index: 0, offsetMs: 59999 });
    assert.deepEqual(partAt(parts, 60000), { index: 1, offsetMs: 0 });
    assert.deepEqual(partAt(parts, 100000), { index: 1, offsetMs: 40000 });
    assert.deepEqual(partAt(parts, 170000), { index: 2, offsetMs: 20000 });
    // The very end (and past it) belongs to the last part.
    assert.deepEqual(partAt(parts, 180000), { index: 2, offsetMs: 30000 });
    assert.deepEqual(partAt(parts, 999999), { index: 2, offsetMs: 30000 });
    assert.deepEqual(partAt(parts, -10), { index: 0, offsetMs: 0 });
    assert.equal(partAt([], 10), null);

    assert.equal(positionOf(parts, 1, 40000), 100000);
    assert.equal(positionOf(parts, 2, 99999), 180000); // offset kept within the part
    assert.equal(positionOf(parts, 7, 10), 0);
    for (const pos of [0, 1, 59999, 60000, 123456, 179999]) {
        const p = partAt(parts, pos);
        assert.equal(positionOf(parts, p.index, p.offsetMs), pos, `round trip ${pos}`);
    }
});

test('parts are taken in start order whatever order they come in', () => {
    const shuffled = [parts[2], parts[0], parts[1]];
    assert.deepEqual(partAt(shuffled, 100000), { index: 1, offsetMs: 40000 });
});

test('the chapter at a position, previous and next chapter', () => {
    assert.equal(chapterAt(chapters, 0), 0);
    assert.equal(chapterAt(chapters, 44999), 0);
    assert.equal(chapterAt(chapters, 45000), 1);
    assert.equal(chapterAt(chapters, 179999), 2);
    assert.equal(chapterAt(chapters, 500000), 2);
    assert.equal(chapterAt([], 10), -1);

    // More than 3 s into a chapter: back to its start; within 3 s: the one before.
    assert.equal(previousChapterTarget(chapters, 60000), 45000);
    assert.equal(previousChapterTarget(chapters, 46000), 0);
    assert.equal(previousChapterTarget(chapters, 1000), 0);
    assert.equal(nextChapterTarget(chapters, 10000), 45000);
    assert.equal(nextChapterTarget(chapters, 130000), null);
    assert.equal(nextChapterTarget([], 0), null);
});

test('skips stay within the book', () => {
    assert.equal(skip(10000, -15000, 180000), 0);
    assert.equal(skip(10000, 30000, 180000), 40000);
    assert.equal(skip(170000, 30000, 180000), 180000);
});

test('speeds cycle and are kept within what the API accepts', () => {
    assert.equal(nextSpeed(1), 1.25);
    assert.equal(nextSpeed(3), SPEEDS[0]);
    assert.equal(nextSpeed(1.1), SPEEDS[0]);
    assert.equal(normalizeSpeed('1.5'), 1.5);
    assert.equal(normalizeSpeed(9), 3);
    assert.equal(normalizeSpeed(0.1), 0.5);
    assert.equal(normalizeSpeed('fast'), 1);
    assert.equal(normalizeSpeed(1.234), 1.23);
});

test('the sleep timer: minutes on the clock, or the end of the chapter', () => {
    const now = 1_000_000;
    const t = startSleepTimer(15, now, chapters, 0);
    assert.deepEqual(t, { kind: 'minutes', endsAt: now + 15 * 60000 });
    assert.equal(sleepTimerDue(t, now + 15 * 60000 - 1, 0), false);
    assert.equal(sleepTimerDue(t, now + 15 * 60000, 0), true);
    assert.equal(sleepRemaining(t, now + 60000), 14 * 60000);
    assert.equal(sleepRemaining(t, now + 99 * 60000), 0);

    const c = startSleepTimer(SLEEP_END_OF_CHAPTER, now, chapters, 50000);
    assert.deepEqual(c, { kind: 'chapter', stopAtMs: 120000 });
    assert.equal(sleepTimerDue(c, now, 100000), false);
    assert.equal(sleepTimerDue(c, now, 119800), true);
    assert.equal(sleepRemaining(c, now), null);

    assert.equal(startSleepTimer(null, now, chapters, 0), null);
    assert.equal(startSleepTimer(0, now, chapters, 0), null);
    assert.equal(startSleepTimer(SLEEP_END_OF_CHAPTER, now, [], 0), null);
    assert.equal(sleepTimerDue(null, now, 0), false);
});

test('times read like a player shows them', () => {
    assert.equal(formatTime(0), '0:00');
    assert.equal(formatTime(65000), '1:05');
    assert.equal(formatTime(3723000), '1:02:03');
    assert.equal(formatTime(-5), '0:00');
    assert.equal(humanDuration(41234000), '11 h 27 min');
    assert.equal(humanDuration(3600000), '1 h');
    assert.equal(humanDuration(1620000), '27 min');
    assert.equal(humanDuration(45000), '45 s');
});

test('resume picks the newer of the server and this browser, from the start when finished', () => {
    const server = { position_ms: 50000, finished: false, updated_at: '2026-11-27T10:00:00Z' };
    const older = { position_ms: 90000, at: Date.parse('2026-11-27T09:00:00Z') };
    const newer = { position_ms: 90000, at: Date.parse('2026-11-27T11:00:00Z') };
    assert.equal(resumePosition(server, older, 180000), 50000);
    assert.equal(resumePosition(server, newer, 180000), 90000);
    assert.equal(resumePosition(null, newer, 180000), 90000);
    assert.equal(resumePosition(server, null, 180000), 50000);
    assert.equal(resumePosition(null, null, 180000), 0);
    assert.equal(resumePosition({ ...server, finished: true }, null, 180000), 0);
    // Within 5 s of the end: start again.
    assert.equal(resumePosition({ ...server, position_ms: 177000 }, null, 180000), 0);
    assert.equal(resumePosition({ ...server, position_ms: 990000 }, null, 1000000), 990000);
});

test('the progress body the API expects', () => {
    assert.deepEqual(progressBody(1234.6, 1.5), { position_ms: 1235, speed: 1.5 });
    assert.deepEqual(progressBody(-3, 7, true), { position_ms: 0, speed: 3, finished: true });
    const at = Date.parse('2026-11-27T10:00:00Z');
    assert.deepEqual(progressBody(10, 1, false, at), { position_ms: 10, speed: 1, finished: false, recorded_at: '2026-11-27T10:00:00.000Z' });
});

test('a periodic save is due after 15 s of listening', () => {
    assert.equal(saveDue(0, 15000, 0, 15000), true);
    assert.equal(saveDue(0, 14999, 0, 15000), false);
    assert.equal(saveDue(0, 60000, 5000, 5500), false); // has not moved
});

test('bookmarks: in position order, labelled by note or chapter', () => {
    const sorted = sortBookmarks([{ id: 2, position_ms: 900 }, { id: 1, position_ms: 100 }, { id: 3, position_ms: 100 }]);
    assert.deepEqual(sorted.map((b) => b.id), [1, 3, 2]);
    assert.deepEqual(sortBookmarks(undefined), []);
    assert.equal(bookmarkLabel({ position_ms: 50000, note: '  The duel ' }, chapters), 'The duel');
    assert.equal(bookmarkLabel({ position_ms: 50000, note: null }, chapters), 'Middle · 0:50');
    assert.equal(bookmarkLabel({ position_ms: 50000, note: '' }, []), '0:50');
});

test('keyboard: space plays / pauses, arrows skip (shift: further)', () => {
    assert.deepEqual(keyAction(' '), { action: 'toggle' });
    assert.deepEqual(keyAction('ArrowLeft'), { action: 'skip', deltaMs: -15000 });
    assert.deepEqual(keyAction('ArrowRight', true), { action: 'skip', deltaMs: 30000 });
    assert.equal(keyAction('x'), null);
});
