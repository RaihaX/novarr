@extends('layouts.app')

@section('content')
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Library health</span>
        <h1 class="page-title mb-0">Dashboard</h1>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('novels.index') }}" class="btn btn-secondary">All novels</a>
        <a href="{{ route('novels.discover') }}" class="btn btn-primary">Add novel</a>
    </div>
</div>

{{-- Stat tiles: mono figures, micro caption labels, one hairline, no shadow.
     Label colours follow the status triad: active/downloaded = green,
     queued = cyan, completed (finished, nothing to do) = muted. --}}
<div class="stat-grid">
    <a href="{{ route('novels.index', ['status' => 0]) }}" class="stat-tile stat-tile-success">
        <span class="stat-tile-value">{{ number_format($stats['active']) }}</span>
        <span class="stat-tile-label">Active</span>
        <span class="stat-tile-foot">novels tracking</span>
    </a>
    <a href="{{ route('novels.index', ['status' => 1]) }}" class="stat-tile stat-tile-muted">
        <span class="stat-tile-value">{{ number_format($stats['completed']) }}</span>
        <span class="stat-tile-label">Completed</span>
        <span class="stat-tile-foot">novels finished</span>
    </a>
    <a href="#missing-section" class="stat-tile stat-tile-pending">
        <span class="stat-tile-value">{{ number_format($stats['pending']) }}</span>
        <span class="stat-tile-label">Pending</span>
        <span class="stat-tile-foot">chapters queued</span>
    </a>
    <a href="#recent-section" class="stat-tile stat-tile-success">
        <span class="stat-tile-value">{{ number_format($stats['downloaded_today']) }}</span>
        <span class="stat-tile-label">Downloaded</span>
        <span class="stat-tile-foot">last 24 hours</span>
    </a>
</div>

{{-- Needs attention (handoff §4): 2px warning left border, tinted header,
     count chip, title / reason / mono source per row. --}}
@if(count($attention) > 0)
    <section class="attention-panel mb-4" id="attentionPanel" aria-labelledby="attentionTitle">
        <div class="attention-header">
            <x-icon name="triangle-alert" :size="16" class="icon attention-icon" />
            <span class="attention-title" id="attentionTitle">Needs attention</span>
            <span class="chip-count" id="attentionCount">{{ count($attention) }}</span>
        </div>
        <div class="attention-list">
            @foreach($attention as $item)
                @php
                    $host = !empty($item['url']) ? parse_url($item['url'], PHP_URL_HOST) : null;
                    $host = $host ? preg_replace('/^www\./', '', $host) : null;
                @endphp
                <div class="attention-row">
                    <div class="attention-row-body">
                        <a href="{{ route('novels.show', $item['id']) }}" class="attention-row-title">{{ $item['name'] }}</a>
                        <span class="attention-row-reason">{{ $item['reason'] }}</span>
                        <span class="attention-row-source">{{ $host ?? 'no source url configured' }}</span>
                    </div>
                    <div class="attention-row-actions">
                        @if(!empty($item['url']))
                            <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="btn btn-outline-warning">Test source ↗</a>
                        @endif
                        <button type="button" class="btn btn-secondary snooze-btn" data-id="{{ $item['id'] }}" data-url="{{ route('novels.attention_snooze', $item['id']) }}" title="Hide from this panel for 7 days. Downloads keep running.">Snooze 7 days</button>
                    </div>
                </div>
            @endforeach
        </div>
        @include('partials.snoozed-note', ['snoozed' => $snoozed ?? collect()])
    </section>
@elseif(($snoozed ?? collect())->isNotEmpty())
    <div class="mb-4">@include('partials.snoozed-note', ['snoozed' => $snoozed])</div>
@endif

{{-- Continue reading --}}
@if(count($continue_reading) > 0)
    <section class="dash-panel mb-4" id="continue-section" aria-labelledby="continueTitle">
        <div class="dash-panel-head">
            <x-icon name="book-open" :size="16" class="icon icon-amber" />
            <h2 class="dash-panel-title" id="continueTitle">Continue reading</h2>
            <div class="dash-panel-tools">
                <span class="chip-mono">{{ count($continue_reading) }}</span>
            </div>
        </div>
        <div class="dash-panel-body">
            <div class="continue-row">
                @foreach($continue_reading as $item)
                    @php
                        $novel = $item['novel'];
                        $resume = !empty($item['resume']);
                        $pct = $resume ? (int) ($item['next']->read_progress ?? 0) : 0;
                    @endphp
                    <a href="{{ route('chapters.show', $item['next']->id) }}" class="continue-card" title="Continue {{ $novel->name }} — Ch. {{ $item['next']->chapter }}">
                        <div class="poster-cover">
                            @if($novel->file)
                                <img src="{{ Storage::url($novel->file->file_path) }}" alt="Cover of {{ $novel->name }}" loading="lazy">
                            @else
                                <div class="poster-cover-placeholder"><span>{{ $novel->name }}</span></div>
                            @endif
                            @if($resume)
                                <div class="continue-rail" aria-hidden="true"><span style="width: {{ $pct }}%"></span></div>
                            @endif
                        </div>
                        <div class="continue-title">{{ $novel->name }}</div>
                        <div class="continue-meta">
                            @if($resume)
                                CH {{ $item['next']->chapter }} · {{ $pct }}%
                            @else
                                NEXT CH {{ $item['next']->chapter }}
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif

