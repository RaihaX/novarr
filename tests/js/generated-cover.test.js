// "Generate a cover" decisions (resources/js/generated-cover-logic.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { RUNNING, GIVE_UP_AFTER, isRunning, pollDelay, statusText, freshUrl } from '../../resources/js/generated-cover-logic.js';

test('queued and painting are still going; done, failed and idle are not', () => {
    assert.deepEqual(RUNNING, ['queued', 'painting']);
    assert.equal(isRunning('queued'), true);
    assert.equal(isRunning('painting'), true);
    for (const state of ['done', 'failed', 'idle', undefined, null, '']) {
        assert.equal(isRunning(state), false, String(state));
    }
});

test('polls quickly at first, then backs off to at most 10 s', () => {
    assert.equal(pollDelay(0), 3000);
    assert.equal(pollDelay(1), 3000);
    assert.equal(pollDelay(2), 5000);
    assert.equal(pollDelay(4), 7000);
    assert.equal(pollDelay(50), 10000);
    assert.equal(pollDelay(-1), 3000);
    assert.equal(pollDelay('x'), 3000);
    assert.ok(GIVE_UP_AFTER >= 10 * 60 * 1000);
});

test('the line under the cover says what is happening', () => {
    assert.equal(statusText('queued'), 'Waiting for the GPU…');
    assert.equal(statusText('painting'), 'Painting… ~1 min');
    assert.equal(statusText('done'), 'New cover ready.');
    assert.equal(statusText('failed', 'The GPU is busy. Try again in a few minutes.'), 'The GPU is busy. Try again in a few minutes.');
    assert.equal(statusText('failed'), 'The cover could not be painted.');
    assert.equal(statusText('idle'), '');
});

test('the new cover URL gets a cache-buster', () => {
    assert.equal(freshUrl('/storage/cover-1.md.jpg', 42), '/storage/cover-1.md.jpg?v=42');
    assert.equal(freshUrl('/storage/c.jpg?x=1', 42), '/storage/c.jpg?x=1&v=42');
    assert.equal(freshUrl(null), null);
    assert.equal(freshUrl(''), null);
});
