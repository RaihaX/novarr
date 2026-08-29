@extends('layouts.app')

@section('content')
<h1 class="page-title mb-4">Commands</h1>

@php
    $categories = [
        'Scraping' => ['keys' => ['toc', 'chapter', 'create_novel']],
        'Generation' => ['keys' => ['epub', 'metadata', 'normalize_labels']],
        'Maintenance' => ['keys' => ['info', 'clean_content', 'chapter_cleaner', 'queue_health']],
    ];
@endphp

@foreach($categories as $category => $config)
    <div class="section-rule">
        <span class="section-rule-label">{{ $category }}</span>
    </div>
    <div class="row g-3">
        @foreach($config['keys'] as $key)
            @if(isset($commands[$key]))
                @php $cmd = $commands[$key]; @endphp
                <div class="col-md-6 col-xl-4">
                    <div class="command-card">
                        <h2 class="command-name">{{ $cmd['name'] }}</h2>
                        <p class="command-desc">{{ $cmd['description'] }}</p>
                        <div class="command-foot">
                            <span class="command-slug" title="{{ $cmd['command'] }}">{{ $cmd['command'] }}</span>
                            <a href="{{ route('commands.form', $key) }}" class="btn btn-primary">Run</a>
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endforeach
@endsection
