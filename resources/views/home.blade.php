@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-4">Dashboard</h1>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">Missing Chapters</h5>
            </div>
            <div class="card-body">
                @if($missing_chapters->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>Novel</th>
                                    <th>Chapter</th>
                                    <th>Label</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($missing_chapters as $chapter)
                                    <tr>
                                        <td>
                                            @if($chapter->novel)
                                                <a href="{{ route('novels.show', $chapter->novel_id) }}">
                                                    {{ $chapter->novel->name }}
                                                </a>
                                            @else
                                                Unknown
                                            @endif
                                        </td>
                                        <td>{{ $chapter->chapter }}</td>
                                        <td>{{ $chapter->label }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $missing_chapters->links() }}
                @else
                    <p class="text-muted mb-0">No missing chapters!</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Latest Chapters</h5>
            </div>
            <div class="card-body">
                @if($latest_chapters->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>Novel</th>
                                    <th>Chapter</th>
                                    <th>Label</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($latest_chapters as $chapter)
                                    <tr>
                                        <td>
                                            @if($chapter->novel)
                                                <a href="{{ route('novels.show', $chapter->novel_id) }}">
                                                    {{ $chapter->novel->name }}
                                                </a>
                                            @else
                                                Unknown
                                            @endif
                                        </td>
                                        <td>{{ $chapter->chapter }}</td>
                                        <td>{{ $chapter->label }}</td>
                                        <td>{{ $chapter->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $latest_chapters->links() }}
                @else
                    <p class="text-muted mb-0">No chapters yet.</p>
                @endif
            </div>
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
                <a href="{{ route('novels.index') }}" class="btn btn-primary me-2">View All Novels</a>
                <a href="{{ url('/admin') }}" class="btn btn-secondary">Admin Panel</a>
            </div>
        </div>
    </div>
</div>
@endsection
