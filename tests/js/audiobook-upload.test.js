// The audiobook upload page's decisions (resources/js/audiobook-upload-logic.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    extensionOf, relativeName, naturalCompare, selectFiles, planChunks, contentRange, missingChunks,
    pendingWork, receivedBytes, shouldRetry, backoffDelay, MAX_ATTEMPTS,
    resumeKey, resumeState, saveResume, loadResume, clearResume, matchResume,
    speedTracker, eta, percent, formatBytes, formatDuration, statusLabel,
} from '../../resources/js/audiobook-upload-logic.js';

const MB = 1048576;
const file = (name, size, extra = {}) => ({ name, size, lastModified: 1700000000000, ...extra });

function memoryStorage() {
    const data = new Map();
    return {
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => data.set(k, String(v)),
        removeItem: (k) => data.delete(k),
        data,
    };
}

test('names: extension, folder-relative path, natural order', () => {
    assert.equal(extensionOf('Book/Part 01.MP3'), 'mp3');
    assert.equal(extensionOf('.hidden'), '');
    assert.equal(extensionOf('noext'), '');
    assert.equal(relativeName(file('01.mp3', 1, { webkitRelativePath: 'Mistborn/01.mp3' })), 'Mistborn/01.mp3');
    assert.equal(relativeName(file('01.mp3', 1, { relativePath: 'Dropped/01.mp3' })), 'Dropped/01.mp3');
    assert.equal(relativeName(file('01.mp3', 1)), '01.mp3');
    const names = ['Part 10.mp3', 'part 2.mp3', 'Part 1.mp3'];
    assert.deepEqual(names.sort(naturalCompare), ['Part 1.mp3', 'part 2.mp3', 'Part 10.mp3']);
});

test('selecting: audio and a cover go, hidden and other files are skipped, in natural order', () => {
    const r = selectFiles([
        file('10.mp3', 5 * MB), file('2.mp3', 5 * MB), file('.DS_Store', 10), file('notes.txt', 10),
        file('cover.jpg', 100000), file('scan.png', 30 * MB),
    ]);
    assert.deepEqual(r.accepted.map((f) => f.name), ['2.mp3', '10.mp3', 'cover.jpg']);
    assert.deepEqual(r.accepted.map((f) => f.kind), ['audio', 'audio', 'image']);
    assert.deepEqual(r.skipped.map((s) => s.name), ['.DS_Store', 'notes.txt', 'scan.png']);
    assert.equal(r.total, 10 * MB + 100000);
    assert.deepEqual(r.errors, []);
});

test('selecting: the limits are errors', () => {
    const limits = { maxFile: 10 * MB, maxTotal: 15 * MB, maxFiles: 2 };
    assert.match(selectFiles([file('a.m4b', 11 * MB)], limits).errors[0], /larger than 10 MB/);
    assert.match(selectFiles([file('a.mp3', 8 * MB), file('b.mp3', 8 * MB)], limits).errors[0], /16 MB together/);
    assert.match(selectFiles([file('a.mp3', 1), file('b.mp3', 1), file('c.mp3', 1)], limits).errors[0], /Up to 2 files/);
    assert.match(selectFiles([file('cover.jpg', 10)]).errors[0], /no audio file/);
    assert.match(selectFiles([file('a.mp3', 0)]).errors[0], /empty/);
    assert.deepEqual(selectFiles([]).accepted, []);
    assert.deepEqual(selectFiles(null).accepted, []);
});

test('chunk plan: 8 MB pieces, the last shorter, Content-Range of each', () => {
    const chunks = planChunks(20 * MB, 8 * MB);
    assert.equal(chunks.length, 3);
    assert.deepEqual(chunks[0], { n: 0, start: 0, end: 8 * MB, length: 8 * MB });
    assert.deepEqual(chunks[2], { n: 2, start: 16 * MB, end: 20 * MB, length: 4 * MB });
    assert.equal(contentRange(chunks[2], 20 * MB), `bytes ${16 * MB}-${20 * MB - 1}/${20 * MB}`);
    assert.equal(planChunks(8 * MB, 8 * MB).length, 1);
    assert.equal(planChunks(8 * MB + 1, 8 * MB).length, 2);
    assert.deepEqual(planChunks(0, 8 * MB), []);
    assert.deepEqual(planChunks(10, 0), []);
});

test('resume: what is left to send comes from the server\'s received lists', () => {
    const upload = {
        chunk_size: 10,
        files: [
            { index: 0, size: 35, chunks: 4, received: [0, 2] },
            { index: 1, size: 5, chunks: 1, received: [0] },
            { index: 2, size: 12, chunks: 2, received: [] },
        ],
    };
    assert.deepEqual(missingChunks(upload.files[0]), [1, 3]);
    assert.deepEqual(missingChunks(upload.files[1]), []);
    assert.deepEqual(pendingWork(upload).map((t) => `${t.index}:${t.n}`), ['0:1', '0:3', '2:0', '2:1']);
    assert.deepEqual(pendingWork(upload)[1], { index: 0, n: 3, start: 30, end: 35, length: 5 });
    const got = receivedBytes(upload);
    assert.deepEqual(got.perFile, { 0: 20, 1: 5, 2: 0 });
    assert.equal(got.total, 25);
    assert.deepEqual(pendingWork(null), []);
});

