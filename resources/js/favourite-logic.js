/**
 * "Favourites" (the second built-in reading list, the heart) — the pure
 * decisions, no DOM: `yarn test:js` (tests/js/favourites.test.js). The DOM
 * glue is resources/js/want-to-read.js (both built-in toggles); the server
 * is App\Services\ReadingLists.
 */

/** Its name, as the server shows it (App\ReadingList::FAVOURITES_NAME). */
export const FAVOURITES = 'Favourites';

/** The call that puts a series in Favourites (on) or takes it out. */
export function favouriteRequest(novelId, on) {
    const id = Number(novelId);
    if (!Number.isInteger(id) || id <= 0) return null;
    return { method: on ? 'PUT' : 'DELETE', url: `/api/v1/novels/${id}/favourite` };
}

/** What a screen reader hears once the change is saved. */
export function favouriteAnnouncement(on, name) {
    const verb = on ? `added to ${FAVOURITES}.` : `removed from ${FAVOURITES}.`;
    return name ? `“${name}” ${verb}` : verb.charAt(0).toUpperCase() + verb.slice(1);
}

/** The heart's accessible name for its state (aria-pressed says it too). */
export function favouriteLabel(on) {
    return on ? 'Remove from favourites' : 'Favourite';
}

/** The heart's tooltip for its state. */
export function favouriteTitle(on) {
    return on ? `In your ${FAVOURITES} — click to remove it` : `Add to your ${FAVOURITES}`;
}
