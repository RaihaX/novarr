/**
 * The web audiobook player (resources/views/audiobooks/show.blade.php) —
 * the DOM glue; every decision is in ./audiobook-player-logic.js.
 *
 * One <audio> element plays the book's parts one after another; the
 * scrubber, the chapters and the saved position are whole-book positions,
 * so a book in 40 mp3s plays like one file. Progress is saved through
 * PUT /api/v1/audiobooks/{id}/progress (the web session) every 15 s while
 * playing, on pause, on seeking and when the page is left (fetch keepalive);
 * this browser also keeps its last position locally, and resume starts from
 * whichever of the two is newer. Bookmarks: POST / DELETE …/bookmarks.
 *
 * Media Session API (lock screen / OS media keys, artwork, chapters as
 * previous / next track), sleep timer (minutes or end of chapter), speed
 * (remembered per browser), keyboard: space, ←/→ (shift: 30 s).
 *
 * Bound per Turbo visit (turbo:load) when the page has #audiobookPlayer;
 * leaving the page saves, stops the audio and unbinds everything.
 */
import { api, messageOf, networkMessage } from './api';
import { deviceHeaders } from './device';
import {
    SPEEDS, SLEEP_END_OF_CHAPTER, totalDuration, clampPosition, partAt, positionOf, chapterAt,
    previousChapterTarget, nextChapterTarget, skip, normalizeSpeed, startSleepTimer, sleepTimerDue,
    sleepRemaining, formatTime, resumePosition, progressBody, saveDue, sortBookmarks, bookmarkLabel, keyAction,
} from './audiobook-player-logic';

const toast = (msg, type = 'info') => window.Novarr?.showToast?.(msg, type);
const SPEED_KEY = 'novarr_audiobook_speed';
const localKey = (id) => `novarr_audiobook_pos_${id}`;

function storageGet(key) {
    try { return window.localStorage.getItem(key); } catch { return null; }
}
function storageSet(key, value) {
    try { window.localStorage.setItem(key, value); } catch { /* private window: fine */ }
}

let active = null;

export function initAudiobookPlayer() {
    const root = document.getElementById('audiobookPlayer');
    if (!root || root.dataset.bound) return;
    root.dataset.bound = '1';
    const dataEl = document.getElementById('audiobookData');
    let book;
    try { book = JSON.parse(dataEl?.textContent || '{}'); } catch { book = null; }
    if (!book || !Array.isArray(book.parts) || book.parts.length === 0) return;
    active = createPlayer(root, book);
}

/** Leaving the page (Turbo): save where we are, stop, unbind. */
export function teardownAudiobookPlayer() {
    if (active) {
        active.destroy();
        active = null;
    }
}

