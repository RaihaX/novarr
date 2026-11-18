// "Latest position" prompt — the pure pieces shared by the text reader and
// the manga reader (unit-tested in tests/js/latest-position.test.js).
//
// When a chapter is opened and ANOTHER device has read this series more
// recently somewhere else, the reader offers "Chapter 124 · 40% was read on
// Kindle 2 hours ago. Go there?". The server hands the reader that position
// as `latest_position` (see the shared contract); everything here decides
// whether to offer it and remembers the answer, per series, locally.

/** Same chapter: only offer when the positions are this many points apart. */
export const SAME_CHAPTER_POINTS = 5;

/** Date.now() of this browser's last progress write for a series. */
export const lastWriteKey = (novelId) => 'reader_last_write_' + novelId;
/** The `at` of the position the reader already answered (declined or accepted). */
export const declinedKey = (novelId) => 'reader_declined_pos_' + novelId;
/** sessionStorage: the reader is moving to the next / previous chapter itself. */
export const NAV_KEY = 'novarr_reader_nav';
/** sessionStorage: "Go to latest" to another chapter — the position to open at. */
export const ACCEPT_KEY = 'novarr_latest_accept';
/** An in-reader navigation marker older than this is ignored. */
const MARKER_TTL_MS = 5 * 60 * 1000;

const num = (v) => (v === null || v === undefined || v === '' || Number.isNaN(Number(v)) ? null : Number(v));

/**
 * Should the reader offer to jump to `position`? The contract's client rule:
 *  1. there is a position, from a known device that is not this one;
 *  2. this browser has no progress for the series newer than it —
 *     `localWriteAgeMs` (ms since our last write) against the position's age
 *     (`age_seconds` when the page was served + `elapsedMs` since then);
 *     relative ages only, never another device's clock;
 *  3. it is somewhere else: another chapter, or this one >= 5 points away
 *     from `localProgress` (this device's position here; none = 0);
 *  4. this exact position (its `at`) was not already declined/answered;
 *  5. its chapter can be opened (downloaded, and the caller says reachable).
 */
export function shouldOffer({
    position,
    deviceId,
    chapterId,
    localProgress = null,
    localWriteAgeMs = null,
    elapsedMs = 0,
    declinedAt = null,
    canOpen = true,
} = {}) {
    if (!position || typeof position !== 'object') return false;
    // 1
    if (!position.device_id || !deviceId || position.device_id === deviceId) return false;
    if (position.chapter_id === null || position.chapter_id === undefined) return false;
    // 5
    if (!canOpen || position.downloaded === false) return false;
    // 4
    if (declinedAt && position.at && String(declinedAt) === String(position.at)) return false;
    // 2
    const writeAge = num(localWriteAgeMs);
    if (writeAge !== null) {
        const positionAgeMs = Math.max(0, num(position.age_seconds) ?? 0) * 1000 + Math.max(0, num(elapsedMs) ?? 0);
        if (writeAge < positionAgeMs) return false;
    }
    // 3
    if (String(position.chapter_id) !== String(chapterId)) return true;
    const theirs = num(position.progress) ?? 0;
    const mine = num(localProgress) ?? 0;
    return Math.abs(theirs - mine) >= SAME_CHAPTER_POINTS;
}

/** "just now", "5 minutes ago", "2 hours ago", "yesterday", "3 days ago"… */
export function relativeTime(ageSeconds) {
    const s = Math.max(0, num(ageSeconds) ?? 0);
    const plural = (n, unit) => `${n} ${unit}${n === 1 ? '' : 's'} ago`;
    if (s < 60) return 'just now';
    if (s < 3600) return plural(Math.floor(s / 60), 'minute');
    if (s < 86400) return plural(Math.floor(s / 3600), 'hour');
    const days = Math.floor(s / 86400);
    if (days === 1) return 'yesterday';
    if (days < 60) return plural(days, 'day');
    if (days < 365) return plural(Math.floor(days / 30), 'month');
    return plural(Math.floor(days / 365), 'year');
}

/** The chapter as the prompt names it: its label, else "Chapter N". */
export function positionLabel(position) {
    const label = position?.chapter_label ? String(position.chapter_label).trim() : '';
    if (label) return label;
    const n = position?.chapter_number;
    return n === null || n === undefined || n === '' ? 'Another chapter' : 'Chapter ' + n;
}

/**
 * "{label}{ · NN% if 1–99} was read on {device or 'another device'}
 * {relative time}. Go there?" — `ageSeconds` is the position's age now.
 */
