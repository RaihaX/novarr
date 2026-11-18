@extends('layouts.app')

@section('title', $data->name)

@php
    // ---- Figures, each stated once on the page -----------------------------
    $unread = max(0, $current_chapters - $read_count);
    // Not downloaded = waiting for a first fetch (queued) + whose last fetch
    // failed (failed). "Gaps" are numbers missing from the 1..latest sequence.
    $queued = max(0, $current_chapters_not_downloaded - $failed_count);
    $gaps = count($missing_chapters);
    $dupes = count($duplicate_chapters);

    // Download progress uses the one shared definition — downloaded ÷
    // chapters known to the source — so it matches the Library.
    $dl = \App\Services\NovelHealth::downloadProgress(
        (int) $current_chapters,
        (int) $current_chapters + (int) $current_chapters_not_downloaded,
        (int) ($data->no_of_chapters ?? 0)
    );
    // Reading progress is read ÷ chapters known to the source, the same
    // figure the Library grid and table show.
    $readTotal = max((int) $dl['total'], (int) $read_count);
    $readPct = $readTotal > 0 ? (int) min(100, floor($read_count / $readTotal * 100)) : 0;
    $dlState = \App\Services\NovelHealth::progressState(
        $dl['percent'],
        (bool) $data->status,
        !$data->status && (bool) $data->paused_at,
        isset(\App\Services\NovelHealth::attentionIds()[$data->id])
    );
    $dlComplete = $dl['total'] > 0 && $dl['downloaded'] >= $dl['total'];

    $continueLabel = $continue_number !== null && $continue_number !== '' ? 'chapter ' . $continue_number : null;

    $metaItems = [];
    if ($data->translator_url) $metaItems[] = ['href' => $data->translator_url, 'text' => parse_url($data->translator_url, PHP_URL_HOST)];
    if ($data->isManga()) $metaItems[] = ['text' => 'Manga'];
    if ($originLabel = $data->originLabel()) $metaItems[] = ['text' => $originLabel];
    if ($data->group && $data->group->label) $metaItems[] = ['text' => $data->group->label];
    if ($data->language && $data->language->label) $metaItems[] = ['text' => $data->language->label];

    // The Book column only earns its space when some chapter is in a book.
    $showBookCol = $chapters->contains(fn($c) => (int) $c->book !== 0);
    $chapterUrl = route('chapters.show', '__ID__');
    // Links back to the library go to this series' own library (Novels / Manga).
    $seriesMedium = $data->isManga() ? \App\Novel::MEDIUM_MANGA : \App\Novel::MEDIUM_NOVEL;
    $libraryUrl = route('novels.index', ['medium' => $seriesMedium]);
@endphp

@section('content')
<a href="{{ $libraryUrl }}" class="back-link">
    <x-icon name="chevron-left" :size="14" :stroke="1.5" /> {{ \App\Http\Helpers\LibraryMedium::title($seriesMedium) }}
</a>

