@extends('layouts.app')

{{-- Admin: the comics inbox (waiting / not imported + Retry), "Scan now",
     comic requests waiting to arrive, the recent series and a Usenet &
     torrents search (App\Http\Controllers\ComicController::manage()). --}}

@section('title', 'Manage comics')
@section('breadcrumb')
    @include('partials.breadcrumb', ['trail' => [['Comics', route('novels.index', ['medium' => 'comic'])], ['Manage']]])
@endsection

@section('content')
@php
    $h = fn ($bytes) => \App\Support\Bytes::human($bytes);
    $stateLabel = [
        \App\Comics\ComicInbox::COPYING => 'Still copying',
        \App\Comics\ComicInbox::SETTLING => 'Settling',
        \App\Comics\ComicInbox::READY => 'Ready: next scan',
        \App\Downloads\AudiobookGrabs::DOWNLOADING => 'Downloading',
        \App\Downloads\AudiobookGrabs::SEEDING => 'Seeding',
    ];
    $last = $status['last_scan'];
@endphp
<div class="ab-page ab-manage">
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Comics</span>
        <h1 class="page-title mb-0">Manage comics</h1>
        <p class="page-head-sub mb-0">
            Drop a comic (a <code>.cbz</code>, <code>.cbr</code>, <code>.cb7</code>, <code>.cbt</code> or <code>.pdf</code> file,
            a folder of them, or a folder of page images) into <code>{{ $status['inbox'] }}</code>; downloads finish there too.
            Every ten minutes the ones whose copy has finished (unchanged for {{ $status['settle_seconds'] }} seconds) are unpacked
            onto the manga disk and added to their series. A torrent's files stay to seed; anything else is removed once imported.
        </p>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('novels.index', ['medium' => 'comic']) }}" class="btn btn-secondary">Comics</a>
        <form method="POST" action="{{ route('comics.manage.scan') }}">
            @csrf
            <button type="submit" class="btn btn-primary" id="scanComicsNow"><x-icon name="refresh-cw" :size="14" /> Scan now</button>
        </form>
    </div>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif
@unless($comicVine)
    <div class="alert alert-info" role="status" id="comicVineOff">
        Comic Vine is not set up, so series details come from the files' own ComicInfo.xml and the book catalogues, and covers from the first page.
        Set <code>NOVARR_COMICVINE_API_KEY</code> (a free key from comicvine.gamespot.com/api) for descriptions, publishers and covers.
    </div>
@endunless

<div class="ab-manage-totals" id="comicTotals">
    <span><span class="mono-num">{{ number_format($totals['series']) }}</span> {{ Str::plural('series', $totals['series']) }}</span>
    <span><span class="mono-num">{{ number_format($totals['issues']) }}</span> {{ Str::plural('issue', $totals['issues']) }} and volumes</span>
    <span>
        Last scan:
        @if($last)
            {{ \Carbon\Carbon::parse($last['at'])->diffForHumans() }}
            ({{ $last['imported'] }} imported, {{ $last['failed'] }} failed, {{ $last['waiting'] }} waiting)
        @else
            not yet
        @endif
    </span>
</div>

@if(\App\Downloads\Downloads::enabled())
<div class="mb-4" id="comicSearch">
    @include('downloads._finder', [
        'kind' => 'comic',
        'query' => '',
        'requestId' => null,
        'novelId' => null,
        'grabs' => \App\DownloadGrab::where('kind', 'comic')->orderByDesc('id')->limit(15)->get(),
        'heading' => 'Search for a comic',
        'finderId' => 'comicManageFinder',
    ])
    <p class="form-text"><a href="{{ route('downloads.index') }}">All downloads</a> · <a href="{{ route('downloads.index', ['tab' => 'wanted']) }}">Wanted</a></p>
</div>
@endif

