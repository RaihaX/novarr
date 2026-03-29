@extends('layouts.app')

@push('styles')
<style>
    .chapter-content {
        font-size: 16px;
        line-height: 1.9;
        color: #d1d1d1;
        max-width: 800px;
        margin: 0 auto;
    }
    .chapter-content p {
        margin-bottom: 1em;
    }
    .chapter-nav .btn {
        min-width: 140px;
    }
</style>
@endpush

@section('content')
<div class="mb-3 d-flex justify-content-between align-items-center">
    <a href="{{ route('novels.show', $chapter->novel_id) }}" class="btn btn-outline-secondary btn-sm">&larr; {{ $chapter->novel->name ?? 'Back' }}</a>
    <div class="chapter-nav d-flex gap-2">
        @if($prev)
            <a href="{{ route('chapters.show', $prev->id) }}" class="btn btn-sm btn-outline-secondary">&larr; Ch. {{ $prev->chapter }}</a>
        @endif
        @if($next)
            <a href="{{ route('chapters.show', $next->id) }}" class="btn btn-sm btn-outline-secondary">Ch. {{ $next->chapter }} &rarr;</a>
        @endif
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h4 class="mb-1">{{ $chapter->label ?: 'Chapter ' . $chapter->chapter }}</h4>
        <div class="d-flex gap-3" style="font-size: 13px;">
            <span class="text-muted">Chapter {{ $chapter->chapter }}</span>
            @if($chapter->book)
                <span class="text-muted">Book {{ $chapter->book }}</span>
            @endif
            @if($chapter->status)
                <span class="badge bg-success" style="font-size: 11px;">Downloaded</span>
            @else
                <span class="badge bg-warning text-dark" style="font-size: 11px;">Pending</span>
            @endif
            @if($chapter->download_date)
                <span class="text-muted">{{ $chapter->download_date->format('Y-m-d H:i') }}</span>
            @endif
        </div>
    </div>
    <div class="card-body">
        @if($chapter->getRawOriginal('description'))
            <div class="chapter-content">
                {!! $chapter->description !!}
            </div>
        @else
            <p class="text-muted text-center py-5">No content available for this chapter.</p>
        @endif
    </div>
</div>

<div class="d-flex justify-content-between chapter-nav">
    <div>
        @if($prev)
            <a href="{{ route('chapters.show', $prev->id) }}" class="btn btn-outline-secondary">&larr; {{ Str::limit($prev->label ?: 'Ch. ' . $prev->chapter, 40) }}</a>
        @endif
    </div>
    <div>
        @if($next)
            <a href="{{ route('chapters.show', $next->id) }}" class="btn btn-outline-secondary">{{ Str::limit($next->label ?: 'Ch. ' . $next->chapter, 40) }} &rarr;</a>
        @endif
    </div>
</div>
@endsection
