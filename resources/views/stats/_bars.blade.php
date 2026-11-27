{{-- A vertical bar chart in the stats/_body style (HTML axes, integer
     ticks on a 1/2/5 step, the peak's value shown). Params:
       $bars    list of ['label' => x-axis label, 'value' => number, 'title' => hover text]
       $label   aria-label summary
       $tone    '' (amber, reading) | 'is-listen' (cyan) | 'is-pattern' (indigo)
       $every   show every nth x label (the last always)
       $height  plot height in px (optional) --}}
@php
    $values = array_column($bars, 'value');
    $max = max($values ?: [0]);
    $ticks = \App\Support\StatsMath::ticks($max);
    $top = $ticks[0];
    $n = count($bars);
    $maxIdx = $max > 0 ? array_search($max, $values) : null;
    $every = max(1, $every ?? 1);
@endphp
<div class="bar-chart stats-bars {{ $tone ?? '' }}" role="img" aria-label="{{ $label }}" style="--ticks: {{ count($ticks) - 1 }};@isset($height) grid-template-rows: {{ $height }}px auto;@endisset">
    <div class="bar-chart-y" aria-hidden="true">
        @foreach($ticks as $k => $t)
            <span style="top: {{ count($ticks) > 1 ? round($k / (count($ticks) - 1) * 100, 3) : 100 }}%">{{ \App\Support\StatsMath::compact($t) }}</span>
        @endforeach
    </div>
    <div class="bar-chart-plot" aria-hidden="true">
        <div class="bar-chart-grid">
            @foreach($ticks as $t)<span></span>@endforeach
        </div>
        <div class="bar-chart-bars">
            @foreach($bars as $i => $bar)
                @php $pct = $bar['value'] > 0 ? max(1.5, $bar['value'] / $top * 100) : 0; @endphp
                <div class="bar-chart-col {{ $i === $maxIdx ? 'is-peak' : '' }}" title="{{ $bar['title'] }}">
                    @if($pct > 0)
                        <div class="bar-chart-bar" style="height: {{ $pct }}%">
                            <span class="bar-chart-value">{{ \App\Support\StatsMath::compact($bar['value']) }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    <div></div>
    <div class="bar-chart-x" aria-hidden="true" style="grid-template-columns: repeat({{ $n }}, minmax(0, 1fr));">
        @foreach($bars as $i => $bar)
            @php $shown = $i % $every === 0 || $i === $n - 1; @endphp
            <span class="{{ $shown ? 'is-shown' : '' }} {{ $i === $n - 1 ? 'is-last' : '' }}">{{ $shown ? $bar['label'] : '' }}</span>
        @endforeach
    </div>
</div>
