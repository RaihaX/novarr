// ---- Highlights: select text → floating save button; dictionary lookup;
// saved highlights drawn into the text, tap one for its note / Delete ----
import { matchExcerpt } from './excerpt-match.js';

// A keyboard (or assistive-tech) selection has no mouseup/touchend: open the
// popover once selectionchange has been quiet for this long.
const KEYBOARD_SELECTION_SETTLE_MS = 450;
/** How long a highlight opened from the Highlights page pulses. */
const PULSE_MS = 2400;

// Elements whose start breaks the text (Selection.toString() puts a newline
// there), and elements whose text is never prose.
const BREAKS = new Set(['P', 'DIV', 'LI', 'BLOCKQUOTE', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'PRE', 'TD', 'TH', 'TR',
    'TABLE', 'UL', 'OL', 'DL', 'DT', 'DD', 'SECTION', 'ARTICLE', 'ASIDE', 'HEADER', 'FOOTER', 'FIGURE', 'FIGCAPTION',
    'CENTER', 'HR', 'BR']);
const SKIP = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE']);

/** The text of `root` as matcher pieces (see excerpt-match.js): one per text node, plus breaks. */
function textPieces(root) {
    const pieces = [];
    let pendingBreak = false;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
        acceptNode: (n) => (n.nodeType === 1 && SKIP.has(n.tagName) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
    });
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        if (n.nodeType === 1) {
            if (BREAKS.has(n.tagName)) pendingBreak = true;
            continue;
        }
        if (pendingBreak && pieces.length) pieces.push({ text: '\n', virtual: true });
        pendingBreak = false;
        pieces.push({ text: n.nodeValue, node: n });
    }
    return pieces;
}

/** Replace each mark with its contents and merge the text nodes it split. */
function unwrapMarks(marks) {
    const parents = new Set();
    marks.forEach((mark) => {
        const parent = mark.parentNode;
        if (!parent) return;
        while (mark.firstChild) parent.insertBefore(mark.firstChild, mark);
        parent.removeChild(mark);
        parents.add(parent);
    });
    parents.forEach((p) => p.normalize());
}

/**
 * Wrap `hl`'s excerpt inside `content` in <mark class="reader-highlight">, one
 * per text node it covers. Returns the marks (none when it isn't found —
 * the text may have changed since it was saved; it is skipped silently).
 */
function drawHighlight(content, hl) {
    const pieces = textPieces(content);
    const match = matchExcerpt(pieces, hl.excerpt);
    if (!match) return [];
    const marks = [];
    for (const { index, from, to } of match.parts) {
        let node = pieces[index].node;
        if (from > 0) node = node.splitText(from);
        if (to - from < node.nodeValue.length) node.splitText(to - from);
        const mark = document.createElement('mark');
        mark.className = 'reader-highlight';
        if (hl.id !== null && hl.id !== undefined) mark.dataset.hlId = String(hl.id);
        node.parentNode.insertBefore(mark, node);
        mark.appendChild(node);
        marks.push(mark);
    }
    if (marks.length) {
        // Reachable by keyboard once (not once per text node).
        marks[0].tabIndex = 0;
        marks[0].setAttribute('aria-label', 'Highlight' + (hl.note ? ' with a note' : '') + ': ' + hl.excerpt.slice(0, 80));
        if (hl.note) marks[marks.length - 1].classList.add('has-note');
    }
    return marks;
}

