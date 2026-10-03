@extends('layouts.app')

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <h1 class="page-title mb-0">Failure snapshots</h1>
    <a href="{{ route('novels.show', $novel->id) }}" class="btn btn-secondary">Back to {{ $novel->name }}</a>
</div>

<p class="text-muted">
    Pages the scraper fetched for <strong>{{ $novel->name }}</strong> but could not use (no or too little
    chapter text, or an empty table of contents). The newest {{ $keep }} are kept for up to {{ $days }} days.
    @unless($enabled)
        <br><strong>Snapshots are currently disabled</strong> (<code>novarr.snapshots.enabled</code>).
    @endunless
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Chapter</th>
                    <th style="width: 150px;">Reason</th>
                    <th style="width: 190px;">Captured</th>
                    <th style="width: 110px;">Size</th>
                    <th style="width: 120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($snapshots as $snapshot)
                    <tr>
                        <td>
                            @if($snapshot['chapter_id'] > 0)
                                <a href="{{ route('chapters.show', $snapshot['chapter_id']) }}">{{ $snapshot['label'] }}</a>
                            @else
                                {{ $snapshot['label'] }}
                            @endif
                        </td>
                        <td class="mono-muted">{{ str_replace('_', ' ', $snapshot['reason']) }}</td>
                        <td class="mono-muted">{{ $snapshot['time']->format('Y-m-d H:i:s') }}</td>
                        <td class="mono-muted">{{ number_format($snapshot['size'] / 1024, 1) }} KB</td>
                        <td>
                            <a href="{{ route('novels.snapshots', [$novel->id, $snapshot['file']]) }}" class="btn btn-secondary" target="_blank" rel="noopener">View HTML</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No failure snapshots for this novel.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
