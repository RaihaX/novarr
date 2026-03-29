@extends('layouts.app')

@section('content')
<div class="mb-3 d-flex justify-content-between align-items-center">
    <div>
        <a href="{{ route('logs.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Back to Logs</a>
        <span class="ms-2 fw-bold">{{ $filename }}</span>
    </div>
    <a href="{{ route('logs.download', $filename) }}" class="btn btn-outline-primary btn-sm">Download</a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('logs.show', $filename) }}" class="row g-2 align-items-center">
            <div class="col-auto">
                <select name="level" class="form-select form-select-sm">
                    @foreach($levels as $lvl)
                        <option value="{{ $lvl }}" @selected($level === $lvl)>{{ ucfirst($lvl) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search..." value="{{ $search }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <a href="{{ route('logs.show', $filename) }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
            <div class="col-auto">
                <small class="text-muted">{{ $totalEntries }} entries | Page {{ $currentPage }}/{{ $totalPages }}</small>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-striped mb-0" style="font-size: 13px;">
            <thead>
                <tr>
                    <th style="width: 180px;">Timestamp</th>
                    <th style="width: 80px;">Level</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                @forelse($entries as $entry)
                    <tr>
                        <td class="text-nowrap"><code>{{ $entry['timestamp'] }}</code></td>
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
                        <td><pre class="mb-0" style="white-space: pre-wrap; font-size: 12px;">{{ Str::limit($entry['message'], 500) }}</pre></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-muted py-3">No log entries found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($totalPages > 1)
        <div class="card-footer">
            <nav>
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
