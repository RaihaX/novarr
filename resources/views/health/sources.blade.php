@extends('layouts.app')

@section('title', 'Sources · Health')
@section('breadcrumb')
    @include('partials.breadcrumb', ['trail' => [['System'], ['Health', route('health.index')], ['Sources']]])
@endsection

@push('styles')
<style>
    /* Health → Sources: 7-day bars (ok over failed per day), no chart library. */
    .src-spark { display: inline-flex; align-items: flex-end; gap: 3px; height: 28px; vertical-align: middle; }
    .src-spark-day { display: flex; flex-direction: column-reverse; width: 8px; height: 100%; background: var(--nv-surface-alt); border-radius: 1px; overflow: hidden; }
    .src-spark-day > span { display: block; width: 100%; }
    .src-rate { white-space: nowrap; }
    .src-rate small { display: block; }
    .src-meta { display: block; font-size: .8125rem; color: var(--nv-text-muted); }
    .src-error { display: block; max-width: 28ch; overflow-wrap: anywhere; }
    @media (max-width: 767.98px) { .src-error { max-width: none; text-align: right; } }
</style>
@endpush


@section('content')
@php
    $badge = [
        \App\Services\SourceHealth::FAILING => 'failed',
        \App\Services\SourceHealth::DEGRADED => 'attention',
        \App\Services\SourceHealth::HEALTHY => 'downloaded',
        \App\Services\SourceHealth::IDLE => 'paused',
    ];
    $tone = fn (?float $rate) => $rate === null ? 'tone-muted'
        : ($rate < $thresholds['failing_below'] ? 'tone-danger' : ($rate < $thresholds['degraded_below'] ? 'tone-warning' : 'tone-success'));
    $pct = fn (?float $rate) => $rate === null ? '—' : (int) floor($rate * 100) . '%';
    $counts = collect($sources)->countBy('status');
@endphp
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <h1 class="page-title mb-0">Sources</h1>
    <a href="{{ route('health.index') }}" class="btn btn-secondary">Back to Health</a>
</div>

<p class="text-muted mb-4">
    How often each site answered over the last 24 hours and 7 days: chapter downloads, chapter lists and searches.
    A source is <strong>Failing</strong> with at least {{ $thresholds['min_attempts'] }} attempts of one kind in 24 hours and under
    {{ (int) round($thresholds['failing_below'] * 100) }}% of them ok, <strong>Degraded</strong> under {{ (int) round($thresholds['degraded_below'] * 100) }}%.
    Rate limits alone count as Degraded at worst — that is Novarr's pace, not a broken site. The owner is told when a source starts failing.
</p>

