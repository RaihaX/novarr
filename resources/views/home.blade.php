@extends('layouts.app')

@section('title', 'Home')

@php
    // Chapter numbers read as "1,204" (or "12.5" for a part); never "1204.0".
    $ch = function ($n) {
        $n = (float) $n;
        return fmod($n, 1.0) == 0.0 ? number_format((int) $n) : rtrim(rtrim(number_format($n, 2), '0'), '.');
    };
    $range = fn(array $g) => $g['first'] == $g['last']
        ? 'Chapter ' . $ch($g['first'])
        : 'Chapters ' . $ch($g['first']) . ' to ' . $ch($g['last']);

    $attentionCount = count($attention);
    $firstAttention = $attention[0] ?? null;
    $attentionHost = null;
    if ($firstAttention && !empty($firstAttention['url'])) {
        $attentionHost = parse_url($firstAttention['url'], PHP_URL_HOST);
        $attentionHost = $attentionHost ? preg_replace('/^www\./', '', $attentionHost) : null;
    }
    // $strip is null for a reader (sign-in on): library status is the admin's.
    if ($strip !== null) {
        $schedulerWord = $strip['scheduler_ok'] ? 'OK' : ($strip['scheduler_last_run'] ? 'late' : 'not running');
        $schedulerAgo = $strip['scheduler_last_run']?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true);
        $jobsNote = $strip['jobs'] === null ? 'job queue unreachable'
            : ($strip['jobs'] > 0 ? $strip['jobs'] . ' background ' . Str::plural('job', $strip['jobs']) . ' running' : null);
    }
@endphp

@push('preload')
@php try { $literataPreload = Vite::asset('node_modules/@fontsource-variable/literata/files/literata-latin-wght-normal.woff2'); } catch (\Throwable $e) { $literataPreload = null; } @endphp
@if($literataPreload)<link rel="preload" as="font" type="font/woff2" crossorigin href="{{ $literataPreload }}">@endif
@endpush

