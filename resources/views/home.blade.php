@extends('layouts.app')

@section('title', 'Home')

@php
    // Chapter numbers read as "1,204" (or "12.5" for a part); never "1204.0".
    $ch = function ($n) {
        $n = (float) $n;
        return fmod($n, 1.0) == 0.0 ? number_format((int) $n) : rtrim(rtrim(number_format($n, 2), '0'), '.');
    };
@endphp

@push('preload')
@php try { $literataPreload = Vite::asset('node_modules/@fontsource-variable/literata/files/literata-latin-wght-normal.woff2'); } catch (\Throwable $e) { $literataPreload = null; } @endphp
@if($literataPreload)<link rel="preload" as="font" type="font/woff2" crossorigin href="{{ $literataPreload }}">@endif
@endpush

@section('content')

{{-- Status strip: one 32px line of system state. Each item is a link into
     Health / Activity; colour follows the status triad and drops to muted
     when there is nothing to report. --}}
<nav class="home-strip" aria-label="System status">
    <a href="{{ route('health.index') }}" class="home-strip-item {{ $strip['scheduler_ok'] ? 'is-success' : 'is-danger' }}" id="stripScheduler">
        <span class="home-strip-dot" aria-hidden="true"></span>
        <span class="home-strip-label">Scheduler {{ $strip['scheduler_ok'] ? 'OK' : ($strip['scheduler_last_run'] ? 'late' : 'not running') }}</span>
        @if($strip['scheduler_last_run'])
            <span class="home-strip-mono" title="{{ $strip['scheduler_last_run']->format('Y-m-d H:i:s') }}">{{ $strip['scheduler_last_run']->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true) }} ago</span>
        @endif
    </a>
    <a href="{{ route('activity.index') }}" class="home-strip-item {{ $strip['queue'] ? 'is-pending' : 'is-zero' }}" id="stripQueue">
        <span class="home-strip-dot" aria-hidden="true"></span>
        @php
            $queueNote = $strip['queue'] === 1 ? 'chapter' : 'chapters';
            if ($strip['jobs'] === null) {
                $queueNote .= ' · jobs —';
            } elseif ($strip['jobs'] > 0) {
                $queueNote .= ' · ' . $strip['jobs'] . ($strip['jobs'] === 1 ? ' job running' : ' jobs running');
            }
        @endphp
        <span class="home-strip-label">Queued</span>
        <span class="home-strip-mono">{{ number_format($strip['queue']) }}</span>
        <span class="home-strip-sub">{{ $queueNote }}</span>
    </a>
    {{-- The P2 dashboard summary line, now a strip item (id kept). --}}
    <a href="{{ route('health.index') }}#attentionPanel" class="home-strip-item {{ $strip['attention'] > 0 ? 'is-warning' : 'is-zero' }}" id="attentionSummary">
        <x-icon name="triangle-alert" :size="12" :stroke="2.25" />
        <span class="home-strip-label">
            @if($strip['attention'] > 0)
                <strong>{{ $strip['attention'] }} {{ Str::plural('novel', $strip['attention']) }}</strong> {{ $strip['attention'] === 1 ? 'needs' : 'need' }} attention
            @else
                <span class="home-strip-mono">0</span> need attention
            @endif
        </span>
    </a>
    <a href="{{ route('activity.index') }}#recent-section" class="home-strip-item {{ $strip['today'] > 0 ? 'is-success' : 'is-zero' }}" id="stripToday">
        <x-icon name="download" :size="12" :stroke="2.25" />
        <span class="home-strip-label"><span class="home-strip-mono">{{ number_format($strip['today']) }}</span> {{ Str::plural('chapter', $strip['today']) }} today</span>
    </a>
</nav>

@if(($snoozed ?? collect())->isNotEmpty())
    <div class="home-snoozed">@include('partials.snoozed-note', ['snoozed' => $snoozed])</div>
@endif

