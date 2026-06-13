@extends('layouts.app')

@section('content')
<h1 class="mb-4">System Health</h1>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value {{ $scheduler_stale ? 'text-danger' : 'text-success' }}">
                    {{ $scheduler_stale ? 'Stale' : 'OK' }}
                </div>
                <div class="dash-stat-label">Scheduler</div>
                <small class="text-muted">{{ $scheduler_last_run ? 'Last ran ' . \Carbon\Carbon::parse($scheduler_last_run)->diffForHumans() : 'never recorded' }}</small>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value {{ $queue_depth > 0 ? 'text-warning' : 'text-success' }}">{{ $queue_depth }}</div>
                <div class="dash-stat-label">Queued jobs</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card dash-stat">
            <div class="card-body">
                <div class="dash-stat-value" id="flareValue">…</div>
                <div class="dash-stat-label">FlareSolverr</div>
                <small class="text-muted" id="flareMsg">checking…</small>
            </div>
        </div>
    </div>
</div>

@if($scheduler_stale)
    <div class="alert alert-warning py-2" style="font-size: 13px;">
        The scheduler hasn't run in the last 3 minutes. Check that cron is invoking <code>php artisan schedule:run</code> every minute.
    </div>
@endif

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><span class="badge {{ $failed_jobs->count() ? 'bg-danger' : 'bg-secondary' }} me-2">{{ $failed_jobs->count() }}</span> Failed Jobs</h5>
        @if($failed_jobs->count())
            <div class="d-flex gap-2">
                <button type="button" id="retryAll" class="btn btn-sm btn-outline-success">Retry all</button>
                <button type="button" id="flushAll" class="btn btn-sm btn-outline-danger">Delete all</button>
            </div>
        @endif
    </div>
    <div class="table-responsive">
        @if($failed_jobs->count())
            <table class="table table-sm table-striped mb-0" style="font-size: 13px;">
                <thead>
                    <tr><th>Queue</th><th>Failed</th><th>Error</th><th style="width: 140px;"></th></tr>
                </thead>
                <tbody>
                    @foreach($failed_jobs as $job)
                        <tr data-uuid="{{ $job->uuid }}">
                            <td>{{ $job->queue }}</td>
                            <td class="text-nowrap text-muted">{{ \Carbon\Carbon::parse($job->failed_at)->diffForHumans() }}</td>
                            <td><code style="font-size: 12px;">{{ Str::limit($job->exception, 120) }}</code></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-success job-retry" data-uuid="{{ $job->uuid }}">Retry</button>
                                <button type="button" class="btn btn-sm btn-outline-danger job-forget" data-uuid="{{ $job->uuid }}">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="card-body"><p class="text-muted mb-0">No failed jobs. 🎉</p></div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const post = (url) => fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }).then(r => r.json());

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
        await post('{{ route('health.retry_all') }}');
        Novarr.showToast('All failed jobs re-queued.', 'success');
        setTimeout(() => location.reload(), 800);
    });
    document.getElementById('flushAll')?.addEventListener('click', async () => {
        if (!confirm('Delete all failed job records?')) return;
        await post('{{ route('health.flush') }}');
        setTimeout(() => location.reload(), 500);
    });

    // Async FlareSolverr check (reuses the settings test endpoint)
    post('{{ route('settings.test_flaresolverr') }}').then(data => {
        document.getElementById('flareValue').textContent = data.success ? 'OK' : 'Down';
        document.getElementById('flareValue').className = 'dash-stat-value ' + (data.success ? 'text-success' : 'text-danger');
        document.getElementById('flareMsg').textContent = data.message;
    }).catch(() => {
        document.getElementById('flareValue').textContent = 'Down';
        document.getElementById('flareValue').className = 'dash-stat-value text-danger';
        document.getElementById('flareMsg').textContent = 'Check failed';
    });
</script>
@endpush
