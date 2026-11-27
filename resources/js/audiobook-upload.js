/**
 * The audiobook upload page (/audiobooks/upload, resources/views/audiobooks/upload):
 * pick files or a folder (or drop them), then the files go up in chunks
 * through the chunk API (App\Http\Controllers\Api\V1\AudiobookUploadController),
 * three chunks at a time, each retried with backoff when the network drops.
 * Pause / resume; a reload keeps the upload (its id is in localStorage: pick
 * the same files again and only what is missing is sent). "Don't close this
 * tab" while sending (beforeunload, and Turbo visits ask first).
 *
 * The decisions are in ./audiobook-upload-logic.js (tested).
 */
import {
    selectFiles, pendingWork, receivedBytes, contentRange, shouldRetry, backoffDelay, MAX_ATTEMPTS,
    resumeKey, resumeState, saveResume, loadResume, clearResume, matchResume,
    speedTracker, eta, percent, formatBytes, formatDuration, statusLabel,
} from './audiobook-upload-logic';

let active = null;

export function initAudiobookUpload() {
    const root = document.getElementById('audiobookUpload');
    if (!root || root.dataset.bound) return;
    root.dataset.bound = '1';
    let config = {};
    try { config = JSON.parse(root.dataset.config || '{}'); } catch { config = {}; }
    active = createUploader(root, config);
}

export function teardownAudiobookUpload() {
    if (active) {
        active.destroy();
        active = null;
    }
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function apiJson(method, url, body) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() };
    const init = { method, headers, credentials: 'same-origin' };
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }
    let res;
    try {
        res = await fetch(url, init);
    } catch (err) {
        return { ok: false, status: 0, data: { message: navigator.onLine === false ? 'You are offline.' : 'The server could not be reached.' } };
    }
    const data = res.status === 204 ? {} : await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data, headers: res.headers };
}

/** One chunk PUT with upload progress: resolves {status, retryAfter}. */
function putChunk(url, blob, range, onProgress, register) {
    return new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        register(xhr);
        xhr.open('PUT', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
        xhr.setRequestHeader('Content-Type', 'application/octet-stream');
        xhr.setRequestHeader('Content-Range', range);
        xhr.timeout = 10 * 60 * 1000;
        xhr.upload.onprogress = (e) => onProgress(e.loaded);
        const done = (status) => {
            let data = {};
            try { data = JSON.parse(xhr.responseText || '{}'); } catch { data = {}; }
            resolve({ status, data, retryAfter: xhr.getResponseHeader?.('Retry-After') ?? null });
        };
        xhr.onload = () => done(xhr.status);
        xhr.onerror = () => done(0);
        xhr.ontimeout = () => done(0);
        xhr.onabort = () => resolve({ status: -1, data: {}, aborted: true });
        xhr.send(blob);
    });
}

/** Files from a drop, folders walked (each File gets relativePath "Folder/file.mp3"). */
async function filesFromDrop(dataTransfer) {
    const items = Array.from(dataTransfer.items || []);
    const entries = items.map((i) => (i.webkitGetAsEntry ? i.webkitGetAsEntry() : null)).filter(Boolean);
    if (entries.length === 0) return Array.from(dataTransfer.files || []);
    const out = [];
    const walk = async (entry, prefix) => {
        if (entry.isFile) {
            const file = await new Promise((res, rej) => entry.file(res, rej)).catch(() => null);
            if (file) {
                try { file.relativePath = prefix + file.name; } catch { /* read-only: the name is enough */ }
                out.push(file);
            }
        } else if (entry.isDirectory) {
            const reader = entry.createReader();
            // readEntries answers in batches until an empty one.
            for (;;) {
                const batch = await new Promise((res) => reader.readEntries(res, () => res([])));
                if (batch.length === 0) break;
                for (const child of batch) await walk(child, `${prefix}${entry.name}/`);
            }
        }
    };
    for (const entry of entries) await walk(entry, '');
    return out;
}

