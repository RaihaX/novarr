@extends('layouts.app')

@push('styles')
<style>
    .stat-card {
        border-left: 3px solid;
        transition: transform 0.15s;
    }
    .stat-card:hover { transform: translateY(-2px); }
    .stat-card.stat-success { border-color: #198754; }
    .stat-card.stat-warning { border-color: #ffc107; }
    .stat-card.stat-danger { border-color: #dc3545; }
    .stat-card.stat-info { border-color: #0d6efd; }
    .stat-card .stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1; }

    .novel-meta td { padding: 0.35rem 0.75rem !important; font-size: 14px; }
    .novel-meta .meta-label { color: #6c757d; width: 100px; }

    .cmd-btn {
        transition: all 0.2s;
        min-width: 120px;
    }
    .cmd-btn:not(:disabled):hover { transform: translateY(-1px); }

    .novel-cover {
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.4);
    }

    .chapter-row td { font-size: 13px; }

    /* Quick Actions grouped sections */
    .qa-section { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
    .qa-section + .qa-section { margin-top: 0.6rem; padding-top: 0.6rem; border-top: 1px solid rgba(255,255,255,0.05); }
    .qa-label {
        flex: 0 0 90px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6c757d;
        font-weight: 600;
    }
    @media (max-width: 575.98px) { .qa-label { flex-basis: 100%; } }

    #cmdOutputText {
        font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
    }
</style>
@endpush

@section('content')
<div class="mb-3">
    <a href="{{ route('novels.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Back to Novels</a>
</div>

@if(session('status'))
    <div class="alert alert-success py-2">{{ session('status') }}</div>
@endif

{{-- Hero Section --}}
<div class="row mb-4">
    <div class="col-5 col-sm-4 col-md-2 mx-auto mx-md-0">
        @if($data->file)
            <img src="{{ Storage::url($data->file->file_path) }}" alt="Cover of {{ $data->name }}" class="novel-cover img-fluid w-100 mb-3">
        @else
            <div class="novel-cover d-flex align-items-center justify-content-center w-100 mb-3" style="height: 220px; background: #2c3034;">
                <span class="text-muted">No Cover</span>
            </div>
        @endif
    </div>

    <div class="col-12 col-md-10">
        <div class="d-flex align-items-start justify-content-between mb-2">
            <div>
                <h2 class="mb-1">{{ $data->name }}</h2>
                <span class="text-muted">by {{ $data->author ?? 'Unknown' }}</span>
                @if($data->status)
                    <span class="badge bg-info ms-2">Completed</span>
                @elseif($data->paused_at)
                    <span class="badge bg-secondary ms-2" title="Paused {{ $data->paused_at->format('j M Y') }} — automatic downloads skip this novel">Paused</span>
                @else
                    <span class="badge bg-success ms-2">Active</span>
                @endif
            </div>
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                @if($continue_chapter_id)
                    <a href="{{ route('chapters.show', $continue_chapter_id) }}" class="btn btn-sm btn-primary">{{ $read_count > 0 ? 'Continue reading' : 'Start reading' }}</a>
                @endif
                <span id="offlineControls" data-id="{{ $data->id }}" data-total="{{ $current_chapters }}" data-unread="{{ max(0, $current_chapters - $read_count) }}" class="d-inline-flex gap-2">
                    <div class="dropdown">
                        <button type="button" id="offlineBtn" class="btn btn-sm btn-outline-info dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">Download for offline</button>
                        <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width: 250px;">
                            <li><button type="button" class="dropdown-item rounded" data-scope="unread-next" data-limit="100">Next 100 unread</button></li>
                            <li><button type="button" class="dropdown-item rounded" data-scope="unread">All unread (<span class="offl-unread">0</span>)</button></li>
                            <li><button type="button" class="dropdown-item rounded" data-scope="all">All chapters (<span class="offl-total">0</span>)</button></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <div class="px-2 pt-1">
                                    <div class="text-muted mb-1" style="font-size: 12px;">Chapter range</div>
                                    <div class="d-flex gap-1 align-items-center">
                                        <input type="number" id="offlFrom" class="form-control form-control-sm" placeholder="From" min="0" step="any" style="width: 78px;">
                                        <input type="number" id="offlTo" class="form-control form-control-sm" placeholder="To" min="0" step="any" style="width: 78px;">
                                        <button type="button" class="btn btn-sm btn-outline-info" data-scope="range">Get</button>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <button type="button" id="offlineRemove" class="btn btn-sm btn-outline-secondary d-none">Remove offline</button>
                </span>
                <a href="{{ route('novels.edit', $data->id) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                <button type="button" id="pauseToggle" class="btn btn-sm {{ $data->paused_at ? 'btn-success' : 'btn-outline-secondary' }}" data-id="{{ $data->id }}" title="Paused novels are skipped by automatic downloads; manual commands still work">
                    {{ $data->paused_at ? 'Resume downloads' : 'Pause downloads' }}
                </button>
                <button type="button" id="deleteNovel" class="btn btn-sm btn-outline-danger" data-id="{{ $data->id }}" data-name="{{ $data->name }}">Delete</button>
            </div>
        </div>

        <div class="row g-2 mb-3" style="font-size: 13px;">
            @if($data->group && $data->group->label)
                <div class="col-auto"><span class="text-muted">Group:</span> {{ $data->group->label }}</div>
            @endif
            @if($data->language && $data->language->label)
                <div class="col-auto"><span class="text-muted ms-3">Language:</span> {{ $data->language->label }}</div>
            @endif
            @if($data->external_url)
                <div class="col-auto"><span class="text-muted ms-3">Source:</span> <a href="{{ $data->external_url }}" target="_blank">{{ parse_url($data->external_url, PHP_URL_HOST) }}</a></div>
            @endif
        </div>

        {{-- Tags --}}
        <div class="mb-3" style="font-size: 13px;">
            <div id="tagDisplay" class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted">Tags:</span>
                @forelse($data->tags as $tag)
                    <a href="{{ route('novels.index', ['tag' => $tag->id]) }}" class="badge bg-secondary text-decoration-none">{{ $tag->name }}</a>
                @empty
                    <span class="text-muted fst-italic">none</span>
                @endforelse
                <button type="button" id="editTags" class="btn btn-sm btn-outline-secondary py-0 px-2 ms-1" style="font-size: 12px;">Edit tags</button>
            </div>
            <div id="tagEditor" class="d-none">
                <label class="text-muted d-block mb-1">Tags</label>
                <div class="d-flex gap-2 align-items-start flex-wrap">
                    @include('partials.tag-picker', ['selectedIds' => $data->tags->pluck('id')->all()])
                    <button type="button" id="saveTags" class="btn btn-sm btn-primary" data-id="{{ $data->id }}">Save</button>
                    <button type="button" id="cancelTags" class="btn btn-sm btn-outline-secondary">Cancel</button>
                </div>
            </div>
        </div>

        {{-- Stats --}}
        <div class="row g-2 mb-3">
            <div class="col">
                <div class="card stat-card stat-success">
                    <div class="card-body py-2 px-3">
                        <div class="stat-value text-success">{{ $current_chapters }}</div>
                        <small class="text-muted">Downloaded</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card stat-warning">
                    <div class="card-body py-2 px-3">
                        <div class="stat-value text-warning">{{ $current_chapters_not_downloaded }}</div>
                        <small class="text-muted">Pending</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card stat-danger">
                    <div class="card-body py-2 px-3">
                        <div class="stat-value text-danger">{{ count($missing_chapters) }}</div>
                        <small class="text-muted">Missing</small>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card stat-info">
                    <div class="card-body py-2 px-3">
                        <div class="stat-value text-info">{{ $progress }}%</div>
                        <small class="text-muted">Progress</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="progress mb-2" style="height: 6px; border-radius: 3px;">
            <div class="progress-bar {{ $progress >= 100 ? 'bg-success' : 'bg-info' }}" style="width: {{ $progress }}%; border-radius: 3px;"></div>
        </div>

        @if($current_chapters > 0)
            <div class="text-muted mb-3" style="font-size: 12px;">
                Read {{ $read_count }} / {{ $current_chapters }} downloaded
                ({{ $current_chapters > 0 ? round($read_count / $current_chapters * 100) : 0 }}%)
            </div>
        @endif

        @if($synopsis)
            <div class="synopsis" id="synopsis">
                <div class="synopsis-body" id="synopsisBody">{!! $synopsis !!}</div>
                <button type="button" class="btn btn-link btn-sm p-0 synopsis-toggle d-none" id="synopsisToggle" aria-expanded="false">Read more</button>
            </div>
        @else
            <div class="d-flex align-items-center gap-2" style="font-size: 13px; color: #6c757d;">
                <em>No summary available.</em>
                <button class="btn btn-sm btn-outline-secondary cmd-btn" data-command="metadata" data-novel="{{ $data->id }}" style="font-size: 11px; padding: 2px 8px;">
                    <span class="cmd-label">Refresh metadata</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
        @endif
    </div>
</div>

{{-- Quick Actions --}}
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Quick Actions</h6>
        <small class="text-muted">Commands run in background</small>
    </div>
    <div class="card-body py-3">
        <div class="qa-section">
            <div class="qa-label">Acquire</div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-primary cmd-btn" data-command="toc" data-novel="{{ $data->id }}" title="Re-scrape the table of contents to discover new chapters">
                    <span class="cmd-label">Scrape TOC</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-sm btn-primary cmd-btn" data-command="chapter" data-novel="{{ $data->id }}" title="Download the content of any pending chapters">
                    <span class="cmd-label">Download Chapters</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
        </div>

        <div class="qa-section">
            <div class="qa-label">Export</div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-outline-success cmd-btn" data-command="epub" data-novel="{{ $data->id }}" title="Build an ePub from the downloaded chapters">
                    <span class="cmd-label">Generate ePub</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <a href="{{ route('novels.download_epub', $data->id) }}" class="btn btn-sm btn-success">Download ePub</a>
                <button class="btn btn-sm btn-outline-success cmd-btn" data-command="send_to_kindle" data-novel="{{ $data->id }}" title="Email this novel's ePub to your Kindle">
                    <span class="cmd-label">Send to Kindle</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Sending</span>
                    <span class="cmd-done d-none">Sent</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
        </div>

        <div class="qa-section">
            <div class="qa-label">Maintenance</div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-outline-warning cmd-btn" data-command="metadata" data-novel="{{ $data->id }}" title="Re-fetch title, author, cover and synopsis from the source">
                    <span class="cmd-label">Refresh Metadata</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-sm btn-outline-info cmd-btn" data-command="normalize_labels" data-novel="{{ $data->id }}" title="Rewrite chapter labels/numbers to a consistent format">
                    <span class="cmd-label">Normalize Labels</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-sm btn-outline-secondary cmd-btn" data-command="chapter_cleanser" data-novel="{{ $data->id }}" title="Strip ads, leftover tags and junk characters from chapter text">
                    <span class="cmd-label">Clean Formatting</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-sm btn-outline-secondary cmd-btn" data-command="chapter_cleaner" data-novel="{{ $data->id }}" title="Re-download chapters that saved with little or no content">
                    <span class="cmd-label">Fix Empty Chapters</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
        </div>
    </div>
    <div id="cmdOutput" class="d-none">
        <pre id="cmdOutputText" class="mb-0 p-3" style="max-height: 250px; overflow-y: auto; white-space: pre-wrap; font-size: 12px; background: #0d1117; color: #8b949e; border-top: 1px solid rgba(255,255,255,0.05); border-radius: 0 0 6px 6px;"></pre>
    </div>
</div>

{{-- Chapters Table --}}
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="mb-0">Chapters</h6>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <div id="chBulkBar" class="d-none align-items-center gap-2">
                <span id="chBulkCount" class="text-muted" style="font-size: 12px;"></span>
                <button type="button" id="chMarkRead" class="btn btn-sm btn-outline-success">Mark read</button>
                <button type="button" id="chMarkUnread" class="btn btn-sm btn-outline-secondary">Mark unread</button>
            </div>
            @if(count($duplicate_chapters) > 0)
                <button type="button" id="removeDupes" class="btn btn-sm btn-outline-warning" data-id="{{ $data->id }}" title="{{ count($duplicate_chapters) }} duplicate chapter group(s) detected">Remove {{ count($duplicate_chapters) }} duplicate(s)</button>
            @endif
            <form method="GET" action="{{ route('novels.jump_chapter', $data->id) }}" class="d-flex gap-1">
                <input type="number" name="n" step="any" min="0" class="form-control form-control-sm" style="width: 90px;" placeholder="Ch. #" aria-label="Jump to chapter">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Go</button>
            </form>
            <span class="badge bg-secondary">{{ $chapters->total() }}</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead>
                <tr style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d;">
                    <th style="width: 34px"><input type="checkbox" id="chSelectAll" class="form-check-input" aria-label="Select all chapters"></th>
                    <th style="width: 70px">Ch.</th>
                    <th style="width: 50px">Book</th>
                    <th>Label</th>
                    <th style="width: 95px">Status</th>
                    <th style="width: 130px">Downloaded</th>
                </tr>
            </thead>
            <tbody>
                @forelse($chapters as $chapter)
                    <tr class="chapter-row">
                        <td><input type="checkbox" class="form-check-input ch-check" value="{{ $chapter->id }}" aria-label="Select chapter {{ $chapter->chapter }}"></td>
                        <td class="fw-semibold">{{ $chapter->chapter }}</td>
                        <td class="text-muted">{{ $chapter->book ?: '-' }}</td>
                        <td>
                            @if($chapter->read_at)
                                <span class="text-success me-1" title="Read {{ $chapter->read_at->format('Y-m-d H:i') }}">✓</span>
                            @endif
                            @if($chapter->status)
                                <a href="{{ route('chapters.show', $chapter->id) }}" class="text-decoration-none {{ $chapter->read_at ? 'text-muted' : '' }}">{{ Str::limit($chapter->label, 90) }}</a>
                            @else
                                {{ Str::limit($chapter->label, 90) }}
                            @endif
                        </td>
                        <td>
                            @if($chapter->status)
                                <span class="badge bg-success" style="font-size: 11px;">Downloaded</span>
                            @else
                                <span class="badge bg-warning text-dark" style="font-size: 11px;">Pending</span>
                            @endif
                        </td>
                        <td class="text-muted" style="font-size: 12px;">{{ $chapter->download_date ? $chapter->download_date->format('Y-m-d H:i') : '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No chapters found.</td>
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

@push('scripts')
<script>
    // Synopsis read-more: only show the toggle when the text actually clamps.
    const synopsisBody = document.getElementById('synopsisBody');
    const synopsisToggle = document.getElementById('synopsisToggle');

    if (synopsisBody && synopsisToggle) {
        if (synopsisBody.scrollHeight > synopsisBody.clientHeight + 2) {
            synopsisToggle.classList.remove('d-none');
        }

        synopsisToggle.addEventListener('click', () => {
            const expanded = synopsisBody.classList.toggle('expanded');
            synopsisToggle.textContent = expanded ? 'Read less' : 'Read more';
            synopsisToggle.setAttribute('aria-expanded', expanded);
        });
    }

    document.querySelectorAll('.cmd-btn').forEach(btn => {
        btn.addEventListener('click', () => runCommand(btn));
    });

    const deleteBtn = document.getElementById('deleteNovel');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
            const ok = await Novarr.confirmDialog(
                `Delete "${deleteBtn.dataset.name}" and all of its chapters? This cannot be undone from the UI.`,
                { title: 'Delete novel', confirmText: 'Delete', danger: true }
            );
            if (!ok) return;
            deleteBtn.disabled = true;
            try {
                const response = await fetch(`/novels/${deleteBtn.dataset.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    window.location.href = '{{ route('novels.index') }}';
                } else {
                    deleteBtn.disabled = false;
                    Novarr.showToast('Failed to delete novel.', 'danger');
                }
            } catch (err) {
                deleteBtn.disabled = false;
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    }

    const pauseToggle = document.getElementById('pauseToggle');
    if (pauseToggle) {
        pauseToggle.addEventListener('click', async () => {
            pauseToggle.disabled = true;
            try {
                const response = await fetch(`/novels/${pauseToggle.dataset.id}/toggle-pause`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    pauseToggle.disabled = false;
                    Novarr.showToast('Failed to update pause state.', 'danger');
                }
            } catch (err) {
                pauseToggle.disabled = false;
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    }

    // ---- Tag editing ----
    const editTags = document.getElementById('editTags');
    if (editTags) {
        const tagDisplay = document.getElementById('tagDisplay');
        const tagEditor = document.getElementById('tagEditor');
        const showEditor = (on) => {
            tagDisplay.classList.toggle('d-none', on);
            tagEditor.classList.toggle('d-none', !on);
            if (on) document.getElementById('tagInput').focus();
        };
        editTags.addEventListener('click', () => showEditor(true));
        document.getElementById('cancelTags').addEventListener('click', () => showEditor(false));
        document.getElementById('saveTags').addEventListener('click', async (e) => {
            const btn = e.target;
            btn.disabled = true;
            try {
                const tagIds = [...document.querySelectorAll('#tagEditor input[name="tags[]"]:checked')].map(c => c.value);
                const response = await fetch(`/novels/${btn.dataset.id}/tags`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ tags: tagIds }),
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    Novarr.showToast('Failed to save tags.', 'danger');
                }
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                btn.disabled = false;
            }
        });
    }

    // ---- Remove duplicate chapters ----
    const removeDupes = document.getElementById('removeDupes');
    if (removeDupes) {
        removeDupes.addEventListener('click', async () => {
            const ok = await Novarr.confirmDialog(
                'Remove duplicate chapters, keeping the best copy of each?',
                { title: 'Remove duplicates', confirmText: 'Remove' }
            );
            if (!ok) return;
            removeDupes.disabled = true;
            try {
                const response = await fetch(`/novels/${removeDupes.dataset.id}/remove-duplicates`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    Novarr.showToast(`Removed ${data.removed} duplicate chapter(s).`, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    removeDupes.disabled = false;
                    Novarr.showToast('Failed to remove duplicates.', 'danger');
                }
            } catch (err) {
                removeDupes.disabled = false;
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    }

    // ---- Chapter bulk read/unread ----
    const chChecks = () => [...document.querySelectorAll('.ch-check')];
    const chSelected = () => chChecks().filter(c => c.checked).map(c => c.value);
    const chBulkBar = document.getElementById('chBulkBar');
    const chSelectAll = document.getElementById('chSelectAll');

    function refreshChBulk() {
        const n = chSelected().length;
        chBulkBar.classList.toggle('d-none', n === 0);
        chBulkBar.classList.toggle('d-flex', n > 0);
        document.getElementById('chBulkCount').textContent = `${n} selected`;
        if (chSelectAll) {
            chSelectAll.checked = n > 0 && n === chChecks().length;
            chSelectAll.indeterminate = n > 0 && n < chChecks().length;
        }
    }

    chChecks().forEach(c => c.addEventListener('change', refreshChBulk));
    chSelectAll?.addEventListener('change', () => {
        chChecks().forEach(c => c.checked = chSelectAll.checked);
        refreshChBulk();
    });

    async function bulkRead(read) {
        const ids = chSelected();
        if (!ids.length) return;
        try {
            const response = await fetch('{{ route('chapters.bulk_read') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ ids, read }),
            });
            const data = await response.json();
            if (data.success) {
                location.reload();
            } else {
                Novarr.showToast(data.message || 'Failed to update chapters.', 'danger');
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }

    document.getElementById('chMarkRead')?.addEventListener('click', () => bulkRead(true));
    document.getElementById('chMarkUnread')?.addEventListener('click', () => bulkRead(false));

    // ---- Download for offline (PWA), with range options ----
    function initOfflineBtn() {
        const wrap = document.getElementById('offlineControls');
        if (!wrap || !window.Novarr?.downloadNovel) return;

        const id = parseInt(wrap.dataset.id, 10);
        const btn = document.getElementById('offlineBtn');
        const removeBtn = document.getElementById('offlineRemove');

        // Fill the option counts from the page's stats.
        wrap.querySelectorAll('.offl-total').forEach(e => e.textContent = wrap.dataset.total || '0');
        wrap.querySelectorAll('.offl-unread').forEach(e => e.textContent = wrap.dataset.unread || '0');

        async function reflect() {
            const rec = await Novarr.getNovel(id);
            btn.textContent = rec ? `✓ ${rec.chapterCount} offline` : 'Download for offline';
            btn.classList.toggle('btn-info', !!rec);
            btn.classList.toggle('btn-outline-info', !rec);
            removeBtn.classList.toggle('d-none', !rec);
        }
        reflect();

        function closeMenu() {
            window.bootstrap?.Dropdown.getOrCreateInstance(btn).hide();
        }

        async function run(opts) {
            closeMenu();
            btn.disabled = true;
            btn.classList.add('disabled');
            try {
                const r = await Novarr.downloadNovel(id, opts, (done, total) => {
                    btn.textContent = `Saving ${done}/${total}…`;
                });
                Novarr.showToast(`Saved ${r.addedCount} chapter(s) for offline (${r.cachedCount} total).`, 'success');
            } catch (err) {
                Novarr.showToast('Download failed: ' + err.message, 'danger');
            } finally {
                btn.disabled = false;
                btn.classList.remove('disabled');
                reflect();
            }
        }

        wrap.querySelectorAll('[data-scope]').forEach(el => el.addEventListener('click', () => {
            const scope = el.dataset.scope;
            if (scope === 'range') {
                const from = document.getElementById('offlFrom').value.trim();
                const to = document.getElementById('offlTo').value.trim();
                if (!from && !to) {
                    Novarr.showToast('Enter a “from” and/or “to” chapter number.', 'warning');
                    return;
                }
                run({ scope: 'range', from, to });
            } else if (scope === 'unread-next') {
                run({ scope: 'unread-next', limit: parseInt(el.dataset.limit, 10) || 100 });
            } else {
                run({ scope });
            }
        }));

        removeBtn.addEventListener('click', async () => {
            removeBtn.disabled = true;
            try {
                await Novarr.removeNovel(id);
                Novarr.showToast('Removed offline copy.', 'info');
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                removeBtn.disabled = false;
                reflect();
            }
        });
    }

    // window.Novarr is set by the deferred app.js module, which runs after this
    // inline script on a hard load but is already present on Turbo visits.
    if (window.Novarr?.downloadNovel) initOfflineBtn();
    else window.addEventListener('load', initOfflineBtn, { once: true });

    async function runCommand(btn) {
        if (btn.disabled) return;

        const command = btn.dataset.command;
        const novelId = btn.dataset.novel;
        const outputText = document.getElementById('cmdOutputText');

        if (!btn.dataset.origClass) btn.dataset.origClass = btn.className;

        setButtonState(btn, 'running');
        document.getElementById('cmdOutput').classList.remove('d-none');
        outputText.textContent = `> ${command} --novel=${novelId}\nRunning...`;

        // Commands that change the chapter list or stats shown on this page —
        // reload after they finish so the page reflects the new data.
        const reloadAfter = ['toc', 'chapter', 'metadata', 'normalize_labels', 'calculate_chapter', 'chapter_cleanser', 'chapter_cleaner'];

        try {
            const result = await Novarr.executeCommand({ command, novel_id: novelId });
            setButtonState(btn, result.success ? 'done' : 'fail');
            outputText.textContent = `> ${command} --novel=${novelId}\n${result.output || result.error || 'Done'}`;
            outputText.scrollTop = outputText.scrollHeight;

            if (result.success && reloadAfter.includes(command)) {
                Novarr.showToast('Done — refreshing the page…', 'success');
                setTimeout(() => location.reload(), 1200);
            }
        } catch (err) {
            setButtonState(btn, 'fail');
            outputText.textContent = `> ${command}\nError: ${err.message}`;
            Novarr.showToast(err.message, 'danger');
        }
    }

    function setButtonState(btn, state) {
        const show = cls => btn.querySelector(cls).classList.remove('d-none');
        const hide = cls => btn.querySelector(cls).classList.add('d-none');

        ['.cmd-label', '.cmd-spinner', '.cmd-done', '.cmd-fail'].forEach(hide);

        if (state === 'running') {
            show('.cmd-spinner');
            btn.disabled = true;
            return;
        }

        btn.disabled = false;
        show(state === 'done' ? '.cmd-done' : '.cmd-fail');
        btn.className = `btn btn-sm ${state === 'done' ? 'btn-success' : 'btn-danger'} cmd-btn`;

        setTimeout(() => {
            ['.cmd-done', '.cmd-fail'].forEach(hide);
            show('.cmd-label');
            if (btn.dataset.origClass) btn.className = btn.dataset.origClass;
        }, 4000);
    }
</script>
@endpush
