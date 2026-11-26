@extends('layouts.app')

{{--
    The owner's review of an uploaded book (BookReviewController). Left: what
    the file says about itself and an excerpt of what it actually holds.
    Right: catalogue matches (Google Books, Open Library) and a search for the
    right book. Below: the details the book will be added with, pre-filled
    from the chosen match (?match=N) or from the file (?match=file).
--}}

@php
    $pending = $book->isPending();
    $status = $book->displayStatus();
    $fileTitle = $meta['title'] ?? null;
    $fileAuthors = implode(', ', (array) ($meta['authors'] ?? []));
    $mb = isset($meta['size']) ? number_format($meta['size'] / 1048576, 1) : null;
    $chosenCover = $chosen['cover_url'] ?? null;
@endphp

@section('title', 'Match ' . $book->title)

@section('content')
<div class="book-review">
    <a href="{{ route('requests.index') }}" class="back-link">
        <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Back to requests
    </a>

    <div class="page-head">
        <div class="page-head-titles">
            <span class="page-head-kicker">Uploaded book · {{ $requester?->name ?? 'Someone' }}{{ $book->created_at ? ' · ' . $book->created_at->diffForHumans() : '' }}</span>
            <h1 class="page-title mb-0">Match this book</h1>
            <p class="page-head-sub mb-0">Check the file is what it claims, pick the catalogue entry it really is (or search for it), then add it with the right details.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-warning" role="status">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            @foreach($errors->all() as $error)<p class="mb-0">{{ $error }}</p>@endforeach
        </div>
    @endif

    @if(!$pending)
        <div class="alert {{ $status === 'declined' ? 'alert-secondary' : 'alert-success' }}" role="status">
            @if($status === 'declined')
                This request was declined{{ $book->decline_reason ? ': ' . $book->decline_reason : '.' }}
            @else
                This book was added to the library.
                @if($novel) <a href="{{ route('novels.show', $novel->id) }}">Open “{{ $novel->name }}”</a>@endif
            @endif
        </div>
    @elseif($fileMissing)
        <div class="alert alert-danger" role="alert">The uploaded file is no longer on the server. Decline this request and ask for it again.</div>
    @endif

    @if($book->note)
        <div class="book-review-note"><span class="req-row-label">{{ $requester?->name ?? 'Their' }} note:</span> {{ $book->note }}</div>
    @endif

    <div class="book-review-grid">
        {{-- What the file says --}}
        <section class="book-review-panel" aria-labelledby="fileTitle">
            <h2 class="req-section-title" id="fileTitle">The file</h2>
            <div class="book-review-file">
                <div class="cover book-review-cover">
                    @if($fileCover)
                        <img src="{{ $fileCover }}" alt="The file’s cover">
                    @endif
                </div>
                <dl class="book-review-facts">
                    <dt>Title</dt><dd>{{ $fileTitle ?: '— none —' }}</dd>
                    <dt>Author</dt><dd>{{ $fileAuthors ?: '— none —' }}</dd>
                    @if(!empty($meta['series']))<dt>Series</dt><dd>{{ $meta['series'] }}{{ !empty($meta['series_index']) ? ' #' . rtrim(rtrim(number_format($meta['series_index'], 1), '0'), '.') : '' }}</dd>@endif
                    <dt>ISBN</dt><dd>{{ $meta['isbn'] ?? '—' }}</dd>
                    <dt>Language</dt><dd>{{ $meta['language'] ?? '—' }}</dd>
                    @if(!empty($meta['publisher']) || !empty($meta['year']))
                        <dt>Published</dt><dd>{{ trim(($meta['publisher'] ?? '') . ' ' . ($meta['year'] ?? '')) }}</dd>
                    @endif
                    <dt>File</dt><dd class="text-break">{{ $meta['original_name'] ?? 'book.epub' }}{{ $mb ? " · {$mb} MB" : '' }}</dd>
                    <dt>Contents</dt><dd>{{ number_format($meta['chapters'] ?? 0) }} chapters · {{ number_format($meta['words'] ?? 0) }} words</dd>
                </dl>
            </div>
            @if(!empty($meta['labels']))
                <details class="book-review-labels">
                    <summary>Chapter list</summary>
                    <ol>
                        @foreach($meta['labels'] as $label)<li>{{ $label }}</li>@endforeach
                    </ol>
                    @if(($meta['chapters'] ?? 0) > count($meta['labels']))
                        <p class="text-muted mb-0">… and {{ $meta['chapters'] - count($meta['labels']) }} more</p>
                    @endif
                </details>
            @endif
            @if(!empty($meta['excerpt']))
                <figure class="book-review-excerpt">
                    <figcaption>How it begins</figcaption>
                    <blockquote>{{ $meta['excerpt'] }}</blockquote>
                </figure>
            @endif
        </section>

        {{-- Catalogue matches --}}
        <section class="book-review-panel" aria-labelledby="matchesTitle">
            <h2 class="req-section-title" id="matchesTitle">Which book is it?</h2>
            @if($pending)
                <form method="POST" action="{{ route('books.review.search', $book->id) }}" class="book-review-search" role="search">
                    @csrf
                    <input type="text" name="q_title" class="form-control" placeholder="Title" aria-label="Title to search for"
                           value="{{ old('q_title', $fileTitle ?: $book->title) }}">
                    <input type="text" name="q_author" class="form-control" placeholder="Author" aria-label="Author to search for"
                           value="{{ old('q_author', $meta['authors'][0] ?? '') }}">
                    <input type="text" name="q_isbn" class="form-control" placeholder="ISBN (optional)" aria-label="ISBN to search for"
                           value="{{ old('q_isbn') }}" inputmode="numeric">
                    <button type="submit" class="btn btn-secondary">Search</button>
                </form>
            @endif

            <ul class="book-review-matches" aria-label="Matches">
                <li class="book-match {{ $form['match'] === 'file' ? 'is-chosen' : '' }}">
                    <div class="book-match-body">
                        <span class="book-match-title">Use the file’s own details</span>
                        <span class="book-match-meta">{{ $fileTitle ?: $book->title }}{{ $fileAuthors ? ' · ' . $fileAuthors : '' }}</span>
                    </div>
                    @if($pending)
                        @if($form['match'] === 'file')
                            <span class="badge badge-success">Chosen</span>
                        @else
                            <a href="{{ route('books.review', [$book->id, 'match' => 'file']) }}#details" class="btn btn-sm btn-ghost">Use these</a>
                        @endif
                    @endif
                </li>
                @forelse($candidates as $i => $c)
                    <li class="book-match {{ $form['match'] === $i ? 'is-chosen' : '' }}">
                        <div class="cover book-match-cover">
                            @if(!empty($c['cover_url']))
                                <img src="{{ $c['cover_url'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                            @endif
                        </div>
                        <div class="book-match-body">
                            <span class="book-match-title">{{ $c['title'] }}</span>
                            <span class="book-match-meta">
                                {{ implode(', ', (array) ($c['authors'] ?? [])) ?: 'Unknown author' }}
                                @if(!empty($c['year'])) · {{ $c['year'] }}@endif
                                @if(!empty($c['publisher'])) · {{ $c['publisher'] }}@endif
                            </span>
                            <span class="book-match-meta">
                                {{ $c['source_name'] ?? $c['source'] }}
                                @if(!empty($c['isbn'])) · ISBN {{ $c['isbn'] }}@endif
                                @if(!empty($c['url'])) · <a href="{{ $c['url'] }}" target="_blank" rel="noopener noreferrer">View</a>@endif
                            </span>
                            @if(!empty($c['description']))
                                <span class="book-match-desc">{{ \Illuminate\Support\Str::limit($c['description'], 220) }}</span>
                            @endif
                        </div>
                        @if($pending)
                            @if($form['match'] === $i)
                                <span class="badge badge-success">Chosen</span>
                            @else
                                <a href="{{ route('books.review', [$book->id, 'match' => $i]) }}#details" class="btn btn-sm btn-ghost">Use this</a>
                            @endif
                        @endif
                    </li>
                @empty
                    <li class="book-match book-match-empty">No catalogue matches for these details. Search with the real title or the ISBN, or use the file’s own details.</li>
                @endforelse
            </ul>
        </section>
    </div>

    @if($pending && !$fileMissing)
        <section class="book-review-panel" id="details" aria-labelledby="detailsTitle">
            <h2 class="req-section-title" id="detailsTitle">Add it with these details</h2>
            <form method="POST" action="{{ route('books.review.approve', $book->id) }}">
                @csrf
                <input type="hidden" name="match" value="{{ $form['match'] }}">
                <div class="novel-form">
                    <div class="novel-form-section">
                        <div class="form-grid">
                            <div class="field field-half">
                                <label for="title" class="form-label">Title <span class="field-required">*</span></label>
                                <input type="text" name="title" id="title" class="form-control" required maxlength="250" value="{{ old('title', $form['title']) }}">
                            </div>
                            <div class="field field-half">
                                <label for="author" class="form-label">Author</label>
                                <input type="text" name="author" id="author" class="form-control" maxlength="250" value="{{ old('author', $form['author']) }}">
                            </div>
                            <div class="field field-full">
                                <label for="description" class="form-label">Description</label>
                                <textarea name="description" id="description" class="form-control" rows="5">{{ old('description', $form['description']) }}</textarea>
                            </div>
                            <div class="field field-full">
                                <label for="genres" class="form-label">Genres</label>
                                <input type="text" name="genres" id="genres" class="form-control" maxlength="500" value="{{ old('genres', $form['genres']) }}" aria-describedby="genresHelp">
                                <p class="form-text mb-0" id="genresHelp">Comma separated; each becomes a tag.</p>
                            </div>
                            <fieldset class="field field-full book-review-covers">
                                <legend class="form-label">Cover</legend>
                                @php($coverChoice = old('cover', $form['cover']))
                                @if($chosenCover)
                                    <label class="book-cover-choice">
                                        <input type="radio" name="cover" value="match" @checked($coverChoice === 'match')>
                                        <span class="cover"><img src="{{ $chosenCover }}" alt="" referrerpolicy="no-referrer"></span>
                                        <span>The match’s cover</span>
                                    </label>
                                @endif
                                @if($fileCover)
                                    <label class="book-cover-choice">
                                        <input type="radio" name="cover" value="file" @checked($coverChoice === 'file')>
                                        <span class="cover"><img src="{{ $fileCover }}" alt=""></span>
                                        <span>The file’s cover</span>
                                    </label>
                                @endif
                                <label class="book-cover-choice">
                                    <input type="radio" name="cover" value="none" @checked($coverChoice === 'none' || (!$chosenCover && !$fileCover))>
                                    <span class="cover"></span>
                                    <span>No cover (a designed one is made)</span>
                                </label>
                            </fieldset>
                            @if(session('duplicate'))
                                <div class="field field-full">
                                    <label class="form-check">
                                        <input type="checkbox" name="force" value="1" class="form-check-input">
                                        <span class="form-check-label">It is a different book (or edition): add it anyway</span>
                                    </label>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="novel-form-section novel-form-actions">
                        <button type="submit" class="btn btn-primary">Add to the library</button>
                    </div>
                </div>
            </form>

            <form method="POST" action="{{ route('books.review.decline', $book->id) }}" class="book-review-decline">
                @csrf
                <label for="reason" class="form-label">Or decline it</label>
                <div class="book-review-decline-row">
                    <input type="text" name="reason" id="reason" class="form-control" maxlength="500" placeholder="Reason (optional, the reader sees it)">
                    <button type="submit" class="btn btn-ghost">Decline</button>
                </div>
            </form>
        </section>
    @endif
</div>
@endsection
