/**
 * "Search Usenet & torrents" (resources/views/downloads/_finder.blade.php) —
 * the pure decisions, no DOM: `yarn test:js` (tests/js/download-finder.test.js).
 * The DOM glue is resources/js/download-finder.js; the server is
 * App\Http\Controllers\DownloadController (App\Downloads).
 */

/** Grab states (App\DownloadGrab). */
export const ACTIVE = ['queued', 'downloading', 'completed'];

/** Still worth asking the server about (not imported or failed yet). */
export function isActive(status) {
    return ACTIVE.includes(status);
}

/** The body of POST /downloads/search: only the fields that are set. */
export function searchBody({ kind, q, requestId, novelId }) {
    const body = { kind: kind === 'audiobook' ? 'audiobook' : 'ebook' };
    const query = String(q ?? '').trim();
    if (query) body.q = query;
    const req = Number(requestId);
    if (Number.isInteger(req) && req > 0) body.request_id = req;
    const novel = Number(novelId);
    if (Number.isInteger(novel) && novel > 0) body.novel_id = novel;
    return body;
}

/** Binary units like App\Support\Bytes::human: "0 B", "1.2 MB", "3.4 GB". */
export function humanSize(bytes) {
    let value = Math.max(0, Number(bytes) || 0);
    if (value < 1024) return `${Math.floor(value)} B`;
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let unit = 0;
    while (unit < units.length - 1 && Math.round(value * 10) / 10 >= 1024) {
        value /= 1024;
        unit++;
    }
    return `${value.toFixed(1)} ${units[unit]}`;
}

/** The "age or seeders" column: "12 seeders" for a torrent, "3 days old" for a usenet post. */
export function ageOrSeeders(result) {
    if (!result) return '';
    if (result.protocol === 'torrent') {
        if (result.seeders === null || result.seeders === undefined) return 'seeders unknown';
        const n = Number(result.seeders);
        return `${n} ${n === 1 ? 'seeder' : 'seeders'}`;
    }
    if (result.age_days === null || result.age_days === undefined) return '';
    const d = Number(result.age_days);
    if (d < 1) return 'today';
    if (d < 365) return `${d} ${d === 1 ? 'day' : 'days'} old`;
    const y = Math.floor(d / 365);
    return `${y} ${y === 1 ? 'year' : 'years'} old`;
}

/** The Grab button for a result: disabled with the reason when its client is not set up. */
export function grabButton(result) {
    if (!result || result.grabbable === false) {
        return { disabled: true, label: 'Grab', title: (result && result.not_grabbable) || 'Its download client is not set up.' };
    }
    if (result.blocklisted) {
        return { disabled: false, label: 'Grab anyway', title: 'Blocklisted (it failed before): grab it only if you are sure.' };
    }
    return { disabled: false, label: 'Grab', title: `Send it to ${result.protocol === 'torrent' ? 'Transmission' : 'NZBGet'}` };
}

/** "Would be grabbed automatically" or why not, for the results table. */
export function autoNote(result) {
    if (!result || !result.auto) return '';
    return result.auto === 'ok' ? 'Clear match' : `Not automatic: ${result.auto}`;
}

/** One line for a grab: the server's status_line, else built from its status. */
export function statusLine(grab) {
    if (!grab) return '';
    if (grab.status_line) return grab.status_line;
    switch (grab.status) {
        case 'queued': return 'Queued';
        case 'downloading': return `Downloading ${Math.max(0, Math.min(100, Number(grab.progress) || 0))}%`;
        case 'completed': return grab.kind === 'audiobook' ? 'Finished — waiting for the audiobooks scan' : 'Finished — taking the ePub';
        case 'imported': return grab.kind === 'audiobook' ? 'Imported — in the audiobooks' : 'Finished — ready to review';
        case 'failed': return `Failed: ${grab.error || 'no reason given'}`;
        default: return String(grab.status || '');
    }
}

/** Milliseconds before asking again: 3 s, growing to 30 s for a long download. */
export function pollDelay(attempt) {
    const n = Math.max(0, Number(attempt) || 0);
    return Math.min(30000, 3000 + n * 1500);
}

/** "ETA 12 min" from seconds; '' when unknown. */
export function eta(seconds) {
    const s = Number(seconds);
    if (seconds === null || seconds === undefined || !Number.isFinite(s) || s < 0) return '';
    if (s < 60) return 'ETA under a minute';
    if (s < 3600) return `ETA ${Math.round(s / 60)} min`;
    const h = Math.floor(s / 3600);
    const m = Math.round((s % 3600) / 60);
    return `ETA ${h} h${m ? ` ${m} min` : ''}`;
}
