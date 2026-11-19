@extends('layouts.app')

{{--
    Manga chapter reader. The reader UI (resources/js/manga-reader: pages,
    modes, progress) is mounted on #manga-reader; this view hands it the
    endpoints and renders the chrome it fills in:
      data-pages-url    GET  chapters.pages       (pages + prev/next + reading_mode)
      data-progress-url POST chapters.progress    (percent 0–100, optional read)
      data-mode-url     POST novels.reading_mode  (mode: rtl | ltr | vertical;
                        the admin's: it sets the series default. Anyone else's
                        choice is kept per series in this browser)
      data-lookup       '1' for a look-up open (?lookup=1, a search hit): the
                        reader posts no page progress for this chapter
    Opening this page never marks the chapter read: the reader does, once the
    last page has been shown.
--}}

@php
    $chapterTitle = $chapter->label ?: 'Chapter ' . $chapter->chapter;
    $novelName = $chapter->novel->name ?? 'Back';
@endphp

@section('title', $chapterTitle . ' · ' . ($chapter->novel->name ?? 'Reader'))

@section('chromeless', '1')

@section('content')
<div id="manga-reader" class="mr" tabindex="-1"
     data-chapter-id="{{ $chapter->id }}"
     data-novel-id="{{ $chapter->novel_id }}"
     data-pages-url="{{ route('chapters.pages', $chapter->id) }}"
     data-progress-url="{{ route('chapters.progress', $chapter->id) }}"
     @can('admin') data-mode-url="{{ route('novels.reading_mode', $chapter->novel_id) }}" @endcan
     data-novel-url="{{ route('novels.show', $chapter->novel_id) }}"
     data-novel-name="{{ $chapter->novel->name ?? '' }}"
     data-lookup="{{ request()->boolean('lookup') ? '1' : '' }}"
     aria-label="{{ $chapterTitle }} — manga reader">

    {{-- The pages: filled by the script (paged or vertical). --}}
    <div class="mr-stage" id="mrStage"></div>

    {{-- Loading / error / empty states. --}}
    <div class="mr-status" id="mrStatus" role="status">
        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
        <span>Loading pages…</span>
    </div>

    {{-- Top bar: back to the novel, the chapter, chapter list, theme. --}}
    <div class="mr-chrome mr-top" id="mrTop">
        <a href="{{ route('novels.show', $chapter->novel_id) }}" class="mr-back" aria-label="Back to {{ $novelName }}">
            <x-icon name="chevron-left" :size="16" :stroke="1.75" />
            <span class="mr-back-title">{{ $novelName }}</span>
        </a>
        <span class="mr-title" id="mrChapterLabel">{{ $chapterTitle }}</span>
        <div class="mr-top-actions">
            <button type="button" class="reader-ctl reader-ctl-icon" id="mrTheme" aria-label="Reading theme">
                <x-icon name="sun-moon" :size="14" :stroke="1.75" /><span class="reader-ctl-text" id="mrThemeLabel">Dark</span>
            </button>
            <button type="button" id="tocBtn" class="reader-ctl reader-ctl-icon"
                    data-bs-toggle="offcanvas" data-bs-target="#tocPanel"
                    aria-controls="tocPanel" aria-haspopup="dialog"
                    title="Chapter list" aria-label="Chapter list">
                <x-icon name="list" :size="14" :stroke="1.75" /><span class="reader-ctl-text">Chapters</span>
            </button>
        </div>
    </div>

    {{-- Bottom bar: page slider + counter, reading mode, fit, fullscreen. --}}
    <div class="mr-chrome mr-bottom" id="mrBottom">
        <div class="mr-scrub">
            <input type="range" class="mr-slider" id="mrSlider" min="1" max="1" value="1" step="1"
                   aria-label="Page" aria-valuetext="Page 1 of 1">
            <span class="mr-counter" id="mrCounter" aria-hidden="true">– / –</span>
        </div>
        <div class="mr-controls">
            {{-- The admin's choice is the series default (saved on the
                 server); anyone else's overrides it in this browser only,
                 until "Series default". --}}
            <div class="mr-modes-wrap">
                <div class="reader-seg mr-modes" role="group" aria-label="Reading mode">
                    <button type="button" data-mode="rtl" aria-pressed="false" title="Right to left (manga)">RTL</button>
                    <button type="button" data-mode="ltr" aria-pressed="false" title="Left to right">LTR</button>
                    <button type="button" data-mode="vertical" aria-pressed="false" title="Vertical (webtoon)">Vertical</button>
                </div>
                @cannot('admin')
                <button type="button" class="reader-ctl mr-mode-reset" id="mrModeReset" hidden title="Use this series' default reading mode again">Series default</button>
                @endcannot
            </div>
            {{-- Previous / next chapter, either side of the page slider. --}}
            <button type="button" class="reader-ctl reader-ctl-icon mr-chap mr-chap-prev" id="mrPrevChapter" aria-label="Previous chapter" title="Previous chapter ( [ )" disabled>
                <x-icon name="chevron-left" :size="14" :stroke="1.75" /><span class="reader-ctl-text">Prev</span>
            </button>
            <button type="button" class="reader-ctl reader-ctl-icon mr-chap mr-chap-next" id="mrNextChapter" aria-label="Next chapter" title="Next chapter ( ] )" disabled>
                <span class="reader-ctl-text">Next</span><x-icon name="chevron-right" :size="14" :stroke="1.75" />
            </button>
            <div class="mr-controls-end">
                <button type="button" class="reader-ctl" id="mrFit" aria-label="Page fit: fit height">Fit height</button>
                <button type="button" class="reader-ctl reader-ctl-icon" id="mrFullscreen" aria-label="Fullscreen" aria-pressed="false" hidden>
                    <x-icon name="maximize-2" :size="14" :stroke="1.75" /><span class="reader-ctl-text">Fullscreen</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Page changes, for screen readers. --}}
    <div id="mrAnnounce" class="visually-hidden" aria-live="polite" aria-atomic="true"></div>
