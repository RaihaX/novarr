@extends('layouts.app')

@section('title', 'Stats')

{{-- Stats 2.0 (App\Services\ReaderStats; the same figures as GET /api/v1/stats).
     Charts are drawn here, server-side: HTML bar charts (stats/_bars) and
     inline SVG for the heatmap and the donut; no chart library. Every chart
     has a text alternative (aria-label or a table). --}}
@use('App\Support\StatsMath')
@php
    $h = $s['headline'];
    $rt = $s['range_totals'];
    $rangeLabel = $range === 365 ? 'the last year' : "the last {$range} days";
    $mediumTone = ['novel' => 'is-novel', 'manga' => 'is-manga', 'book' => 'is-book', 'audiobook' => 'is-audio'];
    $day = fn(string $date, string $format) => \Carbon\CarbonImmutable::parse($date)->format($format);
    $nothing = $h['chapters_read'] === 0 && $h['listening_seconds'] === 0;
@endphp

@section('content')
<div class="page-toolbar stats-toolbar">
    <div>
        <h1 class="page-title mb-0">Your stats</h1>
        <span class="mono-muted">Reading and listening · {{ $s['timezone'] }}</span>
    </div>
    <nav class="btn-group segmented stats-range" aria-label="Range">
        @foreach([30 => '30 days', 90 => '90 days', 365 => '1 year'] as $value => $text)
            <a href="{{ route('stats.index', ['range' => $value]) }}" class="btn btn-secondary {{ $range === $value ? 'active' : '' }}" @if($range === $value) aria-current="page" @endif>{{ $text }}</a>
        @endforeach
    </nav>
</div>

{{-- Headline tiles --}}
<div class="stats-tiles mb-4">
    <div class="card dash-stat stats-tile is-hero">
        <div class="card-body">
            <div class="dash-stat-value {{ $h['current_streak'] > 0 ? 'value-amber' : 'text-muted' }}">{{ number_format($h['current_streak']) }}<span class="dash-stat-unit">day{{ $h['current_streak'] === 1 ? '' : 's' }}</span></div>
            <div class="dash-stat-label">Current streak</div>
            <div class="health-detail">Best {{ number_format($h['best_streak']) }} · {{ $h['active_today'] ? 'today done' : 'nothing yet today' }}</div>
        </div>
    </div>
    <div class="card dash-stat stats-tile">
        <div class="card-body">
            <div class="dash-stat-value {{ $h['chapters_read'] > 0 ? '' : 'text-muted' }}">{{ StatsMath::compact($h['chapters_read']) }}</div>
            <div class="dash-stat-label">Chapters read</div>
            <div class="health-detail">{{ number_format($h['chapters_today']) }} today · {{ number_format($h['chapters_week']) }} this week</div>
        </div>
    </div>
    <div class="card dash-stat stats-tile">
        <div class="card-body">
            <div class="dash-stat-value {{ $h['words_read'] > 0 ? '' : 'text-muted' }}">{{ StatsMath::compact($h['words_read']) }}</div>
            <div class="dash-stat-label">Words read</div>
            <div class="health-detail">&asymp;{{ StatsMath::compact($h['words_per_day']) }} a day lately</div>
        </div>
    </div>
    <div class="card dash-stat stats-tile">
        <div class="card-body">
            <div class="dash-stat-value {{ $h['listening_seconds'] > 0 ? '' : 'text-muted' }}">{{ number_format(StatsMath::hours($h['listening_seconds']), 1) }}<span class="dash-stat-unit">h</span></div>
            <div class="dash-stat-label">Hours listened</div>
            <div class="health-detail">{{ StatsMath::duration($h['listening_seconds_today']) }} today</div>
        </div>
    </div>
    <div class="card dash-stat stats-tile">
        <div class="card-body">
            <div class="dash-stat-value {{ $h['series_finished'] > 0 ? '' : 'text-muted' }}">{{ number_format($h['series_finished']) }}</div>
            <div class="dash-stat-label">Series &amp; books finished</div>
            <div class="health-detail">{{ number_format($h['books_finished']) }} book{{ $h['books_finished'] === 1 ? '' : 's' }} · {{ number_format($h['audiobooks_finished']) }} audiobook{{ $h['audiobooks_finished'] === 1 ? '' : 's' }}</div>
        </div>
    </div>
    <div class="card dash-stat stats-tile">
        <div class="card-body">
            <div class="dash-stat-value {{ $h['chapters_per_day'] > 0 ? '' : 'text-muted' }}">{{ rtrim(rtrim(number_format($h['chapters_per_day'], 1), '0'), '.') }}<span class="dash-stat-unit">/day</span></div>
            <div class="dash-stat-label">Chapters a day</div>
            <div class="health-detail">{{ rtrim(rtrim(number_format($h['listening_minutes_per_day'], 1), '0'), '.') }} min listening a day · {{ $rt['active_days'] }}/{{ $rt['days'] }} active days</div>
        </div>
    </div>
