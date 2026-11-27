/**
 * The web audiobook player's pure decisions, no DOM: `yarn test:js`
 * (tests/js/audiobook-player.test.js). The DOM glue is
 * resources/js/audiobook-player.js; the data is GET /api/v1/audiobooks/{id}
 * (App\Http\Controllers\Api\V1\AudiobookPresenter).
 *
 * Positions are milliseconds in the WHOLE book. A book is one or more parts
 * ({index, start_ms, duration_ms}) played one after another; chapters
 * ({index, title, start_ms, end_ms}) are whole-book spans.
 */

/** Speeds offered (the API accepts 0.5–3.0). */
export const SPEEDS = [0.75, 1, 1.25, 1.5, 1.75, 2, 2.5, 3];
export const MIN_SPEED = 0.5;
export const MAX_SPEED = 3;

/** Sleep timer choices, in minutes; 'chapter' stops at the end of the current chapter. */
export const SLEEP_CHOICES = [15, 30, 45, 60];
export const SLEEP_END_OF_CHAPTER = 'chapter';

/** Progress is saved this often while playing (and on pause / leaving). */
export const SAVE_INTERVAL_MS = 15000;

/** "Previous chapter" within this far into a chapter goes to the one before; later, to its start. */
export const RESTART_THRESHOLD_MS = 3000;

/** Within this much of the end, a resume starts the book again. */
export const NEAR_END_MS = 5000;

const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

/** The whole book's length: the end of its last part. */
export function totalDuration(parts) {
    if (!Array.isArray(parts) || parts.length === 0) return 0;
    return parts.reduce((end, p) => Math.max(end, num(p.start_ms) + num(p.duration_ms)), 0);
}

/** A position kept within the book. */
export function clampPosition(positionMs, durationMs) {
    const p = Math.round(num(positionMs));
    return Math.max(0, Math.min(p, Math.max(0, num(durationMs))));
}

/**
 * The part a whole-book position falls in and how far into it:
 * {index, offsetMs} (index into `parts`, sorted by start). The very end
 * belongs to the last part. null without parts.
 */
export function partAt(parts, positionMs) {
    if (!Array.isArray(parts) || parts.length === 0) return null;
    const sorted = [...parts].sort((a, b) => num(a.start_ms) - num(b.start_ms));
    const pos = clampPosition(positionMs, totalDuration(sorted));
    for (let i = 0; i < sorted.length; i++) {
        const start = num(sorted[i].start_ms);
        const end = start + num(sorted[i].duration_ms);
        if (pos < end || i === sorted.length - 1) {
            return { index: i, offsetMs: Math.max(0, Math.min(pos - start, num(sorted[i].duration_ms))) };
        }
    }
    return { index: sorted.length - 1, offsetMs: 0 };
}

/** The whole-book position of $offsetMs into part $index. */
export function positionOf(parts, index, offsetMs) {
    if (!Array.isArray(parts) || !parts[index]) return 0;
    const sorted = [...parts].sort((a, b) => num(a.start_ms) - num(b.start_ms));
    const part = sorted[index];
    return clampPosition(num(part.start_ms) + Math.max(0, Math.min(num(offsetMs), num(part.duration_ms))), totalDuration(sorted));
}

/** The chapter (index into `chapters`) playing at a position, or -1 (none / before the first). */
export function chapterAt(chapters, positionMs) {
    if (!Array.isArray(chapters) || chapters.length === 0) return -1;
    const pos = num(positionMs);
    let found = -1;
    for (let i = 0; i < chapters.length; i++) {
        if (pos >= num(chapters[i].start_ms)) found = i;
        else break;
    }
    // Past the last chapter's end: still the last one.
    return found;
}

/**
 * "Previous chapter": the current chapter's start when more than
 * RESTART_THRESHOLD_MS into it, else the previous chapter's start (the
 * first chapter's start at the beginning).
 */
export function previousChapterTarget(chapters, positionMs, threshold = RESTART_THRESHOLD_MS) {
    const i = chapterAt(chapters, positionMs);
    if (i < 0) return 0;
    const start = num(chapters[i].start_ms);
    if (num(positionMs) - start > threshold || i === 0) return start;
    return num(chapters[i - 1].start_ms);
}

/** "Next chapter": the next chapter's start, or null in the last one. */
export function nextChapterTarget(chapters, positionMs) {
    if (!Array.isArray(chapters) || chapters.length === 0) return null;
    const i = chapterAt(chapters, positionMs);
    const next = chapters[i + 1];
    return next ? num(next.start_ms) : null;
}

/** Skip by deltaMs (negative = back), kept within the book. */
export function skip(positionMs, deltaMs, durationMs) {
    return clampPosition(num(positionMs) + num(deltaMs), durationMs);
}

/** The speed after $current in SPEEDS (wrapping), for a one-button cycle. */
export function nextSpeed(current) {
    const i = SPEEDS.findIndex((s) => Math.abs(s - num(current)) < 0.001);
    return SPEEDS[(i + 1) % SPEEDS.length];
}

/** A speed the API accepts (0.5–3, two decimals), 1 for nonsense. */
export function normalizeSpeed(speed) {
    const s = Number(speed);
    if (!Number.isFinite(s) || s <= 0) return 1;
    return Math.round(Math.max(MIN_SPEED, Math.min(MAX_SPEED, s)) * 100) / 100;
}

