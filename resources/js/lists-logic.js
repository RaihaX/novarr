/**
 * Reading lists — the pure decisions, no DOM: `yarn test:js`
 * (tests/js/lists.test.js). The DOM glue is resources/js/lists.js.
 *
 * Names follow the server (App\ReadingList::clean() / key(),
 * App\Services\ReadingLists): control characters and runs of whitespace
 * become one space, trimmed; unique per reader ignoring case; at most 40
 * characters. The server stays the judge — these only save a round trip and
 * word the same messages.
 */

export const MAX_NAME = 40;

/** The one-tap starters offered before a reader has any list. */
export const STARTERS = ['To read', 'Finished'];

/** The built-in lists' names (App\ReadingList::BUILT_IN): reserved. */
export const BUILT_IN_NAMES = ['Want to read', 'Favourites'];

/** "  Summer \t reads " → "Summer reads". */
export function cleanName(name) {
    return String(name ?? '')
        // Control and format characters (as the server's \p{Cc}\p{Cf}).
        .replace(/[\p{Cc}\p{Cf}]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/** The uniqueness key: the clean name, lowercased. */
export function nameKey(name) {
    return cleanName(name).toLowerCase();
}

/**
 * Why a name cannot be used (the server's wording), or null.
 * lists: [{id, name}]; exceptId: the list being renamed (its own name is fine).
 */
export function nameError(name, lists = [], exceptId = null) {
    const clean = cleanName(name);
    if (clean === '') return 'Give the list a name.';
    if ([...clean].length > MAX_NAME) return `A list name can be up to ${MAX_NAME} characters.`;
    const key = clean.toLowerCase();
    // The built-in lists' names (App\ReadingList::BUILT_IN) are reserved.
    const builtIn = BUILT_IN_NAMES.find((n) => nameKey(n) === key);
    if (builtIn) return `“${builtIn}” is already one of your lists.`;
    const taken = lists.some((l) => l.id !== exceptId && nameKey(l.name) === key);
    return taken ? `You already have a list called “${clean}”.` : null;
}

/** Lists by name, case-insensitively, then id (the API's order). Returns a new array. */
export function sortLists(lists) {
    return [...lists].sort((a, b) => {
        const ka = nameKey(a.name);
        const kb = nameKey(b.name);
        if (ka !== kb) return ka < kb ? -1 : 1;
        return a.id - b.id;
    });
}

/** lists with `list` added or replaced (by id), in the API's order. */
export function upsertList(lists, list) {
    return sortLists([...lists.filter((l) => l.id !== list.id), list]);
}

/** The starters a reader without lists is offered (those not taken, in order). */
export function startersFor(lists) {
    const taken = new Set(lists.map((l) => nameKey(l.name)));
    return STARTERS.filter((s) => !taken.has(nameKey(s)));
}

/** The Categories button's accessible name: "Categories" or "Categories, in 2 categories". */
export function buttonLabel(inCount) {
    if (!inCount) return 'Categories';
    return `Categories, in ${inCount} ${inCount === 1 ? 'category' : 'categories'}`;
}

/** The screen-reader line after a tick was saved or failed. */
export function toggleAnnouncement(name, on, ok) {
    if (!ok) return on ? `Could not add to ${name}.` : `Could not remove from ${name}.`;
    return on ? `Added to ${name}.` : `Removed from ${name}.`;
}

/**
 * A Library URL without the list filter (and its page): where to go after
 * the list was deleted. Keeps every other parameter.
 */
export function urlWithoutList(href) {
    const url = new URL(href);
    url.searchParams.delete('list');
    url.searchParams.delete('page');
    return url.pathname + (url.search ? url.search : '') + url.hash;
}