{{-- ===================================================================== --}}
{{-- Hero — cover, identity, about, actions; the progress card beside it.  --}}
{{-- Grid areas re-order it on phones (resources/css/_novel.scss).         --}}
{{-- ===================================================================== --}}
<div class="nv-hero">
    <div class="nv-cover">
        <x-cover :novel="$data" size="lg" :progress="$readPct" eager />
    </div>

    <div class="nv-head">
        <h1 class="nv-title">{{ $data->name }}</h1>
        <p class="nv-byline">
            <span class="nv-author">{{ $data->author ?: 'Unknown author' }}</span>
            <span class="nv-sep" aria-hidden="true">·</span>
            @if($data->status)
                <x-status state="completed" as="text" id="novelStatusBadge" data-completed="1" />
            @elseif($data->paused_at)
                <x-status state="paused" as="text" id="novelStatusBadge" title="Paused {{ $data->paused_at->format('j M Y') }} — automatic downloads skip this novel" />
            @else
                <x-status state="active" as="text" id="novelStatusBadge" />
            @endif
            @foreach($metaItems as $item)
                <span class="nv-sep" aria-hidden="true">·</span>
                @isset($item['href'])
                    <a href="{{ $item['href'] }}" class="nv-meta-link" target="_blank" rel="noopener">{{ $item['text'] }}<span class="visually-hidden"> (source site, opens in a new tab)</span></a>
                @else
                    <span>{{ $item['text'] }}</span>
                @endisset
            @endforeach
        </p>
    </div>

    <div class="nv-about">
        {{-- Tags --}}
        <div>
            <div id="tagDisplay" class="nv-tags">
                <span class="visually-hidden">Tags:</span>
                <span id="tagList" class="nv-tag-list">
                    @forelse($data->tags as $tag)
                        <a href="{{ route('novels.index', ['medium' => $seriesMedium, 'tag' => $tag->id]) }}" class="nv-tag">{{ $tag->name }}</a>
                    @empty
                        <span class="tag-empty">No tags</span>
                    @endforelse
                </span>
                <button type="button" id="editTags" class="nv-link-btn">Edit tags</button>
            </div>
            <div id="tagEditor" class="d-none">
                <div class="label-caption mb-2">Tags</div>
                <div class="d-flex gap-2 align-items-start flex-wrap">
                    @include('partials.tag-picker', ['selectedIds' => $data->tags->pluck('id')->all()])
                    <button type="button" id="saveTags" class="btn btn-primary btn-sm" data-id="{{ $data->id }}">Save</button>
                    <button type="button" id="cancelTags" class="btn btn-secondary btn-sm">Cancel</button>
                </div>
            </div>
        </div>

        {{-- Synopsis --}}
        @if($synopsis)
            <div class="detail-synopsis" id="synopsis">
                <div class="synopsis-body" id="synopsisBody">{!! $synopsis !!}</div>
                <button type="button" class="synopsis-toggle d-none" id="synopsisToggle" aria-expanded="false" aria-controls="synopsisBody">Read more</button>
            </div>
        @else
            <div class="detail-no-synopsis">
                <em>No summary available.</em>
                <span>Fetch one with <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-proxy-click="#refreshMetadataBtn">Refresh metadata</button>.</span>
            </div>
        @endif
    </div>

    {{-- Actions: one primary, Download for offline, and the overflow menu. --}}
    <div class="nv-actions">
        @if($continue_chapter_id)
            <a href="{{ route('chapters.show', $continue_chapter_id) }}" class="btn btn-primary nv-continue">
                <x-icon name="play" :size="13" />
                @if($read_count > 0 || !empty($continue_page_number))
                    Continue{{ $continueLabel ? ' at ' . $continueLabel : ' reading' }}{{ !empty($continue_page_number) ? ', page ' . number_format($continue_page_number) : '' }}
                @else
                    Start reading
                @endif
            </a>
        @endif
        {{-- Offline copies hold chapter pages' HTML only: a manga's page
             images are not saved, so it has no "Download for offline". --}}
        @unless($data->isManga())
        <span id="offlineControls" data-id="{{ $data->id }}" data-total="{{ $current_chapters }}" data-unread="{{ $unread }}" class="nv-offline">
            <div class="dropdown">
                <button type="button" id="offlineBtn" class="btn btn-secondary btn-icon-label" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Download for offline">
                    <x-icon name="download" :size="14" />
                    <span class="offline-btn-label">Download for offline</span>
                    <span class="offline-btn-count d-none"></span>
                    <x-icon name="chevron-down" :size="14" class="icon caret" />
                </button>
                <ul class="dropdown-menu dropdown-menu-end download-menu">
                    <li><h6 class="dropdown-header label-caption">Save on this device</h6></li>
                    <li><button type="button" class="dropdown-item" data-scope="unread-next" data-limit="100">Next 100 unread</button></li>
                    <li><button type="button" class="dropdown-item" data-scope="unread">All unread (<span class="offl-unread">0</span>)</button></li>
                    <li><button type="button" class="dropdown-item" data-scope="all">All chapters (<span class="offl-total">0</span>)</button></li>
                    <li>
                        <div class="download-range">
                            <div class="label-caption mb-2">Chapter range</div>
                            <div class="d-flex gap-1 align-items-center">
                                <input type="number" id="offlFrom" class="form-control form-control-sm" placeholder="From" min="0" step="any" aria-label="Range from chapter">
                                <input type="number" id="offlTo" class="form-control form-control-sm" placeholder="To" min="0" step="any" aria-label="Range to chapter">
                                <button type="button" class="btn btn-sm btn-secondary" data-scope="range">Get</button>
                            </div>
                        </div>
                    </li>
                    <li><button type="button" id="offlineRemove" class="dropdown-item item-danger d-none"><x-icon name="trash-2" :size="14" />Remove offline copy</button></li>
                </ul>
            </div>
        </span>
        @endunless
        <div class="dropdown">
            <button type="button" class="btn btn-secondary btn-icon" id="novelMoreBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="More actions" title="More actions">
                <x-icon name="more-horizontal" :size="16" />
            </button>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="novelMoreBtn">
                <li><a href="{{ route('novels.edit', $data->id) }}" class="dropdown-item"><x-icon name="pencil" :size="14" />Edit details</a></li>
                <li>
                    <button type="button" id="pauseToggle" class="dropdown-item" data-id="{{ $data->id }}" title="Paused novels are skipped by automatic downloads; manual commands still work">
                        <x-icon name="pause" :size="14" class="icon pause-icon-pause {{ $data->paused_at ? 'd-none' : '' }}" />
                        <x-icon name="play" :size="14" class="icon pause-icon-play {{ $data->paused_at ? '' : 'd-none' }}" />
                        <span class="pause-label">{{ $data->paused_at ? 'Resume downloads' : 'Pause downloads' }}</span>
                    </button>
                </li>
                <li>
                    <button type="button" class="dropdown-item cmd-btn" id="refreshMetadataBtn" data-command="metadata" data-novel="{{ $data->id }}" title="Re-fetch title, author, cover and synopsis from the source">
                        <x-icon name="refresh-cw" :size="14" />
                        <span class="cmd-label">Refresh metadata</span>
                        <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                        <span class="cmd-done d-none">Done</span>
                        <span class="cmd-fail d-none">Failed</span>
                    </button>
                </li>
                {{-- Text-only actions: a manga has page images (no text search, ePub or Kindle). --}}
                @unless($data->isManga())
                <li><a href="{{ route('search.index', ['novel' => $data->id]) }}" class="dropdown-item"><x-icon name="search" :size="14" />Search the text of this novel</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header label-caption">ePub</h6></li>
                <li><a href="{{ route('novels.download_epub', $data->id) }}" class="dropdown-item" data-turbo="false" data-turbo-prefetch="false"><x-icon name="download" :size="14" />Download ePub</a></li>
                <li>
                    <button type="button" class="dropdown-item cmd-btn" data-command="epub" data-novel="{{ $data->id }}" title="Build an ePub from the downloaded chapters">
                        <x-icon name="refresh-cw" :size="14" />
                        <span class="cmd-label">Generate ePub</span>
                        <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                        <span class="cmd-done d-none">Done</span>
                        <span class="cmd-fail d-none">Failed</span>
                    </button>
                </li>
                <li>
                    <button type="button" class="dropdown-item cmd-btn" data-command="send_to_kindle" data-novel="{{ $data->id }}" title="Email this novel's ePub to your Kindle">
                        <x-icon name="external-link" :size="14" />
                        <span class="cmd-label">Send to Kindle</span>
                        <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Sending</span>
                        <span class="cmd-done d-none">Sent</span>
                        <span class="cmd-fail d-none">Failed</span>
                    </button>
                </li>
                @endunless
                <li><hr class="dropdown-divider"></li>
                <li><button type="button" id="deleteNovel" class="dropdown-item item-danger" data-id="{{ $data->id }}" data-name="{{ $data->name }}"><x-icon name="trash-2" :size="14" />Delete novel</button></li>
            </ul>
        </div>
    </div>

    {{-- Progress: reading and downloads, each stated once. Exception counts
         (queued / failed / review / gaps / duplicates) only when non-zero. --}}
    <div class="nv-side">
        <section class="nv-progress" aria-labelledby="nvProgressTitle">
            <h2 class="visually-hidden" id="nvProgressTitle">Progress</h2>

            <div class="nv-progress-part">
                <h3 class="nv-progress-key">Reading</h3>
                @if($current_chapters > 0)
                    <p class="nv-progress-figure">
                        <span class="nv-progress-pct">{{ $readPct }}%</span>
                        <span class="nv-progress-of">{{ number_format($read_count) }} of {{ number_format($readTotal) }} read</span>
                    </p>
                    <div class="progress progress-reading" role="progressbar" aria-label="Reading progress" aria-valuenow="{{ $readPct }}" aria-valuemin="0" aria-valuemax="100" aria-valuetext="{{ number_format($read_count) }} of {{ number_format($readTotal) }} chapters read">
                        <div class="progress-bar" style="width: {{ $readPct }}%"></div>
                    </div>
                    <p class="nv-progress-note">
                        @if($unread > 0)
                            {{ number_format($unread) }} {{ Str::plural('chapter', $unread) }} unread
                        @else
                            All caught up
                        @endif
                    </p>
                @else
                    <p class="nv-progress-note">Nothing downloaded to read yet.</p>
                @endif
            </div>

            <div class="nv-progress-part">
                <h3 class="nv-progress-key">Downloads</h3>
                <p class="nv-dl-line">
                    <span class="nv-dot {{ \App\Enums\NovelState::from($dlState)->barClass() }}" aria-hidden="true"></span>
                    @if($dlComplete)
                        <span>All {{ number_format($dl['downloaded']) }} {{ Str::plural('chapter', $dl['downloaded']) }} downloaded</span>
                    @else
                        <span>Downloaded {{ number_format($dl['downloaded']) }} of {{ number_format($dl['total']) }} on source · <span data-progress-percent>{{ $dl['percent'] }}%</span></span>
                    @endif
                </p>
                @unless($dlComplete)
                    <div class="progress progress-{{ $dlState }}" role="progressbar" aria-label="Download progress" aria-valuenow="{{ $dl['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: {{ $dl['percent'] }}%"></div>
                    </div>
                @endunless

                @if($queued || $failed_count || $review_count || $gaps || $dupes)
                    <ul class="nv-issues">
                        @if($queued > 0)
                            <li><span class="nv-issue-num tone-pending">{{ number_format($queued) }}</span> queued</li>
                        @endif
                        @if($failed_count > 0)
                            <li>
                                <span class="nv-issue-num tone-danger">{{ number_format($failed_count) }}</span> failed
                                <button type="button" class="nv-link-btn" data-show-chapters="failed">Show</button>
                            </li>
                        @endif
                        @if($review_count > 0)
                            <li title="Failed {{ \App\NovelChapter::REVIEW_ATTEMPTS }} or more times — still retried every 3 days, but worth a look">
                                <span class="nv-issue-num tone-warning">{{ number_format($review_count) }}</span> {{ $review_count === 1 ? 'needs' : 'need' }} review
                            </li>
                        @endif
                        @if($gaps > 0)
                            <li title="Chapter numbers between 1 and the latest that no chapter covers">
                                <span class="nv-issue-num tone-warning">{{ number_format($gaps) }}</span> missing from the numbering
                            </li>
                        @endif
                        @if($dupes > 0)
                            <li>
                                <span class="nv-issue-num tone-warning">{{ number_format($dupes) }}</span> duplicated {{ Str::plural('chapter', $dupes) }}
                                <button type="button" id="removeDupes" class="nv-link-btn" data-id="{{ $data->id }}" title="{{ $dupes }} duplicate chapter group(s) detected">Remove {{ $dupes }} duplicate(s)</button>
                            </li>
                        @endif
                    </ul>
                @else
                    <p class="nv-progress-note">Nothing queued or failed · checked {{ !$data->status && $data->frequent_toc ? 'hourly' : 'daily' }}</p>
                @endif

                @if($queued + $failed_count > 0)
                    <button type="button" class="btn btn-secondary btn-sm cmd-btn nv-progress-action" data-command="chapter" data-novel="{{ $data->id }}" title="Fetch the queued and failed chapters now">
                        <span class="cmd-label">{{ $failed_count > 0 ? 'Retry downloads now' : 'Download queued now' }}</span>
                        <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                        <span class="cmd-done d-none">Done</span>
                        <span class="cmd-fail d-none">Failed</span>
                    </button>
                @endif

                @if(!$data->status)
                    {{-- Hourly checks: a switch, posting to the existing toggle endpoint --}}
                    <div class="form-check form-switch detail-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="frequentToggle" data-id="{{ $data->id }}" @checked($data->frequent_toc)>
                        <label class="form-check-label" for="frequentToggle" title="Check this novel's source for new chapters every hour instead of once a day">Check this novel hourly</label>
                    </div>
                @endif
            </div>
        </section>
        @include('partials.source-unavailable', ['novel' => $data])
    </div>
