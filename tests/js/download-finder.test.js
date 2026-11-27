// "Search Usenet & torrents" decisions (resources/js/download-finder-logic.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    isActive, searchBody, humanSize, ageOrSeeders, grabButton, autoNote, statusLine, pollDelay, eta,
} from '../../resources/js/download-finder-logic.js';

test('only unfinished grabs are polled', () => {
    for (const s of ['queued', 'downloading', 'completed']) assert.equal(isActive(s), true, s);
    for (const s of ['imported', 'failed', '', undefined]) assert.equal(isActive(s), false, String(s));
});

test('the search body carries only what is set', () => {
    assert.deepEqual(searchBody({ kind: 'ebook', q: '  Orchard ', requestId: '12', novelId: '' }), { kind: 'ebook', q: 'Orchard', request_id: 12 });
    assert.deepEqual(searchBody({ kind: 'audiobook', q: '', requestId: undefined, novelId: 7 }), { kind: 'audiobook', novel_id: 7 });
    assert.deepEqual(searchBody({ kind: 'weird', q: null, requestId: 'x', novelId: -1 }), { kind: 'ebook' });
    assert.deepEqual(searchBody({ kind: 'comic', q: 'Saga v01', requestId: '', novelId: 3 }), { kind: 'comic', q: 'Saga v01', novel_id: 3 });
    assert.equal(statusLine({ status: 'completed', kind: 'comic', progress: 100 }), 'Finished — waiting for the comics scan');
});

test('sizes read like the server writes them', () => {
    assert.equal(humanSize(0), '0 B');
    assert.equal(humanSize(1023), '1023 B');
    assert.equal(humanSize(1_200_000), '1.1 MB');
    assert.equal(humanSize(1024 * 1024 * 1024 * 1.5), '1.5 GB');
});

test('torrents show seeders, usenet posts their age', () => {
    assert.equal(ageOrSeeders({ protocol: 'torrent', seeders: 12 }), '12 seeders');
    assert.equal(ageOrSeeders({ protocol: 'torrent', seeders: 1 }), '1 seeder');
    assert.equal(ageOrSeeders({ protocol: 'torrent', seeders: null }), 'seeders unknown');
    assert.equal(ageOrSeeders({ protocol: 'usenet', age_days: 0 }), 'today');
    assert.equal(ageOrSeeders({ protocol: 'usenet', age_days: 3 }), '3 days old');
    assert.equal(ageOrSeeders({ protocol: 'usenet', age_days: 800 }), '2 years old');
});

test('the grab button says why it cannot grab, and warns on a blocklisted release', () => {
    assert.deepEqual(grabButton({ grabbable: false, not_grabbable: 'Transmission is not set up.' }), { disabled: true, label: 'Grab', title: 'Transmission is not set up.' });
    assert.equal(grabButton({ grabbable: true, blocklisted: true }).label, 'Grab anyway');
    assert.match(grabButton({ grabbable: true, protocol: 'torrent' }).title, /Transmission/);
    assert.match(grabButton({ grabbable: true, protocol: 'usenet' }).title, /NZBGet/);
});

test('the automation note', () => {
    assert.equal(autoNote({ auto: 'ok' }), 'Clear match');
    assert.equal(autoNote({ auto: 'pdf is not an allowed format' }), 'Not automatic: pdf is not an allowed format');
    assert.equal(autoNote({}), '');
});

test('status lines', () => {
    assert.equal(statusLine({ status_line: 'From the server' }), 'From the server');
    assert.equal(statusLine({ status: 'downloading', progress: 43 }), 'Downloading 43%');
    assert.equal(statusLine({ status: 'imported', kind: 'ebook' }), 'Finished — ready to review');
    assert.equal(statusLine({ status: 'failed', error: 'FAILURE/PAR' }), 'Failed: FAILURE/PAR');
});

test('polling slows down, and ETAs read plainly', () => {
    assert.equal(pollDelay(0), 3000);
    assert.ok(pollDelay(5) > pollDelay(1));
    assert.equal(pollDelay(1000), 30000);
    assert.equal(eta(null), '');
    assert.equal(eta(-1), '');
    assert.equal(eta(30), 'ETA under a minute');
    assert.equal(eta(600), 'ETA 10 min');
    assert.equal(eta(3600 * 2 + 600), 'ETA 2 h 10 min');
});
