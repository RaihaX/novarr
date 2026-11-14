@extends('layouts.app')

@push('styles')
{{-- Preload the next chapter body (the direction readers actually go). --}}
@if($next)
    <link rel="prefetch" href="{{ route('chapters.show', $next->id) }}">
@endif
@endpush

@php
    // Kicker data ("CHAPTER 12 OF 323 · 9 MIN LEFT"). The position is the
    // chapter's rank among the novel's non-blacklisted chapters, ordered the
    // same way the prev/next queries are, so it stays consistent with the
    // reader's own navigation even when a novel is split into books.
    $novelId = $chapter->novel_id;
    $chapterTotal = \App\NovelChapter::where('novel_id', $novelId)->where('blacklist', 0)->count();
    $chapterIndex = \App\NovelChapter::where('novel_id', $novelId)
        ->where('blacklist', 0)
        ->relativeTo($chapter, '<=')
        ->count();
    $chapterTitle = $chapter->label ?: 'Chapter ' . $chapter->chapter;
    // Honest minutes-left: real word count of this chapter's body, scaled by
    // how much of it is still below the viewport (see updateMinutesLeft()).
    $wordCount = str_word_count(strip_tags((string) $chapter->description));

    // "Ch. 12 · Chapter 12 - Title" says the number twice; only prefix the
    // number when the label doesn't already start with it (mirrors chLabel()
    // in resources/js/reader/format.js).
    $navLabel = function ($c) {
        $label = $c->label ?: 'Chapter ' . $c->chapter;
        $num = is_numeric($c->chapter) ? (string) (0 + $c->chapter) : (string) $c->chapter;
        $leads = preg_match('/^\s*(?:ch(?:apter)?\.?\s*)?#?' . preg_quote($num, '/') . '(?![\d.])/i', $label);

        return Str::limit($leads ? $label : 'Ch. ' . $num . ' · ' . $label, 40);
    };

    // Everything the reader module (resources/js/reader) needs from the
    // server rides in #readerState, so the script itself is identical on every
    // chapter and cached once. `title` is the full <title> below, so a
    // continuously-appended chapter can set it verbatim when it becomes current.
    $readerState = array_merge($readerState, [
        'title' => $chapterTitle . ' · ' . ($chapter->novel->name ?? 'Reader') . ' · ' . config('app.name', 'Novarr'),
        'novelName' => $chapter->novel->name ?? null,
        'bulkReadUrl' => route('chapters.bulk_read'),
        'bookmarksUrl' => route('bookmarks.store'),
    ]);
@endphp

@section('title', $chapterTitle . ' · ' . ($chapter->novel->name ?? 'Reader'))

{{-- Reader is chromeless: no global navbar (layouts/app.blade.php) --}}
@section('chromeless', '1')

