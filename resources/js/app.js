import './bootstrap';
// Self-hosted Inter (variable) — no render-blocking Google Fonts request,
// and typography keeps working offline in the PWA.
import '@fontsource-variable/inter';
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