{{-- Phones: the status card's numbers ride in the navbar, as one mono line. --}}
@can('admin')
@section('nav-status')
    <a href="{{ route('activity.index') }}" class="home-navstat {{ $strip['scheduler_ok'] ? 'is-success' : 'is-danger' }}"
       aria-label="Scheduler {{ $schedulerWord }}, {{ number_format($strip['today']) }} downloaded today, {{ number_format($strip['queue']) }} queued">
        <span class="home-navstat-dot" aria-hidden="true"></span>
        <span aria-hidden="true">{{ number_format($strip['today']) }} today · {{ number_format($strip['queue']) }} queued</span>
    </a>
@endsection
@endcan

@section('content')

{{-- Phones: the status card collapses to one tappable attention row. --}}
@if($attentionCount > 0)
    <a href="{{ route('health.index') }}#attentionPanel" class="home-alert" id="attentionRow">
        <x-icon name="triangle-alert" :size="14" />
        <span class="home-alert-text">{{ $attentionCount }} {{ Str::plural('novel', $attentionCount) }} {{ $attentionCount === 1 ? 'needs' : 'need' }} attention</span>
        <x-icon name="chevron-right" :size="14" />
    </a>
@endif

{{-- Categories first (docs/plans/categories-and-home.md): once the reader
     has a category, Home is a summary of them, then New chapters. The 1.16
     shelves (Continue, Continue listening, Favourites, Want to read) are
     still rendered but hidden (data-home-optional) unless the reader turned
     them back on under "Customise Home" (resources/js/home-layout.js, kept
     in this browser). Until then Home keeps its old layout. --}}
@php $categoriesFirst = (bool) ($categories_first ?? false); @endphp
<div class="home{{ $categoriesFirst ? ' is-categories-first' : '' }}" data-home>

    @unless($categoriesFirst)
        <section class="home-first-category" aria-labelledby="firstCategoryTitle" id="first-category">
            <div class="home-first-category-body">
                <h2 class="home-first-category-title" id="firstCategoryTitle">Sort your library into categories</h2>
                <p class="home-quiet">Make a category (“Manhwa”, “Finished”…) and Home becomes a summary of them. In the Library, choose <strong>Select</strong> to put series in one.</p>
            </div>
            <form class="home-first-category-form" data-home-first-category>
                <label class="visually-hidden" for="firstCategoryName">Category name</label>
                <input type="text" id="firstCategoryName" name="name" class="form-control" maxlength="40" placeholder="Category name" required>
                <button type="submit" class="btn btn-primary">Create your first category</button>
                <p class="home-first-category-error" role="alert" hidden></p>
            </form>
        </section>
    @endunless

    @if($categoriesFirst)
        {{-- The summary of the categories, in the reader's order. --}}
        <section class="home-section home-categories" aria-labelledby="categoriesTitle" id="categories-section">
            <div class="home-section-head">
                <h2 class="home-section-title" id="categoriesTitle">Categories</h2>
                <a href="{{ route('novels.index') }}" class="home-section-link">Manage categories</a>
            </div>
            @if(count($categories) === 0)
                <p class="home-quiet">Your categories are empty. In the Library, choose <strong>Select</strong>, pick some series and <strong>Set categories</strong>.</p>
            @endif
            @foreach($categories as $cat)
                @php
                    $catUrl = match ($cat['kind']) {
                        \App\ReadingList::KIND_FAVOURITES => route('favourites'),
                        \App\ReadingList::KIND_WANT_TO_READ => route('want_to_read'),
                        default => route('novels.index', ['list' => $cat['id']]),
                    };
                @endphp
                <div class="home-category" data-category="{{ $cat['id'] }}">
                    <div class="home-section-head">
                        <h3 class="home-category-title" id="category{{ $cat['id'] }}Title">{{ $cat['name'] }} <span class="home-category-count mono-num">{{ number_format($cat['count']) }}</span></h3>
                        <a href="{{ $catUrl }}" class="home-section-link">See all<span class="visually-hidden"> {{ number_format($cat['count']) }} in {{ $cat['name'] }}</span></a>
                    </div>
                    <div class="home-shelf" aria-labelledby="category{{ $cat['id'] }}Title">
                        @foreach($cat['items'] as $n)
                            <a href="{{ route('novels.show', $n->id) }}" class="home-tile" aria-label="{{ $n->name }}">
                                <div class="home-cover{{ $n->hasPages() ? ' has-chip' : '' }}">
                                    @if($cover = $n->coverUrl('sm'))
                                        @php $cover2x = $n->coverThumbnailUrl('md'); @endphp
                                        <img src="{{ $cover }}"{!! $cover2x ? ' srcset="' . e($cover) . ' 1x, ' . e($cover2x) . ' 2x"' : '' !!} alt="" loading="lazy" decoding="async">
                                    @else
                                        <span class="home-cover-title">{{ $n->name }}</span>
                                    @endif
                                    @if($n->hasPages())
                                        <span class=\"cover-chip\">{{ $n->isComic() ? 'COMIC' : 'MANGA' }}</span>
                                    @endif
                                </div>
                                <span class="home-tile-title">{{ $n->name }}</span>
                                <span class="home-tile-meta">{{ $n->isBook() ? 'Book' : ($n->isManga() ? 'Manga' : ($n->isComic() ? 'Comic' : 'Web Novel')) }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    <div class="home-top">
        {{-- Hero: the current book --}}
        @if($hero)
            @php
                $novel = $hero['novel'];
                $chapter = $hero['chapter'];
                $resumeUrl = route('chapters.show', $chapter->id);
                $chapterNo = $ch($chapter->chapter);
                $position = 'Chapter ' . number_format($hero['index']) . ' of ' . number_format($hero['total']);
            @endphp
            <section class="home-hero" aria-labelledby="nowReadingTitle" id="nowReading" data-home-optional="continue">
                <a href="{{ $resumeUrl }}" class="home-hero-cover" tabindex="-1" aria-hidden="true">
                    {{-- Novel progress shows once, on the bar below: no cover edge here. --}}
                    <div class="home-cover home-cover-lg{{ ($hero['manga'] ?? false) ? ' has-chip' : '' }}">
                        @if($cover = $novel->coverUrl('md'))
                            <img src="{{ $cover }}" alt="" loading="eager" fetchpriority="high" decoding="async">
                        @else
                            <span class="home-cover-title">{{ $novel->name }}</span>
                        @endif
                        @if($hero['manga'] ?? false)
                            <span class="cover-chip">MANGA</span>
                        @endif
                    </div>
                </a>
                <div class="home-hero-head">
                    <span class="home-eyebrow">Now reading</span>
                    <h1 class="home-hero-title" id="nowReadingTitle">{{ $novel->name }}</h1>
                    <p class="home-hero-meta">
                        @if($novel->author)<span>by {{ $novel->author }}</span><span aria-hidden="true"> · </span>@endif
                        @if($hero['source'])<span class="home-hero-source">{{ $hero['source'] }}</span><span class="home-hero-source" aria-hidden="true"> · </span>@endif
                        <x-status :state="$hero['state']" as="text" class="home-hero-state" />
                        <x-origin :novel="$novel" class="ms-1" />
                    </p>
                </div>
                <div class="home-hero-progress">
                    {{-- Position in the novel (the bar) and, labelled apart, time
                         left in the current chapter. --}}
                    <div class="home-hero-pos" id="heroKicker">
                        <span>{{ $position }}</span>
                        <span class="home-hero-togo">{{ number_format($hero['to_go']) }} to go</span>
                        @if(!empty($hero['page_text']))
                            <span class="home-hero-mins-short" aria-hidden="true">{{ $hero['page_text'] }}</span>
                        @elseif($hero['minutes_left'])
                            <span class="home-hero-mins-short" aria-hidden="true">{{ $hero['minutes_left'] }} min {{ $hero['resume'] ? 'left' : 'read' }}</span>
                        @endif
                    </div>
                    <div class="home-progress" role="progressbar" aria-label="Progress through {{ $novel->name }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $hero['book_percent'] }}" aria-valuetext="{{ $position }}">
                        <span style="width: {{ $hero['book_percent'] }}%"></span>
                    </div>
                    @if(!empty($hero['page_text']))
                        {{-- Manga: pages, not minutes. --}}
                        <p class="home-hero-mins" id="heroPages">
                            @if($hero['resume'])
                                {{ $hero['page_text'] }} in this chapter
                            @else
                                Next up: chapter {{ $chapterNo }}, {{ $hero['page_text'] }}
                            @endif
                        </p>
                    @elseif($hero['minutes_left'])
                        <p class="home-hero-mins" id="heroMinutes">
                            @if($hero['resume'])
                                {{ $hero['minutes_left'] }} min left in this chapter
                            @else
                                Next up: chapter {{ $chapterNo }}, about {{ $hero['minutes_left'] }} min to read
                            @endif
                        </p>
                    @endif
                </div>
                <div class="home-hero-actions">
                    <a href="{{ $resumeUrl }}" class="btn btn-primary" id="heroResume">
                        <x-icon name="play" :size="14" />
                        {{ $hero['resume'] ? 'Resume' : 'Start' }} chapter {{ $chapterNo }}
                    </a>
                    <a href="{{ route('novels.show', $novel->id) }}#chapterPanel" class="btn btn-ghost home-hero-list">
                        <x-icon name="list" :size="18" />
                        <span class="home-hero-list-text">Chapter list</span>
                    </a>
                    <a href="{{ route('novels.show', $novel->id) }}" class="btn btn-ghost home-hero-novel">Novel page</a>
                </div>
            </section>
        @else
            <section class="home-empty" aria-labelledby="nothingReadingTitle" id="nothingReading" data-home-optional="continue">
                <x-icon name="book-open" :size="20" class="icon icon-amber" />
                <div class="home-empty-body">
                    <h1 class="home-empty-title" id="nothingReadingTitle">Nothing in progress</h1>
                    <p class="home-empty-text">Open a novel from your library and start a chapter — it will wait for you here, at the exact line you stopped.</p>
                    <div class="home-hero-actions">
                        <a href="{{ route('novels.index') }}" class="btn btn-primary">Open library</a>
                        @can('admin')
                        <a href="{{ route('novels.discover', ['medium' => 'novel']) }}" class="btn btn-ghost">Discover novels</a>
                        @endcan
                    </div>
                </div>
            </section>
        @endif

        {{-- Status card (desktop and tablet; phones get the row above and the
             navbar line). Same cached attention list as Health. Admin only. --}}
        @can('admin')
        <aside class="home-status" aria-labelledby="statusTitle" id="statusCard">
            <h2 class="visually-hidden" id="statusTitle">Library status</h2>
            @if($firstAttention)
                <div class="home-status-attn is-warning" id="attentionSummary">
                    <a href="{{ route('health.index') }}#attentionPanel" class="home-status-key">
                        <x-icon name="triangle-alert" :size="14" />
                        <span>{{ $attentionCount === 1 ? 'Needs attention' : $attentionCount . ' novels need attention' }}</span>
                    </a>
                    <a href="{{ route('novels.show', $firstAttention['id']) }}" class="home-status-name">{{ $firstAttention['name'] }}</a>
                    @if(!empty($firstAttention['reason']) || $attentionHost)
                        <span class="home-status-reason">{{ $firstAttention['reason'] ?? '' }}@if(!empty($firstAttention['reason']) && $attentionHost) · @endif @if($attentionHost)<span class="home-mono">{{ $attentionHost }}</span>@endif</span>
                    @endif
                    <div class="home-status-actions">
                        @if(!empty($firstAttention['url']))
                            <a href="{{ $firstAttention['url'] }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Test source <x-icon name="external-link" :size="12" /><span class="visually-hidden"> (opens in a new tab)</span></a>
                        @endif
                        {{-- Same endpoint as Health's button; a plain form, so no
                             script to bind and Turbo follows the redirect back. --}}
                        <form method="post" action="{{ route('novels.attention_snooze', $firstAttention['id']) }}" class="home-status-snooze">
                            @csrf
                            <input type="hidden" name="days" value="7">
                            <button type="submit" class="btn btn-ghost btn-sm" title="Hide from Needs attention for 7 days. Downloads keep running.">Snooze 7 days</button>
                        </form>
                    </div>
                    @if($attentionCount > 1)
                        <a href="{{ route('health.index') }}#attentionPanel" class="home-status-more">{{ $attentionCount - 1 }} more on Health</a>
                    @endif
                </div>
            @else
                <div class="home-status-attn is-zero" id="attentionSummary">
                    <a href="{{ route('health.index') }}" class="home-status-key">
                        <x-icon name="check" :size="14" />
                        <span><span class="home-mono">0</span> need attention</span>
                    </a>
                </div>
            @endif
            @if(($snoozed ?? collect())->isNotEmpty())
                <div class="home-snoozed">@include('partials.snoozed-note', ['snoozed' => $snoozed])</div>
            @endif
            <dl class="home-status-nums">
                <div class="home-status-num {{ $strip['today'] > 0 ? 'is-plain' : 'is-zero' }}" id="stripToday">
                    <dt>downloaded today</dt>
                    <dd><a href="{{ route('activity.index') }}#recent-section">{{ number_format($strip['today']) }}</a></dd>
                </div>
                <div class="home-status-num {{ $strip['queue'] ? 'is-pending' : 'is-zero' }}" id="stripQueue">
                    <dt>queued</dt>
                    <dd><a href="{{ route('activity.index') }}#queued-section">{{ number_format($strip['queue']) }}</a></dd>
                    @if($jobsNote)<dd class="home-status-sub">{{ $jobsNote }}</dd>@endif
                </div>
                <div class="home-status-num {{ $strip['scheduler_ok'] ? 'is-success' : 'is-danger' }}" id="stripScheduler">
                    <dt>scheduler</dt>
                    <dd class="home-status-word"><a href="{{ route('health.index') }}" @if($strip['scheduler_last_run']) title="Last run {{ $schedulerAgo }} ago ({{ $strip['scheduler_last_run']->format('Y-m-d H:i:s') }})" @endif>{{ $schedulerWord }}</a></dd>
                </div>
            </dl>
        </aside>
        @endcan
    </div>

    {{-- Continue: the next six in-progress novels --}}
    @if(count($shelf) > 0)
        <section class="home-section" aria-labelledby="continueTitle" id="continue-section" data-home-optional="continue">
            <div class="home-section-head">
                <h2 class="home-section-title" id="continueTitle">Continue</h2>
                <a href="{{ route('novels.index') }}" class="home-section-link">Library</a>
            </div>
            <div class="home-shelf">
                @foreach($shelf as $item)
                    @php $n = $item['novel']; @endphp
                    <a href="{{ route('chapters.show', $item['next']->id) }}" class="home-tile" aria-label="Continue {{ $n->name }} at chapter {{ $ch($item['next']->chapter) }}, {{ !empty($item['page_text']) && $item['resume'] ? Str::lower($item['page_text']) : number_format($item['unread']) . ' to read' }}">
                        <div class="home-cover{{ ($item['manga'] ?? false) ? ' has-chip' : '' }}">
                            {{-- The shelf is a single row, all of it in the first screenful. --}}
                            @if($cover = $n->coverUrl('sm'))
                                {{-- Tiles show at about 170 CSS px: offer md to 2x screens. --}}
                                @php $cover2x = $n->coverThumbnailUrl('md'); @endphp
                                <img src="{{ $cover }}"{!! $cover2x ? ' srcset="' . e($cover) . ' 1x, ' . e($cover2x) . ' 2x"' : '' !!} alt="" loading="eager" decoding="async">
                            @else
                                <span class="home-cover-title">{{ $n->name }}</span>
                            @endif
                            @if($item['manga'] ?? false)
                                {{-- The medium chip carries the origin code too ("MANGA · KR"). --}}
                                @php $oc = $n->originCountry(); @endphp
                                <span class="cover-chip">MANGA{!! $oc ? '<span data-origin="' . e($oc) . '" title="' . e($n->originCountryLabel()) . '"> · ' . e($oc) . '</span>' : '' !!}</span>
                            @endif
                            <span class="home-cover-edge"><i style="width: {{ $item['percent'] }}%"></i></span>
                        </div>
                        <span class="home-tile-title">{{ $n->name }}</span>
                        @if(!empty($item['page_text']) && $item['resume'])
                            {{-- Manga in progress: where in the chapter, in pages. --}}
                            <span class="home-tile-meta">ch {{ $ch($item['next']->chapter) }} · {{ $item['page_text'] }}</span>
                        @else
                            <span class="home-tile-meta"><span class="home-tile-wide">Next: </span>ch {{ $ch($item['next']->chapter) }} · {{ number_format($item['unread']) }} <span class="home-tile-wide">to read</span><span class="home-tile-narrow">left</span></span>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Continue listening: audiobooks part way through, newest first (only when there are some). --}}
    @if(count($continue_listening ?? []) > 0)
        <section class="home-section" aria-labelledby="continueListeningTitle" id="continue-listening-section" data-home-optional="listening">
            <div class="home-section-head">
                <h2 class="home-section-title" id="continueListeningTitle">Continue listening</h2>
                <a href="{{ route('audiobooks.index') }}" class="home-section-link">Audiobooks</a>
            </div>
            <div class="home-shelf">
                @foreach($continue_listening as $c)
                    @php $cp = $c['duration_ms'] > 0 ? (int) min(100, round($c['progress']['position_ms'] / $c['duration_ms'] * 100)) : 0; @endphp
                    <a href="{{ route('audiobooks.show', $c['id']) }}" class="home-tile" aria-label="Continue listening to {{ $c['title'] }}, {{ $cp }}% listened">
                        <div class="home-cover has-chip">
                            @if($c['cover_url'])
                                <img src="{{ $c['cover_url'] }}" alt="" loading="lazy" decoding="async">
                            @else
                                <span class="home-cover-title">{{ $c['title'] }}</span>
                            @endif
                            <span class="ab-chip"><x-icon name="headphones" :size="11" />Audio</span>
                            <span class="home-cover-edge"><i style="width: {{ $cp }}%"></i></span>
                        </div>
                        <span class="home-tile-title">{{ $c['title'] }}</span>
                        <span class="home-tile-meta">{{ \App\Audiobooks\AudiobookLibrary::humanDuration(max(0, $c['duration_ms'] - $c['progress']['position_ms'])) }} left</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Favourites: the reader's hearted series (the second built-in reading
         list), newest first, up to HomeController::FAVOURITES_SHELF covers.
         Only once there is one: the heart on a series page fills it. --}}
    @php $favs = $favourites ?? ['count' => 0, 'items' => []]; @endphp
    @if(count($favs['items']) > 0)
        <section class="home-section" aria-labelledby="favouritesTitle" id="favourites-section" data-home-optional="favourites">
            <div class="home-section-head">
                <h2 class="home-section-title" id="favouritesTitle">Favourites</h2>
                <a href="{{ route('favourites') }}" class="home-section-link">See all<span class="visually-hidden"> {{ number_format($favs['count']) }} in Favourites</span></a>
            </div>
            <div class="home-shelf">
                @foreach($favs['items'] as $item)
                    @php $n = $item['novel']; @endphp
                    <a href="{{ route('novels.show', $n->id) }}" class="home-tile" aria-label="{{ $n->name }}">
                        <div class="home-cover{{ $n->hasPages() ? ' has-chip' : '' }}">
                            @if($cover = $n->coverUrl('sm'))
                                @php $cover2x = $n->coverThumbnailUrl('md'); @endphp
                                <img src="{{ $cover }}"{!! $cover2x ? ' srcset="' . e($cover) . ' 1x, ' . e($cover2x) . ' 2x"' : '' !!} alt="" loading="lazy" decoding="async">
                            @else
                                <span class="home-cover-title">{{ $n->name }}</span>
                            @endif
                            @if($n->hasPages())
                                <span class=\"cover-chip\">{{ $n->isComic() ? 'COMIC' : 'MANGA' }}</span>
                            @endif
                        </div>
                        <span class="home-tile-title">{{ $n->name }}</span>
                        <span class="home-tile-meta">{{ $n->isBook() ? 'Book' : ($n->isManga() ? 'Manga' : ($n->isComic() ? 'Comic' : 'Web Novel')) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Want to read: the reader's shortlist (the built-in reading list),
         newest first, up to HomeController::WANT_TO_READ_SHELF covers. --}}
    @php $wtr = $want_to_read ?? ['count' => 0, 'items' => []]; @endphp
    <section class="home-section" aria-labelledby="wantToReadTitle" id="want-to-read-section" data-home-optional="want_to_read">
        <div class="home-section-head">
            <h2 class="home-section-title" id="wantToReadTitle">Want to read</h2>
            @if($wtr['count'] > 0)
                <a href="{{ route('want_to_read') }}" class="home-section-link">See all<span class="visually-hidden"> {{ number_format($wtr['count']) }} in Want to read</span></a>
            @endif
        </div>
        @if(count($wtr['items']) === 0)
            <p class="home-quiet">Shortlist what to read next: tap <strong>Want to read</strong> on a series’ page and it waits here.</p>
        @else
            <div class="home-shelf">
                @foreach($wtr['items'] as $item)
                    @php $n = $item['novel']; @endphp
                    <a href="{{ route('novels.show', $n->id) }}" class="home-tile" aria-label="{{ $n->name }}{{ $item['started'] ? ', started' : '' }}">
                        <div class="home-cover{{ $n->hasPages() ? ' has-chip' : '' }}">
                            @if($cover = $n->coverUrl('sm'))
                                @php $cover2x = $n->coverThumbnailUrl('md'); @endphp
                                <img src="{{ $cover }}"{!! $cover2x ? ' srcset="' . e($cover) . ' 1x, ' . e($cover2x) . ' 2x"' : '' !!} alt="" loading="lazy" decoding="async">
                            @else
                                <span class="home-cover-title">{{ $n->name }}</span>
                            @endif
                            @if($n->hasPages())
                                <span class=\"cover-chip\">{{ $n->isComic() ? 'COMIC' : 'MANGA' }}</span>
                            @endif
                        </div>
                        <span class="home-tile-title">{{ $n->name }}</span>
                        @if($item['started'])
                            <span class="home-tile-meta"><span class="wtr-started">Started</span></span>
                        @else
                            <span class="home-tile-meta">{{ $n->isBook() ? 'Book' : ($n->isManga() ? 'Manga' : ($n->isComic() ? 'Comic' : 'Web Novel')) }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- New chapters: novels you are reading, then novels not started yet --}}
    <section class="home-section" aria-labelledby="newTitle" id="new-section">
        <div class="home-section-head">
            <h2 class="home-section-title" id="newTitle">New chapters</h2>
            <span class="home-section-note">Last {{ \App\Http\Controllers\HomeController::NEW_CHAPTER_DAYS }} days</span>
        </div>

        @if(count($new_reading) === 0 && count($new_fresh) === 0)
            <p class="home-quiet">No new chapters in the last {{ \App\Http\Controllers\HomeController::NEW_CHAPTER_DAYS }} days.@can('admin') <a href="{{ route('activity.index') }}">Activity</a> shows what is still queued.@endcan</p>
        @endif

        @if(count($new_reading) > 0)
            <div class="home-new-group" id="newReading">
                <h3 class="home-new-head" id="newReadingTitle">In novels you are reading</h3>
                <ul class="home-new" aria-labelledby="newReadingTitle">
                    @foreach($new_reading as $g)
                        <li class="home-new-row" data-novel="{{ $g['novel_id'] }}">
                            <span class="home-new-thumb" aria-hidden="true">
                                @if(isset($g['novel']) && ($thumb = $g['novel']->coverUrl('sm')))<img src="{{ $thumb }}" alt="" width="32" height="48" loading="lazy" decoding="async">@endif
                            </span>
                            <div class="home-new-main">
                                <a href="{{ route('novels.show', $g['novel_id']) }}" class="home-new-name">{{ $g['name'] }}</a>
                                <span class="home-new-range">{{ $range($g) }}@if($g['source']) · <span class="home-mono">{{ $g['source'] }}</span>@endif @if($g['notes'] > 0)<span class="home-new-note"> · includes an author's note</span>@endif</span>
                            </div>
                            @if($g['queued'] > 0)<span class="home-new-queued">{{ number_format($g['queued']) }} queued</span>@endif
                            @if($g['downloaded'] > 0)<span class="home-new-count">{{ number_format($g['downloaded']) }} new</span>@endif
                            @if($g['read_from_id'])
                                <a href="{{ route('chapters.show', $g['read_from_id']) }}" class="home-new-go">Continue at ch {{ $ch($g['read_from']) }}<span class="visually-hidden"> of {{ $g['name'] }}</span></a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(count($new_fresh) > 0)
            @php
                $freshNames = array_column($new_fresh, 'name');
                $freshSummary = count($freshNames) <= 3
                    ? implode(', ', $freshNames)
                    : implode(', ', array_slice($freshNames, 0, 3)) . ' and ' . (count($freshNames) - 3) . ' more';
            @endphp
            <div class="home-new-group" id="newFresh">
                {{-- Phones: one row that opens the list below it (a <details>
                     toggle, so no script). Hidden from md up, where the list
                     always shows. --}}
                <details class="home-new-toggle">
                    <summary class="home-new-summary">
                        <span class="home-new-summary-main">
                            <span class="home-new-summary-title">{{ count($new_fresh) }} {{ Str::plural('novel', count($new_fresh)) }} not started yet</span>
                            <span class="home-new-range">{{ $freshSummary }}</span>
                        </span>
                        <x-icon name="chevron-right" :size="14" />
                    </summary>
                </details>
                <h3 class="home-new-head" id="newFreshTitle">Not started yet</h3>
                <ul class="home-new home-new-grid" aria-labelledby="newFreshTitle">
                    @foreach($new_fresh as $g)
                        <li class="home-new-row" data-novel="{{ $g['novel_id'] }}">
                            <div class="home-new-main">
                                <a href="{{ route('novels.show', $g['novel_id']) }}" class="home-new-name">{{ $g['name'] }}</a>
                                <span class="home-new-range">{{ $range($g) }}@if($g['source']) · <span class="home-mono">{{ $g['source'] }}</span>@endif @if($g['notes'] > 0)<span class="home-new-note"> · includes an author's note</span>@endif</span>
                            </div>
                            @if($g['queued'] > 0)<span class="home-new-queued">{{ number_format($g['queued']) }} queued</span>@endif
                            @if($g['downloaded'] > 0)<span class="home-new-count">{{ number_format($g['downloaded']) }} new</span>@endif
                            @if($g['read_from_id'])
                                <a href="{{ route('chapters.show', $g['read_from_id']) }}" class="home-new-go" title="Start at chapter {{ $ch($g['read_from']) }}">Start<span class="visually-hidden"> {{ $g['name'] }} at chapter {{ $ch($g['read_from']) }}</span></a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    @if($categoriesFirst)
        {{-- Bring back the shelves Home had before categories (this browser). --}}
        <details class="home-customise" data-home-customise>
            <summary>Customise Home</summary>
            <fieldset>
                <legend class="visually-hidden">Also show on Home</legend>
                <label><input type="checkbox" class="form-check-input" data-home-toggle="continue"> Continue reading</label>
                <label><input type="checkbox" class="form-check-input" data-home-toggle="listening"> Continue listening</label>
                <label><input type="checkbox" class="form-check-input" data-home-toggle="favourites"> Favourites</label>
                <label><input type="checkbox" class="form-check-input" data-home-toggle="want_to_read"> Want to read</label>
            </fieldset>
        </details>
    @endif
</div>
@endsection