<div class="home">

    {{-- Hero: the current book --}}
    @if($hero)
        @php
            $novel = $hero['novel'];
            $chapter = $hero['chapter'];
            $resumeUrl = route('chapters.show', $chapter->id);
        @endphp
        <section class="home-hero" aria-labelledby="nowReadingTitle" id="nowReading">
            <a href="{{ $resumeUrl }}" class="home-hero-cover" tabindex="-1" aria-hidden="true">
                <div class="home-cover home-cover-lg">
                    @if($novel->file)
                        <img src="{{ Storage::url($novel->file->file_path) }}" alt="" loading="eager">
                    @else
                        <span class="home-cover-title">{{ $novel->name }}</span>
                    @endif
                    <span class="home-cover-edge"><i style="width: {{ $hero['book_percent'] }}%"></i></span>
                </div>
            </a>
            <div class="home-hero-body">
                <span class="home-eyebrow">Now reading</span>
                <h1 class="home-hero-title" id="nowReadingTitle">{{ $novel->name }}</h1>
                <p class="home-hero-meta">
                    @if($novel->author)<span>by {{ $novel->author }}</span><span aria-hidden="true"> · </span>@endif
                    <span><span class="home-mono">{{ number_format($hero['total']) }}</span> {{ Str::plural('chapter', $hero['total']) }} on source</span>
                    <span aria-hidden="true"> · </span>
                    <x-status :state="$hero['state']" as="text" class="home-hero-state" />
                </p>
                <div class="home-hero-progress">
                    <div class="home-progress" role="progressbar" aria-label="Progress through {{ $novel->name }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $hero['book_percent'] }}">
                        <span style="width: {{ $hero['book_percent'] }}%"></span>
                    </div>
                    <div class="home-kicker" id="heroKicker">
                        CHAPTER {{ number_format($hero['index']) }} OF {{ number_format($hero['total']) }}
                        @if($hero['resume'])
                            @if($hero['minutes_left']) · {{ $hero['minutes_left'] }} MIN LEFT IN THIS CHAPTER @endif
                            · {{ $hero['chapter_percent'] }}% OF CHAPTER
                        @else
                            · NEXT UP @if($hero['minutes_left']) · {{ $hero['minutes_left'] }} MIN READ @endif
                        @endif
                    </div>
                </div>
                <div class="home-hero-actions">
                    <a href="{{ $resumeUrl }}" class="btn btn-primary" id="heroResume">
                        <x-icon name="play" :size="14" />
                        {{ $hero['resume'] ? 'Resume' : 'Start chapter ' . $ch($chapter->chapter) }}
                    </a>
                    <a href="{{ route('novels.show', $novel->id) }}#chapterPanel" class="btn btn-ghost">Chapter list</a>
                    <a href="{{ route('novels.show', $novel->id) }}" class="btn btn-ghost">Novel page</a>
                </div>
            </div>
        </section>
    @else
        <section class="home-empty" aria-labelledby="nothingReadingTitle" id="nothingReading">
            <x-icon name="book-open" :size="20" class="icon icon-amber" />
            <div class="home-empty-body">
                <h1 class="home-empty-title" id="nothingReadingTitle">Nothing in progress</h1>
                <p class="home-empty-text">Open a novel from your library and start a chapter — it will wait for you here, at the exact line you stopped.</p>
                <div class="home-hero-actions">
                    <a href="{{ route('novels.index') }}" class="btn btn-primary">Open library</a>
                    <a href="{{ route('novels.discover') }}" class="btn btn-ghost">Discover novels</a>
                </div>
            </div>
        </section>
    @endif

    {{-- Continue: the next six in-progress novels --}}
    @if(count($shelf) > 0)
        <section class="home-section" aria-labelledby="continueTitle" id="continue-section">
            <div class="home-section-head">
                <h2 class="home-section-title" id="continueTitle">Continue</h2>
                <a href="{{ route('novels.index') }}" class="home-section-link">Library</a>
            </div>
            <div class="home-shelf">
                @foreach($shelf as $item)
                    @php $n = $item['novel']; @endphp
                    <a href="{{ route('chapters.show', $item['next']->id) }}" class="home-tile" title="Continue {{ $n->name }} — chapter {{ $ch($item['next']->chapter) }}">
                        <div class="home-cover">
                            @if($n->file)
                                <img src="{{ Storage::url($n->file->file_path) }}" alt="" loading="lazy">
                            @else
                                <span class="home-cover-title">{{ $n->name }}</span>
                            @endif
                            <span class="home-cover-edge"><i style="width: {{ $item['percent'] }}%"></i></span>
                        </div>
                        <span class="home-tile-title">{{ $n->name }}</span>
                        <span class="home-tile-meta">CH {{ $ch($item['next']->chapter) }}@if($item['unread'] > 0) · {{ number_format($item['unread']) }} NEW @endif</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- New chapters, grouped by novel --}}
    <section class="home-section" aria-labelledby="newTitle" id="new-section">
        <div class="home-section-head">
            <h2 class="home-section-title" id="newTitle">New chapters</h2>
            <span class="home-section-note">Grouped by novel, last {{ \App\Http\Controllers\HomeController::NEW_CHAPTER_DAYS }} days</span>
        </div>
        @if(count($new_chapters) > 0)
            <ul class="home-new">
                @foreach($new_chapters as $g)
                    <li class="home-new-row" data-novel="{{ $g['novel_id'] }}">
                        <div class="home-new-main">
                            <a href="{{ route('novels.show', $g['novel_id']) }}" class="home-new-name">{{ $g['name'] }}</a>
                            <span class="home-new-range">
                                @if($g['first'] == $g['last'])
                                    Chapter {{ $ch($g['first']) }}
                                @else
                                    Chapters {{ $ch($g['first']) }} to {{ $ch($g['last']) }}
                                @endif
                                @if($g['source']) · <span class="home-mono">{{ $g['source'] }}</span>@endif
                            </span>
                        </div>
                        <div class="home-new-side">
                            @if($g['notes'] > 0)
                                <x-status state="attention" class="home-pill">Author's note</x-status>
                            @endif
                            @if($g['queued'] > 0)
                                <x-status state="queued" class="home-pill">{{ number_format($g['queued']) }} queued</x-status>
                            @endif
                            @if($g['downloaded'] > 0)
                                <x-status state="downloaded" class="home-pill">{{ number_format($g['downloaded']) }} downloaded</x-status>
                            @endif
                            @if($g['read_from_id'])
                                <a href="{{ route('chapters.show', $g['read_from_id']) }}" class="btn btn-ghost btn-sm home-new-read">Read from {{ $ch($g['read_from']) }}</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="home-quiet">No new chapters in the last {{ \App\Http\Controllers\HomeController::NEW_CHAPTER_DAYS }} days. <a href="{{ route('activity.index') }}">Activity</a> shows what is still queued.</p>
        @endif
    </section>
</div>
@endsection
