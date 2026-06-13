@extends('layouts.app')

@push('styles')
@if($next)
    <link rel="prefetch" href="{{ route('chapters.show', $next->id) }}">
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
            <div class="btn-group btn-group-sm" id="widthGroup">
                <button type="button" class="btn btn-outline-secondary" data-width="narrow">Narrow</button>
                <button type="button" class="btn btn-outline-secondary" data-width="medium">Medium</button>
                <button type="button" class="btn btn-outline-secondary" data-width="wide">Wide</button>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted">Theme</span>
            <div class="btn-group btn-group-sm" id="themeGroup">
                <button type="button" class="btn btn-outline-secondary" data-theme="dark">Dark</button>
                <button type="button" class="btn btn-outline-secondary" data-theme="sepia">Sepia</button>
                <button type="button" class="btn btn-outline-secondary" data-theme="light">Light</button>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted">Font</span>
            <div class="btn-group btn-group-sm" id="familyGroup">
                <button type="button" class="btn btn-outline-secondary" data-family="sans">Sans</button>
                <button type="button" class="btn btn-outline-secondary" data-family="serif">Serif</button>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4" id="readerCard">
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
                <button type="button" id="readToggle" class="btn btn-sm {{ $chapter->read_at ? 'btn-success' : 'btn-outline-secondary' }}" data-id="{{ $chapter->id }}">
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
    const card = document.getElementById('readerCard');
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
    const themes = {
        dark:  { bg: '', fg: '' },                       // inherit app theme
        sepia: { bg: '#f4ecd8', fg: '#5b4636' },
        light: { bg: '#ffffff', fg: '#1a1a1a' },
    };

    function applyPrefs() {
        if (content) {
            content.style.fontSize = prefs.font + 'px';
            content.style.maxWidth = widths[prefs.width] || widths.medium;
            content.style.fontFamily = families[prefs.family] || families.sans;
        }
        const t = themes[prefs.theme] || themes.dark;
        if (card) {
            card.style.backgroundColor = t.bg;
            card.style.color = t.fg;
            card.querySelector('.card-body').style.color = t.fg;
        }
        // reflect active buttons
        document.querySelectorAll('#widthGroup [data-width]').forEach(b =>
            b.classList.toggle('active', b.dataset.width === prefs.width));
        document.querySelectorAll('#themeGroup [data-theme]').forEach(b =>
            b.classList.toggle('active', b.dataset.theme === prefs.theme));
        document.querySelectorAll('#familyGroup [data-family]').forEach(b =>
            b.classList.toggle('active', b.dataset.family === prefs.family));
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
    @if($prev) const prevUrl = '{{ route('chapters.show', $prev->id) }}'; @else const prevUrl = null; @endif
    @if($next) const nextUrl = '{{ route('chapters.show', $next->id) }}'; @else const nextUrl = null; @endif
    document.addEventListener('keydown', (e) => {
        if (e.target.matches('input, textarea, select')) return;
        if (e.key === 'ArrowLeft' && prevUrl) window.location.href = prevUrl;
        if (e.key === 'ArrowRight' && nextUrl) window.location.href = nextUrl;
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

    // ---- "Mark to here" (this + all earlier chapters) ----
    const readThrough = document.getElementById('readThrough');
    readThrough.addEventListener('click', async () => {
        readThrough.disabled = true;
        try {
            const response = await fetch(`/chapters/${readThrough.dataset.id}/read-through`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            });
            const data = await response.json();
            if (data.success) {
                Novarr.showToast(`Marked ${data.marked} earlier chapter(s) as read.`, 'success');
                document.getElementById('readToggle').className = 'btn btn-sm btn-success';
                document.getElementById('readToggle').textContent = '✓ Read';
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        } finally {
            readThrough.disabled = false;
        }
    });

    // ---- Manual read/unread toggle ----
    const readToggle = document.getElementById('readToggle');
    readToggle.addEventListener('click', async () => {
        readToggle.disabled = true;
        try {
            const response = await fetch(`/chapters/${readToggle.dataset.id}/toggle-read`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            });
            const data = await response.json();
            if (data.success) {
                readToggle.className = 'btn btn-sm ' + (data.read ? 'btn-success' : 'btn-outline-secondary');
                readToggle.textContent = data.read ? '✓ Read' : 'Mark read';
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        } finally {
            readToggle.disabled = false;
        }
    });
</script>
@endpush
