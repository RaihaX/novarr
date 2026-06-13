@extends('layouts.app')

@section('content')
<div class="page-toolbar">
    <div class="d-flex align-items-center gap-3">
        <h1 class="mb-0">Novels</h1>
        <a href="{{ route('novels.discover') }}" class="btn btn-sm btn-success">+ Add Novel</a>
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
            <select name="sort" aria-label="Sort" class="form-select form-select-sm w-auto" onchange="this.form.requestSubmit()">
                <option value="name" @selected($sort === 'name')>A–Z</option>
                <option value="progress" @selected($sort === 'progress')>Progress</option>
                <option value="updated" @selected($sort === 'updated')>Recently updated</option>
                <option value="chapters" @selected($sort === 'chapters')>Chapter count</option>
            </select>
            <select name="status" aria-label="Filter by status" class="form-select form-select-sm w-auto" onchange="this.form.requestSubmit()">
                <option value="">All Status</option>
                <option value="0" @selected(request('status') === '0')>Active</option>
                <option value="1" @selected(request('status') === '1')>Completed</option>
            </select>
            @if($tags->isNotEmpty())
                <select name="tag" aria-label="Filter by tag" class="form-select form-select-sm w-auto" onchange="this.form.requestSubmit()">
                    <option value="">All tags</option>
                    @foreach($tags as $tag)
                        <option value="{{ $tag->id }}" @selected((string) $activeTag === (string) $tag->id)>{{ $tag->name }}</option>
                    @endforeach
                </select>
            @endif
            <input type="search" name="search" aria-label="Search novels" class="form-control form-control-sm w-auto" placeholder="Search novels..." value="{{ request('search') }}">
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
            @if(request('search') || request()->filled('status') || request()->filled('tag'))
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
                    @elseif($novel->paused_at)
                        <span class="poster-badge badge bg-secondary">Paused</span>
                    @endif
                    <button type="button" class="btn btn-sm btn-danger poster-delete novel-delete-btn" data-id="{{ $novel->id }}" data-name="{{ $novel->name }}" title="Delete novel" aria-label="Delete {{ $novel->name }}">
                        <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M6.5 1h3a.5.5 0 0 1 .5.5V2h4v1.5H2V2h4v-.5a.5.5 0 0 1 .5-.5zM3 4.5h10L12.2 14a1.5 1.5 0 0 1-1.5 1.4H5.3A1.5 1.5 0 0 1 3.8 14L3 4.5z"/></svg>
                    </button>
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
<div id="bulkBar" class="d-none align-items-center gap-2 mb-3 p-2 px-3 card flex-row">
    <span id="bulkCount" class="fw-semibold"></span>
    <button type="button" id="bulkComplete" class="btn btn-sm btn-outline-info">Mark complete</button>
    <button type="button" id="bulkDelete" class="btn btn-sm btn-outline-danger">Delete</button>
    <button type="button" id="bulkClear" class="btn btn-sm btn-link text-decoration-none ms-auto">Clear selection</button>
</div>
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
                @elseif($novel->paused_at)
                    <span class="badge bg-secondary">Paused</span>
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
                    <th style="width: 34px"><input type="checkbox" id="selectAll" class="form-check-input" aria-label="Select all novels"></th>
                    <th style="width: 50px"></th>
                    <th>Name</th>
                    <th style="width: 180px">Author</th>
                    <th style="width: 90px">Status</th>
                    <th style="width: 180px">Progress</th>
                    <th style="width: 110px">Chapters</th>
                    <th style="width: 46px"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse($novels as $novel)
                    <tr>
                        <td><input type="checkbox" class="form-check-input novel-check" value="{{ $novel->id }}" aria-label="Select {{ $novel->name }}"></td>
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
                            @elseif($novel->paused_at)
                                <span class="badge bg-secondary">Paused</span>
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
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger novel-delete-btn" data-id="{{ $novel->id }}" data-name="{{ $novel->name }}" title="Delete novel" aria-label="Delete {{ $novel->name }}">
                                <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M6.5 1h3a.5.5 0 0 1 .5.5V2h4v1.5H2V2h4v-.5a.5.5 0 0 1 .5-.5zM3 4.5h10L12.2 14a1.5 1.5 0 0 1-1.5 1.4H5.3A1.5 1.5 0 0 1 3.8 14L3 4.5z"/></svg>
                            </button>
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
@endif
@endsection

@push('scripts')
<script>
(function(){

    // --- Bulk selection ---
    const bulkBar = document.getElementById('bulkBar');
    const selectAll = document.getElementById('selectAll');
    const checks = () => [...document.querySelectorAll('.novel-check')];
    const selected = () => checks().filter(c => c.checked).map(c => c.value);

    function refreshBulkBar() {
        const n = selected().length;
        if (bulkBar) {
            bulkBar.classList.toggle('d-none', n === 0);
            bulkBar.classList.toggle('d-flex', n > 0);
            document.getElementById('bulkCount').textContent = `${n} selected`;
        }
        if (selectAll) {
            selectAll.checked = n > 0 && n === checks().length;
            selectAll.indeterminate = n > 0 && n < checks().length;
        }
    }

    checks().forEach(c => c.addEventListener('change', refreshBulkBar));
    selectAll?.addEventListener('change', () => {
        checks().forEach(c => c.checked = selectAll.checked);
        refreshBulkBar();
    });
    document.getElementById('bulkClear')?.addEventListener('click', () => {
        checks().forEach(c => c.checked = false);
        refreshBulkBar();
    });

    async function bulkAction(action) {
        const ids = selected();
        if (!ids.length) return;

        if (action === 'delete' && !confirm(`Delete ${ids.length} novel(s) and all of their chapters? This cannot be undone from the UI.`)) return;
        if (action === 'complete' && !confirm(`Mark ${ids.length} novel(s) as complete?`)) return;

        try {
            const response = await fetch('{{ route('novels.bulk') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action, ids }),
            });
            const data = await response.json();

            if (data.success) {
                location.reload();
            } else {
                Novarr.showToast(data.message || 'Bulk action failed.', 'danger');
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }

    document.getElementById('bulkDelete')?.addEventListener('click', () => bulkAction('delete'));
    document.getElementById('bulkComplete')?.addEventListener('click', () => bulkAction('complete'));

    // --- Single delete ---
    document.querySelectorAll('.novel-delete-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            // Grid buttons live inside the poster link — don't navigate.
            e.preventDefault();
            e.stopPropagation();

            const name = btn.dataset.name;

            if (!confirm(`Delete "${name}" and all of its chapters? This cannot be undone from the UI.`)) return;

            btn.disabled = true;

            try {
                const response = await fetch(`/novels/${btn.dataset.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();

                if (data.success) {
                    (btn.closest('tr') ?? btn.closest('.poster-card'))?.remove();
                    Novarr.showToast(`Deleted "${name}".`, 'success');
                } else {
                    btn.disabled = false;
                    Novarr.showToast('Failed to delete novel.', 'danger');
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
