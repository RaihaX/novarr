/**
 * JSON API calls from reader pages with the web session (the web UI's own
 * /api/v1 calls: App\Http\Middleware\ApiSession / IdentifyReader sign them
 * in; the CSRF token comes from the layout's meta tag). The same helper the
 * Requests page uses (resources/js/requests.js), shared here for the reading
 * lists (resources/js/lists.js).
 */

/** JSON API call with the session cookie: {ok, status, data, headers}. Throws only on network errors / abort. */
export async function api(method, url, body, signal) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    };
    const init = { method, headers, credentials: 'same-origin', signal };
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }
    const res = await fetch(url, init);
    const data = res.status === 204 ? {} : await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data, headers: res.headers };
}

/** The API's message, or a fallback naming the status. */
export function messageOf(r, fallback) {
    return (r && r.data && typeof r.data.message === 'string' && r.data.message) || `${fallback} (${r ? r.status : 'network'}).`;
}

/** A readable line for a failed fetch (offline, or the error's own message). */
export function networkMessage(err, fallback) {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) return 'You are offline. Try again when you have a connection.';
    return err && err.message ? `${fallback}: ${err.message}` : fallback;
}
