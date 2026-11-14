// Read state controls: "Mark to here", the read toggle, "Mark read &
// continue" and the pending-chapter download button.
import { applyReadUi } from './footer.js';

export function initReadState(ctx) {
    const { state, sections } = ctx;
    const BULK_READ_URL = state.bulkReadUrl;
    const readToggle = document.getElementById('readToggle');

    // queuedFetch parks the write in IndexedDB if we're offline and replays it
    // on reconnect. app.js always runs first (it is the earlier module), so
    // the plain-fetch fallback only matters if app.js failed to load.
    function readFetch(url, body) {
        if (window.Novarr?.queuedFetch) {
            return window.Novarr.queuedFetch(url, { method: 'POST', body: body || null });
        }
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
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

    // ---- "Mark to here" (this + all earlier chapters) ----
    const readThrough = document.getElementById('readThrough');
    readThrough.addEventListener('click', async () => {
        readThrough.disabled = true;
        const id = readThrough.dataset.id;
        try {
            const data = await readFetch(`/chapters/${id}/read-through`);
            if (data.success) {
                const upTo = sections.findIndex(s => String(s.id) === String(id));
                sections.slice(0, upTo + 1).forEach(s => setSectionRead(s.id, true));
                window.Novarr.showToast(
                    data.queued
                        ? 'Saved offline — earlier chapters sync when you reconnect.'
                        : `Marked ${data.marked} earlier chapter(s) as read.`,
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
            ctx.visit(url);
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
