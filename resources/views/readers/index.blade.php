@extends('layouts.app')

@section('title', 'Readers')

@php
    use App\Services\ReaderActivity;
    $tiles = $overview['tiles'];
    $daily = $overview['daily'];
    $figures = $overview['readers'];
    $windowDays = $overview['window_days'];
    $ownerId = \App\Support\CurrentReader::ownerId();

    // Series colour per account, by its place in the list (owner first = amber).
    $series = [];
    foreach ($accounts->values() as $i => $u) {
        $series[(int) $u->id] = ReaderActivity::seriesClass($i);
    }
    // Readers with reads in the window, in account order: the chart's
    // segments and legend.
    $charted = $accounts->filter(fn($u) => ($figures[(int) $u->id]['month'] ?? 0) > 0)->values();

    // Readers table: 30-day chapters desc, then name.
    $rowsByActivity = $accounts->sortBy([
        fn($a, $b) => ($figures[(int) $b->id]['month'] ?? 0) <=> ($figures[(int) $a->id]['month'] ?? 0),
        fn($a, $b) => strcasecmp((string) $a->name, (string) $b->name),
    ])->values();

    $roleOf = fn($u) => (int) $u->id === $ownerId ? 'Owner' : ($u->isAdmin() ? 'Admin' : 'Reader');
@endphp

@section('content')
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Reading activity</span>
        <h1 class="page-title mb-0">Readers</h1>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('readers.history') }}" class="btn btn-secondary">History</a>
        <a href="{{ route('settings.users') }}" class="btn btn-secondary">Accounts</a>
    </div>
</div>

{{-- Who is reading right now; refreshed every 30 s while the tab is visible. --}}
<section class="dash-panel mb-4" id="reading-now-section" aria-labelledby="nowTitle"
         data-refresh-url="{{ route('readers.now') }}" data-refresh-every="30">
    @include('readers._now', ['now' => $now, 'names' => $names])
</section>

