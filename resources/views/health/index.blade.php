@extends('layouts.app')

@section('title', 'Health')
@section('breadcrumb')
    @include('partials.breadcrumb', ['trail' => [['System'], ['Health']]])
@endsection

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

{{-- Needs attention: owned by this page (the dashboard shows a one-line
     summary linking here). Same cached list the dashboard counts from, so
     the two never disagree; snoozed novels are listed under it. --}}
@php
    $nh = app(\App\Services\NovelHealth::class);
    $attention = \Illuminate\Support\Facades\Cache::remember('dashboard_attention', 900, fn() => $nh->needingAttention());
    $snoozed = $nh->snoozed();
@endphp
{{-- Needs attention (handoff §4): 2px warning left border, tinted header,
     count chip, title / reason / mono source per row. --}}
@if(count($attention) > 0)
    <section class="attention-panel mb-4" id="attentionPanel" aria-labelledby="attentionTitle">
        <div class="attention-header">
            <x-icon name="triangle-alert" :size="16" class="icon attention-icon" />
            <span class="attention-title" id="attentionTitle">Needs attention</span>
            <span class="chip-count" id="attentionCount">{{ count($attention) }}</span>
        </div>
        <div class="attention-list">
            @foreach($attention as $item)
                @php
                    $host = !empty($item['url']) ? parse_url($item['url'], PHP_URL_HOST) : null;
                    $host = $host ? preg_replace('/^www\./', '', $host) : null;
                @endphp
                <div class="attention-row">
                    <div class="attention-row-body">
                        <a href="{{ route('novels.show', $item['id']) }}" class="attention-row-title">{{ $item['name'] }}</a>
                        <span class="attention-row-reason">{{ $item['reason'] }}</span>
                        <span class="attention-row-source">{{ $host ?? 'no source url configured' }}</span>
                    </div>
                    <div class="attention-row-actions">
                        @if(!empty($item['url']))
                            <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="btn btn-outline-warning">Test source <x-icon name="external-link" :size="13" /></a>
                        @endif
                        <button type="button" class="btn btn-secondary snooze-btn" data-id="{{ $item['id'] }}" data-url="{{ route('novels.attention_snooze', $item['id']) }}" title="Hide from this panel for 7 days. Downloads keep running.">Snooze 7 days</button>
                    </div>
                </div>
            @endforeach
        </div>
        @include('partials.snoozed-note', ['snoozed' => $snoozed ?? collect()])
    </section>
@elseif(($snoozed ?? collect())->isNotEmpty())
    <div class="mb-4">@include('partials.snoozed-note', ['snoozed' => $snoozed])</div>
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
            <table class="table table-hover align-middle table-responsive-cards">
                <caption class="visually-hidden">Failed queue jobs</caption>
                <thead>
                    <tr>
                        <th style="width: 130px;">Queue</th>
                        <th style="width: 150px;">Failed</th>
                        <th>Error</th>
                        <th style="width: 230px;"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($failed_jobs as $job)
                        <tr data-uuid="{{ $job->uuid }}">
                            <td class="mono-figure" data-label="Queue">{{ $job->queue }}</td>
                            <td class="mono-muted text-nowrap" data-label="Failed">{{ \Carbon\Carbon::parse($job->failed_at)->diffForHumans() }}</td>
                            <td class="cell-block" data-label="Error"><span class="job-error">{{ Str::limit($job->exception, 120) }}</span></td>
                            <td class="cell-actions">
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
<div class="modal fade" id="jobModal" tabindex="-1" aria-hidden="true" aria-labelledby="jobModalTitle">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="jobModalTitle">Failed job</h5>
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
(function(){

    const panel = document.getElementById('attentionPanel');
    const countChip = document.getElementById('attentionCount');

    // "Snooze 7 days" hides the row from the panel for a week. It never
    // pauses the novel — downloads keep running — and never navigates: the
    // row fades out in place and the count chip updates.
    document.querySelectorAll('.snooze-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            btn.disabled = true;

            try {
                const response = await fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ days: 7 }),
                });
                const data = await response.json().catch(() => ({}));

                if (response.ok && data.success) {
                    const row = btn.closest('.attention-row');
                    const finish = () => {
                        row?.remove();
                        const left = panel ? panel.querySelectorAll('.attention-row').length : 0;
                        if (countChip) countChip.textContent = left;
                        if (!left) panel?.remove();
                    };
                    if (row && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        row.classList.add('is-leaving');
                        row.addEventListener('transitionend', finish, { once: true });
                        setTimeout(finish, 400); // fallback if transitionend never fires
                    } else {
                        finish();
                    }

                    Novarr.showToast(data.message || 'Snoozed for 7 days.', 'success');
                } else {
                    btn.disabled = false;
                    Novarr.showToast(data.message || 'Could not snooze this novel.', 'danger');
                }
            } catch (err) {
                btn.disabled = false;
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    });

})();
</script>
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
