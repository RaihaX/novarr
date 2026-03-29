@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <h1 class="mb-0">Novels</h1>
        <a href="{{ route('novels.create') }}" class="btn btn-sm btn-success">+ Add Novel</a>
    </div>
    <form class="d-flex gap-2" method="GET" action="{{ route('novels.index') }}">
        <select name="status" class="form-select form-select-sm" style="width: 130px;" onchange="this.form.submit()">
            <option value="">All Status</option>
            <option value="0" @selected(request('status') === '0')>Active</option>
            <option value="1" @selected(request('status') === '1')>Completed</option>
        </select>
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search novels..." value="{{ request('search') }}" style="width: 200px;">
        <button type="submit" class="btn btn-sm btn-primary">Search</button>
        @if(request('search') || request('status') !== null && request('status') !== '')
            <a href="{{ route('novels.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
        @endif
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d;">
                    <th style="width: 50px"></th>
                    <th>Name</th>
                    <th style="width: 180px">Author</th>
                    <th style="width: 90px">Status</th>
                    <th style="width: 180px">Progress</th>
                    <th style="width: 110px">Chapters</th>
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
                        <td colspan="6" class="text-center text-muted py-4">No novels found.</td>
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
