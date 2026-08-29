@extends('layouts.app')

@section('content')
<a href="{{ route('logs.index') }}" class="back-link">
    <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Logs
</a>

<div class="log-head">
    <span class="log-head-name">{{ $filename }}</span>
    <div class="log-head-tools">
        <div class="form-check form-switch mb-0 me-2">
            <input class="form-check-input" type="checkbox" id="liveTail">
            <label class="form-check-label label-caption" for="liveTail">Live tail</label>
        </div>
        <a href="{{ route('logs.download', $filename) }}" class="btn btn-secondary btn-sm">Download</a>
        <button type="button" id="clearLog" class="btn btn-warning btn-sm">Clear log</button>
    </div>
</div>

<div class="card mb-3 form-panel">
    <form method="GET" action="{{ route('logs.show', $filename) }}" class="log-toolbar">
        <select name="level" class="form-select" aria-label="Minimum level">
            @foreach($levels as $lvl)
                <option value="{{ $lvl }}" @selected($level === $lvl)>{{ ucfirst($lvl) }}</option>
            @endforeach
        </select>
        <input type="text" name="search" class="form-control log-search" placeholder="Search entries…" value="{{ $search }}" aria-label="Search log entries">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="{{ route('logs.show', $filename) }}" class="btn btn-ghost btn-sm">Reset</a>
        <span class="log-count">{{ number_format($totalEntries) }} entries · page {{ $currentPage }}/{{ $totalPages }}</span>
    </form>
</div>

@if($truncated)
    <div class="alert alert-secondary mb-3">
        Large file — showing entries from the most recent 5&nbsp;MB only. Use Download for the full history.
    </div>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table log-table mb-0">
            <thead>
                <tr>
                    <th style="width: 190px;">Timestamp</th>
                    <th style="width: 110px;">Level</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody id="logBody">
                @forelse($entries as $entry)
                    <tr>
                        <td class="log-time">{{ $entry['timestamp'] }}</td>
                        <td>
                            @php
                                $levelColors = [
                                    'emergency' => 'danger',
                                    'alert' => 'danger',
                                    'critical' => 'danger',
                                    'error' => 'danger',
                                    'warning' => 'warning',
                                    'notice' => 'info',
                                    'info' => 'info',
                                    'debug' => 'secondary',
                                ];
                                $color = $levelColors[$entry['level']] ?? 'secondary';
                            @endphp
                            <span class="badge bg-{{ $color }}">{{ $entry['level'] }}</span>
                        </td>
                        <td>
                            @if(mb_strlen($entry['message']) > 400)
                                <details class="log-entry">
                                    <summary>{{ Str::limit($entry['message'], 200) }} <span class="text-muted">(expand)</span></summary>
                                    <pre class="log-pre mt-2">{{ $entry['message'] }}</pre>
                                </details>
                            @else
                                <pre class="log-pre">{{ $entry['message'] }}</pre>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-muted py-4">No log entries found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($totalPages > 1)
        <div class="card-footer">
            <nav aria-label="Log pages">
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    @if($currentPage > 1)
                        <li class="page-item">
                            <a class="page-link" href="{{ route('logs.show', $filename) }}?page={{ $currentPage - 1 }}&level={{ $level }}&search={{ $search }}">Prev</a>
                        </li>
                    @endif
                    @for($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++)
                        <li class="page-item @if($i === $currentPage) active @endif">
                            <a class="page-link" href="{{ route('logs.show', $filename) }}?page={{ $i }}&level={{ $level }}&search={{ $search }}">{{ $i }}</a>
                        </li>
                    @endfor
                    @if($currentPage < $totalPages)
                        <li class="page-item">
                            <a class="page-link" href="{{ route('logs.show', $filename) }}?page={{ $currentPage + 1 }}&level={{ $level }}&search={{ $search }}">Next</a>
                        </li>
                    @endif
                </ul>
            </nav>
        </div>
    @endif
</div>
@endsection


@push('scripts')
<script>
(function(){

    const levelColors = {
        emergency: 'danger', alert: 'danger', critical: 'danger', error: 'danger',
        warning: 'warning', notice: 'info', info: 'info', debug: 'secondary',
    };
    const tbody = document.getElementById('logBody');
    const liveToggle = document.getElementById('liveTail');
    let liveTimer = null;

    function renderEntries(entries) {
        tbody.innerHTML = '';

        for (const entry of entries) {
            const tr = document.createElement('tr');

            const tsTd = document.createElement('td');
            tsTd.className = 'log-time';
            tsTd.textContent = entry.timestamp;

            const lvlTd = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = 'badge bg-' + (levelColors[entry.level] || 'secondary');
            badge.textContent = entry.level;
            lvlTd.appendChild(badge);

            const msgTd = document.createElement('td');
            const pre = document.createElement('pre');
            pre.className = 'log-pre';
            pre.textContent = entry.message;
            msgTd.appendChild(pre);

            tr.append(tsTd, lvlTd, msgTd);
            tbody.appendChild(tr);
        }

        if (!entries.length) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">Log is empty.</td></tr>';
        }
    }

    async function refreshTail() {
        try {
            const response = await fetch('{{ route('logs.tail', $filename) }}', { headers: { 'Accept': 'application/json' } });
            const data = await response.json();
            if (data.success) renderEntries(data.entries);
        } catch (e) {
            // keep polling; transient errors are fine
        }
    }

    liveToggle.addEventListener('change', () => {
        if (liveToggle.checked) {
            refreshTail();
            liveTimer = setInterval(refreshTail, 3000);
            Novarr.showToast('Live tail on — showing the latest 100 entries, refreshed every 3s.', 'info');
        } else {
            clearInterval(liveTimer);
            location.reload();
        }
    });

    document.getElementById('clearLog').addEventListener('click', async () => {
        if (!await Novarr.confirmDialog('Clear {{ $filename }}? The file is kept but all entries are removed.', { title: 'Clear log', confirmText: 'Clear', danger: true })) return;

        try {
            const response = await fetch('{{ route('logs.clear', $filename) }}', {
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
                Novarr.showToast(data.message || 'Failed to clear log.', 'danger');
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    });

})();
</script>
@endpush