</div>

{{-- Output pane for header-menu and progress-card commands (Generate ePub,
     Send to Kindle, Refresh metadata, Retry) — near where they are started. --}}
<div id="cmdOutput" class="card mb-4 d-none">
    <pre id="cmdOutputText" class="cmd-output-pane" aria-live="polite"></pre>
</div>

{{-- ===================================================================== --}}
{{-- Chapters — server-rendered page of rows; the filter (resources/js/    --}}
{{-- novel.js) loads chapters-json once and filters it in memory.          --}}
{{-- ===================================================================== --}}
<section class="card chapter-panel nv-chapters" id="chapterPanel" aria-labelledby="chaptersTitle"
         data-novel-chapters
         data-novel-id="{{ $data->id }}"
         data-json-url="{{ route('novels.chapters_json', $data->id) }}"
         data-chapter-url="{{ $chapterUrl }}"
         data-bulk-url="{{ route('chapters.bulk_read') }}"
         data-continue-id="{{ $continue_chapter_id }}"
         data-book-col="{{ $showBookCol ? 1 : 0 }}">
    <div class="nv-ch-head">
        <h2 class="panel-title" id="chaptersTitle">Chapters <span class="count-chip">{{ number_format($chapters->total()) }}</span></h2>

        <div class="nv-chips" role="group" aria-label="Show chapters">
            <button type="button" class="nv-chip" data-state-filter="all" aria-pressed="true">All <span class="nv-chip-count">{{ number_format($chapters->total()) }}</span></button>
            <button type="button" class="nv-chip" data-state-filter="unread" aria-pressed="false">Unread <span class="nv-chip-count">{{ number_format($unread) }}</span></button>
            @if($queued > 0)
                <button type="button" class="nv-chip" data-state-filter="queued" aria-pressed="false">Queued <span class="nv-chip-count">{{ number_format($queued) }}</span></button>
            @endif
            @if($failed_count > 0)
                <button type="button" class="nv-chip" data-state-filter="failed" aria-pressed="false">Failed <span class="nv-chip-count tone-danger">{{ number_format($failed_count) }}</span></button>
            @endif
        </div>

        @if($continue_chapter_id)
            <a href="{{ request()->fullUrlWithQuery(['page' => $continue_page > 1 ? $continue_page : null]) }}#ch-{{ $continue_chapter_id }}" class="nv-jump" data-jump-current data-jump-page="{{ $continue_page }}">Jump to {{ $continueLabel ?? 'the next chapter' }}</a>
        @endif

        <span class="nv-ch-spacer"></span>

        {{-- Typing filters the list; Enter on a number opens that chapter
             (the old "Ch. #" jump), also without JavaScript. --}}
        <form method="GET" action="{{ route('novels.jump_chapter', $data->id) }}" class="nv-filter" role="search" data-chapter-filter-form>
            <label for="chFilter" class="visually-hidden">Filter chapters by title or number; press Enter on a number to open it</label>
            <x-icon name="search" :size="13" />
            <input type="search" id="chFilter" name="n" class="form-control" placeholder="Filter or go to chapter" autocomplete="off" enterkeyhint="go" aria-describedby="chFilterStatus">
        </form>

        <div class="nv-ch-tools">
            {{-- Phones: checkboxes stay hidden until selection mode is on --}}
            <button type="button" id="chSelectToggle" class="btn btn-ghost btn-sm ch-select-toggle" aria-pressed="false" aria-controls="chapterTable">Select</button>
            <div id="chBulkBar" class="bulk-bar">
                <span id="chBulkCount" class="bulk-count"></span>
                <button type="button" id="chMarkRead" class="btn btn-ghost btn-sm">Mark read</button>
                <button type="button" id="chMarkUnread" class="btn btn-ghost btn-sm">Mark unread</button>
            </div>
            <div class="dropdown">
                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Mark read</button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header label-caption">Needs one chapter selected</h6></li>
                    <li><button type="button" class="dropdown-item" id="chReadUpTo">Read up to selected chapter</button></li>
                    <li><button type="button" class="dropdown-item" id="chReadFrom">Read from selected chapter to end</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><button type="button" class="dropdown-item" id="chReadAll">Mark all chapters read</button></li>
                    <li><button type="button" class="dropdown-item item-danger" id="chUnreadAll">Mark all chapters unread</button></li>
                </ul>
            </div>
        </div>
    </div>

    <p class="nv-filter-status" id="chFilterStatus" role="status" aria-live="polite"></p>

    <div class="table-responsive">
        <table class="table chapter-table align-middle" id="chapterTable">
            <caption class="visually-hidden">Chapters of {{ $data->name }}</caption>
            <thead>
                <tr>
                    <th class="ch-select" style="width: 46px"><input type="checkbox" id="chSelectAll" class="form-check-input" aria-label="Select all chapters shown"></th>
                    <th style="width: 84px">Ch.</th>
                    @if($showBookCol)
                        <th style="width: 60px">Book</th>
                    @endif
                    <th>Title</th>
                    <th style="width: 150px">State</th>
                    <th class="ch-date-head" style="width: 150px">Downloaded</th>
                </tr>
            </thead>
            <tbody id="chRows">
                @forelse($chapters as $chapter)
                    @php
                        $state = \App\Http\Controllers\NovelController::chapterState($chapter);
                        $isRead = $chapter->read_at !== null;
                        $isCurrent = (int) $chapter->id === (int) $continue_chapter_id;
                        $review = $state !== 'downloaded' && (int) ($chapter->attempts ?? 0) >= \App\NovelChapter::REVIEW_ATTEMPTS;
                    @endphp
                    <tr class="chapter-row is-{{ $state }}{{ $isRead ? ' is-read' : '' }}{{ $isCurrent ? ' is-current' : '' }}" id="ch-{{ $chapter->id }}">
                        <td class="ch-select"><input type="checkbox" class="form-check-input ch-check" value="{{ $chapter->id }}" aria-label="Select chapter {{ $chapter->chapter }}"></td>
                        <td class="ch-num"><span class="ch-num-prefix">Ch. </span>{{ $chapter->chapter }}</td>
                        @if($showBookCol)
                            <td class="ch-book"><span class="ch-num-prefix">Book </span>{{ $chapter->book ?: '—' }}</td>
                        @endif
                        <td class="ch-title">
                            @if($chapter->status)
                                <a href="{{ route('chapters.show', $chapter->id) }}" class="chapter-link">{{ Str::limit($chapter->label, 90) }}</a>
                            @else
                                <span class="chapter-label">{{ Str::limit($chapter->label, 90) }}</span>
                            @endif
                            @if($data->isManga() && $chapter->page_count)
                                <span class="ch-pages text-muted small ms-1">{{ number_format($chapter->page_count) }} {{ $chapter->page_count === 1 ? 'page' : 'pages' }}</span>
                            @endif
                            @if($chapter->isNote())
                                <x-status state="paused" class="ms-1" title="Author's note — a message from the author or translator, not a story chapter">Note</x-status>
                            @endif
                            @if($state === 'failed')
                                <span class="ch-reason">{{ \App\Http\Controllers\NovelController::failureReasonText($chapter->last_failure_reason) . ((int) $chapter->attempts > 1 ? ' · ' . (int) $chapter->attempts . ' attempts' : '') }}</span>
                            @endif
                        </td>
                        <td class="ch-status">
                            @if($isCurrent)
                                <span class="ch-current">Up next</span>
                            @endif
                            @if($review)
                                <x-status state="attention" title="Download failed {{ (int) $chapter->attempts }} times — still retried every 3 days, but it needs a look">Needs review</x-status>
                            @elseif($state === 'failed')
                                <x-status state="failed" />
                            @elseif($state === 'queued')
                                <x-status state="queued" />
                            @endif
                            @if($isRead)
                                <span class="ch-read" title="Read {{ $chapter->read_at->format('Y-m-d H:i') }}"><x-icon name="check" :size="13" :stroke="2.25" />Read</span>
                            @endif
                        </td>
                        <td class="ch-date">{{ $chapter->download_date ? $chapter->download_date->format('Y-m-d H:i') : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showBookCol ? 6 : 5 }}" class="text-center text-muted py-4">No chapters found.</td>
                    </tr>
                @endforelse
            </tbody>
            {{-- Filter results, rendered by resources/js/novel.js --}}
            <tbody id="chFiltered" hidden></tbody>
        </table>
    </div>
    <div class="nv-filter-more" id="chFilterMore" hidden>
        <button type="button" class="btn btn-secondary btn-sm" id="chShowMore">Show more</button>
    </div>
    @if($chapters->hasPages())
        <div class="card-footer" id="chPager">
            {{ $chapters->links() }}
        </div>
    @endif
