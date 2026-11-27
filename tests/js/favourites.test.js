// "Favourites" decisions (resources/js/favourite-logic.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FAVOURITES, favouriteRequest, favouriteAnnouncement, favouriteLabel, favouriteTitle } from '../../resources/js/favourite-logic.js';
import { nameError, STARTERS, BUILT_IN_NAMES } from '../../resources/js/lists-logic.js';

test('the heart puts a series in with PUT and takes it out with DELETE', () => {
    assert.deepEqual(favouriteRequest(12, true), { method: 'PUT', url: '/api/v1/novels/12/favourite' });
    assert.deepEqual(favouriteRequest('12', false), { method: 'DELETE', url: '/api/v1/novels/12/favourite' });
});

test('no call for a missing or bad series id', () => {
    for (const bad of [undefined, null, '', 'abc', 0, -3, 1.5]) {
        assert.equal(favouriteRequest(bad, true), null, String(bad));
    }
});

test('labels, tooltips and announcements', () => {
    assert.equal(FAVOURITES, 'Favourites');
    assert.equal(favouriteLabel(false), 'Favourite');
    assert.equal(favouriteLabel(true), 'Remove from favourites');
    assert.match(favouriteTitle(true), /remove/);
    assert.match(favouriteTitle(false), /^Add/);
    assert.equal(favouriteAnnouncement(true, 'Mother of Learning'), '“Mother of Learning” added to Favourites.');
    assert.equal(favouriteAnnouncement(false, 'Mother of Learning'), '“Mother of Learning” removed from Favourites.');
    assert.equal(favouriteAnnouncement(false, ''), 'Removed from Favourites.');
});

test('"Favourites" is reserved like "Want to read" and no longer a starter', () => {
    assert.deepEqual(BUILT_IN_NAMES, ['Want to read', 'Favourites']);
    assert.equal(nameError('Favourites'), '“Favourites” is already one of your lists.');
    assert.equal(nameError('  FAVOURITES '), '“Favourites” is already one of your lists.');
    assert.equal(nameError('Favourites 2'), null);
    assert.ok(!STARTERS.includes('Favourites'));
});