</div>

@if($nothing)
    <div class="card mb-4"><div class="card-body">
        <div class="chart-empty">
            <div class="chart-empty-rule" aria-hidden="true"></div>
            <p class="mb-0">Read or listen to something and your stats will show up here.</p>
        </div>
    </div></div>
@endif

{{-- Activity heatmap: last 12 months, one square a day, Monday at the top. --}}
@php
    $cells = $s['heatmap']['days'];
    $weeks = array_chunk($cells, 7);
    $cell = 12; $gap = 3; $step = $cell + $gap; $left = 30; $topPad = 18;
    $width = $left + count($weeks) * $step;
    $heightSvg = $topPad + 7 * $step;
    $activeCells = count(array_filter($cells, fn($c) => $c['level'] > 0));
    $busiest = collect($cells)->sortByDesc('minutes')->first();
@endphp
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Activity</h2>
        <span class="chart-note">{{ $activeCells }} active days in the last year · darker = more time</span>
    </div>
    <div class="card-body">
        <div class="stats-heatmap-scroll" data-scroll-end>
            <svg class="stats-heatmap" width="{{ $width }}" height="{{ $heightSvg }}" viewBox="0 0 {{ $width }} {{ $heightSvg }}" role="img"
                 aria-label="Activity heatmap for the last 12 months: {{ $activeCells }} active days{{ $busiest && $busiest['minutes'] > 0 ? ', busiest ' . $day($busiest['date'], 'j M') . ' with about ' . $busiest['minutes'] . ' minutes' : '' }}">
                @foreach(['Mon' => 0, 'Wed' => 2, 'Fri' => 4] as $name => $row)
                    <text x="0" y="{{ $topPad + $row * $step + $cell - 2 }}" class="hm-label">{{ $name }}</text>
                @endforeach
                @php $lastMonth = null; @endphp
                @foreach($weeks as $w => $week)
                    @php $month = substr($week[0]['date'], 0, 7); @endphp
                    @if($month !== $lastMonth)
                        @if($w > 0 || (int) substr($week[0]['date'], 8, 2) <= 7)
                            <text x="{{ $left + $w * $step }}" y="11" class="hm-label">{{ $day($week[0]['date'], 'M') }}</text>
                        @endif
                        @php $lastMonth = $month; @endphp
                    @endif
                    @foreach($week as $d => $c)
                        <rect x="{{ $left + $w * $step }}" y="{{ $topPad + $d * $step }}" width="{{ $cell }}" height="{{ $cell }}" rx="2" class="hm-{{ $c['level'] }}"><title>{{ $day($c['date'], 'D j M Y') }}: {{ $c['chapters'] }} chapter{{ $c['chapters'] === 1 ? '' : 's' }}, ≈{{ $c['minutes'] }} min</title></rect>
                    @endforeach
                @endforeach
            </svg>
        </div>
        <div class="hm-legend" aria-hidden="true">Less @for($l = 0; $l <= 4; $l++)<span class="hm-swatch hm-{{ $l }}"></span>@endfor More</div>
    </div>
</div>

