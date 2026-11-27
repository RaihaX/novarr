{{-- One audiobook on a shelf: $a (App\Audiobook, novel.file loaded), $p (the reader's progress row or null). --}}
@php
    $abTitle = $a->displayTitle();
    $abPercent = $p && $a->duration_ms > 0 ? (int) min(100, round($p->position_ms / $a->duration_ms * 100)) : 0;
    $abFinished = $p && $p->finished_at !== null;
@endphp
<li class="ab-item">
    <a href="{{ route('audiobooks.show', $a->id) }}" class="home-tile" aria-label="{{ $abTitle }}, audiobook, {{ \App\Audiobooks\AudiobookLibrary::humanDuration((int) $a->duration_ms) }}{{ $abFinished ? ', finished' : ($abPercent > 0 ? ', ' . $abPercent . '% listened' : '') }}">
        <div class="home-cover has-chip">
            @if($cover = $a->coverUrl('sm'))
                <img src="{{ $cover }}" alt="" loading="lazy" decoding="async">
            @else
                <span class="home-cover-title">{{ $abTitle }}</span>
            @endif
            <span class="ab-chip"><x-icon name="headphones" :size="11" />Audio</span>
            @if($abPercent > 0)
                <span class="home-cover-edge"><i style="width: {{ $abFinished ? 100 : $abPercent }}%"></i></span>
            @endif
        </div>
        <span class="home-tile-title">{{ $abTitle }}</span>
        <span class="home-tile-meta">
            @if($abFinished)
                Finished
            @elseif($p && $p->position_ms > 0)
                {{ \App\Audiobooks\AudiobookLibrary::humanDuration(max(0, (int) $a->duration_ms - (int) $p->position_ms)) }} left
            @else
                {{ $a->displayAuthor() ?? \App\Audiobooks\AudiobookLibrary::humanDuration((int) $a->duration_ms) }}
            @endif
        </span>
    </a>
</li>
