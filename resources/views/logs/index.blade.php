@extends('layouts.app')

@section('content')
<h1 class="page-title mb-4">Log files</h1>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
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
                        <td><span class="log-name">{{ $file['name'] }}</span></td>
                        <td class="mono-muted">{{ $file['size'] }}</td>
                        <td class="mono-muted">{{ $file['modified'] }}</td>
                        <td>
                            <div class="job-actions justify-content-start">
                                <a href="{{ route('logs.show', $file['name']) }}" class="btn btn-secondary">View</a>
                                <a href="{{ route('logs.download', $file['name']) }}" class="btn btn-secondary">Download</a>
                                <button class="btn btn-warning log-clear-btn" data-filename="{{ $file['name'] }}">Clear</button>
                                <button class="btn btn-danger log-delete-btn" data-filename="{{ $file['name'] }}">Delete</button>
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
