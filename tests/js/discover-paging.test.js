// Discover "Load more" paging (resources/js/discover-paging.js) — `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { AUTO_PAGES, freshItems, moreAction, showMore, keepFetching } from '../../resources/js/discover-paging.js';

test('freshItems drops urls seen on earlier pages and repeats within the page', () => {
    const seen = new Set(['https://x/a', 'https://x/b']);
    const fresh = freshItems([
        { url: 'https://x/b', name: 'B again' },
        { url: 'https://x/c', name: 'C' },
        { url: 'https://x/c', name: 'C twice' },
        { name: 'no url' },
        { url: 'https://x/d', name: 'D' },
    ], seen);
    assert.deepEqual(fresh.map(i => i.name), ['C', 'no url', 'D']);
    assert.deepEqual([...seen], ['https://x/a', 'https://x/b', 'https://x/c', 'https://x/d']);
    assert.deepEqual(freshItems(undefined, seen), []);
    assert.deepEqual(freshItems([{ url: 'https://x/c' }], seen), [], 'a later page repeating it adds nothing');
});

test('moreAction reveals fetched items first, then fetches while the source has more', () => {
    assert.equal(moreAction(5, true), 'reveal');
    assert.equal(moreAction(5, false), 'reveal');
    assert.equal(moreAction(0, true), 'fetch');
    assert.equal(moreAction(0, false), 'none');
    assert.equal(showMore(0, false), false);
    assert.equal(showMore(0, true), true);
    assert.equal(showMore(3, false), true);
});

test('keepFetching follows empty pages, at most AUTO_PAGES per click', () => {
    assert.equal(AUTO_PAGES, 3);
    assert.equal(keepFetching(0, true, 1), true);
    assert.equal(keepFetching(0, true, 2), true);
    assert.equal(keepFetching(0, true, 3), false, 'stops after three pages');
    assert.equal(keepFetching(2, true, 1), false, 'a page that added items ends the click');
    assert.equal(keepFetching(0, false, 1), false, 'nothing more to fetch');
});