@if($waiting->isNotEmpty())
<section aria-labelledby="comicRequestsTitle" class="mb-4">
    <h2 class="panel-title" id="comicRequestsTitle">Asked for by readers <span class="count-chip">{{ $waiting->count() }}</span></h2>
    <p class="ab-empty">Approved by themselves when a comic of the same series arrives (downloaded, or dropped into the inbox).</p>
    <ul class="ab-list" id="comicRequests">
        @foreach($waiting as $req)
            <li class="ab-row">
                <div class="ab-row-body">
                    <span class="ab-row-title">{{ $req->title }}</span>
                    <span class="ab-row-meta">{{ $req->author ?: 'Comic' }} · asked {{ $req->created_at?->diffForHumans() }}</span>
                </div>
                @if(\App\Downloads\Downloads::enabled())
                    <a class="btn btn-sm btn-secondary" href="{{ route('downloads.index', ['tab' => 'wanted']) }}">Find it</a>
                @endif
            </li>
        @endforeach
    </ul>
</section>
@endif

<section aria-labelledby="comicInboxTitle" class="mb-4">
    <h2 class="panel-title" id="comicInboxTitle">In the inbox <span class="count-chip">{{ count($status['pending']) }}</span></h2>
    @if(count($status['pending']) === 0)
        <p class="ab-empty">Nothing waiting.</p>
    @else
        <ul class="ab-list" id="comicInboxPending">
            @foreach($status['pending'] as $p)
                <li class="ab-row" data-inbox-entry="{{ $p['name'] }}">
                    <div class="ab-row-body">
                        <span class="ab-row-title">{{ $p['name'] }}</span>
                        <span class="ab-row-meta">
                            {{ $p['kind'] === 'folder' ? 'Folder' : 'File' }} · {{ $p['comics'] }} {{ Str::plural('comic', $p['comics']) }} · {{ $h($p['size_bytes']) }}
                            @if(!empty($p['note'])) · {{ $p['note'] }}@endif
                        </span>
                    </div>
                    <span class="chip-mono {{ $p['state'] === \App\Comics\ComicInbox::READY ? 'chip-mono-success' : 'chip-mono-pending' }}">{{ $stateLabel[$p['state']] ?? $p['state'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>

@if(count($status['failed']) > 0)
<section aria-labelledby="comicFailedTitle" class="mb-4">
    <h2 class="panel-title" id="comicFailedTitle">Not imported <span class="count-chip">{{ count($status['failed']) }}</span></h2>
    <p class="ab-empty">Moved to <code>{{ $status['inbox'] }}/_failed</code> with the reason beside it; nothing was deleted. Fix it, then Retry puts it back in the inbox.</p>
    <ul class="ab-list" id="comicInboxFailed">
        @foreach($status['failed'] as $f)
            <li class="ab-row">
                <div class="ab-row-body">
                    <span class="ab-row-title">{{ $f['name'] }}</span>
                    <span class="ab-row-meta">{{ $f['reason'] }}@if($f['failed_at']) · {{ \Carbon\Carbon::createFromTimestamp($f['failed_at'])->diffForHumans() }}@endif</span>
                </div>
                <form method="POST" action="{{ route('comics.manage.retry') }}" class="ab-row-actions">
                    @csrf
                    <input type="hidden" name="name" value="{{ $f['name'] }}">
                    <button type="submit" class="btn btn-sm btn-secondary">Retry</button>
                </form>
            </li>
        @endforeach
    </ul>
</section>
@endif

<section aria-labelledby="comicRecentTitle" class="mb-4">
    <h2 class="panel-title" id="comicRecentTitle">Recently updated series</h2>
    @if($recent->isEmpty())
        <p class="ab-empty">No comics yet.</p>
    @else
        <ul class="ab-list" id="comicRecent">
            @foreach($recent as $series)
                <li class="ab-row">
                    <div class="ab-row-body">
                        <a class="ab-row-title" href="{{ route('novels.show', $series->id) }}">{{ \App\Comics\ComicLibrary::label($series) }}</a>
                        <span class="ab-row-meta">{{ number_format((int) $series->no_of_chapters) }} {{ Str::plural('issue', (int) $series->no_of_chapters) }}@if($series->publisher) · {{ $series->publisher }}@endif @if($series->reading_mode === 'rtl') · right to left @endif</span>
                    </div>
                    <form method="POST" action="{{ route('comics.lookup', $series->id) }}" class="ab-row-actions">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary">Look up details</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</section>
</div>
@endsection