@section('content')
<div class="reader" id="reader" data-reader-page data-reader-module>

    {{-- 52px chrome bar + the 2px chapter-progress rail directly under it.
         The pair sticks below the navbar; in focus mode only the bar hides,
         so the rail keeps reporting position. --}}
    <div class="reader-chrome" id="readerChrome">
        <div class="reader-bar" id="readerToolbar">
            <a href="{{ route('novels.show', $novelId) }}" class="reader-back">
                <x-icon name="chevron-left" :size="14" :stroke="1.75" />
                <span class="reader-back-title">{{ $chapter->novel->name ?? 'Back' }}</span>
            </a>
            <div class="reader-controls">
                <button type="button" id="readerSettingsBtn" class="reader-ctl reader-ctl-aa"
                        aria-expanded="false" aria-controls="readerSettings" aria-haspopup="dialog"
                        title="Reading settings" aria-label="Reading settings">Aa</button>
                <button type="button" id="playbackBtn" class="reader-ctl reader-ctl-icon"
                        aria-expanded="false" aria-controls="readerPlayback" aria-haspopup="dialog"
                        title="Playback — listen or auto-scroll" aria-label="Playback">
                    <x-icon name="headphones" :size="14" :stroke="1.75" /><span class="reader-ctl-text">Playback</span>
                </button>
                <button type="button" id="tocBtn" class="reader-ctl reader-ctl-icon"
                        data-bs-toggle="offcanvas" data-bs-target="#tocPanel"
                        aria-controls="tocPanel" aria-haspopup="dialog"
                        title="Chapter list" aria-label="Chapter list">
                    <x-icon name="list" :size="14" :stroke="1.75" /><span class="reader-ctl-text">Contents</span>
                </button>
                <button type="button" id="focusBtn" class="reader-ctl reader-ctl-icon"
                        title="Focus mode (hide chrome — tap the page to peek)"
                        aria-label="Focus mode" aria-pressed="false">
                    <x-icon name="maximize-2" :size="14" :stroke="1.75" /><span class="reader-ctl-text">Focus</span>
                </button>
            </div>
        </div>
        <div id="readProgressBar" class="reader-rail" aria-hidden="true"><div id="readProgressFill"></div></div>

        {{-- "Aa": every typography preference. Desktop = popover with
             Text / Layout / Playback tabs; phones (<768px) = bottom sheet. --}}
        <div id="readerSettings" class="reader-pop d-none" tabindex="-1" role="dialog" aria-labelledby="readerSettingsTitle" aria-modal="true">
            <div class="reader-sheet-grip" aria-hidden="true"></div>
            <div class="reader-pop-head">
                <span class="reader-pop-title" id="readerSettingsTitle">Reading settings</span>
                <button type="button" class="reader-pop-close" data-close-pop aria-label="Close reading settings"><x-icon name="x" :size="16" /></button>
            </div>
            <div class="reader-tabs" role="tablist" aria-label="Settings group">
                <button type="button" role="tab" id="rsTab-text" data-tab="text" aria-controls="rsPane-text" aria-selected="true">Text</button>
                <button type="button" role="tab" id="rsTab-layout" data-tab="layout" aria-controls="rsPane-layout" aria-selected="false" tabindex="-1">Layout</button>
                <button type="button" role="tab" id="rsTab-playback" data-tab="playback" aria-controls="rsPane-playback" aria-selected="false" tabindex="-1">Playback</button>
            </div>

            <div class="reader-pane" id="rsPane-text" role="tabpanel" aria-labelledby="rsTab-text" data-pane="text">
                <div class="reader-pop-block">
                    <span class="reader-pop-label">Theme</span>
                    <div class="reader-swatches" id="themeGroup" role="group" aria-label="Reading theme">
                        <button type="button" data-theme="dark"><span class="reader-swatch swatch-dark" aria-hidden="true">Aa</span>Dark</button>
                        <button type="button" data-theme="sepia"><span class="reader-swatch swatch-sepia" aria-hidden="true">Aa</span>Sepia</button>
                        <button type="button" data-theme="light"><span class="reader-swatch swatch-light" aria-hidden="true">Aa</span>Light</button>
                    </div>
                </div>
                <div class="reader-pop-block">
                    <span class="reader-pop-label">Text size</span>
                    <div class="reader-seg reader-seg-fill" role="group" aria-label="Text size">
                        <button type="button" data-font="-" aria-label="Smaller text"><span class="seg-a-small">A</span></button>
                        <span class="reader-seg-value" id="fontSizeLabel" aria-live="polite">19px</span>
                        <button type="button" data-font="+" aria-label="Larger text"><span class="seg-a-large">A</span></button>
                    </div>
                </div>
                <div class="reader-pop-block">
                    <span class="reader-pop-label">Typeface</span>
                    <div class="reader-faces" id="familyGroup" role="group" aria-label="Font family">
                        <button type="button" data-family="read"><span class="face-sample face-read" aria-hidden="true">Aa</span>Literata</button>
                        <button type="button" data-family="sans"><span class="face-sample face-sans" aria-hidden="true">Aa</span>Sans</button>
                        <button type="button" data-family="serif"><span class="face-sample face-serif" aria-hidden="true">Aa</span>Georgia</button>
                        <button type="button" data-family="legible" title="Atkinson Hyperlegible — a high-legibility font"><span class="face-sample face-legible" aria-hidden="true">Aa</span>Legible</button>
                    </div>
                </div>
            </div>

            <div class="reader-pane" id="rsPane-layout" role="tabpanel" aria-labelledby="rsTab-layout" data-pane="layout" hidden>
                <div class="reader-pop-row">
                    <span class="reader-pop-label">Measure</span>
                    <div class="reader-seg" role="group" aria-label="Line measure">
                        <button type="button" data-measure="-" aria-label="Narrower column">&minus;</button>
                        <span class="reader-seg-value" id="measureLabel" aria-live="polite">68ch</span>
                        <button type="button" data-measure="+" aria-label="Wider column">+</button>
                    </div>
                </div>
                <div class="reader-pop-row">
                    <span class="reader-pop-label">Spacing</span>
                    <div class="reader-seg" id="lineHeightGroup" role="group" aria-label="Line spacing">
                        <button type="button" data-lineheight="1.5">Compact</button>
                        <button type="button" data-lineheight="1.75">Normal</button>
                        <button type="button" data-lineheight="2.1">Relaxed</button>
                    </div>
                </div>
                <div class="reader-pop-row">
                    <span class="reader-pop-label">Gutter</span>
                    <div class="reader-seg" id="marginGroup" role="group" aria-label="Side margins">
                        <button type="button" data-margin="s">S</button>
                        <button type="button" data-margin="m">M</button>
                        <button type="button" data-margin="l">L</button>
                    </div>
                </div>
                <div class="reader-pop-row">
                    <span class="reader-pop-label">Justify</span>
                    <div class="reader-seg" id="justifyGroup" role="group" aria-label="Justified text">
                        <button type="button" data-justify="1">On</button>
                        <button type="button" data-justify="0">Off</button>
                    </div>
                </div>
            </div>

            <div class="reader-pane" id="rsPane-playback" role="tabpanel" aria-labelledby="rsTab-playback" data-pane="playback" hidden>
                <div class="reader-pop-row">
                    <span class="reader-pop-label">Continuous</span>
                    <div class="reader-seg" id="autoNextGroup" role="group" aria-label="Auto-load next chapter while scrolling">
                        <button type="button" data-autonext="1" title="Load the next chapter inline when you reach the end">On</button>
                        <button type="button" data-autonext="0">Off</button>
                    </div>
                </div>
                <div class="reader-pop-row">
                    <span class="reader-pop-label">Voice speed</span>
                    <div class="reader-seg" id="ttsRateGroup" role="group" aria-label="Speech rate">
                        <button type="button" data-ttsrate="0.8">0.8&times;</button>
                        <button type="button" data-ttsrate="1">1&times;</button>
                        <button type="button" data-ttsrate="1.25">1.25&times;</button>
                        <button type="button" data-ttsrate="1.5">1.5&times;</button>
                    </div>
                </div>
                <p class="reader-pop-hint">Listen and auto-scroll start from the <x-icon name="headphones" :size="12" /> Playback control in the bar.</p>
            </div>

            <div class="reader-pop-foot">
                <label class="reader-pop-label" for="perNovelPrefs" title="Keep a separate typography setup for this novel">This novel only</label>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="perNovelPrefs" aria-label="Use separate reader settings for this novel">
                </div>
            </div>
            <button type="button" class="reader-keys-link" data-open-keys aria-haspopup="dialog" aria-controls="readerKeys">
                Keyboard shortcuts <kbd>?</kbd>
            </button>
        </div>
    </div>

    {{-- Phones: dims the page behind the Aa bottom sheet; tap to close. --}}
    <div id="readerBackdrop" class="reader-backdrop d-none" aria-hidden="true"></div>

    {{-- Playback: its own control, docked to the bottom of the viewport so it
         stays reachable while the chrome auto-hides during auto-scroll. --}}
    <div id="readerPlayback" class="reader-playbar d-none" role="dialog" aria-modal="false" aria-labelledby="readerPlaybackTitle" tabindex="-1">
        <h2 class="visually-hidden" id="readerPlaybackTitle">Playback</h2>
        <div class="reader-playbar-group" id="ttsBar">
            <span class="reader-playbar-label"><x-icon name="headphones" :size="14" />Listen</span>
            <button type="button" id="ttsPlayPause" class="reader-playbtn" aria-label="Play read-aloud">
                <x-icon name="play" :size="14" class="icon pb-play" /><x-icon name="pause" :size="14" class="icon pb-pause d-none" />
                <span class="pb-text">Play</span>
            </button>
            <button type="button" id="ttsStop" class="reader-playbtn" aria-label="Stop read-aloud"><x-icon name="square" :size="13" /></button>
            <span class="reader-playbar-note" id="ttsStatus"></span>
        </div>
        <div class="reader-playbar-group">
            <span class="reader-playbar-label"><x-icon name="chevrons-down" :size="14" />Auto-scroll</span>
            <button type="button" id="autoScrollToggle" class="reader-playbtn" aria-pressed="false">
                <x-icon name="play" :size="14" class="icon pb-play" /><x-icon name="pause" :size="14" class="icon pb-pause d-none" />
                <span class="pb-text">Start</span>
            </button>
            <div class="reader-seg" role="group" aria-label="Scroll speed">
                <button type="button" data-scrollspeed="-" title="Scroll slower" aria-label="Scroll slower">&minus;</button>
                <span class="reader-seg-value" id="autoScrollSpeedLabel"></span>
                <button type="button" data-scrollspeed="+" title="Scroll faster" aria-label="Scroll faster">+</button>
            </div>
        </div>
        <button type="button" class="reader-pop-close reader-playbar-close" data-close-playback aria-label="Close playback"><x-icon name="x" :size="16" /></button>
    </div>

    {{-- 680px column: kicker, chapter title, hairline rule, prose. --}}
    <div class="reader-col">
        <div id="readerSections">
            <section class="reader-section" data-id="{{ $chapter->id }}" data-words="{{ $wordCount }}">
                <header class="reader-head">
                    <p class="reader-kicker">
                        @if($chapter->isNote())
                            <x-status state="attention" title="This chapter is a message from the author or translator">Author's note</x-status>
                        @endif
                        <span>Chapter {{ $chapterIndex }} of {{ $chapterTotal }}</span>
                        @if($chapter->book)
                            <span class="reader-kicker-sep">&middot;</span><span>Book {{ $chapter->book }}</span>
                        @endif
                        @unless($chapter->status)
                            <span class="reader-kicker-sep">&middot;</span><span class="reader-kicker-pending">Pending</span>
                        @endunless
                        <span class="reader-kicker-sep">&middot;</span><span data-mins>&mdash;</span>
                    </p>
                    <h1 class="reader-title">{{ $chapterTitle }}</h1>
                    <div class="reader-title-rule" aria-hidden="true"></div>
                </header>

                @if($chapter->rawText())
                    <div class="chapter-content" id="chapterContent">
                        {!! $chapter->description !!}
                    </div>
                @else
                    <div class="reader-empty">
                        <p>No content available for this chapter yet.</p>
                        <button type="button" id="downloadChapterBtn" class="btn btn-primary" data-id="{{ $chapter->id }}">
                            <span class="dl-label">Download this chapter now</span>
                            <span class="dl-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Downloading&hellip;</span>
                        </button>
                        <div class="reader-empty-note">Fetches just this chapter from the source in the background.</div>
                    </div>
                @endif
            </section>
        </div>

        <div id="readerSentinel" aria-hidden="true"></div>

        <div class="reader-foot">
            <div class="reader-foot-actions">
                <button type="button" id="readThrough" class="reader-ghost" data-id="{{ $chapter->id }}" title="Mark this and all earlier chapters as read">Mark to here</button>
                <button type="button" id="readToggle" class="reader-ghost {{ $chapter->read_at ? 'is-read' : '' }}" data-id="{{ $chapter->id }}" data-read="{{ $chapter->read_at ? '1' : '0' }}" aria-pressed="{{ $chapter->read_at ? 'true' : 'false' }}">
                    {{ $chapter->read_at ? 'Read' : 'Mark read' }}
                </button>
            </div>

            <nav class="chapter-nav reader-nav" aria-label="Chapter navigation">
                @if($prev)
                    <a href="{{ route('chapters.show', $prev->id) }}" id="navPrev" class="reader-navblock">
                        <span class="reader-navblock-dir">&larr; Previous</span>
                        <span class="reader-navblock-label">{{ $navLabel($prev) }}</span>
                    </a>
                @else
                    <span class="reader-navblock is-empty" aria-hidden="true"></span>
                @endif

                @if($next)
                    <a href="{{ route('chapters.show', $next->id) }}" id="nextChapterCta" class="reader-continue">Mark read &amp; continue</a>
                @else
                    <span id="endOfNovelNote" class="reader-caughtup">You're all caught up &mdash; no next chapter yet.</span>
                @endif

                @if($next)
                    <a href="{{ route('chapters.show', $next->id) }}" id="navNext" class="reader-navblock reader-navblock-next">
                        <span class="reader-navblock-dir">Next &rarr;</span>
                        <span class="reader-navblock-label">{{ $navLabel($next) }}</span>
                    </a>
                @else
                    <span class="reader-navblock is-empty" aria-hidden="true"></span>
                @endif
            </nav>
        </div>
    </div>
