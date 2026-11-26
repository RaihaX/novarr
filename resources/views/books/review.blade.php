@extends('layouts.app')

{{--
    The owner's review of an uploaded book (BookReviewController), laid out
    so nothing needs scrolling: the file at a glance on top (the chapter list
    and an excerpt fold out), then catalogue matches with a search on the
    left and, on the right, a sticky panel with the details the book will be
    added with and Add / Decline. "Use this" fills that panel in place
    (data-match-*; the link reloads with ?match=N without JavaScript).
--}}

@php
    $pending = $book->isPending();
    $status = $book->displayStatus();
    $fileTitle = $meta['title'] ?? null;
    $fileAuthors = implode(', ', (array) ($meta['authors'] ?? []));
    $mb = isset($meta['size']) ? number_format($meta['size'] / 1048576, 1) : null;
    $chosenCover = $chosen['cover_url'] ?? null;
    $coverChoice = old('cover', $form['cover']);
    $fileDetails = [
        'title' => $fileTitle ?: $book->title,
        'author' => $fileAuthors ?: (string) $book->author,
        'description' => (string) $book->description,
        'genres' => implode(', ', \App\Books\BookLibrary::subjectTags((array) ($meta['subjects'] ?? []))),
        'cover' => '',
    ];
@endphp

@section('title', 'Match ' . $book->title)

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
        <div class="alert {{ $status === 'declined' ? 'alert-secondary' : 'alert-success' }}" role="status">
            @if($status === 'declined')
                This upload was declined{{ $book->decline_reason ? ': ' . $book->decline_reason : '.' }}
            @else
                This book was added to the library.
                @if($novel) <a href="{{ route('novels.show', $novel->id) }}">Open “{{ $novel->name }}”</a>@endif
            @endif
        </div>
    @elseif($fileMissing)
        <div class="alert alert-danger" role="alert">The uploaded file is no longer on the server. Decline this request and ask for it again.</div>
    @endif

    {{-- The file at a glance --}}
    <section class="book-file-strip" aria-labelledby="fileTitle">
        <div class="cover book-file-cover">
            @if($fileCover)<img src="{{ $fileCover }}" alt="The file’s cover">@endif
        </div>
        <div class="book-file-main">
            <span class="page-head-kicker">@if(!empty($meta['requested']))Requested by {{ $requester?->name ?? 'someone' }} as “{{ $meta['requested']['title'] }}” · you uploaded the ePub @else Uploaded by {{ $requester?->name ?? 'someone' }}{{ $book->created_at ? ' · ' . $book->created_at->diffForHumans() : '' }}@endif</span>
            <h1 class="book-file-title" id="fileTitle">{{ $fileTitle ?: '(no title in the file)' }}</h1>
            <p class="book-file-facts">
                {{ $fileAuthors ?: 'No author in the file' }}
                · ISBN {{ $meta['isbn'] ?? '—' }}
                · {{ $meta['language'] ?? '?' }}
                @if(!empty($meta['series'])) · {{ $meta['series'] }}{{ !empty($meta['series_index']) ? ' #' . rtrim(rtrim(number_format($meta['series_index'], 1), '0'), '.') : '' }}@endif
                · {{ number_format($meta['chapters'] ?? 0) }} chapters, {{ number_format($meta['words'] ?? 0) }} words
                · {{ $meta['original_name'] ?? 'book.epub' }}{{ $mb ? " ({$mb} MB)" : '' }}
            </p>
            @if($book->note)
                <p class="book-file-note"><span class="req-row-label">{{ $requester?->name ? $requester->name . '’s' : 'Their' }} note:</span> {{ $book->note }}</p>
            @endif
            <details class="book-file-inspect">
                <summary>Inspect the file (chapter list and how it begins)</summary>
                <div class="book-file-inspect-body">
                    @if(!empty($meta['labels']))
                        <ol class="book-file-labels">
                            @foreach($meta['labels'] as $label)<li>{{ $label }}</li>@endforeach
                            @if(($meta['chapters'] ?? 0) > count($meta['labels']))<li class="text-muted">… and {{ $meta['chapters'] - count($meta['labels']) }} more</li>@endif
                        </ol>
                    @endif
                    @if(!empty($meta['excerpt']))
                        <blockquote class="book-file-excerpt">{{ $meta['excerpt'] }}</blockquote>
                    @endif
                </div>
            </details>
        </div>
    </section>

    <div class="book-review-work">
        {{-- Which book is it? --}}
        <section class="book-review-panel" aria-labelledby="matchesTitle">
            <h2 class="req-section-title" id="matchesTitle">Which book is it?</h2>
            @if($pending)
                <form method="POST" action="{{ route('books.review.search', $book->id) }}" class="book-review-search" role="search">
                    @csrf
                    <input type="text" name="q_title" class="form-control" placeholder="Title" aria-label="Title to search for" value="{{ old('q_title', $fileTitle ?: $book->title) }}">
                    <input type="text" name="q_author" class="form-control" placeholder="Author" aria-label="Author to search for" value="{{ old('q_author', $meta['authors'][0] ?? '') }}">
                    <input type="text" name="q_isbn" class="form-control" placeholder="ISBN" aria-label="ISBN to search for" value="{{ old('q_isbn') }}" inputmode="numeric">
                    <button type="submit" class="btn btn-secondary">Search</button>
                </form>
            @endif

            <ul class="book-review-matches" aria-label="Matches">
                <li class="book-match {{ $form['match'] === 'file' ? 'is-chosen' : '' }}" data-match-row>
                    <div class="book-match-body">
                        <span class="book-match-title">The file’s own details</span>
                        <span class="book-match-meta">{{ $fileDetails['title'] }}{{ $fileDetails['author'] ? ' · ' . $fileDetails['author'] : '' }}</span>
                    </div>
                    @if($pending)
                        <a href="{{ route('books.review', [$book->id, 'match' => 'file']) }}" class="btn btn-sm btn-ghost"
                           data-match="file" data-match-details='@json($fileDetails)'>Use these</a>
                    @endif
                </li>
                @forelse($candidates as $i => $c)
                    @php($details = [
                        'title' => $c['title'],
                        'author' => implode(', ', (array) ($c['authors'] ?? [])),
                        'description' => ((string) ($c['description'] ?? '')) ?: (string) $book->description,
                        'genres' => implode(', ', (array) ($c['genres'] ?? [])) ?: $fileDetails['genres'],
                        'cover' => (string) ($c['cover_url'] ?? ''),
                    ])
                    <li class="book-match {{ $form['match'] === $i ? 'is-chosen' : '' }}" data-match-row>
                        <div class="cover book-match-cover">
                            @if(!empty($c['cover_url']))<img src="{{ $c['cover_url'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">@endif
                        </div>
                        <div class="book-match-body">
                            <span class="book-match-title">{{ $c['title'] }}</span>
                            <span class="book-match-meta">
                                {{ $details['author'] ?: 'Unknown author' }}@if(!empty($c['year'])) · {{ $c['year'] }}@endif @if(!empty($c['publisher'])) · {{ $c['publisher'] }}@endif
                            </span>
                            <span class="book-match-meta">
                                {{ $c['source_name'] ?? $c['source'] }}@if(!empty($c['isbn'])) · ISBN {{ $c['isbn'] }}@endif @if(!empty($c['url'])) · <a href="{{ $c['url'] }}" target="_blank" rel="noopener noreferrer">View</a>@endif
                            </span>
                            @if(!empty($c['description']))<span class="book-match-desc">{{ \Illuminate\Support\Str::limit($c['description'], 180) }}</span>@endif
                        </div>
                        @if($pending)
                            <a href="{{ route('books.review', [$book->id, 'match' => $i]) }}" class="btn btn-sm btn-ghost"
                               data-match="{{ $i }}" data-match-details='@json($details)'>Use this</a>
                        @endif
                    </li>
                @empty
                    <li class="book-match book-match-empty">No catalogue matches. Search with the real title or the ISBN, or use the file’s own details.</li>
                @endforelse
            </ul>
        </section>

        {{-- The details it will be added with: always in view --}}
        @if($pending && !$fileMissing)
            <aside class="book-review-panel book-review-decide" aria-labelledby="detailsTitle">
                <h2 class="req-section-title" id="detailsTitle">Add it as</h2>
                <form method="POST" action="{{ route('books.review.approve', $book->id) }}" id="bookApprove">
                    @csrf
                    <input type="hidden" name="match" value="{{ $form['match'] }}">
                    <div class="book-decide-fields">
                        <label class="form-label" for="title">Title <span class="field-required">*</span></label>
                        <input type="text" name="title" id="title" class="form-control" required maxlength="250" value="{{ old('title', $form['title']) }}">
                        <label class="form-label" for="author">Author</label>
                        <input type="text" name="author" id="author" class="form-control" maxlength="250" value="{{ old('author', $form['author']) }}">
                        <label class="form-label" for="genres">Genres <span class="form-text">(comma separated)</span></label>
                        <input type="text" name="genres" id="genres" class="form-control" maxlength="500" value="{{ old('genres', $form['genres']) }}">
                        <label class="form-label" for="description">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="5">{{ old('description', $form['description']) }}</textarea>
                    </div>
                    <fieldset class="book-review-covers">
                        <legend class="form-label">Cover</legend>
                        <label class="book-cover-choice" data-cover-choice="match" @if(!$chosenCover) hidden @endif>
                            <span class="cover"><img src="{{ $chosenCover }}" alt="" referrerpolicy="no-referrer" data-match-cover></span>
                            <span class="book-cover-caption"><input type="radio" name="cover" value="match" @checked($coverChoice === 'match' && $chosenCover)> The match’s</span>
                        </label>
                        @if($fileCover)
                            <label class="book-cover-choice">
                                <span class="cover"><img src="{{ $fileCover }}" alt=""></span>
                                <span class="book-cover-caption"><input type="radio" name="cover" value="file" @checked($coverChoice === 'file')> The file’s</span>
                            </label>
                        @endif
                        <label class="book-cover-choice">
                            <span class="cover"></span>
                            <span class="book-cover-caption"><input type="radio" name="cover" value="none" @checked($coverChoice === 'none' || (!$chosenCover && !$fileCover))> None</span>
                        </label>
                    </fieldset>
                    @if(session('duplicate'))
                        <label class="form-check">
                            <input type="checkbox" name="force" value="1" class="form-check-input">
                            <span class="form-check-label">It is a different book (or edition): add it anyway</span>
                        </label>
                    @endif
                    <button type="submit" class="btn btn-primary book-decide-add">Add to the library</button>
                </form>
                <form method="POST" action="{{ route('books.review.decline', $book->id) }}" class="book-review-decline">
                    @csrf
                    <div class="book-review-decline-row">
                        <input type="text" name="reason" class="form-control" maxlength="500" placeholder="Reason (the reader sees it)" aria-label="Reason for declining">
                        <button type="submit" class="btn btn-ghost">Decline</button>
                    </div>
                </form>
            </aside>
        @endif
    </div>
</div>

<script>
    // "Use this" fills the panel in place: no reload, no scrolling.
    (() => {
        const form = document.getElementById('bookApprove');
        if (!form) return;
        const field = (name) => form.querySelector(`[name="${name}"]`);
        const matchCover = form.querySelector('[data-cover-choice="match"]');
        document.querySelectorAll('[data-match-details]').forEach((link) => link.addEventListener('click', (event) => {
            event.preventDefault();
            const d = JSON.parse(link.dataset.matchDetails);
            field('match').value = link.dataset.match;
            ['title', 'author', 'description', 'genres'].forEach((k) => { field(k).value = d[k] || ''; });
            matchCover.hidden = !d.cover;
            if (d.cover) {
                matchCover.querySelector('[data-match-cover]').src = d.cover;
                matchCover.querySelector('input').checked = true;
            } else if (matchCover.querySelector('input').checked) {
                (form.querySelector('[name=cover][value=file]') || form.querySelector('[name=cover][value=none]')).checked = true;
            }
            document.querySelectorAll('[data-match-row]').forEach((row) => row.classList.toggle('is-chosen', row.contains(link)));
            field('title').focus({ preventScroll: true });
        }));
    })();
</script>
@endsection
