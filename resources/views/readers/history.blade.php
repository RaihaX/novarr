@extends('layouts.app')

@section('title', 'Reading history')

@section('breadcrumb')
    <li><a href="{{ route('readers.index') }}">Readers</a></li>
    <li aria-current="page">History</li>
@endsection

@section('content')
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Reading activity</span>
        <h1 class="page-title mb-0">Reading history</h1>
    </div>
</div>

<section class="card mb-4" aria-labelledby="historyTitle">
    <div class="card-body">
        <h2 class="visually-hidden" id="historyTitle">Sessions</h2>
        <form method="GET" action="{{ route('readers.history') }}" class="history-filter" id="readerHistoryFilter">
            <div class="form-row">
                <label class="form-label" for="history-user">Reader</label>
                <select name="user" id="history-user" class="form-select">
                    <option value="">All readers</option>
                    @foreach($accounts as $u)
                        <option value="{{ $u->id }}" @selected($userId === (int) $u->id)>{{ $u->name }} ({{ $u->username }})</option>
                    @endforeach
                </select>
            </div>
            @if($novel)
                <input type="hidden" name="novel" value="{{ $novel->id }}">
            @endif
            <div class="form-row form-check">
                <input type="checkbox" name="bulk" id="history-bulk" class="form-check-input" value="1" @checked($bulk)>
                <label for="history-bulk" class="form-check-label">Include chapters marked read in bulk</label>
            </div>
            <div class="acct-actions">
                <button type="submit" class="btn btn-secondary">Show</button>
            </div>
        </form>

        @if($novel)
            <p class="mb-3">
                <span class="filter-chip" id="historyNovelChip">
                    {{ $novel->name }}
                    <a href="{{ route('readers.history', array_filter(['user' => $userId, 'bulk' => $bulk ? 1 : null])) }}" class="filter-chip-clear" title="All series" aria-label="Remove series filter">&times;</a>
                </span>
            </p>
        @endif

        @if($sessions === [])
            <p class="text-muted mb-0" id="historyEmpty">Nothing read{{ $novel || $userId ? ' that matches' : ' yet' }}.</p>
        @else
            @include('readers._sessions', ['sessions' => $sessions, 'names' => $names, 'caption' => 'Reading sessions, newest first', 'absolute' => true])
        @endif

        @if($rows->hasPages())
            <div class="history-pages">{{ $rows->links() }}</div>
        @endif
    </div>
</section>

<p class="page-note">A session is a reader's chapters of one series read no more than {{ \App\Services\ReaderActivity::SESSION_GAP_MINUTES }} minutes apart, grouped within each page of {{ \App\Services\ReaderActivity::HISTORY_PER_PAGE }} reads. Chapters marked read in bulk are not reading and are left out unless you include them.</p>
@endsection