{{-- Chapters per day (per week over a year) and listening minutes. --}}
@php
    $useWeeks = $range === 365;
    $series = $useWeeks ? $s['weekly'] : $s['daily'];
    $chapterBars = array_map(fn($p) => [
        'label' => $day($useWeeks ? $p['start'] : $p['date'], 'j M'),
        'value' => $p['chapters'],
        'title' => ($useWeeks ? 'Week of ' . $day($p['start'], 'j M') : $day($p['date'], 'D j M')) . ': ' . $p['chapters'] . ' chapters, ≈' . number_format($p['words']) . ' words',
    ], $series);
    $listenBars = array_map(fn($p) => [
        'label' => $day($useWeeks ? $p['start'] : $p['date'], 'j M'),
        'value' => (int) round($p['listening_seconds'] / 60),
        'title' => ($useWeeks ? 'Week of ' . $day($p['start'], 'j M') : $day($p['date'], 'D j M')) . ': ' . StatsMath::duration($p['listening_seconds']) . ' listened',
    ], $series);
    $every = max(1, (int) ceil(count($series) / 6));
@endphp
<div class="stats-grid mb-4">
    <div class="card">
        <div class="panel-head">
            <h2 class="panel-title">Chapters per {{ $useWeeks ? 'week' : 'day' }}</h2>
            <span class="chart-note">{{ number_format($rt['chapters']) }} chapters · ≈{{ StatsMath::compact($rt['words']) }} words</span>
        </div>
        <div class="card-body">
            @include('stats._bars', ['bars' => $chapterBars, 'label' => "Bar chart of chapters read per " . ($useWeeks ? 'week' : 'day') . " over {$rangeLabel}: {$rt['chapters']} in all", 'tone' => '', 'every' => $every])
        </div>
    </div>
    <div class="card">
        <div class="panel-head">
            <h2 class="panel-title">Listening minutes per {{ $useWeeks ? 'week' : 'day' }}</h2>
            <span class="chart-note">{{ StatsMath::duration($rt['listening_seconds']) }} in {{ $rangeLabel }}</span>
        </div>
        <div class="card-body">
            @include('stats._bars', ['bars' => $listenBars, 'label' => "Bar chart of minutes listened per " . ($useWeeks ? 'week' : 'day') . " over {$rangeLabel}", 'tone' => 'is-listen', 'every' => $every])
        </div>
    </div>
</div>

{{-- By medium (donut) and genres. --}}
@php
    $mediumMinutes = array_column($s['by_medium'], 'minutes', 'medium');
    $arcs = StatsMath::donut($mediumMinutes, 80, 80, 58);
    $genreMax = max(array_column($s['top_genres'], 'minutes') ?: [0]);
