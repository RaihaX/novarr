// ---- Read-aloud (browser speechSynthesis) + Media Session ----
import { setPlayBtn } from './chrome.js';
import { chLabel } from './format.js';

export function initTts(ctx) {
    const { state, signal, sections } = ctx;
    const synth = window.speechSynthesis;
    const ttsPlayPause = document.getElementById('ttsPlayPause');
    const ttsStatus = document.getElementById('ttsStatus');
    let ttsRate = parseFloat(localStorage.getItem('reader_ttsrate') || '1');
    let ttsActive = false, ttsPausedState = false, ttsIdx = 0;
    // Only the utterance last handed to the synth may advance or stop
    // playback: cancel() makes the one it interrupts fire onerror/onend late.
    let currentUtterance = null;
    // Set when a pause lands while the next chapter is still loading, so
    // Resume starts there instead of resuming an empty synth.
    let resumeFrom = null;
    ctx.isSpeaking = () => ttsActive && !ttsPausedState;

    function reflectTtsRate() {
        document.querySelectorAll('#ttsRateGroup [data-ttsrate]').forEach(b => {
            const on = parseFloat(b.dataset.ttsrate) === ttsRate;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    const ttsParas = () => [...document.querySelectorAll('.chapter-content p')].filter(p => p.textContent.trim().length > 0);
    const sectionFor = p => sections.find(s => s.el.contains(p)) || sections[ctx.currentIdx];

    function ttsHighlight(p) {
        document.querySelectorAll('.tts-active').forEach(el => el.classList.remove('tts-active'));
        if (p) {
            p.classList.add('tts-active');
            p.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    // ---- Media Session: lock-screen / headset controls while speaking ----
    const media = 'mediaSession' in navigator ? navigator.mediaSession : null;
    let mediaSection = null;
    function setMediaMetadata(section) {
        if (!media || section === mediaSection || typeof window.MediaMetadata !== 'function') return;
        mediaSection = section;
        try {
            media.metadata = new window.MediaMetadata({
                title: chLabel(section, 200),
                artist: state.novelName || '',
                album: state.novelName || '',
            });
        } catch (e) { /* unsupported */ }
    }
    function setMediaState(playbackState) {
        if (!media) return;
        try { media.playbackState = playbackState; } catch (e) { /* unsupported */ }
    }
    function setMediaHandlers(on) {
        if (!media?.setActionHandler) return;
        const handlers = {
            play: () => (ttsActive ? ttsResume() : ttsStart()),
            pause: () => ttsPause(),
            previoustrack: () => ttsJumpChapter(-1),
            nexttrack: () => ttsJumpChapter(1),
        };
        for (const [action, handler] of Object.entries(handlers)) {
            try { media.setActionHandler(action, on ? handler : null); } catch (e) { /* action unsupported */ }
        }
        if (!on) {
            mediaSection = null;
            try { media.metadata = null; } catch (e) { /* unsupported */ }
            setMediaState('none');
        }
    }

    function ttsSpeakFrom(idx) {
        const paras = ttsParas();
        if (idx >= paras.length) { continueIntoNext(idx); return; }
        ttsIdx = idx;
        const p = paras[idx];
        ttsHighlight(p);
        setMediaMetadata(sectionFor(p));
        ttsStatus.textContent = `Paragraph ${idx + 1} of ${paras.length}`;
        const u = new SpeechSynthesisUtterance(p.textContent);
        u.rate = ttsRate;
        u.onend = () => { if (u === currentUtterance && ttsActive && !ttsPausedState) ttsSpeakFrom(ttsIdx + 1); };
        u.onerror = () => { if (u === currentUtterance) ttsStopAll(); };
        currentUtterance = u;
        synth.speak(u);
    }

    // Reached the last loaded paragraph: with continuous reading on and a
    // next chapter available, append it inline and keep speaking into it.
    async function continueIntoNext(idx) {
        const last = sections[sections.length - 1];
        if (!last.next || ctx.store.prefs.autoNext !== '1' || ctx.autoLoadStopped) { ttsStopAll(); return; }
        ttsStatus.textContent = 'Loading the next chapter…';
        await ctx.loadNextInline();
        if (!ttsActive || signal.aborted) return;
        if (idx >= ttsParas().length) { ttsStopAll(); return; }
        if (ttsPausedState) { resumeFrom = idx; return; }
        ttsSpeakFrom(idx);
    }

    // Media keys: jump to the start of the previous / next loaded chapter
    // (loading the next one inline if needed); "previous" in the first
    // loaded chapter restarts it.
    async function ttsJumpChapter(delta) {
        if (!ttsActive) return;
        const from = sections.indexOf(sectionFor(ttsParas()[ttsIdx]));
        let target = sections[Math.max(0, from + delta)];
        if (!target && delta > 0) {
            await ctx.loadNextInline();
            if (!ttsActive || signal.aborted) return;
            target = sections[from + delta];
        }
        if (!target) return;
        const start = ttsParas().findIndex(p => target.el.contains(p));
        if (start < 0) return;
        currentUtterance = null;
        synth.cancel();
        ttsPausedState = false;
        resumeFrom = null;
        setPlayBtn(ttsPlayPause, 'Pause', true);
        setMediaState('playing');
        ctx.updateWakeLock();
        ttsSpeakFrom(start);
    }

    function ttsStart() {
        if (!synth) { window.Novarr.showToast('Text-to-speech is not supported in this browser.', 'warning'); return; }
        currentUtterance = null;
        synth.cancel();
        ttsActive = true;
        ttsPausedState = false;
        resumeFrom = null;
        setPlayBtn(ttsPlayPause, 'Pause', true);
        setMediaHandlers(true);
        setMediaState('playing');
        ctx.updateWakeLock();
        // Start from the first paragraph currently in view.
        const paras = ttsParas();
        const ref = window.scrollY + 90;
        let start = 0;
        for (let i = 0; i < paras.length; i++) {
            if (paras[i].getBoundingClientRect().top + window.scrollY + paras[i].offsetHeight > ref) { start = i; break; }
        }
        ttsSpeakFrom(start);
    }

    function ttsPause() {
        if (!ttsActive || ttsPausedState) return;
        ttsPausedState = true;
        synth.pause();
        setPlayBtn(ttsPlayPause, 'Resume', false);
        setMediaState('paused');
        ctx.updateWakeLock();
    }

    function ttsResume() {
        if (!ttsActive || !ttsPausedState) return;
        ttsPausedState = false;
        setPlayBtn(ttsPlayPause, 'Pause', true);
        setMediaState('playing');
        ctx.updateWakeLock();
        if (resumeFrom !== null) {
            const idx = resumeFrom;
            resumeFrom = null;
            ttsSpeakFrom(idx);
        } else {
            synth.resume();
        }
    }

    function ttsStopAll() {
        ttsActive = false;
        ttsPausedState = false;
        resumeFrom = null;
        currentUtterance = null;
        synth?.cancel();
        ttsHighlight(null);
        setPlayBtn(ttsPlayPause, 'Play', false);
        ttsStatus.textContent = '';
        setMediaHandlers(false);
        ctx.updateWakeLock();
    }
    ctx.ttsStopAll = ttsStopAll;

    ttsPlayPause.addEventListener('click', () => {
        if (!ttsActive) { ttsStart(); return; }
        if (ttsPausedState) ttsResume();
        else ttsPause();
    }, { signal });
    document.getElementById('ttsStop').addEventListener('click', ttsStopAll, { signal });
    document.querySelectorAll('[data-ttsrate]').forEach(btn => btn.addEventListener('click', () => {
        ttsRate = parseFloat(btn.dataset.ttsrate);
        localStorage.setItem('reader_ttsrate', ttsRate);
        reflectTtsRate();
        if (ttsActive && !ttsPausedState) { currentUtterance = null; synth.cancel(); ttsSpeakFrom(ttsIdx); }
    }));
    reflectTtsRate();

    // Never leave speech running after leaving the page.
    document.addEventListener('turbo:before-visit', ttsStopAll, { once: true, signal });
    window.addEventListener('pagehide', () => synth?.cancel(), { once: true, signal });
}
