@extends('layouts.app')

@section('title', 'Want to read')

{{-- The reader's whole "Want to read" (HomeController::wantToRead()):
     novels, manga and books, newest first. Each tile's bookmark takes it off
     (or, clicked again, puts it back) — resources/js/want-to-read.js. --}}
@section('content')
<div class="wtr-page">
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Your shortlist</span>
        <h1 class="page-title mb-0">Want to read</h1>
        <p class="page-head-sub mb-0">
            @if($want_to_read['count'] > 0)
                <span class="mono-num">{{ number_format($want_to_read['count']) }}</span> {{ Str::plural('series', $want_to_read['count']) }}, newest first. Reading one keeps it here, marked Started.
            @else
                What you want to read next, in one place.
            @endif
        </p>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('novels.index') }}" class="btn btn-secondary">Library</a>
    </div>
</div>

<p class="visually-hidden" data-want-status role="status" aria-live="polite"></p>

@if($want_to_read['count'] === 0)
    <div class="wtr-empty" id="wantToReadEmpty">
        <p>Nothing here yet. Open a novel, manga or book and tap <strong><x-icon name="bookmark" :size="14" /> Want to read</strong> at the top of its page: it waits here (and on Home) until you get to it.</p>
        <a href="{{ route('novels.index') }}" class="btn btn-primary">Browse the library</a>
    </div>
@else
    <ul class="wtr-grid" id="wantToReadGrid" role="list">
        @foreach($want_to_read['items'] as $item)
            @php $n = $item['novel']; @endphp
            <li class="wtr-item" data-want-item data-novel="{{ $n->id }}">
                <a href="{{ route('novels.show', $n->id) }}" class="home-tile">
                    <div class="home-cover{{ $n->isManga() ? ' has-chip' : '' }}">
                        @if($cover = $n->coverUrl('sm'))
                            @php $cover2x = $n->coverThumbnailUrl('md'); @endphp
                            <img src="{{ $cover }}"{!! $cover2x ? ' srcset="' . e($cover) . ' 1x, ' . e($cover2x) . ' 2x"' : '' !!} alt="" loading="lazy" decoding="async">
                        @else
                            <span class="home-cover-title">{{ $n->name }}</span>
                        @endif
                        @if($n->isManga())
                            <span class="cover-chip">MANGA</span>
                        @endif
                    </div>
                    <span class="home-tile-title">{{ $n->name }}</span>
                </a>
                <div class="wtr-actions">
                    @if($item['started'])
                        <span class="wtr-started">Started</span>
                    @else
                        <span class="home-tile-meta">{{ $n->isBook() ? 'Book' : ($n->isManga() ? 'Manga' : 'Novel') }}</span>
                    @endif
                    <button type="button" class="btn btn-ghost btn-sm btn-icon nv-want is-wanted ms-auto" data-want-to-read data-novel="{{ $n->id }}" data-name="{{ $n->name }}" data-want-keep-title
                            aria-pressed="true" aria-label="Want to read: {{ $n->name }}" title="Take it off, or put it back">
                        <x-icon name="bookmark" :size="14" />
                    </button>
                </div>
            </li>
        @endforeach
    </ul>
@endif
</div>
@endsection
