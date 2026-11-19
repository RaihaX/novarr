// Finding a saved highlight's excerpt in a chapter's text — pure functions,
// no DOM (unit-tested in tests/js/highlights.test.js).
//
// A highlight is stored as the selected text only (App\Bookmark: excerpt +
// note, no offsets), so drawing it means finding that text again. The text
// of a chapter is handed in as "pieces": one per DOM text node, in document
// order, plus virtual separators (`virtual: true`) where a paragraph or a
// <br> breaks the text, because Selection.toString() put a newline there
// that no text node contains. Whitespace is compared loosely: any run of
// whitespace matches any other, and as a last resort whitespace is ignored
// altogether (an excerpt saved by another app may have lost or gained it).

const WS = /\s/;

/** Collapse whitespace runs to one space and trim. */
export function normaliseWhitespace(s) {
    return String(s ?? '').replace(/\s+/g, ' ').trim();
}

/**
 * `text` with whitespace runs collapsed to one space (`collapse`) or removed,
 * and per kept character the index in `text` it came from.
 */
function reduce(text, collapse) {
    let out = '';
    const map = [];
    let lastWasSpace = true;   // drops leading whitespace
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (WS.test(ch)) {
            if (!collapse || lastWasSpace) continue;
            out += ' ';
            map.push(i);
            lastWasSpace = true;
            continue;
        }
        out += ch;
        map.push(i);
        lastWasSpace = false;
    }
    return { out, map };
}

/**
 * Where `excerpt` first occurs in `text`, as [start, end) offsets into
 * `text`; null when it does not occur (or is empty). Whitespace differences
 * are tolerated (see the file comment).
 *
 * @returns {{start: number, end: number} | null}
 */
export function locateExcerpt(text, excerpt) {
    text = String(text ?? '');
    for (const collapse of [true, false]) {
        const needle = collapse ? normaliseWhitespace(excerpt) : String(excerpt ?? '').replace(/\s+/g, '');
        if (!needle) return null;
        const { out, map } = reduce(text, collapse);
        const at = out.indexOf(needle);
        if (at < 0) continue;
        return { start: map[at], end: map[at + needle.length - 1] + 1 };
    }
    return null;
}

/**
 * The parts of `pieces` that [start, end) of their joined text covers: one
 * { index, from, to } per real (non-virtual) piece, offsets within that
 * piece. Parts that are only whitespace are left out (wrapping the newline
 * between two paragraphs would draw nothing anyway).
 *
 * @param {{text: string, virtual?: boolean}[]} pieces
 */
export function partsForRange(pieces, start, end) {
    const parts = [];
    let offset = 0;
    pieces.forEach((piece, index) => {
        const len = piece.text.length;
        const from = Math.max(start, offset) - offset;
        const to = Math.min(end, offset + len) - offset;
        if (!piece.virtual && to > from && piece.text.slice(from, to).trim() !== '') {
            parts.push({ index, from, to });
        }
        offset += len;
    });
    return parts;
}

/**
 * Locate `excerpt` in `pieces`: the parts to wrap, or null when it is not
 * there (the caller skips it silently).
 */
export function matchExcerpt(pieces, excerpt) {
    const text = pieces.map((p) => p.text).join('');
    const hit = locateExcerpt(text, excerpt);
    if (!hit) return null;
    const parts = partsForRange(pieces, hit.start, hit.end);
    return parts.length ? { ...hit, parts } : null;
}
