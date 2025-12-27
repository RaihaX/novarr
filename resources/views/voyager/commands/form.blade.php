@extends('voyager::master')

@section('page_title', $config['name'])

@section('page_header')
    <div class="container-fluid">
        <h1 class="page-title">
            <i class="{{ $config['icon'] }}"></i> {{ $config['name'] }}
        </h1>
        <a href="{{ route('voyager.commands.index') }}" class="btn btn-default">
            <i class="voyager-angle-left"></i> Back to Commands
        </a>
    </div>
@stop

@section('content')
    <div class="page-content browse container-fluid">
        @include('voyager::alerts')

        <div class="row">
            <div class="col-md-6">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="voyager-params"></i> Command Parameters
                        </h3>
                    </div>
                    <div class="panel-body">
                        @if($isDestructive)
                            <div class="destructive-warning">
                                <i class="voyager-warning"></i>
                                <strong>Warning:</strong> This command modifies or deletes data. Please review the parameters carefully before executing.
                            </div>
                        @endif

                        <p class="text-muted">{{ $config['description'] }}</p>

                        <form id="command-form">
                            @csrf
                            <input type="hidden" name="command" value="{{ $command }}">

                            @if(in_array('novel_id', $config['params']))
                                <div class="form-group">
                                    <label for="novel_id">Select Novel</label>
                                    <select name="novel_id" id="novel_id" class="form-control select2">
                                        <option value="0">-- All Novels --</option>
                                        @foreach($novels as $novel)
                                            <option value="{{ $novel->id }}">{{ $novel->name }}</option>
                                        @endforeach
                                    </select>
                                    <p class="help-block">Select a specific novel or leave blank to process all novels.</p>
                                </div>
                            @endif

                            @if(in_array('name', $config['params']))
                                <div class="form-group">
                                    <label for="name">Novel Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" id="name" class="form-control" required
                                           placeholder="Enter novel name">
                                    <p class="help-block">The name of the novel to create.</p>
                                </div>
                            @endif

                            @if(in_array('url', $config['params']))
                                <div class="form-group">
                                    <label for="url">Source URL <span class="text-danger">*</span></label>
                                    <input type="url" name="url" id="url" class="form-control" required
                                           placeholder="https://example.com/novel/...">
                                    <p class="help-block">The URL to scrape novel information from.</p>
                                </div>
                            @endif

                            @if(in_array('dry_run', $config['params']))
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="dry_run" id="dry_run" value="1">
                                        Dry Run (Preview changes without applying)
                                    </label>
                                    <p class="help-block">Check this to see what changes would be made without actually applying them.</p>
                                </div>
                            @endif

                            <div class="form-group">
                                <button type="button" class="btn btn-primary" id="execute-sync">
                                    <i class="voyager-play"></i> Execute Now
                                </button>
                                <button type="button" class="btn btn-info" id="execute-async">
                                    <i class="voyager-controller-play"></i> Execute in Background
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="voyager-terminal"></i> Output
                        </h3>
                    </div>
                    <div class="panel-body">
                        <div id="execution-status" class="execution-status" style="display: none;">
                            <span class="loading-spinner"></span>
                            <span id="status-text">Running...</span>
                        </div>

                        <div class="command-output" id="command-output">
                            <span class="text-muted">Command output will appear here...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Confirmation Modal --}}
    @if($isDestructive)
        <div class="modal modal-warning fade" tabindex="-1" id="confirm-modal" role="dialog">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h4 class="modal-title">
                            <i class="voyager-warning"></i> Confirm Execution
                        </h4>
                    </div>
                    <div class="modal-body">
                        <p>You are about to run a destructive command that may modify or delete data.</p>
                        <p><strong>Command:</strong> {{ $config['name'] }}</p>
                        <p>Are you sure you want to continue?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-warning" id="confirm-execute">
                            <i class="voyager-play"></i> Execute
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@stop

