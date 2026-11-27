// The reader's own chrome: the "Aa" popover / bottom sheet and its tabs, the
// docked playback bar and the "?" shortcuts overlay.
import { clampNum, FONT_MIN, FONT_MAX, MEASURE_MIN, MEASURE_MAX, MEASURE_STEP } from './prefs.js';

// Label + play/pause glyph on a playback button (icons are server-rendered).
export function setPlayBtn(btn, label, playing) {
    btn.querySelector('.pb-text').textContent = label;
    btn.querySelector('.pb-play')?.classList.toggle('d-none', playing);
    btn.querySelector('.pb-pause')?.classList.toggle('d-none', !playing);
    btn.classList.toggle('is-active', playing);
}

export function initChrome(ctx) {
    const { signal, dialogs: { holds, holdDialog, releaseDialog } } = ctx;

    // ---- "Aa" popover (desktop) / bottom sheet (phones, <768px) ----
    // Same element either way; CSS decides the presentation. The backdrop
    // only renders on phones, where it dims the page and closes on tap.
    const settingsBtn = document.getElementById('readerSettingsBtn');
    const settingsPop = document.getElementById('readerSettings');
    const backdrop = document.getElementById('readerBackdrop');
    let settingsHold = null;
    function toggleSettings(show) {
        const open = show ?? settingsPop.classList.contains('d-none');
        if (open === !settingsPop.classList.contains('d-none')) return;
        if (!open) settingsHold = releaseDialog(settingsHold);
        settingsPop.classList.toggle('d-none', !open);
        backdrop.classList.toggle('d-none', !open);
        document.body.classList.toggle('reader-sheet-open', open);
        settingsBtn.classList.toggle('is-active', open);
        settingsBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            // The sheet lives inside the chrome; a translated (auto-hidden)
            // chrome would become the containing block of the fixed sheet.
            ctx.setChromeHidden(false);
            settingsHold = holdDialog(settingsPop, settingsBtn);
            // Move focus into the dialog (the container, so no ring flashes on a tab).
            settingsPop.focus({ preventScroll: true });
        }
    }
    ctx.toggleSettings = toggleSettings;
    ctx.settingsPop = settingsPop;
    settingsBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleSettings(); }, { signal });
    settingsPop.addEventListener('click', (e) => e.stopPropagation(), { signal });
    settingsPop.querySelector('[data-close-pop]').addEventListener('click', () => { toggleSettings(false); settingsBtn.focus(); }, { signal });
    backdrop.addEventListener('click', () => toggleSettings(false), { signal });
    document.addEventListener('click', () => toggleSettings(false), { signal });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !settingsPop.classList.contains('d-none')) {
            e.preventDefault();
            toggleSettings(false);
            settingsBtn.focus();
        }
    }, { signal });

    // Tabs: Text / Layout / Playback. Arrow keys move between tabs (WAI-ARIA
    // tabs pattern); the last-used tab is remembered per device.
    const TAB_KEY = 'reader_settings_tab';
    const tabs = [...settingsPop.querySelectorAll('[role="tab"]')];
    function selectTab(name, focus = false) {
        if (!tabs.some(t => t.dataset.tab === name)) name = 'text';
        tabs.forEach(t => {
            const on = t.dataset.tab === name;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            t.classList.toggle('is-active', on);
            if (on && focus) t.focus();
        });
        settingsPop.querySelectorAll('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== name; });
        try { localStorage.setItem(TAB_KEY, name); } catch (e) { /* storage blocked */ }
    }
    tabs.forEach((t, i) => {
        t.addEventListener('click', () => selectTab(t.dataset.tab), { signal });
        t.addEventListener('keydown', (e) => {
            const d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
            if (!d) return;
            e.preventDefault();
            selectTab(tabs[(i + d + tabs.length) % tabs.length].dataset.tab, true);
        }, { signal });
    });
    let savedTab = 'text';
    try { savedTab = localStorage.getItem(TAB_KEY) || 'text'; } catch (e) { /* storage blocked */ }
    selectTab(savedTab);

    // ---- Playback bar (Listen + Auto-scroll), docked at the bottom ----
    const playbackBtn = document.getElementById('playbackBtn');
    const playbackBar = document.getElementById('readerPlayback');
    ctx.playbackBar = playbackBar;
    // Deliberately non-modal (aria-modal="false", no trap, no inert): it is
    // meant to stay docked while you read, scroll, select text and follow the
    // Next link during auto-scroll / read-aloud. Focus still moves into it on
    // open and Esc / Close return focus to the Playback button.
    function togglePlayback(show) {
        const open = show ?? playbackBar.classList.contains('d-none');
        playbackBar.classList.toggle('d-none', !open);
        document.body.classList.toggle('reader-playbar-open', open);
        playbackBtn.classList.toggle('is-active', open);
        playbackBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) playbackBar.querySelector('button')?.focus({ preventScroll: true });
    }
    function closePlayback() {
        ctx.stopAutoScroll();
        ctx.ttsStopAll();
        const hadFocus = playbackBar.contains(document.activeElement);
        togglePlayback(false);
        if (hadFocus || document.activeElement === document.body) playbackBtn.focus({ preventScroll: true });
    }
    playbackBtn.addEventListener('click', () => { toggleSettings(false); togglePlayback(); }, { signal });
    playbackBar.querySelector('[data-close-playback]').addEventListener('click', closePlayback, { signal });
    // Esc closes it from anywhere — unless a modal (sheet, drawer, "?") is on
    // top, in which case that modal takes the Esc. preventDefault marks the
    // Esc as used, so focus mode (navigation.js) doesn't also act on it.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || e.defaultPrevented || holds.size) return;
        if (playbackBar.classList.contains('d-none') || document.querySelector('.offcanvas.show, .modal.show')) return;
        e.preventDefault();
        closePlayback();
    }, { signal });

    // ---- "?" keyboard-shortcuts overlay (modal) ----
    const keysPanel = document.getElementById('readerKeys');
    let keysHold = null;
    function toggleKeys(show, trigger = document.activeElement) {
        const open = show ?? keysPanel.classList.contains('d-none');
        if (open === !keysPanel.classList.contains('d-none')) return;
        if (open) {
            toggleSettings(false);
            keysPanel.classList.remove('d-none');
            document.body.classList.add('reader-keys-open');
            // Back to the opener if it's a real control, else the Aa button.
            const back = trigger && trigger !== document.body && !keysPanel.contains(trigger) && !settingsPop.contains(trigger) ? trigger : settingsBtn;
            keysHold = holdDialog(keysPanel, back, ['#reader']);
            keysPanel.focus({ preventScroll: true });
        } else {
            keysHold = releaseDialog(keysHold);
            keysPanel.classList.add('d-none');
            document.body.classList.remove('reader-keys-open');
        }
    }
    settingsPop.querySelector('[data-open-keys]').addEventListener('click', () => toggleKeys(true, settingsBtn), { signal });
    keysPanel.querySelector('[data-close-keys]').addEventListener('click', () => toggleKeys(false), { signal });
    // A click outside the panel (it lands on the inert page → body) dismisses.
    document.addEventListener('click', (e) => {
        if (!keysPanel.classList.contains('d-none') && !keysPanel.contains(e.target)) toggleKeys(false);
    }, { signal });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !keysPanel.classList.contains('d-none')) { e.preventDefault(); toggleKeys(false); return; }
        if (e.key !== '?' || e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.target.matches('input, textarea, select, [contenteditable]')) return;
        if (document.querySelector('.offcanvas.show, .modal.show')) return;
        e.preventDefault();
        toggleKeys();
    }, { signal });

    // ---- Preference controls ----
    const { prefs } = ctx.store;
    const bindPref = (attr, apply) => settingsPop.querySelectorAll(`[data-${attr}]`).forEach(btn =>
        btn.addEventListener('click', () => { apply(btn.dataset[attr]); ctx.applyPrefs(); }, { signal }));
    const persist = (k, v) => ctx.store.persist(k, v);

    bindPref('font', v => persist('font', clampNum(prefs.font + (v === '+' ? 1 : -1), FONT_MIN, FONT_MAX, 19)));
    bindPref('measure', v => persist('measure', clampNum(prefs.measure + (v === '+' ? MEASURE_STEP : -MEASURE_STEP), MEASURE_MIN, MEASURE_MAX, 68)));
    bindPref('theme', v => persist('theme', v));
    bindPref('family', v => persist('family', v));
    bindPref('lineheight', v => persist('lineHeight', v));
    bindPref('autonext', v => persist('autoNext', v));
    bindPref('margin', v => persist('margin', v));
    bindPref('justify', v => persist('justify', v));
    bindPref('pagemode', v => { persist('pageMode', v === 'pages' ? 'pages' : 'scroll'); ctx.applyPageMode?.(); });

    document.getElementById('perNovelPrefs').addEventListener('change', (e) => {
        ctx.store.setPerNovel(e.target.checked);
        if (e.target.checked) {
            window.Novarr?.showToast('Reader settings for this novel are now independent.', 'info');
        } else {
            window.Novarr?.showToast('Back to your global reader settings.', 'info');
        }
        ctx.applyPrefs();
    });
}
