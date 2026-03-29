@extends('layouts.app')

@section('content')
<div class="mb-3">
    <a href="{{ route('novels.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Back to Novels</a>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                @if($data->file)
                    <img src="{{ Storage::url($data->file->file_path) }}" alt="{{ $data->name }}" class="img-fluid rounded mb-3" style="max-height: 280px;">
                @else
                    <div class="rounded d-flex align-items-center justify-content-center mb-3" style="height: 200px; background: #2c3034;">
                        <span class="text-muted">No Cover</span>
                    </div>
                @endif
                <div class="d-grid gap-2">
                    <a href="{{ route('novels.download_epub', $data->id) }}" class="btn btn-primary btn-sm">Download ePub</a>
                    <a href="{{ route('novels.get_metadata', $data->id) }}" class="btn btn-outline-secondary btn-sm">Update Metadata</a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-9">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="mb-0">{{ $data->name }}</h3>
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0" style="border: none;">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width: 120px; border: none;">Author</td>
                            <td style="border: none;">{{ $data->author ?? 'Unknown' }}</td>
                            <td class="text-muted" style="width: 120px; border: none;">Status</td>
                            <td style="border: none;">
                                @if($data->status)
                                    <span class="badge bg-info">Completed</span>
                                @else
                                    <span class="badge bg-success">Active</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted" style="border: none;">Group</td>
                            <td style="border: none;">{{ $data->group->label ?? '-' }}</td>
                            <td class="text-muted" style="border: none;">Language</td>
                            <td style="border: none;">{{ $data->language->label ?? '-' }}</td>
                        </tr>
                        @if($data->external_url)
                            <tr>
                                <td class="text-muted" style="border: none;">URL</td>
                                <td colspan="3" style="border: none;"><a href="{{ $data->external_url }}" target="_blank">{{ Str::limit($data->external_url, 60) }}</a></td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-3">
                <div class="card text-center">
                    <div class="card-body py-2">
                        <div class="h4 mb-0 text-success">{{ $current_chapters }}</div>
                        <small class="text-muted">Downloaded</small>
                    </div>
                </div>
            </div>
            <div class="col-3">
                <div class="card text-center">
                    <div class="card-body py-2">
                        <div class="h4 mb-0 text-warning">{{ $current_chapters_not_downloaded }}</div>
                        <small class="text-muted">Pending</small>
                    </div>
                </div>
            </div>
            <div class="col-3">
                <div class="card text-center">
                    <div class="card-body py-2">
                        <div class="h4 mb-0 text-danger">{{ count($missing_chapters) }}</div>
                        <small class="text-muted">Missing</small>
                    </div>
                </div>
            </div>
            <div class="col-3">
                <div class="card text-center">
                    <div class="card-body py-2">
                        <div class="h4 mb-0 text-info">{{ $progress }}%</div>
                        <small class="text-muted">Progress</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="progress mb-3" style="height: 10px;">
            <div class="progress-bar {{ $progress >= 100 ? 'bg-success' : 'bg-info' }}" style="width: {{ $progress }}%"></div>
        </div>

        @if($data->description)
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0">Description</h6></div>
                <div class="card-body" style="line-height: 1.7; font-size: 14px;">
                    {!! $data->description !!}
                </div>
            </div>
        @endif
    </div>
</div>

