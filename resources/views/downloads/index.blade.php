@extends('layouts.app')

{{-- Admin: Activity for searching Usenet & torrents (App\Downloads), Sonarr
     style: Queue (with live progress), History, Wanted, Blocklist, Settings
     (App\Http\Controllers\DownloadController::index()). --}}

@section('title', 'Downloads')
@section('breadcrumb')
    @include('partials.breadcrumb', ['trail' => [['System'], ['Health', route('health.index')], ['Downloads']]])
@endsection

@section('content')
@php
    $h = fn ($bytes) => \App\Support\Bytes::human($bytes);
    $tabs = ['attention' => 'Needs you', 'queue' => 'Queue', 'seeding' => 'Seeding', 'history' => 'History', 'wanted' => 'Wanted', 'blocklist' => 'Blocklist', 'settings' => 'Settings'];
    $counts = ['attention' => \App\Http\Controllers\DownloadController::attentionCount($attention), 'queue' => $queue->count(), 'wanted' => $wanted->where('monitored', true)->count(), 'blocklist' => $blocklist->count()];
    $targetOf = function ($grab) {
        if ($grab->request) return ['Wanted: ' . $grab->request->title, route('books.review', $grab->request->id)];
        if ($grab->novel) return ['Audiobook of ' . $grab->novel->name, route('novels.show', $grab->novel->id)];
        return [null, null];
    };
@endphp
<div class="dl-page">
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">System · Downloads</span>
        <h1 class="page-title mb-0">Downloads</h1>
        <p class="page-head-sub mb-0">
            Books and audiobooks from every Prowlarr indexer: usenet through NZBGet, torrents through Transmission (they keep seeding).
            @if($lastPoll) Last checked {{ \Carbon\Carbon::parse($lastPoll['at'])->diffForHumans() }}. @endif
            Searches today: <span class="mono-num" id="searchBudget">{{ $budget['used'] }} / {{ $budget['cap'] }}</span>.
        </p>
    </div>
    <div class="page-head-actions">
        <form method="POST" action="{{ route('downloads.poll') }}">
            @csrf
            <button type="submit" class="btn btn-secondary" id="pollNow"><x-icon name="refresh-cw" :size="14" /> Check now</button>
        </form>
    </div>
</div>

@unless($enabled)
    <div class="alert alert-warning" role="status" id="downloadsOff">
        Searching is off: set <code>NOVARR_PROWLARR_URL</code> and <code>NOVARR_PROWLARR_API_KEY</code>
        (and <code>NOVARR_NZBGET_URL</code> / <code>NOVARR_TRANSMISSION_URL</code> for the download clients).
    </div>
@endunless
@if($enabled && !$clients['nzbget'] && !$clients['transmission'])
    <div class="alert alert-warning" role="status">No download client is set up: results can be searched but not grabbed.</div>
@endif

<nav class="dl-tabs" aria-label="Downloads">
    @foreach($tabs as $key => $label)
        <a href="{{ route('downloads.index', ['tab' => $key]) }}" class="dl-tab {{ $tab === $key ? 'is-active' : '' }}" @if($tab === $key) aria-current="page" @endif>
            {{ $label }}@if(isset($counts[$key]) && $counts[$key] > 0) <span class="count-chip">{{ $counts[$key] }}</span>@endif
        </a>
    @endforeach
</nav>