/**
 * A sleep timer: {kind: 'minutes', endsAt} (wall clock, ms) or
 * {kind: 'chapter', stopAtMs} (the current chapter's end, whole-book), or
 * null for "off".
 */
export function startSleepTimer(choice, nowMs, chapters, positionMs) {
    if (choice === SLEEP_END_OF_CHAPTER) {
        const i = chapterAt(chapters, positionMs);
        if (i < 0) return null;
        return { kind: 'chapter', stopAtMs: num(chapters[i].end_ms) };
    }
    const minutes = Number(choice);
    if (!Number.isFinite(minutes) || minutes <= 0) return null;
    return { kind: 'minutes', endsAt: num(nowMs) + minutes * 60000 };
}

/** Whether the timer says stop now. */
export function sleepTimerDue(timer, nowMs, positionMs) {
    if (!timer) return false;
    if (timer.kind === 'minutes') return num(nowMs) >= timer.endsAt;
    if (timer.kind === 'chapter') return num(positionMs) >= timer.stopAtMs - 250;
    return false;
}

/** Milliseconds left on a minutes timer (0 when due), or null for other kinds / off. */
export function sleepRemaining(timer, nowMs) {
    if (!timer || timer.kind !== 'minutes') return null;
    return Math.max(0, timer.endsAt - num(nowMs));
}

/** "1:02:03" past an hour, else "2:03" (negative reads as 0). */
export function formatTime(ms) {
    const total = Math.max(0, Math.floor(num(ms) / 1000));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const ss = String(s).padStart(2, '0');
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`;
}

/** "11 h 27 min" / "27 min" / "45 s" — App\Audiobooks\AudiobookLibrary::humanDuration(). */
export function humanDuration(ms) {
    const seconds = Math.max(0, Math.floor(num(ms) / 1000));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (h > 0) return m > 0 ? `${h} h ${m} min` : `${h} h`;
    if (m > 0) return `${m} min`;
    return `${seconds % 60} s`;
}

/**
 * Where to start playing: the newer of the server's progress
 * ({position_ms, finished, updated_at}) and this browser's own last save
 * ({position_ms, at}, at in ms), from the start when that one is finished
 * or (almost) at the end.
 */
export function resumePosition(serverProgress, localSave, durationMs) {
    const server = serverProgress && Number.isFinite(Number(serverProgress.position_ms))
        ? { pos: num(serverProgress.position_ms), at: Date.parse(serverProgress.updated_at || '') || 0, finished: !!serverProgress.finished }
        : null;
    const local = localSave && Number.isFinite(Number(localSave.position_ms))
        ? { pos: num(localSave.position_ms), at: num(localSave.at), finished: !!localSave.finished }
        : null;
    let pick = server;
    if (local && (!server || local.at > server.at)) pick = local;
    if (!pick || pick.finished) return 0;
    const pos = clampPosition(pick.pos, durationMs);
    return num(durationMs) > 0 && num(durationMs) - pos <= NEAR_END_MS ? 0 : pos;
}

/** The PUT /api/v1/audiobooks/{id}/progress body. */
export function progressBody(positionMs, speed, finished, recordedAt) {
    const body = { position_ms: Math.max(0, Math.round(num(positionMs))), speed: normalizeSpeed(speed) };
    if (finished !== undefined && finished !== null) body.finished = !!finished;
    if (recordedAt) body.recorded_at = new Date(recordedAt).toISOString();
    return body;
}

/** Whether a periodic save is due: playing, moved, and SAVE_INTERVAL_MS since the last one. */
export function saveDue(lastSavedAt, nowMs, lastSavedPos, positionMs, interval = SAVE_INTERVAL_MS) {
    if (Math.abs(num(positionMs) - num(lastSavedPos)) < 1000) return false;
    return num(nowMs) - num(lastSavedAt) >= interval;
}

/** Bookmarks sorted by position (then id), as the list shows them. */
export function sortBookmarks(bookmarks) {
    return [...(bookmarks || [])].sort((a, b) => num(a.position_ms) - num(b.position_ms) || num(a.id) - num(b.id));
}

/** A bookmark's label: its note, else the chapter it is in and the time. */
export function bookmarkLabel(bookmark, chapters) {
    const note = bookmark && typeof bookmark.note === 'string' ? bookmark.note.trim() : '';
    if (note) return note;
    const i = chapterAt(chapters, bookmark ? bookmark.position_ms : 0);
    const chapter = i >= 0 ? chapters[i].title : null;
    return chapter ? `${chapter} · ${formatTime(bookmark.position_ms)}` : formatTime(bookmark ? bookmark.position_ms : 0);
}

/** What a keyboard key does on the player page, or null: space, ←/→ (shift: 30 s), j/l. */
export function keyAction(key, shiftKey = false) {
    switch (key) {
        case ' ':
        case 'Spacebar':
        case 'k':
            return { action: 'toggle' };
        case 'ArrowLeft':
        case 'j':
            return { action: 'skip', deltaMs: shiftKey ? -30000 : -15000 };
        case 'ArrowRight':
        case 'l':
            return { action: 'skip', deltaMs: shiftKey ? 30000 : 15000 };
        default:
            return null;
    }
}
