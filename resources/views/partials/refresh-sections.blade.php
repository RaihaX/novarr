{{-- Self-refreshing panels: every <section data-refresh-url="…"> on the page
     is re-fetched every data-refresh-every seconds (default 10) while the tab
     is visible, and once more when it becomes visible again. The server
     renders the panel (all text escaped there); this only swaps it in. The
     endpoint answers JSON {html: "…"} (Activity → Downloads) or the HTML
     fragment itself (Readers → Reading now). Stops when Turbo leaves the
     page. Include it once, inside @push('scripts'). --}}
<script>
(function () {
    const sections = Array.from(document.querySelectorAll('[data-refresh-url]'));
    if (sections.length === 0) return;

    const timers = [];
    const leave = new AbortController();

    async function refresh(section) {
        if (document.visibilityState !== 'visible') return;
        try {
            const response = await fetch(section.dataset.refreshUrl, { headers: { 'Accept': 'application/json, text/html' } });
            // A redirect (signed out: the login page) is not the panel.
            if (!response.ok || response.redirected) return;
            if ((response.headers.get('Content-Type') || '').includes('application/json')) {
                const data = await response.json();
                if (typeof data.html === 'string') section.innerHTML = data.html;
            } else {
                section.innerHTML = await response.text();
            }
        } catch (e) {
            // Keep polling; a transient error is fine.
        }
    }

    function stop() {
        timers.forEach(clearInterval);
        timers.length = 0;
        leave.abort();
    }

    sections.forEach((section) => {
        const every = Math.max(5, parseInt(section.dataset.refreshEvery, 10) || 10);
        timers.push(setInterval(() => refresh(section), every * 1000));
    });
    const opts = { signal: leave.signal, once: true };
    document.addEventListener('turbo:before-render', stop, opts);
    document.addEventListener('turbo:before-cache', stop, opts);
    window.addEventListener('pagehide', stop, opts);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') sections.forEach(refresh);
    }, { signal: leave.signal });
})();
</script>
