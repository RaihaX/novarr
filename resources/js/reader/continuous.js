// ---- Continuous reading: append the next chapter as you reach the end ----
// The next chapter's full page is fetched and only its section and
// #readerState are lifted out; the page's own <script> tags are never
// inserted, so the reader module is not evaluated again by an append.
const MAX_APPENDED = 15;

export function initContinuous(ctx) {
    const { signal, sections, sectionsEl } = ctx;
    const cta = document.getElementById('nextChapterCta');
    const sentinel = document.getElementById('readerSentinel');
    let inflight = null;
    ctx.autoLoadStopped = false;

    function updateCta(next) {
        if (!cta) return;
        cta.classList.toggle('d-none', !next);
        if (next) cta.href = next.url;
    }

    // Resolves once the append (or the decision not to) is done; concurrent
    // callers (sentinel + read-aloud) share the same in-flight load.
    function loadNextInline() {
        if (inflight) return inflight;
        const last = sections[sections.length - 1];
        if (ctx.autoLoadStopped || !last.next || ctx.store.prefs.autoNext !== '1') return Promise.resolve();
        if (sections.length > MAX_APPENDED) { ctx.autoLoadStopped = true; return Promise.resolve(); }
        inflight = appendNext(last).finally(() => { inflight = null; });
        return inflight;
    }
    ctx.loadNextInline = loadNextInline;

    async function appendNext(last) {
        try {
            // Tagged as a background fetch: the server must not mark the
            // chapter read just because we loaded it ahead of the reader.
            const res = await fetch(last.next.url, { headers: { 'Accept': 'text/html', 'X-Novarr-Fetch': 'continuous' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            if (signal.aborted) return;
            const nextState = JSON.parse(doc.getElementById('readerState').textContent);
            const fetched = doc.querySelector('.reader-section');
            const content = doc.getElementById('chapterContent');
            if (!nextState.hasContent || !content || !fetched) {
                // Pending chapter — stop auto-loading and leave the CTA as a
                // normal link so its page (with the download button) is reachable.
                ctx.autoLoadStopped = true;
                return;
            }

            const section = document.createElement('section');
            section.className = 'reader-section';
            section.dataset.id = nextState.id;
            section.dataset.words = fetched.dataset.words || '0';
            // Reuse the fetched page's own kicker/title block, so appended
            // chapters carry the same header as the one they were rendered with.
            const head = fetched.querySelector('.reader-head');
            if (head) section.appendChild(head);
            const body = document.createElement('div');
            body.className = 'chapter-content';
            body.innerHTML = content.innerHTML;
            section.appendChild(body);
            sectionsEl.appendChild(section);

            sections.push({ ...nextState, el: section, words: parseInt(section.dataset.words, 10) || 0 });
            updateCta(nextState.next);
            // The fetch did NOT mark it read; that happens once the reader
            // actually scrolls into it (sectionObserver below).
            if (nextState.deferRead) sectionObserver?.observe(section);
        } catch (err) {
            ctx.autoLoadStopped = true; // fall back to the CTA link
        }
    }

    let sentinelObserver = null;
    if (sentinel && 'IntersectionObserver' in window) {
        sentinelObserver = new IntersectionObserver((entries) => {
            if (entries.some(e => e.isIntersecting)) loadNextInline();
        }, { rootMargin: '600px 0px' });
        sentinelObserver.observe(sentinel);
    }

    // ---- Mark read on view ----
    // Chapters fetched in the background (inline continuous sections, or this
    // page if it was rendered from a Turbo prefetch) arrive with deferRead:
    // the server skipped the read-mark. Mark them via the progress endpoint
    // only when actually shown — for inline sections, once the section's top
    // passes 40% of the viewport (the same point findCurrentIdx switches the
    // "current" chapter). queuedFetch parks the write if we're offline.
    const markedOnView = new Set();
    function markReadOnView(id) {
        if (markedOnView.has(id) || signal.aborted) return;
        markedOnView.add(id);
        ctx.readFetch(`/chapters/${id}/progress`, { read: true })
            .then((data) => { if (data?.success) ctx.setSectionRead(id, true); })
            .catch(() => markedOnView.delete(id));
    }
    ctx.markReadOnView = markReadOnView;
    const sectionObserver = 'IntersectionObserver' in window
        ? new IntersectionObserver((entries) => {
            for (const e of entries) {
                if (!e.isIntersecting) continue;
                sectionObserver.unobserve(e.target);
                markReadOnView(parseInt(e.target.dataset.id, 10));
            }
        }, { rootMargin: '0px 0px -60% 0px' })
        : null;

    ctx.cta = cta;

    return function cleanup() {
        sentinelObserver?.disconnect();
        sectionObserver?.disconnect();
    };
}
