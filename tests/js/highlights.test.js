// Reader highlights and next-chapter pieces — run with `yarn test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { normaliseWhitespace, locateExcerpt, partsForRange, matchExcerpt } from '../../resources/js/reader/excerpt-match.js';
import { nextReady, inSwipeEdge, inHorizontalScroller, SWIPE_EDGE_PX } from '../../resources/js/reader/navigation.js';
import { readThroughMessage, chapterCount, READ_THROUGH_CONFIRM_OVER } from '../../resources/js/reader/readstate.js';

// Pieces as the reader builds them from the DOM: one per text node, plus a
// virtual break where a paragraph (or <br>) starts.
const t = (text) => ({ text });
const br = () => ({ text: '\n', virtual: true });
/** The covered text of each part, for readable assertions. */
const covered = (pieces, parts) => parts.map(({ index, from, to }) => pieces[index].text.slice(from, to));

// ---- normaliseWhitespace / locateExcerpt ----

test('whitespace is collapsed and trimmed', () => {
    assert.equal(normaliseWhitespace('  a \n\n b  c\t'), 'a b c');
    assert.equal(normaliseWhitespace(null), '');
});

test('an excerpt inside a single text node', () => {
    const text = 'The rain fell on the quiet town.';
    assert.deepEqual(locateExcerpt(text, 'on the quiet'), { start: 14, end: 26 });
    const pieces = [t(text)];
    const m = matchExcerpt(pieces, 'on the quiet');
    assert.deepEqual(m.parts, [{ index: 0, from: 14, to: 26 }]);
    assert.deepEqual(covered(pieces, m.parts), ['on the quiet']);
});

test('an excerpt spanning inline elements (<em>, <strong>)', () => {
    // <p>He said <em>never</em> again, <strong>loudly</strong>.</p>
    const pieces = [t('He said '), t('never'), t(' again, '), t('loudly'), t('.')];
    const m = matchExcerpt(pieces, 'said never again, loud');
    assert.deepEqual(covered(pieces, m.parts), ['said ', 'never', ' again, ', 'loud']);
    assert.deepEqual(m.parts.map((p) => p.index), [0, 1, 2, 3]);
});

test('an excerpt spanning two paragraphs (the selection put a blank line between them)', () => {
    // <p>End of one.</p>\n<p>Start of two.</p>
    const pieces = [t('End of one.'), br(), t('\n'), br(), t('Start of two.')];
    const m = matchExcerpt(pieces, 'of one.\n\nStart of');
    assert.ok(m, 'found across the paragraph break');
    // The whitespace-only node between the paragraphs and the virtual
    // breaks are not wrapped.
    assert.deepEqual(covered(pieces, m.parts), ['of one.', 'Start of']);
    assert.deepEqual(m.parts.map((p) => p.index), [0, 4]);
});

test('paragraphs with no whitespace node between them still match', () => {
    const pieces = [t('Alpha beta.'), br(), t('Gamma delta.')];
    assert.deepEqual(covered(pieces, matchExcerpt(pieces, 'beta.\nGamma').parts), ['beta.', 'Gamma']);
});

test('whitespace differences: runs, newlines, NBSP and indentation', () => {
    const pieces = [t('She  waited,\n      and then   she left.')];
    const m = matchExcerpt(pieces, 'waited, and then she');
    assert.deepEqual(covered(pieces, m.parts), ['waited,\n      and then   she']);
    // The excerpt has the extra whitespace instead.
    const p2 = [t('waited, and then')];
    assert.deepEqual(covered(p2, matchExcerpt(p2, '  waited,\n\n and   then ').parts), ['waited, and then']);
});

test('whitespace lost on one side still matches (last resort: whitespace ignored)', () => {
    // A <br> split with no break recorded, or an excerpt from another app.
    const pieces = [t('line one'), t('line two')];
    assert.deepEqual(covered(pieces, matchExcerpt(pieces, 'one line two').parts), ['one', 'line two']);
});

test('an excerpt that is not in the text is null (skipped silently)', () => {
    const pieces = [t('Nothing to see here.')];
    assert.equal(matchExcerpt(pieces, 'something else'), null);
    assert.equal(matchExcerpt(pieces, ''), null);
    assert.equal(matchExcerpt(pieces, '   \n'), null);
    assert.equal(matchExcerpt([], 'anything'), null);
    assert.equal(locateExcerpt('abc', 'abcd'), null);
});

