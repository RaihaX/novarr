@extends('layouts.app')

@php $hasFilters = request('search') || request()->filled('status') || request()->filled('tag'); @endphp

@section('title', 'Library')

@section('content')
@php $attentionIds = \App\Services\NovelHealth::attentionIds(); @endphp
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">{{ number_format($novels->total()) }} {{ Str::plural('novel', $novels->total()) }}{{ $hasFilters ? ' · filtered' : '' }}</span>
        <h1 class="page-title mb-0">Library</h1>
    </div>
    <div class="page-head-actions">
        <div class="btn-group segmented" role="group" aria-label="View mode">
            <a href="{{ request()->fullUrlWithQuery(['view' => 'list', 'page' => null]) }}"
               class="btn btn-secondary {{ $view === 'list' ? 'active' : '' }}" aria-label="List view" title="List view">
                <x-icon name="list" :size="14" />
            </a>
            <a href="{{ request()->fullUrlWithQuery(['view' => 'grid', 'page' => null]) }}"
               class="btn btn-secondary {{ $view === 'grid' ? 'active' : '' }}" aria-label="Grid view" title="Grid view">
                <x-icon name="layout-grid" :size="14" />
            </a>
        </div>
        <a href="{{ route('novels.discover') }}" class="btn btn-primary">Add novel</a>
    </div>
</div>

<form method="GET" action="{{ route('novels.index') }}" class="filter-bar mb-4">
    <select name="sort" aria-label="Sort" class="form-select" onchange="this.form.requestSubmit()">
        <option value="name" @selected($sort === 'name')>A–Z</option>
        <option value="progress" @selected($sort === 'progress')>Progress</option>
        <option value="updated" @selected($sort === 'updated')>Recently updated</option>
        <option value="chapters" @selected($sort === 'chapters')>Chapter count</option>
    </select>
    <select name="status" aria-label="Filter by status" class="form-select" onchange="this.form.requestSubmit()">
        <option value="">All status</option>
        <option value="0" @selected(request('status') === '0')>Active</option>
        <option value="1" @selected(request('status') === '1')>Completed</option>
    </select>
    @if($tags->isNotEmpty())
        <select name="tag" aria-label="Filter by tag" class="form-select" onchange="this.form.requestSubmit()">
            <option value="">All tags</option>
            @foreach($tags as $tag)
                <option value="{{ $tag->id }}" @selected((string) $activeTag === (string) $tag->id)>{{ $tag->name }}</option>
            @endforeach
        </select>
    @endif
    <input type="search" name="search" aria-label="Search novels" class="form-control" placeholder="Search novels…" value="{{ request('search') }}">
    <button type="submit" class="btn btn-secondary">Search</button>
    @if($hasFilters)
        <a href="{{ route('novels.index') }}" class="btn btn-ghost">Clear</a>
    @endif
</form>

