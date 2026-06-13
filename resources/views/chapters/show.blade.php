@extends('layouts.app')

@section('content')
<div class="mb-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <a href="{{ route('novels.show', $chapter->novel_id) }}" class="btn btn-outline-secondary btn-sm text-truncate" style="max-width: 100%;">&larr; {{ $chapter->novel->name ?? 'Back' }}</a>
    <div class="d-flex gap-2 align-items-center">
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
            <button type="button" id="readToggle" class="btn btn-sm {{ $chapter->read_at ? 'btn-success' : 'btn-outline-secondary' }}" data-id="{{ $chapter->id }}">
                {{ $chapter->read_at ? '✓ Read' : 'Mark read' }}
            </button>
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

    applyPrefs();

    // ---- Keyboard navigation: ← / → between chapters ----
    @if($prev) const prevUrl = '{{ route('chapters.show', $prev->id) }}'; @else const prevUrl = null; @endif
    @if($next) const nextUrl = '{{ route('chapters.show', $next->id) }}'; @else const nextUrl = null; @endif
    document.addEventListener('keydown', (e) => {
        if (e.target.matches('input, textarea, select')) return;
        if (e.key === 'ArrowLeft' && prevUrl) window.location.href = prevUrl;
        if (e.key === 'ArrowRight' && nextUrl) window.location.href = nextUrl;
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
