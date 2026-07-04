@extends('layouts.app')

@section('content')
<h1 class="mb-4">Reading Stats</h1>

{{-- Tiles --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value text-success">{{ number_format($streak) }}<small class="text-muted" style="font-size: 0.5em;"> day{{ $streak === 1 ? '' : 's' }}</small></div>
                <div class="dash-stat-label">Reading streak</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value text-primary">{{ number_format($read_today) }}</div>
                <div class="dash-stat-label">Chapters today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value text-info">{{ number_format($read_week) }}</div>
                <div class="dash-stat-label">Chapters this week</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value">{{ number_format($read_total) }}</div>
                <div class="dash-stat-label">Chapters all-time (&asymp;{{ $words_total >= 1000000 ? round($words_total / 1000000, 1) . 'M' : number_format($words_total) }} words)</div>
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
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Chapters read per day — last {{ $window_days }} days</h5>
        <span class="text-muted" style="font-size: 13px;">{{ number_format($window_chapters) }} chapters · &asymp;{{ number_format($window_words) }} words · {{ $active_days }}/{{ $window_days }} active days</span>
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
                    $r = min(4, $barW / 2, $bh); // rounded top data-end, flat baseline
                @endphp
                <g class="chart-bar">
                    @if($bh > 0)
                        <path d="M{{ $x }} {{ $padT + $plotH }} V{{ $y + $r }} Q{{ $x }} {{ $y }} {{ $x + $r }} {{ $y }} H{{ $x + $barW - $r }} Q{{ $x + $barW }} {{ $y }} {{ $x + $barW }} {{ $y + $r }} V{{ $padT + $plotH }} Z" fill="#3987e5"/>
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

        <details class="mt-2">
            <summary class="text-muted" style="font-size: 13px; cursor: pointer;">View as table</summary>
            <div class="table-responsive mt-2" style="max-height: 260px; overflow-y: auto;">
                <table class="table table-sm table-striped mb-0" style="font-size: 13px;">
                    <thead><tr><th>Date</th><th class="text-end">Chapters</th><th class="text-end">&asymp;Words</th></tr></thead>
                    <tbody>
                        @foreach(array_reverse($daily) as $day)
                            <tr><td>{{ $day['date'] }}</td><td class="text-end">{{ $day['chapters'] }}</td><td class="text-end">{{ number_format($day['words']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</div>

{{-- Most-read novels --}}
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">Most read — last {{ $window_days }} days</h5></div>
    @if($top_novels->isEmpty())
        <div class="card-body"><p class="text-muted mb-0">No chapters read in the last {{ $window_days }} days.</p></div>
    @else
        <div class="table-responsive">
            <table class="table table-striped table-sm mb-0 align-middle">
                <thead>
                    <tr class="table-head-label">
                        <th>Novel</th>
                        <th class="text-end" style="width: 120px;">Chapters</th>
                        <th class="text-end" style="width: 160px;">Last read</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($top_novels as $row)
                        <tr>
                            <td class="text-truncate" style="max-width: 300px;">
                                <a href="{{ route('novels.show', $row->novel_id) }}" class="text-decoration-none">{{ $row->novel->name }}</a>
                            </td>
                            <td class="text-end">{{ number_format($row->chapters) }}</td>
                            <td class="text-end text-muted" style="font-size: 13px;">{{ \Carbon\Carbon::parse($row->last_read)->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<p class="text-muted" style="font-size: 12px;">Word counts are estimated from text length. Stats refresh every 15 minutes.</p>
@endsection
