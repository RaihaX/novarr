// Text reader: the "latest position" prompt ("Chapter 124 · 40% was read on
// Kindle 2 hours ago. Go there?"). The decision is in latest-position.js;
// this is the reader glue.
//
// planLatest() runs before initProgress (its restore reads ctx.latestPlan);
// initLatest() runs after it and shows the banner. Only for a real open of
// the page: the continuous reader appending the next chapter never mounts
// the reader again, and next / previous navigation inside the reader leaves
// a one-use sessionStorage marker (markInReaderNav) that suppresses it.
import { getDevice } from '../device.js';
import {
    planLatest as decide, consumeAccepted, consumeInReaderNav, markInReaderNav,
    markAccepted, rememberAnswered, markLocalWrite, promptText,
} from './latest-position.js';
import { showLatestPrompt } from './latest-prompt.js';

const posKey = (id) => 'reader_pos_' + id;

function storageOf(name) {
    try {
        const s = window[name];
        s.getItem('novarr');
        return s;
    } catch (e) {
        return null;
    }
}

export function planLatest(ctx) {
    const { state } = ctx;
    const storage = storageOf('localStorage');
    const session = storageOf('sessionStorage');
    const loadedAt = Date.now();
    const position = state.latest_position ?? null;

    const accepted = consumeAccepted(session, state.id, loadedAt);
    const inReaderNav = consumeInReaderNav(session, state.id, loadedAt);
    let local = null;
    try { local = parseInt(storage?.getItem(posKey(state.id)) || '', 10); } catch (e) { local = null; }

    const { offer, sameChapter } = decide({
        position,
        device: getDevice(),
        chapterId: state.id,
        novelId: state.novelId,
        storage,
        localProgress: Number.isNaN(local) ? null : local,
        accepted,
        inReaderNav,
        online: navigator.onLine !== false,
        now: loadedAt,
    });
    ctx.latestPlan = { offer, sameChapter, accepted, position, loadedAt, storage, session };
    return ctx.latestPlan;
}

export function initLatest(ctx) {
    const { root, signal, state } = ctx;
    const plan = ctx.latestPlan;

    // The footer's previous / next links and "Mark read & continue" are
    // in-reader navigation too (keys and swipes go through ctx.visitAdjacent).
    document.addEventListener('click', (e) => {
        const a = e.target.closest?.('a#navPrev, a#navNext, a#nextChapterCta');
        if (a?.href) {
            try { markInReaderNav(sessionStorage, a.href); } catch (err) { /* storage blocked */ }
        }
    }, { capture: true, signal });

    if (!plan?.offer) return;
    const { position, storage, session, loadedAt } = plan;
    const ageNow = () => (Number(position.age_seconds) || 0) + (Date.now() - loadedAt) / 1000;

    showLatestPrompt({
        host: root,
        text: promptText(position, ageNow()),
        signal,
        announcer: document.getElementById('readerAnnounce'),
        escBlocked: () => ctx.dialogs.holds.size > 0 || !!document.querySelector('.offcanvas.show, .modal.show'),
        onStay: () => rememberAnswered(storage, state.novelId, position),
        onGo: () => {
            rememberAnswered(storage, state.novelId, position);
            const pct = Math.max(0, Math.min(100, Number(position.progress) || 0));
            if (plan.sameChapter) {
                // The scroll itself saves the position (updateProgress).
                ctx.scrollToProgress(pct);
                try {
                    if (pct >= 98) storage?.removeItem(posKey(state.id));
                    else storage?.setItem(posKey(state.id), String(pct));
                } catch (e) { /* storage blocked */ }
                markLocalWrite(storage, state.novelId);
                return;
            }
            // Another chapter: its page must open at the other device's
            // position even if this browser has an older one saved there.
            try {
                if (pct >= 98) storage?.removeItem(posKey(position.chapter_id));
                else storage?.setItem(posKey(position.chapter_id), String(pct));
            } catch (e) { /* storage blocked */ }
            markAccepted(session, position);
            ctx.visit(position.url);
        },
    });
}
