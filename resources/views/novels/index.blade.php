@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Novels</h1>
    <form class="d-flex" method="GET" action="{{ route('novels.index') }}">
        <input type="text" name="search" class="form-control me-2" placeholder="Search novels..." value="{{ request('search') }}">
        <button type="submit" class="btn btn-outline-primary">Search</button>
        @if(request('search'))
            <a href="{{ route('novels.index') }}" class="btn btn-outline-secondary ms-1">Clear</a>
        @endif
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped table-hover mb-0">
            <thead class="table-dark">
                <tr>
                    <th style="width: 60px">Cover</th>
                    <th>Name</th>
                    <th>Author</th>
                    <th>Group</th>
                    <th>Status</th>
                    <th style="width: 200px">Progress</th>
                    <th>Chapters</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($novels as $novel)
                    <tr>
                        <td>
                            @if($novel->file)
                                <img src="{{ Storage::url($novel->file->file_path) }}" alt="" style="width: 40px; height: 56px; object-fit: cover; border-radius: 3px;">
                            @else
                                <div style="width: 40px; height: 56px; background: #dee2e6; border-radius: 3px; display: flex; align-items: center; justify-content: center;">
                                    <small class="text-muted">N/A</small>
                                </div>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('novels.show', $novel->id) }}" class="text-decoration-none fw-bold">
                                {{ $novel->name }}
                            </a>
                        </td>
                        <td>{{ $novel->author ?? '-' }}</td>
                        <td>{{ $novel->group->label ?? '-' }}</td>
                        <td>
                            @if($novel->status)
                                <span class="badge bg-info">Completed</span>
                            @else
                                <span class="badge bg-success">Active</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $total = $novel->chapters_count ?? 0;
                                $downloaded = $novel->downloaded_chapters_count ?? 0;
                                $pct = $total > 0 ? round(($downloaded / $total) * 100) : 0;
                            @endphp
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar bg-info" style="width: {{ $pct }}%">{{ $pct }}%</div>
                            </div>
                        </td>
                        <td>{{ $novel->downloaded_chapters_count ?? 0 }} / {{ $novel->chapters_count ?? 0 }}</td>
                        <td>
                            <a href="{{ route('novels.show', $novel->id) }}" class="btn btn-sm btn-outline-primary">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No novels found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($novels->hasPages())
        <div class="card-footer">
            {{ $novels->appends(request()->query())->links() }}
        </div>
    @endif
</div>
@endsection