@if($view === 'grid')
    {{-- Poster wall: covers first, one download rail per tile --}}
    <div class="poster-grid mb-4">
        @forelse($novels as $novel)
            @php
                // One progress definition, shared with the novel page:
                // downloaded ÷ chapters known to the source (NovelHealth).
                $prog = \App\Services\NovelHealth::downloadProgress(
                    (int) ($novel->downloaded_chapters_count ?? 0),
                    (int) ($novel->source_chapters_count ?? $novel->chapters_count ?? 0),
                    (int) ($novel->no_of_chapters ?? 0)
                );
                $total = $prog['total'];
                $downloaded = $prog['downloaded'];
                $pct = $prog['percent'];
                $isCompleted = (bool) $novel->status;
                $isPaused = !$isCompleted && $novel->paused_at;
                $progState = \App\Services\NovelHealth::progressState($pct, $isCompleted, (bool) $isPaused, isset($attentionIds[$novel->id]));
                $barClass = \App\Enums\NovelState::from($progState)->barClass();
            @endphp
            <a href="{{ route('novels.show', $novel->id) }}" class="poster-card" title="{{ $novel->name }}">
                <div class="poster-cover">
                    @if($novel->file)
                        <img src="{{ Storage::url($novel->file->file_path) }}" alt="Cover of {{ $novel->name }}" loading="lazy">
                    @else
                        <div class="poster-cover-placeholder"><span>{{ $novel->name }}</span></div>
                    @endif
                    @if($isCompleted || $isPaused)
                        <x-status :state="\App\Enums\NovelState::forNovel($novel)" class="poster-badge" />
                    @endif
                    <div class="poster-actions">
                        <button type="button" class="btn btn-sm btn-outline-success poster-action novel-complete-btn" data-id="{{ $novel->id }}" data-completed="{{ $novel->status ? 1 : 0 }}" data-paused="{{ $novel->paused_at ? 1 : 0 }}" title="{{ $novel->status ? 'Mark active' : 'Mark complete' }}" aria-label="Toggle complete">
                            <x-icon name="check" :size="13" />
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger poster-action novel-delete-btn" data-id="{{ $novel->id }}" data-name="{{ $novel->name }}" title="Delete novel" aria-label="Delete {{ $novel->name }}">
                            <x-icon name="trash-2" :size="13" />
                        </button>
                    </div>
                    <div class="poster-progress" aria-hidden="true">
                        <div class="poster-progress-bar {{ $barClass }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
                <div class="poster-title">{{ $novel->name }}</div>
                <div class="poster-meta">{{ number_format($downloaded) }} of {{ number_format($total) }} · {{ $pct }}%</div>
            </a>
        @empty
            @include('novels._empty', ['hasFilters' => $hasFilters])
        @endforelse
    </div>
    @if($novels->hasPages())
        {{ $novels->appends(request()->query())->links() }}
    @endif
@else
<div id="bulkBar" class="novels-bulk-bar">
    <span id="bulkCount" class="bulk-count"></span>
    <button type="button" id="bulkComplete" class="btn btn-secondary">Mark complete</button>
    <button type="button" id="bulkDelete" class="btn btn-outline-danger">Delete</button>
    <button type="button" id="bulkClear" class="btn btn-ghost ms-auto">Clear selection</button>
</div>