</section>
<template id="checkIconTpl"><x-icon name="check" :size="13" :stroke="2.25" /></template>
<template id="chStatusTpl">
    <x-status state="failed" data-tpl="failed" />
    <x-status state="queued" data-tpl="queued" />
    <x-status state="attention" data-tpl="review">Needs review</x-status>
    <x-status state="paused" class="ms-1" data-tpl="note" title="Author's note — a message from the author or translator, not a story chapter">Note</x-status>
</template>

{{-- ===================================================================== --}}
{{-- Maintenance — one disclosure, collapsed by default. The command        --}}
{{-- buttons inside are unchanged (data-dry-run-first wiring below).        --}}
{{-- ===================================================================== --}}
<details class="card maintenance-panel mt-4" id="maintenancePanel">
    <summary class="panel-head maintenance-summary">
        <h2 class="panel-title">Maintenance</h2>
        <span class="panel-note">Scrape, repair and inspect — commands run in the background</span>
        <x-icon name="chevron-down" :size="16" class="icon maintenance-caret" />
    </summary>
    <div class="card-body">
        <div class="qa-section">
            <div class="qa-label">Acquire</div>
            <div class="qa-buttons">
                <button class="btn btn-secondary cmd-btn" data-command="toc" data-novel="{{ $data->id }}" title="Re-scrape the table of contents to discover new chapters">
                    <span class="cmd-label">Scrape TOC</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-secondary cmd-btn" data-command="chapter" data-novel="{{ $data->id }}" title="Download the content of any pending chapters">
                    <span class="cmd-label">Download chapters</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
        </div>

        <div class="qa-section">
            <div class="qa-label">Repair</div>
            {{-- Buttons with data-dry-run-first preview the change (dry run) and
                 ask for confirmation before the real run; see script below. --}}
            <div class="qa-buttons" id="maintenanceButtons">
                <button class="btn btn-secondary cmd-btn" data-command="normalize_labels" data-novel="{{ $data->id }}" data-dry-run-first title="Rewrite chapter labels/numbers to a consistent format">
                    <span class="cmd-label">Normalize labels</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-secondary cmd-btn" data-command="fix_chapters" data-novel="{{ $data->id }}" data-dry-run-first title="Resolve chapters with missing numbers by elimination against the novel sequence">
                    <span class="cmd-label">Fix chapter numbers</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-secondary cmd-btn" data-command="clean_content" data-novel="{{ $data->id }}" data-dry-run-first title="Strip leftover CSS and ad-widget text from downloaded chapters">
                    <span class="cmd-label">Clean formatting</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-secondary cmd-btn" data-command="chapter_cleaner" data-novel="{{ $data->id }}" data-dry-run-first title="Re-download chapters that saved with little or no content">
                    <span class="cmd-label">Fix empty chapters</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <a href="{{ route('novels.snapshots', $data->id) }}" class="btn btn-secondary" title="Pages the scraper fetched but could not read, kept for diagnosis">Snapshots</a>
            </div>
            <script>
            // Destructive maintenance commands: run a dry run first, show its
            // output, and only start the real run (via the page's normal
            // cmd-btn handler) once the user confirms. Capture phase on the
            // container fires before the button's own click listener.
            (function () {
                const box = document.getElementById('maintenanceButtons');
                box.addEventListener('click', async (e) => {
                    const btn = e.target.closest('button[data-dry-run-first]');
                    if (!btn || btn.disabled) return;
                    if (btn.dataset.confirmed === '1') { delete btn.dataset.confirmed; return; }
                    e.stopImmediatePropagation();
                    e.preventDefault();

                    const label = btn.querySelector('.cmd-label')?.textContent.trim() || btn.dataset.command;
                    const pane = document.getElementById('cmdOutput');
                    const out = document.getElementById('cmdOutputText');
                    pane.classList.remove('d-none');
                    out.textContent = `> ${btn.dataset.command} --novel=${btn.dataset.novel} --dry-run\nPreviewing...`;
                    btn.disabled = true;

                    let result;
                    try {
                        result = await Novarr.executeCommand({ command: btn.dataset.command, novel_id: btn.dataset.novel, dry_run: 1 });
                    } catch (err) {
                        result = { success: false, message: err.message };
                    } finally {
                        btn.disabled = false;
                    }

                    const text = result.output || result.error || result.message || '';
                    out.textContent = `> ${btn.dataset.command} --novel=${btn.dataset.novel} --dry-run\n${text}`;
                    if (!result.success) {
                        Novarr.showToast(`${label}: dry run failed — nothing was changed.`, 'danger');
                        return;
                    }

                    const summary = text.split('\n').map(l => l.trim()).filter(Boolean).slice(-3).join(' · ');
                    const ok = await Novarr.confirmDialog(
                        `Dry run finished (output shown above the chapter list). ${summary} — apply these changes for real?`,
                        { title: label, confirmText: 'Apply', danger: true }
                    );
                    if (!ok) return;
                    btn.dataset.confirmed = '1';
                    btn.click();
                }, true);
            })();
            </script>
        </div>
    </div>
