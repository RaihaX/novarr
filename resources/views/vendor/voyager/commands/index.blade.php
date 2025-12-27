@extends('voyager::master')

@section('page_title', 'Commands')

@section('page_header')
    <h1 class="page-title">
        <i class="voyager-terminal"></i>
        Console Commands
    </h1>
@stop

@section('content')
    <div class="page-content container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">Available Commands</h3>
                    </div>
                    <div class="panel-body">
                        <div class="row">
                            @foreach($commands as $key => $command)
                                <div class="col-md-4 col-sm-6">
                                    <div class="panel panel-default command-panel">
                                        <div class="panel-heading">
                                            <h4>
                                                <i class="{{ $command['icon'] }}"></i>
                                                {{ $command['name'] }}
                                            </h4>
                                        </div>
                                        <div class="panel-body">
                                            <p>{{ $command['description'] }}</p>
                                            <p class="text-muted"><code>php artisan {{ $command['command'] }}</code></p>
                                        </div>
                                        <div class="panel-footer">
                                            <a href="{{ route('voyager.commands.form', $key) }}" class="btn btn-primary btn-sm">
                                                <i class="voyager-play"></i> Run Command
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop

@section('css')
    <style>
        .command-panel {
            min-height: 200px;
            margin-bottom: 20px;
        }
        .command-panel .panel-heading h4 {
            margin: 0;
            font-size: 16px;
        }
        .command-panel .panel-heading h4 i {
            margin-right: 10px;
        }
        .command-panel .panel-body {
            min-height: 80px;
        }
        .command-panel .panel-body p {
            margin-bottom: 10px;
        }
        .command-panel .panel-footer {
            background: #f5f5f5;
        }
    </style>
@stop