<div class="dash-grid">
    {{-- Missing chapters --}}
    <section class="dash-panel" id="missing-section" aria-labelledby="missingTitle">
        <div class="dash-panel-head">
            <h2 class="dash-panel-title" id="missingTitle">Missing chapters</h2>
            <div class="dash-panel-tools">
                <span class="chip-mono {{ $missing_chapters->total() > 0 ? 'chip-mono-danger' : '' }}">{{ number_format($missing_chapters->total()) }}</span>
            </div>
        </div>
        @if($missing_chapters->count() > 0)
            <div class="table-responsive">
                <table class="table table-hover table-compact align-middle">
                    <thead>
                        <tr>
                            <th>Novel</th>
                            <th class="cell-num">Ch.</th>
                            <th>Label</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($missing_chapters as $chapter)
                            <tr>
                                <td class="cell-title">
                                    @if($chapter->novel)
                                        <a href="{{ route('novels.show', $chapter->novel_id) }}">{{ $chapter->novel->name }}</a>
                                    @else
                                        <span class="text-muted">Unknown</span>
                                    @endif
                                </td>
                                <td class="cell-num">{{ $chapter->chapter }}</td>
                                <td class="cell-label">{{ $chapter->label }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="dash-panel-empty mb-0">Nothing pending — every indexed chapter is downloaded.</p>
        @endif
        @if($missing_chapters->hasPages())
            <div class="dash-panel-foot">
                <span class="dash-pager-status">PAGE {{ $missing_chapters->currentPage() }} / {{ $missing_chapters->lastPage() }}</span>
                <div class="dash-pager">
                    <a class="btn btn-secondary {{ $missing_chapters->onFirstPage() ? 'disabled' : '' }}" href="{{ $missing_chapters->appends(request()->query())->previousPageUrl() }}" aria-label="Previous page">
                        <x-icon name="chevron-left" :size="14" :stroke="1.5" />
                    </a>
                    <a class="btn btn-secondary {{ $missing_chapters->hasMorePages() ? '' : 'disabled' }}" href="{{ $missing_chapters->appends(request()->query())->nextPageUrl() }}" aria-label="Next page">
                        <x-icon name="chevron-right" :size="14" :stroke="1.5" />
                    </a>
                </div>
            </div>
        @endif
    </section>

    {{-- Recently downloaded --}}
    <section class="dash-panel" id="recent-section" aria-labelledby="recentTitle">
        <div class="dash-panel-head">
            <h2 class="dash-panel-title" id="recentTitle">Recently downloaded</h2>
            <div class="dash-panel-tools">
                <span class="chip-mono {{ $stats['downloaded_today'] > 0 ? 'chip-mono-success' : '' }}" title="Chapters downloaded in the last 24 hours">{{ number_format($stats['downloaded_today']) }} / 24H</span>
            </div>
        </div>
        @if($latest_chapters->count() > 0)
            <div class="table-responsive">
                <table class="table table-hover table-compact align-middle">
                    <thead>
                        <tr>
                            <th>Novel</th>
                            <th class="cell-num">Ch.</th>
                            <th>Label</th>
                            <th class="cell-when">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($latest_chapters as $chapter)
                            <tr>
                                <td class="cell-title">
                                    @if($chapter->novel)
                                        <a href="{{ route('novels.show', $chapter->novel_id) }}">{{ $chapter->novel->name }}</a>
                                    @else
                                        <span class="text-muted">Unknown</span>
                                    @endif
                                </td>
                                <td class="cell-num">{{ $chapter->chapter }}</td>
                                <td class="cell-label">{{ $chapter->label }}</td>
                                <td class="cell-when" title="{{ optional($chapter->download_date)->format('Y-m-d H:i') }}">{{ optional($chapter->download_date)->diffForHumans(null, true) ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="dash-panel-empty mb-0">No chapters downloaded yet.</p>
        @endif
        @if($latest_chapters->hasPages())
            <div class="dash-panel-foot">
                <span class="dash-pager-status">PAGE {{ $latest_chapters->currentPage() }}</span>
                <div class="dash-pager">
                    <a class="btn btn-secondary {{ $latest_chapters->onFirstPage() ? 'disabled' : '' }}" href="{{ $latest_chapters->appends(request()->query())->previousPageUrl() }}" aria-label="Previous page">
                        <x-icon name="chevron-left" :size="14" :stroke="1.5" />
                    </a>
                    <a class="btn btn-secondary {{ $latest_chapters->hasMorePages() ? '' : 'disabled' }}" href="{{ $latest_chapters->appends(request()->query())->nextPageUrl() }}" aria-label="Next page">
                        <x-icon name="chevron-right" :size="14" :stroke="1.5" />
                    </a>
                </div>
            </div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script>
(function(){

    const panel = document.getElementById('attentionPanel');
    const countChip = document.getElementById('attentionCount');

    // "Snooze 7 days" hides the row from the panel for a week. It never
    // pauses the novel — downloads keep running — and never navigates: the
    // row fades out in place and the count chip updates.
    document.querySelectorAll('.snooze-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            btn.disabled = true;

            try {
                const response = await fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ days: 7 }),
                });
                const data = await response.json().catch(() => ({}));

                if (response.ok && data.success) {
                    const row = btn.closest('.attention-row');
                    const finish = () => {
                        row?.remove();
                        const left = panel ? panel.querySelectorAll('.attention-row').length : 0;
                        if (countChip) countChip.textContent = left;
                        if (!left) panel?.remove();
                    };
                    if (row && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        row.classList.add('is-leaving');
                        row.addEventListener('transitionend', finish, { once: true });
                        setTimeout(finish, 400); // fallback if transitionend never fires
                    } else {
                        finish();
                    }

                    Novarr.showToast(data.message || 'Snoozed for 7 days.', 'success');
                } else {
                    btn.disabled = false;
                    Novarr.showToast(data.message || 'Could not snooze this novel.', 'danger');
                }
            } catch (err) {
                btn.disabled = false;
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    });

})();
</script>
@endpush
