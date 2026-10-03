@extends('layouts.app')

@push('styles')
{{-- Preload the adjacent chapter bodies (handoff §"Interactions": prev/next preload) --}}
@if($next)
    <link rel="prefetch" href="{{ route('chapters.show', $next->id) }}">
@endif
@if($prev)
    <link rel="prefetch" href="{{ route('chapters.show', $prev->id) }}">
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
    // in the script below).
    $navLabel = function ($c) {
        $label = $c->label ?: 'Chapter ' . $c->chapter;
        $num = is_numeric($c->chapter) ? (string) (0 + $c->chapter) : (string) $c->chapter;
        $leads = preg_match('/^\s*(?:ch(?:apter)?\.?\s*)?#?' . preg_quote($num, '/') . '(?![\d.])/i', $label);

        return Str::limit($leads ? $label : 'Ch. ' . $num . ' · ' . $label, 40);
    };
@endphp

@section('title', $chapterTitle . ' · ' . ($chapter->novel->name ?? 'Reader'))

{{-- Reader is chromeless: no global navbar (layouts/app.blade.php) --}}
@section('chromeless', '1')

@section('content')
<div class="reader" id="reader" data-reader-page>

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
<div id="highlightPop" class="card p-2 d-none" style="position: absolute; z-index: 1055;">
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

{{-- "?" — keyboard shortcuts. Lists only what the script below binds. --}}
<div id="readerKeys" class="reader-keys d-none" role="dialog" aria-modal="true" aria-labelledby="readerKeysTitle" tabindex="-1">
    <div class="reader-keys-head">
        <h2 class="reader-keys-title" id="readerKeysTitle">Keyboard shortcuts</h2>
        <button type="button" class="reader-pop-close" data-close-keys aria-label="Close keyboard shortcuts"><x-icon name="x" :size="16" /></button>
    </div>
    <dl class="reader-keys-list">
        <div><dt><kbd>&larr;</kbd></dt><dd>Previous chapter</dd></div>
        <div><dt><kbd>&rarr;</kbd></dt><dd>Next chapter</dd></div>
        <div><dt><kbd>Esc</kbd></dt><dd>Close settings, playback, chapter list or this panel</dd></div>
        <div><dt><kbd>&larr;</kbd> <kbd>&rarr;</kbd></dt><dd>Switch tabs inside Reading settings</dd></div>
        <div><dt><kbd>?</kbd></dt><dd>Show this list</dd></div>
    </dl>
    <p class="reader-keys-note">On touch screens, swipe left or right to change chapter; in focus mode, tap the page to show the controls.</p>
</div>

{{-- Server-rendered Lucide icons the script clones (keeps one icon source). --}}
<template id="readerIconCheck"><x-icon name="check" :size="13" :stroke="2.25" class="icon toc-check" /></template>

<script type="application/json" id="readerState">@json($readerState)</script>
@endsection

@push('preload')
@php try { $literataPreload = Vite::asset('node_modules/@fontsource-variable/literata/files/literata-latin-wght-normal.woff2'); } catch (\Throwable $e) { $literataPreload = null; } @endphp
@if($literataPreload)<link rel="preload" as="font" type="font/woff2" crossorigin href="{{ $literataPreload }}">@endif
@endpush

