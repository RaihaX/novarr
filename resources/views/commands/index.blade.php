@extends('layouts.app')

@section('content')
<h1 class="mb-4">Commands</h1>

@php
    $categories = [
        'Scraping' => ['badge' => 'primary', 'keys' => ['toc', 'chapter', 'create_novel']],
        'Generation' => ['badge' => 'success', 'keys' => ['epub', 'metadata', 'normalize_labels']],
        'Maintenance' => ['badge' => 'warning', 'keys' => ['calculate_chapter', 'info', 'chapter_cleanser', 'chapter_cleaner', 'queue_health']],
    ];
@endphp

@foreach($categories as $category => $config)
    <div class="d-flex align-items-center mb-3 mt-4">
        <span class="badge bg-{{ $config['badge'] }} me-2">{{ $category }}</span>
        <hr class="flex-grow-1 m-0" style="border-color: rgba(255,255,255,0.1);">
    </div>
    <div class="row">
        @foreach($config['keys'] as $key)
            @if(isset($commands[$key]))
                @php $cmd = $commands[$key]; @endphp
                <div class="col-md-4 mb-3">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h6 class="card-title mb-1">{{ $cmd['name'] }}</h6>
                            <p class="card-text text-muted small flex-grow-1 mb-2">{{ $cmd['description'] }}</p>
                            <div class="d-flex justify-content-between align-items-center">
                                <code class="small">{{ $cmd['command'] }}</code>
                                <a href="{{ route('commands.form', $key) }}" class="btn btn-primary btn-sm">Run</a>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endforeach
@endsection
