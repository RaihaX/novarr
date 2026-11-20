// Reading lists decisions (resources/js/lists-logic.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    MAX_NAME, STARTERS, cleanName, nameKey, nameError, sortLists, upsertList,
    startersFor, buttonLabel, toggleAnnouncement, urlWithoutList,
} from '../../resources/js/lists-logic.js';

test('names are cleaned like the server: trimmed, whitespace and control characters collapsed', () => {
    assert.equal(cleanName('  Summer \t  reads  '), 'Summer reads');
    assert.equal(cleanName('A\u0000B​C'), 'A B C');
    assert.equal(cleanName(null), '');
    assert.equal(cleanName(undefined), '');
    assert.equal(nameKey(' FAVOURITES '), 'favourites');
});

test('nameError words the server messages', () => {
    const lists = [{ id: 1, name: 'Favourites' }, { id: 2, name: 'To read' }];
    assert.equal(nameError('', lists), 'Give the list a name.');
    assert.equal(nameError('   ', lists), 'Give the list a name.');
    assert.equal(nameError('x'.repeat(MAX_NAME + 1), lists), 'A list name can be up to 40 characters.');
    // Length counts characters, not UTF-16 units.
    assert.equal(nameError('😀'.repeat(MAX_NAME), lists), null);
    assert.equal(nameError(' favourites ', lists), 'You already have a list called “favourites”.');
    assert.equal(nameError('New', lists), null);
});

test('a rename may keep its own name in another case', () => {
    const lists = [{ id: 1, name: 'Favourites' }, { id: 2, name: 'To read' }];
    assert.equal(nameError('FAVOURITES', lists, 1), null);
    assert.equal(nameError('to read', lists, 1), 'You already have a list called “to read”.');
});

test('lists sort by name case-insensitively, then id; upsert keeps that order', () => {
    const lists = [{ id: 3, name: 'b' }, { id: 1, name: 'B' }, { id: 2, name: 'a' }];
    assert.deepEqual(sortLists(lists).map((l) => l.id), [2, 1, 3]);
    assert.deepEqual(lists.map((l) => l.id), [3, 1, 2], 'does not mutate');

    const next = upsertList([{ id: 1, name: 'Favourites' }], { id: 5, name: 'Ahead' });
    assert.deepEqual(next.map((l) => l.name), ['Ahead', 'Favourites']);
    const renamed = upsertList(next, { id: 5, name: 'Zed' });
    assert.deepEqual(renamed.map((l) => l.name), ['Favourites', 'Zed']);
});

test('starters are offered in order, minus names already taken', () => {
    assert.deepEqual(startersFor([]), STARTERS);
    assert.deepEqual(startersFor([{ id: 1, name: 'to read' }]), ['Favourites', 'Finished']);
});

test('button label and announcements', () => {
    assert.equal(buttonLabel(0), 'Lists');
    assert.equal(buttonLabel(1), 'Lists, in 1 list');
    assert.equal(buttonLabel(3), 'Lists, in 3 lists');
    assert.equal(toggleAnnouncement('Favourites', true, true), 'Added to Favourites.');
    assert.equal(toggleAnnouncement('Favourites', false, true), 'Removed from Favourites.');
    assert.equal(toggleAnnouncement('Favourites', true, false), 'Could not add to Favourites.');
    assert.equal(toggleAnnouncement('Favourites', false, false), 'Could not remove from Favourites.');
});

test('urlWithoutList drops the list and page, keeps the rest', () => {
    assert.equal(urlWithoutList('https://n.test/novels?list=4&sort=name&page=3&medium=manga'), '/novels?sort=name&medium=manga');
    assert.equal(urlWithoutList('https://n.test/novels?list=4'), '/novels');
    assert.equal(urlWithoutList('https://n.test/novels?tag=2#top'), '/novels?tag=2#top');
});
