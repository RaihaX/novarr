// Categories decisions (resources/js/categories-logic.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    ON, OFF, MIXED, triState, nextState, membershipDiff, chosenIds, rowsForResult, categoriesOf,
    isTaken, badgeText, appliedLine, markedLine, HOME_OPTIONAL, parseHomeToggles, setHomeToggle,
} from '../../resources/js/categories-logic.js';

const lists = [
    { id: 1, name: 'Want to read', kind: 'want_to_read', novel_ids: [10] },
    { id: 2, name: 'Manhwa', kind: null, novel_ids: [10, 11] },
    { id: 3, name: 'Later', kind: null, novel_ids: [] },
];

test('the sheet is tri-state for a selection: on when all are in, mixed when some are', () => {
    assert.deepEqual(triState(lists, [10, 11]).map((r) => [r.id, r.state]), [[1, MIXED], [2, ON], [3, OFF]]);
    assert.deepEqual(triState(lists, [10]).map((r) => r.state), [ON, ON, OFF]);
    // Duplicates and strings in the selection count once.
    assert.deepEqual(triState(lists, ['11', 11]).map((r) => r.state), [OFF, ON, OFF]);
    // An empty selection ticks nothing.
    assert.deepEqual(triState(lists, []).map((r) => r.state), [OFF, OFF, OFF]);
    assert.deepEqual(triState(null, [1]), []);
    assert.equal(triState(lists, [10])[0].kind, 'want_to_read');
});

test('a tap turns off → on → off, and mixed → on first', () => {
    assert.equal(nextState(OFF), ON);
    assert.equal(nextState(ON), OFF);
    assert.equal(nextState(MIXED), ON);
});

test('only changed rows are sent; a row left mixed changes nothing', () => {
    const initial = triState(lists, [10, 11]); // 1 mixed, 2 on, 3 off
    const chosen = initial.map((r) => ({ ...r }));
    assert.deepEqual(membershipDiff(initial, chosen), { add: [], remove: [] });
    chosen[0].state = ON;   // mixed → on: add to all
    chosen[1].state = OFF;  // on → off: remove
    chosen[2].state = ON;   // off → on: add
    assert.deepEqual(membershipDiff(initial, chosen), { add: [1, 3], remove: [2] });
    // A category made in the sheet (not in initial) and ticked is added.
    assert.deepEqual(membershipDiff(initial, [...initial, { id: 9, state: ON }]), { add: [9], remove: [] });
    assert.deepEqual(chosenIds(chosen), [1, 3]);
});

test('a Browse card is ticked and greyed from the categories it is in or was asked for in', () => {
    const inLibrary = { in_library: true, novel_id: 10, in_categories: [2], requested_categories: [], requested: null };
    const requested = { in_library: false, novel_id: null, in_categories: [], requested_categories: [3, 2], requested: { status: 'pending', by_me: true } };
    const fresh = { in_library: false, novel_id: null, in_categories: [], requested_categories: [], requested: null };
    const othersRequest = { ...fresh, requested: { status: 'pending', by_me: false } };

    assert.deepEqual(rowsForResult(lists, inLibrary).map((r) => r.state), [OFF, ON, OFF]);
    assert.deepEqual(rowsForResult(lists, requested).map((r) => r.state), [OFF, ON, ON]);
    assert.deepEqual(categoriesOf(requested), [3, 2]);
    // Old servers send neither field.
    assert.deepEqual(categoriesOf({ in_library: true }), []);

    assert.equal(isTaken(inLibrary), true);
    assert.equal(isTaken(requested), true);
    assert.equal(isTaken(fresh), false);
    assert.equal(isTaken(othersRequest), false, 'someone else asked: still mine to take');
    assert.equal(isTaken({ ...fresh, requested: { status: 'pending', by_me: true } }), true, 'requested without categories');

    assert.equal(badgeText(inLibrary, lists), 'In Manhwa');
    assert.equal(badgeText(requested, lists), 'Requested · Later +1');
    assert.equal(badgeText({ ...fresh, requested: { status: 'pending', by_me: true } }, lists), 'Requested');
    assert.equal(badgeText(fresh, lists), null);
    // In the library but in no category of mine (and not requested by me): not taken.
    assert.equal(badgeText({ in_library: true, in_categories: [] }, lists), null);
    // Names not loaded yet: the plain state.
    assert.equal(badgeText(requested, []), 'Requested');
});

test('toasts', () => {
    assert.equal(appliedLine(3, { add: [], remove: [] }), 'Nothing changed.');
    assert.equal(appliedLine(3, { add: [1], remove: [] }), 'Updated 3 series.');
    assert.equal(markedLine(true, 2), 'Marked 2 series as read.');
    assert.equal(markedLine(false, 1), 'Marked 1 series as unread.');
});

test('Home toggles: all off by default, unreadable storage is all off', () => {
    const off = Object.fromEntries(HOME_OPTIONAL.map((k) => [k, false]));
    assert.deepEqual(parseHomeToggles(null), off);
    assert.deepEqual(parseHomeToggles('not json'), off);
    assert.deepEqual(parseHomeToggles('[true]'), off);
    assert.deepEqual(parseHomeToggles('{"favourites": true, "continue": "yes", "other": true}'), { ...off, favourites: true });
    const on = setHomeToggle(off, 'continue', true);
    assert.equal(on.continue, true);
    assert.equal(off.continue, false, 'not changed in place');
    assert.deepEqual(setHomeToggle(off, 'nope', true), off);
});
