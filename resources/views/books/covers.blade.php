@extends('layouts.app')

{{--
    "Find a better cover" (BookCoverController): the book's cover beside
    every catalogue cover of the same book, largest first, with their sizes.
    Choosing one makes it the book's, as the owner's choice (books:covers
    never replaces it afterwards). Styled like the review page's cover chooser.
--}}

@section('title', 'Cover of ' . $book->name)

@section('content')
<div class="book-review" id="bookCovers">
    <div class="book-review-top">
        <a href="{{ route('novels.show', $book->id) }}" class="back-link">
            <x-icon name="chevron-left" :size="14" :stroke="1.5" /> {{ $book->name }}
        </a>
        <a href="{{ route('novels.covers', [$book->id, 'fresh' => 1]) }}" class="book-review-queue">Search again</a>
    </div>

    @if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif

    <section class="book-review-panel" aria-labelledby="coversTitle">
        <h1 class="req-section-title" id="coversTitle">Find a better cover</h1>
        <p class="form-text">
            {{ $book->name }}{{ $book->author ? ' · ' . $book->author : '' }}.
            Now:
            @if($current['exists'])
                {{ $current['width'] }}×{{ $current['height'] }}{{ $current['placeholder'] ? ' — ' . $current['reason'] : '' }}.
            @else
                no cover.
            @endif
            @if($book->cover_source === \App\Books\BookCovers::SOURCE_MANUAL) You chose it, so it is never replaced automatically.@endif
            @if($book->cover_source === \App\Books\BookCovers::SOURCE_GENERATED) An AI painted it: any real cover of this book replaces it.@endif
        </p>

        <fieldset class="book-review-covers">
            <legend class="form-label">Covers</legend>
            <div class="book-cover-choice">
                <span class="cover">@if($currentUrl)<img src="{{ $currentUrl }}" alt="The current cover">@endif</span>
                <span class="book-cover-caption">Current</span>
            </div>
            @forelse($candidates as $i => $c)
                <form method="POST" action="{{ route('novels.covers.choose', $book->id) }}" class="book-cover-choice" data-cover-candidate="{{ $i }}">
                    @csrf
                    <input type="hidden" name="choice" value="{{ $i }}">
                    <span class="cover"><img src="{{ $c['url'] }}" alt="" loading="lazy" referrerpolicy="no-referrer"></span>
                    <span class="book-cover-caption">
                        {{ $c['source_name'] }} · {{ $c['width'] }}×{{ $c['height'] }}@if($c['isbn_match']) · same edition @endif
                    </span>
                    <button type="submit" class="btn btn-sm btn-primary">Use this</button>
                </form>
            @empty
                <p class="form-text" id="noCoverCandidates">No catalogue has a cover of this book (same title and author, or the same ISBN). Upload one on the edit page{{ \App\Covers\CoverGenerator::enabled() ? ', or have one painted' : '' }}.</p>
            @endforelse
            @if(\App\Covers\CoverGenerator::enabled())
                {{-- The AI option (App\Covers): queued; the series page shows the progress. --}}
                <form method="POST" action="{{ route('novels.covers.generate', $book->id) }}" class="book-cover-choice" id="generateCoverChoice">
                    @csrf
                    <span class="cover book-cover-ai" aria-hidden="true"><x-icon name="image" :size="24" /></span>
                    <span class="book-cover-caption">{{ $book->cover_source === \App\Books\BookCovers::SOURCE_GENERATED ? 'Another AI cover' : 'AI cover' }} · painted from the story</span>
                    <button type="submit" class="btn btn-sm btn-secondary">{{ $book->cover_source === \App\Books\BookCovers::SOURCE_GENERATED ? 'Regenerate' : 'Generate' }}</button>
                </form>
            @endif
        </fieldset>
    </section>
</div>
@endsection
