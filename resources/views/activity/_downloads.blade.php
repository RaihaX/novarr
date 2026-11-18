{{-- Downloads by source (App\Services\DownloadLanes::overview()): one row
     per download lane — each source downloads one chapter at a time, the
     sources in parallel. Rendered on the page and by GET /activity/downloads
     for the 10-second refresh, so both always look the same. --}}
@php
    $rows = $downloads['rows'];
    $summary = $downloads['summary'];
@endphp
<div class="dash-panel-head">
    <h2 class="dash-panel-title" id="downloadsTitle">Downloads by source</h2>
    <div class="dash-panel-tools">
        <span class="chip-mono {{ $summary['downloading'] > 0 ? 'chip-mono-success' : '' }}" title="Sources downloading right now">{{ number_format($summary['downloading']) }} ACTIVE</span>
    </div>
</div>
<p class="dash-panel-body mb-0 text-muted" data-downloads-summary>{{ $summary['downloading'] }} {{ Str::plural('source', $summary['downloading']) }} downloading now · {{ number_format($summary['queued']) }} {{ Str::plural('chapter', $summary['queued']) }} queued across {{ $summary['sources'] }} {{ Str::plural('source', $summary['sources']) }}</p>
@if(count($rows) > 0)
    <div class="table-responsive">
        <table class="table table-hover table-compact align-middle">
            <thead>
                <tr>
                    <th>Source</th>
                    <th>State</th>
                    <th class="cell-num" title="Pending chapters that are due now">Queued</th>
                    <th class="cell-num" title="Pending chapters waiting out a retry delay">Retrying later</th>
                    <th class="cell-num">Series</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr data-lane="{{ $row['lane'] }}">
                        <td class="cell-title">
                            {{ $row['label'] }}
                            @if($row['medium'])
                                <span class="chip-mono">{{ strtoupper($row['medium']) }}</span>
                            @endif
                        </td>
                        <td class="cell-label">
                            @if($row['state'] === 'downloading')
                                <x-status state="active">Downloading</x-status>
                                @if($row['novel_name'])
                                    @if($row['novel_id'])
                                        <a href="{{ route('novels.show', $row['novel_id']) }}">{{ $row['novel_name'] }}</a>
                                    @else
                                        {{ $row['novel_name'] }}
                                    @endif
                                    @if($row['chapter_label']) — {{ $row['chapter_label'] }}@endif
                                @endif
                                @if($row['since'])
                                    <span class="text-muted" title="{{ \Carbon\Carbon::parse($row['since'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}">· since {{ \Carbon\Carbon::parse($row['since'])->diffForHumans(null, true) }}{{ $row['mode'] === 'manual' ? ' (started by hand)' : '' }}</span>
                                @endif
                            @elseif($row['state'] === 'cooling')
                                <x-status state="attention">{{ $row['cooldown_until'] ? 'Cooling down until ' . $row['cooldown_until'] : 'Cooling down' }}</x-status>
                            @else
                                <x-status state="queued">Waiting</x-status>
                            @endif
                            @if(!empty($row['stalled']))
                                <small data-stalled title="No chapter of these series downloaded for {{ $row['stalled_hours'] }} hours while chapters wait"><x-status state="attention" as="text">· nothing downloaded for {{ number_format($row['stalled_hours']) }} h</x-status></small>
                            @endif
                        </td>
                        <td class="cell-num">{{ number_format($row['queued']) }}</td>
                        <td class="cell-num">{{ number_format($row['retrying']) }}</td>
                        <td class="cell-num">{{ number_format($row['series']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p class="dash-panel-empty mb-0">No downloads queued: every source is idle.</p>
@endif
