@extends('layouts.app')

@push('styles')
@if($next)
    <link rel="prefetch" href="{{ route('chapters.show', $next->id) }}">
@endif
@if($prev)
    <link rel="prefetch" href="{{ route('chapters.show', $prev->id) }}">
@endif
@endpush

@section('content')
{{-- Thin reading-progress bar (fixed to the viewport top) --}}
<div id="readProgressBar" aria-hidden="true"><div id="readProgressFill"></div></div>

<div class="mb-3 d-flex flex-wrap gap-2 justify-content-between align-items-center" id="readerToolbar">
    <a href="{{ route('novels.show', $chapter->novel_id) }}" class="btn btn-outline-secondary btn-sm text-truncate" style="max-width: 100%;">&larr; {{ $chapter->novel->name ?? 'Back' }}</a>
    <div class="d-flex gap-2 align-items-center">
        <button type="button" id="tocBtn" class="btn btn-sm btn-outline-secondary" data-bs-toggle="offcanvas" data-bs-target="#tocPanel" title="Chapter list" aria-label="Chapter list">☰</button>
        <button type="button" id="focusBtn" class="btn btn-sm btn-outline-secondary" title="Focus mode (hide chrome — tap the page to peek)" aria-label="Focus mode">⛶</button>
        <button type="button" id="readerSettingsBtn" class="btn btn-sm btn-outline-secondary" title="Reading settings" aria-label="Reading settings">Aa</button>
        @if($prev)
            <a href="{{ route('chapters.show', $prev->id) }}" id="navPrev" class="btn btn-sm btn-outline-secondary">&larr; Ch. {{ $prev->chapter }}</a>
        @endif
        @if($next)
            <a href="{{ route('chapters.show', $next->id) }}" id="navNext" class="btn btn-sm btn-outline-secondary">Ch. {{ $next->chapter }} &rarr;</a>
        @endif
    </div>
</div>

{{-- Reading settings panel --}}
<div id="readerSettings" class="card mb-3 d-none">
    <div class="card-body d-flex flex-wrap gap-4 align-items-center" style="font-size: 13px;">
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted">Font size</span>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary" data-font="-">A−</button>
                <button type="button" class="btn btn-outline-secondary" data-font="+">A+</button>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted">Width</span>
            <div class="btn-group btn-group-sm" id="widthGroup" role="group" aria-label="Reading width">
                <button type="button" class="btn btn-outline-secondary" data-width="narrow">Narrow</button>
                <button type="button" class="btn btn-outline-secondary" data-width="medium">Medium</button>
                <button type="button" class="btn btn-outline-secondary" data-width="wide">Wide</button>
                <button type="button" class="btn btn-outline-secondary" data-width="full">Full</button>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted">Theme</span>
            <div class="btn-group btn-group-sm" id="themeGroup" role="group" aria-label="Reading theme">
                <button type="button" class="btn btn-outline-secondary" data-theme="dark">Dark</button>
                <button type="button" class="btn btn-outline-secondary" data-theme="sepia">Sepia</button>
                <button type="button" class="btn btn-outline-secondary" data-theme="light">Light</button>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted">Font</span>
            <div class="btn-group btn-group-sm" id="familyGroup" role="group" aria-label="Font family">
                <button type="button" class="btn btn-outline-secondary" data-family="sans">Sans</button>
                <button type="button" class="btn btn-outline-secondary" data-family="serif">Serif</button>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted">Spacing</span>
            <div class="btn-group btn-group-sm" id="lineHeightGroup" role="group" aria-label="Line spacing">
                <button type="button" class="btn btn-outline-secondary" data-lineheight="1.5">Compact</button>
                <button type="button" class="btn btn-outline-secondary" data-lineheight="1.8">Normal</button>
                <button type="button" class="btn btn-outline-secondary" data-lineheight="2.1">Relaxed</button>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted">Auto-next</span>
            <div class="btn-group btn-group-sm" id="autoNextGroup" role="group" aria-label="Auto-load next chapter while scrolling">
                <button type="button" class="btn btn-outline-secondary" data-autonext="1" title="Load the next chapter inline when you reach the end">On</button>
                <button type="button" class="btn btn-outline-secondary" data-autonext="0">Off</button>
            </div>
        </div>
    </div>