<div class="dash-panel">
    {{-- Below 900px the table collapses to the novel-row list (handoff) --}}
    <div class="novels-list">
        @forelse($novels as $novel)
            @php
                // One progress definition, shared with the novel page:
                // downloaded ÷ chapters known to the source (NovelHealth).
                $prog = \App\Services\NovelHealth::downloadProgress(
                    (int) ($novel->downloaded_chapters_count ?? 0),
                    (int) ($novel->source_chapters_count ?? $novel->chapters_count ?? 0),
                    (int) ($novel->no_of_chapters ?? 0)
                );
                $total = $prog['total'];
                $downloaded = $prog['downloaded'];
                $pct = $prog['percent'];
                $isCompleted = (bool) $novel->status;
                $isPaused = !$isCompleted && $novel->paused_at;
                $progState = \App\Services\NovelHealth::progressState($pct, $isCompleted, (bool) $isPaused, isset($attentionIds[$novel->id]));
                $barClass = \App\Enums\NovelState::from($progState)->barClass();
            @endphp
            <div class="novel-row">
                <input type="checkbox" class="form-check-input novel-check flex-shrink-0" value="{{ $novel->id }}" aria-label="Select {{ $novel->name }}">
                <a href="{{ route('novels.show', $novel->id) }}" class="novel-row-link">
                    @if($novel->file)
                        <img src="{{ Storage::url($novel->file->file_path) }}" alt="Cover of {{ $novel->name }}" loading="lazy" class="cover-thumb">
                    @else
                        <div class="cover-placeholder" aria-hidden="true"><x-brand-mark variant="mono" :size="16" /></div>
                    @endif
                    <div class="novel-row-body">
                        <div class="novel-row-title">{{ $novel->name }}</div>
                        <div class="novel-row-meta">{{ $novel->author ?? 'Unknown author' }}</div>
                        <div class="novel-row-counts">{{ number_format($downloaded) }} of {{ number_format($total) }} on source · {{ $pct }}%</div>
                        <div class="novel-row-progress" aria-hidden="true"><span class="{{ $barClass }}" style="width: {{ $pct }}%"></span></div>
                    </div>
                    <x-status :state="\App\Enums\NovelState::forNovel($novel)" />
                </a>
            </div>
        @empty
            @include('novels._empty', ['hasFilters' => $hasFilters])
        @endforelse
    </div>

    {{-- 900px and up: the full table (handoff §4 column recipe) --}}
    <div class="table-responsive novels-table">
        <table class="table table-hover table-novels align-middle">
            <thead>
                <tr>
                    <th class="col-select"><input type="checkbox" id="selectAll" class="form-check-input" aria-label="Select all novels"></th>
                    <th class="col-cover"><span class="visually-hidden">Cover</span></th>
                    <th>Name</th>
                    <th class="col-author">Author</th>
                    <th class="col-status">Status</th>
                    <th class="col-progress">Progress</th>
                    <th class="col-chapters">Chapters</th>
                    <th class="col-actions"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse($novels as $novel)
                    @php
                        // One progress definition, shared with the novel page:
                        // downloaded ÷ chapters known to the source (NovelHealth).
                        $prog = \App\Services\NovelHealth::downloadProgress(
                            (int) ($novel->downloaded_chapters_count ?? 0),
                            (int) ($novel->source_chapters_count ?? $novel->chapters_count ?? 0),
                            (int) ($novel->no_of_chapters ?? 0)
                        );
                        $total = $prog['total'];
                        $downloaded = $prog['downloaded'];
                        $pct = $prog['percent'];
                        $isCompleted = (bool) $novel->status;
                        $isPaused = !$isCompleted && $novel->paused_at;
                        $progState = \App\Services\NovelHealth::progressState($pct, $isCompleted, (bool) $isPaused, isset($attentionIds[$novel->id]));
                        $barClass = \App\Enums\NovelState::from($progState)->barClass();
                    @endphp
                    <tr>
                        <td class="col-select"><input type="checkbox" class="form-check-input novel-check" value="{{ $novel->id }}" aria-label="Select {{ $novel->name }}"></td>
                        <td class="col-cover">
                            @if($novel->file)
                                <img src="{{ Storage::url($novel->file->file_path) }}" alt="Cover of {{ $novel->name }}" loading="lazy" class="cover-thumb">
                            @else
                                <div class="cover-placeholder" aria-hidden="true"><x-brand-mark variant="mono" :size="16" /></div>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('novels.show', $novel->id) }}" class="novel-name">{{ $novel->name }}</a>
                        </td>
                        <td class="col-author">{{ $novel->author ?? '—' }}</td>
                        <td class="col-status">
                            <x-status :state="\App\Enums\NovelState::forNovel($novel)" />
                        </td>
                        <td class="col-progress">
                            <div class="progress-inline">
                                <div class="progress" role="progressbar" aria-label="Downloaded chapters" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar {{ $barClass }}" style="width: {{ $pct }}%"></div>
                                </div>
                                <span class="progress-value" data-progress-percent>{{ $pct }}%</span>
                            </div>
                        </td>
                        <td class="col-chapters" title="Downloaded of chapters on source">{{ number_format($downloaded) }} / {{ number_format($total) }}</td>
                        <td class="col-actions">
                            <span class="row-actions">
                                <button type="button" class="btn btn-outline-success novel-complete-btn" data-id="{{ $novel->id }}" data-completed="{{ $novel->status ? 1 : 0 }}" data-paused="{{ $novel->paused_at ? 1 : 0 }}" title="{{ $novel->status ? 'Mark active' : 'Mark complete' }}" aria-label="Toggle complete">
                                    <x-icon name="check" :size="14" />
                                </button>
                                <button type="button" class="btn btn-outline-danger novel-delete-btn" data-id="{{ $novel->id }}" data-name="{{ $novel->name }}" title="Delete novel" aria-label="Delete {{ $novel->name }}">
                                    <x-icon name="trash-2" :size="14" />
                                </button>
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">@include('novels._empty', ['hasFilters' => $hasFilters])</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($novels->hasPages())
        <div class="dash-panel-foot">
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
    // The mobile card list and desktop table both render a checkbox per novel,
    // so dedupe by value.
    const selected = () => [...new Set(checks().filter(c => c.checked).map(c => c.value))];

    function refreshBulkBar() {
        const n = selected().length;
        if (bulkBar) {
            bulkBar.classList.toggle('is-active', n > 0);
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

    // Same classes the x-status component renders (App\Enums\NovelState::badgeClass()):
    // completed/active are the success triad, paused is the muted one.
    function badgeFor(completed, paused) {
        if (completed) return ['badge badge-completed', 'Completed'];
        if (paused) return ['badge badge-paused', 'Paused'];
        return ['badge badge-active', 'Active'];
    }

    // Update a novel's status badge + complete button in place (grid poster or
    // table row), so list actions don't trigger a full-page reload.
    function setNovelComplete(btn, completed) {
        const paused = btn.dataset.paused === '1';
        btn.dataset.completed = completed ? '1' : '0';
        btn.title = completed ? 'Mark active' : 'Mark complete';

        const row = btn.closest('tr');
        const card = btn.closest('.poster-card');

        if (row) {
            const cell = row.querySelector('td.col-status');
            let badge = cell?.querySelector('.badge');
            if (!badge && cell) {
                badge = document.createElement('span');
                cell.appendChild(badge);
            }
            if (badge) {
                const [cls, label] = badgeFor(completed, paused);
                badge.className = cls;
                badge.textContent = label;
            }
        } else if (card) {
            const cover = card.querySelector('.poster-cover');
            let badge = cover?.querySelector('.poster-badge');
            if (completed || paused) {
                if (!badge && cover) {
                    badge = document.createElement('span');
                    cover.appendChild(badge);
                }
                badge.className = 'poster-badge badge ' + (completed ? 'badge-completed' : 'badge-paused');
                badge.textContent = completed ? 'Completed' : 'Paused';
            } else {
                badge?.remove();
            }
        }
    }

    async function bulkAction(action) {
        const boxes = checks().filter(c => c.checked);
        const ids = boxes.map(c => c.value);
        if (!ids.length) return;

        if (action === 'delete' && !await Novarr.confirmDialog(
            `Delete ${ids.length} novel(s) and all of their chapters? This cannot be undone from the UI.`,
            { title: 'Delete novels', confirmText: 'Delete', danger: true }
        )) return;
        if (action === 'complete' && !await Novarr.confirmDialog(
            `Mark ${ids.length} novel(s) as complete?`,
            { title: 'Mark complete', confirmText: 'Mark complete' }
        )) return;

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
                if (action === 'delete') {
                    boxes.forEach(c => (c.closest('tr') ?? c.closest('.novel-row'))?.remove());
                    Novarr.showToast(`Deleted ${ids.length} novel(s).`, 'success');
                } else {
                    boxes.forEach(c => {
                        const cbtn = c.closest('tr')?.querySelector('.novel-complete-btn');
                        if (cbtn) setNovelComplete(cbtn, true);
                        c.checked = false;
                    });
                    Novarr.showToast(`Marked ${ids.length} novel(s) complete.`, 'success');
                }
                refreshBulkBar();
            } else {
                Novarr.showToast(data.message || 'Bulk action failed.', 'danger');
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }

    document.getElementById('bulkDelete')?.addEventListener('click', () => bulkAction('delete'));
    document.getElementById('bulkComplete')?.addEventListener('click', () => bulkAction('complete'));

    // --- Toggle complete (grid + list) ---
    document.querySelectorAll('.novel-complete-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            btn.disabled = true;
            try {
                const response = await fetch(`/novels/${btn.dataset.id}/toggle-complete`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    setNovelComplete(btn, data.completed);
                    Novarr.showToast(data.completed ? 'Marked complete.' : 'Marked active.', 'success');
                } else {
                    Novarr.showToast('Failed to update.', 'danger');
                }
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                btn.disabled = false;
            }
        });
    });

    // --- Single delete ---
    document.querySelectorAll('.novel-delete-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            // Grid buttons live inside the poster link — don't navigate.
            e.preventDefault();
            e.stopPropagation();

            const name = btn.dataset.name;

            const ok = await Novarr.confirmDialog(
                `Delete "${name}" and all of its chapters? This cannot be undone from the UI.`,
                { title: 'Delete novel', confirmText: 'Delete', danger: true }
            );
            if (!ok) return;

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
