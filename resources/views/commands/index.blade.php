@extends('layouts.app')

@section('content')
<h1 class="mb-4">Commands</h1>

@php
    $categories = [
        'Scraping' => ['toc', 'chapter', 'create_novel'],
        'Generation' => ['epub', 'metadata', 'normalize_labels'],
        'Maintenance' => ['calculate_chapter', 'info', 'chapter_cleanser', 'chapter_cleaner', 'queue_health'],
    ];
@endphp

@foreach($categories as $category => $keys)
    <h4 class="mt-4 mb-3">{{ $category }}</h4>
    <div class="row">
        @foreach($keys as $key)
            @if(isset($commands[$key]))
                @php $cmd = $commands[$key]; @endphp
                <div class="col-md-4 mb-3">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">{{ $cmd['name'] }}</h5>
                            <p class="card-text text-muted flex-grow-1">{{ $cmd['description'] }}</p>
                            <code class="d-block mb-3 small">{{ $cmd['command'] }}</code>
                            <a href="{{ route('commands.form', $key) }}" class="btn btn-primary btn-sm mt-auto">Run</a>
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endforeach
@endsection
