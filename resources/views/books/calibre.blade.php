@extends('layouts.app')

{{-- Admin: the owner's Calibre-Web, newest first or searched; Import adds a book straight away (CalibreController). --}}

@section('title', 'Import from Calibre')

@section('content')
<div class="book-review">
    <a href="{{ route('novels.index', ['medium' => 'book']) }}" class="back-link">
        <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Books
    </a>

    <div class="page-head">
        <div class="page-head-titles">
            <span class="page-head-kicker">Books · Calibre-Web</span>
            <h1 class="page-title mb-0">Import from Calibre</h1>
            <p class="page-head-sub mb-0">
                Your Calibre library{{ config('novarr.calibre_web.languages') ? ' (' . implode(', ', config('novarr.calibre_web.languages')) . ' and books with no language)' : '' }}.
                Calibre’s title, author, description, tags and cover are used as they are.
                @if($sync)
                    New books added to Calibre are imported every hour{{ $mark ? '' : ' (from the first run on)' }}.
                @endif
            </p>
        </div>
    </div>

    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif
    @if($error)<div class="alert alert-danger" role="alert">{{ $error }}</div>@endif

    <form method="GET" action="{{ route('books.calibre') }}" class="book-review-search book-calibre-search" role="search">
        <input type="search" name="q" class="form-control" placeholder="Title, author, series or tag" aria-label="Search Calibre" value="{{ $query }}">
        <button type="submit" class="btn btn-secondary">Search</button>
        @if($query !== '')
            <a href="{{ route('books.calibre') }}" class="btn btn-ghost">Newest</a>
        @endif
    </form>

    <ul class="book-review-matches" aria-label="{{ $query !== '' ? 'Search results' : 'Newest in Calibre' }}">
        @forelse($entries as $e)
            @php($inLibrary = $e['uuid'] ? ($imported[$e['uuid']] ?? null) : null)
            <li class="book-match">
                <div class="cover book-match-cover">
                    @if($e['cover_href'] && $e['id'])
                        <img src="{{ route('books.calibre.cover', $e['id']) }}" alt="" loading="lazy">
                    @endif
                </div>
                <div class="book-match-body">
                    <span class="book-match-title">{{ $e['title'] }}</span>
                    <span class="book-match-meta">
                        {{ implode(', ', $e['authors']) ?: 'Unknown author' }}
                        @if($e['series']) · {{ $e['series'] }}{{ $e['series_index'] ? ' #' . rtrim(rtrim(number_format($e['series_index'], 1), '0'), '.') : '' }}@endif
                        @if($e['year']) · {{ $e['year'] }}@endif
                    </span>
                    @if($e['tags'])<span class="book-match-meta">{{ implode(' · ', array_slice($e['tags'], 0, 6)) }}</span>@endif
                    @if($e['description'])<span class="book-match-desc">{{ \Illuminate\Support\Str::limit($e['description'], 200) }}</span>@endif
                </div>
                @if($inLibrary)
                    <a href="{{ route('novels.show', $inLibrary) }}" class="btn btn-sm btn-ghost">In library</a>
                @elseif(!$e['epub_href'])
                    <span class="badge badge-secondary" title="Calibre has no ePub of this book (convert it in Calibre first)">No ePub</span>
                @else
                    <form method="POST" action="{{ route('books.calibre.import') }}">
                        @csrf
                        <input type="hidden" name="id" value="{{ $e['id'] }}">
                        <button type="submit" class="btn btn-sm btn-primary" data-busy-label="Importing…">Import</button>
                    </form>
                @endif
            </li>
        @empty
            @unless($error)
                <li class="book-match book-match-empty">{{ $query !== '' ? 'Nothing in Calibre matches that.' : 'Calibre has no books to show.' }}</li>
            @endunless
        @endforelse
    </ul>

    <div class="book-calibre-paging">
        @if($offset > 0)
            <a class="btn btn-secondary" href="{{ route('books.calibre', array_filter(['q' => $query, 'offset' => max(0, $offset - max(count($entries), 1))])) }}">Previous</a>
        @endif
        @if($next !== null)
            <a class="btn btn-secondary" href="{{ route('books.calibre', array_filter(['q' => $query, 'offset' => $next])) }}">Next</a>
        @endif
    </div>
</div>

<script>
    document.querySelectorAll('[data-busy-label]').forEach((button) => button.form?.addEventListener('submit', () => {
        button.disabled = true;
        button.textContent = button.dataset.busyLabel;
    }));
</script>
@endsection
