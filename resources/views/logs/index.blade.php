@extends('layouts.app')

@section('title', 'Logs')
@section('breadcrumb')
    @include('partials.breadcrumb', ['trail' => [['System'], ['Logs']]])
@endsection

@section('content')
<h1 class="page-title mb-4">Log files</h1>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle table-responsive-cards">
            <caption class="visually-hidden">Log files</caption>
            <thead>
                <tr>
                    <th>File name</th>
                    <th style="width: 110px;">Size</th>
                    <th style="width: 190px;">Last modified</th>
                    <th style="width: 300px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logFiles as $file)
                    <tr>
                        <td class="cell-primary"><span class="log-name">{{ $file['name'] }}</span></td>
                        <td class="mono-muted" data-label="Size">{{ $file['size'] }}</td>
                        <td class="mono-muted" data-label="Modified">{{ $file['modified'] }}</td>
                        <td class="cell-actions">
                            <div class="job-actions justify-content-start">
                                <a href="{{ route('logs.show', $file['name']) }}" class="btn btn-secondary">View</a>
                                <a href="{{ route('logs.download', $file['name']) }}" class="btn btn-secondary" data-turbo="false" data-turbo-prefetch="false">Download</a>
                                <button type="button" class="btn btn-warning log-clear-btn" data-filename="{{ $file['name'] }}" aria-label="Clear {{ $file['name'] }}">Clear</button>
                                <button type="button" class="btn btn-danger log-delete-btn" data-filename="{{ $file['name'] }}" aria-label="Delete {{ $file['name'] }}">Delete</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No log files found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function(){

    document.querySelectorAll('.log-clear-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const filename = btn.dataset.filename;

            if (!await Novarr.confirmDialog('Clear ' + filename + '? The file is kept but all entries are removed.', { title: 'Clear log', confirmText: 'Clear', danger: true })) return;

            try {
                const response = await fetch('/logs/' + encodeURIComponent(filename) + '/clear', {
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
    });

    document.querySelectorAll('.log-delete-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const filename = btn.dataset.filename;

            if (!await Novarr.confirmDialog('Delete ' + filename + '?', { title: 'Delete log file', confirmText: 'Delete', danger: true })) return;

            try {
                const response = await fetch('/logs/' + encodeURIComponent(filename), {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();

                if (data.success) {
                    location.reload();
                } else {
                    Novarr.showToast(data.message || 'Failed to delete log.', 'danger');
                }
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    });

})();
</script>
@endpush
