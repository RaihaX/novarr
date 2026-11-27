@extends('layouts.app')

@section('title', $detail['title'] . ' · Audiobook')

{{-- The web player (resources/js/audiobook-player.js): $detail is the API's
     audiobook detail (AudiobookPresenter::detail()), parts signed for this reader. --}}
@section('content')
@php
    $abDuration = \App\Audiobooks\AudiobookLibrary::humanDuration((int) $detail['duration_ms']);
    $fmt = function (int $ms) {
        $s = intdiv(max(0, $ms), 1000);
        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    };
@endphp
<div class="ab-page" id="audiobookPlayer" data-audiobook="{{ $detail['id'] }}">
    <a href="{{ $detail['novel_id'] ? route('novels.show', $detail['novel_id']) : route('audiobooks.index') }}" class="back-link">
        <x-icon name="chevron-left" :size="14" :stroke="1.5" /> {{ $detail['novel_id'] ? 'The book' : 'Audiobooks' }}
    </a>

    <div class="ab-player">
        <div class="ab-cover">
            @if($detail['cover_url'])
                <img src="{{ $detail['cover_url'] }}" alt="Cover of {{ $detail['title'] }}">
            @else
                <span class="ab-cover-title">{{ $detail['title'] }}</span>
            @endif
        </div>

        <div class="ab-main">
            <span class="page-head-kicker">Audiobook</span>
            <h1 class="page-title mb-1">{{ $detail['title'] }}</h1>
            <p class="ab-meta">
                {{ $detail['author'] ?? 'Unknown author' }}
                @if($detail['narrator']) · read by {{ $detail['narrator'] }}@endif
                · {{ $abDuration }}
                @if($detail['year']) · {{ $detail['year'] }}@endif
            </p>

            <audio data-ab-audio preload="metadata"></audio>

            <p class="ab-now" data-ab-chapter-title aria-live="off"></p>
            <input type="range" class="ab-scrubber" data-ab-scrubber min="0" max="{{ max(1, (int) $detail['duration_ms']) }}" step="1000" value="0" aria-label="Position in the book">
            <div class="ab-times mono-num">
                <span data-ab-elapsed>0:00</span>
                <span data-ab-remaining>-{{ $fmt((int) $detail['duration_ms']) }}</span>
            </div>

            <div class="ab-controls" role="group" aria-label="Player">
                <button type="button" class="btn btn-secondary btn-sm" data-ab-prev-chapter aria-label="Previous chapter" title="Previous chapter"><x-icon name="chevron-left" :size="14" /><x-icon name="chevron-left" :size="14" /></button>
                <button type="button" class="btn btn-secondary btn-sm" data-ab-skip="-30" aria-label="Back 30 seconds" title="Back 30 seconds">−30</button>
                <button type="button" class="btn btn-secondary btn-sm" data-ab-skip="-15" aria-label="Back 15 seconds" title="Back 15 seconds (←)">−15</button>
                <button type="button" class="btn btn-primary ab-play" data-ab-play aria-pressed="false" aria-label="Play" title="Play / pause (space)">
                    <x-icon name="play" :size="16" class="ab-icon-play" /><x-icon name="pause" :size="16" class="ab-icon-pause" />
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-ab-skip="15" aria-label="Forward 15 seconds" title="Forward 15 seconds (→)">+15</button>
                <button type="button" class="btn btn-secondary btn-sm" data-ab-skip="30" aria-label="Forward 30 seconds" title="Forward 30 seconds">+30</button>
                <button type="button" class="btn btn-secondary btn-sm" data-ab-next-chapter aria-label="Next chapter" title="Next chapter"><x-icon name="chevron-right" :size="14" /><x-icon name="chevron-right" :size="14" /></button>
            </div>

            <div class="ab-options">
                <span>
                    <label for="abSpeed">Speed</label>
                    <select id="abSpeed" class="form-select form-select-sm" data-ab-speed><option value="1">1×</option></select>
                </span>
                <span>
                    <label for="abSleep">Sleep timer</label>
                    <select id="abSleep" class="form-select form-select-sm" data-ab-sleep>
                        <option value="">Off</option>
                        <option value="15">15 min</option>
                        <option value="30">30 min</option>
                        <option value="45">45 min</option>
                        <option value="60">60 min</option>
                        <option value="chapter">End of chapter</option>
                    </select>
                </span>
                <span class="ab-sleep-status" data-ab-sleep-status></span>
            </div>
            <p class="visually-hidden" data-ab-status role="status" aria-live="polite"></p>
            @if($detail['description'])
                <p class="ab-meta">{{ \Illuminate\Support\Str::limit($detail['description'], 600) }}</p>
            @endif
        </div>
    </div>

    <div class="ab-panels">
        <section aria-labelledby="abChaptersTitle">
            <h2 class="panel-title" id="abChaptersTitle">Chapters <span class="count-chip">{{ count($detail['chapters']) }}</span></h2>
            <ol class="ab-chapters" data-ab-chapters>
                @foreach($detail['chapters'] as $i => $c)
                    <li class="ab-chapter" data-ab-chapter="{{ $i }}">
                        <button type="button"><span>{{ $c['title'] }}</span><span class="ab-chapter-time mono-num">{{ $fmt((int) $c['start_ms']) }}</span></button>
                    </li>
                @endforeach
            </ol>
        </section>
        <section aria-labelledby="abBookmarksTitle">
            <h2 class="panel-title" id="abBookmarksTitle">Bookmarks</h2>
            <form class="ab-bookmark-form" data-ab-bookmark-form>
                <input type="text" name="note" class="form-control form-control-sm" maxlength="500" placeholder="Note (optional)" aria-label="Bookmark note">
                <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="bookmark" :size="14" /> Add bookmark</button>
            </form>
            <p class="ab-empty" data-ab-bookmarks-empty @if(count($detail['bookmarks']) > 0) hidden @endif>No bookmarks yet: add one to come back to a moment.</p>
            <ul class="ab-bookmarks" data-ab-bookmarks></ul>
        </section>
    </div>

    <script type="application/json" id="audiobookData">{!! json_encode($detail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
</div>
@endsection
