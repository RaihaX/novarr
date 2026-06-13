import './bootstrap';

import { executeCommand, pollJobStatus } from './commands';
import { showToast } from './toast';
import { initTagPickers } from './tagpicker';
import { initNavSearch } from './navsearch';

// Exposed for the thin page-specific glue scripts in Blade templates
// (inline scripts are not part of the Vite module graph).
window.Novarr = { executeCommand, pollJobStatus, showToast, initTagPickers };

document.addEventListener('DOMContentLoaded', () => {
    initTagPickers();
    initNavSearch();
});
