{{-- Readers → Reading now (App\Services\ReaderActivity::readingNow()): a
     reading position written in the last few minutes, one per reader.
     Rendered on the page and by GET /readers/now for the 30-second refresh,
     so both always look the same. $names: accounts keyed by id. --}}
@php
    $minutes = \App\Services\ReaderActivity::NOW_MINUTES;
@endphp
<div class="dash-panel-head">
    <h2 class="dash-panel-title" id="nowTitle">Reading now</h2>
    <div class="dash-panel-tools">
        <span class="chip-mono {{ $now->isNotEmpty() ? 'chip-mono-success' : '' }}" title="Readers with a position in the last {{ $minutes }} minutes">{{ number_format($now->count()) }} READING</span>
    </div>
</div>
@if($now->isEmpty())
    <p class="dash-panel-empty mb-0" data-reading-now-empty>Nobody is reading right now.</p>
@else
    <ul class="reader-now-list" data-reading-now>
        @foreach($now as $item)
            @php
                $who = $names[$item->user_id] ?? null;
                $deviceLabel = $item->device_name ?? $item->client;
                $clientTitle = $item->device_name && $item->client && $item->client !== $item->device_name ? $item->client : null;
            @endphp
            <li class="reader-now">
                <a href="{{ route('novels.show', $item->novel->id) }}" class="reader-now-cover" tabindex="-1" aria-hidden="true">
                    <x-cover :novel="$item->novel" size="sm" decorative />
                </a>
                <div class="reader-now-main">
                    <span class="reader-now-who">
                        @if($who)
                            <a href="{{ route('readers.show', $who->id) }}">{{ $who->name }}</a>
                        @else
                            Deleted account
                        @endif
                    </span>
                    <a href="{{ route('novels.show', $item->novel->id) }}" class="reader-now-series">{{ $item->novel->name }}</a>
                    <span class="reader-now-chapter" @if($item->chapter->label) title="{{ $item->chapter->label }}" @endif>{{ $item->number !== '' ? 'Chapter ' . $item->number : ($item->chapter->label ?: 'A chapter') }}</span>
                    @if($item->progress !== null)
                        <div class="progress progress-reading reader-now-progress" role="progressbar" aria-label="Progress in this chapter" aria-valuenow="{{ $item->progress }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" style="width: {{ $item->progress }}%"></div>
                        </div>
                    @endif
                    <span class="reader-now-meta mono-muted">
                        @if($item->progress !== null){{ $item->progress }}% · @endif
                        @if($deviceLabel)<span class="reader-device-name" @if($clientTitle) title="{{ $clientTitle }}" @endif>{{ $deviceLabel }}</span> · @endif
                        @if($item->ip)<span class="reader-ip">{{ $item->ip }}</span> · @endif
                        <time datetime="{{ $item->at->toIso8601String() }}">{{ $item->minutes < 1 ? 'just now' : $item->minutes . ' min ago' }}</time>
                    </span>
                    @if($item->public)
                        <span class="reader-now-flags"><span class="chip-mono chip-mono-pending reader-chip-public">Public address</span></span>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