{{-- Tiles, across every reader. --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value {{ $tiles['active_today'] > 0 ? 'value-amber' : 'text-muted' }}" data-tile="active-today">{{ number_format($tiles['active_today']) }}<span class="dash-stat-unit">of {{ number_format($tiles['accounts']) }}</span></div>
                <div class="dash-stat-label">Readers active today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value {{ $tiles['today'] > 0 ? 'value-amber' : 'text-muted' }}" data-tile="today">{{ number_format($tiles['today']) }}</div>
                <div class="dash-stat-label">Chapters today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value {{ $tiles['week'] > 0 ? '' : 'text-muted' }}" data-tile="week">{{ number_format($tiles['week']) }}</div>
                <div class="dash-stat-label">Chapters this week</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value {{ $tiles['month'] > 0 ? '' : 'text-muted' }}" data-tile="month">{{ number_format($tiles['month']) }}</div>
                <div class="dash-stat-label">Chapters in {{ $windowDays }} days</div>
            </div>
        </div>
    </div>
</div>

{{-- Chapters per day, each day's bar stacked by reader. Same axes and
     ticks as /stats; legend text and hover titles carry the names, so the
     colours are never the only cue. --}}
@php
    $totals = array_column($daily, 'total');
    $max = max($totals ?: [0]);
    $n = count($daily);
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
    $maxIdx = $max > 0 ? array_search($max, $totals) : null;
    $labelEvery = $n > 14 ? 5 : 1;
    $dayTitle = function (array $day) use ($charted) {
        $parts = [];
        foreach ($charted as $u) {
            if (($c = $day['by'][(int) $u->id] ?? 0) > 0) {
                $parts[] = $u->name . ' ' . $c;
            }
        }

        return $day['label'] . ': ' . ($parts === [] ? 'nothing read' : implode(', ', $parts));
    };
@endphp
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Chapters read per day, by reader</h2>
        <span class="chart-note">{{ number_format($tiles['month']) }} chapters · last {{ $windowDays }} days</span>
    </div>
    <div class="card-body">
        @if($max === 0)
            <div class="chart-empty">
                <div class="chart-empty-rule" aria-hidden="true"></div>
                <p class="mb-0">Nobody read a chapter in the last {{ $windowDays }} days. Days with reading will show up here as bars, one colour per reader.</p>
            </div>
        @else
            @if($charted->count() > 1)
                <ul class="reader-legend" aria-label="Readers in the chart">
                    @foreach($charted as $u)
                        <li><span class="reader-swatch {{ $series[(int) $u->id] }}" aria-hidden="true"></span>{{ $u->name }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="bar-chart bar-chart-stacked" role="img" aria-label="Stacked bar chart of chapters read per day by reader over the last {{ $windowDays }} days; peak {{ $max }} on {{ $daily[$maxIdx]['label'] }}" style="--ticks: {{ count($ticks) - 1 }};">
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
                            @php $pct = $day['total'] > 0 ? max(1.5, $day['total'] / $top * 100) : 0; @endphp
                            <div class="bar-chart-col {{ $i === $maxIdx ? 'is-peak' : '' }}" title="{{ $dayTitle($day) }}">
                                @if($pct > 0)
                                    <div class="bar-chart-bar" style="height: {{ $pct }}%">
                                        @foreach($charted as $u)
                                            @php $c = $day['by'][(int) $u->id] ?? 0; @endphp
                                            @if($c > 0)
                                                <span class="bar-chart-seg {{ $series[(int) $u->id] }}" style="height: {{ round($c / $day['total'] * 100, 3) }}%"></span>
                                            @endif
                                        @endforeach
                                        <span class="bar-chart-value">{{ $day['total'] }}</span>
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
                    <caption class="visually-hidden">Chapters read per day by each reader, newest first</caption>
                    <thead>
                        <tr>
                            <th>Date</th>
                            @foreach($accounts as $u)
                                <th class="text-end">{{ $u->name }}</th>
                            @endforeach
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_reverse($daily) as $day)
                            <tr>
                                <td class="mono-muted">{{ $day['date'] }}</td>
                                @foreach($accounts as $u)
                                    <td class="num">{{ $day['by'][(int) $u->id] ?? 0 }}</td>
                                @endforeach
                                <td class="num">{{ $day['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</div>

{{-- One row per account. --}}
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Readers</h2>
        <span class="chart-note">{{ number_format($accounts->count()) }} {{ Str::plural('account', $accounts->count()) }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle table-responsive-cards reader-table" id="readersTable">
            <caption class="visually-hidden">Every account's reading, most active in the last {{ $windowDays }} days first</caption>
            <thead>
                <tr>
                    <th>Reader</th>
                    <th>Last seen</th>
                    <th>Last read</th>
                    <th class="text-end">Today</th>
                    <th class="text-end">7 days</th>
                    <th class="text-end">{{ $windowDays }} days</th>
                    <th class="text-end">All-time</th>
                    <th class="text-end">Streak</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rowsByActivity as $u)
                    @php $f = $figures[(int) $u->id] ?? ['today' => 0, 'week' => 0, 'month' => 0, 'total' => 0, 'streak' => 0, 'last' => null]; @endphp
                    <tr data-reader="{{ $u->id }}" class="{{ $u->isDisabled() ? 'is-disabled' : '' }}">
                        <td class="cell-primary">
                            <a href="{{ route('readers.show', $u->id) }}">{{ $u->name }}</a>
                            <span class="badge {{ $u->isAdmin() ? 'badge-accent' : 'badge-muted' }}">{{ $roleOf($u) }}</span>
                            @if($u->isDisabled())
                                <span class="badge badge-muted">Turned off</span>
                            @endif
                            <span class="reader-username mono-muted">{{ $u->username }}</span>
                        </td>
                        <td class="mono-muted reader-seen" data-label="Last seen">
                            @if($u->last_seen_at)
                                <time datetime="{{ $u->last_seen_at->toIso8601String() }}">{{ $u->last_seen_at->diffForHumans() }}</time>
                            @else
                                Never
                            @endif
                        </td>
                        <td data-label="Last read">
                            @if($f['last'])
                                @php $lastAt = \Illuminate\Support\Carbon::parse($f['last']['at']); @endphp
                                {{-- One cell child, so the phone card layout keeps the two lines together. --}}
                                <span class="reader-last">
                                    <a href="{{ route('novels.show', $f['last']['novel_id']) }}">{{ $f['last']['novel_name'] }}</a>
                                    <span class="reader-last-meta mono-muted">{{ $f['last']['number'] !== '' ? 'Ch. ' . $f['last']['number'] : $f['last']['label'] }} · <time datetime="{{ $lastAt->toIso8601String() }}">{{ $lastAt->diffForHumans() }}</time></span>
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end" data-label="Today"><span class="mono-figure {{ $f['today'] > 0 ? 'text-amber' : 'text-muted' }}" data-figure="today">{{ number_format($f['today']) }}</span></td>
                        <td class="text-end" data-label="7 days"><span class="mono-figure" data-figure="week">{{ number_format($f['week']) }}</span></td>
                        <td class="text-end" data-label="{{ $windowDays }} days"><span class="mono-figure" data-figure="month">{{ number_format($f['month']) }}</span></td>
                        <td class="text-end" data-label="All-time"><span class="mono-figure" data-figure="total">{{ number_format($f['total']) }}</span></td>
                        <td class="text-end" data-label="Streak"><span class="reader-streak"><span class="mono-figure" data-figure="streak">{{ number_format($f['streak']) }}</span> <span class="mono-muted">{{ $f['streak'] === 1 ? 'day' : 'days' }}</span></span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Most popular series in the window: by distinct readers, then chapters. --}}
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Most popular</h2>
        <span class="chart-note">Last {{ $windowDays }} days</span>
    </div>
    @if($overview['popular'] === [])
        <div class="card-body"><p class="text-muted mb-0">No chapters read in the last {{ $windowDays }} days.</p></div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle table-responsive-cards" id="popularTable">
                <caption class="visually-hidden">Most popular series in the last {{ $windowDays }} days, by readers then chapters</caption>
                <thead>
                    <tr>
                        <th>Series</th>
                        <th class="text-end" style="width: 110px;">Readers</th>
                        <th class="text-end" style="width: 110px;">Chapters</th>
                        <th class="text-end" style="width: 170px;">Last read</th>
                        <th class="text-end" style="width: 110px;"><span class="visually-hidden">History</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($overview['popular'] as $row)
                        <tr data-popular="{{ $row['novel_id'] }}">
                            <td class="cell-primary text-truncate" style="max-width: 340px;">
                                <a href="{{ route('novels.show', $row['novel_id']) }}">{{ $row['name'] }}</a>
                            </td>
                            <td class="text-end" data-label="Readers"><span class="mono-figure text-amber">{{ number_format($row['readers']) }}</span></td>
                            <td class="text-end" data-label="Chapters"><span class="mono-figure">{{ number_format($row['chapters']) }}</span></td>
                            <td class="text-end mono-muted" data-label="Last read">{{ \Illuminate\Support\Carbon::parse($row['last_read'])->diffForHumans() }}</td>
                            <td class="text-end" data-label="History"><a href="{{ route('readers.history', ['novel' => $row['novel_id']]) }}" aria-label="History of {{ $row['name'] }}">History</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- The newest reading sessions (not cached). --}}
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Recent activity</h2>
        <a href="{{ route('readers.history') }}" class="chart-note">Full history</a>
    </div>
    @if($recent === [])
        <div class="card-body"><p class="text-muted mb-0">Nothing read yet.</p></div>
    @else
        @include('readers._sessions', ['sessions' => $recent, 'names' => $names, 'caption' => 'Recent reading sessions, newest first'])
    @endif
</div>

<p class="page-note">Chapters marked read in bulk count in All-time only. A session is a reader's chapters of one series read no more than {{ ReaderActivity::SESSION_GAP_MINUTES }} minutes apart. Figures refresh every minute; Reading now every 30 seconds.</p>
@endsection

@push('scripts')
@include('partials.refresh-sections')
@endpush
