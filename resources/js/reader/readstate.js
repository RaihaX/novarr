// Read state controls: "Mark to here", the read toggle, "Mark read &
// continue" and the pending-chapter download button.
import { applyReadUi } from './footer.js';
import { deviceHeaders } from '../device.js';
import { markLocalWrite } from './latest-position.js';

/** "Mark to here" asks first when it would change more chapters than this. */
export const READ_THROUGH_CONFIRM_OVER = 20;

/** "1 chapter" / "12 chapters". */
export const chapterCount = (n) => `${n} chapter${n === 1 ? '' : 's'}`;

/** The toast after "Mark to here" marked `n` chapters. */
export function readThroughMessage(n) {
    n = Number(n) || 0;
    return n === 0
        ? 'Nothing to mark — every chapter up to here was already read.'
        : `Marked ${chapterCount(n)} as read.`;
}

export function initReadState(ctx) {
    const { state, sections } = ctx;
    const BULK_READ_URL = state.bulkReadUrl;
    const readToggle = document.getElementById('readToggle');

    // queuedFetch parks the write in IndexedDB if we're offline and replays it
    // on reconnect. app.js always runs first (it is the earlier module), so
    // the plain-fetch fallback only matters if app.js failed to load.
    function readFetch(url, body) {
        // A progress-endpoint write (mark read on view) is "reading here" for
        // the latest-position prompt; bulk marking is not (see the contract).
        // A look-up chapter's mark (`lookup`, see continuous.js) is not.
        if (/\/progress$/.test(url) && !body?.lookup) {
            try { markLocalWrite(localStorage, state.novelId); } catch (e) { /* storage blocked */ }
        }
        if (window.Novarr?.queuedFetch) {
            return window.Novarr.queuedFetch(url, { method: 'POST', body: body || null });
        }
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
                ...deviceHeaders(),
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
        }).then((r) => r.json());
    }
    ctx.readFetch = readFetch;

    // Record a chapter's read state on its section; the footer toggle shows
    // the current section, so only repaint it when that's the one that changed.
    function setSectionRead(id, read) {
        const s = sections.find(x => String(x.id) === String(id));
        if (s) s.read = read;
        if (String(sections[ctx.currentIdx]?.id) === String(id)) applyReadUi(readToggle, read);
    }
    ctx.setSectionRead = setSectionRead;

    // How many chapters "Mark to here" would change (the endpoint's dry
    // run). Null when it can't be asked (offline): the mark then goes ahead
    // through the offline queue unconfirmed, as before.
    async function readThroughCount(id) {
        if (navigator.onLine === false) return null;
        try {
            const res = await fetch(`/chapters/${id}/read-through`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ dry_run: true }),
            });
            if (!res.ok) return null;
            const data = await res.json();
            return Number.isFinite(data?.marked) ? data.marked : null;
        } catch (e) {
            return null;
        }
    }

    // ---- "Mark to here" (this + all earlier chapters) ----
    const readThrough = document.getElementById('readThrough');
    readThrough.addEventListener('click', async () => {
        readThrough.disabled = true;
        const id = readThrough.dataset.id;
        try {
            // Many chapters at once is hard to undo by hand: confirm first.
            const pending = await readThroughCount(id);
            if (pending !== null && pending > READ_THROUGH_CONFIRM_OVER) {
                const ok = await window.Novarr.confirmDialog(
                    `This marks ${chapterCount(pending)} as read — this one and every earlier unread chapter.`,
                    { title: 'Mark to here?', confirmText: `Mark ${chapterCount(pending)} read` }
                );
                if (!ok) return;
            }
            const data = await readFetch(`/chapters/${id}/read-through`);
            if (data.success) {
                const upTo = sections.findIndex(s => String(s.id) === String(id));
                sections.slice(0, upTo + 1).forEach(s => setSectionRead(s.id, true));
                window.Novarr.showToast(
                    data.queued
                        ? 'Saved offline — earlier chapters sync when you reconnect.'
                        : readThroughMessage(data.marked),
                    data.queued ? 'info' : 'success'
                );
            }
        } catch (err) {
            window.Novarr.showToast('Error: ' + err.message, 'danger');
        } finally {
            readThrough.disabled = false;
        }
    });

    // ---- Manual read/unread toggle ----
    // Uses the idempotent bulk-read endpoint (set, not toggle) so a queued
    // replay applies the exact state we intended regardless of ordering.
    readToggle.addEventListener('click', async () => {
        readToggle.disabled = true;
        const id = readToggle.dataset.id;
        const desired = readToggle.dataset.read !== '1';
        try {
            const data = await readFetch(BULK_READ_URL, { ids: [id], read: desired });
            if (data.success) {
                setSectionRead(id, desired);
                if (data.queued) window.Novarr.showToast('Saved offline — will sync when you reconnect.', 'info');
            }
        } catch (err) {
            window.Novarr.showToast('Error: ' + err.message, 'danger');
        } finally {
            readToggle.disabled = false;
        }
    });

    // ---- "Mark read & continue": the dominant binge-reading action ----
    const cta = ctx.cta;
    if (cta) {
        cta.addEventListener('click', async (e) => {
            e.preventDefault();
            const cur = sections[ctx.currentIdx];
            const url = cta.href;
            cta.classList.add('is-busy');
            try {
                await readFetch(BULK_READ_URL, { ids: [cur.id], read: true });
                setSectionRead(cur.id, true);
            } catch (err) {
                // Navigating still marks it read server-side when the page loads.
            }
            ctx.visitAdjacent(url);
        });
    }

    // ---- "Mark read" when the next chapter isn't downloaded: stay here ----
    const stay = document.getElementById('markReadStay');
    if (stay) {
        stay.addEventListener('click', async () => {
            const cur = sections[ctx.currentIdx];
            stay.disabled = true;
            try {
                const data = await readFetch(BULK_READ_URL, { ids: [cur.id], read: true });
                if (data.success) {
                    setSectionRead(cur.id, true);
                    window.Novarr.showToast(
                        data.queued
                            ? 'Saved offline — will sync when you reconnect.'
                            : 'Marked read. The next chapter isn’t downloaded yet.',
                        data.queued ? 'info' : 'success'
                    );
                }
            } catch (err) {
                window.Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                stay.disabled = false;
            }
        });
    }

    // ---- Pending chapter: fetch just this one on demand ----
    const dlBtn = document.getElementById('downloadChapterBtn');
    if (dlBtn) {
        dlBtn.addEventListener('click', async () => {
            const N = window.Novarr;
            dlBtn.disabled = true;
            dlBtn.querySelector('.dl-label').classList.add('d-none');
            dlBtn.querySelector('.dl-spinner').classList.remove('d-none');
            try {
                const result = await N.executeCommand({ command: 'download_chapter', chapter_id: dlBtn.dataset.id });
                if (result.success) {
                    N.showToast('Chapter downloaded — loading it now…', 'success');
                    N.softRefresh(600);
                    return;
                }
                N.showToast(result.output || result.error || 'Download failed — the source may not have this chapter yet.', 'danger');
            } catch (err) {
                N.showToast('Error: ' + err.message, 'danger');
            }
            dlBtn.disabled = false;
            dlBtn.querySelector('.dl-label').classList.remove('d-none');
            dlBtn.querySelector('.dl-spinner').classList.add('d-none');
        });
    }
}
