/**
 * App-wide "Funnel is on" warning banner.
 *
 * Tailscale Funnel state is not a stored setting — it is read live from the
 * tailscale CLI by GET /settings/tailscale. Spawning that on every page view
 * would be wasteful, so the last known state is kept in localStorage and
 * re-checked in the background at most every CHECK_TTL. The Settings page
 * pushes fresh state through setFunnelState() whenever it loads or toggles.
 * Every storage access is guarded (private windows / blocked storage throw).
 */
const KEY = 'novarr_funnel_state';
const CHECK_TTL = 10 * 60 * 1000;
const STATUS_URL = '/settings/tailscale';

function read() {
    try {
        const v = JSON.parse(localStorage.getItem(KEY) || 'null');
        return v && typeof v === 'object' ? v : null;
    } catch (_) {
        return null;
    }
}

function write(on) {
    try {
        localStorage.setItem(KEY, JSON.stringify({ on: !!on, checked: Date.now() }));
    } catch (_) { /* ignore */ }
}

function render(on) {
    const banner = document.getElementById('funnelBanner');
    if (banner) banner.classList.toggle('d-none', !on);
}

export function setFunnelState(on) {
    write(on);
    render(on);
}

async function refresh() {
    try {
        const res = await fetch(STATUS_URL, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const d = await res.json();
        setFunnelState(!!(d.available && d.funnel));
    } catch (_) {
        /* offline or Tailscale unreachable: keep the last known state */
    }
}

export function initFunnelBanner() {
    const state = read();
    render(!!state?.on);
    if (!state || Date.now() - (state.checked || 0) > CHECK_TTL) {
        refresh();
    }
}