test('retry: the network and server errors are retried, refusals and a full disk are not', () => {
    for (const s of [0, 408, 429, 500, 502, 503, 504]) assert.equal(shouldRetry(s), true, String(s));
    for (const s of [400, 401, 403, 404, 409, 413, 422, 507]) assert.equal(shouldRetry(s), false, String(s));
    assert.ok(MAX_ATTEMPTS >= 5);
});

test('backoff: doubles from 1 s up to 30 s with jitter; Retry-After wins', () => {
    const none = { random: () => 0.5 }; // no jitter
    assert.equal(backoffDelay(1, none), 1000);
    assert.equal(backoffDelay(2, none), 2000);
    assert.equal(backoffDelay(4, none), 8000);
    assert.equal(backoffDelay(10, none), 30000);
    assert.equal(backoffDelay(1, { random: () => 0 }), 750);
    assert.equal(backoffDelay(1, { random: () => 1 }), 1250);
    assert.equal(backoffDelay(3, { random: () => 0.5, retryAfter: '12' }), 12000);
    assert.equal(backoffDelay(3, { random: () => 0.5, retryAfter: 'soon' }), 4000);
});

test('resume state: saved, loaded, expired, cleared, and matched to the files picked again', () => {
    const storage = memoryStorage();
    const key = resumeKey(7);
    assert.equal(key, 'novarr.audiobookUpload.7');
    const picked = [
        { name: 'Book/01.mp3', size: 100, file: file('01.mp3', 100) },
        { name: 'Book/02.mp3', size: 200, file: file('02.mp3', 200) },
    ];
    const state = resumeState({ id: 42 }, picked, { novelId: 3, note: 'hi', now: 1000 });
    assert.deepEqual(state.files, [
        { name: 'Book/01.mp3', size: 100, modified: 1700000000000 },
        { name: 'Book/02.mp3', size: 200, modified: 1700000000000 },
    ]);
    assert.equal(saveResume(storage, key, state), true);
    assert.equal(loadResume(storage, key, { now: 2000 }).id, 42);
    assert.equal(loadResume(storage, key, { now: 1000 + 49 * 3600 * 1000 }), null, 'older than the server keeps it');

    // The same files, picked in another order: matched, in the upload's order.
    const again = [picked[1], picked[0]];
    assert.deepEqual(matchResume(state, again).map((f) => f.name), ['Book/01.mp3', 'Book/02.mp3']);
    // A different size, a missing file, a changed file: not the same upload.
    assert.equal(matchResume(state, [picked[0], { ...picked[1], size: 201 }]), null);
    assert.equal(matchResume(state, [picked[0]]), null);
    assert.equal(matchResume(state, [picked[0], { ...picked[1], file: file('02.mp3', 200, { lastModified: 5 }) }]), null);

    clearResume(storage, key);
    assert.equal(loadResume(storage, key), null);
    storage.setItem(key, '{not json');
    assert.equal(loadResume(storage, key), null);
    const broken = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); } };
    assert.equal(saveResume(broken, key, state), false);
    assert.equal(loadResume(broken, key), null);
    clearResume(broken, key);
});

test('progress: speed over a window, time left, percent and words', () => {
    const speed = speedTracker(10000);
    assert.equal(speed.rate(0), 0);
    speed.add(4 * MB, 0);
    speed.add(4 * MB, 2000);
    assert.equal(speed.rate(2000), (8 * MB * 1000) / 2000);
    speed.add(MB, 20000); // the old samples fall out of the window
    assert.equal(speed.rate(20000), MB);
    speed.reset();
    assert.equal(speed.rate(20000), 0);

    assert.equal(eta(100 * MB, 2 * MB), 50);
    assert.equal(eta(100, 0), null);
    assert.equal(percent(1, 3), 33);
    assert.equal(percent(5, 0), 0);
    assert.equal(percent(9, 3), 100);
    assert.equal(formatBytes(1536 * MB), '1.5 GB');
    assert.equal(formatBytes(2048 * MB), '2 GB');
    assert.equal(formatBytes(820 * MB), '820 MB');
    assert.equal(formatBytes(2048), '2 KB');
    assert.equal(formatBytes(12), '12 B');
    assert.equal(formatDuration(3900), '1 h 5 min');
    assert.equal(formatDuration(7200), '2 h');
    assert.equal(formatDuration(240), '4 min');
    assert.equal(formatDuration(35), '35 s');
    assert.equal(formatDuration(null), '—');
    assert.equal(statusLabel('pending'), 'Waiting for the owner');
    assert.equal(statusLabel('approved'), 'In the library');
});
