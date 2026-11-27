@extends('layouts.app')

{{-- Admin: the audiobooks inbox (waiting / failed + Retry), the unmatched
     audiobooks with a book search to match them (or leave them standalone),
     "Scan now" and totals (App\Http\Controllers\AudiobookController::manage()). --}}

@section('title', 'Manage audiobooks')
@section('breadcrumb')
    @include('partials.breadcrumb', ['trail' => [['Books', route('novels.index', ['medium' => 'book'])], ['Audiobooks', route('audiobooks.index')], ['Manage']]])
@endsection

@section('content')
@php
    $h = fn ($bytes) => \App\Support\Bytes::human($bytes);
    $dur = fn ($ms) => \App\Audiobooks\AudiobookLibrary::humanDuration((int) $ms);
    $stateLabel = [
        \App\Audiobooks\AudiobookInbox::COPYING => 'Still copying',
        \App\Audiobooks\AudiobookInbox::SETTLING => 'Settling',
        \App\Audiobooks\AudiobookInbox::READY => 'Ready: next scan',
    ];
    $last = $status['last_scan'];
@endphp
<div class="ab-page ab-manage">
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Books · Audiobooks</span>
        <h1 class="page-title mb-0">Manage audiobooks</h1>
        <p class="page-head-sub mb-0">
            Drop an audiobook (an <code>.m4b</code>, <code>.mp3</code>… file, or a folder of them) into
            <code>{{ $status['inbox'] }}</code>. Every ten minutes the ones whose copy has finished
            (unchanged for {{ $dur($status['settle_seconds'] * 1000) }}) are moved into <code>{{ $root }}</code> and matched to their book.
        </p>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('audiobooks.index') }}" class="btn btn-secondary">Audiobooks</a>
        <form method="POST" action="{{ route('audiobooks.manage.scan') }}">
            @csrf
            <button type="submit" class="btn btn-primary" id="scanNow"><x-icon name="refresh-cw" :size="14" /> Scan now</button>
        </form>
    </div>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif
@if($status['problem'])<div class="alert alert-danger" role="alert" id="probeProblem">{{ $status['problem'] }} Nothing is imported until then; the inbox is left as it is.</div>@endif

<div class="ab-manage-totals" id="audiobookTotals">
    <span><span class="mono-num">{{ number_format($totals['count']) }}</span> {{ Str::plural('audiobook', $totals['count']) }}</span>
    <span><span class="mono-num">{{ number_format($totals['matched']) }}</span> matched to a book</span>
    <span>{{ $dur($totals['duration_ms']) }} of listening</span>
    <span>{{ $h($totals['size_bytes']) }}</span>
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

