@extends('layouts.app')

@php
    $hasFilters = request('search') || request()->filled('status') || request()->filled('tag') || $filter !== 'all';
    // Chip / toggle links keep every other query parameter, drop the page.
    $chipUrl = fn(string $f) => request()->fullUrlWithQuery(['filter' => $f === 'all' ? null : $f, 'ids' => null, 'page' => null]);
    $chips = [
        'all' => ['All', null, null],
        'reading' => ['Reading', null, null],
        'new' => ['New chapters', $chipCounts['new'], 'tone-pending'],
        'attention' => ['Needs attention', $chipCounts['attention'], 'tone-warning'],
        'offline' => ['Offline', null, null],
        'finished' => ['Finished', null, null],
    ];
    $sorts = ['read' => 'Last read', 'name' => 'Name', 'updated' => 'Updated', 'progress' => 'Progress'];
@endphp

@section('title', 'Library')

@section('content')
<div class="lib-page" data-view="{{ $view }}" data-filter="{{ $filter }}" @if($offlinePending) data-offline-pending @endif>
<div class="page-head lib-head">
    <div class="page-head-titles">
        <h1 class="page-title mb-0">Library</h1>
        <p class="lib-summary mb-0">
            <span class="mono-num">{{ number_format($chipCounts['all']) }}</span> {{ Str::plural('novel', $chipCounts['all']) }}
            · <span class="mono-num">{{ number_format($totals['downloaded']) }}</span> chapters downloaded
            @if($totals['queued'] > 0)
                · <span class="mono-num">{{ number_format($totals['queued']) }}</span> queued
            @endif
            @if($hasFilters)
                · showing <span class="mono-num">{{ number_format($novels->total()) }}</span>
            @endif
        </p>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('novels.discover') }}" class="btn btn-primary">+ Add novel</a>
        @if($view === 'grid')
            <button type="button" class="btn btn-secondary" id="libSelect" aria-pressed="false">Select</button>
        @endif
    </div>
</div>

<div class="lib-toolbar mb-4">
    <nav class="lib-chips" aria-label="Filter library">
        @foreach($chips as $key => [$label, $count, $tone])
            <a href="{{ $chipUrl($key) }}" class="lib-chip {{ $filter === $key ? 'is-on' : '' }}" data-chip="{{ $key }}"
               @if($filter === $key) aria-current="page" @endif>
                {{ $label }}
                @if($count)
                    <span class="lib-chip-count {{ $tone }}">{{ number_format($count) }}</span>
                @elseif($key === 'offline')
                    <span class="lib-chip-count" data-offline-count hidden></span>
                @endif
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('novels.index') }}" class="lib-tools" role="search">
        @foreach(['filter' => $filter === 'all' ? null : $filter, 'ids' => request('ids'), 'view' => $view, 'status' => request('status')] as $k => $v)
            @if($v !== null && $v !== '')
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endif
        @endforeach
        <input type="search" name="search" aria-label="Search library" class="form-control lib-search" placeholder="Search library…" value="{{ request('search') }}">
        @if($tags->isNotEmpty())
            <select name="tag" aria-label="Filter by tag" class="form-select" onchange="this.form.requestSubmit()">
                <option value="">All tags</option>
                @foreach($tags as $tag)
                    <option value="{{ $tag->id }}" @selected((string) $activeTag === (string) $tag->id)>{{ $tag->name }}</option>
                @endforeach
            </select>
        @endif
        <label class="lib-sort">
            <span class="visually-hidden">Sort by</span>
            <select name="sort" class="form-select" onchange="this.form.requestSubmit()">
                @foreach($sorts as $key => $label)
                    <option value="{{ $key }}" @selected($sort === $key)>Sort: {{ Str::lower($label) }}</option>
                @endforeach
            </select>
        </label>
        <div class="btn-group segmented lib-viewtoggle" role="group" aria-label="View">
            <a href="{{ request()->fullUrlWithQuery(['view' => 'grid', 'page' => null]) }}" data-view-choice="grid"
               class="btn btn-secondary {{ $view === 'grid' ? 'active' : '' }}" @if($view === 'grid') aria-current="true" @endif>
                <x-icon name="layout-grid" :size="14" /> Grid
            </a>
            <a href="{{ request()->fullUrlWithQuery(['view' => 'table', 'page' => null]) }}" data-view-choice="table"
               class="btn btn-secondary {{ $view === 'table' ? 'active' : '' }}" @if($view === 'table') aria-current="true" @endif>
                <x-icon name="list" :size="14" /> Table
            </a>
        </div>
    </form>