export function promptText(position, ageSeconds = position?.age_seconds) {
    const pct = num(position?.progress);
    const where = positionLabel(position) + (pct !== null && pct >= 1 && pct <= 99 ? ` · ${Math.round(pct)}%` : '');
    const device = position?.device_name ? String(position.device_name) : 'another device';
    return `${where} was read on ${device} ${relativeTime(ageSeconds)}. Go there?`;
}

/** The chapter id in a /chapters/{id} URL (absolute or relative), or null. */
export function chapterIdFromUrl(url) {
    const m = String(url ?? '').match(/\/chapters\/(\d+)(?:[/?#]|$)/);
    return m ? parseInt(m[1], 10) : null;
}

// ---- Storage helpers (all guarded: blocked storage throws) ----

function get(storage, key) {
    try { return storage?.getItem(key) ?? null; } catch (e) { return null; }
}
function set(storage, key, value) {
    try { storage?.setItem(key, String(value)); } catch (e) { /* storage blocked */ }
}
function remove(storage, key) {
    try { storage?.removeItem(key); } catch (e) { /* storage blocked */ }
}

/** Remember that this browser just wrote progress for the series. */
export function markLocalWrite(storage, novelId, now = Date.now()) {
    if (novelId === null || novelId === undefined) return;
    set(storage, lastWriteKey(novelId), now);
}

/** ms since this browser's last progress write for the series, or null. */
export function localWriteAge(storage, novelId, now = Date.now()) {
    const t = num(get(storage, lastWriteKey(novelId)));
    return t === null ? null : now - t;
}

export const readDeclined = (storage, novelId) => get(storage, declinedKey(novelId));
export function rememberAnswered(storage, novelId, position) {
    if (position?.at) set(storage, declinedKey(novelId), position.at);
}

/** The reader is about to open `url` as its own next / previous chapter. */
export function markInReaderNav(session, url, now = Date.now()) {
    const id = chapterIdFromUrl(url);
    if (id === null) return;
    set(session, NAV_KEY, JSON.stringify({ id, t: now }));
}

/**
 * Was this chapter reached by the reader's own next / previous navigation?
 * Reads and clears the marker (one use).
 */
export function consumeInReaderNav(session, chapterId, now = Date.now()) {
    const raw = get(session, NAV_KEY);
    if (raw === null) return false;
    remove(session, NAV_KEY);
    try {
        const v = JSON.parse(raw);
        return String(v?.id) === String(chapterId) && now - Number(v.t) >= 0 && now - Number(v.t) < MARKER_TTL_MS;
    } catch (e) {
        return false;
    }
}

/** "Go to latest" to another chapter: open it at `progress`. */
export function markAccepted(session, position, now = Date.now()) {
    set(session, ACCEPT_KEY, JSON.stringify({ id: position.chapter_id, progress: num(position.progress) ?? 0, t: now }));
}

/**
 * The progress this chapter must open at because "Go to latest" brought us
 * here, or null. Reads and clears the marker (one use).
 */
export function consumeAccepted(session, chapterId, now = Date.now()) {
    const raw = get(session, ACCEPT_KEY);
    if (raw === null) return null;
    remove(session, ACCEPT_KEY);
    try {
        const v = JSON.parse(raw);
        if (String(v?.id) !== String(chapterId) || !(now - Number(v.t) >= 0 && now - Number(v.t) < MARKER_TTL_MS)) return null;
        return Math.max(0, Math.min(100, num(v.progress) ?? 0));
    } catch (e) {
        return null;
    }
}

/**
 * The whole decision for one reader open. `accepted` (from consumeAccepted)
 * and `inReaderNav` (consumeInReaderNav) are what the caller already read
 * for this open. Returns { offer, sameChapter }.
 */
export function planLatest({
    position, device, chapterId, novelId, storage,
    localProgress = null, elapsedMs = 0, accepted = null, inReaderNav = false, online = true,
    now = Date.now(),
}) {
    const sameChapter = !!position && String(position.chapter_id) === String(chapterId);
    if (accepted !== null || inReaderNav || !online) return { offer: false, sameChapter };
    const offer = shouldOffer({
        position,
        deviceId: device?.id,
        chapterId,
        localProgress,
        localWriteAgeMs: localWriteAge(storage, novelId, now),
        elapsedMs,
        declinedAt: readDeclined(storage, novelId),
        canOpen: sameChapter || !!position?.url,
    });
    return { offer, sameChapter };
}