{{-- Waiting in the inbox --}}
<section aria-labelledby="inboxTitle" class="mb-4">
    <h2 class="panel-title" id="inboxTitle">In the inbox <span class="count-chip">{{ count($status['pending']) }}</span></h2>
    @if(count($status['pending']) === 0)
        <p class="ab-empty">Nothing waiting.</p>
    @else
        <ul class="ab-list" id="inboxPending">
            @foreach($status['pending'] as $p)
                <li class="ab-row" data-inbox-entry="{{ $p['name'] }}">
                    <div class="ab-row-body">
                        <span class="ab-row-title">{{ $p['name'] }}</span>
                        <span class="ab-row-meta">
                            {{ $p['kind'] === 'folder' ? 'Folder' : 'File' }} · {{ $p['audio_files'] }} audio {{ Str::plural('file', $p['audio_files']) }} · {{ $h($p['size_bytes']) }}
                            @if($p['state'] !== \App\Audiobooks\AudiobookInbox::COPYING && $p['stable_seconds'] > 0) · unchanged for {{ $dur($p['stable_seconds'] * 1000) }}@endif
                        </span>
                    </div>
                    <span class="chip-mono {{ $p['state'] === \App\Audiobooks\AudiobookInbox::READY ? 'chip-mono-success' : 'chip-mono-pending' }}">{{ $stateLabel[$p['state']] ?? $p['state'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>

{{-- Failed --}}
@if(count($status['failed']) > 0)
<section aria-labelledby="failedTitle" class="mb-4">
    <h2 class="panel-title" id="failedTitle">Not imported <span class="count-chip">{{ count($status['failed']) }}</span></h2>
    <p class="ab-empty">Moved to <code>{{ $status['inbox'] }}/_failed</code> with the reason beside it; nothing was deleted. Fix it, then Retry puts it back in the inbox.</p>
    <ul class="ab-list" id="inboxFailed">
        @foreach($status['failed'] as $f)
            <li class="ab-row">
                <div class="ab-row-body">
                    <span class="ab-row-title">{{ $f['name'] }}</span>
                    <span class="ab-row-meta">{{ $f['reason'] }}@if($f['failed_at']) · {{ \Carbon\Carbon::createFromTimestamp($f['failed_at'])->diffForHumans() }}@endif</span>
                </div>
                <form method="POST" action="{{ route('audiobooks.manage.retry') }}" class="ab-row-actions">
                    @csrf
                    <input type="hidden" name="name" value="{{ $f['name'] }}">
                    <button type="submit" class="btn btn-sm btn-secondary">Retry</button>
                </form>
            </li>
        @endforeach
    </ul>
</section>
@endif

{{-- Matching one audiobook to a book --}}
@if($searchFor)
<section aria-labelledby="matchTitle" class="mb-4" id="matchPanel">
    <h2 class="panel-title" id="matchTitle">Which book is “{{ $searchFor->title }}”?</h2>
    <p class="ab-empty">{{ $searchFor->author ?? 'No author in its tags' }} · {{ $dur($searchFor->duration_ms) }} · from “{{ $searchFor->source_name }}”@if($searchFor->match_note) · {{ ucfirst($searchFor->match_note) }}@endif</p>
    <form method="GET" action="{{ route('audiobooks.manage') }}" class="ab-search" role="search">
        <input type="hidden" name="for" value="{{ $searchFor->id }}">
        <input type="search" name="q" class="form-control" placeholder="Title, author or series" aria-label="Search the library's books" value="{{ $q }}">
        <button type="submit" class="btn btn-secondary">Search books</button>
    </form>
    <ul class="ab-list" id="matchResults">
        @forelse($results as $book)
            @php $taken = $linked[$book->id] ?? null; @endphp
            <li class="ab-row">
                <div class="ab-row-cover">@if($c = $book->coverUrl('sm'))<img src="{{ $c }}" alt="" loading="lazy">@endif</div>
                <div class="ab-row-body">
                    <span class="ab-row-title">{{ $book->name }}</span>
                    <span class="ab-row-meta">{{ $book->author ?: 'Unknown author' }}@if($book->series) · {{ $book->series }}@endif</span>
                </div>
                <div class="ab-row-actions">
                    @if($taken && $taken !== $searchFor->id)
                        <span class="ab-row-meta">Has an audiobook</span>
                    @elseif($taken === $searchFor->id)
                        <span class="ab-row-meta">Matched</span>
                    @else
                        <form method="POST" action="{{ route('audiobooks.manage.match', $searchFor->id) }}">
                            @csrf
                            <input type="hidden" name="novel_id" value="{{ $book->id }}">
                            <button type="submit" class="btn btn-sm btn-primary">It’s this one</button>
                        </form>
                    @endif
                </div>
            </li>
        @empty
            <li class="ab-row"><span class="ab-row-meta">No book in the library matches “{{ $q }}”.</span></li>
        @endforelse
    </ul>
    <form method="POST" action="{{ route('audiobooks.manage.match', $searchFor->id) }}" class="d-flex gap-2 align-items-center">
        @csrf
        <input type="hidden" name="standalone" value="1">
        <button type="submit" class="btn btn-secondary btn-sm">Leave it standalone</button>
        <span class="ab-row-meta">It plays with its own title, author and cover.</span>
    </form>
</section>
@endif

{{-- Unmatched --}}
<section aria-labelledby="unmatchedTitle" class="mb-4">
    <h2 class="panel-title" id="unmatchedTitle">Unmatched <span class="count-chip">{{ $unmatched->count() }}</span></h2>
    @if($unmatched->isEmpty())
        <p class="ab-empty">Every audiobook is matched to its book or left standalone.</p>
    @else
        <ul class="ab-list" id="unmatchedList">
            @foreach($unmatched as $a)
                <li class="ab-row" data-audiobook="{{ $a->id }}">
                    <div class="ab-row-cover">@if($c = $a->coverUrl('sm'))<img src="{{ $c }}" alt="" loading="lazy">@endif</div>
                    <div class="ab-row-body">
                        <span class="ab-row-title">{{ $a->title }}</span>
                        <span class="ab-row-meta">{{ $a->author ?? 'No author' }}{{ $a->narrator ? ' · read by ' . $a->narrator : '' }} · {{ $dur($a->duration_ms) }} · {{ ucfirst((string) $a->match_note) }}</span>
                    </div>
                    <div class="ab-row-actions">
                        <a href="{{ route('audiobooks.manage', ['for' => $a->id]) }}#matchPanel" class="btn btn-sm btn-primary">Match to a book</a>
                        <a href="{{ route('audiobooks.show', $a->id) }}" class="btn btn-sm btn-ghost">Play</a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

{{-- Recently added (matched or standalone) --}}
<section aria-labelledby="recentTitle">
    <h2 class="panel-title" id="recentTitle">Recently added</h2>
    @if($recent->isEmpty())
        <p class="ab-empty">None yet.</p>
    @else
        <ul class="ab-list" id="recentList">
            @foreach($recent as $a)
                <li class="ab-row" data-audiobook="{{ $a->id }}">
                    <div class="ab-row-cover">@if($c = $a->coverUrl('sm'))<img src="{{ $c }}" alt="" loading="lazy">@endif</div>
                    <div class="ab-row-body">
                        <span class="ab-row-title">{{ $a->title }}</span>
                        <span class="ab-row-meta">
                            @if($a->novel)
                                The audiobook of <a href="{{ route('novels.show', $a->novel->id) }}">{{ $a->novel->name }}</a>
                            @else
                                Standalone
                            @endif
                            · {{ $dur($a->duration_ms) }} · {{ $h($a->size_bytes) }}
                        </span>
                    </div>
                    <div class="ab-row-actions">
                        <a href="{{ route('audiobooks.manage', ['for' => $a->id]) }}#matchPanel" class="btn btn-sm btn-ghost">Change book</a>
                        <a href="{{ route('audiobooks.show', $a->id) }}" class="btn btn-sm btn-ghost">Play</a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
</div>
@endsection
