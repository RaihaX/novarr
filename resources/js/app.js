import './bootstrap';
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

// Exposed for the thin page-specific glue scripts in Blade templates
// (inline scripts are not part of the Vite module graph).
window.Novarr = {
    executeCommand, pollJobStatus, showToast, confirmDialog, initTagPickers,
    downloadNovel, removeNovel, getLibrary, getNovel, isDownloaded,
    queuedFetch, flushQueue,
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