{{-- Quick Actions --}}
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Quick Actions</h5>
    </div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-primary cmd-btn" data-command="toc" data-novel="{{ $data->id }}">
                    <span class="cmd-label">Scrape TOC</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running...</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-primary cmd-btn" data-command="chapter" data-novel="{{ $data->id }}">
                    <span class="cmd-label">Download Chapters</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running...</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-success cmd-btn" data-command="epub" data-novel="{{ $data->id }}">
                    <span class="cmd-label">Generate ePub</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running...</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-info cmd-btn" data-command="normalize_labels" data-novel="{{ $data->id }}">
                    <span class="cmd-label">Normalize Labels</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running...</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-warning cmd-btn" data-command="chapter_cleanser" data-novel="{{ $data->id }}">
                    <span class="cmd-label">Cleanse Chapters</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running...</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-danger cmd-btn" data-command="chapter_cleaner" data-novel="{{ $data->id }}">
                    <span class="cmd-label">Clean Chapters</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running...</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
        </div>
    </div>
    <div id="cmdOutput" class="d-none">
        <div class="card-footer p-0">
            <pre id="cmdOutputText" class="mb-0 p-3" style="max-height: 300px; overflow-y: auto; white-space: pre-wrap; font-size: 12px; background: #111; color: #ccc; border-radius: 0;"></pre>
        </div>
    </div>
</div>

{{-- Chapters Table --}}
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Chapters</h5>
        <span class="badge bg-secondary">{{ $chapters->total() }} total</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-sm mb-0 align-middle">
            <thead class="table-dark">
                <tr>
                    <th style="width: 80px">Chapter</th>
                    <th style="width: 60px">Book</th>
                    <th>Label</th>
                    <th style="width: 100px">Status</th>
                    <th style="width: 140px">Download Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse($chapters as $chapter)
                    <tr>
                        <td>{{ $chapter->chapter }}</td>
                        <td>{{ $chapter->book ?: '-' }}</td>
                        <td>{{ Str::limit($chapter->label, 80) }}</td>
                        <td>
                            @if($chapter->status)
                                <span class="badge bg-success">Downloaded</span>
                            @else
                                <span class="badge bg-warning text-dark">Pending</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $chapter->download_date ? $chapter->download_date->format('Y-m-d H:i') : '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-3">No chapters found.</td>
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

        // Reset state
        label.classList.add('d-none');
        done.classList.add('d-none');
        fail.classList.add('d-none');
        spinner.classList.remove('d-none');
        btn.disabled = true;
        btn.classList.remove('btn-outline-success', 'btn-outline-danger');

        // Show output panel
        outputPanel.classList.remove('d-none');
        outputText.textContent = `Running ${command}...`;

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
                outputText.textContent = `Command queued (${data.job_id}). Waiting for result...`;
                pollStatus(data.job_id, btn);
            } else {
                finishCommand(btn, false, data.message || 'Failed to queue');
                outputText.textContent = data.message || 'Failed to queue command';
            }
        })
        .catch(err => {
            finishCommand(btn, false);
            outputText.textContent = 'Error: ' + err.message;
        });
    }

    function pollStatus(jobId, btn) {
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
                    outputText.textContent = result.output || result.error || 'Completed';
                }
            })
            .catch(() => {
                clearInterval(interval);
                finishCommand(btn, false);
                outputText.textContent = 'Lost connection while polling.';
            });
        }, 2000);
    }

    function finishCommand(btn, success) {
        const spinner = btn.querySelector('.cmd-spinner');
        const done = btn.querySelector('.cmd-done');
        const fail = btn.querySelector('.cmd-fail');
        const label = btn.querySelector('.cmd-label');

        spinner.classList.add('d-none');
        btn.disabled = false;

        if (success) {
            done.classList.remove('d-none');
            btn.classList.remove('btn-outline-primary', 'btn-outline-info', 'btn-outline-warning', 'btn-outline-danger');
            btn.classList.add('btn-outline-success');
        } else {
            fail.classList.remove('d-none');
            btn.classList.remove('btn-outline-primary', 'btn-outline-info', 'btn-outline-warning', 'btn-outline-success');
            btn.classList.add('btn-outline-danger');
        }

        // After 4 seconds, revert back to the label
        setTimeout(() => {
            done.classList.add('d-none');
            fail.classList.add('d-none');
            label.classList.remove('d-none');
            // Restore original class from data attribute or keep current
        }, 4000);
    }
</script>
@endpush
