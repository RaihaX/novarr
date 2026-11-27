@extends('layouts.app')

@section('title', 'Audiobooks')

{{-- Every audiobook (books' and standalone ones), by title, and the
     reader's Continue listening first (App\Http\Controllers\AudiobookController::index()). --}}
@section('content')
<div class="ab-page">
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Books · Listen</span>
        <h1 class="page-title mb-0">Audiobooks</h1>
        <p class="page-head-sub mb-0">
            @if($total > 0)
                <span class="mono-num">{{ number_format($total) }}</span> {{ Str::plural('audiobook', $total) }}.
            @else
                Audiobooks the owner adds show here, with the book they belong to.
            @endif
        </p>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('novels.index', ['medium' => 'book']) }}" class="btn btn-secondary">Books</a>
        @can('admin')
            <a href="{{ route('audiobooks.manage') }}" class="btn btn-secondary" id="manageAudiobooksLink">Manage audiobooks</a>
        @endcan
    </div>
</div>

@if(count($continue) > 0)
    <section class="home-section" aria-labelledby="continueListeningTitle" id="continue-listening">
        <div class="home-section-head">
            <h2 class="home-section-title" id="continueListeningTitle">Continue listening</h2>
        </div>
        <ul class="ab-grid" role="list">
            @foreach($continue as $c)
                <li class="ab-item">
                    <a href="{{ route('audiobooks.show', $c['id']) }}" class="home-tile" aria-label="Continue {{ $c['title'] }}">
                        <div class="home-cover has-chip">
                            @if($c['cover_url'])
                                <img src="{{ $c['cover_url'] }}" alt="" loading="lazy" decoding="async">
                            @else
                                <span class="home-cover-title">{{ $c['title'] }}</span>
                            @endif
                            <span class="ab-chip"><x-icon name="headphones" :size="11" />Audio</span>
                            @php $cp = $c['duration_ms'] > 0 ? (int) min(100, round($c['progress']['position_ms'] / $c['duration_ms'] * 100)) : 0; @endphp
                            <span class="home-cover-edge"><i style="width: {{ $cp }}%"></i></span>
                        </div>
                        <span class="home-tile-title">{{ $c['title'] }}</span>
                        <span class="home-tile-meta">{{ \App\Audiobooks\AudiobookLibrary::humanDuration(max(0, $c['duration_ms'] - $c['progress']['position_ms'])) }} left</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif

<form method="GET" action="{{ route('audiobooks.index') }}" class="ab-search" role="search">
    <input type="search" name="q" class="form-control" placeholder="Title, author or narrator" aria-label="Search audiobooks" value="{{ $q }}">
    <button type="submit" class="btn btn-secondary">Search</button>
    @if($q !== '')
        <a href="{{ route('audiobooks.index') }}" class="btn btn-ghost">All</a>
    @endif
</form>

@if($audiobooks->isEmpty())
    <div class="ab-empty" id="audiobooksEmpty">
        @if($q !== '')
            <p>No audiobook matches “{{ $q }}”.</p>
        @else
            <p>No audiobooks yet.
                @can('admin')
                    Drop an <code>.m4b</code> or <code>.mp3</code> file (or a folder of them) into <code>{{ \App\Audiobooks\AudiobookLibrary::inbox() }}</code>: it is imported within about ten minutes, and matched to its book.
                @endcan
            </p>
        @endif
    </div>
@else
    <ul class="ab-grid" role="list" id="audiobooksGrid">
        @foreach($audiobooks as $a)
            @include('audiobooks._tile', ['a' => $a, 'p' => $progress[(int) $a->id] ?? null])
        @endforeach
    </ul>
    @if($page > 1 || $has_more)
        <nav class="d-flex gap-2" aria-label="Pages">
            @if($page > 1)
                <a class="btn btn-secondary" href="{{ route('audiobooks.index', array_filter(['q' => $q, 'page' => $page - 1 > 1 ? $page - 1 : null])) }}">Previous</a>
            @endif
            @if($has_more)
                <a class="btn btn-secondary" href="{{ route('audiobooks.index', array_filter(['q' => $q, 'page' => $page + 1])) }}">Next</a>
            @endif
        </nav>
    @endif
@endif
</div>
@endsection
