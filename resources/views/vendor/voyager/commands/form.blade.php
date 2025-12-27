@extends('voyager::master')

@section('page_title', $config['name'])

@section('page_header')
    <h1 class="page-title">
        <i class="{{ $config['icon'] }}"></i>
        {{ $config['name'] }}
    </h1>
    <a href="{{ route('voyager.commands.index') }}" class="btn btn-warning">
        <i class="voyager-angle-left"></i> Back to Commands
    </a>
@stop

@section('content')
    <div class="page-content container-fluid">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="{{ $config['icon'] }}"></i>
                            Run: {{ $config['name'] }}
                        </h3>
                    </div>
                    <div class="panel-body">
                        <p class="text-muted">{{ $config['description'] }}</p>

                        @if(isset($isDestructive) && $isDestructive)
                            <div class="alert alert-warning">
                                <i class="voyager-warning"></i>
                                <strong>Warning:</strong> This is a destructive command that may modify or delete data.
                                Please make sure you have selected the correct novel before proceeding.
                            </div>
                        @endif
                        <hr>

                        <form id="command-form">
                            @csrf
                            <input type="hidden" name="command" value="{{ $command }}">

                            @if(in_array('novel_id', $config['params']))
                                <div class="form-group">
                                    <label for="novel_id">Select Novel</label>
                                    <select name="novel_id" id="novel_id" class="form-control select2">
                                        <option value="0">All Novels</option>
                                        @foreach($novels as $novel)
                                            <option value="{{ $novel->id }}">{{ $novel->name }}</option>
                                        @endforeach
                                    </select>
                                    <p class="help-block">Select a specific novel or choose "All Novels" to process all.</p>
                                </div>
                            @endif

                            @if(in_array('name', $config['params']))
                                <div class="form-group">
                                    <label for="name">Novel Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" id="name" class="form-control" required placeholder="Enter novel name">
                                    <p class="help-block">Enter the name for the new novel.</p>
                                </div>
                            @endif

                            @if(in_array('url', $config['params']))
                                <div class="form-group">
                                    <label for="url">Novel URL <span class="text-danger">*</span></label>
                                    <input type="url" name="url" id="url" class="form-control" required placeholder="https://example.com/novel">
                                    <p class="help-block">Enter the translator URL for the novel.</p>
                                </div>
                            @endif

                            @if(in_array('dry_run', $config['params']))
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="dry_run" id="dry_run" value="1">
                                        Dry Run (preview changes without applying)
                                    </label>
                                    <p class="help-block">When enabled, shows what changes would be made without actually making them.</p>
                                </div>
                            @endif

                            <div class="form-group">
                                <button type="submit" class="btn btn-primary" id="execute-btn">
                                    <i class="voyager-play"></i> Execute Command
                                </button>
                                <button type="button" class="btn btn-info" id="execute-async-btn">
                                    <i class="voyager-refresh"></i> Run in Background
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="panel panel-bordered" id="output-panel" style="display: none;">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="voyager-terminal"></i>
                            Command Output
                        </h3>
                    </div>
                    <div class="panel-body">
                        <div id="status-message" class="alert" style="display: none;"></div>
                        <pre id="command-output" class="command-output"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop

@section('css')
    <style>
        .command-output {
            background: #1a1a1a;
            color: #00ff00;
            padding: 15px;
            border-radius: 4px;
            max-height: 500px;
            overflow-y: auto;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 13px;
            line-height: 1.5;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .panel-bordered {
            margin-bottom: 20px;
        }
    </style>
@stop

@section('javascript')
    <script>
        $(document).ready(function() {
            $('.select2').select2();

            var $form = $('#command-form');
            var $outputPanel = $('#output-panel');
            var $commandOutput = $('#command-output');
            var $statusMessage = $('#status-message');
            var $executeBtn = $('#execute-btn');
            var $executeAsyncBtn = $('#execute-async-btn');
            var isDestructive = {{ isset($isDestructive) && $isDestructive ? 'true' : 'false' }};

            function confirmDestructive(callback) {
                if (!isDestructive) {
                    callback();
                    return;
                }

                if (confirm('This is a destructive command. Are you sure you want to proceed?\n\nThis action may modify or delete data and cannot be undone.')) {
                    callback();
                }
            }

            function showLoading(button) {
                button.prop('disabled', true);
                button.data('original-html', button.html());
                button.html('<span class="loading-spinner"></span> Running...');
            }

            function hideLoading(button) {
                button.prop('disabled', false);
                button.html(button.data('original-html'));
            }

            function showOutput(success, message, output) {
                $outputPanel.show();
                $statusMessage.removeClass('alert-success alert-danger alert-info');

                if (success) {
                    $statusMessage.addClass('alert-success').text(message).show();
                } else {
                    $statusMessage.addClass('alert-danger').text(message).show();
                }

                $commandOutput.text(output || 'No output');

                $('html, body').animate({
                    scrollTop: $outputPanel.offset().top - 100
                }, 300);
            }

            $form.on('submit', function(e) {
                e.preventDefault();

                confirmDestructive(function() {
                    showLoading($executeBtn);
                    $commandOutput.text('Executing command...');
                    $outputPanel.show();
                    $statusMessage.removeClass('alert-success alert-danger').addClass('alert-info').text('Command is running...').show();

                    $.ajax({
                        url: '{{ route("voyager.commands.execute") }}',
                        method: 'POST',
                        data: $form.serialize(),
                        success: function(response) {
                            showOutput(response.success, response.message, response.output);
                        },
                        error: function(xhr) {
                            var message = 'An error occurred';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                message = xhr.responseJSON.message;
                            }
                            showOutput(false, message, xhr.responseJSON?.output || '');
                        },
                        complete: function() {
                            hideLoading($executeBtn);
                        }
                    });
                });
            });

            $executeAsyncBtn.on('click', function() {
                confirmDestructive(function() {
                    showLoading($executeAsyncBtn);

                    $.ajax({
                        url: '{{ route("voyager.commands.execute-async") }}',
                        method: 'POST',
                        data: $form.serialize(),
                        success: function(response) {
                            if (response.success) {
                                $outputPanel.show();
                                $statusMessage.removeClass('alert-success alert-danger').addClass('alert-info')
                                    .html('<i class="voyager-refresh"></i> Command queued. Job ID: <code>' + response.job_id + '</code><br>Check back later for results.')
                                    .show();
                                $commandOutput.text('Command is running in background...\nJob ID: ' + response.job_id);

                                pollStatus(response.job_id);
                            } else {
                                showOutput(false, response.message, '');
                            }
                        },
                        error: function(xhr) {
                            var message = 'An error occurred';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                message = xhr.responseJSON.message;
                            }
                            showOutput(false, message, '');
                        },
                        complete: function() {
                            hideLoading($executeAsyncBtn);
                        }
                    });
                });
            });

            function pollStatus(jobId) {
                var pollInterval = setInterval(function() {
                    $.ajax({
                        url: '{{ url("admin/commands/status") }}/' + jobId,
                        method: 'GET',
                        success: function(response) {
                            if (response.status === 'completed') {
                                clearInterval(pollInterval);
                                showOutput(
                                    response.result.success,
                                    response.result.success ? 'Background command completed successfully' : 'Background command failed',
                                    response.result.output || response.result.error || ''
                                );
                            }
                        },
                        error: function() {
                            clearInterval(pollInterval);
                            showOutput(false, 'Error checking command status', '');
                        }
                    });
                }, 3000);
            }
        });
    </script>
@stop
