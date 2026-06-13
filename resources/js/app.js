import './bootstrap';
import '@hotwired/turbo';
import * as bootstrap from 'bootstrap';

// Expose Bootstrap so inline page scripts can drive modals/toasts.
window.bootstrap = bootstrap;

import { executeCommand, pollJobStatus } from './commands';
import { showToast } from './toast';
import { initTagPickers } from './tagpicker';
import { initNavSearch } from './navsearch';

// Exposed for the thin page-specific glue scripts in Blade templates
// (inline scripts are not part of the Vite module graph).
window.Novarr = { executeCommand, pollJobStatus, showToast, initTagPickers };

// turbo:load fires on the first load and after every Turbo navigation, so
// page chrome (tag pickers, navbar search) is re-bound on each visit.
document.addEventListener('turbo:load', () => {
    initTagPickers();
    initNavSearch();
});
