// ---- Highlights: select text → floating save button; dictionary lookup ----

// A keyboard (or assistive-tech) selection has no mouseup/touchend: open the
// popover once selectionchange has been quiet for this long.
const KEYBOARD_SELECTION_SETTLE_MS = 450;

export function initHighlights(ctx) {
    const { state, signal } = ctx;
    const hlPop = document.getElementById('highlightPop');
    const hlNote = document.getElementById('hlNote');
    let hlPending = null;

    // Keep the selection alive while interacting with the popover.
    hlPop.addEventListener('mousedown', e => e.preventDefault());

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
        if (!section || !anchor.closest('.chapter-content')) { hideHlPop(); return; }

        hlPending = { chapter_id: section.dataset.id, excerpt: text.substring(0, 2000) };
        // Single word → offer a dictionary lookup too.
        hlDefine.classList.toggle('d-none', !/^[A-Za-z’'‐-]{2,30}$/.test(text));
        hlDefine.dataset.word = text;
        hlDefinition.classList.add('d-none');

        const rect = sel.getRangeAt(0).getBoundingClientRect();
        hlPop.classList.remove('d-none');
        hlPop.style.top = Math.max(8, window.scrollY + rect.top - hlPop.offsetHeight - 10) + 'px';
        hlPop.style.left = Math.max(8, Math.min(window.innerWidth - hlPop.offsetWidth - 8,
            window.scrollX + rect.left + rect.width / 2 - hlPop.offsetWidth / 2)) + 'px';
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
    document.addEventListener('mouseup', () => setTimeout(maybeShowHlPop, 10), { signal });
    document.addEventListener('touchend', () => setTimeout(maybeShowHlPop, 150), { signal });
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
            if (data.success && data.queued) N.showToast('Highlight saved offline — it syncs when you reconnect.', 'info');
            else if (data.success) N.showToast('Highlight saved — find it under Highlights.', 'success');
            else N.showToast(data.message || 'Could not save the highlight.', 'danger');
        } catch (err) {
            N.showToast('Error: ' + err.message, 'danger');
        }
    });

    return function cleanup() {
        clearTimeout(settleTimer);
    };
}
