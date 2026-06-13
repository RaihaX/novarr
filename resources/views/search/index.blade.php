@extends('layouts.app')

@section('content')
<h1 class="mb-3">Search</h1>

<form method="GET" action="{{ route('search.index') }}" class="mb-4">
    <div class="input-group" style="max-width: 600px;">
        <input type="search" name="q" class="form-control" value="{{ $q }}" placeholder="Search chapter titles and content…" autofocus>
        <button type="submit" class="btn btn-primary">Search</button>
    </div>
    <div class="form-text">Searches the text of downloaded chapters.</div>
</form>

@if($q === '')
    <p class="text-muted">Enter a search term above.</p>
@elseif($results->isEmpty())
    <p class="text-muted">No chapters matched “{{ $q }}”.</p>
@else
    <p class="text-muted mb-3">{{ $results->count() }} matching chapter(s) across {{ $grouped->count() }} novel(s).</p>

    @foreach($grouped as $novelName => $rows)
        <div class="card mb-3">
            <div class="card-header">
                <a href="{{ route('novels.show', $rows->first()['novel']->id) }}" class="fw-semibold text-decoration-none">{{ $novelName }}</a>
                <span class="text-muted ms-2" style="font-size: 13px;">{{ $rows->count() }} match(es)</span>
            </div>
            <div class="list-group list-group-flush">
                @foreach($rows as $row)
                    <a href="{{ route('chapters.show', $row['chapter']->id) }}" class="list-group-item list-group-item-action bg-transparent">
                        <div class="d-flex justify-content-between gap-2">
                            <span class="fw-semibold" style="font-size: 14px;">{{ Str::limit($row['chapter']->label ?: 'Chapter ' . $row['chapter']->chapter, 80) }}</span>
                            <span class="text-muted text-nowrap" style="font-size: 12px;">Ch. {{ $row['chapter']->chapter }}</span>
                        </div>
                        @if($row['snippet'])
                            <div class="text-muted mt-1" style="font-size: 13px;">{{ $row['snippet'] }}</div>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
@endif
@endsection