@if($tab === 'attention')
{{-- Sonarr's warnings + manual import: everything that waits for the owner, with the one action that settles it. --}}
<section aria-labelledby="attentionTitle" class="dl-attention">
    <h2 class="panel-title visually-hidden" id="attentionTitle">Needs you</h2>
    @if(\App\Http\Controllers\DownloadController::attentionCount($attention) === 0)
        <p class="ab-empty">Nothing needs you. Downloads are matched and added by themselves; anything Novarr is unsure about shows up here.</p>
    @endif

    @if($attention['unmatched']->isNotEmpty())
        <h3 class="dl-attention-head">Audiobooks to match <span class="count-chip">{{ $attention['unmatched']->count() }}</span></h3>
        <ul class="dl-grabs">
            @foreach($attention['unmatched'] as $a)
                <li class="dl-grab">
                    <div class="dl-grab-body">
                        <span class="dl-grab-title">{{ $a->title }}</span>
                        <span class="dl-grab-meta">{{ $a->author ?: 'Unknown author' }}{{ $a->narrator ? ' · read by ' . $a->narrator : '' }} · added {{ $a->created_at?->diffForHumans() }}</span>
                        <span class="dl-grab-status">{{ $a->match_note ?: 'Which book is it?' }}</span>
                    </div>
                    <a class="btn btn-sm btn-primary" href="{{ route('audiobooks.manage') }}#unmatchedTitle">Match it</a>
                </li>
            @endforeach
        </ul>
    @endif

    @if($attention['review']->isNotEmpty())
        <h3 class="dl-attention-head">Books to check and add <span class="count-chip">{{ $attention['review']->count() }}</span></h3>
        <ul class="dl-grabs">
            @foreach($attention['review'] as $r)
                <li class="dl-grab">
                    <div class="dl-grab-body">
                        <span class="dl-grab-title">{{ $r->title }}</span>
                        <span class="dl-grab-meta">{{ $r->author ?: 'Unknown author' }} · {{ $r->from_download ? 'downloaded' : 'uploaded by ' . ($r->requester?->name ?? 'a reader') }} · {{ $r->created_at?->diffForHumans() }}</span>
                        <span class="dl-grab-status">{{ $r->from_download ? 'Downloaded — check it is the right book' : 'Waiting for your review' }}</span>
                    </div>
                    <a class="btn btn-sm btn-primary" href="{{ route('books.review', $r->id) }}">Review and match</a>
                </li>
            @endforeach
        </ul>
    @endif

    @if($attention['choice']->isNotEmpty())
        <h3 class="dl-attention-head">Pick a result <span class="count-chip">{{ $attention['choice']->count() }}</span></h3>
        <ul class="dl-grabs">
            @foreach($attention['choice'] as $w)
                @php $url = $w->request ? route('books.review', $w->request->id) : ($w->novel ? route('novels.show', $w->novel->id) : null); @endphp
                <li class="dl-grab">
                    <div class="dl-grab-body">
                        <span class="dl-grab-title">{{ $w->request?->title ?? $w->novel?->name ?? 'Wanted #' . $w->id }}</span>
                        <span class="dl-grab-meta">{{ $w->kind === 'audiobook' ? 'Audiobook' : 'Ebook' }} · last searched {{ $w->last_searched_at?->diffForHumans() ?? 'never' }}</span>
                        <span class="dl-grab-status">Results found, none clear enough to take by itself.</span>
                    </div>
                    @if($url)<a class="btn btn-sm btn-primary" href="{{ $url }}">Choose</a>@endif
                </li>
            @endforeach
        </ul>
    @endif

    @if($attention['stuck']->isNotEmpty())
        <h3 class="dl-attention-head">Finished but not imported <span class="count-chip">{{ $attention['stuck']->count() }}</span></h3>
        <ul class="dl-grabs">
            @foreach($attention['stuck'] as $grab)
                <li class="dl-grab">
                    <div class="dl-grab-body">
                        <span class="dl-grab-title">{{ $grab->title }}</span>
                        <span class="dl-grab-meta">{{ $grab->kind === 'audiobook' ? 'Audiobook' : 'Ebook' }} · in the inbox as “{{ $grab->completed_path }}” · {{ $grab->updated_at?->diffForHumans() }}</span>
                    </div>
                    <form method="POST" action="{{ route('downloads.grabs.import', $grab->id) }}" class="dl-import">
                        @csrf
                        <input type="number" min="1" class="form-control form-control-sm" name="{{ $grab->kind === 'audiobook' ? 'novel_id' : 'request_id' }}" value="{{ $grab->kind === 'audiobook' ? $grab->novel_id : $grab->novel_request_id }}" aria-label="{{ $grab->kind === 'audiobook' ? 'Book id' : 'Request id' }}">
                        <button type="submit" class="btn btn-sm btn-secondary">Import</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    @if($attention['failed']->isNotEmpty())
        <h3 class="dl-attention-head">Failed this week <span class="count-chip">{{ $attention['failed']->count() }}</span></h3>
        <ul class="dl-grabs">
            @foreach($attention['failed'] as $grab)
                @php [$targetLabel, $targetUrl] = $targetOf($grab); @endphp
                <li class="dl-grab">
                    <div class="dl-grab-body">
                        <span class="dl-grab-title">{{ $grab->title }}</span>
                        <span class="dl-grab-meta"><span class="dl-badge dl-badge-{{ $grab->protocol }}">{{ $grab->protocol === 'torrent' ? 'Torrent' : 'Usenet' }}</span> {{ $grab->indexer }} · {{ $grab->updated_at?->diffForHumans() }}@if($targetLabel) · <a href="{{ $targetUrl }}">{{ $targetLabel }}</a>@endif</span>
                        <span class="dl-grab-status dl-status-failed">{{ $grab->error }}</span>
                    </div>
                    @if($targetUrl)<a class="btn btn-sm btn-secondary" href="{{ $targetUrl }}">Search again</a>@endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endif

@if($tab === 'seeding')
<section aria-labelledby="seedingTitle">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <h2 class="panel-title mb-0" id="seedingTitle">Seeding</h2>
        <form method="POST" action="{{ route('downloads.seed_forever') }}">
            @csrf
            <button type="submit" class="btn btn-sm btn-secondary">Seed all without limit</button>
        </form>
    </div>
    <p class="form-text">Torrents Novarr grabbed, as Transmission reports them now. They seed with no ratio or idle limit (your other torrents keep Transmission's own limits). Imported files are copies, so the torrent's files stay in place.</p>
    @if($seeding['error'])<div class="alert alert-warning" role="status">Transmission did not answer: {{ $seeding['error'] }}</div>@endif
    @if($seeding['rows'] === [])
        <p class="ab-empty">No torrents yet.</p>
    @else
        <div class="table-responsive">
            <table class="table align-middle table-responsive-cards mb-0" id="seedingTable">
                <thead><tr><th>Torrent</th><th>State</th><th class="stg-num">Ratio</th><th class="stg-num">Uploaded</th><th class="stg-num">Seeding for</th><th class="stg-num">Peers</th><th>Limit</th></tr></thead>
                <tbody>
                    @foreach($seeding['rows'] as $row)
                        @php
                            $t = $row['torrent']; $g = $row['grab'];
                            $states = [0 => 'Stopped', 1 => 'Checking', 2 => 'Checking', 3 => 'Waiting', 4 => 'Downloading', 5 => 'Waiting to seed', 6 => 'Seeding'];
                            $secs = (int) ($t['secondsSeeding'] ?? 0);
                        @endphp
                        <tr>
                            <td class="cell-primary"><strong>{{ $t['name'] ?? $g->title }}</strong>
                                <span class="stg-meta">{{ $g->indexer }} · {{ $g->kind === 'audiobook' ? 'Audiobook' : 'Ebook' }} · Novarr: {{ $g->status }}</span></td>
                            <td data-label="State">{{ $t ? (($t['error'] ?? 0) ? 'Error: ' . ($t['errorString'] ?? '') : ($states[(int) ($t['status'] ?? 0)] ?? '?')) : 'Not in Transmission' }}</td>
                            <td class="stg-num mono-figure" data-label="Ratio">{{ $t ? number_format(max(0, (float) ($t['uploadRatio'] ?? 0)), 2) : '—' }}</td>
                            <td class="stg-num" data-label="Uploaded">{{ $t ? $h((int) ($t['uploadedEver'] ?? 0)) : '—' }}</td>
                            <td class="stg-num" data-label="Seeding for">{{ $t ? ($secs >= 86400 ? floor($secs / 86400) . ' d ' : '') . floor(($secs % 86400) / 3600) . ' h' : '—' }}</td>
                            <td class="stg-num" data-label="Peers">{{ $t ? (int) ($t['peersGettingFromUs'] ?? 0) : '—' }}</td>
                            <td data-label="Limit">{{ $t ? ((int) ($t['seedRatioMode'] ?? 0) === 2 ? 'None' : 'Transmission default') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endif

@if($tab === 'queue')
<section aria-labelledby="queueTitle" data-grab-watch>
    <h2 class="panel-title visually-hidden" id="queueTitle">Queue</h2>
    @if($queue->isEmpty())
        <p class="ab-empty">Nothing is downloading.</p>
    @else
        <ul class="dl-grabs" id="downloadQueue">
            @foreach($queue as $grab)
                @php [$targetLabel, $targetUrl] = $targetOf($grab); @endphp
                <li class="dl-grab" data-grab-id="{{ $grab->id }}" data-status="{{ $grab->status }}">
                    <div class="dl-grab-body">
                        <span class="dl-grab-title">{{ $grab->title }}</span>
                        <span class="dl-grab-meta">
                            <span class="dl-badge dl-badge-{{ $grab->protocol }}">{{ $grab->protocol === 'torrent' ? 'Torrent' : 'Usenet' }}</span>
                            {{ $grab->kind === 'audiobook' ? 'Audiobook' : 'Ebook' }} · {{ $grab->indexer }} · {{ $h($grab->size) }}{{ $grab->auto ? ' · grabbed automatically' : '' }}
                            @if($targetLabel) · <a href="{{ $targetUrl }}">{{ $targetLabel }}</a>@endif
                        </span>
                        <span class="dl-grab-status" data-grab-status role="status">{{ \App\Downloads\Downloads::statusLine($grab) }}</span>
                    </div>
                    <progress class="dl-grab-bar" max="100" value="{{ (int) $grab->progress }}" data-grab-progress aria-label="Download progress" @if($grab->status !== 'downloading') hidden @endif></progress>
                    <a class="btn btn-sm btn-secondary" data-grab-next href="#" hidden>Open</a>
                    <form method="POST" action="{{ route('downloads.grabs.fail', $grab->id) }}" data-confirm="Mark “{{ $grab->title }}” failed? It is blocklisted and its book searched again (the client keeps its copy).">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-ghost">Mark failed &amp; search again</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    @if($recent->isNotEmpty())
        <h2 class="panel-title mt-4" id="recentTitle">Recently finished</h2>
        <ul class="dl-grabs" id="downloadRecent">
            @foreach($recent as $grab)
                @php [$targetLabel, $targetUrl] = $targetOf($grab); $p = $grab->present(); @endphp
                <li class="dl-grab" data-status="{{ $grab->status }}">
                    <div class="dl-grab-body">
                        <span class="dl-grab-title">{{ $grab->title }}</span>
                        <span class="dl-grab-meta">
                            <span class="dl-badge dl-badge-{{ $grab->protocol }}">{{ $grab->protocol === 'torrent' ? 'Torrent' : 'Usenet' }}</span>
                            {{ $grab->kind === 'audiobook' ? 'Audiobook' : 'Ebook' }} · {{ $grab->updated_at?->diffForHumans() }}
                            @if($targetLabel) · <a href="{{ $targetUrl }}">{{ $targetLabel }}</a>@endif
                        </span>
                        <span class="dl-grab-status {{ $grab->status === 'failed' ? 'dl-status-failed' : 'dl-status-done' }}">{{ $p['status_line'] }}</span>
                    </div>
                    @if($p['next_url'])<a class="btn btn-sm btn-secondary" href="{{ $p['next_url'] }}">Open</a>@endif
                    @if($grab->status === 'failed' && $grab->completed_path)
                        {{-- Manual import: into a wanted request (ebook) or a book (audiobook), by id. --}}
                        <form method="POST" action="{{ route('downloads.grabs.import', $grab->id) }}" class="dl-import">
                            @csrf
                            <input type="number" min="1" class="form-control form-control-sm" name="{{ $grab->kind === 'audiobook' ? 'novel_id' : 'request_id' }}"
                                   value="{{ $grab->kind === 'audiobook' ? $grab->novel_id : $grab->novel_request_id }}"
                                   aria-label="{{ $grab->kind === 'audiobook' ? 'Book id' : 'Request id' }}" placeholder="{{ $grab->kind === 'audiobook' ? 'Book #' : 'Request #' }}">
                            <button type="submit" class="btn btn-sm btn-ghost">Import</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endif

@if($tab === 'history')
<section aria-labelledby="historyTitle">
    <h2 class="panel-title visually-hidden" id="historyTitle">History</h2>
    @if($events->isEmpty())
        <p class="ab-empty">Nothing yet.</p>
    @else
        <div class="table-responsive">
            <table class="table dl-history" id="downloadHistory">
                <thead><tr><th scope="col">When</th><th scope="col">What</th><th scope="col">Release</th><th scope="col">Details</th></tr></thead>
                <tbody>
                    @foreach($events as $event)
                        <tr data-event="{{ $event->event }}">
                            <td class="text-nowrap" title="{{ $event->created_at }}">{{ $event->created_at?->diffForHumans() }}</td>
                            <td class="text-nowrap"><span class="chip-mono {{ in_array($event->event, ['failed', 'blocklisted'], true) ? 'chip-mono-danger' : (in_array($event->event, ['imported', 'auto_approved'], true) ? 'chip-mono-success' : 'chip-mono-pending') }}">{{ $event->label() }}</span></td>
                            <td>{{ $event->title }}</td>
                            <td>{{ $event->message }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endif

@if($tab === 'wanted')
<section aria-labelledby="wantedTitle">
    <h2 class="panel-title visually-hidden" id="wantedTitle">Wanted</h2>
    <p class="form-text">Every book a reader asked for by title, and every book whose audiobook is wanted. Novarr searches for each by itself (after 1 h, 6 h, 24 h, then every 3 days) and grabs a clear match; the rest wait for your choice.</p>
    @if($wanted->isEmpty())
        <p class="ab-empty">Nothing is wanted.</p>
    @else
        <ul class="ab-list" id="wantedList">
            @foreach($wanted as $row)
                @php $busy = $row->busyGrab(); @endphp
                <li class="ab-row" data-wanted-id="{{ $row->id }}">
                    <div class="ab-row-body">
                        <span class="ab-row-title">{{ $row->title() }}</span>
                        <span class="ab-row-meta">
                            {{ $row->kind === 'audiobook' ? 'Audiobook' : 'Ebook' }}{{ $row->author() ? ' · ' . $row->author() : '' }}
                            @if($row->requester) · asked for by {{ $row->requester->name }}@endif
                            · {{ $row->last_searched_at ? 'searched ' . $row->last_searched_at->diffForHumans() : 'not searched yet' }}
                            @if($row->monitored && $row->next_search_at) · next {{ $row->next_search_at->diffForHumans() }}@endif
                            @if($row->last_error) · <span class="dl-status-failed">{{ $row->last_error }}</span>@endif
                        </span>
                    </div>
                    <span class="chip-mono {{ $busy ? 'chip-mono-success' : ($row->needs_choice ? 'chip-mono-danger' : 'chip-mono-pending') }}">
                        @if(!$row->monitored) Unmonitored
                        @elseif($busy) {{ \App\Downloads\Downloads::statusLine($busy) }}
                        @elseif($row->needs_choice) Needs your choice: {{ $row->results_count }} {{ \Illuminate\Support\Str::plural('result', $row->results_count) }}
                        @else Wanted
                        @endif
                    </span>
                    <div class="ab-row-actions">
                        <form method="POST" action="{{ route('downloads.wanted.search', $row->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">Search now</button>
                        </form>
                        @if($row->pageUrl())<a href="{{ $row->pageUrl() }}" class="btn btn-sm btn-secondary">Interactive search</a>@endif
                        <form method="POST" action="{{ route($row->monitored ? 'downloads.wanted.unmonitor' : 'downloads.wanted.monitor', $row->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-ghost">{{ $row->monitored ? 'Unmonitor' : 'Monitor' }}</button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endif

@if($tab === 'blocklist')
<section aria-labelledby="blocklistTitle">
    <h2 class="panel-title visually-hidden" id="blocklistTitle">Blocklist</h2>
    <p class="form-text">Releases that failed: never grabbed automatically again (a manual Grab still can). Remove one to let it be grabbed again.</p>
    @if($blocklist->isEmpty())
        <p class="ab-empty">Nothing is blocklisted.</p>
    @else
        <ul class="ab-list" id="blocklist">
            @foreach($blocklist as $block)
                <li class="ab-row">
                    <div class="ab-row-body">
                        <span class="ab-row-title">{{ $block->title }}</span>
                        <span class="ab-row-meta">{{ $block->indexer }} · {{ $block->created_at?->diffForHumans() }}@if($block->reason) · {{ $block->reason }}@endif</span>
                    </div>
                    <form method="POST" action="{{ route('downloads.blocklist.destroy', $block->id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-ghost">Remove &amp; retry</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endif

@if($tab === 'settings')
<section aria-labelledby="settingsTitle" class="dl-settings">
    <h2 class="panel-title" id="settingsTitle">Automation</h2>
    <form method="POST" action="{{ route('downloads.settings') }}" class="card"><div class="card-body">
        @csrf
        <div class="form-check"><input type="checkbox" class="form-check-input" id="autoSearch" name="auto_search" value="1" @checked($settings['auto_search'])><label class="form-check-label" for="autoSearch">Search the wanted list by itself</label></div>
        <div class="form-check"><input type="checkbox" class="form-check-input" id="autoGrab" name="auto_grab" value="1" @checked($settings['auto_grab'])><label class="form-check-label" for="autoGrab">Grab a clear match by itself (exact title and author, an allowed format, a plausible size, nothing odd in its name)</label></div>
        <div class="form-check mb-3"><input type="checkbox" class="form-check-input" id="autoApprove" name="auto_approve" value="1" @checked($settings['auto_approve'])><label class="form-check-label" for="autoApprove">Add a downloaded ePub at once when its own title and author are the wanted book's</label></div>
        <div class="row g-3">
            <div class="col-sm-4">
                <label class="form-label" for="preferProtocol">Try first</label>
                <select class="form-select" id="preferProtocol" name="prefer_protocol">
                    <option value="usenet" @selected($settings['prefer_protocol'] === 'usenet')>Usenet, then torrents</option>
                    <option value="torrent" @selected($settings['prefer_protocol'] === 'torrent')>Torrents, then usenet</option>
                </select>
            </div>
            <div class="col-sm-4">
                <label class="form-label" for="minSeeders">Torrents need at least</label>
                <input type="number" class="form-control" id="minSeeders" name="min_seeders" min="0" max="1000" value="{{ $settings['min_seeders'] }}">
                <span class="form-text">seeders to be grabbed by itself</span>
            </div>
            <div class="col-sm-4">
                <label class="form-label" for="searchesPerDay">Searches a day</label>
                <input type="number" class="form-control" id="searchesPerDay" name="searches_per_day" min="0" max="10000" value="{{ $settings['searches_per_day'] }}">
                <span class="form-text">automatic ones stop there (indexers limit API calls)</span>
            </div>
        </div>
        <p class="form-text mt-3 mb-2">Formats grabbed by themselves: ebooks {{ implode(', ', config('novarr.downloads.formats.ebook')) }} (never pdf); audiobooks {{ implode(', ', config('novarr.downloads.formats.audiobook')) }}. A download that does not move for {{ config('novarr.downloads.stall_hours') }} hours is failed and replaced.</p>
        <button type="submit" class="btn btn-primary">Save</button>
    </div></form>
</section>
@endif
</div>
@endsection
