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
            <div class="d-flex gap-2">
                @if($continue_chapter_id)
                    <a href="{{ route('chapters.show', $continue_chapter_id) }}" class="btn btn-sm btn-primary">{{ $read_count > 0 ? 'Continue reading' : 'Start reading' }}</a>
                @endif
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
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-primary cmd-btn" data-command="toc" data-novel="{{ $data->id }}">
                <span class="cmd-label">Scrape TOC</span>
                <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                <span class="cmd-done d-none">Done</span>
                <span class="cmd-fail d-none">Failed</span>
            </button>
            <button class="btn btn-sm btn-primary cmd-btn" data-command="chapter" data-novel="{{ $data->id }}">
                <span class="cmd-label">Download Chapters</span>
                <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                <span class="cmd-done d-none">Done</span>
                <span class="cmd-fail d-none">Failed</span>
            </button>

            <div class="vr mx-1" style="opacity: 0.2;"></div>

            <button class="btn btn-sm btn-outline-success cmd-btn" data-command="epub" data-novel="{{ $data->id }}">
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

            <div class="vr mx-1" style="opacity: 0.2;"></div>

            <button class="btn btn-sm btn-outline-warning cmd-btn" data-command="metadata" data-novel="{{ $data->id }}">
                <span class="cmd-label">Refresh Metadata</span>
                <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                <span class="cmd-done d-none">Done</span>
                <span class="cmd-fail d-none">Failed</span>
            </button>
            <button class="btn btn-sm btn-outline-info cmd-btn" data-command="normalize_labels" data-novel="{{ $data->id }}">
                <span class="cmd-label">Normalize Labels</span>
                <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                <span class="cmd-done d-none">Done</span>
                <span class="cmd-fail d-none">Failed</span>
            </button>
            <button class="btn btn-sm btn-outline-secondary cmd-btn" data-command="chapter_cleanser" data-novel="{{ $data->id }}">
                <span class="cmd-label">Cleanse Chapters</span>
                <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                <span class="cmd-done d-none">Done</span>
                <span class="cmd-fail d-none">Failed</span>
            </button>
            <button class="btn btn-sm btn-outline-secondary cmd-btn" data-command="chapter_cleaner" data-novel="{{ $data->id }}">
                <span class="cmd-label">Clean Chapters</span>
                <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                <span class="cmd-done d-none">Done</span>
                <span class="cmd-fail d-none">Failed</span>
            </button>
        </div>
    </div>
    <div id="cmdOutput" class="d-none">
        <pre id="cmdOutputText" class="mb-0 p-3" style="max-height: 250px; overflow-y: auto; white-space: pre-wrap; font-size: 12px; background: #0d1117; color: #8b949e; border-top: 1px solid rgba(255,255,255,0.05); border-radius: 0 0 6px 6px;"></pre>
    </div>
</div>

{{-- Chapters Table --}}
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Chapters</h6>
        <div class="d-flex gap-2 align-items-center">
            <div id="chBulkBar" class="d-none align-items-center gap-2">
                <span id="chBulkCount" class="text-muted" style="font-size: 12px;"></span>
                <button type="button" id="chMarkRead" class="btn btn-sm btn-outline-success">Mark read</button>
                <button type="button" id="chMarkUnread" class="btn btn-sm btn-outline-secondary">Mark unread</button>
            </div>
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
            if (!confirm(`Delete "${deleteBtn.dataset.name}" and all of its chapters? This cannot be undone from the UI.`)) return;
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
