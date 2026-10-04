@extends('layouts.app')

@section('title', $data->name)

@section('content')
<a href="{{ route('novels.index') }}" class="back-link">
    <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Library
</a>

{{-- ===================================================================== --}}
{{-- Hero — cover, identity, actions, meters                               --}}
{{-- ===================================================================== --}}
<div class="detail-hero">
    <div class="detail-cover-wrap">
        @if($data->file)
            <img src="{{ Storage::url($data->file->file_path) }}" alt="Cover of {{ $data->name }}" class="detail-cover">
        @else
            <div class="detail-cover-placeholder" aria-hidden="true">
                <x-brand-mark variant="mono" :size="34" />
            </div>
        @endif
        @if($originChip = $data->originChip())
            {{-- Same micro-label as the <x-cover> chip on Library tiles --}}
            <span class="cover-chip" aria-hidden="true">{{ $originChip }}</span>
        @endif
    </div>

    <div class="detail-main">
        <div class="detail-head">
            <div class="detail-ident">
                <h1 class="detail-title">{{ $data->name }}</h1>

                <div class="detail-byline">
                    <span class="detail-author">{{ $data->author ?: 'Unknown author' }}</span>
                    @if($data->status)
                        <x-status state="completed" id="novelStatusBadge" data-completed="1" />
                    @elseif($data->paused_at)
                        <x-status state="paused" id="novelStatusBadge" title="Paused {{ $data->paused_at->format('j M Y') }} — automatic downloads skip this novel" />
                    @else
                        <x-status state="active" id="novelStatusBadge" />
                    @endif
                </div>

                @php
                    $metaItems = [];
                    if ($data->group && $data->group->label) $metaItems[] = ['key' => 'Group', 'value' => $data->group->label];
                    if ($data->language && $data->language->label) $metaItems[] = ['key' => 'Lang', 'value' => $data->language->label];
                    if ($originLabel = $data->originLabel()) $metaItems[] = ['key' => 'Origin', 'value' => $originLabel];
                @endphp
                @if($data->translator_url || count($metaItems))
                    <div class="detail-meta">
                        @if($data->translator_url)
                            <a class="detail-meta-item" href="{{ $data->translator_url }}" target="_blank" rel="noopener">
                                <span class="detail-meta-key">Source</span>{{ parse_url($data->translator_url, PHP_URL_HOST) }} <x-icon name="external-link" :size="11" />
                            </a>
                            @if(count($metaItems))<span class="detail-meta-sep">·</span>@endif
                        @endif
                        @foreach($metaItems as $i => $item)
                            <span class="detail-meta-item"><span class="detail-meta-key">{{ $item['key'] }}</span>{{ $item['value'] }}</span>
                            @if($i < count($metaItems) - 1)<span class="detail-meta-sep">·</span>@endif
                        @endforeach
                    </div>
                @endif

                @if(!$data->status)
                    {{-- Hourly checks: a switch, posting to the existing toggle endpoint --}}
                    <div class="form-check form-switch detail-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="frequentToggle" data-id="{{ $data->id }}" @checked($data->frequent_toc)>
                        <label class="form-check-label" for="frequentToggle" title="Check this novel's source for new chapters every hour instead of once a day">Hourly checks</label>
                    </div>
                @endif
            </div>

            {{-- Header actions: one primary, one Download menu, one overflow menu. --}}
            <div class="detail-actions">
                @if($continue_chapter_id)
                    <a href="{{ route('chapters.show', $continue_chapter_id) }}" class="btn btn-primary">
                        <x-icon name="book-open" :size="14" />{{ $read_count > 0 ? 'Continue reading' : 'Start reading' }}
                    </a>
                @endif
                <span id="offlineControls" data-id="{{ $data->id }}" data-total="{{ $current_chapters }}" data-unread="{{ max(0, $current_chapters - $read_count) }}" class="d-inline-flex">
                    <div class="dropdown">
                        <button type="button" id="offlineBtn" class="btn btn-secondary btn-icon-label" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                            <x-icon name="download" :size="14" />
                            <span class="offline-btn-label">Download</span>
                            <span class="offline-btn-count d-none"></span>
                            <x-icon name="chevron-down" :size="14" class="icon caret" />
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end download-menu">
                            <li><h6 class="dropdown-header label-caption">Save for offline</h6></li>
                            <li><button type="button" class="dropdown-item" data-scope="unread-next" data-limit="100">Next 100 unread</button></li>
                            <li><button type="button" class="dropdown-item" data-scope="unread">All unread (<span class="offl-unread">0</span>)</button></li>
                            <li><button type="button" class="dropdown-item" data-scope="all">All chapters (<span class="offl-total">0</span>)</button></li>
                            <li>
                                <div style="padding: 4px 12px 8px;">
                                    <div class="label-caption mb-2">Chapter range</div>
                                    <div class="d-flex gap-1 align-items-center">
                                        <input type="number" id="offlFrom" class="form-control form-control-sm" placeholder="From" min="0" step="any" style="width: 78px;" aria-label="Range from chapter">
                                        <input type="number" id="offlTo" class="form-control form-control-sm" placeholder="To" min="0" step="any" style="width: 78px;" aria-label="Range to chapter">
                                        <button type="button" class="btn btn-sm btn-secondary" data-scope="range">Get</button>
                                    </div>
                                </div>
                            </li>
                            <li><button type="button" id="offlineRemove" class="dropdown-item item-danger d-none"><x-icon name="trash-2" :size="14" />Remove offline copy</button></li>
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
                        </ul>
                    </div>
                </span>
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
                        <li><hr class="dropdown-divider"></li>
                        <li><button type="button" id="deleteNovel" class="dropdown-item item-danger" data-id="{{ $data->id }}" data-name="{{ $data->name }}"><x-icon name="trash-2" :size="14" />Delete novel</button></li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Metric strip --}}
        <div class="metric-strip">
            <div class="metric">
                <x-status state="downloaded" as="text" class="metric-value">{{ number_format($current_chapters) }}</x-status>
                <div class="metric-label">Downloaded</div>
            </div>
            <div class="metric">
                @if($current_chapters_not_downloaded > 0)
                    <x-status state="queued" as="text" class="metric-value">{{ number_format($current_chapters_not_downloaded) }}</x-status>
                @else
                    <div class="metric-value value-muted">0</div>
                @endif
                <div class="metric-label">Queued</div>
            </div>
            <div class="metric">
                @if(count($missing_chapters) > 0)
                    <x-status state="failed" as="text" class="metric-value">{{ number_format(count($missing_chapters)) }}</x-status>
                @else
                    <div class="metric-value value-muted">0</div>
                @endif
                <div class="metric-label">Missing</div>
            </div>
            <div class="metric">
                <x-status state="reading" as="text" class="metric-value">{{ number_format($read_count) }}</x-status>
                <div class="metric-label">Read</div>
            </div>
        </div>

        {{-- Meters: download progress (status colour), reading progress (always amber).
             Download progress uses the one shared definition — downloaded ÷
             chapters known to the source — so it matches the novels list. --}}
        @php
            $dl = \App\Services\NovelHealth::downloadProgress(
                (int) $current_chapters,
                (int) $current_chapters + (int) $current_chapters_not_downloaded,
                (int) ($data->no_of_chapters ?? 0)
            );
            $dlState = \App\Services\NovelHealth::progressState(
                $dl['percent'],
                (bool) $data->status,
                !$data->status && (bool) $data->paused_at,
                isset(\App\Services\NovelHealth::attentionIds()[$data->id])
            );
        @endphp
        <div>
            <div class="meter">
                <div class="meter-head">
                    <span class="meter-label">Downloaded</span>
                    <span class="meter-value">{{ number_format($dl['downloaded']) }} of {{ number_format($dl['total']) }} on source · <span data-progress-percent>{{ $dl['percent'] }}%</span></span>
                </div>
                <div class="progress progress-{{ $dlState }}" role="progressbar" aria-label="Download progress" aria-valuenow="{{ $dl['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: {{ $dl['percent'] }}%"></div>
                </div>
            </div>

            @if($current_chapters > 0)
                @php $readPct = (int) round($read_count / $current_chapters * 100); @endphp
                <div class="meter meter-reading">
                    <div class="meter-head">
                        <span class="meter-label">Read</span>
                        <span class="meter-value">{{ number_format($read_count) }} / {{ number_format($current_chapters) }} · {{ $readPct }}%</span>
                    </div>
                    <div class="progress progress-reading" role="progressbar" aria-label="Reading progress" aria-valuenow="{{ $readPct }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: {{ $readPct }}%"></div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Tags --}}
        <div>
            <div id="tagDisplay" class="tag-strip">
                <span class="tag-strip-key">Tags</span>
                <span id="tagList" class="d-flex align-items-center gap-2 flex-wrap">
                    @forelse($data->tags as $tag)
                        <a href="{{ route('novels.index', ['tag' => $tag->id]) }}" class="tag-chip">{{ $tag->name }}</a>
                    @empty
                        <span class="tag-empty">None</span>
                    @endforelse
                </span>
                <button type="button" id="editTags" class="btn btn-ghost btn-sm">Edit</button>
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
                <button type="button" class="synopsis-toggle d-none" id="synopsisToggle" aria-expanded="false">Read more</button>
            </div>
        @else
            <div class="detail-no-synopsis">
                <em>No summary available.</em>
                <span>Fetch one with <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-proxy-click="#refreshMetadataBtn">Refresh metadata</button>.</span>
            </div>
        @endif
    </div>
