/**
 * Categories (Mihon-style, docs/plans/categories-and-home.md) — the pure
 * decisions, no DOM: `yarn test:js` (tests/js/categories.test.js). The DOM
 * glue is resources/js/categories.js (the "Set categories" sheet, the
 * Library's Select mode actions), resources/js/requests.js (long-press /
 * right-click a Browse card) and resources/js/home-layout.js (Home's
 * toggles).
 *
 * Categories are the reader's reading lists (GET /api/v1/lists: {id, name,
 * count, novel_ids, kind, position, hidden_on_home}), already in the
 * reader's order.
 */

export const ON = 'on';
export const OFF = 'off';
export const MIXED = 'mixed';

/**
 * The sheet's rows for a selection of series: every category (hidden
 * built-ins too), ticked when every selected series is in it, mixed when
 * some are. lists: the API's lists; novelIds: the selection.
 * → [{id, name, kind, state}]
 */
export function triState(lists, novelIds) {
    const ids = [...new Set((novelIds || []).map(Number))];
    return (lists || []).map((list) => {
        const members = new Set((list.novel_ids || []).map(Number));
        const inCount = ids.filter((id) => members.has(id)).length;
        const state = ids.length > 0 && inCount === ids.length ? ON : (inCount > 0 ? MIXED : OFF);
        return { id: Number(list.id), name: String(list.name), kind: list.kind ?? null, state };
    });
}

/**
 * A tap on a row: off → on → off; a mixed row becomes on first (as Mihon),
 * then off. (Mixed is only ever the starting state.)
 */
export function nextState(state) {
    return state === ON ? OFF : ON;
}

/**
 * What to send (PUT /api/v1/lists/memberships): the rows whose state
 * changed from the start. A row left mixed changes nothing.
 * initial, chosen: [{id, state}] → {add: [ids], remove: [ids]}
 */
export function membershipDiff(initial, chosen) {
    const before = new Map((initial || []).map((r) => [Number(r.id), r.state]));
    const add = [];
    const remove = [];
    for (const row of chosen || []) {
        const was = before.get(Number(row.id)) ?? OFF;
        if (row.state === was || row.state === MIXED) continue;
        if (row.state === ON) add.push(Number(row.id));
        else remove.push(Number(row.id));
    }
    return { add, remove };
}

/** Ticked ids of a sheet (for a request: categories to remember). */
export function chosenIds(rows) {
    return (rows || []).filter((r) => r.state === ON).map((r) => Number(r.id));
}

/** The sheet's rows for a Browse card: ticked from its in_categories / requested_categories. */
export function rowsForResult(lists, result) {
    const ticked = new Set(categoriesOf(result));
    return (lists || []).map((list) => ({
        id: Number(list.id), name: String(list.name), kind: list.kind ?? null, state: ticked.has(Number(list.id)) ? ON : OFF,
    }));
}

/** The categories a Browse result is in (library) or was asked for in (request). */
export function categoriesOf(result) {
    if (!result) return [];
    const ids = result.in_library ? result.in_categories : result.requested_categories;
    return Array.isArray(ids) ? ids.map(Number) : [];
}

/**
 * A Browse card is greyed out once the reader has taken it: in one of their
 * categories, or requested by them (or joined).
 */
export function isTaken(result) {
    if (!result) return false;
    return categoriesOf(result).length > 0 || Boolean(result.requested && result.requested.by_me);
}

/**
 * The small badge on a taken card: "In Manhwa", "Requested · Manhwa",
 * "Requested · Manhwa +1", "Requested"; null when not taken.
 */
export function badgeText(result, lists) {
    if (!isTaken(result)) return null;
    const names = new Map((lists || []).map((l) => [Number(l.id), String(l.name)]));
    const cats = categoriesOf(result).map((id) => names.get(id)).filter(Boolean);
    const first = cats.length ? cats[0] + (cats.length > 1 ? ` +${cats.length - 1}` : '') : '';
    if (result.in_library) return first ? `In ${first}` : null;
    return first ? `Requested · ${first}` : 'Requested';
}

/** "Set categories" toast: "Updated 3 series." / "Nothing changed." */
export function appliedLine(count, diff) {
    if (!diff || (diff.add.length === 0 && diff.remove.length === 0)) return 'Nothing changed.';
    return `Updated ${count} series.`;
}

/** Mark read / unread toast. */
export function markedLine(read, count) {
    return `Marked ${count} series as ${read ? 'read' : 'unread'}.`;
}

// ---------------------------------------------------------------------------
// Home layout: the shelves Home had before categories, each off by default
// once the reader has categories, turned back on per browser.
// ---------------------------------------------------------------------------

export const HOME_OPTIONAL = ['continue', 'listening', 'favourites', 'want_to_read'];

export const HOME_STORAGE_KEY = 'novarr.homeShelves';

/** The stored toggles (a JSON object) → {continue: bool, …}; anything unreadable is all off. */
export function parseHomeToggles(raw) {
    let stored = {};
    try { stored = JSON.parse(raw || '{}') || {}; } catch (_) { stored = {}; }
    if (typeof stored !== 'object' || Array.isArray(stored)) stored = {};
    return Object.fromEntries(HOME_OPTIONAL.map((key) => [key, stored[key] === true]));
}

/** The toggles with one changed, as stored. */
export function setHomeToggle(toggles, key, on) {
    if (!HOME_OPTIONAL.includes(key)) return { ...toggles };
    return { ...toggles, [key]: Boolean(on) };
}
