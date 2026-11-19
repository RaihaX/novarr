// Manga reading position: page ↔ percent on the same chapters.progress
// endpoint the text reader uses, with the same delivery: debounced while
// reading, a beacon when the page goes away (pagehide / hidden tab), and
// window.Novarr.queuedFetch otherwise, which parks the write in the offline
// queue when there is no connection.
//
// A look-up open (`?lookup=1`, a full-text search hit) is checking a page,
// not reading there: no page percents are posted and it is not a local write
// for the latest-position prompt (as reader/progress.js does for text). The
// last page still marks the chapter read, flagged `lookup` so the server
// leaves the series' latest position alone (as reader/continuous.js does).
import { pageToPercent, shouldSaveProgress } from './logic.js';
import { appendDeviceFields, deviceHeaders } from '../device.js';
import { isLookupChapter } from '../reader/latest-position.js';

const SAVE_DELAY_MS = 1500;
const localKey = (id) => 'manga_pos_' + id;

/** This device's exact page for a chapter: { page, pct } or null. */
export function readLocalPosition(storage, chapterId) {
    try {
        const v = JSON.parse(storage.getItem(localKey(chapterId)) || 'null');
        return v && typeof v === 'object' ? v : null;
    } catch (e) {
        return null;
    }
}

/**
 * Whether this open is a look-up of chapter `id`: the text reader's rule
 * (reader/latest-position.js), on the manga page's config.
 *
 * @param {{lookup?: boolean|string}} config  data-lookup from the view ('1' / '')
 */
export function isMangaLookup(config, id) {
    const on = config?.lookup === true || config?.lookup === '1';
    return isLookupChapter({ lookup: on, id: config?.id }, id);
}

/**
 * @param {object} o
 * @param {number} o.chapterId
 * @param {string} o.url         chapters.progress
 * @param {number|null} o.stored the server's read_progress when the page opened
 * @param {boolean} o.read       the chapter is already read
 * @param {number} o.count       pages in the chapter
 * @param {AbortSignal} o.signal this visit's lifetime
 * @param {() => void} [o.onWrite] this device wrote progress (latest-position bookkeeping)
 * @param {boolean} [o.lookup]  opened as a look-up: no percents, a `lookup` read mark
 */
export function createProgress({ chapterId, url, stored, read, count, signal, storage, onWrite = () => {}, lookup = false }) {
    let serverPct = stored === null || stored === undefined ? null : Number(stored);
    let readSent = !!read;
    let pending = null;      // percent waiting to be sent
    let timer = null;
    let lastPage = null;
    let movedBack = false;

    function send(body, onPageHide = false) {
        if (signal.aborted) return;
        if (!lookup) onWrite();
        if (onPageHide && navigator.sendBeacon && navigator.onLine !== false) {
            const form = new FormData();
            Object.entries(body).forEach(([k, v]) => form.append(k, v === true ? '1' : String(v)));
            appendDeviceFields(form);
            navigator.sendBeacon(url, form);
            return;
        }
        const q = window.Novarr?.queuedFetch;
        if (q) {
            q(url, { method: 'POST', body }).catch(() => {});
            return;
        }
        // app.js failed to load: a plain fetch (progress is CSRF-exempt).
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...deviceHeaders() },
            body: JSON.stringify(body),
        }).catch(() => {});
    }

    function flush(onPageHide = false) {
        clearTimeout(timer);
        timer = null;
        if (pending === null) return;
        const pct = pending;
        pending = null;
        serverPct = pct;
        send({ progress: pct }, onPageHide);
    }

    /**
     * The reader is now showing `page` (paged: on screen; vertical: the
     * current page). `readAtEnd: false` (vertical) — the last page being
     * current is not yet "displayed": it counts as 99 until markRead().
     */
    function pageShown(page, { readAtEnd = true } = {}) {
        if (!(count > 0)) return;
        if (lastPage !== null && page < lastPage) movedBack = true;
        lastPage = page;
        let pct = pageToPercent(page, count);
        if (page >= count) {
            if (readAtEnd) {
                saveLocal(page, pct);
                markRead();
                return;
            }
            pct = Math.min(pct, 99);
        }
        saveLocal(page, pct);
        if (lookup) return;
        if (!shouldSaveProgress(pct, pending ?? serverPct, movedBack)) return;
        pending = pct;
        clearTimeout(timer);
        timer = setTimeout(() => flush(), SAVE_DELAY_MS);
    }

    function saveLocal(page, pct) {
        try { storage?.setItem(localKey(chapterId), JSON.stringify({ page, pct })); } catch (e) { /* storage blocked */ }
        if (!lookup) onWrite();
    }

    /** The last page has been displayed: 100% and read, sent right away. */
    function markRead() {
        clearTimeout(timer);
        timer = null;
        pending = null;
        if (readSent && serverPct === 100) return;
        if (lookup) {
            if (readSent) return;
            readSent = true;
            send({ read: true, lookup: true });
            return;
        }
        readSent = true;
        serverPct = 100;
        send({ progress: 100, read: true });
    }

    return {
        pageShown,
        markRead,
        flush,
        get movedBack() { return movedBack; },
        /** Let the next pages save below the server's percent (as after moving back). */
        allowLower() { movedBack = true; },
        get serverPct() { return serverPct; },
        cancel() { clearTimeout(timer); timer = null; },
    };
}