</div>

{{-- Floating save-highlight popover (shown over a text selection) --}}
<div id="highlightPop" class="card p-2 d-none" style="position: absolute; z-index: 1055;" role="dialog" aria-modal="false" aria-label="Save highlight">
    <div class="d-flex gap-2">
        <input type="text" id="hlNote" class="form-control form-control-sm" placeholder="Note (optional)" style="width: 170px;" aria-label="Highlight note">
        <button type="button" id="hlSave" class="btn btn-sm btn-primary text-nowrap">Save</button>
        <button type="button" id="hlDefine" class="btn btn-sm btn-outline-secondary d-none" title="Look up this word" aria-label="Define word">Define</button>
    </div>
    <div id="hlDefinition" class="d-none mt-2 text-body" style="max-width: 320px; max-height: 200px; overflow-y: auto; font-size: 12px;"></div>
</div>

{{-- In-reader chapter list --}}
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

{{-- "?" — keyboard shortcuts. Lists only what resources/js/reader binds. --}}
<div id="readerKeys" class="reader-keys d-none" role="dialog" aria-modal="true" aria-labelledby="readerKeysTitle" tabindex="-1">
    <div class="reader-keys-head">
        <h2 class="reader-keys-title" id="readerKeysTitle">Keyboard shortcuts</h2>
        <button type="button" class="reader-pop-close" data-close-keys aria-label="Close keyboard shortcuts"><x-icon name="x" :size="16" /></button>
    </div>
    <dl class="reader-keys-list">
        <div><dt><kbd>&larr;</kbd> <kbd>k</kbd></dt><dd>Previous chapter</dd></div>
        <div><dt><kbd>&rarr;</kbd> <kbd>j</kbd></dt><dd>Next chapter</dd></div>
        <div><dt><kbd>t</kbd></dt><dd>Chapter list</dd></div>
        <div><dt><kbd>a</kbd></dt><dd>Reading settings</dd></div>
        <div><dt><kbd>p</kbd></dt><dd>Playback bar</dd></div>
        <div><dt><kbd>f</kbd></dt><dd>Focus mode on / off</dd></div>
        <div><dt><kbd>Esc</kbd></dt><dd>Close settings, playback, chapter list or this panel; leave focus mode</dd></div>
        <div><dt><kbd>&larr;</kbd> <kbd>&rarr;</kbd></dt><dd>Switch tabs inside Reading settings</dd></div>
        <div><dt><kbd>?</kbd></dt><dd>Show this list</dd></div>
    </dl>
    <p class="reader-keys-note">On touch screens, swipe left or right to change chapter; in focus mode, tap the page to show the controls.</p>
</div>

{{-- Server-rendered Lucide icons the script clones (keeps one icon source). --}}
<template id="readerIconCheck"><x-icon name="check" :size="13" :stroke="2.25" class="icon toc-check" /></template>

{{-- Continuous reading: announces the chapter that scrolled into view. --}}
<div id="readerAnnounce" class="visually-hidden" aria-live="polite" aria-atomic="true"></div>

<script type="application/json" id="readerState">@json($readerState)</script>
@endsection

@push('preload')
@php try { $literataPreload = Vite::asset('node_modules/@fontsource-variable/literata/files/literata-latin-wght-normal.woff2'); } catch (\Throwable $e) { $literataPreload = null; } @endphp
@if($literataPreload)<link rel="preload" as="font" type="font/woff2" crossorigin href="{{ $literataPreload }}">@endif
@endpush

@push('scripts')
@vite('resources/js/reader/index.js')
@endpush