@section('javascript')
    <script>
        $(document).ready(function() {
            var isDestructive = {{ $isDestructive ? 'true' : 'false' }};
            var pendingAction = null;

            // Initialize Select2
            $('.select2').select2({
                placeholder: 'Select a novel...',
                allowClear: true
            });

            // Execute Sync
            $('#execute-sync').on('click', function() {
                if (isDestructive) {
                    pendingAction = 'sync';
                    $('#confirm-modal').modal('show');
                } else {
                    executeCommand('sync');
                }
            });

            // Execute Async
            $('#execute-async').on('click', function() {
                if (isDestructive) {
                    pendingAction = 'async';
                    $('#confirm-modal').modal('show');
                } else {
                    executeCommand('async');
                }
            });

            // Confirm execution
            $('#confirm-execute').on('click', function() {
                $('#confirm-modal').modal('hide');
                if (pendingAction) {
                    executeCommand(pendingAction);
                    pendingAction = null;
                }
            });

            function executeCommand(mode) {
                var formData = $('#command-form').serialize();
                var url = mode === 'sync' ? '{{ route("voyager.commands.execute") }}' : '{{ route("voyager.commands.execute-async") }}';

                // Show running status
                showStatus('running', 'Running command...');
                disableButtons(true);
                $('#command-output').html('<span class="text-muted">Executing...</span>');

                $.ajax({
                    url: url,
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        if (mode === 'sync') {
                            handleSyncResponse(response);
                        } else {
                            handleAsyncResponse(response);
                        }
                    },
                    error: function(xhr) {
                        var message = xhr.responseJSON ? xhr.responseJSON.message : 'An error occurred';
                        showStatus('error', 'Error: ' + message);
                        $('#command-output').html('<span class="text-danger">' + escapeHtml(message) + '</span>');
                        disableButtons(false);
                    }
                });
            }

            function handleSyncResponse(response) {
                if (response.success) {
                    showStatus('success', 'Command completed successfully');
                    toastr.success(response.message);
                } else {
                    showStatus('error', 'Command failed');
                    toastr.error(response.message);
                }

                $('#command-output').html(escapeHtml(response.output || 'No output'));
                scrollOutputToBottom();
                disableButtons(false);
            }

            function handleAsyncResponse(response) {
                if (response.success) {
                    showStatus('running', 'Command queued. Checking status...');
                    toastr.info('Command queued for background execution');
                    pollJobStatus(response.job_id);
                } else {
                    showStatus('error', 'Failed to queue command');
                    toastr.error(response.message);
                    disableButtons(false);
                }
            }

            function pollJobStatus(jobId) {
                var pollInterval = setInterval(function() {
                    $.ajax({
                        url: '{{ url("admin/commands/status") }}/' + jobId,
                        type: 'GET',
                        success: function(response) {
                            if (response.status === 'completed') {
                                clearInterval(pollInterval);
                                var result = response.result;

                                if (result.success) {
                                    showStatus('success', 'Command completed successfully');
                                    toastr.success('Background command completed');
                                } else {
                                    showStatus('error', 'Command failed');
                                    toastr.error(result.error || 'Command failed');
                                }

                                $('#command-output').html(escapeHtml(result.output || result.error || 'No output'));
                                scrollOutputToBottom();
                                disableButtons(false);
                            }
                        },
                        error: function() {
                            clearInterval(pollInterval);
                            showStatus('error', 'Failed to check command status');
                            disableButtons(false);
                        }
                    });
                }, 2000);
            }

            function showStatus(type, text) {
                var statusDiv = $('#execution-status');
                statusDiv.removeClass('running success error').addClass(type).show();
                $('#status-text').text(text);

                if (type === 'running') {
                    statusDiv.find('.loading-spinner').show();
                } else {
                    statusDiv.find('.loading-spinner').hide();
                }
            }

            function disableButtons(disabled) {
                $('#execute-sync, #execute-async').prop('disabled', disabled);
            }

            function scrollOutputToBottom() {
                var output = $('#command-output');
                output.scrollTop(output[0].scrollHeight);
            }

            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        });
    </script>
@stop