</div>

{{-- ===================================================================== --}}
{{-- Maintenance — one disclosure, collapsed by default. The command        --}}
{{-- buttons inside are unchanged (data-dry-run-first wiring below).        --}}
{{-- ===================================================================== --}}
<details class="card maintenance-panel mb-4" id="maintenancePanel">
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
                        `Dry run finished (output below the buttons). ${summary} — apply these changes for real?`,
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
{{-- Output pane sits outside the disclosure so header-menu commands
     (Generate ePub, Send to Kindle, Refresh metadata) show their output
     even while Maintenance is collapsed. --}}
<div id="cmdOutput" class="card mb-4 d-none">
    <pre id="cmdOutputText" class="cmd-output-pane"></pre>
</div>

{{-- ===================================================================== --}}
{{-- Chapters                                                               --}}
{{-- ===================================================================== --}}
@php
    // The Book column only earns its space when some chapter is in a book.
    $showBookCol = $chapters->contains(fn($c) => (int) $c->book !== 0);
@endphp
<div class="card chapter-panel" id="chapterPanel">
    <div class="panel-head">
        <h2 class="panel-title">Chapters <span class="count-chip">{{ number_format($chapters->total()) }}</span></h2>
        <div class="panel-tools">
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
            @if(count($duplicate_chapters) > 0)
                <button type="button" id="removeDupes" class="btn btn-warning btn-sm" data-id="{{ $data->id }}" title="{{ count($duplicate_chapters) }} duplicate chapter group(s) detected">Remove {{ count($duplicate_chapters) }} duplicate(s)</button>
            @endif
            <form method="GET" action="{{ route('novels.jump_chapter', $data->id) }}" class="d-flex gap-1">
                <input type="number" name="n" step="any" min="0" class="form-control form-control-sm" style="width: 84px;" placeholder="Ch. #" aria-label="Jump to chapter">
                <button type="submit" class="btn btn-secondary btn-sm">Go</button>
            </form>
            <form method="GET" action="{{ route('search.index') }}" class="d-flex gap-1">
                <input type="hidden" name="novel" value="{{ $data->id }}">
                <input type="search" name="q" minlength="2" class="form-control form-control-sm" style="width: 150px;" placeholder="Search in novel…" aria-label="Search within this novel">
                <button type="submit" class="btn btn-secondary btn-sm">Find</button>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover chapter-table align-middle" id="chapterTable">
            <thead>
                <tr>
                    <th style="width: 46px"><input type="checkbox" id="chSelectAll" class="form-check-input" aria-label="Select all chapters"></th>
                    <th style="width: 84px">Ch.</th>
                    @if($showBookCol)
                        <th style="width: 60px">Book</th>
                    @endif
                    <th>Title</th>
                    <th style="width: 118px">Status</th>
                    <th style="width: 150px">Downloaded</th>
                </tr>
            </thead>
            <tbody>
                @forelse($chapters as $chapter)
                    <tr class="chapter-row {{ $chapter->status ? 'is-downloaded' : 'is-queued' }}">
                        <td class="ch-select"><input type="checkbox" class="form-check-input ch-check" value="{{ $chapter->id }}" aria-label="Select chapter {{ $chapter->chapter }}"></td>
                        <td class="ch-num"><span class="ch-num-prefix">Ch. </span>{{ $chapter->chapter }}</td>
                        @if($showBookCol)
                            <td class="ch-book"><span class="ch-num-prefix">Book </span>{{ $chapter->book ?: '—' }}</td>
                        @endif
                        <td class="ch-title">
                            @if($chapter->read_at)
                                <span class="read-check" title="Read {{ $chapter->read_at->format('Y-m-d H:i') }}"><x-icon name="check" :size="13" :stroke="2.25" /><span class="visually-hidden">Read</span></span>
                            @endif
                            @if($chapter->status)
                                <a href="{{ route('chapters.show', $chapter->id) }}" class="chapter-link {{ $chapter->read_at ? 'text-muted' : '' }}">{{ Str::limit($chapter->label, 90) }}</a>
                            @else
                                <span class="text-muted">{{ Str::limit($chapter->label, 90) }}</span>
                            @endif
                            @if($chapter->isNote())
                                <x-status state="paused" class="ms-1" title="Author's note — a message from the author or translator, not a story chapter">Note</x-status>
                            @endif
                            {{-- attempts may not be selected/migrated yet; ?? keeps this safe either way --}}
                            @if((int) ($chapter->attempts ?? 0) >= \App\NovelChapter::REVIEW_ATTEMPTS)
                                <x-status state="paused" class="ms-1" title="Download failed {{ (int) ($chapter->attempts ?? 0) }} times — still retried every 3 days, but it needs a look{{ !empty($chapter->last_failure_reason) ? ' (last: ' . $chapter->last_failure_reason . ')' : '' }}">Needs review</x-status>
                            @endif
                        </td>
                        <td class="ch-status">
                            <x-status :state="\App\Enums\NovelState::forChapter($chapter)" />
                        </td>
                        <td class="ch-date">{{ $chapter->download_date ? $chapter->download_date->format('Y-m-d H:i') : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showBookCol ? 6 : 5 }}" class="text-center text-muted py-4">No chapters found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($chapters->hasPages())
        <div class="card-footer">
            {{ $chapters->links() }}
        </div>
    @endif