</div>

<div id="readerSections">
    <section class="reader-section" data-id="{{ $chapter->id }}">
        <div class="card mb-4 reader-card" id="readerCard">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <h4 class="mb-1">{{ $chapter->label ?: 'Chapter ' . $chapter->chapter }}</h4>
                        <div class="d-flex gap-3 flex-wrap" style="font-size: 13px;">
                            <span class="text-muted">Chapter {{ $chapter->chapter }}</span>
                            @if($chapter->book)
                                <span class="text-muted">Book {{ $chapter->book }}</span>
                            @endif
                            @if($chapter->status)
                                <span class="badge bg-success" style="font-size: 11px;">Downloaded</span>
                            @else
                                <span class="badge bg-warning text-dark" style="font-size: 11px;">Pending</span>
                            @endif
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-shrink-0">
                        <button type="button" id="readThrough" class="btn btn-sm btn-outline-secondary" data-id="{{ $chapter->id }}" title="Mark this and all earlier chapters as read">Mark to here</button>
                        <button type="button" id="readToggle" class="btn btn-sm {{ $chapter->read_at ? 'btn-success' : 'btn-outline-secondary' }}" data-id="{{ $chapter->id }}" data-read="{{ $chapter->read_at ? '1' : '0' }}" aria-pressed="{{ $chapter->read_at ? 'true' : 'false' }}">
                            {{ $chapter->read_at ? '✓ Read' : 'Mark read' }}
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                @if($chapter->getRawOriginal('description'))
                    <div class="chapter-content" id="chapterContent">
                        {!! $chapter->description !!}
                    </div>
                @else
                    <div class="text-center py-5">
                        <p class="text-muted mb-3">No content available for this chapter yet.</p>
                        <button type="button" id="downloadChapterBtn" class="btn btn-primary" data-id="{{ $chapter->id }}">
                            <span class="dl-label">Download this chapter now</span>
                            <span class="dl-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Downloading…</span>
                        </button>
                        <div class="form-text mt-2">Fetches just this chapter from the source in the background.</div>
                    </div>
                @endif
            </div>
        </div>
    </section>
</div>

<div id="readerSentinel" aria-hidden="true"></div>

@if($next)
    {{-- Prominent end-of-chapter action: mark this chapter read and move on,
         the dominant interaction when reading a series straight through. --}}
    <a href="{{ route('chapters.show', $next->id) }}" id="nextChapterCta" class="btn btn-primary next-chapter-cta mb-3">
        Next: {{ Str::limit($next->label ?: 'Chapter ' . $next->chapter, 50) }} &rarr;
    </a>
@else
    <div id="endOfNovelNote" class="text-center text-muted mb-3 py-2">You're all caught up — no next chapter yet.</div>
@endif

<div class="chapter-nav justify-content-between">
    @if($prev)
        <a href="{{ route('chapters.show', $prev->id) }}" class="btn btn-outline-secondary text-truncate">&larr; {{ Str::limit($prev->label ?: 'Ch. ' . $prev->chapter, 40) }}</a>
    @endif
    @if($next)
        <a href="{{ route('chapters.show', $next->id) }}" class="btn btn-outline-secondary text-truncate">{{ Str::limit($next->label ?: 'Ch. ' . $next->chapter, 40) }} &rarr;</a>
    @endif
</div>

{{-- In-reader chapter list --}}
<div class="offcanvas offcanvas-start" tabindex="-1" id="tocPanel" aria-labelledby="tocPanelLabel">
    <div class="offcanvas-header pb-2">
        <h5 class="offcanvas-title" id="tocPanelLabel">Chapters</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0 d-flex flex-column">
        <div class="p-2 border-bottom">
            <input type="search" id="tocFilter" class="form-control form-control-sm" placeholder="Filter by number or title…" aria-label="Filter chapters">
        </div>
        <div id="tocList" class="list-group list-group-flush overflow-auto flex-grow-1" style="font-size: 13px;">
            <div class="p-3 text-muted">Loading…</div>
        </div>
    </div>
</div>

<script type="application/json" id="readerState">@json($readerState)</script>
@endsection

