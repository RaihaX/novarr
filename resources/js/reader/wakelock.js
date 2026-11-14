// Screen Wake Lock while auto-scroll or read-aloud runs. The browser drops
// the lock whenever the page is hidden, so it is re-requested on return.
// Feature-detected; every failure is silent (the screen just may dim).

export function createWakeLock(nav = globalThis.navigator, doc = globalThis.document) {
    let wanted = false;
    let sentinel = null;
    let requesting = null;

    async function acquire() {
        if (!nav?.wakeLock?.request || sentinel || requesting) return;
        if (doc?.visibilityState && doc.visibilityState !== 'visible') return;
        try {
            requesting = nav.wakeLock.request('screen');
            const lock = await requesting;
            if (!wanted) { lock.release?.().catch?.(() => {}); return; }
            sentinel = lock;
            lock.addEventListener?.('release', () => { if (sentinel === lock) sentinel = null; });
        } catch (e) {
            // Not allowed (no user activation, battery saver, unsupported).
        } finally {
            requesting = null;
        }
    }

    function drop() {
        const lock = sentinel;
        sentinel = null;
        try { lock?.release?.()?.catch?.(() => {}); } catch (e) { /* already released */ }
    }

    return {
        /** Hold the lock while `on` is true. */
        set(on) {
            wanted = !!on;
            return wanted ? acquire() : drop();
        },
        /** Call on visibilitychange: re-acquire after the tab comes back. */
        visibilityChanged() {
            if (wanted && doc?.visibilityState === 'visible') return acquire();
            return undefined;
        },
        release() {
            wanted = false;
            drop();
        },
        get held() { return !!sentinel; },
    };
}
