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
        ->where(function ($q) use ($chapter) {
            $q->where('book', '<', $chapter->book)
              ->orWhere(function ($q2) use ($chapter) {
                  $q2->where('book', $chapter->book)->where('chapter', '<=', $chapter->chapter);
              });
        })
        ->count();
    $chapterTitle = $chapter->label ?: 'Chapter ' . $chapter->chapter;
    // Honest minutes-left: real word count of this chapter's body, scaled by
    // how much of it is still below the viewport (see updateMinutesLeft()).
    $wordCount = str_word_count(strip_tags((string) $chapter->description));
@endphp

@section('content')
<div class="reader" id="reader">

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
                        aria-expanded="false" aria-controls="readerSettings"
                        title="Reading settings" aria-label="Reading settings">Aa</button>
                <button type="button" id="tocBtn" class="reader-ctl"
                        data-bs-toggle="offcanvas" data-bs-target="#tocPanel"
                        title="Chapter list" aria-label="Chapter list">Contents</button>
                <button type="button" id="focusBtn" class="reader-ctl"
                        title="Focus mode (hide chrome — tap the page to peek)"
                        aria-label="Focus mode" aria-pressed="false">Focus</button>
            </div>
        </div>
        <div id="readProgressBar" class="reader-rail" aria-hidden="true"><div id="readProgressFill"></div></div>

        {{-- "Aa" popover: every reading preference in one cluster. --}}
        <div id="readerSettings" class="reader-pop d-none" role="dialog" aria-label="Reading settings" aria-modal="false">
            <div class="reader-pop-row">
                <span class="reader-pop-label">Text size</span>
                <div class="reader-seg" role="group" aria-label="Text size">
                    <button type="button" data-font="-" aria-label="Smaller text">A&minus;</button>
                    <span class="reader-seg-value" id="fontSizeLabel" aria-live="polite">19px</span>
                    <button type="button" data-font="+" aria-label="Larger text">A+</button>
                </div>
            </div>
            <div class="reader-pop-row">
                <span class="reader-pop-label">Measure</span>
                <div class="reader-seg" role="group" aria-label="Line measure">
                    <button type="button" data-measure="-" aria-label="Narrower column">&minus;</button>
                    <span class="reader-seg-value" id="measureLabel" aria-live="polite">68ch</span>
                    <button type="button" data-measure="+" aria-label="Wider column">+</button>
                </div>
            </div>
            <div class="reader-pop-row">
                <span class="reader-pop-label">Theme</span>
                <div class="reader-seg" id="themeGroup" role="group" aria-label="Reading theme">
                    <button type="button" data-theme="dark">Dark</button>
                    <button type="button" data-theme="sepia">Sepia</button>
                    <button type="button" data-theme="light">Light</button>
                </div>
            </div>
            <div class="reader-pop-row">
                <span class="reader-pop-label">Typeface</span>
                <div class="reader-seg reader-seg-wrap" id="familyGroup" role="group" aria-label="Font family">
                    <button type="button" data-family="read">Literata</button>
                    <button type="button" data-family="sans">Sans</button>
                    <button type="button" data-family="serif">Georgia</button>
                    <button type="button" data-family="legible" title="Atkinson Hyperlegible — a high-legibility font">Legible</button>
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

            <div class="reader-pop-sep" role="separator"></div>

            <div class="reader-pop-row">
                <span class="reader-pop-label">Auto-scroll</span>
                <div class="reader-seg" role="group" aria-label="Auto-scroll">
                    <button type="button" id="autoScrollToggle" aria-pressed="false">Start</button>
                    <button type="button" data-scrollspeed="-" title="Scroll slower" aria-label="Scroll slower">&minus;</button>
                    <button type="button" data-scrollspeed="+" title="Scroll faster" aria-label="Scroll faster">+</button>
                </div>
            </div>
            <div class="reader-pop-row reader-pop-note">
                <span></span><span id="autoScrollSpeedLabel"></span>
            </div>
            <div class="reader-pop-row">
                <span class="reader-pop-label">Continuous</span>
                <div class="reader-seg" id="autoNextGroup" role="group" aria-label="Auto-load next chapter while scrolling">
                    <button type="button" data-autonext="1" title="Load the next chapter inline when you reach the end">On</button>
                    <button type="button" data-autonext="0">Off</button>
                </div>
            </div>

            <div class="reader-pop-sep" role="separator"></div>

            <div class="reader-pop-row" id="ttsBar">
                <span class="reader-pop-label">Read aloud</span>
                <div class="reader-seg" role="group" aria-label="Text to speech">
                    <button type="button" id="ttsPlayPause">Play</button>
                    <button type="button" id="ttsStop">Stop</button>
                </div>
            </div>
            <div class="reader-pop-row">
                <span class="reader-pop-label">Speed</span>
                <div class="reader-seg" id="ttsRateGroup" role="group" aria-label="Speech rate">
                    <button type="button" data-ttsrate="0.8">0.8&times;</button>
                    <button type="button" data-ttsrate="1">1&times;</button>
                    <button type="button" data-ttsrate="1.25">1.25&times;</button>
                    <button type="button" data-ttsrate="1.5">1.5&times;</button>
                </div>
            </div>
            <div class="reader-pop-row reader-pop-note">
                <span></span><span id="ttsStatus"></span>
            </div>

            <div class="reader-pop-sep" role="separator"></div>

            <div class="reader-pop-row">
                <label class="reader-pop-label" for="perNovelPrefs" title="Keep a separate typography setup for this novel">This novel only</label>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="perNovelPrefs" aria-label="Use separate reader settings for this novel">
                </div>
            </div>
        </div>
    </div>

    {{-- 680px column: kicker, chapter title, hairline rule, prose. --}}
    <div class="reader-col">
        <div id="readerSections">
            <section class="reader-section" data-id="{{ $chapter->id }}" data-words="{{ $wordCount }}">
                <header class="reader-head">
                    <p class="reader-kicker">
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
                        <span class="reader-navblock-label">Ch. {{ $prev->chapter }} &middot; {{ Str::limit($prev->label ?: 'Chapter ' . $prev->chapter, 34) }}</span>
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
                        <span class="reader-navblock-label">Ch. {{ $next->chapter }} &middot; {{ Str::limit($next->label ?: 'Chapter ' . $next->chapter, 34) }}</span>
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
        <input type="text" id="hlNote" class="form-control form-control-sm" placeholder="Note (optional)" style="width: 170px;" aria-label="Bookmark note">
        <button type="button" id="hlSave" class="btn btn-sm btn-primary text-nowrap">Save</button>
        <button type="button" id="hlDefine" class="btn btn-sm btn-outline-secondary d-none" title="Look up this word" aria-label="Define word">Define</button>
    </div>
    <div id="hlDefinition" class="d-none mt-2 text-body" style="max-width: 320px; max-height: 200px; overflow-y: auto; font-size: 12px;"></div>
