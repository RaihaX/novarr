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
                <button type="button" id="btnExecute" class="btn btn-primary">Execute Now</button>
                <button type="button" id="btnAsync" class="btn btn-outline-primary">Run in Background</button>
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
        <pre id="commandOutput" class="command-output bg-dark text-light p-3 rounded"></pre>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function(){

    const output = document.getElementById('commandOutput');
    const badge = document.getElementById('statusBadge');
    const buttons = [document.getElementById('btnExecute'), document.getElementById('btnAsync')];

    buttons[0].addEventListener('click', () => run(false));
    buttons[1].addEventListener('click', () => run(true));

    function setStatus(text, badgeClass) {
        badge.className = 'badge ' + badgeClass;
        badge.textContent = text;
    }

    async function run(background) {
        const payload = Object.fromEntries(new FormData(document.getElementById('commandForm')));

        document.getElementById('outputPanel').classList.remove('d-none');
        output.textContent = background ? 'Command queued. Polling for results...' : 'Running...';
        setStatus('Running', 'bg-warning text-dark');
        buttons.forEach(b => b.disabled = true);

        try {
            const result = await Novarr.executeCommand(payload, { background });
            output.textContent = result.output || result.error || result.message || 'No output';
            setStatus(result.success ? 'Success' : 'Failed', result.success ? 'bg-success' : 'bg-danger');
        } catch (err) {
            output.textContent = 'Error: ' + err.message;
            setStatus('Error', 'bg-danger');
            Novarr.showToast(err.message, 'danger');
        } finally {
            buttons.forEach(b => b.disabled = false);
        }
    }

})();
</script>
@endpush
