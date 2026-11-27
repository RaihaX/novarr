import './bootstrap';
// This browser's device identity (localStorage + cookies), set before
// anything can navigate to a reader page: see device.js.
import { initDevice, getDevice, deviceHeaders } from './device';
// Self-hosted font faces live in ./fonts (Geist, Geist Mono, Literata;
// Atkinson Hyperlegible is loaded on the reader only). ./bootstrap exports
// the Bootstrap plugins in use and sets window.bootstrap for inline scripts.
import './fonts';
import '@hotwired/turbo';

import { executeCommand, pollJobStatus } from './commands';
import { showToast } from './toast';
import { confirmDialog, initConfirmForms } from './confirm';
import { initTagPickers } from './tagpicker';
import { initNavSearch } from './navsearch';
import { initTheme, setTheme, getTheme, cycleTheme } from './theme';
import { focusTrap, releaseFocusTrap, openSheet, closeSheet, closeAll, initSheets } from './modal';
import { initPalette, openPalette, closePalette } from './palette';
import { initFunnelBanner, setFunnelState } from './funnel';
import * as discoverPaging from './discover-paging';
import {
    initOffline, downloadNovel, removeNovel, getLibrary,
    getNovel, isDownloaded, queuedFetch, flushQueue,
} from './offline';
initDevice();

// Copy buttons (Settings → Users invite link); re-initialised per visit.
import { initCopyButtons } from './copy-link';
document.addEventListener('turbo:load', () => initCopyButtons());

// Passkeys: sign in, second step, confirm, add (Account); per visit.
import { initPasskeys } from './passkeys';
document.addEventListener('turbo:load', () => initPasskeys());

// Novel page chapter list (filter, selection); re-initialised per visit.
import { initNovelPage } from './novel';
document.addEventListener('turbo:load', initNovelPage);

// Reading lists: the novel page's Lists menu, the Library's list bar; per visit.
import { initLists } from './lists';
document.addEventListener('turbo:load', initLists);

// Built-in list toggles: the novel page's bookmark (Want to read) and heart (Favourites),
// and those pages' tiles; per visit.
import { initWantToRead } from './want-to-read';
document.addEventListener('turbo:load', initWantToRead);

// The audiobook player page (/audiobooks/{id}); leaving it saves the place and stops the audio.
import { initAudiobookPlayer, teardownAudiobookPlayer } from './audiobook-player';
document.addEventListener('turbo:load', initAudiobookPlayer);
document.addEventListener('turbo:before-render', teardownAudiobookPlayer);
document.addEventListener('turbo:before-cache', teardownAudiobookPlayer);

// "Search Usenet & torrents" (owner: wanted books, book pages, audiobooks, Downloads); per visit.
import { initDownloadFinders, teardownDownloadFinders } from './download-finder';
document.addEventListener('turbo:load', initDownloadFinders);
document.addEventListener('turbo:before-render', teardownDownloadFinders);

// Reader requests page (search, my requests, review); re-initialised per visit.
import { initRequestsPage } from './requests';
document.addEventListener('turbo:load', initRequestsPage);

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
    queuedFetch, flushQueue, softRefresh, setFunnelState,
    // Device identity for read-state writes — see device.js
    getDevice, deviceHeaders,
    // Shell (theme, sheets, palette) — see theme.js / modal.js / palette.js
    setTheme, getTheme, cycleTheme,
    focusTrap, releaseFocusTrap, openSheet, closeSheet, closeAll,
    openPalette, closePalette,
    // Discover "Load more" paging decisions — see discover-paging.js
    discoverPaging,
};

// Document-level listeners: bound once, survive Turbo body swaps.
initTheme();
initSheets();
initConfirmForms();
initPalette();

// Flush any queued offline read-marks and watch for reconnects.
initOffline();

// turbo:load fires on the first load and after every Turbo navigation, so
// page chrome (tag pickers, navbar search) is re-bound on each visit.
document.addEventListener('turbo:load', () => {
    initTagPickers();
    initNavSearch();
    initFunnelBanner();
});

// Register the service worker (PWA / offline). Only works in a secure
// context (HTTPS / localhost); silently no-ops over plain http.
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

// ---- Custom PWA install prompt ----
// Browsers fire beforeinstallprompt when installable. The event is held and
// the small banner is only offered once the visitor has actually read
// something (>= 2 chapters opened), at most once per browser session, never
// on the reader itself (it would sit over the text and the drawer), and not
// for 30 days after "Not now". All storage access is guarded — private
// windows and blocked site data throw.
const INSTALL_MIN_CHAPTERS = 2;

function storageGet(store, key) {
    try { return window[store].getItem(key); } catch (_) { return null; }
}
function storageSet(store, key, value) {
    try { window[store].setItem(key, value); } catch (_) { /* ignore */ }
}
function isReaderPage() {
    return !!document.querySelector('[data-reader-page]')
        || /^\/chapters\/\d+/.test(window.location.pathname);
}

// Count chapter opens (one per reader visit) so the nudge waits for real use.
document.addEventListener('turbo:load', () => {
    if (!isReaderPage()) return;
    const n = parseInt(storageGet('localStorage', 'novarr_chapters_opened') || '0', 10) || 0;
    storageSet('localStorage', 'novarr_chapters_opened', String(n + 1));
});

let deferredInstall = null;

function maybeShowInstallBar() {
    if (!deferredInstall || document.querySelector('.pwa-install-bar')) return;
    if (isReaderPage()) return;
    if (storageGet('sessionStorage', 'novarr_install_shown') === '1') return;
    const opened = parseInt(storageGet('localStorage', 'novarr_chapters_opened') || '0', 10) || 0;
    if (opened < INSTALL_MIN_CHAPTERS) return;

    const prompt = deferredInstall;
    storageSet('sessionStorage', 'novarr_install_shown', '1');

    const bar = document.createElement('div');
    bar.className = 'pwa-install-bar';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Install Novarr');
    bar.innerHTML = `
        <span class="pwa-install-text">Install Novarr as an app for offline reading.</span>
        <span class="pwa-install-actions">
            <button type="button" class="btn btn-sm btn-primary" data-install>Install</button>
            <button type="button" class="btn btn-sm btn-ghost" data-dismiss>Not now</button>
        </span>`;
    document.body.appendChild(bar);

    bar.querySelector('[data-install]').addEventListener('click', async () => {
        bar.remove();
        deferredInstall = null;
        prompt.prompt();
        await prompt.userChoice.catch(() => {});
    });
    bar.querySelector('[data-dismiss]').addEventListener('click', () => {
        storageSet('localStorage', 'pwa_install_snooze', String(Date.now() + 30 * 24 * 3600 * 1000));
        bar.remove();
    });
}

window.addEventListener('beforeinstallprompt', (e) => {
    const standalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
    const snoozedUntil = parseInt(storageGet('localStorage', 'pwa_install_snooze') || '0', 10);
    if (standalone || Date.now() < snoozedUntil) return;

    e.preventDefault();
    deferredInstall = e;
    maybeShowInstallBar();
});

// Turbo swaps <body>, which drops the bar; re-evaluate on every visit (this
// also removes it on the way into the reader).
document.addEventListener('turbo:load', maybeShowInstallBar);
