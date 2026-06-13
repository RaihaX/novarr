@extends('layouts.app')

@section('content')
<div class="page-toolbar">
    <div class="d-flex align-items-center gap-3">
        <h1 class="mb-0">Novels</h1>
        <a href="{{ route('novels.create') }}" class="btn btn-sm btn-success">+ Add Novel</a>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <div class="btn-group" role="group" aria-label="View mode">
            <a href="{{ request()->fullUrlWithQuery(['view' => 'list', 'page' => null]) }}"
               class="btn btn-sm btn-outline-secondary {{ $view === 'list' ? 'active' : '' }}" aria-label="List view" title="List view">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M2 3.5h12v2H2zm0 3.5h12v2H2zm0 3.5h12v2H2z"/></svg>
            </a>
            <a href="{{ request()->fullUrlWithQuery(['view' => 'grid', 'page' => null]) }}"
               class="btn btn-sm btn-outline-secondary {{ $view === 'grid' ? 'active' : '' }}" aria-label="Grid view" title="Grid view">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M2 2h5v5H2zm7 0h5v5H9zM2 9h5v5H2zm7 0h5v5H9z"/></svg>
            </a>
        </div>
        <form method="GET" action="{{ route('novels.index') }}">
            <select name="status" aria-label="Filter by status" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="0" @selected(request('status') === '0')>Active</option>
                <option value="1" @selected(request('status') === '1')>Completed</option>
            </select>
            <input type="search" name="search" aria-label="Search novels" class="form-control form-control-sm w-auto" placeholder="Search novels..." value="{{ request('search') }}">
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
            @if(request('search') || request('status') !== null && request('status') !== '')
                <a href="{{ route('novels.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            @endif
        </form>
    </div>
</div>

@if($view === 'grid')
    {{-- Poster grid (Sonarr-style) --}}
    <div class="poster-grid mb-4">
        @forelse($novels as $novel)
            @php
                $total = $novel->chapters_count ?? 0;
                $downloaded = $novel->downloaded_chapters_count ?? 0;
                $pct = $total > 0 ? round(($downloaded / $total) * 100) : 0;
            @endphp
            <a href="{{ route('novels.show', $novel->id) }}" class="poster-card" title="{{ $novel->name }}">
                <div class="poster-cover">
                    @if($novel->file)
                        <img src="{{ Storage::url($novel->file->file_path) }}" alt="Cover of {{ $novel->name }}" loading="lazy">
                    @else
                        <div class="poster-cover-placeholder"><span>{{ $novel->name }}</span></div>
                    @endif
                    @if($novel->status)
                        <span class="poster-badge badge bg-info">Done</span>
                    @endif
                    <div class="poster-progress">
                        <div class="poster-progress-bar {{ $pct >= 100 ? 'is-complete' : '' }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
                <div class="poster-title">{{ $novel->name }}</div>
                <div class="poster-meta">{{ $downloaded }} / {{ $total }}</div>
            </a>
        @empty
            <p class="text-muted">No novels found.</p>
        @endforelse
    </div>
    @if($novels->hasPages())
        {{ $novels->appends(request()->query())->links() }}
    @endif
@else
<div class="card">
    {{-- Mobile: compact card list --}}
    <div class="d-md-none">
        @forelse($novels as $novel)
            @php
                $total = $novel->chapters_count ?? 0;
                $downloaded = $novel->downloaded_chapters_count ?? 0;
                $pct = $total > 0 ? round(($downloaded / $total) * 100) : 0;
            @endphp
            <a href="{{ route('novels.show', $novel->id) }}" class="novel-card">
                @if($novel->file)
                    <img src="{{ Storage::url($novel->file->file_path) }}" alt="Cover of {{ $novel->name }}" loading="lazy" class="cover-thumb">
                @else
                    <div class="cover-placeholder">N/A</div>
                @endif
                <div class="novel-card-body">
                    <div class="novel-card-title">{{ $novel->name }}</div>
                    <div class="novel-card-meta mb-1">{{ $novel->author ?? 'Unknown author' }} · {{ $downloaded }}/{{ $total }}</div>
                    <div class="progress" style="height: 5px;">
                        <div class="progress-bar {{ $pct >= 100 ? 'bg-success' : 'bg-info' }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
                @if($novel->status)
                    <span class="badge bg-info">Done</span>
                @endif
            </a>
        @empty
            <p class="text-center text-muted py-4 mb-0">No novels found.</p>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr class="table-head-label">
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
                                <img src="{{ Storage::url($novel->file->file_path) }}" alt="Cover of {{ $novel->name }}" loading="lazy" class="cover-thumb">
                            @else
                                <div class="cover-placeholder">N/A</div>
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
@endif
@endsection
