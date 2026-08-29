@extends('layouts.app')

@section('content')
<h1 class="page-title mb-4">System health</h1>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value {{ $scheduler_stale ? 'value-danger' : 'value-success' }}">
                    {{ $scheduler_stale ? 'STALE' : 'OK' }}
                </div>
                <div class="dash-stat-label">Scheduler</div>
                <span class="health-detail">{{ $scheduler_last_run ? 'Last ran ' . \Carbon\Carbon::parse($scheduler_last_run)->diffForHumans() : 'never recorded' }}</span>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value {{ $queue_depth > 0 ? 'value-pending' : 'value-success' }}">{{ number_format($queue_depth) }}</div>
                <div class="dash-stat-label">Queued jobs</div>
                <span class="health-detail">{{ $queue_depth > 0 ? 'waiting for a worker' : 'queue drained' }}</span>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value" id="flareValue">…</div>
                <div class="dash-stat-label">FlareSolverr</div>
                <span class="health-detail" id="flareMsg">checking…</span>
            </div>
        </div>
    </div>
</div>

@if($scheduler_stale)
    <div class="alert alert-warning mb-4">
        The scheduler hasn't run in the last 3 minutes. Check that cron is invoking <code>php artisan schedule:run</code> every minute.
    </div>
@endif

<div class="card">
    <div class="panel-head">
        <h2 class="panel-title">
            Failed jobs
            <span class="count-chip {{ $failed_jobs->count() ? 'is-danger' : '' }}">{{ number_format($failed_jobs->count()) }}</span>
        </h2>
        @if($failed_jobs->count())
            <div class="panel-tools">
                <button type="button" id="retryAll" class="btn btn-secondary btn-sm">Retry all</button>
                <button type="button" id="flushAll" class="btn btn-danger btn-sm">Delete all</button>
            </div>
        @endif
    </div>
    @if($failed_jobs->count())
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th style="width: 130px;">Queue</th>
                        <th style="width: 150px;">Failed</th>
                        <th>Error</th>
                        <th style="width: 230px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($failed_jobs as $job)
                        <tr data-uuid="{{ $job->uuid }}">
                            <td class="mono-figure">{{ $job->queue }}</td>
                            <td class="mono-muted text-nowrap">{{ \Carbon\Carbon::parse($job->failed_at)->diffForHumans() }}</td>
                            <td><span class="job-error">{{ Str::limit($job->exception, 120) }}</span></td>
                            <td>
                                <div class="job-actions">
                                    <button type="button" class="btn btn-secondary job-details" data-uuid="{{ $job->uuid }}">Details</button>
                                    <button type="button" class="btn btn-secondary job-retry" data-uuid="{{ $job->uuid }}">Retry</button>
                                    <button type="button" class="btn btn-danger job-forget" data-uuid="{{ $job->uuid }}">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="card-body">
            <p class="text-muted mb-0">No failed jobs — everything the queue has picked up has completed.</p>
        </div>
    @endif
</div>

{{-- Failed job detail modal --}}
<div class="modal fade" id="jobModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Failed job</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="kv-grid mb-4">
                    <dt>Command</dt><dd id="jmCommand"></dd>
                    <dt>Params</dt><dd id="jmParams"></dd>
                    <dt>Queue</dt><dd id="jmQueue"></dd>
                    <dt>Failed</dt><dd id="jmFailed"></dd>
                </dl>
                <div class="label-caption mb-2">Exception</div>
                <pre id="jmException" class="trace-pane"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="jmRetry">Retry</button>
                <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const post = (url) => fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }).then(r => r.json());

    // Details modal — instantiate lazily (window.bootstrap is set by the
    // deferred module, which runs after this inline script on first load).
    let modalUuid = null;
    const modalEl = document.getElementById('jobModal');
    const getModal = () => window.bootstrap.Modal.getOrCreateInstance(modalEl);

    document.querySelectorAll('.job-details').forEach(b => b.addEventListener('click', async () => {
        try {
            const data = await fetch(`/health/job/${b.dataset.uuid}`, { headers: { 'Accept': 'application/json' } }).then(r => r.json());
            if (!data.success) { Novarr.showToast('Could not load job.', 'danger'); return; }
            modalUuid = data.uuid;
            document.getElementById('jmCommand').textContent = data.command || '—';
            document.getElementById('jmParams').textContent = data.params || '—';
            document.getElementById('jmQueue').textContent = data.queue;
            document.getElementById('jmFailed').textContent = data.failed_at;
            document.getElementById('jmException').textContent = data.exception;
            getModal().show();
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }));

    document.getElementById('jmRetry')?.addEventListener('click', async () => {
        if (!modalUuid) return;
        await post(`/health/retry/${modalUuid}`);
        Novarr.showToast('Job re-queued.', 'success');
        getModal().hide();
        document.querySelector(`tr[data-uuid="${modalUuid}"]`)?.remove();
    });

    document.querySelectorAll('.job-retry').forEach(b => b.addEventListener('click', async () => {
        await post(`/health/retry/${b.dataset.uuid}`);
        Novarr.showToast('Job re-queued.', 'success');
        b.closest('tr').remove();
    }));
    document.querySelectorAll('.job-forget').forEach(b => b.addEventListener('click', async () => {
        await post(`/health/forget/${b.dataset.uuid}`);
        b.closest('tr').remove();
    }));
    document.getElementById('retryAll')?.addEventListener('click', async () => {
        if (!await Novarr.confirmDialog('Re-queue every failed job?', { title: 'Retry all failed jobs', confirmText: 'Retry all' })) return;
        await post('{{ route('health.retry_all') }}');
        Novarr.showToast('All failed jobs re-queued.', 'success');
        setTimeout(() => location.reload(), 800);
    });
    document.getElementById('flushAll')?.addEventListener('click', async () => {
        if (!await Novarr.confirmDialog('Delete all failed job records?', { title: 'Flush failed jobs', confirmText: 'Delete', danger: true })) return;
        await post('{{ route('health.flush') }}');
        setTimeout(() => location.reload(), 500);
    });

    // Async FlareSolverr check (reuses the settings test endpoint)
    post('{{ route('settings.test_flaresolverr') }}').then(data => {
        document.getElementById('flareValue').textContent = data.success ? 'OK' : 'DOWN';
        document.getElementById('flareValue').className = 'dash-stat-value ' + (data.success ? 'value-success' : 'value-danger');
        document.getElementById('flareMsg').textContent = data.message;
    }).catch(() => {
        document.getElementById('flareValue').textContent = 'DOWN';
        document.getElementById('flareValue').className = 'dash-stat-value value-danger';
        document.getElementById('flareMsg').textContent = 'Check failed';
    });
})();
</script>
@endpush