</div>

<div id="bulkBar" class="novels-bulk-bar">
    <span id="bulkCount" class="bulk-count"></span>
    <button type="button" id="bulkComplete" class="btn btn-secondary">Mark complete</button>
    <button type="button" id="bulkDelete" class="btn btn-outline-danger">Delete</button>
    <button type="button" id="bulkClear" class="btn btn-ghost ms-auto">Clear selection</button>
</div>

@if($offlinePending)
    <p class="library-status" id="libOfflineStatus">
        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>Checking what is downloaded on this device…
    </p>
@elseif($view === 'grid')
    {{-- Cover-forward grid: whole-tile links, reading progress on the cover's
         bottom edge, a corner flag only for exceptions. --}}
    @if($novels->isEmpty())
        @include('novels._empty', ['hasFilters' => $hasFilters, 'filter' => $filter])
    @else
        <ul class="lib-grid mb-4" id="libGrid" role="list">
            @foreach($novels as $novel)
                @php
                    $r = $reading[$novel->id] ?? ['read' => 0, 'new' => 0, 'total' => 0, 'percent' => 0, 'finished' => false];
                    // Download figure for the tooltip — same definition as the
                    // table and the novel page (NovelHealth::downloadProgress).
                    $prog = \App\Services\NovelHealth::downloadProgress(
                        (int) ($novel->downloaded_chapters_count ?? 0),
                        (int) ($novel->source_chapters_count ?? $novel->chapters_count ?? 0),
                        (int) ($novel->no_of_chapters ?? 0)
                    );
                    $attention = isset($attentionIds[$novel->id]);
                    $flag = match (true) {
                        $attention && ((int) $novel->scrape_failures >= 3 || (int) $novel->toc_failures >= 2) => \App\Enums\NovelState::Failed,
                        $attention => \App\Enums\NovelState::Attention,
                        !$novel->status && (bool) $novel->paused_at => \App\Enums\NovelState::Paused,
                        default => null,
                    };
                    [$sub, $subTone] = match (true) {
                        $r['new'] > 0 => [number_format($r['new']) . ' NEW', 'tone-pending'],
                        $r['finished'] => ['FINISHED', 'tone-success'],
                        $r['read'] === 0 => ['NOT STARTED', 'tone-muted'],
                        default => [number_format($r['read']) . ' / ' . number_format($r['total']), ''],
                    };
                @endphp
                <li class="lib-tile" data-id="{{ $novel->id }}">
                    <a href="{{ route('novels.show', $novel->id) }}" class="lib-tile-link"
                       title="{{ $novel->name }} — {{ number_format($prog['downloaded']) }} of {{ number_format($prog['total']) }} · {{ $prog['percent'] }}% downloaded">
                        <x-cover :novel="$novel" :progress="$r['percent']" :flag="$flag" :chapters="$prog['total']" decorative />
                        <span class="lib-tile-title">{{ $novel->name }}</span>
                        <span class="lib-tile-sub {{ $subTone }}" data-reading="{{ $r['read'] }}/{{ $r['total'] }}">{{ $sub }}</span>
                    </a>
                    <input type="checkbox" class="form-check-input novel-check lib-tile-check" value="{{ $novel->id }}" aria-label="Select {{ $novel->name }}" tabindex="-1">
                </li>
            @endforeach
        </ul>
    @endif
    @if($novels->hasPages())
        {{ $novels->appends(request()->query())->links() }}
    @endif
@else
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
            @include('novels._empty', ['hasFilters' => $hasFilters, 'filter' => $filter])
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
                        <td colspan="8">@include('novels._empty', ['hasFilters' => $hasFilters, 'filter' => $filter])</td>
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
</div>
@endsection