function createPlayer(root, book) {
    const parts = [...book.parts].sort((a, b) => a.start_ms - b.start_ms);
    const chapters = [...(book.chapters || [])].sort((a, b) => a.start_ms - b.start_ms);
    const duration = totalDuration(parts) || book.duration_ms || 0;
    let bookmarks = sortBookmarks(book.bookmarks);

    const $ = (sel) => root.querySelector(sel);
    const audio = $('[data-ab-audio]');
    const playBtn = $('[data-ab-play]');
    const scrubber = $('[data-ab-scrubber]');
    const elapsedEl = $('[data-ab-elapsed]');
    const remainingEl = $('[data-ab-remaining]');
    const chapterTitleEl = $('[data-ab-chapter-title]');
    const chapterList = $('[data-ab-chapters]');
    const speedSelect = $('[data-ab-speed]');
    const sleepSelect = $('[data-ab-sleep]');
    const sleepStatus = $('[data-ab-sleep-status]');
    const bookmarkList = $('[data-ab-bookmarks]');
    const bookmarkForm = $('[data-ab-bookmark-form]');
    const statusEl = $('[data-ab-status]');

    let partIndex = -1;
    let pendingSeek = null;     // offset (ms) to apply once the part's metadata loads
    let wantPlay = false;
    let speed = normalizeSpeed(storageGet(SPEED_KEY) || 1);
    let sleepTimer = null;
    let lastSaved = { at: Date.now(), pos: -1 };
    let scrubbing = false;
    let finished = !!(book.progress && book.progress.finished);
    // Nothing is saved until the reader has played or moved: opening the
    // page and leaving it must never overwrite the place saved elsewhere.
    let touched = false;
    const listeners = [];

    const on = (target, type, fn, opts) => {
        target.addEventListener(type, fn, opts);
        listeners.push([target, type, fn, opts]);
    };
    const say = (text) => {
        if (!statusEl) return;
        statusEl.textContent = '';
        requestAnimationFrame(() => { statusEl.textContent = text; });
    };

    // --- Position ----------------------------------------------------------

    // Until a part's metadata has loaded, its pending seek is where we are
    // (currentTime still reads 0 then: saving that would lose the place).
    const position = () => (partIndex < 0 ? 0 : positionOf(parts, partIndex, pendingSeek !== null ? pendingSeek : (audio.currentTime || 0) * 1000));

    function loadPart(index, offsetMs, play) {
        wantPlay = play;
        if (index === partIndex && audio.readyState >= 1) {
            audio.currentTime = offsetMs / 1000;
            if (play) audio.play().catch(() => {});
            return;
        }
        partIndex = index;
        pendingSeek = offsetMs;
        audio.src = parts[index].url;
        audio.playbackRate = speed;
        audio.load();
    }

    function seekTo(positionMs, play = !audio.paused) {
        touched = true;
        const target = partAt(parts, clampPosition(positionMs, duration));
        if (!target) return;
        loadPart(target.index, target.offsetMs, play);
        render(positionOf(parts, target.index, target.offsetMs));
    }

    // --- Rendering ---------------------------------------------------------

    let renderedChapter = -2;
    function render(pos = position()) {
        if (!scrubbing) scrubber.value = String(Math.round(pos));
        elapsedEl.textContent = formatTime(pos);
        remainingEl.textContent = '-' + formatTime(duration - pos);
        scrubber.setAttribute('aria-valuetext', `${formatTime(pos)} of ${formatTime(duration)}`);
        const ci = chapterAt(chapters, pos);
        if (ci !== renderedChapter) {
            renderedChapter = ci;
            chapterTitleEl.textContent = ci >= 0 ? chapters[ci].title : '';
            chapterList?.querySelectorAll('[data-ab-chapter]').forEach((li) => {
                const current = Number(li.dataset.abChapter) === ci;
                li.classList.toggle('is-current', current);
                const btn = li.querySelector('button');
                if (current) btn?.setAttribute('aria-current', 'true'); else btn?.removeAttribute('aria-current');
            });
            updateMediaMetadata(ci);
        }
        if (sleepTimer && sleepTimer.kind === 'minutes' && sleepStatus) {
            const left = sleepRemaining(sleepTimer, Date.now());
            sleepStatus.textContent = left !== null ? `Stops in ${formatTime(left)}` : '';
        }
        updatePositionState(pos);
    }

    function renderPlaying() {
        const playing = !audio.paused;
        playBtn.setAttribute('aria-pressed', playing ? 'true' : 'false');
        playBtn.setAttribute('aria-label', playing ? 'Pause' : 'Play');
        playBtn.classList.toggle('is-playing', playing);
        if ('mediaSession' in navigator) navigator.mediaSession.playbackState = playing ? 'playing' : 'paused';
    }

    function renderBookmarks() {
        if (!bookmarkList) return;
        bookmarkList.innerHTML = '';
        const empty = root.querySelector('[data-ab-bookmarks-empty]');
        if (empty) empty.hidden = bookmarks.length > 0;
        for (const b of bookmarks) {
            const li = document.createElement('li');
            li.className = 'ab-bookmark';
            const jump = document.createElement('button');
            jump.type = 'button';
            jump.className = 'ab-bookmark-jump';
            jump.innerHTML = '<span class="ab-bookmark-time mono-num"></span><span class="ab-bookmark-label"></span>';
            jump.querySelector('.ab-bookmark-time').textContent = formatTime(b.position_ms);
            jump.querySelector('.ab-bookmark-label').textContent = bookmarkLabel(b, chapters);
            jump.addEventListener('click', () => { seekTo(b.position_ms, true); save(); });
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn-sm btn-ghost ab-bookmark-delete';
            del.setAttribute('aria-label', `Delete bookmark at ${formatTime(b.position_ms)}`);
            del.textContent = 'Delete';
            del.addEventListener('click', () => deleteBookmark(b));
            li.append(jump, del);
            bookmarkList.append(li);
        }
    }

    // --- Saving ------------------------------------------------------------

    async function save({ keepalive = false, markFinished } = {}) {
        if (partIndex < 0 || !touched) return;
        const pos = position();
        const body = progressBody(pos, speed, markFinished ?? (finished ? true : undefined), Date.now());
        if (markFinished === undefined && finished && pos < duration - 5000) {
            // Listening again after finishing: no longer finished.
            finished = false;
            body.finished = false;
        }
        lastSaved = { at: Date.now(), pos };
        storageSet(localKey(book.id), JSON.stringify({ position_ms: pos, at: Date.now(), finished }));
        try {
            if (keepalive) {
                fetch(`/api/v1/audiobooks/${book.id}/progress`, {
                    method: 'PUT',
                    keepalive: true,
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        ...deviceHeaders(),
                    },
                    body: JSON.stringify(body),
                }).catch(() => {});
                return;
            }
            const r = await apiWithDevice('PUT', `/api/v1/audiobooks/${book.id}/progress`, body);
            if (!r.ok && r.status !== 422) say(messageOf(r, 'Your place could not be saved'));
        } catch {
            // Offline: the local save keeps the place; the next save catches up.
        }
    }

    async function apiWithDevice(method, url, body) {
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            ...deviceHeaders(),
        };
        const res = await fetch(url, { method, headers, credentials: 'same-origin', body: JSON.stringify(body) });
        const data = await res.json().catch(() => ({}));
        return { ok: res.ok, status: res.status, data };
    }

    // --- Bookmarks ---------------------------------------------------------

    async function addBookmark(note) {
        const pos = position();
        try {
            const r = await api('POST', `/api/v1/audiobooks/${book.id}/bookmarks`, { position_ms: pos, note: note || null });
            if (!r.ok) { toast(messageOf(r, 'The bookmark could not be saved'), 'danger'); return; }
            bookmarks = sortBookmarks([...bookmarks, r.data.bookmark]);
            renderBookmarks();
            say(`Bookmark added at ${formatTime(pos)}.`);
        } catch (err) {
            toast(networkMessage(err, 'The bookmark could not be saved'), 'danger');
        }
    }

    async function deleteBookmark(b) {
        try {
            const r = await api('DELETE', `/api/v1/audiobooks/${book.id}/bookmarks/${b.id}`);
            if (!r.ok && r.status !== 404) { toast(messageOf(r, 'The bookmark could not be deleted'), 'danger'); return; }
            bookmarks = bookmarks.filter((x) => x.id !== b.id);
            renderBookmarks();
            say('Bookmark deleted.');
        } catch (err) {
            toast(networkMessage(err, 'The bookmark could not be deleted'), 'danger');
        }
    }

    // --- Media Session -----------------------------------------------------

    function updateMediaMetadata(ci) {
        if (!('mediaSession' in navigator) || typeof window.MediaMetadata !== 'function') return;
        const artwork = book.cover_url ? [{ src: book.cover_url, sizes: '480x720', type: 'image/jpeg' }] : [];
        navigator.mediaSession.metadata = new window.MediaMetadata({
            title: ci >= 0 ? chapters[ci].title : book.title,
            artist: book.author || book.narrator || '',
            album: book.title,
            artwork,
        });
    }

    function updatePositionState(pos) {
        if (!('mediaSession' in navigator) || typeof navigator.mediaSession.setPositionState !== 'function' || duration <= 0) return;
        try {
            navigator.mediaSession.setPositionState({ duration: duration / 1000, playbackRate: speed, position: Math.min(pos, duration) / 1000 });
        } catch { /* some browsers refuse while loading */ }
    }

    function bindMediaSession() {
        if (!('mediaSession' in navigator)) return;
        const ms = navigator.mediaSession;
        const handlers = {
            play: () => play(),
            pause: () => pause(),
            seekbackward: (d) => seekTo(skip(position(), -1000 * (d?.seekOffset || 15), duration)),
            seekforward: (d) => seekTo(skip(position(), 1000 * (d?.seekOffset || 30), duration)),
            seekto: (d) => { if (d && Number.isFinite(d.seekTime)) seekTo(d.seekTime * 1000); },
            previoustrack: () => seekTo(previousChapterTarget(chapters, position())),
            nexttrack: () => { const t = nextChapterTarget(chapters, position()); if (t !== null) seekTo(t); },
        };
        for (const [action, fn] of Object.entries(handlers)) {
            try { ms.setActionHandler(action, fn); } catch { /* not supported here */ }
        }
    }

    function unbindMediaSession() {
        if (!('mediaSession' in navigator)) return;
        for (const action of ['play', 'pause', 'seekbackward', 'seekforward', 'seekto', 'previoustrack', 'nexttrack']) {
            try { navigator.mediaSession.setActionHandler(action, null); } catch { /* fine */ }
        }
    }

    // --- Controls ----------------------------------------------------------

    function play() {
        touched = true;
        wantPlay = true;
        audio.play().catch(() => {});
    }

    function pause() {
        wantPlay = false;
        audio.pause();
    }

    function setSpeed(value) {
        speed = normalizeSpeed(value);
        audio.playbackRate = speed;
        storageSet(SPEED_KEY, String(speed));
        if (speedSelect) speedSelect.value = String(speed);
    }

    function setSleep(choice) {
        sleepTimer = choice ? startSleepTimer(choice, Date.now(), chapters, position()) : null;
        if (sleepStatus) {
            sleepStatus.textContent = !sleepTimer ? '' : sleepTimer.kind === 'chapter' ? 'Stops at the end of this chapter' : `Stops in ${formatTime(sleepRemaining(sleepTimer, Date.now()))}`;
        }
        say(sleepTimer ? sleepStatus.textContent : 'Sleep timer off.');
    }

    // Speed options (the select is rendered empty; SPEEDS is the one list).
    if (speedSelect) {
        speedSelect.innerHTML = '';
        for (const s of SPEEDS) {
            const opt = document.createElement('option');
            opt.value = String(s);
            opt.textContent = `${s}×`;
            speedSelect.append(opt);
        }
        if (!SPEEDS.includes(speed)) speed = 1;
        speedSelect.value = String(speed);
        on(speedSelect, 'change', () => setSpeed(speedSelect.value));
    }

    on(playBtn, 'click', () => (audio.paused ? play() : pause()));
    root.querySelectorAll('[data-ab-skip]').forEach((btn) => {
        on(btn, 'click', () => seekTo(skip(position(), Number(btn.dataset.abSkip) * 1000, duration)));
    });
    on($('[data-ab-prev-chapter]'), 'click', () => seekTo(previousChapterTarget(chapters, position())));
    on($('[data-ab-next-chapter]'), 'click', () => {
        const t = nextChapterTarget(chapters, position());
        if (t !== null) seekTo(t);
    });
    chapterList?.querySelectorAll('[data-ab-chapter] button').forEach((btn) => {
        on(btn, 'click', () => {
            const ch = chapters[Number(btn.closest('[data-ab-chapter]').dataset.abChapter)];
            if (ch) { seekTo(ch.start_ms, true); save(); }
        });
    });

    scrubber.max = String(Math.max(1, duration));
    on(scrubber, 'input', () => {
        scrubbing = true;
        elapsedEl.textContent = formatTime(Number(scrubber.value));
        remainingEl.textContent = '-' + formatTime(duration - Number(scrubber.value));
    });
    on(scrubber, 'change', () => {
        scrubbing = false;
        seekTo(Number(scrubber.value));
        save();
    });

    if (sleepSelect) on(sleepSelect, 'change', () => setSleep(sleepSelect.value === '' ? null : (sleepSelect.value === SLEEP_END_OF_CHAPTER ? SLEEP_END_OF_CHAPTER : Number(sleepSelect.value))));

    if (bookmarkForm) {
        on(bookmarkForm, 'submit', (e) => {
            e.preventDefault();
            const input = bookmarkForm.querySelector('input[name="note"]');
            addBookmark(input ? input.value.trim() : '');
            if (input) input.value = '';
        });
    }

    on(document, 'keydown', (e) => {
        const tag = (e.target && e.target.tagName) || '';
        if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey || /^(INPUT|TEXTAREA|SELECT|BUTTON)$/.test(tag) || e.target?.isContentEditable) return;
        const act = keyAction(e.key, e.shiftKey);
        if (!act) return;
        e.preventDefault();
        if (act.action === 'toggle') { if (audio.paused) play(); else pause(); }
        if (act.action === 'skip') seekTo(skip(position(), act.deltaMs, duration));
    });

    // --- Audio events ------------------------------------------------------

    on(audio, 'loadedmetadata', () => {
        audio.playbackRate = speed;
        if (pendingSeek !== null) {
            audio.currentTime = pendingSeek / 1000;
            pendingSeek = null;
        }
        if (wantPlay) audio.play().catch(() => {});
    });
    on(audio, 'play', renderPlaying);
    on(audio, 'pause', () => { renderPlaying(); save(); });
    on(audio, 'ratechange', () => { if (Math.abs(audio.playbackRate - speed) > 0.001) audio.playbackRate = speed; });
    on(audio, 'timeupdate', () => {
        const pos = position();
        render(pos);
        if (sleepTimerDue(sleepTimer, Date.now(), pos)) {
            sleepTimer = null;
            if (sleepSelect) sleepSelect.value = '';
            if (sleepStatus) sleepStatus.textContent = '';
            pause();
            say('Sleep timer: paused.');
            return;
        }
        if (!audio.paused && saveDue(lastSaved.at, Date.now(), lastSaved.pos, pos)) save();
    });
    on(audio, 'ended', () => {
        if (partIndex < parts.length - 1) {
            loadPart(partIndex + 1, 0, true);
            return;
        }
        finished = true;
        renderPlaying();
        save({ markFinished: true });
        say('Finished.');
    });
    on(audio, 'error', () => {
        if (!audio.src) return;
        say('This part could not be played. Reload the page to try again.');
        toast('The audio could not be loaded (the link may have expired: reload the page).', 'danger');
    });

    on(document, 'visibilitychange', () => { if (document.visibilityState === 'hidden') save({ keepalive: true }); });
    on(window, 'pagehide', () => save({ keepalive: true }));

    // --- Start -------------------------------------------------------------

    let local = null;
    try { local = JSON.parse(storageGet(localKey(book.id)) || 'null'); } catch { local = null; }
    const resumeAt = resumePosition(book.progress, local, duration);
    const startAt = partAt(parts, resumeAt);
    partIndex = startAt.index;
    pendingSeek = startAt.offsetMs;
    audio.preload = 'metadata';
    audio.src = parts[startAt.index].url;
    audio.playbackRate = speed;
    lastSaved = { at: Date.now(), pos: resumeAt };
    render(resumeAt);
    renderPlaying();
    renderBookmarks();
    bindMediaSession();
    if (resumeAt > 0) say(`Resuming at ${formatTime(resumeAt)}.`);

    return {
        destroy() {
            save({ keepalive: true });
            audio.pause();
            unbindMediaSession();
            for (const [target, type, fn, opts] of listeners) target.removeEventListener(type, fn, opts);
            audio.removeAttribute('src');
            audio.load();
        },
    };
}
