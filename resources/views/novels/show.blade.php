@extends('layouts.app')

@section('content')
<div class="mb-3">
    <a href="{{ route('novels.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Back to Novels</a>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                @if($data->file)
                    <img src="{{ Storage::url($data->file->file_path) }}" alt="{{ $data->name }}" class="img-fluid rounded mb-3" style="max-height: 300px;">
                @else
                    <div class="bg-light rounded d-flex align-items-center justify-content-center mb-3" style="height: 200px;">
                        <span class="text-muted">No Cover</span>
                    </div>
                @endif
                <div class="d-grid gap-2">
                    <a href="{{ route('novels.download_epub', $data->id) }}" class="btn btn-primary btn-sm">Download ePub</a>
                    <a href="{{ route('novels.get_metadata', $data->id) }}" class="btn btn-outline-secondary btn-sm">Update Metadata</a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-9">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="mb-0">{{ $data->name }}</h3>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-sm-6">
                        <strong>Author:</strong> {{ $data->author ?? 'Unknown' }}
                    </div>
                    <div class="col-sm-6">
                        <strong>Status:</strong>
                        @if($data->status)
                            <span class="badge bg-info">Completed</span>
                        @else
                            <span class="badge bg-success">Active</span>
                        @endif
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-6">
                        <strong>Group:</strong> {{ $data->group->label ?? '-' }}
                    </div>
                    <div class="col-sm-6">
                        <strong>Language:</strong> {{ $data->language->label ?? '-' }}
                    </div>
                </div>
                @if($data->external_url)
                    <div class="mb-3">
                        <strong>URL:</strong> <a href="{{ $data->external_url }}" target="_blank">{{ $data->external_url }}</a>
                    </div>
                @endif
                @if($data->description)
                    <div class="mb-0">
                        <strong>Description:</strong>
                        <div class="mt-1">{!! $data->description !!}</div>
                    </div>
                @endif
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body py-2">
                        <div class="h4 mb-0">{{ $current_chapters }}</div>
                        <small class="text-muted">Downloaded</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body py-2">
                        <div class="h4 mb-0">{{ $current_chapters_not_downloaded }}</div>
                        <small class="text-muted">Pending</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body py-2">
                        <div class="h4 mb-0">{{ count($missing_chapters) }}</div>
                        <small class="text-muted">Missing</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body py-2">
                        <div class="h4 mb-0">{{ $progress }}%</div>
                        <small class="text-muted">Progress</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="progress mb-3" style="height: 25px;">
            <div class="progress-bar bg-info" style="width: {{ $progress }}%">{{ $progress }}%</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Chapters</h5>
        <span class="badge bg-secondary">{{ $chapters->total() }} total</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-sm mb-0">
            <thead>
                <tr>
                    <th>Chapter</th>
                    <th>Book</th>
                    <th>Label</th>
                    <th>Status</th>
                    <th>Download Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse($chapters as $chapter)
                    <tr>
                        <td>{{ $chapter->chapter }}</td>
                        <td>{{ $chapter->book ?: '-' }}</td>
                        <td>{{ Str::limit($chapter->label, 60) }}</td>
                        <td>
                            @if($chapter->status)
                                <span class="badge bg-success">Downloaded</span>
                            @else
                                <span class="badge bg-warning text-dark">Pending</span>
                            @endif
                        </td>
                        <td>{{ $chapter->download_date ? $chapter->download_date->format('Y-m-d H:i') : '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-3">No chapters found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($chapters->hasPages())
        <div class="card-footer">
            {{ $chapters->links() }}
        </div>
    @endif
</div>
@endsection