</div>

{{-- In-reader chapter list --}}
<div class="offcanvas offcanvas-start" tabindex="-1" id="tocPanel" aria-labelledby="tocPanelLabel">
    <div class="offcanvas-header pb-2">
        <h5 class="offcanvas-title" id="tocPanelLabel">Chapters</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0 d-flex flex-column">
        <div class="p-2 border-bottom">
            <input type="search" id="tocFilter" class="form-control form-control-sm" placeholder="Filter by number or title…" aria-label="Filter chapters">
        </div>
        <div id="tocList" class="list-group list-group-flush overflow-auto flex-grow-1" style="font-size: 13px;">
            <div class="p-3 text-muted">Loading…</div>
        </div>
    </div>
</div>

<script type="application/json" id="readerState">@json($readerState)</script>
@endsection

@push('scripts')
<script>
(() => {
    const state = JSON.parse(document.getElementById('readerState').textContent);
    const readerEl = document.getElementById('reader');

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

    // ---- "Aa" popover ----
    const settingsBtn = document.getElementById('readerSettingsBtn');
    const settingsPop = document.getElementById('readerSettings');
    function toggleSettings(show) {
        const open = show ?? settingsPop.classList.contains('d-none');
        settingsPop.classList.toggle('d-none', !open);
        settingsBtn.classList.toggle('is-active', open);
        settingsBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    settingsBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleSettings(); });
    settingsPop.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', () => toggleSettings(false));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !settingsPop.classList.contains('d-none')) {
            toggleSettings(false);
            settingsBtn.focus();
        }
    });

    const bindPref = (attr, apply) => document.querySelectorAll(`[data-${attr}]`).forEach(btn =>
        btn.addEventListener('click', () => { apply(btn.dataset[attr]); applyPrefs(); }));

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
        el.querySelector('.reader-navblock-label').textContent =
            'Ch. ' + target.chapter + ' · ' + (target.label || 'Chapter ' + target.chapter).substring(0, 34);
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
            window.scrollTo({ top: sectionTop(sections[currentIdx - 1]), behavior: 'smooth' });
        } else {
            visit(cur.prev?.url);
        }
    }
    function goNext() {
        const cur = sections[currentIdx];
        if (currentIdx < sections.length - 1) {
            window.scrollTo({ top: sectionTop(sections[currentIdx + 1]), behavior: 'smooth' });
        } else {
            visit(cur.next?.url);
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.target.matches('input, textarea, select')) return;
        if (e.key === 'ArrowLeft') goPrev();
        if (e.key === 'ArrowRight') goNext();
    });

    // Horizontal swipe on touch devices: left = next, right = previous.
    // Ignored when it starts on a control, while text is selected, or when
    // it's mostly vertical (normal scrolling).
    let touchStart = null;
    document.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 1 || e.target.closest('a, button, input, select, textarea, .offcanvas, .reader-pop')) {
            touchStart = null;
            return;
        }
        touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY, t: Date.now() };
    }, { passive: true });
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
    }, { passive: true });

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
        requestAnimationFrame(() => { rafPending = false; updateProgress(); });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    window.addEventListener('pagehide', () => syncProgress(sections[currentIdx].id, currentProgressPct(), true));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') syncProgress(sections[currentIdx].id, currentProgressPct(), true);
    });

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
            const res = await fetch(last.next.url, { headers: { 'Accept': 'text/html' } });
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
            // Fetching the page marked it read server-side (same as opening it).
        } catch (err) {
            autoLoadStopped = true; // fall back to the CTA link
        } finally {
            loadingNext = false;
        }
    }

    if (sentinel && 'IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
            if (entries.some(e => e.isIntersecting)) loadNextInline();
        }, { rootMargin: '600px 0px' }).observe(sentinel);
    }

    // ---- In-reader chapter list (offcanvas TOC) ----
    const tocList = document.getElementById('tocList');
    const tocFilter = document.getElementById('tocFilter');
    let tocChapters = null;
    const TOC_WINDOW = 120;

    function tocItem(c, currentId) {
        const a = document.createElement('a');
        a.href = '/chapters/' + c.id;
        a.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2 py-2'
            + (c.id === currentId ? ' active' : '') + (!c.downloaded ? ' toc-pending' : '');
        const label = document.createElement('span');
        label.className = 'text-truncate';
        label.textContent = (c.read ? '✓ ' : '') + (c.label || 'Chapter ' + c.chapter);
        const meta = document.createElement('span');
        meta.className = 'text-nowrap ' + (c.id === currentId ? '' : 'text-muted');
        meta.style.fontSize = '11px';
        meta.textContent = c.downloaded ? 'Ch. ' + c.chapter : 'pending';
        a.append(label, meta);
        a.addEventListener('click', (e) => {
            if (window.Turbo) { e.preventDefault(); Turbo.visit(a.href); }
        });
        return a;
    }

    function renderToc(filter = '') {
        if (!tocChapters) return;
        const currentId = sections[currentIdx].id;
        tocList.innerHTML = '';

        let rows = tocChapters;
        if (filter) {
            const f = filter.toLowerCase();
            rows = tocChapters.filter(c =>
                String(c.chapter).startsWith(f) || (c.label || '').toLowerCase().includes(f)
            ).slice(0, 200);
        } else {
            const i = Math.max(0, tocChapters.findIndex(c => c.id === currentId));
            const from = Math.max(0, i - TOC_WINDOW);
            const to = Math.min(tocChapters.length, i + TOC_WINDOW);
            if (from > 0) {
                const more = document.createElement('button');
                more.type = 'button';
                more.className = 'list-group-item list-group-item-action text-center text-muted';
                more.textContent = `Show earlier chapters (${from})`;
                more.addEventListener('click', () => renderTocRange(0, to));
                tocList.appendChild(more);
            }
            rows = tocChapters.slice(from, to);
            renderRows(rows, currentId);
            if (to < tocChapters.length) {
                const more = document.createElement('button');
                more.type = 'button';
                more.className = 'list-group-item list-group-item-action text-center text-muted';
                more.textContent = `Show later chapters (${tocChapters.length - to})`;
                more.addEventListener('click', () => renderTocRange(from, tocChapters.length));
                tocList.appendChild(more);
            }
            tocList.querySelector('.active')?.scrollIntoView({ block: 'center' });
            return;
        }
        renderRows(rows, currentId);
    }

    function renderTocRange(from, to) {
        const currentId = sections[currentIdx].id;
        tocList.innerHTML = '';
        renderRows(tocChapters.slice(from, to), currentId);
        tocList.querySelector('.active')?.scrollIntoView({ block: 'center' });
    }

    function renderRows(rows, currentId) {
        const frag = document.createDocumentFragment();
        rows.forEach(c => frag.appendChild(tocItem(c, currentId)));
        tocList.appendChild(frag);
        if (!rows.length) {
            tocList.innerHTML = '<div class="p-3 text-muted">No chapters match.</div>';
        }
    }

    document.getElementById('tocPanel').addEventListener('show.bs.offcanvas', async () => {
        if (tocChapters) { renderToc(tocFilter.value.trim()); return; }
        try {
            const res = await fetch(`/novels/${state.novelId}/chapters-json`, { headers: { Accept: 'application/json' } });
            tocChapters = (await res.json()).chapters;
            renderToc();
        } catch (err) {
            tocList.innerHTML = '<div class="p-3 text-danger">Could not load the chapter list.</div>';
        }
    });
    let tocFilterTimer = null;
    tocFilter.addEventListener('input', () => {
        clearTimeout(tocFilterTimer);
        tocFilterTimer = setTimeout(() => renderToc(tocFilter.value.trim()), 150);
    });

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

    document.addEventListener('mouseup', () => setTimeout(maybeShowHlPop, 10));
    document.addEventListener('touchend', () => setTimeout(maybeShowHlPop, 150));
    document.addEventListener('selectionchange', () => {
        const sel = window.getSelection();
        if ((!sel || sel.isCollapsed) && !hlNote.matches(':focus')) hideHlPop();
    });

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
            if (data.success) Novarr.showToast('Highlight saved — find it under Bookmarks.', 'success');
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
        autoScrollToggle.textContent = autoScrollOn ? 'Stop' : 'Start';
        autoScrollToggle.classList.toggle('is-active', autoScrollOn);
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

    autoScrollToggle.addEventListener('click', () => autoScrollOn ? stopAutoScroll() : startAutoScroll());
    document.querySelectorAll('[data-scrollspeed]').forEach(btn => btn.addEventListener('click', () => {
        scrollSpeed = Math.min(150, Math.max(10, scrollSpeed + (btn.dataset.scrollspeed === '+' ? 10 : -10)));
        localStorage.setItem('reader_scrollspeed', scrollSpeed);
        reflectAutoScroll();
    }));
    // Any manual scroll intent pauses auto-scroll.
    ['wheel', 'touchmove'].forEach(ev => document.addEventListener(ev, stopAutoScroll, { passive: true }));
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
        ttsPlayPause.textContent = 'Pause';
        ttsPlayPause.classList.add('is-active');
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
        ttsPlayPause.textContent = 'Play';
        ttsPlayPause.classList.remove('is-active');
        ttsStatus.textContent = '';
    }

    ttsPlayPause.addEventListener('click', () => {
        if (!ttsActive) { ttsStart(); return; }
        if (ttsPausedState) {
            ttsPausedState = false;
            synth.resume();
            ttsPlayPause.textContent = 'Pause';
        } else {
            ttsPausedState = true;
            synth.pause();
            ttsPlayPause.textContent = 'Resume';
        }
    });
    document.getElementById('ttsStop').addEventListener('click', ttsStopAll);
    document.querySelectorAll('[data-ttsrate]').forEach(btn => btn.addEventListener('click', () => {
        ttsRate = parseFloat(btn.dataset.ttsrate);
        localStorage.setItem('reader_ttsrate', ttsRate);
        reflectTtsRate();
        if (ttsActive && !ttsPausedState) { synth.cancel(); ttsSpeakFrom(ttsIdx); }
    }));
    reflectTtsRate();

    // Never leave speech running after leaving the page.
    document.addEventListener('turbo:before-visit', ttsStopAll, { once: true });
    window.addEventListener('pagehide', () => synth?.cancel(), { once: true });

    // ---- Offline auto-mark ----
    // The server marks a chapter read when it serves the page; offline the page
    // comes from the cache, so queue the read-mark here instead.
    @if(!$chapter->read_at)
    function offlineAutoMark() {
        if (navigator.onLine || !window.Novarr?.queuedFetch) return;
        Novarr.queuedFetch(BULK_READ_URL, { method: 'POST', body: { ids: [{{ $chapter->id }}], read: true } });
        markReadUi();
    }
    if (window.Novarr?.queuedFetch) offlineAutoMark();
    else window.addEventListener('load', offlineAutoMark, { once: true });
    @endif
})();
</script>
@endpush