function createUploader(root, config) {
    const $ = (sel) => root.querySelector(sel);
    const els = {
        drop: $('[data-abu-drop]'),
        files: $('[data-abu-files]'),
        folder: $('[data-abu-folder]'),
        summary: $('[data-abu-summary]'),
        errors: $('[data-abu-errors]'),
        note: $('[data-abu-note]'),
        start: $('[data-abu-start]'),
        pause: $('[data-abu-pause]'),
        resume: $('[data-abu-resume]'),
        cancel: $('[data-abu-cancel]'),
        progress: $('[data-abu-progress]'),
        totalBar: $('[data-abu-total-bar]'),
        totalText: $('[data-abu-total-text]'),
        list: $('[data-abu-list]'),
        status: $('[data-abu-status]'),
        done: $('[data-abu-done]'),
        resumeBanner: $('[data-abu-resume-banner]'),
        resumeText: $('[data-abu-resume-text]'),
        resumeForget: $('[data-abu-resume-forget]'),
        warning: $('[data-abu-warning]'),
    };
    const storage = (() => { try { return window.localStorage; } catch { return null; } })();
    const key = resumeKey(config.user);
    const endpoint = config.endpoint || '/api/v1/audiobook-uploads';
    const concurrency = Math.max(1, Math.min(4, config.concurrency || 3));

    let selection = [];       // [{file, name, size, kind}] in the server's index order
    let upload = null;        // the server's upload
    let phase = 'idle';       // idle | ready | sending | paused | finishing | processing | done | error
    let queue = [];
    let inflight = new Map(); // "index:n" → {loaded, xhr}
    let stopped = false;
    let rerun = 0;
    let pollTimer = null;
    let renderQueued = false;
    const speed = speedTracker(15000);
    let saved = storage ? loadResume(storage, key) : null;
    const listeners = [];

    const on = (target, type, fn, opts) => {
        if (!target) return;
        target.addEventListener(type, fn, opts);
        listeners.push([target, type, fn, opts]);
    };

    const sending = () => phase === 'sending' || phase === 'finishing';

    // ---- Picking files ----------------------------------------------------------

    function pick(fileList) {
        if (sending() || phase === 'processing' || phase === 'done') return;
        const result = selectFiles(fileList, {
            maxFile: config.max_file, maxTotal: config.max_total, maxFiles: config.max_files,
            formats: config.formats, images: config.images, imageMax: config.image_max, maxImages: config.max_images,
        });
        els.errors.innerHTML = '';
        for (const e of result.errors) {
            const p = document.createElement('p');
            p.className = 'mb-0';
            p.textContent = e;
            els.errors.append(p);
        }
        els.errors.hidden = result.errors.length === 0;

        if (result.accepted.length === 0) {
            selection = [];
            els.summary.textContent = result.skipped.length ? `Nothing to upload: ${result.skipped.length} ${result.skipped.length === 1 ? 'file was' : 'files were'} not audio.` : '';
            phase = 'idle';
            render();
            return;
        }

        // The same files as an interrupted upload: carry on with it.
        const resumed = saved ? matchResume(saved, result.accepted) : null;
        selection = resumed || result.accepted;
        const audio = selection.filter((f) => f.kind === 'audio').length;
        const skippedLine = result.skipped.length ? ` (${result.skipped.length} other ${result.skipped.length === 1 ? 'file' : 'files'} left out)` : '';
        els.summary.textContent = `${audio} audio ${audio === 1 ? 'file' : 'files'}${selection.length > audio ? ' and a cover' : ''}, ${formatBytes(result.total)}${skippedLine}.`;
        phase = result.errors.length ? 'idle' : 'ready';
        upload = null;
        if (resumed) {
            els.summary.textContent += ' These are the files of your interrupted upload: only what is missing is sent.';
            resumeExisting(saved.id);
            return;
        }
        render();
    }

    // ---- The upload --------------------------------------------------------------

    async function startNew() {
        if (phase !== 'ready' || selection.length === 0) return;
        setStatus('Starting…');
        phase = 'sending';
        render();
        const r = await apiJson('POST', endpoint, {
            files: selection.map((f) => ({ name: f.name, size: f.size })),
            novel_id: config.novel_id || null,
            note: els.note ? els.note.value : null,
        });
        if (!r.ok) {
            phase = 'ready';
            fail(r.data.message || `The upload could not start (${r.status}).`, false);
            return;
        }
        upload = r.data.upload;
        saved = resumeState(upload, selection, { novelId: config.novel_id || null, note: els.note?.value || '', title: selection[0]?.name || '' });
        if (storage) saveResume(storage, key, saved);
        run();
    }

    async function resumeExisting(id) {
        phase = 'sending';
        setStatus('Picking up where it stopped…');
        render();
        const r = await apiJson('GET', `${endpoint}/${id}`);
        if (!r.ok) {
            forgetSaved();
            phase = selection.length ? 'ready' : 'idle';
            setStatus(r.status === 404 ? 'That upload is gone (cancelled, or not finished within 48 hours). Start it again.' : (r.data.message || 'The upload could not be found.'));
            render();
            return;
        }
        upload = r.data.upload;
        afterServerState();
    }

    /** The server said where things are: carry on from there. */
    function afterServerState() {
        if (upload.status === 'uploading') {
            if (upload.error) setStatus(upload.error);
            run();
        } else if (upload.status === 'processing') {
            phase = 'processing';
            poll();
        } else {
            settled();
        }
    }

    function run() {
        stopped = false;
        phase = 'sending';
        queue = pendingWork(upload);
        setStatus(queue.length ? 'Sending… keep this tab open until it is done.' : 'Every part is in: checking…');
        render();
        if (queue.length === 0) {
            finish();
            return;
        }
        let running = 0;
        const next = () => {
            if (stopped) return;
            if (queue.length === 0) {
                if (running === 0 && phase === 'sending') finish();
                return;
            }
            const task = queue.shift();
            running++;
            sendTask(task).then((ok) => {
                running--;
                if (!ok) return;
                next();
            });
        };
        for (let i = 0; i < concurrency; i++) next();
    }

    async function sendTask(task) {
        const entry = selection[task.index];
        if (!entry) {
            fail('The files picked no longer match this upload. Pick them again.', true);
            return false;
        }
        const id = `${task.index}:${task.n}`;
        const url = `${endpoint}/${upload.id}/files/${task.index}/chunks/${task.n}`;
        for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
            if (stopped) return false;
            const slot = { loaded: 0, xhr: null };
            inflight.set(id, slot);
            const blob = entry.file.slice(task.start, task.end);
            const result = await putChunk(url, blob, contentRange(task, entry.size), (loaded) => {
                speed.add(Math.max(0, loaded - slot.loaded), Date.now());
                slot.loaded = loaded;
                scheduleRender();
            }, (xhr) => { slot.xhr = xhr; });
            inflight.delete(id);
            if (result.aborted || stopped) return false;
            if (result.status === 204) {
                const file = upload.files[task.index];
                if (!file.received.includes(task.n)) file.received.push(task.n);
                scheduleRender();
                return true;
            }
            if (!shouldRetry(result.status) || attempt === MAX_ATTEMPTS) {
                fail(result.data?.message || `A part could not be sent (${result.status || 'network'}).`, result.status === 409 || result.status === 404);
                return false;
            }
            setStatus(navigator.onLine === false ? 'You are offline: it carries on when the connection is back.' : `The connection dropped: trying again (${attempt}/${MAX_ATTEMPTS - 1})…`);
            if (navigator.onLine === false) await waitOnline();
            else await sleep(backoffDelay(attempt, { retryAfter: result.retryAfter }));
        }
        return false;
    }

    async function finish() {
        if (phase !== 'sending') return;
        phase = 'finishing';
        setStatus('Every part is in: checking the files…');
        render();
        const r = await apiJson('POST', `${endpoint}/${upload.id}/complete`, {});
        if (r.data && r.data.upload) upload = r.data.upload;
        if (r.status === 200) {
            settled();
        } else if (r.status === 202) {
            phase = 'processing';
            poll();
        } else if (r.status === 422 && upload && upload.status === 'uploading' && rerun < 3) {
            // Some parts are missing or arrived damaged: send them again.
            rerun++;
            setStatus(r.data.message || 'Some parts must be sent again…');
            run();
        } else if (r.status === 0 || r.status >= 500) {
            phase = 'paused';
            setStatus('Checking could not start (the connection dropped). Press Resume.');
            render();
        } else {
            fail(r.data.message || `The upload could not be finished (${r.status}).`, true);
        }
    }

    function poll() {
        setStatus('Checking the files (a large audiobook takes a few minutes)… you can close this tab now.');
        render();
        clearTimeout(pollTimer);
        pollTimer = setTimeout(async () => {
            const r = await apiJson('GET', `${endpoint}/${upload.id}`);
            if (r.ok) {
                upload = r.data.upload;
                if (upload.status === 'processing') { poll(); return; }
                afterServerState();
            } else if (r.status === 404) {
                fail('This upload is gone.', true);
            } else {
                poll();
            }
        }, 5000);
    }

    /** Decided by the server (pending, failed …): say so, forget the resume state. */
    function settled() {
        forgetSaved();
        if (upload.status === 'failed') {
            fail(upload.error || 'The files could not be used.', true);
            return;
        }
        phase = 'done';
        setStatus('');
        render();
    }

    function pause() {
        if (!sending()) return;
        stopped = true;
        for (const slot of inflight.values()) slot.xhr?.abort();
        inflight.clear();
        speed.reset();
        phase = 'paused';
        setStatus('Paused. Press Resume to carry on (from here, even after closing the tab).');
        render();
    }

    async function resume() {
        if (phase !== 'paused' && phase !== 'error') return;
        if (!upload) { phase = 'ready'; startNew(); return; }
        rerun = 0;
        await resumeExisting(upload.id);
    }

    async function cancel() {
        const id = upload?.id ?? saved?.id;
        if (!id) { reset(); return; }
        const ok = window.Novarr?.confirmDialog
            ? await window.Novarr.confirmDialog('What was sent is deleted from the server.', { title: 'Cancel this upload?', confirmText: 'Cancel the upload', cancelText: 'Keep uploading', danger: true })
            : window.confirm('Cancel this upload? What was sent is deleted.');
        if (!ok) return;
        stopped = true;
        for (const slot of inflight.values()) slot.xhr?.abort();
        inflight.clear();
        const r = await apiJson('DELETE', `${endpoint}/${id}`);
        if (!r.ok && r.status !== 404) {
            setStatus(r.data.message || 'It could not be cancelled.');
            return;
        }
        forgetSaved();
        reset();
        setStatus('Cancelled.');
    }

    function fail(message, final) {
        stopped = true;
        for (const slot of inflight.values()) slot.xhr?.abort();
        inflight.clear();
        phase = final ? 'error' : (upload ? 'paused' : 'ready');
        if (final && upload && upload.status !== 'uploading') forgetSaved();
        setStatus(message);
        render();
    }

    function reset() {
        stopped = true;
        clearTimeout(pollTimer);
        selection = [];
        upload = null;
        phase = 'idle';
        queue = [];
        if (els.files) els.files.value = '';
        if (els.folder) els.folder.value = '';
        els.summary.textContent = '';
        render();
    }

    function forgetSaved() {
        saved = null;
        if (storage) clearResume(storage, key);
    }

    // ---- Showing it ---------------------------------------------------------------

    function setStatus(text) {
        if (els.status) els.status.textContent = text || '';
    }

    function scheduleRender() {
        if (renderQueued) return;
        renderQueued = true;
        requestAnimationFrame(() => { renderQueued = false; render(); });
    }

    function render() {
        const busy = sending();
        els.start.hidden = !(phase === 'ready');
        els.pause.hidden = !busy || phase === 'finishing';
        els.resume.hidden = !(phase === 'paused' || (phase === 'error' && upload && upload.status === 'uploading'));
        els.cancel.hidden = !(upload || saved) || phase === 'done' || phase === 'processing';
        els.files.disabled = els.folder.disabled = busy || phase === 'processing' || phase === 'done';
        if (els.note) els.note.disabled = !!upload;
        els.warning.hidden = !busy;
        els.drop.classList.toggle('is-busy', busy);

        // The interrupted upload, until its files are picked again.
        const showResume = !!saved && !upload && phase !== 'sending';
        els.resumeBanner.hidden = !showResume;
        if (showResume) {
            const total = saved.files.reduce((s, f) => s + f.size, 0);
            els.resumeText.textContent = `Your upload of ${saved.files.length === 1 ? `“${saved.files[0].name}”` : `${saved.files.length} files`} (${formatBytes(total)}) was interrupted. Choose the same ${saved.files.length === 1 ? 'file' : 'files'} again to carry on from where it stopped.`;
        }

        els.done.hidden = phase !== 'done';
        if (phase === 'done' && upload) {
            const t = els.done.querySelector('[data-abu-done-text]');
            if (t) t.textContent = upload.status === 'pending' || upload.status === 'approved'
                ? `“${upload.title}” is uploaded: ${statusLabel(upload.status).toLowerCase()}. You’ll get an email when it is in the library.`
                : statusLabel(upload.status);
        }

        // Progress: what the server has + what is in flight.
        const showProgress = selection.length > 0 && (upload || busy);
        els.progress.hidden = !showProgress;
        if (!showProgress) { els.list.innerHTML = ''; return; }
        const server = upload ? receivedBytes(upload) : { perFile: {}, total: 0 };
        const flying = {};
        for (const [k, slot] of inflight) {
            const index = Number(k.split(':')[0]);
            flying[index] = (flying[index] || 0) + slot.loaded;
        }
        const total = selection.reduce((s, f) => s + f.size, 0);
        const sent = Math.min(total, server.total + Object.values(flying).reduce((s, b) => s + b, 0));
        const doneAll = phase === 'done' || phase === 'processing' || phase === 'finishing';
        const shownSent = doneAll ? total : sent;
        els.totalBar.value = percent(shownSent, total);
        els.totalBar.textContent = `${percent(shownSent, total)}%`;
        const rate = speed.rate(Date.now());
        const left = eta(total - shownSent, rate);
        els.totalText.textContent = `${formatBytes(shownSent)} of ${formatBytes(total)} (${percent(shownSent, total)}%)`
            + (busy && rate > 0 ? ` · ${formatBytes(rate)}/s · ${formatDuration(left)} left` : '');

        if (els.list.children.length !== selection.length) {
            els.list.innerHTML = '';
            selection.forEach((f) => {
                const li = document.createElement('li');
                li.className = 'abu-file';
                li.innerHTML = '<span class="abu-file-name"></span><span class="abu-file-size mono-num"></span><progress class="abu-file-bar" max="100" value="0"></progress>';
                li.querySelector('.abu-file-name').textContent = f.name;
                els.list.append(li);
            });
        }
        selection.forEach((f, index) => {
            const li = els.list.children[index];
            const got = doneAll ? f.size : Math.min(f.size, (server.perFile[index] || 0) + (flying[index] || 0));
            li.querySelector('.abu-file-bar').value = percent(got, f.size);
            li.querySelector('.abu-file-size').textContent = got >= f.size ? formatBytes(f.size) : `${formatBytes(got)} / ${formatBytes(f.size)}`;
            li.classList.toggle('is-done', got >= f.size);
        });
    }

    // ---- Events ---------------------------------------------------------------------

    on(els.files, 'change', (e) => pick(e.currentTarget.files));
    on(els.folder, 'change', (e) => pick(e.currentTarget.files));
    on(els.start, 'click', startNew);
    on(els.pause, 'click', pause);
    on(els.resume, 'click', resume);
    on(els.cancel, 'click', cancel);
    on(els.resumeForget, 'click', async () => {
        if (saved) {
            await apiJson('DELETE', `${endpoint}/${saved.id}`);
            forgetSaved();
            render();
        }
    });
    on(els.drop, 'dragover', (e) => { e.preventDefault(); els.drop.classList.add('is-over'); });
    on(els.drop, 'dragleave', () => els.drop.classList.remove('is-over'));
    on(els.drop, 'drop', async (e) => {
        e.preventDefault();
        els.drop.classList.remove('is-over');
        if (sending()) return;
        pick(await filesFromDrop(e.dataTransfer));
    });
    const beforeUnload = (e) => {
        if (!sending()) return undefined;
        e.preventDefault();
        e.returnValue = '';
        return '';
    };
    on(window, 'beforeunload', beforeUnload);
    // Turbo visits do not fire beforeunload: ask, and pause when leaving.
    on(document, 'turbo:before-visit', (e) => {
        if (!sending()) return;
        if (!window.confirm('The audiobook is still uploading. Leave this page? It pauses, and carries on when you come back and pick the same files.')) {
            e.preventDefault();
        } else {
            pause();
        }
    });

    render();

    return {
        destroy() {
            if (sending()) pause();
            clearTimeout(pollTimer);
            for (const [t, type, fn, opts] of listeners) t.removeEventListener(type, fn, opts);
            listeners.length = 0;
        },
    };
}

function sleep(ms) {
    return new Promise((res) => setTimeout(res, ms));
}

function waitOnline() {
    return new Promise((res) => {
        if (navigator.onLine !== false) { res(); return; }
        const done = () => { window.removeEventListener('online', done); setTimeout(res, 1000); };
        window.addEventListener('online', done);
    });
}
