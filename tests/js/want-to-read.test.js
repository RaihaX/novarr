// "Want to read" decisions (resources/js/want-to-read-logic.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { WANT_TO_READ, wantRequest, wantAnnouncement, wantTitle } from '../../resources/js/want-to-read-logic.js';
import { nameError } from '../../resources/js/lists-logic.js';

test('the toggle puts a series on with PUT and takes it off with DELETE', () => {
    assert.deepEqual(wantRequest(12, true), { method: 'PUT', url: '/api/v1/novels/12/want-to-read' });
    assert.deepEqual(wantRequest('12', false), { method: 'DELETE', url: '/api/v1/novels/12/want-to-read' });
});

test('no call for a missing or bad series id', () => {
    for (const bad of [undefined, null, '', 'abc', 0, -3, 1.5]) {
        assert.equal(wantRequest(bad, true), null, String(bad));
    }
});

test('announcements and tooltips name the list', () => {
    assert.equal(WANT_TO_READ, 'Want to read');
    assert.equal(wantAnnouncement(true, 'Mother of Learning'), '“Mother of Learning” added to Want to read.');
    assert.equal(wantAnnouncement(false, 'Mother of Learning'), '“Mother of Learning” removed from Want to read.');
    assert.equal(wantAnnouncement(true, ''), 'Added to Want to read.');
    assert.match(wantTitle(true), /remove/);
    assert.match(wantTitle(false), /^Add/);
});

test('the built-in list name is reserved for new lists, whatever the case or spacing', () => {
    assert.equal(nameError('Want to read'), '“Want to read” is already one of your lists.');
    assert.equal(nameError('  WANT   to read '), '“Want to read” is already one of your lists.');
    assert.equal(nameError('Want to read later'), null);
});
