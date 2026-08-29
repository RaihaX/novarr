@extends('layouts.app')

@section('content')
<a href="{{ route('commands.index') }}" class="back-link">
    <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Commands
</a>

<div class="row">
    <div class="col-lg-8">
        <div class="card form-panel mb-4">
            <div class="panel-head">
                <h1 class="panel-title">{{ $config['name'] }}</h1>
                <span class="mono-muted">{{ $command }}</span>
            </div>
            <div class="card-body">
                <p class="text-muted mb-4">{{ $config['description'] }}</p>

                @if($isDestructive)
                    <div class="alert alert-danger mb-4">
                        <strong>Destructive command.</strong> It may modify or delete data — review the parameters before executing.
                    </div>
                @endif

                <form id="commandForm">
                    <input type="hidden" name="command" value="{{ $command }}">

                    @if(in_array('novel_id', $config['params']))
                        <div class="form-row">
                            <label for="novel_id" class="form-label">Novel</label>
                            <select name="novel_id" id="novel_id" class="form-select">
                                <option value="0">All novels</option>
                                @foreach($novels as $novel)
                                    <option value="{{ $novel->id }}">{{ $novel->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if(in_array('name', $config['params']))
                        <div class="form-row">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" name="name" id="name" class="form-control" required>
                        </div>
                    @endif

                    @if(in_array('url', $config['params']))
                        <div class="form-row">
                            <label for="url" class="form-label">URL</label>
                            <input type="url" name="url" id="url" class="form-control" required>
                        </div>
                    @endif

                    @if(in_array('dry_run', $config['params']))
                        <div class="form-row form-check form-switch">
                            <input type="checkbox" name="dry_run" id="dry_run" class="form-check-input" value="1">
                            <label for="dry_run" class="form-check-label">Dry run</label>
                            <div class="form-text">Preview the changes without applying them.</div>
                        </div>
                    @endif

                    <div class="form-actions">
                        <button type="button" id="btnExecute" class="btn btn-primary">Execute now</button>
                        <button type="button" id="btnAsync" class="btn btn-secondary">Run in background</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="outputPanel" class="card d-none">
            <div class="panel-head">
                <h2 class="panel-title">Output</h2>
                <span id="statusBadge" class="badge"></span>
            </div>
            <pre id="commandOutput" class="cmd-output-pane"></pre>
        </div>
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
        setStatus('Running', 'badge-queued');
        buttons.forEach(b => b.disabled = true);

        try {
            const result = await Novarr.executeCommand(payload, { background });
            output.textContent = result.output || result.error || result.message || 'No output';
            setStatus(result.success ? 'Success' : 'Failed', result.success ? 'badge-success' : 'badge-failed');
        } catch (err) {
            output.textContent = 'Error: ' + err.message;
            setStatus('Error', 'badge-failed');
            Novarr.showToast(err.message, 'danger');
        } finally {
            buttons.forEach(b => b.disabled = false);
        }
    }

})();
</script>
@endpush
