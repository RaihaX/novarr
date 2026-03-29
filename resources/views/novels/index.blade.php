@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Novels</h1>
    <form class="d-flex" method="GET" action="{{ route('novels.index') }}">
        <input type="text" name="search" class="form-control form-control-sm me-2" placeholder="Search novels..." value="{{ request('search') }}" style="width: 200px;">
        <button type="submit" class="btn btn-sm btn-primary">Search</button>
        @if(request('search'))
            <a href="{{ route('novels.index') }}" class="btn btn-sm btn-outline-secondary ms-1">Clear</a>
        @endif
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
            <thead class="table-dark">
                <tr>
                    <th style="width: 50px"></th>
                    <th>Name</th>
                    <th>Author</th>
                    <th>Group</th>
                    <th style="width: 90px">Status</th>
                    <th style="width: 180px">Progress</th>
                    <th style="width: 100px">Chapters</th>
                </tr>
            </thead>
            <tbody>
                @forelse($novels as $novel)
                    <tr>
                        <td>
                            @if($novel->file)
                                <img src="{{ Storage::url($novel->file->file_path) }}" alt="" style="width: 36px; height: 50px; object-fit: cover; border-radius: 3px;">
                            @else
                                <div style="width: 36px; height: 50px; background: #2c3034; border-radius: 3px; display: flex; align-items: center; justify-content: center;">
                                    <small class="text-muted" style="font-size: 10px;">N/A</small>
                                </div>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('novels.show', $novel->id) }}" class="text-decoration-none fw-semibold">
                                {{ $novel->name }}
                            </a>
                        </td>
                        <td class="text-muted">{{ $novel->author ?? '-' }}</td>
                        <td class="text-muted">{{ $novel->group->label ?? '-' }}</td>
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
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar {{ $pct >= 100 ? 'bg-success' : 'bg-info' }}" style="width: {{ $pct }}%"></div>
                                </div>
                                <small class="text-muted" style="width: 35px; text-align: right;">{{ $pct }}%</small>
                            </div>
                        </td>
                        <td class="text-muted">{{ $downloaded }} / {{ $total }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No novels found.</td>
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