<div class="card mb-4" id="sourceHealth">
    <div class="panel-head">
        <h2 class="panel-title">
            Sources
            @if(($counts[\App\Services\SourceHealth::FAILING] ?? 0) > 0)
                <span class="count-chip is-danger">{{ $counts[\App\Services\SourceHealth::FAILING] }} failing</span>
            @endif
            @if(($counts[\App\Services\SourceHealth::DEGRADED] ?? 0) > 0)
                <span class="count-chip is-warning">{{ $counts[\App\Services\SourceHealth::DEGRADED] }} degraded</span>
            @endif
            <span class="count-chip">{{ count($sources) }}</span>
        </h2>
    </div>
    @if(count($sources))
        <div class="table-responsive">
            <table class="table align-middle table-responsive-cards">
                <caption class="visually-hidden">Success rate per source, worst first</caption>
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Status</th>
                        @foreach(\App\Services\SourceHealth::KIND_LABELS as $kindLabel)
                            <th>{{ $kindLabel }} <span class="visually-hidden">(24 hours, 7 days)</span></th>
                        @endforeach
                        <th>7 days</th>
                        <th>Top reason</th>
                        <th>Last error</th>
                        <th>Last success</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sources as $row)
                        @php
                            $max = max(1, collect($row['daily'])->max(fn ($d) => $d['ok'] + $d['failed']));
                        @endphp
                        <tr data-source="{{ $row['key'] }}" data-status="{{ $row['status'] }}">
                            <td class="cell-primary">
                                <strong>{{ $row['name'] }}</strong>
                                <span class="src-meta mono-muted">
                                    {{ $row['host'] !== '' ? $row['host'] : $row['key'] }} · {{ $row['medium'] }} · {{ number_format($row['series']) }} {{ \Illuminate\Support\Str::plural('series', $row['series']) }}
                                </span>
                            </td>
                            <td data-label="Status">
                                <x-status :state="$badge[$row['status']]">{{ $row['status_label'] }}</x-status>
                                @if($row['low_volume'])
                                    <span class="src-meta">few attempts</span>
                                @endif
                            </td>
                            @foreach(\App\Services\SourceHealth::KINDS as $kind)
                                @php $k = $row['kinds'][$kind]; @endphp
                                <td class="src-rate" data-label="{{ \App\Services\SourceHealth::KIND_LABELS[$kind] }}">
                                    @if($k['week']['attempts'] === 0)
                                        <span class="mono-muted">—</span>
                                    @else
                                        <span class="mono-figure {{ $tone($k['day']['rate']) }}" title="Last 24 hours: {{ $k['day']['ok'] }} ok of {{ $k['day']['attempts'] }}">{{ $pct($k['day']['rate']) }}</span>
                                        <span class="mono-muted">of {{ number_format($k['day']['attempts']) }}</span>
                                        <small class="mono-muted" title="Last 7 days: {{ $k['week']['ok'] }} ok of {{ $k['week']['attempts'] }}">7d {{ $pct($k['week']['rate']) }} of {{ number_format($k['week']['attempts']) }}</small>
                                    @endif
                                </td>
                            @endforeach
                            <td data-label="7 days">
                                <span class="src-spark" role="img" aria-label="Calls per day over 7 days: {{ collect($row['daily'])->map(fn ($d) => \Carbon\Carbon::parse($d['day'])->format('D') . ' ' . $d['ok'] . ' ok, ' . $d['failed'] . ' failed')->implode('; ') }}">
                                    @foreach($row['daily'] as $d)
                                        @php $total = $d['ok'] + $d['failed']; @endphp
                                        <span class="src-spark-day" title="{{ \Carbon\Carbon::parse($d['day'])->format('D j M') }}: {{ $d['ok'] }} ok, {{ $d['failed'] }} failed">
                                            @if($d['ok'] > 0)<span class="bar-success" style="height: {{ round(100 * $d['ok'] / $max, 1) }}%"></span>@endif
                                            @if($d['failed'] > 0)<span class="bar-danger" style="height: {{ round(100 * $d['failed'] / $max, 1) }}%"></span>@endif
                                        </span>
                                    @endforeach
                                </span>
                            </td>
                            <td data-label="Top reason">
                                @if($row['top_reason'])
                                    {{ $row['top_reason']['label'] }} <span class="mono-muted">×{{ number_format($row['top_reason']['count']) }}</span>
                                @else
                                    <span class="mono-muted">none</span>
                                @endif
                            </td>
                            <td data-label="Last error">
                                @if($row['last_error'])
                                    <span class="src-error">{{ $row['last_error']['message'] ?? \App\Services\SourceHealth::reasonLabel($row['last_error']['reason']) }}</span>
                                    <span class="src-meta" title="{{ $row['last_error']['at']->format('Y-m-d H:i') }}">{{ strtolower(\App\Services\SourceHealth::KIND_LABELS[$row['last_error']['kind']] ?? $row['last_error']['kind']) }}, {{ $row['last_error']['at']->diffForHumans() }}</span>
                                @else
                                    <span class="mono-muted">none</span>
                                @endif
                            </td>
                            <td class="mono-muted text-nowrap" data-label="Last success">
                                @if($row['last_ok_at'])
                                    <span title="{{ $row['last_ok_at']->format('Y-m-d H:i') }}">{{ $row['last_ok_at']->diffForHumans() }}</span>
                                @else
                                    never recorded
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="card-body">
            <p class="text-muted mb-0">No sources yet — calls are counted from the next download, chapter-list check or search.</p>
        </div>
    @endif
</div>
@endsection
