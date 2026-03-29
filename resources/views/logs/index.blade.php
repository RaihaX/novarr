@extends('layouts.app')

@section('content')
<h1 class="mb-4">Log Files</h1>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead class="table-dark">
                <tr>
                    <th>File Name</th>
                    <th>Size</th>
                    <th>Last Modified</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logFiles as $file)
                    <tr>
                        <td><code>{{ $file['name'] }}</code></td>
                        <td>{{ $file['size'] }}</td>
                        <td>{{ $file['modified'] }}</td>
                        <td>
                            <a href="{{ route('logs.show', $file['name']) }}" class="btn btn-sm btn-outline-primary">View</a>
                            <a href="{{ route('logs.download', $file['name']) }}" class="btn btn-sm btn-outline-secondary">Download</a>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteLog('{{ $file['name'] }}')">Delete</button>
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
    function deleteLog(filename) {
        if (!confirm('Delete ' + filename + '?')) return;

        fetch('/logs/' + filename, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(err => alert('Error: ' + err.message));
    }
</script>
@endpush
