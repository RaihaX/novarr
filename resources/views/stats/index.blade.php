@extends('layouts.app')

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

{{-- Daily chart --}}
@php
    $max = max(1, max(array_column($daily, 'chapters')));
    $w = 900; $h = 200; $padL = 34; $padB = 22; $padT = 8;
    $plotW = $w - $padL - 8; $plotH = $h - $padT - $padB;
    $n = count($daily);
    $step = $plotW / $n;
    $barW = max(4, $step - 2); // 2px surface gap between bars
    $maxIdx = array_search($max, array_column($daily, 'chapters'));
@endphp
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Chapters read per day</h2>
        <span class="chart-note">{{ number_format($window_chapters) }} chapters · &asymp;{{ number_format($window_words) }} words · {{ $active_days }}/{{ $window_days }} active days</span>
    </div>
    <div class="card-body">
        <svg viewBox="0 0 {{ $w }} {{ $h }}" width="100%" role="img" aria-label="Bar chart of chapters read per day over the last {{ $window_days }} days" class="stats-chart">
            {{-- recessive gridlines + y labels --}}
            @foreach([0, 0.5, 1] as $f)
                @php $y = $padT + $plotH - $f * $plotH; @endphp
                <line x1="{{ $padL }}" y1="{{ $y }}" x2="{{ $w - 8 }}" y2="{{ $y }}" class="grid-line"/>
                <text x="{{ $padL - 6 }}" y="{{ $y + 4 }}" text-anchor="end" class="axis-label">{{ round($f * $max) }}</text>
            @endforeach

            @foreach($daily as $i => $day)
                @php
                    $bh = $day['chapters'] > 0 ? max(3, ($day['chapters'] / $max) * $plotH) : 0;
                    $x = $padL + $i * $step + 1;
                    $y = $padT + $plotH - $bh;
                @endphp
                <g class="chart-bar">
                    @if($bh > 0)
                        {{-- Square ends: bars follow the flat-bar rule of the system. --}}
                        <rect class="bar-fill" x="{{ $x }}" y="{{ $y }}" width="{{ $barW }}" height="{{ $bh }}"/>
                    @endif
                    {{-- oversized invisible hit target + hover value --}}
                    <rect x="{{ $padL + $i * $step }}" y="{{ $padT }}" width="{{ $step }}" height="{{ $plotH }}" fill="transparent">
                        <title>{{ $day['label'] }}: {{ $day['chapters'] }} chapter{{ $day['chapters'] === 1 ? '' : 's' }}@if($day['words'] > 0) (&asymp;{{ number_format($day['words']) }} words)@endif</title>
                    </rect>
                    <text x="{{ $padL + $i * $step + $step / 2 }}" y="{{ max($padT + 10, $y - 5) }}" text-anchor="middle" class="bar-value {{ $i === $maxIdx && $day['chapters'] > 0 ? 'always' : '' }}">{{ $day['chapters'] }}</text>
                </g>
                @if($i % 5 === 0 || $i === $n - 1)
                    <text x="{{ $padL + $i * $step + $step / 2 }}" y="{{ $h - 6 }}" text-anchor="middle" class="axis-label">{{ $day['label'] }}</text>
                @endif
            @endforeach
        </svg>

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