@push('scripts')
<script>
(() => {
    const state = JSON.parse(document.getElementById('readerState').textContent);
    const readerEl = document.getElementById('reader');

    // ---- Per-visit lifecycle ----
    // This script re-runs on every Turbo visit, but listeners on document /
    // window outlive the page. One AbortController per visit: every such
    // listener passes { signal }, and teardown() (turbo:before-cache /
    // turbo:before-render, at the bottom) aborts them all, so handlers never
    // pile up across visits (duplicate arrow-key/swipe navigations, stale
    // progress reports for a chapter we've already left).
    const pageAbort = new AbortController();
    const signal = pageAbort.signal;

    // ---- Dialog plumbing: focus trap + inert background ----
    // Prefers the shell's window.Novarr.focusTrap/releaseFocusTrap (looked up
    // at open time: app.js is a module and may load after this script); falls
    // back to a small Tab-cycling trap so the reader works on its own.
    const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    function trapFocus(el) {
        const N = window.Novarr;
        if (typeof N?.focusTrap === 'function') {
            N.focusTrap(el);
            return () => { if (typeof N.releaseFocusTrap === 'function') N.releaseFocusTrap(el); };
        }
        const onKey = (e) => {
            if (e.key !== 'Tab') return;
            const items = [...el.querySelectorAll(FOCUSABLE)].filter(n => n.offsetParent !== null || n === document.activeElement);
            if (!items.length) { e.preventDefault(); el.focus(); return; }
            const first = items[0], last = items[items.length - 1];
            const at = document.activeElement;
            if (e.shiftKey && (at === first || at === el || !el.contains(at))) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && (at === last || !el.contains(at))) { e.preventDefault(); first.focus(); }
        };
        document.addEventListener('keydown', onKey, true);
        return () => document.removeEventListener('keydown', onKey, true);
    }

    // Everything a modal sheet must hide from AT / pointer / Tab while open.
    // Regions that contain the dialog itself are skipped automatically.
    const READER_REGIONS = ['#readerToolbar', '.reader-col', '#readerPlayback', '#highlightPop'];
    const holds = new Set();
    function holdDialog(el, trigger, regions = READER_REGIONS) {
        const inerted = regions.map(sel => document.querySelector(sel))
            .filter(n => n && !n.contains(el) && !n.inert);
        inerted.forEach(n => { n.inert = true; });
        const hold = { el, trigger, inerted, release: trapFocus(el) };
        holds.add(hold);
        return hold;
    }
    // Returns null so callers can write `x = releaseDialog(x)`.
    function releaseDialog(hold, restoreFocus = true) {
        if (!hold || !holds.has(hold)) return null;
        holds.delete(hold);
        hold.inerted.forEach(n => { n.inert = false; });
        hold.release();
        // Back to the control that opened it — unless the user has already
        // moved focus somewhere deliberate outside the dialog.
        const at = document.activeElement;
        if (restoreFocus && hold.trigger?.isConnected && (!at || at === document.body || hold.el.contains(at))) {
            hold.trigger.focus({ preventScroll: true });
        }
        return null;
    }
    const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ---- Reader preferences (persisted in localStorage) ----
    // Typography keys can be overridden per novel ("This novel only"): the
    // override lives as a JSON snapshot under reader_novel_{id} and wins over
    // the global keys while it exists. Behavioural prefs (autoNext) stay global.
    const PREF_KEYS = {
        font: 'reader_font', measure: 'reader_measure', theme: 'reader_theme',
        family: 'reader_family', lineHeight: 'reader_lineheight',
        margin: 'reader_margin', justify: 'reader_justify',
    };
    const PREF_DEFAULTS = {
        font: '19', measure: '68', theme: 'dark', family: 'read',
        lineHeight: '1.75', margin: 'm', justify: '0',
    };
    // Handoff §"State Management": fontSize 15–24px, measure 56–80ch.
    const FONT_MIN = 15, FONT_MAX = 24;
    const MEASURE_MIN = 56, MEASURE_MAX = 80, MEASURE_STEP = 2;
    // Legacy width buckets → a measure in ch, so nobody's saved column width is lost.
    const LEGACY_WIDTH = { narrow: '56', medium: '68', wide: '76', full: '80' };

    const perNovelKey = 'reader_novel_' + state.novelId;
    let perNovel = null;
    try { perNovel = JSON.parse(localStorage.getItem(perNovelKey) || 'null'); } catch (e) { perNovel = null; }

    const prefs = { autoNext: localStorage.getItem('reader_autonext') || '1' };

    function clampNum(v, min, max, fallback) {
        const n = parseFloat(v);
        return isNaN(n) ? fallback : Math.min(max, Math.max(min, n));
    }

    function loadPrefs() {
        for (const [k, sk] of Object.entries(PREF_KEYS)) {
            prefs[k] = localStorage.getItem(sk) ?? PREF_DEFAULTS[k];
        }
        // Migrations from the pre-redesign reader.
        if (localStorage.getItem('reader_measure') === null) {
            const legacy = LEGACY_WIDTH[localStorage.getItem('reader_width')];
            if (legacy) prefs.measure = legacy;
        }
        if (perNovel) Object.assign(prefs, perNovel);
        if (prefs.lineHeight === '1.8') prefs.lineHeight = '1.75';   // old "Normal"
        prefs.font = clampNum(prefs.font, FONT_MIN, FONT_MAX, 19);
        prefs.measure = Math.round(clampNum(prefs.measure, MEASURE_MIN, MEASURE_MAX, 68) / MEASURE_STEP) * MEASURE_STEP;
    }
    loadPrefs();

    function persistPref(k, v) {
        // font/measure stay numeric in memory (they're stepped); storage is strings.
        prefs[k] = (k === 'font' || k === 'measure') ? parseInt(v, 10) : String(v);
        const stored = String(v);
        if (k === 'autoNext') { localStorage.setItem('reader_autonext', stored); return; }
        if (perNovel) {
            perNovel[k] = stored;
            localStorage.setItem(perNovelKey, JSON.stringify(perNovel));
        } else {
            localStorage.setItem(PREF_KEYS[k], stored);
        }
    }

    const families = {
        read: "var(--rd-font-read)",
        sans: "var(--bs-body-font-family)",
        serif: "Georgia, 'Times New Roman', serif",
        legible: "'Atkinson Hyperlegible', var(--bs-body-font-family)",
    };
    const margins = { s: '0px', m: '12px', l: '28px' };

    function applyPrefs() {
        // Typography is driven by custom properties on the reader root; the
        // stylesheet owns the rest, so nothing needs restyling per section.
        readerEl.style.setProperty('--rd-size', prefs.font + 'px');
        readerEl.style.setProperty('--rd-measure', prefs.measure + 'ch');
        readerEl.style.setProperty('--rd-family', families[prefs.family] || families.read);
        readerEl.style.setProperty('--rd-line-h', prefs.lineHeight);
        readerEl.style.setProperty('--rd-gutter', margins[prefs.margin] ?? margins.m);
        readerEl.style.setProperty('--rd-align', prefs.justify === '1' ? 'justify' : 'start');
        readerEl.style.setProperty('--rd-hyphens', prefs.justify === '1' ? 'auto' : 'manual');

        // Theme recolours the whole page via a body class, so focus mode and
        // the page gutters match the reading surface.
        document.body.classList.remove('reader-theme-sepia', 'reader-theme-light');
        if (prefs.theme === 'sepia' || prefs.theme === 'light') {
            document.body.classList.add('reader-theme-' + prefs.theme);
        }
        document.body.setAttribute('data-bs-theme', prefs.theme === 'dark' ? 'dark' : 'light');

        document.getElementById('fontSizeLabel').textContent = prefs.font + 'px';
        document.getElementById('measureLabel').textContent = prefs.measure + 'ch';

        // reflect active buttons + announce state to assistive tech
        const reflect = (sel, key, val) => document.querySelectorAll(sel).forEach(b => {
            const on = b.dataset[key] === val;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        reflect('#themeGroup [data-theme]', 'theme', prefs.theme);
        reflect('#familyGroup [data-family]', 'family', prefs.family);
        reflect('#lineHeightGroup [data-lineheight]', 'lineheight', prefs.lineHeight);
        reflect('#autoNextGroup [data-autonext]', 'autonext', prefs.autoNext);
        reflect('#marginGroup [data-margin]', 'margin', prefs.margin);
        reflect('#justifyGroup [data-justify]', 'justify', prefs.justify);
        document.getElementById('perNovelPrefs').checked = !!perNovel;
    }

    // ---- "Aa" popover (desktop) / bottom sheet (phones, <768px) ----
    // Same element either way; CSS decides the presentation. The backdrop
    // only renders on phones, where it dims the page and closes on tap.
    const settingsBtn = document.getElementById('readerSettingsBtn');
    const settingsPop = document.getElementById('readerSettings');
    const backdrop = document.getElementById('readerBackdrop');
    let settingsHold = null;
    function toggleSettings(show) {
        const open = show ?? settingsPop.classList.contains('d-none');
        if (open === !settingsPop.classList.contains('d-none')) return;
        if (!open) settingsHold = releaseDialog(settingsHold);
        settingsPop.classList.toggle('d-none', !open);
        backdrop.classList.toggle('d-none', !open);
        document.body.classList.toggle('reader-sheet-open', open);
        settingsBtn.classList.toggle('is-active', open);
        settingsBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            // The sheet lives inside the chrome; a translated (auto-hidden)
            // chrome would become the containing block of the fixed sheet.
            setChromeHidden(false);
            settingsHold = holdDialog(settingsPop, settingsBtn);
            // Move focus into the dialog (the container, so no ring flashes on a tab).
            settingsPop.focus({ preventScroll: true });
        }
    }
    settingsBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleSettings(); }, { signal });
    settingsPop.addEventListener('click', (e) => e.stopPropagation(), { signal });
    settingsPop.querySelector('[data-close-pop]').addEventListener('click', () => { toggleSettings(false); settingsBtn.focus(); }, { signal });
    backdrop.addEventListener('click', () => toggleSettings(false), { signal });
    document.addEventListener('click', () => toggleSettings(false), { signal });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !settingsPop.classList.contains('d-none')) {
            e.preventDefault();
            toggleSettings(false);
            settingsBtn.focus();
        }
    }, { signal });

    // Tabs: Text / Layout / Playback. Arrow keys move between tabs (WAI-ARIA
    // tabs pattern); the last-used tab is remembered per device.
    const TAB_KEY = 'reader_settings_tab';
    const tabs = [...settingsPop.querySelectorAll('[role="tab"]')];
    function selectTab(name, focus = false) {
        if (!tabs.some(t => t.dataset.tab === name)) name = 'text';
        tabs.forEach(t => {
            const on = t.dataset.tab === name;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            t.classList.toggle('is-active', on);
            if (on && focus) t.focus();
        });
        settingsPop.querySelectorAll('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== name; });
        try { localStorage.setItem(TAB_KEY, name); } catch (e) { /* storage blocked */ }
    }
    tabs.forEach((t, i) => {
        t.addEventListener('click', () => selectTab(t.dataset.tab), { signal });
        t.addEventListener('keydown', (e) => {
            const d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
            if (!d) return;
            e.preventDefault();
            selectTab(tabs[(i + d + tabs.length) % tabs.length].dataset.tab, true);
        }, { signal });
    });
    let savedTab = 'text';
    try { savedTab = localStorage.getItem(TAB_KEY) || 'text'; } catch (e) { /* storage blocked */ }
    selectTab(savedTab);

    // ---- Playback bar (Listen + Auto-scroll), docked at the bottom ----
    const playbackBtn = document.getElementById('playbackBtn');
    const playbackBar = document.getElementById('readerPlayback');
    // Deliberately non-modal (aria-modal="false", no trap, no inert): it is
    // meant to stay docked while you read, scroll, select text and follow the
    // Next link during auto-scroll / read-aloud. Focus still moves into it on
    // open and Esc / Close return focus to the Playback button.
    function togglePlayback(show) {
        const open = show ?? playbackBar.classList.contains('d-none');
        playbackBar.classList.toggle('d-none', !open);
        document.body.classList.toggle('reader-playbar-open', open);
        playbackBtn.classList.toggle('is-active', open);
        playbackBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) playbackBar.querySelector('button')?.focus({ preventScroll: true });
    }
    function closePlayback() {
        stopAutoScroll();
        ttsStopAll();
        const hadFocus = playbackBar.contains(document.activeElement);
        togglePlayback(false);
        if (hadFocus || document.activeElement === document.body) playbackBtn.focus({ preventScroll: true });
    }
    playbackBtn.addEventListener('click', () => { toggleSettings(false); togglePlayback(); }, { signal });
    playbackBar.querySelector('[data-close-playback]').addEventListener('click', closePlayback, { signal });
    // Esc closes it from anywhere — unless a modal (sheet, drawer, "?") is on
    // top, in which case that modal takes the Esc.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || e.defaultPrevented || holds.size) return;
        if (playbackBar.classList.contains('d-none') || document.querySelector('.offcanvas.show, .modal.show')) return;
        closePlayback();
    }, { signal });

    // ---- "?" keyboard-shortcuts overlay (modal) ----
    const keysPanel = document.getElementById('readerKeys');
    let keysHold = null;
    function toggleKeys(show, trigger = document.activeElement) {
        const open = show ?? keysPanel.classList.contains('d-none');
        if (open === !keysPanel.classList.contains('d-none')) return;
        if (open) {
            toggleSettings(false);
            keysPanel.classList.remove('d-none');
            document.body.classList.add('reader-keys-open');
            // Back to the opener if it's a real control, else the Aa button.
            const back = trigger && trigger !== document.body && !keysPanel.contains(trigger) && !settingsPop.contains(trigger) ? trigger : settingsBtn;
            keysHold = holdDialog(keysPanel, back, ['#reader']);
            keysPanel.focus({ preventScroll: true });
        } else {
            keysHold = releaseDialog(keysHold);
            keysPanel.classList.add('d-none');
            document.body.classList.remove('reader-keys-open');
        }
    }
    settingsPop.querySelector('[data-open-keys]').addEventListener('click', () => toggleKeys(true, settingsBtn), { signal });
    keysPanel.querySelector('[data-close-keys]').addEventListener('click', () => toggleKeys(false), { signal });
    // A click outside the panel (it lands on the inert page → body) dismisses.
    document.addEventListener('click', (e) => {
        if (!keysPanel.classList.contains('d-none') && !keysPanel.contains(e.target)) toggleKeys(false);
    }, { signal });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !keysPanel.classList.contains('d-none')) { e.preventDefault(); toggleKeys(false); return; }
        if (e.key !== '?' || e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.target.matches('input, textarea, select, [contenteditable]')) return;
        if (document.querySelector('.offcanvas.show, .modal.show')) return;
        e.preventDefault();
        toggleKeys();
    }, { signal });

    // Label + play/pause glyph on a playback button (icons are server-rendered).
    function setPlayBtn(btn, label, playing) {
        btn.querySelector('.pb-text').textContent = label;
        btn.querySelector('.pb-play')?.classList.toggle('d-none', playing);
        btn.querySelector('.pb-pause')?.classList.toggle('d-none', !playing);
        btn.classList.toggle('is-active', playing);
    }

    // "Ch. 12 · Chapter 12 – Title" repeats the number: only prefix it when
    // the label doesn't already lead with it (mirrors $navLabel in PHP).
    function chLabel(c, max = 40) {
        const label = c.label || 'Chapter ' + c.chapter;
        const num = String(isNaN(parseFloat(c.chapter)) ? c.chapter : parseFloat(c.chapter));
        const esc = num.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const leads = new RegExp('^\\s*(?:ch(?:apter)?\\.?\\s*)?#?' + esc + '(?![\\d.])', 'i').test(label);
        const text = leads ? label : 'Ch. ' + num + ' · ' + label;
        return text.length > max ? text.substring(0, max - 1) + '…' : text;
    }

    const bindPref = (attr, apply) => settingsPop.querySelectorAll(`[data-${attr}]`).forEach(btn =>
        btn.addEventListener('click', () => { apply(btn.dataset[attr]); applyPrefs(); }, { signal }));

    bindPref('font', v => persistPref('font', clampNum(prefs.font + (v === '+' ? 1 : -1), FONT_MIN, FONT_MAX, 19)));
    bindPref('measure', v => persistPref('measure', clampNum(prefs.measure + (v === '+' ? MEASURE_STEP : -MEASURE_STEP), MEASURE_MIN, MEASURE_MAX, 68)));
    bindPref('theme', v => persistPref('theme', v));
    bindPref('family', v => persistPref('family', v));
    bindPref('lineheight', v => persistPref('lineHeight', v));
    bindPref('autonext', v => persistPref('autoNext', v));
    bindPref('margin', v => persistPref('margin', v));
    bindPref('justify', v => persistPref('justify', v));

    document.getElementById('perNovelPrefs').addEventListener('change', (e) => {
        if (e.target.checked) {
            // Snapshot the current typography as this novel's own setup.
            perNovel = {};
            for (const k of Object.keys(PREF_KEYS)) perNovel[k] = String(prefs[k]);
            localStorage.setItem(perNovelKey, JSON.stringify(perNovel));
            window.Novarr?.showToast('Reader settings for this novel are now independent.', 'info');
        } else {
            localStorage.removeItem(perNovelKey);
            perNovel = null;
            loadPrefs();
            window.Novarr?.showToast('Back to your global reader settings.', 'info');
        }
        applyPrefs();
    });

    // ---- Sections: the initial chapter + any continuously-loaded ones ----
    // Each entry mirrors the per-page readerState so navigation and progress
    // always refer to the chapter actually under the viewport.
    const sectionsEl = document.getElementById('readerSections');
    const firstSectionEl = sectionsEl.querySelector('.reader-section');
    const sections = [{
        ...state,
        el: firstSectionEl,
        words: parseInt(firstSectionEl.dataset.words || '0', 10) || 0,
    }];
    let currentIdx = 0;

    const sectionTop = s => s.el.getBoundingClientRect().top + window.scrollY;
    const sectionHeight = s => s.el.offsetHeight || 1;

    function findCurrentIdx() {
        const ref = window.scrollY + window.innerHeight * 0.4;
        let idx = 0;
        for (let i = 0; i < sections.length; i++) {
            if (sectionTop(sections[i]) <= ref) idx = i;
        }
        return idx;
    }

    function onSectionChange(idx) {
        currentIdx = idx;
        const cur = sections[idx];
        // Keep the address bar and the footer nav in sync with the chapter
        // being read, so reloads/bookmarks land on the right one.
        history.replaceState(history.state, '', cur.url);
        setNavBlock(document.getElementById('navPrev'), cur.prev, '← Previous');
        setNavBlock(document.getElementById('navNext'), cur.next, 'Next →');
    }

    function setNavBlock(el, target, dir) {
        if (!el) return;
        if (!target) { el.classList.add('d-none'); return; }
        el.classList.remove('d-none');
        el.href = target.url;
        el.querySelector('.reader-navblock-dir').textContent = dir;
        el.querySelector('.reader-navblock-label').textContent = chLabel(target);
    }

    // ---- Keyboard + swipe navigation, relative to the current section ----
    function visit(url) {
        if (!url) return;
        if (window.Turbo) Turbo.visit(url);
        else window.location.href = url;
    }
    function goPrev() {
        const cur = sections[currentIdx];
        if (currentIdx > 0) {
            window.scrollTo({ top: sectionTop(sections[currentIdx - 1]), behavior: reducedMotion() ? 'auto' : 'smooth' });
        } else {
            visit(cur.prev?.url);
        }
    }
    function goNext() {
        const cur = sections[currentIdx];
        if (currentIdx < sections.length - 1) {
            window.scrollTo({ top: sectionTop(sections[currentIdx + 1]), behavior: reducedMotion() ? 'auto' : 'smooth' });
        } else {
            visit(cur.next?.url);
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.target.matches('input, textarea, select')) return;
        if (holds.size || document.querySelector('.offcanvas.show, .modal.show')) return;   // a modal is open
        if (e.key === 'ArrowLeft') goPrev();
        if (e.key === 'ArrowRight') goNext();
    }, { signal });

    // Horizontal swipe on touch devices: left = next, right = previous.
    // Ignored when it starts on a control, while text is selected, or when
    // it's mostly vertical (normal scrolling).
    let touchStart = null;
    document.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 1 || e.target.closest('a, button, input, select, textarea, .offcanvas, .reader-pop, .reader-playbar, .reader-backdrop')) {
            touchStart = null;
            return;
        }
        touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY, t: Date.now() };
    }, { passive: true, signal });
    document.addEventListener('touchend', (e) => {
        if (!touchStart) return;
        const dx = e.changedTouches[0].clientX - touchStart.x;
        const dy = e.changedTouches[0].clientY - touchStart.y;
        const dt = Date.now() - touchStart.t;
        touchStart = null;
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;
        if (dt > 600 || Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 2) return;
        if (dx < 0) goNext(); else goPrev();
    }, { passive: true, signal });

    // ---- Focus mode: hide chrome; tap the page to peek at it ----
    const focusBtn = document.getElementById('focusBtn');
    function applyFocus() {
        const on = localStorage.getItem('reader_focus') === '1';
        document.body.classList.toggle('reader-focus', on);
        document.body.classList.remove('chrome-peek');
        focusBtn.classList.toggle('is-active', on);
        focusBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    focusBtn.addEventListener('click', () => {
        const turningOn = localStorage.getItem('reader_focus') !== '1';
        localStorage.setItem('reader_focus', turningOn ? '1' : '0');
        applyFocus();
        if (turningOn && !localStorage.getItem('reader_focus_hint')) {
            localStorage.setItem('reader_focus_hint', '1');
            window.Novarr?.showToast('Focus mode on — tap the page to show the controls.', 'info');
        }
    });
    applyFocus();

    sectionsEl.addEventListener('click', (e) => {
        if (!document.body.classList.contains('reader-focus')) return;
        if (e.target.closest('a, button, input, select, textarea')) return;
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;
        document.body.classList.toggle('chrome-peek');
    });

    applyPrefs();

    // ---- Reading position: local restore + cross-device sync ----
    // Position is a percentage through the current chapter (device-independent),
    // stored locally for instant restore and synced to the server so another
    // device can resume in the same place.
    const posKey = id => 'reader_pos_' + id;
    const WPM = 230;   // honest-ish adult reading pace for web fiction

    function currentProgressPct() {
        const cur = sections[currentIdx];
        const pct = ((window.scrollY + window.innerHeight - sectionTop(cur)) / sectionHeight(cur)) * 100;
        return Math.max(0, Math.min(100, Math.round(pct)));
    }

    // Minutes left = words still below the fold at ~230 wpm. Words come from a
    // server-side count of the chapter body (data-words on the section).
    function updateMinutesLeft(pct) {
        const cur = sections[currentIdx];
        const el = cur.el.querySelector('[data-mins]');
        if (!el) return;
        const words = cur.words || 0;
        if (!words) { el.textContent = '—'; return; }
        const remaining = Math.max(0, words * (1 - pct / 100));
        el.textContent = remaining < WPM / 2 ? 'Finished' : Math.max(1, Math.round(remaining / WPM)) + ' min left';
    }

    let lastSynced = { id: null, pct: -1 };
    function syncProgress(id, pct, useBeacon = false) {
        // Only ever report for this visit's own chapters, and only while this
        // page is the one on screen — a stale handler or timer from a page
        // we've left must not send progress for the wrong chapter.
        if (signal.aborted || !readerEl.isConnected || !sections.some(s => s.id === id)) return;
        if (lastSynced.id === id && Math.abs(lastSynced.pct - pct) < 5 && pct < 98) return;
        lastSynced = { id, pct };
        if (useBeacon && navigator.sendBeacon) {
            const form = new FormData();
            form.append('progress', String(pct));
            navigator.sendBeacon(`/chapters/${id}/progress`, form);
            return;
        }
        window.Novarr?.queuedFetch?.(`/chapters/${id}/progress`, { method: 'POST', body: { progress: pct } }).catch(() => {});
    }

    const progressFill = document.getElementById('readProgressFill');
    let scrollSaveTimer = null;
    let syncTimer = null;
    function updateProgress() {
        const idx = findCurrentIdx();
        if (idx !== currentIdx) onSectionChange(idx);
        const cur = sections[currentIdx];
        const pct = currentProgressPct();

        progressFill.style.width = pct + '%';
        updateMinutesLeft(pct);

        clearTimeout(scrollSaveTimer);
        scrollSaveTimer = setTimeout(() => {
            if (pct >= 98) localStorage.removeItem(posKey(cur.id));
            else localStorage.setItem(posKey(cur.id), String(pct));
        }, 250);

        if (!syncTimer) {
            syncTimer = setTimeout(() => {
                syncTimer = null;
                syncProgress(sections[currentIdx].id, currentProgressPct());
            }, 10000);
        }
    }

    // rAF-throttled: at most one progress update per frame (handoff §Interactions).
    let rafPending = false;
    function onScroll() {
        if (rafPending) return;
        rafPending = true;
        requestAnimationFrame(() => { rafPending = false; updateProgress(); updateChromeAutoHide(); });
    }

    // ---- Reader bar auto-hide: slides away on scroll-down, returns on
    // scroll-up, near the top, or a tap in the middle of the page. The
    // progress rail (inside the chrome, under the bar) stays visible.
    // Never hides while the Aa popover or the contents drawer is open.
    const chromeEl = document.getElementById('readerChrome');
    const tocPanelEl = document.getElementById('tocPanel');
    let chromeLastY = window.scrollY;
    function setChromeHidden(hidden) {
        if (hidden && (!settingsPop.classList.contains('d-none') || tocPanelEl?.classList.contains('show'))) hidden = false;
        chromeEl.classList.toggle('is-autohidden', hidden);
    }
    function updateChromeAutoHide() {
        const y = window.scrollY;
        const dy = y - chromeLastY;
        if (y < 80) setChromeHidden(false);
        else if (dy > 6) setChromeHidden(true);
        else if (dy < -6) setChromeHidden(false);
        if (Math.abs(dy) > 6 || y < 80) chromeLastY = y;
    }
    // Centre tap toggles the bar (outside focus mode — focus mode has its own
    // tap-to-peek above). Taps on links/controls/selections are ignored.
    sectionsEl.addEventListener('click', (e) => {
        if (document.body.classList.contains('reader-focus')) return;
        if (e.target.closest('a, button, input, select, textarea')) return;
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;
        const band = window.innerHeight;
        if (e.clientY < band * 0.25 || e.clientY > band * 0.75) return;
        setChromeHidden(!chromeEl.classList.contains('is-autohidden'));
    }, { signal });
    window.addEventListener('scroll', onScroll, { passive: true, signal });
    window.addEventListener('resize', onScroll, { passive: true, signal });
    window.addEventListener('pagehide', () => syncProgress(sections[currentIdx].id, currentProgressPct(), true), { signal });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') syncProgress(sections[currentIdx].id, currentProgressPct(), true);
    }, { signal });

    // Restore: local position wins (freshest on this device), else the synced
    // server position from another device. Legacy pixel keys still honoured.
    (function restorePosition() {
        let pct = parseInt(localStorage.getItem(posKey(state.id)) || '', 10);
        if (isNaN(pct)) pct = state.progress;
        const legacyPx = parseInt(localStorage.getItem('reader_scroll_' + state.id) || '0', 10);
        localStorage.removeItem('reader_scroll_' + state.id);

        requestAnimationFrame(() => {
            if (pct !== null && !isNaN(pct) && pct > 2 && pct < 98) {
                const s = sections[0];
                window.scrollTo(0, Math.max(0, sectionTop(s) + (pct / 100) * sectionHeight(s) - window.innerHeight));
            } else if (legacyPx > 200) {
                window.scrollTo(0, legacyPx);
            }
            updateProgress();
        });
    })();

    // ---- Continuous reading: append the next chapter as you reach the end ----
    const MAX_APPENDED = 15;
    const cta = document.getElementById('nextChapterCta');
    const sentinel = document.getElementById('readerSentinel');
    let loadingNext = false;
    let autoLoadStopped = false;

    function updateCta(next) {
        if (!cta) return;
        cta.classList.toggle('d-none', !next);
        if (next) cta.href = next.url;
    }

    async function loadNextInline() {
        const last = sections[sections.length - 1];
        if (loadingNext || autoLoadStopped || !last.next || prefs.autoNext !== '1') return;
        if (sections.length > MAX_APPENDED) { autoLoadStopped = true; return; }

        loadingNext = true;
        try {
            // Tagged as a background fetch: the server must not mark the
            // chapter read just because we loaded it ahead of the reader.
            const res = await fetch(last.next.url, { headers: { 'Accept': 'text/html', 'X-Novarr-Fetch': 'continuous' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            const nextState = JSON.parse(doc.getElementById('readerState').textContent);
            const fetched = doc.querySelector('.reader-section');
            const content = doc.getElementById('chapterContent');

            if (!nextState.hasContent || !content || !fetched) {
                // Pending chapter — stop auto-loading and leave the CTA as a
                // normal link so its page (with the download button) is reachable.
                autoLoadStopped = true;
                return;
            }

            const section = document.createElement('section');
            section.className = 'reader-section';
            section.dataset.id = nextState.id;
            section.dataset.words = fetched.dataset.words || '0';
            // Reuse the fetched page's own kicker/title block, so appended
            // chapters carry the same header as the one they were rendered with.
            const head = fetched.querySelector('.reader-head');
            if (head) section.appendChild(head);
            const body = document.createElement('div');
            body.className = 'chapter-content';
            body.innerHTML = content.innerHTML;
            section.appendChild(body);
            sectionsEl.appendChild(section);

            sections.push({ ...nextState, el: section, words: parseInt(section.dataset.words, 10) || 0 });
            updateCta(nextState.next);
            // The fetch did NOT mark it read; that happens once the reader
            // actually scrolls into it (sectionObserver below).
            if (nextState.deferRead) sectionObserver?.observe(section);
        } catch (err) {
            autoLoadStopped = true; // fall back to the CTA link
        } finally {
            loadingNext = false;
        }
    }

    let sentinelObserver = null;
    if (sentinel && 'IntersectionObserver' in window) {
        sentinelObserver = new IntersectionObserver((entries) => {
            if (entries.some(e => e.isIntersecting)) loadNextInline();
        }, { rootMargin: '600px 0px' });
        sentinelObserver.observe(sentinel);
    }

    // ---- Mark read on view ----
    // Chapters fetched in the background (inline continuous sections, or this
    // page if it was rendered from a Turbo prefetch) arrive with deferRead:
    // the server skipped the read-mark. Mark them via the progress endpoint
    // only when actually shown — for inline sections, once the section's top
    // passes 40% of the viewport (the same point findCurrentIdx switches the
    // "current" chapter). queuedFetch parks the write if we're offline.
    const markedOnView = new Set();
    function markReadOnView(id) {
        if (markedOnView.has(id) || signal.aborted) return;
        markedOnView.add(id);
        readFetch(`/chapters/${id}/progress`, { read: true })
            .then((data) => { if (data?.success && id === state.id) markReadUi(); })
            .catch(() => markedOnView.delete(id));
    }
    const sectionObserver = 'IntersectionObserver' in window
        ? new IntersectionObserver((entries) => {
            for (const e of entries) {
                if (!e.isIntersecting) continue;
                sectionObserver.unobserve(e.target);
                markReadOnView(parseInt(e.target.dataset.id, 10));
            }
        }, { rootMargin: '0px 0px -60% 0px' })
        : null;

    // ---- In-reader chapter list (offcanvas TOC) ----
    // Current row: surface-alt fill + 2px amber rule (not Bootstrap's solid
    // indigo .active). "Unread" narrows the list; "Jump to current" scrolls
    // the list back to the chapter being read.
    const tocList = document.getElementById('tocList');
    const tocFilter = document.getElementById('tocFilter');
    const tocUnread = document.getElementById('tocUnread');
    const tocJump = document.getElementById('tocJump');
    const checkTpl = document.getElementById('readerIconCheck');
    let tocChapters = null;
    let tocUnreadOnly = false;
    const TOC_WINDOW = 120;

    function tocItem(c, currentId) {
        const isCurrent = c.id === currentId;
        const a = document.createElement('a');
        a.href = '/chapters/' + c.id;
        a.className = 'list-group-item list-group-item-action toc-row'
            + (isCurrent ? ' is-current' : '') + (!c.downloaded ? ' toc-pending' : '') + (c.read ? ' is-read' : '');
        if (isCurrent) a.setAttribute('aria-current', 'page');

        const mark = document.createElement('span');
        mark.className = 'toc-mark';
        if (c.read) {
            mark.appendChild(checkTpl.content.cloneNode(true));
            mark.title = 'Read';
        }
        const label = document.createElement('span');
        label.className = 'toc-label';
        label.textContent = chLabel(c, 200);
        if (c.note) {
            const note = document.createElement('span');
            note.className = 'badge badge-paused ms-2';   // NovelState::Paused (muted)
            note.textContent = 'note';
            note.title = "Author's note";
            label.appendChild(note);
        }
        a.append(mark, label);
        if (!c.downloaded) {
            const meta = document.createElement('span');
            meta.className = 'toc-meta';
            meta.textContent = 'pending';
            a.appendChild(meta);
        }
        a.addEventListener('click', (e) => {
            if (window.Turbo) { e.preventDefault(); Turbo.visit(a.href); }
        });
        return a;
    }

    function moreButton(text, onClick) {
        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'list-group-item list-group-item-action toc-more';
        more.textContent = text;
        more.addEventListener('click', onClick);
        return more;
    }

    // Scroll the drawer list (never the page behind it) to the current row.
    function scrollToCurrent(behavior = 'auto') {
        const row = tocList.querySelector('.is-current');
        if (!row) return false;
        tocList.scrollTo({ top: row.offsetTop - tocList.clientHeight / 2 + row.offsetHeight / 2, behavior });
        return true;
    }

    function renderToc(filter = '') {
        if (!tocChapters) return;
        const currentId = sections[currentIdx].id;
        tocList.innerHTML = '';

        let rows = tocChapters;
        if (tocUnreadOnly) rows = rows.filter(c => !c.read || c.id === currentId);
        if (filter) {
            const f = filter.toLowerCase();
            rows = rows.filter(c =>
                String(c.chapter).startsWith(f) || (c.label || '').toLowerCase().includes(f)
            ).slice(0, 200);
            renderRows(rows, currentId);
            return;
        }
        const i = Math.max(0, rows.findIndex(c => c.id === currentId));
        const from = Math.max(0, i - TOC_WINDOW);
        const to = Math.min(rows.length, i + TOC_WINDOW);
        if (from > 0) tocList.appendChild(moreButton(`Show earlier chapters (${from})`, () => renderTocRange(rows, 0, to)));
        renderRows(rows.slice(from, to), currentId);
        if (to < rows.length) tocList.appendChild(moreButton(`Show later chapters (${rows.length - to})`, () => renderTocRange(rows, from, rows.length)));
        scrollToCurrent();
    }

    function renderTocRange(rows, from, to) {
        tocList.innerHTML = '';
        renderRows(rows.slice(from, to), sections[currentIdx].id);
        scrollToCurrent();
    }

    function renderRows(rows, currentId) {
        const frag = document.createDocumentFragment();
        rows.forEach(c => frag.appendChild(tocItem(c, currentId)));
        tocList.appendChild(frag);
        if (!rows.length) {
            tocList.innerHTML = '<div class="p-3 text-muted">' + (tocUnreadOnly ? 'No unread chapters match.' : 'No chapters match.') + '</div>';
        }
    }

    const tocPanelNode = document.getElementById('tocPanel');
    tocPanelNode.addEventListener('show.bs.offcanvas', async () => {
        if (tocChapters) { renderToc(tocFilter.value.trim()); return; }
        try {
            const res = await fetch(`/novels/${state.novelId}/chapters-json`, { headers: { Accept: 'application/json' } });
            tocChapters = (await res.json()).chapters;
            renderToc();
        } catch (err) {
            tocList.innerHTML = '<div class="p-3 tone-danger">Could not load the chapter list.</div>';
        }
    }, { signal });
    // Bootstrap's offcanvas already traps focus, closes on Esc, locks body
    // scroll and returns focus to #tocBtn; add inert on the page behind it.
    let tocHold = null;
    tocPanelNode.addEventListener('show.bs.offcanvas', () => {
        toggleSettings(false);
        tocHold = releaseDialog(tocHold, false);
        const inerted = [readerEl].filter(n => !n.inert);
        inerted.forEach(n => { n.inert = true; });
        tocHold = { el: tocPanelNode, trigger: null, inerted, release: () => {} };
        holds.add(tocHold);
    }, { signal });
    tocPanelNode.addEventListener('hidden.bs.offcanvas', () => { tocHold = releaseDialog(tocHold, false); }, { signal });
    // The drawer slides in; centre the current row once it has a layout.
    tocPanelNode.addEventListener('shown.bs.offcanvas', () => scrollToCurrent(), { signal });

    let tocFilterTimer = null;
    tocFilter.addEventListener('input', () => {
        clearTimeout(tocFilterTimer);
        tocFilterTimer = setTimeout(() => renderToc(tocFilter.value.trim()), 150);
    }, { signal });
    tocUnread.addEventListener('click', () => {
        tocUnreadOnly = !tocUnreadOnly;
        tocUnread.setAttribute('aria-pressed', tocUnreadOnly ? 'true' : 'false');
        tocUnread.classList.toggle('is-active', tocUnreadOnly);
        renderToc(tocFilter.value.trim());
    }, { signal });
    tocJump.addEventListener('click', () => {
        // The current row may be filtered out or outside the rendered window.
        if (!scrollToCurrent('smooth')) {
            tocFilter.value = '';
            renderToc();
        }
        tocList.querySelector('.is-current')?.focus({ preventScroll: true });
    }, { signal });

    // ---- Read state controls ----
    const readToggle = document.getElementById('readToggle');

    // queuedFetch parks the write in IndexedDB if we're offline and replays it
    // on reconnect; falls back to a plain fetch if the module isn't ready yet.
    function readFetch(url, body) {
        if (window.Novarr?.queuedFetch) {
            return Novarr.queuedFetch(url, { method: 'POST', body: body || null });
        }
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
        }).then((r) => r.json());
    }

    function setReadUi(read) {
        readToggle.classList.toggle('is-read', read);
        readToggle.textContent = read ? 'Read' : 'Mark read';
        readToggle.dataset.read = read ? '1' : '0';
        readToggle.setAttribute('aria-pressed', read ? 'true' : 'false');
    }
    function markReadUi() { setReadUi(true); }

    // ---- "Mark to here" (this + all earlier chapters) ----
    const readThrough = document.getElementById('readThrough');
    readThrough.addEventListener('click', async () => {
        readThrough.disabled = true;
        try {
            const data = await readFetch(`/chapters/${readThrough.dataset.id}/read-through`);
            if (data.success) {
                markReadUi();
                Novarr.showToast(
                    data.queued
                        ? 'Saved offline — earlier chapters sync when you reconnect.'
                        : `Marked ${data.marked} earlier chapter(s) as read.`,
                    data.queued ? 'info' : 'success'
                );
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        } finally {
            readThrough.disabled = false;
        }
    });

    // ---- Manual read/unread toggle ----
    // Uses the idempotent bulk-read endpoint (set, not toggle) so a queued
    // replay applies the exact state we intended regardless of ordering.
    const BULK_READ_URL = '{{ route('chapters.bulk_read') }}';
    readToggle.addEventListener('click', async () => {
        readToggle.disabled = true;
        const desired = readToggle.dataset.read !== '1';
        try {
            const data = await readFetch(BULK_READ_URL, { ids: [readToggle.dataset.id], read: desired });
            if (data.success) {
                setReadUi(desired);
                if (data.queued) Novarr.showToast('Saved offline — will sync when you reconnect.', 'info');
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        } finally {
            readToggle.disabled = false;
        }
    });

    // ---- "Mark read & continue": the dominant binge-reading action ----
    if (cta) {
        cta.addEventListener('click', async (e) => {
            e.preventDefault();
            const cur = sections[currentIdx];
            const url = cta.href;
            cta.classList.add('is-busy');
            try {
                await readFetch(BULK_READ_URL, { ids: [cur.id], read: true });
                if (cur.id === state.id) setReadUi(true);
            } catch (err) {
                // Navigating still marks it read server-side when the page loads.
            }
            visit(url);
        });
    }

    // ---- Pending chapter: fetch just this one on demand ----
    const dlBtn = document.getElementById('downloadChapterBtn');
    if (dlBtn) {
        dlBtn.addEventListener('click', async () => {
            dlBtn.disabled = true;
            dlBtn.querySelector('.dl-label').classList.add('d-none');
            dlBtn.querySelector('.dl-spinner').classList.remove('d-none');
            try {
                const result = await Novarr.executeCommand({ command: 'download_chapter', chapter_id: dlBtn.dataset.id });
                if (result.success) {
                    Novarr.showToast('Chapter downloaded — loading it now…', 'success');
                    Novarr.softRefresh(600);
                    return;
                }
                Novarr.showToast(result.output || result.error || 'Download failed — the source may not have this chapter yet.', 'danger');
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
            dlBtn.disabled = false;
            dlBtn.querySelector('.dl-label').classList.remove('d-none');
            dlBtn.querySelector('.dl-spinner').classList.add('d-none');
        });
    }

    // ---- Highlights: select text → floating save button ----
    const hlPop = document.getElementById('highlightPop');
    const hlNote = document.getElementById('hlNote');
    let hlPending = null;

    // Keep the selection alive while interacting with the popover.
    hlPop.addEventListener('mousedown', e => e.preventDefault());

    const hlDefine = document.getElementById('hlDefine');
    const hlDefinition = document.getElementById('hlDefinition');

    function hideHlPop() {
        hlPop.classList.add('d-none');
        hlDefinition.classList.add('d-none');
        hlDefinition.innerHTML = '';
        hlNote.value = '';
        hlPending = null;
    }

    function maybeShowHlPop() {
        const sel = window.getSelection();
        if (!sel || sel.isCollapsed) { hideHlPop(); return; }
        const text = sel.toString().trim();
        if (text.length < 2) { hideHlPop(); return; }

        const anchor = sel.anchorNode?.nodeType === 1 ? sel.anchorNode : sel.anchorNode?.parentElement;
        const section = anchor?.closest('.reader-section');
        if (!section || !anchor.closest('.chapter-content')) { hideHlPop(); return; }

        hlPending = { chapter_id: section.dataset.id, excerpt: text.substring(0, 2000) };
        // Single word → offer a dictionary lookup too.
        hlDefine.classList.toggle('d-none', !/^[A-Za-z’'‐-]{2,30}$/.test(text));
        hlDefine.dataset.word = text;
        hlDefinition.classList.add('d-none');

        const rect = sel.getRangeAt(0).getBoundingClientRect();
        hlPop.classList.remove('d-none');
        hlPop.style.top = Math.max(8, window.scrollY + rect.top - hlPop.offsetHeight - 10) + 'px';
        hlPop.style.left = Math.max(8, Math.min(window.innerWidth - hlPop.offsetWidth - 8,
            window.scrollX + rect.left + rect.width / 2 - hlPop.offsetWidth / 2)) + 'px';
    }

    // ---- Dictionary lookup (dictionaryapi.dev, single words) ----
    hlDefine.addEventListener('click', async () => {
        const word = (hlDefine.dataset.word || '').replace(/[’']/g, "'");
        hlDefinition.classList.remove('d-none');
        hlDefinition.textContent = 'Looking up…';
        try {
            const res = await fetch('https://api.dictionaryapi.dev/api/v2/entries/en/' + encodeURIComponent(word.toLowerCase()));
            if (!res.ok) throw new Error('not found');
            const entry = (await res.json())[0];
            hlDefinition.innerHTML = '';
            const head = document.createElement('div');
            head.className = 'fw-semibold mb-1';
            head.textContent = entry.word + (entry.phonetic ? '  ' + entry.phonetic : '');
            hlDefinition.appendChild(head);
            (entry.meanings || []).slice(0, 2).forEach(m => {
                const pos = document.createElement('div');
                pos.className = 'text-muted fst-italic';
                pos.textContent = m.partOfSpeech;
                hlDefinition.appendChild(pos);
                (m.definitions || []).slice(0, 2).forEach(d => {
                    const li = document.createElement('div');
                    li.textContent = '• ' + d.definition;
                    hlDefinition.appendChild(li);
                });
            });
        } catch (err) {
            hlDefinition.textContent = navigator.onLine
                ? `No definition found for “${word}”.`
                : 'Dictionary lookup needs an internet connection.';
        }
    });

    document.addEventListener('mouseup', () => setTimeout(maybeShowHlPop, 10), { signal });
    document.addEventListener('touchend', () => setTimeout(maybeShowHlPop, 150), { signal });
    document.addEventListener('selectionchange', () => {
        const sel = window.getSelection();
        if ((!sel || sel.isCollapsed) && !hlNote.matches(':focus')) hideHlPop();
    }, { signal });

    document.getElementById('hlSave').addEventListener('click', async () => {
        if (!hlPending) return;
        const payload = { ...hlPending, note: hlNote.value.trim() || null };
        hideHlPop();
        window.getSelection()?.removeAllRanges();
        try {
            const res = await fetch('{{ route('bookmarks.store') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.success) Novarr.showToast('Highlight saved — find it under Highlights.', 'success');
            else Novarr.showToast(data.message || 'Could not save the highlight.', 'danger');
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    });

    // ---- Auto-scroll ----
    let scrollSpeed = Math.min(150, Math.max(10, parseInt(localStorage.getItem('reader_scrollspeed') || '40', 10)));
    const autoScrollToggle = document.getElementById('autoScrollToggle');
    const speedLabel = document.getElementById('autoScrollSpeedLabel');
    let autoScrollOn = false, autoScrollRaf = null, autoScrollLastTs = null, autoScrollAcc = 0;

    function reflectAutoScroll() {
        setPlayBtn(autoScrollToggle, autoScrollOn ? 'Stop' : 'Start', autoScrollOn);
        autoScrollToggle.setAttribute('aria-pressed', autoScrollOn ? 'true' : 'false');
        speedLabel.textContent = scrollSpeed + ' px/s';
    }

    function autoScrollStep(ts) {
        if (!autoScrollOn) return;
        if (autoScrollLastTs !== null) {
            autoScrollAcc += ((ts - autoScrollLastTs) / 1000) * scrollSpeed;
            if (autoScrollAcc >= 1) {
                const px = Math.floor(autoScrollAcc);
                autoScrollAcc -= px;
                window.scrollBy(0, px);
            }
            // End of everything loaded and nothing more coming → stop.
            if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 2) {
                const last = sections[sections.length - 1];
                if (!last.next || prefs.autoNext !== '1' || autoLoadStopped) { stopAutoScroll(); return; }
            }
        }
        autoScrollLastTs = ts;
        autoScrollRaf = requestAnimationFrame(autoScrollStep);
    }

    function startAutoScroll() {
        autoScrollOn = true;
        autoScrollLastTs = null;
        autoScrollAcc = 0;
        reflectAutoScroll();
        autoScrollRaf = requestAnimationFrame(autoScrollStep);
    }
    function stopAutoScroll() {
        if (!autoScrollOn) return;
        autoScrollOn = false;
        cancelAnimationFrame(autoScrollRaf);
        reflectAutoScroll();
    }

    autoScrollToggle.addEventListener('click', () => autoScrollOn ? stopAutoScroll() : startAutoScroll(), { signal });
    playbackBar.querySelectorAll('[data-scrollspeed]').forEach(btn => btn.addEventListener('click', () => {
        scrollSpeed = Math.min(150, Math.max(10, scrollSpeed + (btn.dataset.scrollspeed === '+' ? 10 : -10)));
        localStorage.setItem('reader_scrollspeed', scrollSpeed);
        reflectAutoScroll();
    }, { signal }));
    // Any manual scroll intent pauses auto-scroll.
    ['wheel', 'touchmove'].forEach(ev => document.addEventListener(ev, stopAutoScroll, { passive: true, signal }));
    reflectAutoScroll();

    // ---- Text-to-speech (browser speechSynthesis) ----
    const synth = window.speechSynthesis;
    const ttsPlayPause = document.getElementById('ttsPlayPause');
    const ttsStatus = document.getElementById('ttsStatus');
    let ttsRate = parseFloat(localStorage.getItem('reader_ttsrate') || '1');
    let ttsActive = false, ttsPausedState = false, ttsIdx = 0;

    function reflectTtsRate() {
        document.querySelectorAll('#ttsRateGroup [data-ttsrate]').forEach(b => {
            const on = parseFloat(b.dataset.ttsrate) === ttsRate;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    const ttsParas = () => [...document.querySelectorAll('.chapter-content p')].filter(p => p.textContent.trim().length > 0);

    function ttsHighlight(p) {
        document.querySelectorAll('.tts-active').forEach(el => el.classList.remove('tts-active'));
        if (p) {
            p.classList.add('tts-active');
            p.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    function ttsSpeakFrom(idx) {
        const paras = ttsParas();
        if (idx >= paras.length) { ttsStopAll(); return; }
        ttsIdx = idx;
        const p = paras[idx];
        ttsHighlight(p);
        ttsStatus.textContent = `Paragraph ${idx + 1} of ${paras.length}`;
        const u = new SpeechSynthesisUtterance(p.textContent);
        u.rate = ttsRate;
        u.onend = () => { if (ttsActive && !ttsPausedState) ttsSpeakFrom(ttsIdx + 1); };
        u.onerror = () => ttsStopAll();
        synth.speak(u);
    }

    function ttsStart() {
        if (!synth) { Novarr.showToast('Text-to-speech is not supported in this browser.', 'warning'); return; }
        synth.cancel();
        ttsActive = true;
        ttsPausedState = false;
        setPlayBtn(ttsPlayPause, 'Pause', true);
        // Start from the first paragraph currently in view.
        const paras = ttsParas();
        const ref = window.scrollY + 90;
        let start = 0;
        for (let i = 0; i < paras.length; i++) {
            if (paras[i].getBoundingClientRect().top + window.scrollY + paras[i].offsetHeight > ref) { start = i; break; }
        }
        ttsSpeakFrom(start);
    }

    function ttsStopAll() {
        ttsActive = false;
        ttsPausedState = false;
        synth?.cancel();
        ttsHighlight(null);
        setPlayBtn(ttsPlayPause, 'Play', false);
        ttsStatus.textContent = '';
    }

    ttsPlayPause.addEventListener('click', () => {
        if (!ttsActive) { ttsStart(); return; }
        if (ttsPausedState) {
            ttsPausedState = false;
            synth.resume();
            setPlayBtn(ttsPlayPause, 'Pause', true);
        } else {
            ttsPausedState = true;
            synth.pause();
            setPlayBtn(ttsPlayPause, 'Resume', false);
        }
    }, { signal });
    document.getElementById('ttsStop').addEventListener('click', ttsStopAll, { signal });
    document.querySelectorAll('[data-ttsrate]').forEach(btn => btn.addEventListener('click', () => {
        ttsRate = parseFloat(btn.dataset.ttsrate);
        localStorage.setItem('reader_ttsrate', ttsRate);
        reflectTtsRate();
        if (ttsActive && !ttsPausedState) { synth.cancel(); ttsSpeakFrom(ttsIdx); }
    }));
    reflectTtsRate();

    // Never leave speech running after leaving the page.
    document.addEventListener('turbo:before-visit', ttsStopAll, { once: true, signal });
    window.addEventListener('pagehide', () => synth?.cancel(), { once: true, signal });

    // ---- Offline auto-mark ----
    // The server marks a chapter read when it serves the page; offline the page
    // comes from the cache, so queue the read-mark here instead.
    @if(!$chapter->read_at)
    function offlineAutoMark() {
        // A deferred page is marked by markReadOnView below instead.
        if (state.deferRead || navigator.onLine || !window.Novarr?.queuedFetch) return;
        Novarr.queuedFetch(BULK_READ_URL, { method: 'POST', body: { ids: [{{ $chapter->id }}], read: true } });
        markReadUi();
    }
    if (window.Novarr?.queuedFetch) offlineAutoMark();
    else window.addEventListener('load', offlineAutoMark, { once: true, signal });
    @endif

    // This page itself was served to a prefetch (e.g. Turbo hover prefetch)
    // and is now actually being shown — mark it read now. On a cold (offline)
    // load the app module may not be ready yet; wait for it so the write can
    // be queued rather than lost.
    if (state.deferRead) {
        if (window.Novarr?.queuedFetch) markReadOnView(state.id);
        else window.addEventListener('load', () => markReadOnView(state.id), { once: true, signal });
    }

    // ---- Teardown: leaving this page via Turbo ----
    // before-cache fires only when Turbo snapshots the page (this layout sets
    // turbo-cache-control: no-cache, so usually it won't); before-render fires
    // on every Turbo render that replaces this page. Both run while this page's
    // DOM is still in place, so the final progress flush measures the right
    // chapter. Idempotent.
    function teardown() {
        if (signal.aborted) return;
        try { syncProgress(sections[currentIdx].id, currentProgressPct()); } catch (e) { /* best effort */ }
        clearTimeout(scrollSaveTimer);
        clearTimeout(syncTimer);
        syncTimer = null;
        clearTimeout(tocFilterTimer);
        stopAutoScroll();
        ttsStopAll();
        sentinelObserver?.disconnect();
        sectionObserver?.disconnect();
        // Release every dialog hold (focus traps, inert) and body scroll locks,
        // so nothing leaks into the next page if we leave with a sheet open.
        [...holds].forEach(h => releaseDialog(h, false));
        document.body.classList.remove('reader-sheet-open', 'reader-playbar-open', 'reader-keys-open');
        try { window.bootstrap?.Offcanvas.getInstance(tocPanelNode)?.dispose(); } catch (e) { /* best effort */ }
        document.querySelectorAll('.offcanvas-backdrop').forEach(n => n.remove());
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        pageAbort.abort();   // removes every { signal } listener above, incl. these two
    }
    document.addEventListener('turbo:before-cache', teardown, { signal });
    document.addEventListener('turbo:before-render', teardown, { signal });
})();
</script>
@endpush
