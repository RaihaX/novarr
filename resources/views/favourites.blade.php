@extends('layouts.app')

@section('title', 'Favourites')

{{-- The reader's whole "Favourites" (HomeController::favourites()): the
     second built-in reading list, novels, manga and books, newest first.
     Each tile's heart takes it out (or, clicked again, puts it back) —
     resources/js/want-to-read.js. --}}
@section('content')
<div class="wtr-page">
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Your best-loved</span>
        <h1 class="page-title mb-0">Favourites</h1>
        <p class="page-head-sub mb-0">
            @if($favourites['count'] > 0)
                <span class="mono-num">{{ number_format($favourites['count']) }}</span> {{ Str::plural('series', $favourites['count']) }}, newest first.
            @else
                The series you love, in one place.
            @endif
        </p>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('novels.index') }}" class="btn btn-secondary">Library</a>
    </div>
</div>

<p class="visually-hidden" data-want-status role="status" aria-live="polite"></p>

@if($favourites['count'] === 0)
    <div class="wtr-empty" id="favouritesEmpty">
        <p>Nothing here yet. Open a novel, manga or book and tap the <strong><x-icon name="heart" :size="14" /> heart</strong> at the top of its page: it is kept here (and on Home).</p>
        <a href="{{ route('novels.index') }}" class="btn btn-primary">Browse the library</a>
    </div>
@else
    <ul class="wtr-grid" id="favouritesGrid" role="list">
        @foreach($favourites['items'] as $item)
            @php $n = $item['novel']; @endphp
            <li class="wtr-item" data-favourite-item data-novel="{{ $n->id }}">
                <a href="{{ route('novels.show', $n->id) }}" class="home-tile">
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
                </a>
                <div class="wtr-actions">
                    @if($item['started'])
                        <span class="wtr-started">Started</span>
                    @else
                        <span class="home-tile-meta">{{ $n->isBook() ? 'Book' : ($n->isManga() ? 'Manga' : ($n->isComic() ? 'Comic' : 'Web Novel')) }}</span>
                    @endif
                    <button type="button" class="btn btn-ghost btn-sm btn-icon nv-favourite is-favourite ms-auto" data-favourite data-novel="{{ $n->id }}" data-name="{{ $n->name }}" data-favourite-keep-title data-favourite-keep-label
                            aria-pressed="true" aria-label="Favourite: {{ $n->name }}" title="Take it out, or put it back">
                        <x-icon name="heart" :size="14" />
                    </button>
                </div>
            </li>
        @endforeach
    </ul>
@endif
</div>
@endsection
