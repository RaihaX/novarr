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
<div class="mb-3 d-flex flex-wrap gap-2 justify-content-between align-items-center" id="readerToolbar">
    <a href="{{ route('novels.show', $chapter->novel_id) }}" class="btn btn-outline-secondary btn-sm text-truncate" style="max-width: 100%;">&larr; {{ $chapter->novel->name ?? 'Back' }}</a>
    <div class="d-flex gap-2 align-items-center">
        <button type="button" id="focusBtn" class="btn btn-sm btn-outline-secondary" title="Focus mode (hide chrome)" aria-label="Focus mode">⛶</button>
        <button type="button" id="readerSettingsBtn" class="btn btn-sm btn-outline-secondary" title="Reading settings" aria-label="Reading settings">Aa</button>
        @if($prev)
            <a href="{{ route('chapters.show', $prev->id) }}" class="btn btn-sm btn-outline-secondary">&larr; Ch. {{ $prev->chapter }}</a>
        @endif
        @if($next)
            <a href="{{ route('chapters.show', $next->id) }}" class="btn btn-sm btn-outline-secondary">Ch. {{ $next->chapter }} &rarr;</a>
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
    </div>
</div>

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
            <p class="text-muted text-center py-5">No content available for this chapter.</p>
        @endif
    </div>
</div>

@if($next)
    {{-- Prominent end-of-chapter action: mark this chapter read and move on,
         the dominant interaction when reading a series straight through. --}}
    <a href="{{ route('chapters.show', $next->id) }}" id="nextChapterCta" class="btn btn-primary next-chapter-cta mb-3">
        Next: {{ Str::limit($next->label ?: 'Chapter ' . $next->chapter, 50) }} &rarr;
    </a>
@endif

<div class="chapter-nav justify-content-between">
    @if($prev)
        <a href="{{ route('chapters.show', $prev->id) }}" class="btn btn-outline-secondary text-truncate">&larr; {{ Str::limit($prev->label ?: 'Ch. ' . $prev->chapter, 40) }}</a>
    @endif
    @if($next)
        <a href="{{ route('chapters.show', $next->id) }}" class="btn btn-outline-secondary text-truncate">{{ Str::limit($next->label ?: 'Ch. ' . $next->chapter, 40) }} &rarr;</a>
    @endif
</div>
@endsection

@push('scripts')
<script>
    // ---- Reader preferences (persisted in localStorage) ----
    const content = document.getElementById('chapterContent');
    const prefs = {
        font: parseInt(localStorage.getItem('reader_font') || '18', 10),
        width: localStorage.getItem('reader_width') || 'medium',
        theme: localStorage.getItem('reader_theme') || 'dark',
        family: localStorage.getItem('reader_family') || 'sans',
    };

    const families = {
        sans: "var(--bs-body-font-family)",
        serif: "Georgia, 'Times New Roman', serif",
    };
    const widths = { narrow: '600px', medium: '760px', wide: '960px' };

    function applyPrefs() {
        if (content) {
            content.style.fontSize = prefs.font + 'px';
            content.style.maxWidth = widths[prefs.width] || widths.medium;
            content.style.fontFamily = families[prefs.family] || families.sans;
        }
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
    }

    document.getElementById('readerSettingsBtn').addEventListener('click', () => {
        document.getElementById('readerSettings').classList.toggle('d-none');
    });

    document.querySelectorAll('[data-font]').forEach(btn => btn.addEventListener('click', () => {
        prefs.font = Math.min(28, Math.max(13, prefs.font + (btn.dataset.font === '+' ? 1 : -1)));
        localStorage.setItem('reader_font', prefs.font);
        applyPrefs();
    }));
    document.querySelectorAll('[data-width]').forEach(btn => btn.addEventListener('click', () => {
        prefs.width = btn.dataset.width;
        localStorage.setItem('reader_width', prefs.width);
        applyPrefs();
    }));
    document.querySelectorAll('[data-theme]').forEach(btn => btn.addEventListener('click', () => {
        prefs.theme = btn.dataset.theme;
        localStorage.setItem('reader_theme', prefs.theme);
        applyPrefs();
    }));
    document.querySelectorAll('[data-family]').forEach(btn => btn.addEventListener('click', () => {
        prefs.family = btn.dataset.family;
        localStorage.setItem('reader_family', prefs.family);
        applyPrefs();
    }));

    // ---- Focus mode: hide navbar + toolbar, persisted ----
    const focusBtn = document.getElementById('focusBtn');
    function applyFocus() {
        const on = localStorage.getItem('reader_focus') === '1';
        document.body.classList.toggle('reader-focus', on);
        focusBtn.classList.toggle('active', on);
    }
    focusBtn.addEventListener('click', () => {
        localStorage.setItem('reader_focus', localStorage.getItem('reader_focus') === '1' ? '0' : '1');
        applyFocus();
    });
    applyFocus();

    applyPrefs();

    // ---- Keyboard navigation: ← / → between chapters ----
    // Turbo.visit keeps navigation in-app (no full reload / script re-run) and
    // uses the prefetched documents; falls back to a hard nav if Turbo is absent.
    @if($prev) const prevUrl = '{{ route('chapters.show', $prev->id) }}'; @else const prevUrl = null; @endif
    @if($next) const nextUrl = '{{ route('chapters.show', $next->id) }}'; @else const nextUrl = null; @endif
    function goTo(url) {
        if (!url) return;
        if (window.Turbo) Turbo.visit(url);
        else window.location.href = url;
    }
    document.addEventListener('keydown', (e) => {
        if (e.target.matches('input, textarea, select')) return;
        if (e.key === 'ArrowLeft') goTo(prevUrl);
        if (e.key === 'ArrowRight') goTo(nextUrl);
    });

    // ---- Resume scroll position per chapter ----
    const scrollKey = 'reader_scroll_{{ $chapter->id }}';
    const savedScroll = parseInt(localStorage.getItem(scrollKey) || '0', 10);
    if (savedScroll > 200) {
        window.scrollTo(0, savedScroll);
    }
    let scrollSaveTimer = null;
    window.addEventListener('scroll', () => {
        clearTimeout(scrollSaveTimer);
        scrollSaveTimer = setTimeout(() => {
            // Near the bottom? consider it finished and forget the position.
            const atBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 100;
            if (atBottom) {
                localStorage.removeItem(scrollKey);
            } else {
                localStorage.setItem(scrollKey, String(Math.round(window.scrollY)));
            }
        }, 250);
    });

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
</script>
@endpush