</div>

{{-- In-reader chapter list: the text reader's drawer (resources/js/reader/toc.js). --}}
<div class="offcanvas offcanvas-start" tabindex="-1" id="tocPanel" role="dialog" aria-modal="true" aria-labelledby="tocPanelLabel">
    <div class="offcanvas-header pb-2">
        <h5 class="offcanvas-title" id="tocPanelLabel">Chapters</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close chapter list"></button>
    </div>
    <div class="offcanvas-body p-0 d-flex flex-column">
        <div class="toc-tools">
            <input type="search" id="tocFilter" class="form-control form-control-sm" placeholder="Filter by number or title…" aria-label="Filter chapters">
            <div class="toc-tools-row">
                <button type="button" id="tocUnread" class="toc-tool" aria-pressed="false" title="Show only chapters you haven't read">
                    <x-icon name="filter" :size="13" />Unread
                </button>
                <button type="button" id="tocJump" class="toc-tool" title="Scroll the list to the chapter you're reading">
                    <x-icon name="crosshair" :size="13" />Jump to current
                </button>
            </div>
        </div>
        <div id="tocList" class="list-group list-group-flush overflow-auto flex-grow-1 toc-list">
            <div class="p-3 text-muted">Loading…</div>
        </div>
    </div>
</div>
<template id="readerIconCheck"><x-icon name="check" :size="13" :stroke="2.25" class="icon toc-check" /></template>
<template id="mrIconRetry"><x-icon name="refresh-cw" :size="14" /></template>

<noscript>
    <style>.mr { display: none !important; }</style>
    <h1 class="h5">{{ $chapter->novel->name ?? '' }} — {{ $chapterTitle }}</h1>
@forelse ($pages as $page)
    <img src="{{ \App\Support\SignedUrls::route('chapters.page', ['id' => $chapter->id, 'page' => $page->page]) }}" alt="Page {{ $page->page }}" loading="lazy"@if($page->width && $page->height) width="{{ $page->width }}" height="{{ $page->height }}"@endif style="display:block;max-width:100%;height:auto;margin:0 auto">
@empty
    <p>This chapter's pages have not been downloaded yet.</p>
@endforelse
    <p><a href="{{ route('novels.show', $chapter->novel_id) }}">Back to {{ $chapter->novel->name ?? 'the novel' }}</a></p>
</noscript>
@endsection

@push('scripts')
@vite('resources/js/manga-reader/index.js')
@endpush