export function initHighlights(ctx) {
    const { state, signal, sections } = ctx;
    const hlPop = document.getElementById('highlightPop');
    const hlNote = document.getElementById('hlNote');
    let hlPending = null;
    // Drawn highlights by mark: its data and the section it belongs to.
    const drawn = new WeakMap();
    let pulseTimer = null;

    // ---- Draw the saved highlights ----
    // Each section's highlights ride in its reader state (`highlights`):
    // the page's own, and those continuous reading lifts with a section.
    function drawSectionHighlights(section) {
        const content = section?.el?.querySelector('.chapter-content');
        if (!content) return;
        // A Turbo snapshot of this page may still carry marks: start clean.
        unwrapMarks([...content.querySelectorAll('mark.reader-highlight')]);
        if (!Array.isArray(section.highlights)) section.highlights = [];
        section.highlights.forEach((hl) => {
            drawHighlight(content, hl).forEach((m) => drawn.set(m, { hl, section }));
        });
    }
    ctx.drawSectionHighlights = drawSectionHighlights;
    sections.forEach(drawSectionHighlights);

    // The page's own chapter's highlights back into #readerState, so a Turbo
    // snapshot of this page (Back) draws what was saved or deleted since.
    function persistPageHighlights() {
        const el = document.getElementById('readerState');
        if (!el) return;
        try {
            const st = JSON.parse(el.textContent);
            st.highlights = (sections[0].highlights || []).map(({ id, excerpt, note }) => ({ id, excerpt, note }));
            st.focusHighlight = null;
            el.textContent = JSON.stringify(st);
        } catch (e) { /* leave it */ }
    }

    const marksOf = (id) => [...ctx.sectionsEl.querySelectorAll(`mark.reader-highlight[data-hl-id="${CSS.escape(String(id))}"]`)];

    // Opened from the Highlights page (`?highlight=<id>`): centre that
    // passage and pulse it. progress.js asks during its restore; false (not
    // in this chapter, or its text not found) restores the saved place.
    let revealed = false;
    ctx.revealFocusHighlight = () => {
        if (revealed || !state.focusHighlight) return false;
        revealed = true;
        const marks = marksOf(state.focusHighlight).filter((m) => sections[0].el.contains(m));
        if (!marks.length) return false;
        marks[0].scrollIntoView({ block: 'center', behavior: 'auto' });
        if (!ctx.reducedMotion()) {
            marks.forEach((m) => m.classList.add('is-pulse'));
            pulseTimer = setTimeout(() => marks.forEach((m) => m.classList.remove('is-pulse')), PULSE_MS);
        }
        persistPageHighlights();
        return true;
    };

    // Keep the selection alive while interacting with the popover (but let
    // the note field take the caret).
    hlPop.addEventListener('mousedown', (e) => { if (e.target !== hlNote) e.preventDefault(); });
    // Enter in the note saves.
    hlNote.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || e.isComposing || e.shiftKey) return;
        e.preventDefault();
        document.getElementById('hlSave').click();
    }, { signal });

    const hlDefine = document.getElementById('hlDefine');
    const hlDefinition = document.getElementById('hlDefinition');

    function hideHlPop() {
        hlPop.classList.add('d-none');
        hlDefinition.classList.add('d-none');
        hlDefinition.innerHTML = '';
        hlNote.value = '';
        hlPending = null;
    }

    function maybeShowHlPop() {
        const sel = window.getSelection();
        if (!sel || sel.isCollapsed) { hideHlPop(); return; }
        const text = sel.toString().trim();
        if (text.length < 2) { hideHlPop(); return; }

        const anchor = sel.anchorNode?.nodeType === 1 ? sel.anchorNode : sel.anchorNode?.parentElement;
        const section = anchor?.closest('.reader-section');
        if (!section || !anchor.closest('.chapter-content') || hlPop.contains(anchor)) { hideHlPop(); return; }

        hlPending = { chapter_id: section.dataset.id, excerpt: text.substring(0, 2000) };
        // Single word → offer a dictionary lookup too.
        hlDefine.classList.toggle('d-none', !/^[A-Za-z’'‐-]{2,30}$/.test(text));
        hlDefine.dataset.word = text;
        hlDefinition.classList.add('d-none');

        hideHlView();
        const rect = sel.getRangeAt(0).getBoundingClientRect();
        hlPop.classList.remove('d-none');
        placePop(hlPop, rect, 'above');
    }

    // Position a popover over/under `rect` (viewport coords), inside the
    // viewport's width with an 8px margin — a phone included.
    function placePop(pop, rect, prefer) {
        const vw = document.documentElement.clientWidth || window.innerWidth;
        const above = window.scrollY + rect.top - pop.offsetHeight - 10;
        const below = window.scrollY + rect.bottom + 10;
        const top = prefer === 'above' ? (rect.top - pop.offsetHeight - 10 < 8 ? below : above)
            : (rect.bottom + 10 + pop.offsetHeight > window.innerHeight - 8 && rect.top - pop.offsetHeight - 10 >= 8 ? above : below);
        pop.style.top = Math.max(8, top) + 'px';
        pop.style.left = Math.max(window.scrollX + 8, Math.min(window.scrollX + vw - pop.offsetWidth - 8,
            window.scrollX + rect.left + rect.width / 2 - pop.offsetWidth / 2)) + 'px';
    }

    // ---- Dictionary lookup (dictionaryapi.dev, single words) ----
    hlDefine.addEventListener('click', async () => {
        const word = (hlDefine.dataset.word || '').replace(/[’']/g, "'");
        hlDefinition.classList.remove('d-none');
        hlDefinition.textContent = 'Looking up…';
        try {
            const res = await fetch('https://api.dictionaryapi.dev/api/v2/entries/en/' + encodeURIComponent(word.toLowerCase()));
            if (!res.ok) throw new Error('not found');
            const entry = (await res.json())[0];
            hlDefinition.innerHTML = '';
            const head = document.createElement('div');
            head.className = 'fw-semibold mb-1';
            head.textContent = entry.word + (entry.phonetic ? '  ' + entry.phonetic : '');
            hlDefinition.appendChild(head);
            (entry.meanings || []).slice(0, 2).forEach(m => {
                const pos = document.createElement('div');
                pos.className = 'text-muted fst-italic';
                pos.textContent = m.partOfSpeech;
                hlDefinition.appendChild(pos);
                (m.definitions || []).slice(0, 2).forEach(d => {
                    const li = document.createElement('div');
                    li.textContent = '• ' + d.definition;
                    hlDefinition.appendChild(li);
                });
            });
        } catch (err) {
            hlDefinition.textContent = navigator.onLine
                ? `No definition found for “${word}”.`
                : 'Dictionary lookup needs an internet connection.';
        }
    });

    // Pointer selections open on release; while a button/finger is down the
    // selection is still being dragged, so the keyboard path stays out of it.
    let pointerDown = false;
    let settleTimer = null;
    document.addEventListener('pointerdown', () => { pointerDown = true; clearTimeout(settleTimer); }, { signal });
    document.addEventListener('pointerup', () => { pointerDown = false; }, { signal });
    document.addEventListener('pointercancel', () => { pointerDown = false; }, { signal });
    // A release inside either popover (the note field, a button) is working
    // in it, not finishing a selection.
    const inPopover = (t) => hlPop.contains(t) || !!document.getElementById('highlightView')?.contains(t);
    document.addEventListener('mouseup', (e) => { if (!inPopover(e.target)) setTimeout(maybeShowHlPop, 10); }, { signal });
    document.addEventListener('touchend', (e) => { if (!inPopover(e.target)) setTimeout(maybeShowHlPop, 150); }, { signal });
    document.addEventListener('selectionchange', () => {
        const sel = window.getSelection();
        if ((!sel || sel.isCollapsed) && !hlNote.matches(':focus')) hideHlPop();
        clearTimeout(settleTimer);
        if (pointerDown || !sel || sel.isCollapsed) return;
        settleTimer = setTimeout(() => {
            // Don't reposition (or hide) it while the user is working in it.
            if (pointerDown || hlPop.contains(document.activeElement)) return;
            maybeShowHlPop();
        }, KEYBOARD_SELECTION_SETTLE_MS);
    }, { signal });

    // Draw a just-saved highlight in its section (no reload needed).
    function addSaved(payload, id) {
        const section = sections.find((s) => String(s.id) === String(payload.chapter_id));
        if (!section) return;
        const hl = { id: id ?? null, excerpt: payload.excerpt, note: payload.note };
        if (!Array.isArray(section.highlights)) section.highlights = [];
        section.highlights.push(hl);
        const content = section.el.querySelector('.chapter-content');
        if (content) drawHighlight(content, hl).forEach((m) => drawn.set(m, { hl, section }));
        if (section === sections[0]) persistPageHighlights();
    }

    document.getElementById('hlSave').addEventListener('click', async () => {
        if (!hlPending) return;
        const payload = { ...hlPending, note: hlNote.value.trim() || null };
        const N = window.Novarr;
        hideHlPop();
        window.getSelection()?.removeAllRanges();
        try {
            let data;
            if (navigator.onLine === false && N?.queuedFetch) {
                // Offline: park it in the sync queue rather than failing.
                data = await N.queuedFetch(state.bookmarksUrl, { method: 'POST', body: payload });
            } else {
                const res = await fetch(state.bookmarksUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });
                data = await res.json();
            }
            if (data.success) addSaved(payload, data.queued ? null : data.id);
            if (data.success && data.queued) N.showToast('Highlight saved offline — it syncs when you reconnect.', 'info');
            else if (data.success) N.showToast('Highlight saved — find it under Highlights.', 'success');
            else N.showToast(data.message || 'Could not save the highlight.', 'danger');
        } catch (err) {
            N.showToast('Error: ' + err.message, 'danger');
        }
    });

    // ---- A saved highlight: tap / click (or Enter on it) → note + Delete ----
    const hlView = document.getElementById('highlightView');
    const hlViewNote = document.getElementById('hlViewNote');
    const hlViewDelete = document.getElementById('hlViewDelete');
    let viewing = null;   // { hl, section }

    function hideHlView() {
        if (!hlView || hlView.classList.contains('d-none')) return;
        hlView.classList.add('d-none');
        viewing = null;
    }

    function openHlView(mark) {
        const entry = drawn.get(mark);
        if (!entry || !hlView) return;
        hideHlPop();
        viewing = entry;
        const { hl } = entry;
        hlViewNote.textContent = hl.note || 'No note.';
        hlViewNote.classList.toggle('is-empty', !hl.note);
        // Saved offline and not synced yet: no id to delete by.
        const canDelete = hl.id !== null && hl.id !== undefined;
        hlViewDelete.classList.toggle('d-none', !canDelete);
        if (!canDelete) hlViewNote.textContent += ' (Saved offline — it can be deleted once it has synced.)';
        hlView.classList.remove('d-none');
        // Measure every mark of it, so the popover sits under the whole passage.
        const marks = marksOf(hl.id);
        const rect = (marks.length ? marks : [mark]).map((m) => m.getBoundingClientRect())
            .reduce((a, r) => ({ top: Math.min(a.top, r.top), bottom: Math.max(a.bottom, r.bottom), left: a.left, width: a.width }),
                { top: Infinity, bottom: -Infinity, left: mark.getBoundingClientRect().left, width: mark.getBoundingClientRect().width });
        placePop(hlView, rect, 'below');
        hlView.focus({ preventScroll: true });
    }

    ctx.sectionsEl.addEventListener('click', (e) => {
        const mark = e.target.closest?.('mark.reader-highlight');
        if (!mark) return;
        // Dragging a selection that starts on a mark is selecting, not tapping.
        const sel = window.getSelection();
        if (sel && !sel.isCollapsed) return;
        e.preventDefault();
        openHlView(mark);
    }, { signal });
    ctx.sectionsEl.addEventListener('keydown', (e) => {
        if ((e.key !== 'Enter' && e.key !== ' ') || !e.target.matches?.('mark.reader-highlight')) return;
        e.preventDefault();
        openHlView(e.target);
    }, { signal });
    // A tap anywhere else closes it.
    document.addEventListener('pointerdown', (e) => {
        if (hlView && !hlView.contains(e.target) && !e.target.closest?.('mark.reader-highlight')) hideHlView();
    }, { signal });
    hlView?.querySelector('[data-close-hlview]')?.addEventListener('click', hideHlView, { signal });

    hlViewDelete?.addEventListener('click', async () => {
        if (!viewing) return;
        const { hl, section } = viewing;
        const N = window.Novarr;
        hlViewDelete.disabled = true;
        try {
            const res = await fetch(`${state.bookmarksUrl}/${encodeURIComponent(hl.id)}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            });
            // 404: already deleted (elsewhere) — gone either way.
            const data = res.status === 404 ? { success: true } : await res.json();
            if (!data.success) throw new Error(data.message || 'HTTP ' + res.status);
            unwrapMarks(marksOf(hl.id));
            section.highlights = (section.highlights || []).filter((h) => h !== hl);
            if (section === sections[0]) persistPageHighlights();
            hideHlView();
            N?.showToast('Highlight deleted.', 'success');
        } catch (err) {
            N?.showToast(navigator.onLine === false ? 'Deleting a highlight needs a connection.' : 'Error: ' + err.message, 'danger');
        } finally {
            hlViewDelete.disabled = false;
        }
    }, { signal });

    // Esc closes either popover. Capture phase, marked as used, so focus mode
    // and the playback bar (which check defaultPrevented) leave it alone.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (hlView && !hlView.classList.contains('d-none')) {
            e.preventDefault();
            const mark = viewing && marksOf(viewing.hl.id)[0];
            hideHlView();
            mark?.focus?.({ preventScroll: true });
        } else if (!hlPop.classList.contains('d-none')) {
            e.preventDefault();
            hideHlPop();
            if (hlPop.contains(document.activeElement)) document.activeElement.blur();
        }
    }, { signal, capture: true });

    return function cleanup() {
        clearTimeout(settleTimer);
        clearTimeout(pulseTimer);
        hideHlPop();
        hideHlView();
        // Leave no marks in the Turbo snapshot: the next mount draws afresh.
        unwrapMarks([...document.querySelectorAll('#readerSections mark.reader-highlight')]);
    };
}
