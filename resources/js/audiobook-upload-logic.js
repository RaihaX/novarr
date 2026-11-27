/**
 * The audiobook upload page's decisions (resources/js/audiobook-upload.js),
 * kept free of the DOM and the network so `yarn test:js` covers them:
 * which picked files are sent, the chunk plan, what is left to send (from the
 * server's answer), retry with backoff, the resume state kept in
 * localStorage, and progress / speed / time left.
 *
 * The server's API: App\Http\Controllers\Api\V1\AudiobookUploadController.
 */

export const AUDIO_EXTENSIONS = ['m4b', 'm4a', 'mp3', 'aac', 'ogg', 'opus', 'flac'];
export const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

/** Retries of one chunk before the upload stops and waits for the reader. */
export const MAX_ATTEMPTS = 8;

export function extensionOf(name) {
    const base = String(name || '').split('/').pop();
    const dot = base.lastIndexOf('.');
    return dot > 0 ? base.slice(dot + 1).toLowerCase() : '';
}

/** The name sent for a picked file: its path inside a picked folder, else its name. */
export function relativeName(file) {
    return (file && (file.webkitRelativePath || file.relativePath || file.name)) || '';
}

const collator = typeof Intl !== 'undefined' ? new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' }) : null;

/** "Part 2" before "Part 10", case ignored (the server orders parts the same way). */
export function naturalCompare(a, b) {
    if (collator) return collator.compare(String(a), String(b));
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

/**
 * The files of a pick (input or drop) that go up: audio files, and a few small
 * pictures (the cover). Hidden files (".DS_Store") and anything else are
 * skipped with a reason; the limits (per file, in all, how many) are errors.
 *
 * @returns {{accepted: Array<{file, name, size, kind}>, skipped: Array<{name, reason}>, errors: string[], total: number}}
 */
export function selectFiles(files, limits = {}) {
    const {
        maxFile = Infinity, maxTotal = Infinity, maxFiles = Infinity,
        formats = AUDIO_EXTENSIONS, images = IMAGE_EXTENSIONS, imageMax = 20 * 1048576, maxImages = 5,
    } = limits;
    const accepted = [];
    const skipped = [];
    const errors = [];
    let imageCount = 0;

    for (const file of Array.from(files || [])) {
        const name = relativeName(file);
        const base = name.split('/').pop();
        const ext = extensionOf(name);
        if (!base || base.startsWith('.')) {
            skipped.push({ name, reason: 'hidden file' });
            continue;
        }
        if (formats.includes(ext)) {
            accepted.push({ file, name, size: file.size, kind: 'audio' });
        } else if (images.includes(ext)) {
            if (file.size > imageMax || imageCount >= maxImages) {
                skipped.push({ name, reason: 'only a few small pictures (the cover) go along' });
                continue;
            }
            imageCount++;
            accepted.push({ file, name, size: file.size, kind: 'image' });
        } else {
            skipped.push({ name, reason: 'not an audio file' });
        }
    }
    accepted.sort((a, b) => naturalCompare(a.name, b.name));

    const audio = accepted.filter((f) => f.kind === 'audio');
    const total = accepted.reduce((sum, f) => sum + f.size, 0);
    if (accepted.length > 0 && audio.length === 0) errors.push(`There is no audio file here (${formats.map((e) => '.' + e).join(', ')}).`);
    for (const f of accepted) {
        if (f.size <= 0) errors.push(`“${f.name}” is empty.`);
        else if (f.size > maxFile) errors.push(`“${f.name}” is larger than ${formatBytes(maxFile)}, the most one file can be.`);
    }
    if (accepted.length > maxFiles) errors.push(`Up to ${maxFiles} files in one upload (you chose ${accepted.length}).`);
    if (total > maxTotal) errors.push(`These files are ${formatBytes(total)} together: one upload can be at most ${formatBytes(maxTotal)}.`);

    return { accepted, skipped, errors, total };
}

/** The chunks of a file: [{n, start, end (exclusive), length}]. */
export function planChunks(size, chunkSize) {
    const out = [];
    if (!(size > 0) || !(chunkSize > 0)) return out;
    for (let n = 0, start = 0; start < size; n++, start += chunkSize) {
        const end = Math.min(size, start + chunkSize);
        out.push({ n, start, end, length: end - start });
    }
    return out;
}

/** The Content-Range header of a chunk: "bytes 0-8388607/734003200". */
export function contentRange(chunk, size) {
    return `bytes ${chunk.start}-${chunk.end - 1}/${size}`;
}

/** Chunk numbers of a server file ({chunks, received}) not yet in. */
export function missingChunks(serverFile) {
    const have = new Set((serverFile && serverFile.received) || []);
    const out = [];
    const count = (serverFile && serverFile.chunks) || 0;
    for (let n = 0; n < count; n++) if (!have.has(n)) out.push(n);
    return out;
}

/**
 * Everything still to send for an upload (the server's {chunk_size, files}),
 * file by file in order: [{index, n, start, end, length}].
 */
export function pendingWork(upload) {
    const out = [];
    if (!upload || !Array.isArray(upload.files)) return out;
    for (const file of upload.files) {
        const chunks = planChunks(file.size, upload.chunk_size);
        const missing = new Set(missingChunks(file));
        for (const c of chunks) if (missing.has(c.n)) out.push({ index: file.index, ...c });
    }
    return out;
}

/** Bytes already on the server, per file index and in all. */
export function receivedBytes(upload) {
    const perFile = {};
    let total = 0;
    for (const file of (upload && upload.files) || []) {
        const chunks = planChunks(file.size, upload.chunk_size);
        const have = new Set(file.received || []);
        const bytes = chunks.filter((c) => have.has(c.n)).reduce((s, c) => s + c.length, 0);
        perFile[file.index] = bytes;
        total += bytes;
    }
    return { perFile, total };
}

/**
 * Whether a failed chunk PUT is worth trying again: the network (status 0),
 * timeouts, "too many requests" and server errors — but not a full disk
 * (507) or a refusal (4xx: the upload was cancelled, or the chunk is wrong).
 */
export function shouldRetry(status) {
    if (status === 0 || status === 408 || status === 425 || status === 429) return true;
    return status >= 500 && status !== 507 && status !== 501;
}

/** Wait before retry `attempt` (1, 2, …): 1 s, 2 s, 4 s … up to 30 s, with ±25 % jitter. */
export function backoffDelay(attempt, { base = 1000, max = 30000, random = Math.random, retryAfter = null } = {}) {
    if (retryAfter !== null && retryAfter !== undefined && Number.isFinite(Number(retryAfter)) && Number(retryAfter) > 0) {
        return Math.min(max * 4, Number(retryAfter) * 1000);
    }
    const exp = Math.min(max, base * 2 ** Math.max(0, attempt - 1));
    const jitter = exp * 0.25 * (random() * 2 - 1);
    return Math.max(0, Math.round(exp + jitter));
}

// --- Resume after a reload (localStorage) ------------------------------------------

export function resumeKey(userId) {
    return `novarr.audiobookUpload.${userId || 0}`;
}

/** What is kept: the upload id and each file's signature, to know the same files are picked again. */
export function resumeState(upload, selection, extra = {}) {
    return {
        id: upload.id,
        files: selection.map((f) => ({ name: f.name, size: f.size, modified: (f.file && f.file.lastModified) || 0 })),
        novelId: extra.novelId ?? null,
        note: extra.note ?? '',
        title: extra.title ?? '',
        savedAt: extra.now ?? Date.now(),
    };
}

export function saveResume(storage, key, state) {
    try { storage.setItem(key, JSON.stringify(state)); return true; } catch { return false; }
}

export function loadResume(storage, key, { now = Date.now(), maxAgeMs = 48 * 3600 * 1000 } = {}) {
    let state = null;
    try { state = JSON.parse(storage.getItem(key) || 'null'); } catch { state = null; }
    if (!state || !Number.isInteger(state.id) || !Array.isArray(state.files)) return null;
    if (now - (state.savedAt || 0) > maxAgeMs) return null; // the server drops it after 48 h anyway
    return state;
}

export function clearResume(storage, key) {
    try { storage.removeItem(key); } catch { /* storage blocked */ }
}

/**
 * Do the newly picked files match the interrupted upload? Same names and
 * sizes in the same order (modification time too, when both know it).
 * Returns the picked files in the upload's order, or null.
 */
export function matchResume(state, selection) {
    if (!state || !Array.isArray(selection) || selection.length !== state.files.length) return null;
    const byName = new Map(selection.map((f) => [f.name, f]));
    const out = [];
    for (const want of state.files) {
        const got = byName.get(want.name);
        if (!got || got.size !== want.size) return null;
        const modified = (got.file && got.file.lastModified) || 0;
        if (want.modified && modified && want.modified !== modified) return null;
        out.push(got);
    }
    return out;
}

// --- Progress ----------------------------------------------------------------------

/** Upload speed over the last `windowMs` (bytes per second); add() every time bytes go out. */
export function speedTracker(windowMs = 10000) {
    const samples = [];
    return {
        add(bytes, at) {
            samples.push([at, bytes]);
            while (samples.length > 1 && at - samples[0][0] > windowMs) samples.shift();
        },
        rate(at) {
            const recent = samples.filter(([t]) => at - t <= windowMs);
            if (recent.length === 0) return 0;
            const bytes = recent.reduce((s, [, b]) => s + b, 0);
            const span = Math.max(1000, at - recent[0][0]);
            return (bytes * 1000) / span;
        },
        reset() { samples.length = 0; },
    };
}

/** Seconds left at `rate` bytes/s, or null when it cannot be told yet. */
export function eta(remainingBytes, rate) {
    if (!(rate > 0)) return null;
    return Math.max(0, Math.round(remainingBytes / rate));
}

export function percent(done, total) {
    if (!(total > 0)) return 0;
    return Math.max(0, Math.min(100, Math.floor((done / total) * 100)));
}

export function formatBytes(bytes) {
    const b = Math.max(0, Number(bytes) || 0);
    if (b >= 1073741824) return `${(b / 1073741824).toFixed(1).replace(/\.0$/, '')} GB`;
    if (b >= 1048576) return `${Math.round(b / 1048576)} MB`;
    if (b >= 1024) return `${Math.round(b / 1024)} KB`;
    return `${b} B`;
}

/** "1 h 5 min", "4 min", "35 s"; "—" when unknown. */
export function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || !Number.isFinite(seconds)) return '—';
    const s = Math.max(0, Math.round(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    if (h > 0) return m > 0 ? `${h} h ${m} min` : `${h} h`;
    if (m > 0) return `${m} min`;
    return `${s} s`;
}

/** The status chip's words for an upload status (as the server's status_label). */
export function statusLabel(status) {
    return {
        uploading: 'Uploading',
        processing: 'Checking the files',
        pending: 'Waiting for the owner',
        approved: 'In the library',
        declined: 'Declined',
        failed: 'Failed',
    }[status] || status;
}