@endphp
<div class="stats-grid mb-4">
    <div class="card">
        <div class="panel-head">
            <h2 class="panel-title">By medium</h2>
            <span class="chart-note">Share of time · {{ $rangeLabel }}</span>
        </div>
        <div class="card-body stats-donut-wrap">
            @if($arcs === [])
                <p class="text-muted mb-0">Nothing in {{ $rangeLabel }} yet.</p>
            @else
                <svg class="stats-donut" width="160" height="160" viewBox="0 0 160 160" role="img"
                     aria-label="Donut chart of time by medium: @foreach($s['by_medium'] as $m){{ $m['label'] }} {{ round($m['share'] * 100) }}%@if(!$loop->last), @endif @endforeach">
                    <circle cx="80" cy="80" r="58" class="donut-track"></circle>
                    @foreach($arcs as $arc)
                        <path d="{{ $arc['path'] }}" class="donut-arc {{ $mediumTone[$arc['key']] }}"></path>
                    @endforeach
                    <text x="80" y="78" text-anchor="middle" class="donut-total">{{ array_sum($mediumMinutes) >= 60 ? number_format(StatsMath::hours((int) array_sum($mediumMinutes) * 60), 1) . ' h' : array_sum($mediumMinutes) . ' min' }}</text>
                    <text x="80" y="96" text-anchor="middle" class="donut-caption">total</text>
                </svg>
            @endif
            <ul class="stats-legend">
                @foreach($s['by_medium'] as $m)
                    <li>
                        <span class="legend-dot {{ $mediumTone[$m['medium']] }}" aria-hidden="true"></span>
                        <span class="legend-name">{{ $m['label'] }}</span>
                        <span class="legend-figure">{{ round($m['share'] * 100) }}%</span>
                        <span class="legend-detail">{{ $m['medium'] === 'audiobook' ? StatsMath::duration($m['listening_seconds']) : number_format($m['chapters']) . ' ch' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
    <div class="card">
        <div class="panel-head">
            <h2 class="panel-title">Top genres</h2>
            <span class="chart-note">By time · {{ $rangeLabel }}</span>
        </div>
        <div class="card-body">
            @if($s['top_genres'] === [])
                <p class="text-muted mb-0">Genres show up once you read tagged series.</p>
            @else
                <ol class="stats-hbars">
                    @foreach($s['top_genres'] as $g)
                        <li>
                            <span class="hbar-name">{{ $g['name'] }}</span>
                            <span class="hbar-track" aria-hidden="true"><span class="hbar-fill" style="width: {{ $genreMax > 0 ? max(2, round($g['minutes'] / $genreMax * 100, 1)) : 0 }}%"></span></span>
                            <span class="hbar-figure">{{ StatsMath::duration($g['minutes'] * 60) }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
</div>

{{-- Top series and audiobooks. --}}
<div class="stats-grid mb-4">
    <div class="card">
        <div class="panel-head">
            <h2 class="panel-title">Most read</h2>
            <span class="chart-note">{{ ucfirst($rangeLabel) }}</span>
        </div>
        @if($s['top_series'] === [])
            <div class="card-body"><p class="text-muted mb-0">No chapters read in {{ $rangeLabel }}.</p></div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle table-responsive-cards stats-rank-table mb-0">
                    <caption class="visually-hidden">Most-read novels in {{ $rangeLabel }}</caption>
                    <thead>
                        <tr>
                            <th>Series</th>
                            <th class="text-end" style="width: 100px;">Chapters</th>
                            <th class="text-end" style="width: 130px;">Last read</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($s['top_series'] as $row)
                            <tr>
                                <td class="cell-primary text-truncate" style="max-width: 260px;">
                                    <span class="legend-dot {{ $mediumTone[$row['medium']] ?? '' }}" title="{{ \App\Services\ReaderStats::MEDIUM_LABELS[$row['medium']] ?? '' }}"></span>
                                    <a href="{{ route('novels.show', $row['id']) }}">{{ $row['name'] }}</a>
                                </td>
                                <td class="text-end" data-label="Chapters"><span class="mono-figure text-amber">{{ number_format($row['chapters']) }}</span></td>
                                <td class="text-end mono-muted" data-label="Last read">{{ $row['last_read_at'] ? \Carbon\Carbon::parse($row['last_read_at'])->diffForHumans() : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    <div class="card">
        <div class="panel-head">
            <h2 class="panel-title">Most listened</h2>
            <span class="chart-note">{{ ucfirst($rangeLabel) }}</span>
        </div>
        @if($s['top_audiobooks'] === [])
            <div class="card-body"><p class="text-muted mb-0">No listening in {{ $rangeLabel }}.</p></div>
        @else
            <ol class="stats-rank">
                @foreach($s['top_audiobooks'] as $row)
                    <li>
                        <span class="rank-no">{{ $loop->iteration }}</span>
                        <span class="rank-name">{{ $row['title'] }}@if($row['author']) <span class="text-muted">· {{ $row['author'] }}</span>@endif</span>
                        <span class="rank-figure">{{ StatsMath::duration($row['listening_seconds']) }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</div>

{{-- When: time of day and weekday. --}}
@php
    $hourBars = array_map(fn($x) => ['label' => sprintf('%02d', $x['hour']), 'value' => $x['minutes'], 'title' => sprintf('%02d:00–%02d:59', $x['hour'], $x['hour']) . ": {$x['chapters']} chapters, ≈{$x['minutes']} min"], $s['hours']);
    $weekdayBars = array_map(fn($x) => ['label' => $x['label'], 'value' => $x['minutes'], 'title' => "{$x['label']}: {$x['chapters']} chapters, ≈{$x['minutes']} min"], $s['weekdays']);
    $peakHour = collect($s['hours'])->sortByDesc('minutes')->first();
    $peakDay = collect($s['weekdays'])->sortByDesc('minutes')->first();
@endphp
<div class="stats-grid mb-4">
    <div class="card">
        <div class="panel-head">
            <h2 class="panel-title">Time of day</h2>
            <span class="chart-note">@if(($peakHour['minutes'] ?? 0) > 0) Most around {{ sprintf('%02d:00', $peakHour['hour']) }} @else Minutes, {{ $rangeLabel }} @endif</span>
        </div>
        <div class="card-body">
            @include('stats._bars', ['bars' => $hourBars, 'label' => 'Bar chart of minutes by hour of day' . (($peakHour['minutes'] ?? 0) > 0 ? ', peak at ' . sprintf('%02d:00', $peakHour['hour']) : ''), 'tone' => 'is-pattern', 'every' => 6, 'height' => 140])
        </div>
    </div>
    <div class="card">
        <div class="panel-head">
            <h2 class="panel-title">Day of the week</h2>
            <span class="chart-note">@if(($peakDay['minutes'] ?? 0) > 0) {{ $peakDay['label'] }} is your day @else Minutes, {{ $rangeLabel }} @endif</span>
        </div>
        <div class="card-body">
            @include('stats._bars', ['bars' => $weekdayBars, 'label' => 'Bar chart of minutes by day of the week', 'tone' => 'is-pattern', 'every' => 1, 'height' => 140])
        </div>
    </div>
</div>

{{-- Year in review --}}
@php $y = $s['year']; @endphp
<div class="card stats-year mb-4">
    <div class="panel-head">
        <h2 class="panel-title">{{ $y['year'] }} so far</h2>
        <span class="chart-note">Year in review</span>
    </div>
    <div class="card-body">
        <div class="stats-year-grid">
            <div><span class="year-figure">{{ number_format($y['chapters']) }}</span><span class="year-label">chapters</span></div>
            <div><span class="year-figure">{{ StatsMath::compact($y['words']) }}</span><span class="year-label">words</span></div>
            <div><span class="year-figure">{{ number_format(StatsMath::hours($y['listening_seconds']), 1) }}</span><span class="year-label">hours listened</span></div>
            <div><span class="year-figure">{{ number_format($y['series_finished'] + $y['audiobooks_finished']) }}</span><span class="year-label">finished</span></div>
            <div><span class="year-figure">{{ number_format($y['active_days']) }}</span><span class="year-label">active days</span></div>
        </div>
        <p class="stats-year-line mb-0">
            @if($y['top_genre'])Mostly <strong>{{ $y['top_genre'] }}</strong>. @endif
            @if($y['top_series'])Most read: <a href="{{ route('novels.show', $y['top_series']['id']) }}">{{ $y['top_series']['name'] }}</a>. @endif
            @if($y['busiest_month'])Busiest month: <strong>{{ $y['busiest_month']['label'] }}</strong> ({{ number_format($y['busiest_month']['chapters']) }} chapters).@endif
            @if(!$y['top_genre'] && !$y['top_series'] && !$y['busiest_month'])Your year starts with the next chapter.@endif
        </p>
    </div>
</div>

{{-- Milestones --}}
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Milestones</h2>
        <span class="chart-note">{{ count(array_filter($s['milestones'], fn($m) => $m['earned'])) }} of {{ count($s['milestones']) }} earned</span>
    </div>
    <div class="card-body">
        <ul class="stats-badges">
            @foreach($s['milestones'] as $m)
                <li class="stats-badge {{ $m['earned'] ? 'is-earned' : '' }}">
                    <span class="badge-mark" aria-hidden="true">{{ $m['earned'] ? '★' : '☆' }}</span>
                    <span class="badge-title">{{ $m['title'] }}</span>
                    <span class="badge-detail">{{ $m['detail'] }}</span>
                    @unless($m['earned'])
                        <span class="badge-progress" role="progressbar" aria-label="{{ $m['title'] }} progress" aria-valuemin="0" aria-valuemax="{{ $m['target'] }}" aria-valuenow="{{ min($m['current'], $m['target']) }}"><span style="width: {{ round($m['progress'] * 100, 1) }}%"></span></span>
                        <span class="badge-count">{{ StatsMath::compact($m['current']) }} / {{ StatsMath::compact($m['target']) }}</span>
                    @endunless
                </li>
            @endforeach
        </ul>
    </div>
</div>

<p class="page-note">Reading time is estimated (250 words a minute, 5 minutes a manga chapter); listening time is measured. Chapters marked read in bulk count in the totals but not as activity. Stats refresh every 5 minutes.</p>

<script>
    document.querySelectorAll('[data-scroll-end]').forEach(function (el) { el.scrollLeft = el.scrollWidth; });
</script>
@endsection
