/**
 * "Want to read" (the built-in reading list) — the pure decisions, no DOM:
 * `yarn test:js` (tests/js/want-to-read.test.js). The DOM glue is
 * resources/js/want-to-read.js; the server is App\Services\ReadingLists.
 */

/** Its name, as the server shows it (App\ReadingList::WANT_TO_READ_NAME). */
export const WANT_TO_READ = 'Want to read';

/** The call that puts a series on the list (want) or takes it off. */
export function wantRequest(novelId, want) {
    const id = Number(novelId);
    if (!Number.isInteger(id) || id <= 0) return null;
    return { method: want ? 'PUT' : 'DELETE', url: `/api/v1/novels/${id}/want-to-read` };
}

/** What a screen reader hears once the change is saved. */
export function wantAnnouncement(want, name) {
    const verb = want ? `added to ${WANT_TO_READ}.` : `removed from ${WANT_TO_READ}.`;
    return name ? `“${name}” ${verb}` : verb.charAt(0).toUpperCase() + verb.slice(1);
}

/** The button's tooltip for its state (its label stays “Want to read”; aria-pressed says which). */
export function wantTitle(want) {
    return want ? `On your ${WANT_TO_READ} list — click to remove it` : `Add to your ${WANT_TO_READ} list`;
}
