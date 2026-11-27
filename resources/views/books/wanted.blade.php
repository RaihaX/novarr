@extends('layouts.app')

{{--
    A book a reader asked for by title (NovelRequest::isWanted()): what they
    asked for, from the catalogues, and the owner's two ways out: upload the
    ePub they found (BookReviewController::upload → the usual review page,
    the reader's book as the first match), or decline.
--}}

@php
    $pending = $book->isPending();
    $links = collect($book->candidateList())->filter(fn($c) => !empty($c['url']))->unique('url')->take(4);
    $searchTerms = trim($book->title . ' ' . ($book->author ?? ''));
@endphp

@section('title', 'Find ' . $book->title)

@section('content')
<div class="book-review">
    <div class="book-review-top">
        <a href="{{ route('requests.index') }}" class="back-link">
            <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Requests
        </a>
        @if(session('queue_left'))
            <span class="book-review-queue">{{ session('queue_left') }} {{ \Illuminate\Support\Str::plural('book', session('queue_left')) }} waiting</span>
        @endif
        @if(session('success_link'))
            <a href="{{ session('success_link') }}" class="book-review-queue">Open the book you just added</a>
        @endif
    </div>

    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            @foreach($errors->all() as $error)<p class="mb-0">{{ $error }}</p>@endforeach
        </div>
    @endif

    @if(!$pending)
        <div class="alert alert-secondary" role="status">
            This request was {{ $book->displayStatus() }}{{ $book->decline_reason ? ': ' . $book->decline_reason : '.' }}
        </div>
    @endif

    <section class="book-file-strip" aria-labelledby="wantedTitle">
        <div class="cover book-file-cover">
            @if($cover)<img src="{{ $cover }}" alt="Cover of {{ $book->title }}" referrerpolicy="no-referrer">@endif
        </div>
        <div class="book-file-main">
            <span class="page-head-kicker">Requested by {{ $requester?->name ?? 'someone' }}{{ $book->created_at ? ' · ' . $book->created_at->diffForHumans() : '' }}@if($followers > 0) · {{ $followers }} more {{ \Illuminate\Support\Str::plural('reader', $followers) }} want it too @endif</span>
            <h1 class="book-file-title" id="wantedTitle">{{ $book->title }}</h1>
            <p class="book-file-facts">{{ $book->author ?: 'Author unknown' }}</p>
            @if($book->note)
                <p class="book-file-note"><span class="req-row-label">{{ $requester?->name ? $requester->name . '’s' : 'Their' }} note:</span> {{ $book->note }}</p>
            @endif
            @if($book->description)
                <details class="book-file-inspect">
                    <summary>About the book</summary>
                    <div class="book-file-inspect-body"><p class="mb-0">{{ $book->description }}</p></div>
                </details>
            @endif
            @if($links->isNotEmpty())
                <p class="book-file-facts mb-0">
                    @foreach($links as $c)
                        <a href="{{ $c['url'] }}" target="_blank" rel="noopener noreferrer">{{ str_contains($c['url'], 'openlibrary.org') ? 'Open Library' : (str_contains($c['url'], 'google.') ? 'Google Books' : 'Catalogue') }}</a>@unless($loop->last) · @endunless
                    @endforeach
                    · <a href="https://www.goodreads.com/search?q={{ urlencode($searchTerms) }}" target="_blank" rel="noopener noreferrer">Goodreads</a>
                </p>
            @endif
        </div>
    </section>

    @if($pending)
        <div class="book-review-work">
            <section class="book-review-panel" aria-labelledby="uploadTitle">
                <h2 class="req-section-title" id="uploadTitle">Upload the ePub</h2>
                <p class="form-text">Found it? Upload its ePub (from Calibre, a store without DRM…). Next you check the details and cover, with this book as the first match, then add it: {{ $requester?->name ?? 'the reader' }}{{ $followers > 0 ? ' and the others who asked' : '' }} hear it is ready to read.</p>
                <form method="POST" action="{{ route('books.review.upload', $book->id) }}" enctype="multipart/form-data" class="book-wanted-upload">
                    @csrf
                    <input type="file" name="epub" accept=".epub,application/epub+zip" class="form-control" required aria-label="The book's ePub file">
                    <button type="submit" class="btn btn-primary" data-busy-label="Uploading…">Upload and match</button>
                </form>
                <p class="form-text mb-0">Up to {{ $maxMb }} MB.</p>
            </section>

            <aside class="book-review-panel book-decide" aria-labelledby="declineTitle">
                <h2 class="req-section-title" id="declineTitle">Can’t find it?</h2>
                <form method="POST" action="{{ route('books.review.decline', $book->id) }}" class="book-review-decline">
                    @csrf
                    <div class="book-review-decline-row">
                        <input type="text" name="reason" class="form-control" maxlength="500" placeholder="Reason (the reader sees it)" aria-label="Reason for declining" value="Couldn’t find an ePub of this book.">
                        <button type="submit" class="btn btn-ghost">Decline</button>
                    </div>
                </form>
            </aside>
        </div>

        {{-- Owner: search Usenet & torrents for it (App\Downloads); grabbed automatically when a clear match turns up. --}}
        @if(\App\Downloads\Downloads::enabled())
            @php
                $wantedRow = \App\DownloadWanted::where('kind', 'ebook')->where('novel_request_id', $book->id)->first();
            @endphp
            <div class="book-review-panel mt-3">
                @if($wantedRow)
                    <p class="dl-wanted-line" id="wantedStatus">
                        @if($wantedRow->monitored)
                            Novarr searches for it by itself{{ $wantedRow->last_searched_at ? ' · last searched ' . $wantedRow->last_searched_at->diffForHumans() : '' }}{{ $wantedRow->next_search_at ? ' · next ' . $wantedRow->next_search_at->diffForHumans() : '' }}.
                            @if($wantedRow->needs_choice) <strong>Needs your choice: {{ $wantedRow->results_count }} {{ \Illuminate\Support\Str::plural('result', $wantedRow->results_count) }}, none a clear match.</strong> @endif
                        @else
                            Not searched for automatically (unmonitored on the <a href="{{ route('downloads.index', ['tab' => 'wanted']) }}">Downloads</a> page).
                        @endif
                    </p>
                @endif
                @include('downloads._finder', [
                    'kind' => 'ebook',
                    'query' => \App\Downloads\DownloadSearch::defaultQuery($book, null),
                    'requestId' => $book->id,
                    'novelId' => null,
                    'grabs' => \App\DownloadGrab::where('novel_request_id', $book->id)->orderByDesc('id')->limit(10)->get(),
                    'finderId' => 'wantedFinder',
                ])
            </div>
        @endif
    @endif
</div>

<script>
    document.querySelector('.book-wanted-upload')?.addEventListener('submit', (event) => {
        const button = event.currentTarget.querySelector('[type=submit]');
        if (button) { button.disabled = true; button.textContent = button.dataset.busyLabel || 'Uploading…'; }
    });
</script>
@endsection
