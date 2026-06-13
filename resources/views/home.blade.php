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

{{-- Continue reading --}}
@if(count($continue_reading) > 0)
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">📖 Continue Reading</h5></div>
        <div class="card-body">
            <div class="continue-row">
                @foreach($continue_reading as $item)
                    @php($novel = $item['novel'])
                    <a href="{{ route('chapters.show', $item['next']->id) }}" class="continue-card" title="Continue {{ $novel->name }} — Ch. {{ $item['next']->chapter }}">
                        <div class="poster-cover">
                            @if($novel->file)
                                <img src="{{ Storage::url($novel->file->file_path) }}" alt="Cover of {{ $novel->name }}" loading="lazy">
                            @else
                                <div class="poster-cover-placeholder"><span>{{ $novel->name }}</span></div>
                            @endif
                        </div>
                        <div class="continue-title">{{ $novel->name }}</div>
                        <div class="continue-meta">Next: Ch. {{ $item['next']->chapter }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endif

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
                    <div class="d-flex gap-2">
                        @if(!empty($item['url']))
                            <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-warning">Test source ↗</a>
                        @endif
                        <button type="button" class="btn btn-sm btn-outline-secondary ignore-btn" data-id="{{ $item['id'] }}" title="Pause automatic downloads for this novel">Ignore</button>
                    </div>
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
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="text-muted" style="font-size: 12px;">Page {{ $missing_chapters->currentPage() }} of {{ $missing_chapters->lastPage() }}</span>
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary {{ $missing_chapters->onFirstPage() ? 'disabled' : '' }}" href="{{ $missing_chapters->appends(request()->query())->previousPageUrl() }}">&laquo; Prev</a>
                        <a class="btn btn-outline-secondary {{ $missing_chapters->hasMorePages() ? '' : 'disabled' }}" href="{{ $missing_chapters->appends(request()->query())->nextPageUrl() }}">Next &raquo;</a>
                    </div>
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
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="text-muted" style="font-size: 12px;">Page {{ $latest_chapters->currentPage() }}</span>
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary {{ $latest_chapters->onFirstPage() ? 'disabled' : '' }}" href="{{ $latest_chapters->appends(request()->query())->previousPageUrl() }}">&laquo; Prev</a>
                        <a class="btn btn-outline-secondary {{ $latest_chapters->hasMorePages() ? '' : 'disabled' }}" href="{{ $latest_chapters->appends(request()->query())->nextPageUrl() }}">Next &raquo;</a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function(){

    document.querySelectorAll('.ignore-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            btn.disabled = true;

            try {
                const response = await fetch(`/novels/${btn.dataset.id}/toggle-pause`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();

                if (data.success) {
                    btn.closest('.list-group-item').remove();
                    Novarr.showToast('Novel paused — automatic downloads will skip it. Resume from the novel page.', 'success');
                } else {
                    btn.disabled = false;
                    Novarr.showToast('Failed to pause novel.', 'danger');
                }
            } catch (err) {
                btn.disabled = false;
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    });

})();
</script>
@endpush