@push('scripts')
<script>
(() => {
    const state = JSON.parse(document.getElementById('readerState').textContent);

    // ---- Reader preferences (persisted in localStorage) ----
    const prefs = {
        font: parseInt(localStorage.getItem('reader_font') || '18', 10),
        width: localStorage.getItem('reader_width') || 'medium',
        theme: localStorage.getItem('reader_theme') || 'dark',
        family: localStorage.getItem('reader_family') || 'sans',
        lineHeight: localStorage.getItem('reader_lineheight') || '1.8',
        autoNext: localStorage.getItem('reader_autonext') || '1',
    };

    const families = {
        sans: "var(--bs-body-font-family)",
        serif: "Georgia, 'Times New Roman', serif",
    };
    const widths = { narrow: '600px', medium: '760px', wide: '960px', full: '1200px' };

    function styleContent(el) {
        el.style.fontSize = prefs.font + 'px';
        el.style.maxWidth = widths[prefs.width] || widths.medium;
        el.style.fontFamily = families[prefs.family] || families.sans;
        el.style.lineHeight = prefs.lineHeight;
    }

    function applyPrefs() {
        document.querySelectorAll('.chapter-content').forEach(styleContent);
        // Theme recolours the whole page via a body class (styled in app.scss),
        // not just the card — so focus mode and mobile gutters match the theme.
        document.body.classList.remove('reader-theme-sepia', 'reader-theme-light');
        if (prefs.theme === 'sepia' || prefs.theme === 'light') {
            document.body.classList.add('reader-theme-' + prefs.theme);
        }
        document.body.setAttribute('data-bs-theme', prefs.theme === 'dark' ? 'dark' : 'light');

        // reflect active buttons + announce state to assistive tech
        const reflect = (sel, key, val) => document.querySelectorAll(sel).forEach(b => {
            const on = b.dataset[key] === val;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        reflect('#widthGroup [data-width]', 'width', prefs.width);
        reflect('#themeGroup [data-theme]', 'theme', prefs.theme);
        reflect('#familyGroup [data-family]', 'family', prefs.family);
        reflect('#lineHeightGroup [data-lineheight]', 'lineheight', prefs.lineHeight);
        reflect('#autoNextGroup [data-autonext]', 'autonext', prefs.autoNext);
    }

    document.getElementById('readerSettingsBtn').addEventListener('click', () => {
        document.getElementById('readerSettings').classList.toggle('d-none');
    });

    const bindPref = (attr, apply) => document.querySelectorAll(`[data-${attr}]`).forEach(btn =>
        btn.addEventListener('click', () => { apply(btn.dataset[attr]); applyPrefs(); }));

    bindPref('font', v => {
        prefs.font = Math.min(36, Math.max(13, prefs.font + (v === '+' ? 1 : -1)));
        localStorage.setItem('reader_font', prefs.font);
    });
    bindPref('width', v => { prefs.width = v; localStorage.setItem('reader_width', v); });
    bindPref('theme', v => { prefs.theme = v; localStorage.setItem('reader_theme', v); });
    bindPref('family', v => { prefs.family = v; localStorage.setItem('reader_family', v); });
    bindPref('lineheight', v => { prefs.lineHeight = v; localStorage.setItem('reader_lineheight', v); });
    bindPref('autonext', v => { prefs.autoNext = v; localStorage.setItem('reader_autonext', v); });

    // ---- Sections: the initial chapter + any continuously-loaded ones ----
    // Each entry mirrors the per-page readerState so navigation and progress
    // always refer to the chapter actually under the viewport.
    const sectionsEl = document.getElementById('readerSections');
    const sections = [{
        ...state,
        el: sectionsEl.querySelector('.reader-section'),
    }];
    let currentIdx = 0;

    const sectionTop = s => s.el.getBoundingClientRect().top + window.scrollY;
    const sectionHeight = s => s.el.offsetHeight || 1;

    function findCurrentIdx() {
        const ref = window.scrollY + window.innerHeight * 0.4;
        let idx = 0;
        for (let i = 0; i < sections.length; i++) {
            if (sectionTop(sections[i]) <= ref) idx = i;
        }
        return idx;
    }

    function onSectionChange(idx) {
        currentIdx = idx;
        const cur = sections[idx];
        // Keep the address bar, title and toolbar nav in sync with the
        // chapter being read, so reloads/bookmarks land on the right one.
        history.replaceState(history.state, '', cur.url);
        const navPrev = document.getElementById('navPrev');
        const navNext = document.getElementById('navNext');
        if (navPrev && cur.prev) { navPrev.href = cur.prev.url; navPrev.innerHTML = '&larr; Ch. ' + cur.prev.chapter; }
        if (navNext && cur.next) { navNext.href = cur.next.url; navNext.innerHTML = 'Ch. ' + cur.next.chapter + ' &rarr;'; }
    }

    // ---- Keyboard + swipe navigation, relative to the current section ----
    function visit(url) {
        if (!url) return;
        if (window.Turbo) Turbo.visit(url);
        else window.location.href = url;
    }
    function goPrev() {
        const cur = sections[currentIdx];
        if (currentIdx > 0) {
            window.scrollTo({ top: sectionTop(sections[currentIdx - 1]), behavior: 'smooth' });
        } else {
            visit(cur.prev?.url);
        }
    }
    function goNext() {
        const cur = sections[currentIdx];
        if (currentIdx < sections.length - 1) {
            window.scrollTo({ top: sectionTop(sections[currentIdx + 1]), behavior: 'smooth' });
        } else {
            visit(cur.next?.url);
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.target.matches('input, textarea, select')) return;
        if (e.key === 'ArrowLeft') goPrev();
        if (e.key === 'ArrowRight') goNext();
    });

    // Horizontal swipe on touch devices: left = next, right = previous.
    // Ignored when it starts on a control, while text is selected, or when
    // it's mostly vertical (normal scrolling).
    let touchStart = null;
    document.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 1 || e.target.closest('a, button, input, select, textarea, .offcanvas')) {
            touchStart = null;
            return;
        }
        touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY, t: Date.now() };
    }, { passive: true });
    document.addEventListener('touchend', (e) => {
        if (!touchStart) return;
        const dx = e.changedTouches[0].clientX - touchStart.x;
        const dy = e.changedTouches[0].clientY - touchStart.y;
        const dt = Date.now() - touchStart.t;
        touchStart = null;
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;
        if (dt > 600 || Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 2) return;
        if (dx < 0) goNext(); else goPrev();
    }, { passive: true });

    // ---- Focus mode: hide chrome; tap the page to peek at it ----
    const focusBtn = document.getElementById('focusBtn');
    function applyFocus() {
        const on = localStorage.getItem('reader_focus') === '1';
        document.body.classList.toggle('reader-focus', on);
        document.body.classList.remove('chrome-peek');
        focusBtn.classList.toggle('active', on);
    }
    focusBtn.addEventListener('click', () => {
        const turningOn = localStorage.getItem('reader_focus') !== '1';
        localStorage.setItem('reader_focus', turningOn ? '1' : '0');
        applyFocus();
        if (turningOn && !localStorage.getItem('reader_focus_hint')) {
            localStorage.setItem('reader_focus_hint', '1');
            window.Novarr?.showToast('Focus mode on — tap the page to show the controls.', 'info');
        }
    });
    applyFocus();

    sectionsEl.addEventListener('click', (e) => {
        if (!document.body.classList.contains('reader-focus')) return;
        if (e.target.closest('a, button, input, select, textarea')) return;
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;
        document.body.classList.toggle('chrome-peek');
    });

    applyPrefs();

    // ---- Reading position: local restore + cross-device sync ----
    // Position is a percentage through the current chapter (device-independent),
    // stored locally for instant restore and synced to the server so another
    // device can resume in the same place.
    const posKey = id => 'reader_pos_' + id;

    function currentProgressPct() {
        const cur = sections[currentIdx];
        const pct = ((window.scrollY + window.innerHeight - sectionTop(cur)) / sectionHeight(cur)) * 100;
        return Math.max(0, Math.min(100, Math.round(pct)));
    }

    let lastSynced = { id: null, pct: -1 };
    function syncProgress(id, pct, useBeacon = false) {
        if (lastSynced.id === id && Math.abs(lastSynced.pct - pct) < 5 && pct < 98) return;
        lastSynced = { id, pct };
        if (useBeacon && navigator.sendBeacon) {
            const form = new FormData();
            form.append('progress', String(pct));
            navigator.sendBeacon(`/chapters/${id}/progress`, form);
            return;
        }
        window.Novarr?.queuedFetch?.(`/chapters/${id}/progress`, { method: 'POST', body: { progress: pct } }).catch(() => {});
    }

    let scrollSaveTimer = null;
    let syncTimer = null;
    function updateProgress() {
        const idx = findCurrentIdx();
        if (idx !== currentIdx) onSectionChange(idx);
        const cur = sections[currentIdx];
        const pct = currentProgressPct();

        document.getElementById('readProgressFill').style.width = pct + '%';

        clearTimeout(scrollSaveTimer);
        scrollSaveTimer = setTimeout(() => {
            if (pct >= 98) localStorage.removeItem(posKey(cur.id));
            else localStorage.setItem(posKey(cur.id), String(pct));
        }, 250);

        if (!syncTimer) {
            syncTimer = setTimeout(() => {
                syncTimer = null;
                syncProgress(sections[currentIdx].id, currentProgressPct());
            }, 10000);
        }
    }
    window.addEventListener('scroll', updateProgress, { passive: true });
    window.addEventListener('pagehide', () => syncProgress(sections[currentIdx].id, currentProgressPct(), true));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') syncProgress(sections[currentIdx].id, currentProgressPct(), true);
    });

    // Restore: local position wins (freshest on this device), else the synced
    // server position from another device. Legacy pixel keys still honoured.
    (function restorePosition() {
        let pct = parseInt(localStorage.getItem(posKey(state.id)) || '', 10);
        if (isNaN(pct)) pct = state.progress;
        const legacyPx = parseInt(localStorage.getItem('reader_scroll_' + state.id) || '0', 10);
        localStorage.removeItem('reader_scroll_' + state.id);

        requestAnimationFrame(() => {
            if (pct !== null && !isNaN(pct) && pct > 2 && pct < 98) {
                const s = sections[0];
                window.scrollTo(0, Math.max(0, sectionTop(s) + (pct / 100) * sectionHeight(s) - window.innerHeight));
            } else if (legacyPx > 200) {
                window.scrollTo(0, legacyPx);
            }
            updateProgress();
        });
    })();

    // ---- Continuous reading: append the next chapter as you reach the end ----
    const MAX_APPENDED = 15;
    const cta = document.getElementById('nextChapterCta');
    const sentinel = document.getElementById('readerSentinel');
    let loadingNext = false;
    let autoLoadStopped = false;

    function updateCta(next) {
        if (!cta) return;
        if (next) {
            cta.href = next.url;
            cta.innerHTML = 'Next: ' + (next.label || 'Chapter ' + next.chapter).substring(0, 50) + ' &rarr;';
            cta.classList.remove('d-none');
        } else {
            cta.classList.add('d-none');
        }
    }

    async function loadNextInline() {
        const last = sections[sections.length - 1];
        if (loadingNext || autoLoadStopped || !last.next || prefs.autoNext !== '1') return;
        if (sections.length > MAX_APPENDED) { autoLoadStopped = true; return; }

        loadingNext = true;
        try {
            const res = await fetch(last.next.url, { headers: { 'Accept': 'text/html' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            const nextState = JSON.parse(doc.getElementById('readerState').textContent);
            const content = doc.getElementById('chapterContent');

            if (!nextState.hasContent || !content) {
                // Pending chapter — stop auto-loading and leave the CTA as a
                // normal link so its page (with the download button) is reachable.
                autoLoadStopped = true;
                return;
            }

            const section = document.createElement('section');
            section.className = 'reader-section';
            section.dataset.id = nextState.id;
            section.innerHTML = `
                <div class="reader-section-divider" role="separator">
                    <span></span>
                </div>
                <div class="card mb-4 reader-card">
                    <div class="card-header py-2">
                        <h5 class="mb-0"></h5>
                    </div>
                    <div class="card-body">
                        <div class="chapter-content"></div>
                    </div>
                </div>`;
            section.querySelector('.reader-section-divider span').textContent = nextState.label;
            section.querySelector('.card-header h5').textContent = nextState.label;
            section.querySelector('.chapter-content').innerHTML = content.innerHTML;
            styleContent(section.querySelector('.chapter-content'));
            sectionsEl.appendChild(section);

            sections.push({ ...nextState, el: section });
            updateCta(nextState.next);
            // Fetching the page marked it read server-side (same as opening it).
        } catch (err) {
            autoLoadStopped = true; // fall back to the CTA link
        } finally {
            loadingNext = false;
        }
    }

    if (sentinel && 'IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
            if (entries.some(e => e.isIntersecting)) loadNextInline();
        }, { rootMargin: '600px 0px' }).observe(sentinel);
    }

    // ---- In-reader chapter list (offcanvas TOC) ----
    const tocList = document.getElementById('tocList');
    const tocFilter = document.getElementById('tocFilter');
    let tocChapters = null;
    const TOC_WINDOW = 120;

    function tocItem(c, currentId) {
        const a = document.createElement('a');
        a.href = '/chapters/' + c.id;
        a.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2 py-2'
            + (c.id === currentId ? ' active' : '') + (!c.downloaded ? ' toc-pending' : '');
        const label = document.createElement('span');
        label.className = 'text-truncate';
        label.textContent = (c.read ? '✓ ' : '') + (c.label || 'Chapter ' + c.chapter);
        const meta = document.createElement('span');
        meta.className = 'text-nowrap ' + (c.id === currentId ? '' : 'text-muted');
        meta.style.fontSize = '11px';
        meta.textContent = c.downloaded ? 'Ch. ' + c.chapter : 'pending';
        a.append(label, meta);
        a.addEventListener('click', (e) => {
            if (window.Turbo) { e.preventDefault(); Turbo.visit(a.href); }
        });
        return a;
    }

    function renderToc(filter = '') {
        if (!tocChapters) return;
        const currentId = sections[currentIdx].id;
        tocList.innerHTML = '';

        let rows = tocChapters;
        if (filter) {
            const f = filter.toLowerCase();
            rows = tocChapters.filter(c =>
                String(c.chapter).startsWith(f) || (c.label || '').toLowerCase().includes(f)
            ).slice(0, 200);
        } else {
            const i = Math.max(0, tocChapters.findIndex(c => c.id === currentId));
            const from = Math.max(0, i - TOC_WINDOW);
            const to = Math.min(tocChapters.length, i + TOC_WINDOW);
            if (from > 0) {
                const more = document.createElement('button');
                more.type = 'button';
                more.className = 'list-group-item list-group-item-action text-center text-muted';
                more.textContent = `Show earlier chapters (${from})`;
                more.addEventListener('click', () => renderTocRange(0, to));
                tocList.appendChild(more);
            }
            rows = tocChapters.slice(from, to);
            renderRows(rows, currentId);
            if (to < tocChapters.length) {
                const more = document.createElement('button');
                more.type = 'button';
                more.className = 'list-group-item list-group-item-action text-center text-muted';
                more.textContent = `Show later chapters (${tocChapters.length - to})`;
                more.addEventListener('click', () => renderTocRange(from, tocChapters.length));
                tocList.appendChild(more);
            }
            tocList.querySelector('.active')?.scrollIntoView({ block: 'center' });
            return;
        }
        renderRows(rows, currentId);
    }

    function renderTocRange(from, to) {
        const currentId = sections[currentIdx].id;
        tocList.innerHTML = '';
        renderRows(tocChapters.slice(from, to), currentId);
        tocList.querySelector('.active')?.scrollIntoView({ block: 'center' });
    }

    function renderRows(rows, currentId) {
        const frag = document.createDocumentFragment();
        rows.forEach(c => frag.appendChild(tocItem(c, currentId)));
        tocList.appendChild(frag);
        if (!rows.length) {
            tocList.innerHTML = '<div class="p-3 text-muted">No chapters match.</div>';
        }
    }

    document.getElementById('tocPanel').addEventListener('show.bs.offcanvas', async () => {
        if (tocChapters) { renderToc(tocFilter.value.trim()); return; }
        try {
            const res = await fetch(`/novels/${state.novelId}/chapters-json`, { headers: { Accept: 'application/json' } });
            tocChapters = (await res.json()).chapters;
            renderToc();
        } catch (err) {
            tocList.innerHTML = '<div class="p-3 text-danger">Could not load the chapter list.</div>';
        }
    });
    let tocFilterTimer = null;
    tocFilter.addEventListener('input', () => {
        clearTimeout(tocFilterTimer);
        tocFilterTimer = setTimeout(() => renderToc(tocFilter.value.trim()), 150);
    });

    // ---- Read state controls (initial chapter's header) ----
    const readToggle = document.getElementById('readToggle');

    // queuedFetch parks the write in IndexedDB if we're offline and replays it
    // on reconnect; falls back to a plain fetch if the module isn't ready yet.
    function readFetch(url, body) {
        if (window.Novarr?.queuedFetch) {
            return Novarr.queuedFetch(url, { method: 'POST', body: body || null });
        }
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
        }).then((r) => r.json());
    }

    function setReadUi(read) {
        readToggle.className = 'btn btn-sm ' + (read ? 'btn-success' : 'btn-outline-secondary');
        readToggle.textContent = read ? '✓ Read' : 'Mark read';
        readToggle.dataset.read = read ? '1' : '0';
        readToggle.setAttribute('aria-pressed', read ? 'true' : 'false');
    }
    function markReadUi() { setReadUi(true); }

    // ---- "Mark to here" (this + all earlier chapters) ----
    const readThrough = document.getElementById('readThrough');
    readThrough.addEventListener('click', async () => {
        readThrough.disabled = true;
        try {
            const data = await readFetch(`/chapters/${readThrough.dataset.id}/read-through`);
            if (data.success) {
                markReadUi();
                Novarr.showToast(
                    data.queued
                        ? 'Saved offline — earlier chapters sync when you reconnect.'
                        : `Marked ${data.marked} earlier chapter(s) as read.`,
                    data.queued ? 'info' : 'success'
                );
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        } finally {
            readThrough.disabled = false;
        }
    });

    // ---- Manual read/unread toggle ----
    // Uses the idempotent bulk-read endpoint (set, not toggle) so a queued
    // replay applies the exact state we intended regardless of ordering.
    readToggle.addEventListener('click', async () => {
        readToggle.disabled = true;
        const desired = readToggle.dataset.read !== '1';
        try {
            const data = await readFetch('{{ route('chapters.bulk_read') }}', { ids: [readToggle.dataset.id], read: desired });
            if (data.success) {
                setReadUi(desired);
                if (data.queued) Novarr.showToast('Saved offline — will sync when you reconnect.', 'info');
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        } finally {
            readToggle.disabled = false;
        }
    });

    // ---- Pending chapter: fetch just this one on demand ----
    const dlBtn = document.getElementById('downloadChapterBtn');
    if (dlBtn) {
        dlBtn.addEventListener('click', async () => {
            dlBtn.disabled = true;
            dlBtn.querySelector('.dl-label').classList.add('d-none');
            dlBtn.querySelector('.dl-spinner').classList.remove('d-none');
            try {
                const result = await Novarr.executeCommand({ command: 'download_chapter', chapter_id: dlBtn.dataset.id });
                if (result.success) {
                    Novarr.showToast('Chapter downloaded — loading it now…', 'success');
                    Novarr.softRefresh(600);
                    return;
                }
                Novarr.showToast(result.output || result.error || 'Download failed — the source may not have this chapter yet.', 'danger');
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
            dlBtn.disabled = false;
            dlBtn.querySelector('.dl-label').classList.remove('d-none');
            dlBtn.querySelector('.dl-spinner').classList.add('d-none');
        });
    }

    // ---- Offline auto-mark ----
    // The server marks a chapter read when it serves the page; offline the page
    // comes from the cache, so queue the read-mark here instead.
    @if(!$chapter->read_at)
    function offlineAutoMark() {
        if (navigator.onLine || !window.Novarr?.queuedFetch) return;
        Novarr.queuedFetch('{{ route('chapters.bulk_read') }}', { method: 'POST', body: { ids: [{{ $chapter->id }}], read: true } });
        markReadUi();
    }
    if (window.Novarr?.queuedFetch) offlineAutoMark();
    else window.addEventListener('load', offlineAutoMark, { once: true });
    @endif
})();
</script>
@endpush
