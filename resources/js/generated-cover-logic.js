/**
 * "Generate a cover" (AI covers, App\Covers) — the pure decisions, no DOM:
 * `yarn test:js` (tests/js/generated-cover.test.js). The DOM glue is
 * ./generated-cover.js; the server is App\Http\Controllers\GeneratedCoverController
 * (POST queues, GET reports {state, message, cover_url}).
 */

/** States the server reports while the job is still going. */
export const RUNNING = ['queued', 'painting'];

/** Give up polling after this long (ms): the job's own timeout is shorter. */
export const GIVE_UP_AFTER = 30 * 60 * 1000;

export function isRunning(state) {
    return RUNNING.includes(state);
}

/** Wait before the next status check: quick at first, then every 5 s, at most 10 s. */
export function pollDelay(attempt) {
    const n = Number.isInteger(attempt) && attempt > 0 ? attempt : 0;
    if (n < 2) return 3000;
    return Math.min(10000, 5000 + (n - 2) * 1000);
}

/** The line under the cover for a reported state. */
export function statusText(state, message = '') {
    switch (state) {
        case 'queued':
            return 'Waiting for the GPU…';
        case 'painting':
            return 'Painting… ~1 min';
        case 'done':
            return 'New cover ready.';
        case 'failed':
            return message || 'The cover could not be painted.';
        default:
            return '';
    }
}

/** The cover image URL with a cache-buster, so the browser shows the new one. */
export function freshUrl(url, now = Date.now()) {
    if (!url) return null;
    return url + (url.includes('?') ? '&' : '?') + 'v=' + now;
}
