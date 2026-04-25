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

{{-- Hero Section --}}
<div class="row mb-4">
    <div class="col-md-2">
        @if($data->file)
            <img src="{{ Storage::url($data->file->file_path) }}" alt="{{ $data->name }}" class="novel-cover img-fluid w-100 mb-3">
        @else
            <div class="novel-cover d-flex align-items-center justify-content-center w-100 mb-3" style="height: 220px; background: #2c3034;">
                <span class="text-muted">No Cover</span>
            </div>
        @endif
    </div>

    <div class="col-md-10">
        <div class="d-flex align-items-start justify-content-between mb-2">
            <div>
                <h2 class="mb-1">{{ $data->name }}</h2>
                <span class="text-muted">by {{ $data->author ?? 'Unknown' }}</span>
                @if($data->status)
                    <span class="badge bg-info ms-2">Completed</span>
                @else
                    <span class="badge bg-success ms-2">Active</span>
                @endif
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

        <div class="progress mb-3" style="height: 6px; border-radius: 3px;">
            <div class="progress-bar {{ $progress >= 100 ? 'bg-success' : 'bg-info' }}" style="width: {{ $progress }}%; border-radius: 3px;"></div>
        </div>

        @if($data->description)
            <div style="font-size: 13px; line-height: 1.7; color: #adb5bd; max-height: 120px; overflow-y: auto;">
                {!! $data->description !!}
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
        <span class="badge bg-secondary">{{ $chapters->total() }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead>
                <tr style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d;">
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
                        <td class="fw-semibold">{{ $chapter->chapter }}</td>
                        <td class="text-muted">{{ $chapter->book ?: '-' }}</td>
                        <td>
                            @if($chapter->status)
                                <a href="{{ route('chapters.show', $chapter->id) }}" class="text-decoration-none">{{ Str::limit($chapter->label, 90) }}</a>
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
                        <td colspan="5" class="text-center text-muted py-4">No chapters found.</td>
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
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    document.querySelectorAll('.cmd-btn').forEach(btn => {
        btn.addEventListener('click', () => runCommand(btn));
    });

    function runCommand(btn) {
        if (btn.disabled) return;

        const command = btn.dataset.command;
        const novelId = btn.dataset.novel;
        const label = btn.querySelector('.cmd-label');
        const spinner = btn.querySelector('.cmd-spinner');
        const done = btn.querySelector('.cmd-done');
        const fail = btn.querySelector('.cmd-fail');
        const outputPanel = document.getElementById('cmdOutput');
        const outputText = document.getElementById('cmdOutputText');

        // Store original classes to restore later
        if (!btn.dataset.origClass) btn.dataset.origClass = btn.className;

        // Set running state
        label.classList.add('d-none');
        done.classList.add('d-none');
        fail.classList.add('d-none');
        spinner.classList.remove('d-none');
        btn.disabled = true;

        outputPanel.classList.remove('d-none');
        outputText.textContent = `> ${command} --novel=${novelId}\nQueuing...`;

        fetch('{{ route("commands.execute-async") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ command: command, novel_id: novelId }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.job_id) {
                outputText.textContent = `> ${command} --novel=${novelId}\nRunning...`;
                pollStatus(data.job_id, btn, command, novelId);
            } else {
                finishCommand(btn, false);
                outputText.textContent = `> ${command}\n${data.message || 'Failed to queue'}`;
            }
        })
        .catch(err => {
            finishCommand(btn, false);
            outputText.textContent = `> ${command}\nError: ${err.message}`;
        });
    }

    function pollStatus(jobId, btn, command, novelId) {
        const outputText = document.getElementById('cmdOutputText');
        const interval = setInterval(() => {
            fetch('{{ url("commands/status") }}/' + jobId, {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'completed') {
                    clearInterval(interval);
                    const result = data.result;
                    finishCommand(btn, result.success);
                    outputText.textContent = `> ${command} --novel=${novelId}\n${result.output || result.error || 'Done'}`;
                    // Auto-scroll output
                    outputText.scrollTop = outputText.scrollHeight;
                }
            })
            .catch(() => {
                clearInterval(interval);
                finishCommand(btn, false);
                outputText.textContent = `> ${command}\nLost connection while polling.`;
            });
        }, 2000);
    }

    function finishCommand(btn, success) {
        const spinner = btn.querySelector('.cmd-spinner');
        const done = btn.querySelector('.cmd-done');
        const fail = btn.querySelector('.cmd-fail');
        const label = btn.querySelector('.cmd-label');
        const origClass = btn.dataset.origClass;

        spinner.classList.add('d-none');
        btn.disabled = false;

        if (success) {
            done.classList.remove('d-none');
            btn.className = 'btn btn-sm btn-success cmd-btn';
        } else {
            fail.classList.remove('d-none');
            btn.className = 'btn btn-sm btn-danger cmd-btn';
        }

        setTimeout(() => {
            done.classList.add('d-none');
            fail.classList.add('d-none');
            label.classList.remove('d-none');
            if (origClass) btn.className = origClass;
        }, 4000);
    }
</script>
@endpush