test('the first occurrence is used', () => {
    const pieces = [t('echo, echo, echo')];
    assert.deepEqual(matchExcerpt(pieces, 'echo').parts, [{ index: 0, from: 0, to: 4 }]);
});

test('two overlapping highlights are each found over the same text', () => {
    const pieces = [t('The quick '), t('brown'), t(' fox jumps over the lazy dog.')];
    const a = matchExcerpt(pieces, 'quick brown fox');
    const b = matchExcerpt(pieces, 'brown fox jumps');
    assert.deepEqual(covered(pieces, a.parts), ['quick ', 'brown', ' fox']);
    assert.deepEqual(covered(pieces, b.parts), ['brown', ' fox jumps']);
    // Same offsets in the joined text where they overlap ("brown fox").
    assert.ok(a.start < b.start && b.start < a.end && a.end < b.end);

    // Drawn one after the other: wrapping A splits the text nodes but not
    // the text, so B is still found against the split pieces.
    const split = [t('The '), t('quick '), t('brown'), t(' fox'), t(' jumps over the lazy dog.')];
    assert.deepEqual(covered(split, matchExcerpt(split, 'brown fox jumps').parts), ['brown', ' fox', ' jumps']);
});

test('partsForRange skips virtual and whitespace-only pieces', () => {
    const pieces = [t('ab'), br(), t('  '), t('cd')];
    assert.deepEqual(partsForRange(pieces, 1, 7), [{ index: 0, from: 1, to: 2 }, { index: 3, from: 0, to: 2 }]);
    assert.deepEqual(partsForRange(pieces, 2, 5), []);
});

// ---- Next chapter not downloaded ----

test('nextReady: missing, pending and downloaded neighbours', () => {
    assert.equal(nextReady(null), false);
    assert.equal(nextReady(undefined), false);
    assert.equal(nextReady({ id: 2, url: '/chapters/2', downloaded: false }), false);
    assert.equal(nextReady({ id: 2, url: '/chapters/2', downloaded: true }), true);
    // A page cached before the flag existed: still navigable, as before.
    assert.equal(nextReady({ id: 2, url: '/chapters/2' }), true);
});

// ---- Swipe ----

test('swipes starting at the screen edge belong to the browser', () => {
    assert.equal(SWIPE_EDGE_PX, 24);
    assert.equal(inSwipeEdge(0, 400), true);
    assert.equal(inSwipeEdge(23, 400), true);
    assert.equal(inSwipeEdge(24, 400), false);
    assert.equal(inSwipeEdge(200, 400), false);
    assert.equal(inSwipeEdge(376, 400), false);
    assert.equal(inSwipeEdge(377, 400), true);
});

test('swipes starting inside a horizontally scrollable box are ignored', () => {
    const body = { nodeType: 1, scrollWidth: 400, clientWidth: 400, parentElement: null, style: { overflowX: 'visible' } };
    const content = { nodeType: 1, scrollWidth: 400, clientWidth: 400, parentElement: body, style: { overflowX: 'visible' } };
    const pre = { nodeType: 1, scrollWidth: 900, clientWidth: 380, parentElement: content, style: { overflowX: 'auto' } };
    const code = { nodeType: 1, scrollWidth: 900, clientWidth: 900, parentElement: pre, style: { overflowX: 'visible' } };
    const clipped = { nodeType: 1, scrollWidth: 900, clientWidth: 380, parentElement: content, style: { overflowX: 'hidden' } };
    const getStyle = (n) => n.style;
    assert.equal(inHorizontalScroller(code, body, getStyle), true);
    assert.equal(inHorizontalScroller(pre, body, getStyle), true);
    assert.equal(inHorizontalScroller(content, body, getStyle), false);
    // Overflowing but not scrollable (hidden) is not a scroller.
    assert.equal(inHorizontalScroller(clipped, body, getStyle), false);
});

// ---- "Mark to here" ----

test('"Mark to here" says how many chapters it marked', () => {
    assert.equal(READ_THROUGH_CONFIRM_OVER, 20);
    assert.equal(chapterCount(1), '1 chapter');
    assert.equal(chapterCount(21), '21 chapters');
    assert.equal(readThroughMessage(1), 'Marked 1 chapter as read.');
    assert.equal(readThroughMessage(37), 'Marked 37 chapters as read.');
    assert.match(readThroughMessage(0), /already read/);
});