@push('scripts')
<script>
(function(){
    const page = document.querySelector('.lib-page');
    if (!page) return;

    // --- Remember Grid/Table: ?view= is the source of truth, localStorage
    // the per-browser memory (the session remembers it server-side too). ---
    const VIEW_KEY = 'novarr.libraryView';
    const store = {
        get() { try { return localStorage.getItem(VIEW_KEY); } catch (e) { return null; } },
        set(v) { try { localStorage.setItem(VIEW_KEY, v); } catch (e) { /* private window */ } },
    };
    document.querySelectorAll('[data-view-choice]').forEach(a =>
        a.addEventListener('click', () => store.set(a.dataset.viewChoice)));

    const params = new URLSearchParams(location.search);
    const remembered = store.get();
    if (!params.has('view') && (remembered === 'grid' || remembered === 'table') && remembered !== page.dataset.view) {
        params.set('view', remembered);
        const url = `${location.pathname}?${params}`;
        window.Turbo ? Turbo.visit(url, { action: 'replace' }) : location.replace(url);
        return;
    }
    if (params.has('view')) store.set(page.dataset.view);

    // --- Offline chip: what's downloaded lives in this browser's IndexedDB. ---
    const withLibrary = (fn) => {
        if (window.Novarr?.getLibrary) fn();
        else window.addEventListener('load', fn, { once: true });
    };
    withLibrary(() => {
        window.Novarr?.getLibrary?.().then((novels) => {
            const ids = novels.map(n => n.id).filter(Boolean);
            const count = document.querySelector('[data-offline-count]');
            if (count && ids.length) {
                count.textContent = ids.length;
                count.hidden = false;
            }
            if (page.hasAttribute('data-offline-pending')) {
                const p = new URLSearchParams(location.search);
                p.set('ids', ids.join(','));
                const url = `${location.pathname}?${p}`;
                window.Turbo ? Turbo.visit(url, { action: 'replace' }) : location.replace(url);
            }
        }).catch(() => {});
    });

    // --- Bulk selection (table checkboxes, or the grid's Select mode) ---
    const bulkBar = document.getElementById('bulkBar');
    const selectAll = document.getElementById('selectAll');
    const grid = document.getElementById('libGrid');
    const selectBtn = document.getElementById('libSelect');
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
        grid?.querySelectorAll('.lib-tile').forEach(t =>
            t.classList.toggle('is-selected', !!t.querySelector('.novel-check')?.checked));
    }

    function setSelecting(on) {
        if (!grid) return;
        grid.classList.toggle('is-selecting', on);
        selectBtn?.setAttribute('aria-pressed', String(on));
        if (selectBtn) selectBtn.textContent = on ? 'Done' : 'Select';
        grid.querySelectorAll('.lib-tile-check').forEach(c => c.tabIndex = on ? 0 : -1);
        if (!on) checks().forEach(c => c.checked = false);
        refreshBulkBar();
    }
    selectBtn?.addEventListener('click', () => setSelecting(!grid.classList.contains('is-selecting')));

    // In Select mode a tile click toggles its checkbox instead of navigating.
    grid?.addEventListener('click', (e) => {
        if (!grid.classList.contains('is-selecting')) return;
        const tile = e.target.closest('.lib-tile');
        if (!tile || e.target.classList.contains('novel-check')) return;
        e.preventDefault();
        const box = tile.querySelector('.novel-check');
        box.checked = !box.checked;
        refreshBulkBar();
    });

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

    // Update a table row's status badge + complete button in place, so list
    // actions don't trigger a full-page reload.
    function setNovelComplete(btn, completed) {
        const paused = btn.dataset.paused === '1';
        btn.dataset.completed = completed ? '1' : '0';
        btn.title = completed ? 'Mark active' : 'Mark complete';

        const cell = btn.closest('tr')?.querySelector('td.col-status');
        if (!cell) return;
        let badge = cell.querySelector('.badge');
        if (!badge) {
            badge = document.createElement('span');
            cell.appendChild(badge);
        }
        const [cls, label] = badgeFor(completed, paused);
        badge.className = cls;
        badge.textContent = label;
    }

    async function bulkAction(action) {
        const ids = selected();
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
                const boxes = checks().filter(c => c.checked);
                if (action === 'delete') {
                    boxes.forEach(c => (c.closest('tr') ?? c.closest('.novel-row') ?? c.closest('.lib-tile'))?.remove());
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

    // --- Toggle complete (table rows) ---
    document.querySelectorAll('.novel-complete-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
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

    // --- Single delete (table rows) ---
    document.querySelectorAll('.novel-delete-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
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
                    btn.closest('tr')?.remove();
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