</div>
<template id="checkIconTpl"><x-icon name="check" :size="13" :stroke="2.25" /></template>
@endsection

@push('scripts')
<script>
(() => {
    // Lucide "check", cloned from the server-rendered icon template below.
    const CHECK_ICON = document.getElementById('checkIconTpl')?.innerHTML.trim() || '';

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
                    window.location.href = '{{ route('novels.index') }}';
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

                    // Same classes the x-status component renders for NovelState::Paused / ::Active.
                    const badge = document.getElementById('novelStatusBadge');
                    if (badge && !badge.dataset.completed) {
                        badge.className = 'badge ' + (data.paused ? 'badge-paused' : 'badge-active');
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
        const tagBase = '{{ route('novels.index') }}';
        function renderTags(tags) {
            const list = document.getElementById('tagList');
            list.innerHTML = '';
            if (!tags || !tags.length) {
                const none = document.createElement('span');
                none.className = 'tag-empty';
                none.textContent = 'None';
                list.appendChild(none);
                return;
            }
            tags.forEach(t => {
                const a = document.createElement('a');
                a.href = `${tagBase}?tag=${t.id}`;
                a.className = 'tag-chip';
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

    // ---- Chapter bulk read/unread ----
    const chChecks = () => [...document.querySelectorAll('.ch-check')];
    const chSelected = () => chChecks().filter(c => c.checked).map(c => c.value);
    const chBulkBar = document.getElementById('chBulkBar');
    const chSelectAll = document.getElementById('chSelectAll');

    function refreshChBulk() {
        const n = chSelected().length;
        chBulkBar.classList.toggle('d-none', n === 0);
        chBulkBar.classList.toggle('d-flex', n > 0);
        document.getElementById('chBulkCount').textContent = `${n} selected`;
        if (chSelectAll) {
            chSelectAll.checked = n > 0 && n === chChecks().length;
            chSelectAll.indeterminate = n > 0 && n < chChecks().length;
        }
    }

    chChecks().forEach(c => c.addEventListener('change', refreshChBulk));

    // Phones: "Select" reveals the row checkboxes; turning it off clears them.
    const chPanel = document.getElementById('chapterPanel');
    const chSelectToggle = document.getElementById('chSelectToggle');
    chSelectToggle?.addEventListener('click', () => {
        const on = !chPanel.classList.contains('is-selecting');
        chPanel.classList.toggle('is-selecting', on);
        chSelectToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        chSelectToggle.textContent = on ? 'Done' : 'Select';
        if (!on) {
            chChecks().forEach(c => c.checked = false);
            refreshChBulk();
        }
    });
    chSelectAll?.addEventListener('change', () => {
        chChecks().forEach(c => c.checked = chSelectAll.checked);
        refreshChBulk();
    });

    // Toggle a chapter row's read indicator (amber check + muted title) in place,
    // so the long paginated table keeps its scroll position after a bulk action.
    function setChapterRowRead(checkbox, read) {
        const cell = checkbox.closest('tr')?.querySelector('td.ch-title');
        if (!cell) return;

        let mark = cell.querySelector('.read-check');
        if (read && !mark) {
            mark = document.createElement('span');
            mark.className = 'read-check';
            mark.title = 'Read';
            mark.innerHTML = CHECK_ICON + '<span class="visually-hidden">Read</span>';
            cell.insertBefore(mark, cell.firstChild);
        } else if (!read && mark) {
            mark.remove();
        }
        cell.querySelector('.chapter-link')?.classList.toggle('text-muted', read);
    }

    async function bulkRead(read) {
        const boxes = chChecks().filter(c => c.checked);
        const ids = boxes.map(c => c.value);
        if (!ids.length) return;
        try {
            const response = await fetch('{{ route('chapters.bulk_read') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ ids, read }),
            });
            const data = await response.json();
            if (data.success) {
                boxes.forEach(c => { setChapterRowRead(c, read); c.checked = false; });
                refreshChBulk();
                Novarr.showToast(
                    data.queued
                        ? 'Saved offline — will sync when you reconnect.'
                        : `Marked ${ids.length} chapter(s) as ${read ? 'read' : 'unread'}.`,
                    data.queued ? 'info' : 'success'
                );
            } else {
                Novarr.showToast(data.message || 'Failed to update chapters.', 'danger');
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }

    document.getElementById('chMarkRead')?.addEventListener('click', () => bulkRead(true));
    document.getElementById('chMarkUnread')?.addEventListener('click', () => bulkRead(false));

    // Scoped variants: whole novel, or everything up to / from an anchor
    // chapter. Server updates all pages; the DOM update below only needs to
    // cover the rows visible on this page.
    async function bulkReadScope(scope, read) {
        let anchorId = null;
        if (scope === 'up_to' || scope === 'from') {
            const sel = chSelected();
            if (sel.length !== 1) {
                Novarr.showToast('Select exactly one chapter as the starting point first.', 'warning');
                return;
            }
            anchorId = parseInt(sel[0], 10);
        }

        if (scope === 'all') {
            const ok = await Novarr.confirmDialog(
                `Mark ALL chapters as ${read ? 'read' : 'unread'}?`,
                { title: read ? 'Mark all read' : 'Mark all unread', confirmText: 'Continue', danger: !read }
            );
            if (!ok) return;
        }

        try {
            const response = await fetch('{{ route('chapters.bulk_read') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ read, scope, novel_id: {{ $data->id }}, anchor_id: anchorId }),
            });
            const data = await response.json();
            if (!data.success) {
                Novarr.showToast(data.message || 'Failed to update chapters.', 'danger');
                return;
            }

            const boxes = chChecks();
            let affected = boxes;
            if (anchorId !== null) {
                const idx = boxes.findIndex(c => parseInt(c.value, 10) === anchorId);
                affected = scope === 'up_to' ? boxes.slice(0, idx + 1) : boxes.slice(idx);
            }
            affected.forEach(c => setChapterRowRead(c, read));
            boxes.forEach(c => c.checked = false);
            refreshChBulk();
            Novarr.showToast(
                data.queued
                    ? 'Saved offline — will sync when you reconnect.'
                    : `Marked ${data.count} chapter(s) as ${read ? 'read' : 'unread'}.`,
                data.queued ? 'info' : 'success'
            );
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }

    document.getElementById('chReadUpTo')?.addEventListener('click', () => bulkReadScope('up_to', true));
    document.getElementById('chReadFrom')?.addEventListener('click', () => bulkReadScope('from', true));
    document.getElementById('chReadAll')?.addEventListener('click', () => bulkReadScope('all', true));
    document.getElementById('chUnreadAll')?.addEventListener('click', () => bulkReadScope('all', false));

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
            label.textContent = 'Download';
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
