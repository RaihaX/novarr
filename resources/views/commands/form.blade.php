@extends('layouts.app')

@section('content')
<div class="mb-3">
    <a href="{{ route('commands.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Back to Commands</a>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h3 class="mb-0">{{ $config['name'] }}</h3>
        <small class="text-muted">{{ $config['description'] }}</small>
    </div>
    <div class="card-body">
        @if($isDestructive)
            <div class="alert alert-danger">
                <strong>Warning:</strong> This is a destructive command. It may modify or delete data. Please review parameters carefully before executing.
            </div>
        @endif

        <form id="commandForm">
            <input type="hidden" name="command" value="{{ $command }}">

            @if(in_array('novel_id', $config['params']))
                <div class="mb-3">
                    <label for="novel_id" class="form-label">Novel</label>
                    <select name="novel_id" id="novel_id" class="form-select">
                        <option value="0">All Novels</option>
                        @foreach($novels as $novel)
                            <option value="{{ $novel->id }}">{{ $novel->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if(in_array('name', $config['params']))
                <div class="mb-3">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" name="name" id="name" class="form-control" required>
                </div>
            @endif

            @if(in_array('url', $config['params']))
                <div class="mb-3">
                    <label for="url" class="form-label">URL</label>
                    <input type="url" name="url" id="url" class="form-control" required>
                </div>
            @endif

            @if(in_array('dry_run', $config['params']))
                <div class="mb-3 form-check">
                    <input type="checkbox" name="dry_run" id="dry_run" class="form-check-input" value="1">
                    <label for="dry_run" class="form-check-label">Dry Run (preview changes without applying)</label>
                </div>
            @endif

            <div class="d-flex gap-2">
                <button type="button" id="btnExecute" class="btn btn-primary" onclick="executeCommand(false)">Execute Now</button>
                <button type="button" id="btnAsync" class="btn btn-outline-primary" onclick="executeCommand(true)">Run in Background</button>
            </div>
        </form>
    </div>
</div>

<div id="outputPanel" class="card d-none">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Output</h5>
        <span id="statusBadge" class="badge"></span>
    </div>
    <div class="card-body">
        <pre id="commandOutput" class="bg-dark text-light p-3 rounded" style="max-height: 500px; overflow-y: auto; white-space: pre-wrap; font-size: 13px;"></pre>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    function executeCommand(async) {
        const form = document.getElementById('commandForm');
        const formData = new FormData(form);
        const outputPanel = document.getElementById('outputPanel');
        const output = document.getElementById('commandOutput');
        const badge = document.getElementById('statusBadge');
        const btnExecute = document.getElementById('btnExecute');
        const btnAsync = document.getElementById('btnAsync');

        outputPanel.classList.remove('d-none');
        output.textContent = 'Running...';
        badge.className = 'badge bg-warning text-dark';
        badge.textContent = 'Running';
        btnExecute.disabled = true;
        btnAsync.disabled = true;

        const url = async ? '{{ route("commands.execute-async") }}' : '{{ route("commands.execute") }}';

        fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(Object.fromEntries(formData)),
        })
        .then(r => r.json())
        .then(data => {
            if (async && data.success && data.job_id) {
                output.textContent = 'Command queued. Polling for results...';
                pollStatus(data.job_id);
            } else {
                showResult(data);
            }
        })
        .catch(err => {
            output.textContent = 'Error: ' + err.message;
            badge.className = 'badge bg-danger';
            badge.textContent = 'Error';
            btnExecute.disabled = false;
            btnAsync.disabled = false;
        });
    }

    function pollStatus(jobId) {
        const interval = setInterval(() => {
            fetch('{{ url("commands/status") }}/' + jobId, {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'completed') {
                    clearInterval(interval);
                    showResult(data.result);
                }
            })
            .catch(() => {
                clearInterval(interval);
                document.getElementById('commandOutput').textContent = 'Error polling status.';
                document.getElementById('statusBadge').className = 'badge bg-danger';
                document.getElementById('statusBadge').textContent = 'Error';
                document.getElementById('btnExecute').disabled = false;
                document.getElementById('btnAsync').disabled = false;
            });
        }, 2000);
    }

    function showResult(data) {
        const output = document.getElementById('commandOutput');
        const badge = document.getElementById('statusBadge');

        output.textContent = data.output || data.error || data.message || 'No output';

        if (data.success) {
            badge.className = 'badge bg-success';
            badge.textContent = 'Success';
        } else {
            badge.className = 'badge bg-danger';
            badge.textContent = 'Failed';
        }

        document.getElementById('btnExecute').disabled = false;
        document.getElementById('btnAsync').disabled = false;
    }
</script>
@endpush
