@extends('layouts.app')

@section('content')
<h1 class="page-title mb-4">Search</h1>

<form method="GET" action="{{ route('search.index') }}" class="search-form">
    <div class="input-group">
        <input type="search" name="q" class="form-control" value="{{ $q }}" placeholder="Search chapter titles and content…" aria-label="Search chapters" autofocus>
        @if($novelFilter)
            <input type="hidden" name="novel" value="{{ $novelFilter->id }}">
        @endif
        <button type="submit" class="btn btn-primary">Search</button>
    </div>
    <div class="form-text mt-2">Searches the text of downloaded chapters.</div>
</form>

@if($novelFilter)
    <div class="mb-4">
        <span class="filter-chip">
            Within: {{ $novelFilter->name }}
            <a href="{{ route('search.index', ['q' => $q]) }}" class="filter-chip-clear" title="Search all novels" aria-label="Remove novel filter">&times;</a>
        </span>
    </div>
@endif

@if($q === '')
    <div class="empty-state">
        <x-brand-mark variant="mono" :size="40" class="empty-state-mark" />
        <div class="empty-state-title">Search your library</div>
        <p class="empty-state-body">Enter a term above to search across the text of every downloaded chapter.</p>
    </div>
@elseif($results->isEmpty())
    <div class="empty-state">
        <x-brand-mark variant="mono" :size="40" class="empty-state-mark" />
        <div class="empty-state-title">No matches</div>
        <p class="empty-state-body">Nothing matched &ldquo;{{ $q }}&rdquo;{{ $novelFilter ? ' in ' . $novelFilter->name : '' }}. Try a shorter phrase, or check the chapter is downloaded.</p>
    </div>
@else
    <p class="mono-muted mb-3">{{ number_format($paginator->total()) }} matching chapter(s){{ $novelFilter ? '' : ' across ' . $grouped->count() . ' novel(s) on this page' }}</p>

    @foreach($grouped as $novelName => $rows)
        <div class="card stack-card mb-3">
            <div class="stack-head">
                <a href="{{ route('novels.show', $rows->first()['novel']->id) }}" class="stack-head-title">{{ $novelName }}</a>
                <span class="stack-head-count">{{ $rows->count() }} match{{ $rows->count() === 1 ? '' : 'es' }}</span>
            </div>
            @foreach($rows as $row)
                <a href="{{ route('chapters.show', $row['chapter']->id) }}" class="stack-row">
                    <div class="d-flex justify-content-between align-items-baseline gap-3">
                        <span class="result-title">{{ Str::limit($row['chapter']->label ?: 'Chapter ' . $row['chapter']->chapter, 80) }}</span>
                        <span class="result-num">CH {{ $row['chapter']->chapter }}</span>
                    </div>
                    @if($row['snippet'])
                        <div class="result-snippet">{{ $row['snippet'] }}</div>
                    @endif
                </a>
            @endforeach
        </div>
    @endforeach

    @if($paginator && $paginator->hasPages())
        <div class="mt-4">
            {{ $paginator->links() }}
        </div>
    @endif
@endif
@endsection
