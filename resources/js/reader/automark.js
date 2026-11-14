// ---- Offline auto-mark ----
// The server marks a chapter read when it serves the page; offline the page
// comes from the cache, so queue the read-mark here instead.
//
// app.js is a module in <head> and this one is in <body>, so module order
// guarantees window.Novarr exists by now; the `load` fallbacks below are kept
// only for the case where app.js failed to evaluate.
export function initAutoMark(ctx) {
    const { state, signal } = ctx;

    if (!state.read) {
        const offlineAutoMark = () => {
            // A deferred page is marked by markReadOnView below instead.
            if (state.deferRead || navigator.onLine || !window.Novarr?.queuedFetch) return;
            window.Novarr.queuedFetch(state.bulkReadUrl, { method: 'POST', body: { ids: [state.id], read: true } });
            ctx.setSectionRead(state.id, true);
        };
        if (window.Novarr?.queuedFetch) offlineAutoMark();
        else window.addEventListener('load', offlineAutoMark, { once: true, signal });
    }

    // This page itself was served to a prefetch (e.g. Turbo hover prefetch)
    // and is now actually being shown — mark it read now. If the app module
    // isn't ready, wait for it so the write can be queued rather than lost.
    if (state.deferRead) {
        if (window.Novarr?.queuedFetch) ctx.markReadOnView(state.id);
        else window.addEventListener('load', () => ctx.markReadOnView(state.id), { once: true, signal });
    }
}
