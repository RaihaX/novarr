@extends('layouts.app')

@section('content')
<h1 class="mb-4">Dashboard</h1>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><span class="badge bg-danger me-2">{{ $missing_chapters->total() }}</span> Missing Chapters</h5>
            </div>
            <div class="table-responsive">
                @if($missing_chapters->count() > 0)
                    <table class="table table-striped table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Novel</th>
                                <th>Ch.</th>
                                <th>Label</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($missing_chapters as $chapter)
                                <tr>
                                    <td>
                                        @if($chapter->novel)
                                            <a href="{{ route('novels.show', $chapter->novel_id) }}">{{ $chapter->novel->name }}</a>
                                        @else
                                            Unknown
                                        @endif
                                    </td>
                                    <td>{{ $chapter->chapter }}</td>
                                    <td class="text-truncate" style="max-width: 200px;">{{ $chapter->label }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="card-body">
                        <p class="text-muted mb-0">No missing chapters!</p>
                    </div>
                @endif
            </div>
            @if($missing_chapters->hasPages())
                <div class="card-footer">
                    {{ $missing_chapters->links() }}
                </div>
            @endif
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><span class="badge bg-success me-2">{{ $latest_chapters->total() }}</span> Latest Chapters</h5>
            </div>
            <div class="table-responsive">
                @if($latest_chapters->count() > 0)
                    <table class="table table-striped table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Novel</th>
                                <th>Ch.</th>
                                <th>Label</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($latest_chapters as $chapter)
                                <tr>
                                    <td>
                                        @if($chapter->novel)
                                            <a href="{{ route('novels.show', $chapter->novel_id) }}">{{ $chapter->novel->name }}</a>
                                        @else
                                            Unknown
                                        @endif
                                    </td>
                                    <td>{{ $chapter->chapter }}</td>
                                    <td class="text-truncate" style="max-width: 200px;">{{ $chapter->label }}</td>
                                    <td class="text-nowrap">{{ $chapter->created_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="card-body">
                        <p class="text-muted mb-0">No chapters yet.</p>
                    </div>
                @endif
            </div>
            @if($latest_chapters->hasPages())
                <div class="card-footer">
                    {{ $latest_chapters->links() }}
                </div>
            @endif
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Quick Links</h5>
            </div>
            <div class="card-body">
                <a href="{{ route('novels.index') }}" class="btn btn-primary me-2">Novels</a>
                <a href="{{ route('commands.index') }}" class="btn btn-success me-2">Commands</a>
                <a href="{{ route('logs.index') }}" class="btn btn-info">Logs</a>
            </div>
        </div>
    </div>
</div>
@endsection