</details>
@endsection
@push('scripts')
<script>
(() => {
    // Buttons that stand in for a header-menu action (e.g. "Refresh metadata"
    // in the no-synopsis note) click the real one, so it runs in one place.
    document.querySelectorAll('[data-proxy-click]').forEach(el => el.addEventListener('click', () => {
        document.querySelector(el.dataset.proxyClick)?.click();
    }));

    // Synopsis read-more: only show the toggle when the text actually clamps.
    const synopsisBody = document.getElementById('synopsisBody');
    const synopsisToggle = document.getElementById('synopsisToggle');

    if (synopsisBody && synopsisToggle) {
        if (synopsisBody.scrollHeight > synopsisBody.clientHeight + 2) {
            synopsisToggle.classList.remove('d-none');
        }

        synopsisToggle.addEventListener('click', () => {
            const expanded = synopsisBody.classList.toggle('expanded');
            synopsisToggle.textContent = expanded ? 'Read less' : 'Read more';
            synopsisToggle.setAttribute('aria-expanded', expanded);
        });
    }

    document.querySelectorAll('button.cmd-btn').forEach(btn => {
        btn.addEventListener('click', () => runCommand(btn));
    });

    const deleteBtn = document.getElementById('deleteNovel');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
            const ok = await Novarr.confirmDialog(
                `Delete "${deleteBtn.dataset.name}" and all of its chapters? This cannot be undone from the UI.`,
                { title: 'Delete novel', confirmText: 'Delete', danger: true }
            );
            if (!ok) return;
            deleteBtn.disabled = true;
            try {
                const response = await fetch(`/novels/${deleteBtn.dataset.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    window.location.href = @json($libraryUrl);
                } else {
                    deleteBtn.disabled = false;
                    Novarr.showToast('Failed to delete novel.', 'danger');
                }
            } catch (err) {
                deleteBtn.disabled = false;
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    }

    const pauseToggle = document.getElementById('pauseToggle');
    if (pauseToggle) {
        pauseToggle.addEventListener('click', async () => {
            pauseToggle.disabled = true;
            try {
                const response = await fetch(`/novels/${pauseToggle.dataset.id}/toggle-pause`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    // Update the menu item + status badge in place (no reload).
                    pauseToggle.querySelector('.pause-label').textContent = data.paused ? 'Resume downloads' : 'Pause downloads';
                    pauseToggle.querySelector('.pause-icon-pause')?.classList.toggle('d-none', data.paused);
                    pauseToggle.querySelector('.pause-icon-play')?.classList.toggle('d-none', !data.paused);

                    // Same classes the x-status component (as="text") renders for NovelState::Paused / ::Active.
                    const badge = document.getElementById('novelStatusBadge');
                    if (badge && !badge.dataset.completed) {
                        badge.className = data.paused ? 'tone-muted' : 'tone-success';
                        badge.dataset.state = data.paused ? 'paused' : 'active';
                        badge.textContent = data.paused ? 'Paused' : 'Active';
                    }
                    Novarr.showToast(data.paused ? 'Downloads paused.' : 'Downloads resumed.', 'success');
                } else {
                    Novarr.showToast('Failed to update pause state.', 'danger');
                }
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                pauseToggle.disabled = false;
            }
        });
    }

    // Hourly checks switch: optimistic, reverts if the request fails.
    const frequentToggle = document.getElementById('frequentToggle');
    if (frequentToggle) {
        frequentToggle.addEventListener('change', async () => {
            const wanted = frequentToggle.checked;
            frequentToggle.disabled = true;
            try {
                const response = await fetch(`/novels/${frequentToggle.dataset.id}/toggle-frequent`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    frequentToggle.checked = !!data.frequent;
                    Novarr.showToast(data.frequent ? 'This novel is now checked hourly for new chapters.' : 'Back to the daily check.', 'success');
                } else {
                    frequentToggle.checked = !wanted;
                    Novarr.showToast('Could not change hourly checks.', 'danger');
                }
            } catch (err) {
                frequentToggle.checked = !wanted;
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                frequentToggle.disabled = false;
            }
        });
    }

    // ---- Tag editing ----
    const editTags = document.getElementById('editTags');
    if (editTags) {
        const tagDisplay = document.getElementById('tagDisplay');
        const tagEditor = document.getElementById('tagEditor');
        const showEditor = (on) => {
            tagDisplay.classList.toggle('d-none', on);
            tagEditor.classList.toggle('d-none', !on);
            if (on) tagEditor.querySelector('.tag-picker-toggle')?.focus();
        };
        editTags.addEventListener('click', () => showEditor(true));
        document.getElementById('cancelTags').addEventListener('click', () => showEditor(false));

        // Rebuild the tag chips in place from the saved tag list.
        const tagBase = @json($libraryUrl);
        function renderTags(tags) {
            const list = document.getElementById('tagList');
            list.innerHTML = '';
            if (!tags || !tags.length) {
                const none = document.createElement('span');
                none.className = 'tag-empty';
                none.textContent = 'No tags';
                list.appendChild(none);
                return;
            }
            tags.forEach(t => {
                const a = document.createElement('a');
                a.href = `${tagBase}&tag=${t.id}`;
                a.className = 'nv-tag';
                a.textContent = t.name;
                list.appendChild(a);
            });
        }

        document.getElementById('saveTags').addEventListener('click', async (e) => {
            const btn = e.target;
            btn.disabled = true;
            try {
                const tagIds = [...document.querySelectorAll('#tagEditor input[name="tags[]"]:checked')].map(c => c.value);
                const response = await fetch(`/novels/${btn.dataset.id}/tags`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ tags: tagIds }),
                });
                const data = await response.json();
                if (data.success) {
                    renderTags(data.tags);
                    showEditor(false);
                    Novarr.showToast('Tags saved.', 'success');
                } else {
                    Novarr.showToast('Failed to save tags.', 'danger');
                }
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                btn.disabled = false;
            }
        });
    }

    // ---- Remove duplicate chapters ----
    const removeDupes = document.getElementById('removeDupes');
    if (removeDupes) {
        removeDupes.addEventListener('click', async () => {
            const ok = await Novarr.confirmDialog(
                'Remove duplicate chapters, keeping the best copy of each?',
                { title: 'Remove duplicates', confirmText: 'Remove' }
            );
            if (!ok) return;
            removeDupes.disabled = true;
            try {
                const response = await fetch(`/novels/${removeDupes.dataset.id}/remove-duplicates`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    Novarr.showToast(`Removed ${data.removed} duplicate chapter(s).`, 'success');
                    Novarr.softRefresh(1000);
                } else {
                    removeDupes.disabled = false;
                    Novarr.showToast('Failed to remove duplicates.', 'danger');
                }
            } catch (err) {
                removeDupes.disabled = false;
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    }

    // ---- Download for offline (PWA), with range options ----
    function initOfflineBtn() {
        const wrap = document.getElementById('offlineControls');
        if (!wrap || !window.Novarr?.downloadNovel) return;

        const id = parseInt(wrap.dataset.id, 10);
        const btn = document.getElementById('offlineBtn');
        const removeBtn = document.getElementById('offlineRemove');
        const label = btn.querySelector('.offline-btn-label');
        const count = btn.querySelector('.offline-btn-count');

        // Fill the option counts from the page's stats.
        wrap.querySelectorAll('.offl-total').forEach(e => e.textContent = wrap.dataset.total || '0');
        wrap.querySelectorAll('.offl-unread').forEach(e => e.textContent = wrap.dataset.unread || '0');

        async function reflect() {
            const rec = await Novarr.getNovel(id);
            label.textContent = 'Download for offline';
            count.textContent = rec ? `${rec.chapterCount} offline` : '';
            count.classList.toggle('d-none', !rec);
            btn.title = rec ? `${rec.chapterCount} chapters saved on this device` : 'Save for offline or export';
            removeBtn.classList.toggle('d-none', !rec);
        }
        reflect();

        function closeMenu() {
            window.bootstrap?.Dropdown.getOrCreateInstance(btn).hide();
        }

        async function run(opts) {
            closeMenu();
            btn.disabled = true;
            btn.classList.add('disabled');
            try {
                const r = await Novarr.downloadNovel(id, opts, (done, total) => {
                    label.textContent = `Saving ${done}/${total}…`;
                });
                Novarr.showToast(`Saved ${r.addedCount} chapter(s) for offline (${r.cachedCount} total).`, 'success');
            } catch (err) {
                Novarr.showToast('Download failed: ' + err.message, 'danger');
            } finally {
                btn.disabled = false;
                btn.classList.remove('disabled');
                reflect();
            }
        }

        wrap.querySelectorAll('[data-scope]').forEach(el => el.addEventListener('click', () => {
            const scope = el.dataset.scope;
            if (scope === 'range') {
                const from = document.getElementById('offlFrom').value.trim();
                const to = document.getElementById('offlTo').value.trim();
                if (!from && !to) {
                    Novarr.showToast('Enter a “from” and/or “to” chapter number.', 'warning');
                    return;
                }
                run({ scope: 'range', from, to });
            } else if (scope === 'unread-next') {
                run({ scope: 'unread-next', limit: parseInt(el.dataset.limit, 10) || 100 });
            } else {
                run({ scope });
            }
        }));

        removeBtn.addEventListener('click', async () => {
            removeBtn.disabled = true;
            try {
                await Novarr.removeNovel(id);
                Novarr.showToast('Removed offline copy.', 'info');
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                removeBtn.disabled = false;
                reflect();
            }
        });
    }

    // window.Novarr is set by the deferred app.js module, which runs after this
    // inline script on a hard load but is already present on Turbo visits.
    if (window.Novarr?.downloadNovel) initOfflineBtn();
    else window.addEventListener('load', initOfflineBtn, { once: true });

    async function runCommand(btn) {
        if (btn.disabled) return;

        const command = btn.dataset.command;
        const novelId = btn.dataset.novel;
        const outputText = document.getElementById('cmdOutputText');

        setButtonState(btn, 'running');
        document.getElementById('cmdOutput').classList.remove('d-none');
        outputText.textContent = `> ${command} --novel=${novelId}\nRunning...`;

        // Commands that change the chapter list or stats shown on this page —
        // reload after they finish so the page reflects the new data.
        const reloadAfter = ['toc', 'chapter', 'metadata', 'normalize_labels', 'fix_chapters', 'clean_content', 'chapter_cleaner'];

        try {
            const result = await Novarr.executeCommand({ command, novel_id: novelId });
            setButtonState(btn, result.success ? 'done' : 'fail');
            outputText.textContent = `> ${command} --novel=${novelId}\n${result.output || result.error || 'Done'}`;
            outputText.scrollTop = outputText.scrollHeight;

            if (result.success && reloadAfter.includes(command)) {
                Novarr.showToast('Done — refreshing…', 'success');
                Novarr.softRefresh(1200);
            }
        } catch (err) {
            setButtonState(btn, 'fail');
            outputText.textContent = `> ${command}\nError: ${err.message}`;
            Novarr.showToast(err.message, 'danger');
        }
    }

    // State is carried by a modifier class (styled in _views.scss) rather than
    // by rewriting className, so the button keeps its size and layout classes.
    function setButtonState(btn, state) {
        const show = cls => btn.querySelector(cls)?.classList.remove('d-none');
        const hide = cls => btn.querySelector(cls)?.classList.add('d-none');

        ['.cmd-label', '.cmd-spinner', '.cmd-done', '.cmd-fail'].forEach(hide);
        btn.classList.remove('cmd-state-done', 'cmd-state-fail');

        if (state === 'running') {
            show('.cmd-spinner');
            btn.disabled = true;
            return;
        }

        btn.disabled = false;
        show(state === 'done' ? '.cmd-done' : '.cmd-fail');
        btn.classList.add(state === 'done' ? 'cmd-state-done' : 'cmd-state-fail');

        setTimeout(() => {
            ['.cmd-done', '.cmd-fail'].forEach(hide);
            show('.cmd-label');
            btn.classList.remove('cmd-state-done', 'cmd-state-fail');
        }, 4000);
    }
})();
</script>
@endpush
