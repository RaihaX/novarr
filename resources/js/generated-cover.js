/**
 * "Generate a cover" / "Regenerate cover" on a series page (the owner; AI
 * covers, App\Covers). The DOM glue; the decisions are in
 * ./generated-cover-logic.js.
 *
 * The menu button [data-generate-cover][data-url] asks for a confirmation,
 * POSTs to queue the painting, then polls the status URL; the line under the
 * cover (#aiCoverStatus) says "Painting… ~1 min" and, once done, the new
 * cover is swapped in (the page is reloaded when there was no image yet).
 * A painting already under way when the page loads (#aiCoverStatus
 * [data-pending]) is followed the same way.
 *
 * Bound per Turbo visit (turbo:load); polling stops on leaving the page.
 */
import { confirmDialog } from './confirm';
import { isRunning, pollDelay, statusText, freshUrl, GIVE_UP_AFTER } from './generated-cover-logic';

let timer = null;

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

function show(state, message) {
    const line = document.getElementById('aiCoverStatus');
    if (!line) return;
    const text = statusText(state, message);
    line.textContent = text;
    line.hidden = text === '';
    line.classList.toggle('is-failed', state === 'failed');
}

function swapCover(url) {
    const img = document.querySelector('[data-cover-slot] img');
    const src = freshUrl(url);
    if (img && src) {
        img.removeAttribute('srcset');
        img.src = src;
        return;
    }
    if (window.Turbo) window.Turbo.visit(window.location.href, { action: 'replace' });
    else window.location.reload();
}

function poll(statusUrl, started = Date.now(), attempt = 0) {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        let data;
        try {
            const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
            data = await response.json();
        } catch {
            data = null;
        }
        if (data && isRunning(data.state)) {
            show(data.state, data.message);
            if (Date.now() - started < GIVE_UP_AFTER) poll(statusUrl, started, attempt + 1);
            return;
        }
        if (data?.state === 'done') {
            show('done');
            swapCover(data.cover_url);
            return;
        }
        if (data?.state === 'failed') {
            show('failed', data.message);
            return;
        }
        // Lost the server for a moment: keep trying a while.
        if (Date.now() - started < GIVE_UP_AFTER) poll(statusUrl, started, attempt + 1);
    }, pollDelay(attempt));
}

async function start(button) {
    const ok = await confirmDialog(button.dataset.confirmText || 'Paint a new cover with AI?', {
        title: 'AI cover',
        confirmText: 'Paint it',
    });
    if (!ok) return;
    show('queued');
    let data = null;
    try {
        const response = await fetch(button.dataset.url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        });
        data = await response.json().catch(() => null);
        if (!response.ok && response.status !== 202) {
            show('failed', data?.message || `The request failed (HTTP ${response.status}).`);
            return;
        }
    } catch {
        show('failed', 'Novarr could not be reached. Try again.');
        return;
    }
    show(data?.state || 'queued', data?.message);
    if (data?.status_url) poll(data.status_url);
}

export function initGeneratedCover() {
    const button = document.querySelector('[data-generate-cover]');
    if (button && !button.dataset.bound) {
        button.dataset.bound = '1';
        button.addEventListener('click', () => start(button));
    }
    const line = document.getElementById('aiCoverStatus');
    if (line?.dataset.pending && line.dataset.statusUrl) {
        show(line.dataset.pending);
        poll(line.dataset.statusUrl);
    }
}

export function teardownGeneratedCover() {
    clearTimeout(timer);
    timer = null;
}
