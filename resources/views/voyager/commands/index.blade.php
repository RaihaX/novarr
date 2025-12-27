@extends('voyager::master')

@section('page_title', 'Commands')

@section('page_header')
    <div class="container-fluid">
        <h1 class="page-title">
            <i class="voyager-terminal"></i> Commands
        </h1>
        <p class="lead">Execute artisan commands for novel management</p>
    </div>
@stop

@section('content')
    <div class="page-content browse container-fluid">
        @include('voyager::alerts')

        {{-- Scraping Commands --}}
        <div class="row">
            <div class="col-md-12">
                <h4 class="section-title"><i class="voyager-download"></i> Scraping Commands</h4>
            </div>
        </div>
        <div class="command-cards">
            @foreach(['toc', 'chapter'] as $key)
                @if(isset($commands[$key]))
                    @php $cmd = $commands[$key]; @endphp
                    <div class="command-card">
                        <span class="command-category scraping">Scraping</span>
                        <div class="command-card-icon">
                            <i class="{{ $cmd['icon'] }}"></i>
                        </div>
                        <h5 class="command-card-title">{{ $cmd['name'] }}</h5>
                        <p class="command-card-description">{{ $cmd['description'] }}</p>
                        <div class="command-card-actions">
                            <a href="{{ route('voyager.commands.form', $key) }}" class="btn btn-primary btn-sm">
                                <i class="voyager-play"></i> Run
                            </a>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>

        {{-- Generation Commands --}}
        <div class="row" style="margin-top: 30px;">
            <div class="col-md-12">
                <h4 class="section-title"><i class="voyager-book"></i> Generation Commands</h4>
            </div>
        </div>
        <div class="command-cards">
            @foreach(['epub', 'create_novel'] as $key)
                @if(isset($commands[$key]))
                    @php $cmd = $commands[$key]; @endphp
                    <div class="command-card">
                        <span class="command-category generation">Generation</span>
                        <div class="command-card-icon">
                            <i class="{{ $cmd['icon'] }}"></i>
                        </div>
                        <h5 class="command-card-title">{{ $cmd['name'] }}</h5>
                        <p class="command-card-description">{{ $cmd['description'] }}</p>
                        <div class="command-card-actions">
                            <a href="{{ route('voyager.commands.form', $key) }}" class="btn btn-primary btn-sm">
                                <i class="voyager-play"></i> Run
                            </a>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>

        {{-- Maintenance Commands --}}
        <div class="row" style="margin-top: 30px;">
            <div class="col-md-12">
                <h4 class="section-title"><i class="voyager-tools"></i> Maintenance Commands</h4>
            </div>
        </div>
        <div class="command-cards">
            @foreach(['metadata', 'normalize_labels', 'calculate_chapter', 'info', 'chapter_cleanser', 'chapter_cleaner', 'queue_health'] as $key)
                @if(isset($commands[$key]))
                    @php $cmd = $commands[$key]; @endphp
                    <div class="command-card">
                        <span class="command-category maintenance">Maintenance</span>
                        <div class="command-card-icon">
                            <i class="{{ $cmd['icon'] }}"></i>
                        </div>
                        <h5 class="command-card-title">{{ $cmd['name'] }}</h5>
                        <p class="command-card-description">{{ $cmd['description'] }}</p>
                        <div class="command-card-actions">
                            <a href="{{ route('voyager.commands.form', $key) }}" class="btn btn-primary btn-sm">
                                <i class="voyager-play"></i> Run
                            </a>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
@stop

@section('css')
    <style>
        .section-title {
            color: #333;
            font-weight: 600;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e3e3e3;
        }

        .section-title i {
            margin-right: 8px;
            color: #22A7F0;
        }
    </style>
@stop
