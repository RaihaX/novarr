@extends('layouts.app')

@section('title', 'Stats')

@section('content')
<div class="page-toolbar">
    <h1 class="page-title mb-0">Reading stats</h1>
    <span class="mono-muted">Last {{ $window_days }} days</span>
</div>

{{-- Tiles — mono figures, caption labels. Amber marks the reading metrics. --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value value-amber">{{ number_format($streak) }}<span class="dash-stat-unit">day{{ $streak === 1 ? '' : 's' }}</span></div>
                <div class="dash-stat-label">Reading streak</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value value-amber">{{ number_format($read_today) }}</div>
                <div class="dash-stat-label">Chapters today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value">{{ number_format($read_week) }}</div>
                <div class="dash-stat-label">Chapters this week</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value">{{ number_format($read_total) }}</div>
                <div class="dash-stat-label">Chapters all-time</div>
                <div class="health-detail">&asymp;{{ $words_total >= 1000000 ? round($words_total / 1000000, 1) . 'M' : number_format($words_total) }} words</div>
            </div>
        </div>
    </div>
</div>

{{-- Daily chart — HTML axes (text never scales with the plot), integer
     ticks on a "nice" step, and a collapsed empty state when nothing was read. --}}
@php
    $counts = array_column($daily, 'chapters');
    $max = max($counts ?: [0]);
    $n = count($daily);
    // Integer ticks: step from {1, 2, 5} × 10^k so there are at most 4 bands.
    $step = 1;
    if ($max > 4) {
        $raw = $max / 4;
        $mag = 10 ** floor(log10($raw));
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $mag >= $raw) { $step = (int) ($m * $mag); break; }
        }
    }
    $top = max(1, (int) (ceil($max / $step) * $step));
    $ticks = range($top, 0, -$step);
    $maxIdx = $max > 0 ? array_search($max, $counts) : null;
    // Label every ~5th day, always the last; fewer on phones via CSS.
    $labelEvery = $n > 14 ? 5 : 1;
@endphp
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Chapters read per day</h2>
        <span class="chart-note">{{ number_format($window_chapters) }} chapters · &asymp;{{ number_format($window_words) }} words · {{ $active_days }}/{{ $window_days }} active days</span>
    </div>
    <div class="card-body">
        @if($max === 0)
            <div class="chart-empty">
                <div class="chart-empty-rule" aria-hidden="true"></div>
                <p class="mb-0">No chapters read in the last {{ $window_days }} days. Days you read will show up here as bars.</p>
            </div>
        @else
            <div class="bar-chart" role="img" aria-label="Bar chart of chapters read per day over the last {{ $window_days }} days; peak {{ $max }} on {{ $daily[$maxIdx]['label'] }}" style="--ticks: {{ count($ticks) - 1 }};">
                <div class="bar-chart-y" aria-hidden="true">
                    @foreach($ticks as $k => $t)
                        <span style="top: {{ count($ticks) > 1 ? round($k / (count($ticks) - 1) * 100, 3) : 100 }}%">{{ $t }}</span>
                    @endforeach
                </div>
                <div class="bar-chart-plot" aria-hidden="true">
                    <div class="bar-chart-grid">
                        @foreach($ticks as $t)<span></span>@endforeach
                    </div>
                    <div class="bar-chart-bars">
                        @foreach($daily as $i => $day)
                            @php $pct = $day['chapters'] > 0 ? max(1.5, $day['chapters'] / $top * 100) : 0; @endphp
                            <div class="bar-chart-col {{ $i === $maxIdx ? 'is-peak' : '' }}" title="{{ $day['label'] }}: {{ $day['chapters'] }} chapter{{ $day['chapters'] === 1 ? '' : 's' }}@if($day['words'] > 0) (&asymp;{{ number_format($day['words']) }} words)@endif">
                                @if($pct > 0)
                                    <div class="bar-chart-bar" style="height: {{ $pct }}%">
                                        <span class="bar-chart-value">{{ $day['chapters'] }}</span>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                <div></div>
                <div class="bar-chart-x" aria-hidden="true" style="grid-template-columns: repeat({{ $n }}, minmax(0, 1fr));">
                    @foreach($daily as $i => $day)
                        <span class="{{ ($i % $labelEvery === 0 || $i === $n - 1) ? 'is-shown' : '' }} {{ $i === $n - 1 ? 'is-last' : '' }}">{{ ($i % $labelEvery === 0 || $i === $n - 1) ? $day['label'] : '' }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <details class="disclosure">
            <summary>View as table</summary>
            <div class="table-responsive mt-3 table-frame" style="max-height: 260px; overflow-y: auto;">
                <table class="table data-table mb-0">
                    <thead><tr><th>Date</th><th class="text-end">Chapters</th><th class="text-end">&asymp;Words</th></tr></thead>
                    <tbody>
                        @foreach(array_reverse($daily) as $day)
                            <tr>
                                <td class="mono-muted">{{ $day['date'] }}</td>
                                <td class="num">{{ $day['chapters'] }}</td>
                                <td class="num">{{ number_format($day['words']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</div>

{{-- Most-read novels --}}
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Most read</h2>
        <span class="chart-note">Last {{ $window_days }} days</span>
    </div>
    @if($top_novels->isEmpty())
        <div class="card-body"><p class="text-muted mb-0">No chapters read in the last {{ $window_days }} days.</p></div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Novel</th>
                        <th class="text-end" style="width: 120px;">Chapters</th>
                        <th class="text-end" style="width: 170px;">Last read</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($top_novels as $row)
                        <tr>
                            <td class="text-truncate" style="max-width: 340px;">
                                <a href="{{ route('novels.show', $row->novel_id) }}">{{ $row->novel->name }}</a>
                            </td>
                            <td class="text-end"><span class="mono-figure text-amber">{{ number_format($row->chapters) }}</span></td>
                            <td class="text-end mono-muted">{{ \Carbon\Carbon::parse($row->last_read)->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<p class="page-note">Word counts are estimated from text length. Stats refresh every 15 minutes.</p>
@endsection
