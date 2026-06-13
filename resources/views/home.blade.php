@extends('layouts.app')

@section('content')
<h1 class="mb-4">Dashboard</h1>

{{-- Stats --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <a href="{{ route('novels.index', ['status' => 0]) }}" class="card dash-stat text-decoration-none">
            <div class="card-body">
                <div class="dash-stat-value text-success">{{ $stats['active'] }}</div>
                <div class="dash-stat-label">Active novels</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <a href="{{ route('novels.index', ['status' => 1]) }}" class="card dash-stat text-decoration-none">
            <div class="card-body">
                <div class="dash-stat-value text-info">{{ $stats['completed'] }}</div>
                <div class="dash-stat-label">Completed novels</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value text-warning">{{ $stats['pending'] }}</div>
                <div class="dash-stat-label">Pending downloads</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value text-primary">{{ $stats['downloaded_today'] }}</div>
                <div class="dash-stat-label">Downloaded (24h)</div>
            </div>
        </div>
    </div>
</div>

{{-- Needs attention --}}
@if(count($attention) > 0)
    <div class="card mb-4 border-warning-subtle">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">⚠️ Needs Attention</h5>
            <span class="badge bg-warning text-dark">{{ count($attention) }}</span>
        </div>
        <div class="list-group list-group-flush" style="max-height: 340px; overflow-y: auto;">
            @foreach($attention as $item)
                <div class="list-group-item d-flex flex-wrap gap-2 justify-content-between align-items-center bg-transparent">
                    <div class="me-auto">
                        <a href="{{ route('novels.show', $item['id']) }}" class="fw-semibold text-decoration-none">{{ $item['name'] }}</a>
                        <div class="text-muted" style="font-size: 12px;">{{ $item['reason'] }}</div>
                    </div>
                    @if(!empty($item['url']))
                        <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-warning">Test source ↗</a>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><span class="badge bg-danger me-2">{{ $missing_chapters->total() }}</span> Missing Chapters</h5>
            </div>
            <div class="table-responsive">
                @if($missing_chapters->count() > 0)
                    <table class="table table-striped table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Novel</th>
                                <th>Ch.</th>
                                <th>Label</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($missing_chapters as $chapter)
                                <tr>
                                    <td class="text-truncate" style="max-width: 160px;">
                                        @if($chapter->novel)
                                            <a href="{{ route('novels.show', $chapter->novel_id) }}">{{ $chapter->novel->name }}</a>
                                        @else
                                            Unknown
                                        @endif
                                    </td>
                                    <td>{{ $chapter->chapter }}</td>
                                    <td class="text-truncate" style="max-width: 200px;">{{ $chapter->label }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="card-body">
                        <p class="text-muted mb-0">No missing chapters!</p>
                    </div>
                @endif
            </div>
            @if($missing_chapters->hasPages())
                <div class="card-footer">
                    {{ $missing_chapters->appends(request()->query())->links() }}
                </div>
            @endif
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><span class="badge bg-success me-2" title="Chapters added in the last 24 hours">{{ $stats['downloaded_today'] }}</span> Latest Chapters <small class="text-muted fw-normal">(last 24h)</small></h5>
            </div>
            <div class="table-responsive">
                @if($latest_chapters->count() > 0)
                    <table class="table table-striped table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Novel</th>
                                <th>Ch.</th>
                                <th>Label</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($latest_chapters as $chapter)
                                <tr>
                                    <td class="text-truncate" style="max-width: 160px;">
                                        @if($chapter->novel)
                                            <a href="{{ route('novels.show', $chapter->novel_id) }}">{{ $chapter->novel->name }}</a>
                                        @else
                                            Unknown
                                        @endif
                                    </td>
                                    <td>{{ $chapter->chapter }}</td>
                                    <td class="text-truncate" style="max-width: 200px;">{{ $chapter->label }}</td>
                                    <td class="text-nowrap">{{ $chapter->created_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="card-body">
                        <p class="text-muted mb-0">No chapters yet.</p>
                    </div>
                @endif
            </div>
            @if($latest_chapters->hasPages())
                <div class="card-footer">
                    {{ $latest_chapters->appends(request()->query())->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
