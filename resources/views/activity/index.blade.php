@extends('layouts.app')

@section('title', 'Activity')

@section('content')
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Downloads &amp; queue</span>
        <h1 class="page-title mb-0">Activity</h1>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('health.index') }}" class="btn btn-secondary">Health</a>
        <a href="{{ route('commands.index') }}" class="btn btn-secondary">Commands</a>
    </div>
</div>

{{-- What is downloading right now, per source; refreshed every 10 s
     while the tab is visible. --}}
<section class="dash-panel mb-4" id="downloads-section" aria-labelledby="downloadsTitle"
         data-refresh-url="{{ route('activity.downloads') }}" data-refresh-every="10">
    @include('activity._downloads', ['downloads' => $downloads])
</section>

{{-- The numbers the old dashboard stat tiles carried, as one mono line. --}}
<ul class="activity-summary" aria-label="Library summary">
    {{-- Per library: each figure opens the library that lists exactly those
         series. The Manga figures only appear once there is a manga. --}}
    @foreach(['novel' => 'novels', 'manga' => 'manga'] as $mKey => $mWord)
        @php $m = $stats['by_medium'][$mKey] ?? ['active' => 0, 'completed' => 0]; @endphp
        @continue($mKey === 'manga' && $m['active'] + $m['completed'] === 0)
        <li><a href="{{ route('novels.index', ['medium' => $mKey, 'status' => 0]) }}" data-summary="active-{{ $mKey }}"><span class="home-mono">{{ number_format($m['active']) }}</span> <x-status state="active" as="text">active {{ $mWord }}</x-status></a></li>
        <li><a href="{{ route('novels.index', ['medium' => $mKey, 'status' => 1]) }}" data-summary="completed-{{ $mKey }}"><span class="home-mono">{{ number_format($m['completed']) }}</span> completed {{ $mWord }}</a></li>
    @endforeach
    <li><a href="#queued-section"><span class="home-mono">{{ number_format($stats['pending']) }}</span> <x-status state="queued" as="text">chapters queued</x-status></a></li>
    <li><a href="#recent-section"><span class="home-mono">{{ number_format($stats['downloaded_today']) }}</span> downloaded in 24 h</a></li>
    <li><a href="{{ route('health.index') }}#attentionPanel"><span class="home-mono">{{ number_format($attention_count) }}</span> <x-status :state="$attention_count > 0 ? 'attention' : 'paused'" as="text">need attention</x-status></a></li>
</ul>

@if(($snoozed ?? collect())->isNotEmpty())
    <div class="activity-snoozed">@include('partials.snoozed-note', ['snoozed' => $snoozed])</div>
@endif

<div class="dash-grid">
    {{-- Queued chapters: indexed, waiting to be downloaded. Cyan, the
         "queued" triad colour: a backlog is not a failure. --}}
    <section class="dash-panel" id="queued-section" aria-labelledby="queuedTitle">
        <div class="dash-panel-head">
            <h2 class="dash-panel-title" id="queuedTitle">Queued chapters</h2>
            <div class="dash-panel-tools">
                <span class="chip-mono {{ $missing_chapters->total() > 0 ? 'chip-mono-pending' : '' }}" title="Chapters waiting to be downloaded">{{ number_format($missing_chapters->total()) }}</span>
            </div>
        </div>
        @if($missing_chapters->count() > 0)
            <div class="table-responsive">
                <table class="table table-hover table-compact align-middle">
                    <thead>
                        <tr>
                            <th>Series</th>
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
            <p class="dash-panel-empty mb-0">Nothing queued: every indexed chapter is downloaded.</p>
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
                            <th>Series</th>
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
@include('partials.refresh-sections')
@endpush
