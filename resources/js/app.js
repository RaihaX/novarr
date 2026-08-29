import './bootstrap';
// Self-hosted brand faces (variable) — no render-blocking Google Fonts
// request, and typography keeps working offline in the PWA.
// Geist: the UI face. Geist Mono: counts, timestamps, chapter numbers,
// hostnames. Literata: the reading face (italic included for prose).
import '@fontsource-variable/geist';
import '@fontsource-variable/geist-mono';
import '@fontsource-variable/literata';
import '@fontsource-variable/literata/wght-italic.css';
// Inter stays in the stack as a fallback for the UI face.
import '@fontsource-variable/inter';
// Atkinson Hyperlegible: the reader's high-legibility font option.
import '@fontsource/atkinson-hyperlegible/400.css';
import '@fontsource/atkinson-hyperlegible/700.css';
import '@hotwired/turbo';
import * as bootstrap from 'bootstrap';

// Expose Bootstrap so inline page scripts can drive modals/toasts.
window.bootstrap = bootstrap;

import { executeCommand, pollJobStatus } from './commands';
import { showToast } from './toast';
import { confirmDialog } from './confirm';
import { initTagPickers } from './tagpicker';
import { initNavSearch } from './navsearch';
import {
    initOffline, downloadNovel, removeNovel, getLibrary,
    getNovel, isDownloaded, queuedFetch, flushQueue,
} from './offline';

// Refresh the current page while keeping the scroll position — a drop-in
// replacement for location.reload() on long pages (novel chapter tables).
function softRefresh(delay = 0) {
    setTimeout(() => {
        sessionStorage.setItem('novarr_restore_scroll', String(Math.round(window.scrollY)));
        if (window.Turbo) Turbo.visit(window.location.href, { action: 'replace' });
        else window.location.reload();
    }, delay);
}

document.addEventListener('turbo:load', () => {
    const y = sessionStorage.getItem('novarr_restore_scroll');
    if (y !== null) {
        sessionStorage.removeItem('novarr_restore_scroll');
        window.scrollTo(0, parseInt(y, 10) || 0);
    }
});

// Exposed for the thin page-specific glue scripts in Blade templates
// (inline scripts are not part of the Vite module graph).
window.Novarr = {
    executeCommand, pollJobStatus, showToast, confirmDialog, initTagPickers,
    downloadNovel, removeNovel, getLibrary, getNovel, isDownloaded,
    queuedFetch, flushQueue, softRefresh,
};

// Flush any queued offline read-marks and watch for reconnects.
initOffline();

// turbo:load fires on the first load and after every Turbo navigation, so
// page chrome (tag pickers, navbar search) is re-bound on each visit.
document.addEventListener('turbo:load', () => {
    initTagPickers();
    initNavSearch();
});

// Register the service worker (PWA / offline). Only works in a secure
// context (HTTPS / localhost); silently no-ops over plain http.
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

// ---- Custom PWA install prompt ----
// Browsers fire beforeinstallprompt when installable; show a small dismissible
// banner instead of relying on the browser's buried menu entry. Dismissal is
// remembered for 30 days.
window.addEventListener('beforeinstallprompt', (e) => {
    const standalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
    const snoozedUntil = parseInt(localStorage.getItem('pwa_install_snooze') || '0', 10);
    if (standalone || Date.now() < snoozedUntil) return;

    e.preventDefault();

    const bar = document.createElement('div');
    bar.className = 'pwa-install-bar';
    bar.innerHTML = `
        <span>Install Novarr as an app for offline reading.</span>
        <button type="button" class="btn btn-sm btn-primary" data-install>Install</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-dismiss aria-label="Dismiss">Not now</button>`;
    document.body.appendChild(bar);

    bar.querySelector('[data-install]').addEventListener('click', async () => {
        bar.remove();
        e.prompt();
        await e.userChoice.catch(() => {});
    });
    bar.querySelector('[data-dismiss]').addEventListener('click', () => {
        localStorage.setItem('pwa_install_snooze', String(Date.now() + 30 * 24 * 3600 * 1000));
        bar.remove();
    });
});
