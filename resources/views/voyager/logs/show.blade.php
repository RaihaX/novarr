@extends('voyager::master')

@section('page_title', 'Log: ' . $filename)

@section('page_header')
    <div class="container-fluid">
        <h1 class="page-title">
            <i class="voyager-file-text"></i> Log: {{ $filename }}
        </h1>
        <a href="{{ route('voyager.logs.index') }}" class="btn btn-default">
            <i class="voyager-angle-left"></i> Back to Logs
        </a>
        <a href="{{ route('voyager.logs.download', $filename) }}" class="btn btn-primary">
            <i class="voyager-download"></i> Download
        </a>
        <button type="button" class="btn btn-info" id="tail-btn">
            <i class="voyager-refresh"></i> <span id="tail-text">Start Tail</span>
        </button>
    </div>
@stop

@section('content')
    <div class="page-content browse container-fluid">
        @include('voyager::alerts')

        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            Log Entries ({{ $totalEntries }} total)
                        </h3>
                        <div class="panel-actions">
                            <form method="GET" action="{{ route('voyager.logs.show', $filename) }}" class="form-inline log-filter-form">
                                <div class="form-group">
                                    <label for="level">Level:</label>
                                    <select name="level" id="level" class="form-control">
                                        @foreach($levels as $lvl)
                                            <option value="{{ $lvl }}" {{ $level === $lvl ? 'selected' : '' }}>
                                                {{ ucfirst($lvl) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="search">Search:</label>
                                    <input type="text" name="search" id="search" class="form-control"
                                           value="{{ $search }}" placeholder="Search logs...">
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="voyager-search"></i> Filter
                                </button>
                                @if($level !== 'all' || $search)
                                    <a href="{{ route('voyager.logs.show', $filename) }}" class="btn btn-default btn-sm">
                                        <i class="voyager-x"></i> Clear
                                    </a>
                                @endif
                            </form>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="log-content-container" id="log-container">
                            @if(count($entries) > 0)
                                @foreach($entries as $entry)
                                    <div class="log-entry log-level-{{ $entry['level'] }}">
                                        @if($entry['timestamp'])
                                            <span class="log-timestamp">[{{ $entry['timestamp'] }}]</span>
                                        @endif
                                        @if($entry['level'])
                                            <span class="log-level-badge log-level-{{ $entry['level'] }}">{{ strtoupper($entry['level']) }}</span>
                                        @endif
                                        <pre class="log-message">{{ $entry['message'] }}</pre>
                                    </div>
                                @endforeach
                            @else
                                <div class="alert alert-info">
                                    <i class="voyager-info-circled"></i> No log entries found matching your criteria.
                                </div>
                            @endif
                        </div>

                        {{-- Pagination --}}
                        @if($totalPages > 1)
                            <div class="log-pagination">
                                <nav>
                                    <ul class="pagination">
                                        @if($currentPage > 1)
                                            <li>
                                                <a href="{{ route('voyager.logs.show', $filename) }}?page=1&level={{ $level }}&search={{ urlencode($search) }}">
                                                    <i class="voyager-angle-double-left"></i>
                                                </a>
                                            </li>
                                            <li>
                                                <a href="{{ route('voyager.logs.show', $filename) }}?page={{ $currentPage - 1 }}&level={{ $level }}&search={{ urlencode($search) }}">
                                                    <i class="voyager-angle-left"></i>
                                                </a>
                                            </li>
                                        @endif

                                        @for($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++)
                                            <li class="{{ $i === $currentPage ? 'active' : '' }}">
                                                <a href="{{ route('voyager.logs.show', $filename) }}?page={{ $i }}&level={{ $level }}&search={{ urlencode($search) }}">
                                                    {{ $i }}
                                                </a>
                                            </li>
                                        @endfor

                                        @if($currentPage < $totalPages)
                                            <li>
                                                <a href="{{ route('voyager.logs.show', $filename) }}?page={{ $currentPage + 1 }}&level={{ $level }}&search={{ urlencode($search) }}">
                                                    <i class="voyager-angle-right"></i>
                                                </a>
                                            </li>
                                            <li>
                                                <a href="{{ route('voyager.logs.show', $filename) }}?page={{ $totalPages }}&level={{ $level }}&search={{ urlencode($search) }}">
                                                    <i class="voyager-angle-double-right"></i>
                                                </a>
                                            </li>
                                        @endif
                                    </ul>
                                </nav>
                                <p class="text-muted">
                                    Page {{ $currentPage }} of {{ $totalPages }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop

@section('css')
    <style>
        .log-filter-form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            padding: 10px 0;
        }

        .log-filter-form .form-group {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 0;
        }

        .log-content-container {
            overflow-y: auto;
            max-height: 70vh;
            background: #1e1e1e;
            padding: 15px;
            border-radius: 4px;
            font-family: 'Courier New', Consolas, monospace;
            font-size: 12px;
        }

        .log-entry {
            padding: 8px 10px;
            border-bottom: 1px solid #333;
            margin-bottom: 5px;
        }

        .log-entry:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .log-timestamp {
            color: #888;
            font-size: 11px;
        }

        .log-level-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            margin: 0 8px;
        }

        .log-level-emergency, .log-level-alert, .log-level-critical {
            background-color: #dc3545;
            color: #fff;
        }

        .log-level-error {
            background-color: #e74c3c;
            color: #fff;
        }

        .log-level-warning {
            background-color: #f39c12;
            color: #fff;
        }

        .log-level-notice {
            background-color: #3498db;
            color: #fff;
        }

        .log-level-info {
            background-color: #17a2b8;
            color: #fff;
        }

        .log-level-debug {
            background-color: #6c757d;
            color: #fff;
        }

        .log-message {
            color: #d4d4d4;
            margin: 5px 0 0 0;
            padding: 0;
            background: transparent;
            border: none;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: inherit;
            font-size: inherit;
        }

        .log-entry.log-level-error .log-message,
        .log-entry.log-level-emergency .log-message,
        .log-entry.log-level-alert .log-message,
        .log-entry.log-level-critical .log-message {
            color: #ff6b6b;
        }

        .log-entry.log-level-warning .log-message {
            color: #ffd93d;
        }

        .log-entry.log-level-info .log-message {
            color: #6bcf63;
        }

        .log-entry.log-level-debug .log-message {
            color: #888;
        }

        .log-pagination {
            margin-top: 20px;
            text-align: center;
        }

        .log-pagination .pagination {
            margin: 10px 0;
        }

        .panel-actions {
            padding: 10px 15px;
            border-bottom: 1px solid #e3e3e3;
        }

        #tail-btn.tailing {
            background-color: #e74c3c;
            border-color: #c0392b;
        }
    </style>
@stop

@section('javascript')
    <script>
        $(document).ready(function() {
            var isTailing = false;
            var tailInterval = null;
            var logContainer = $('#log-container');

            $('#tail-btn').on('click', function() {
                if (isTailing) {
                    stopTail();
                } else {
                    startTail();
                }
            });

            function startTail() {
                isTailing = true;
                $('#tail-btn').addClass('tailing');
                $('#tail-text').text('Stop Tail');

                fetchTail();
                tailInterval = setInterval(fetchTail, 5000);
            }

            function stopTail() {
                isTailing = false;
                $('#tail-btn').removeClass('tailing');
                $('#tail-text').text('Start Tail');

                if (tailInterval) {
                    clearInterval(tailInterval);
                    tailInterval = null;
                }
            }

            function fetchTail() {
                $.ajax({
                    url: '{{ route("voyager.logs.tail", $filename) }}',
                    type: 'GET',
                    success: function(response) {
                        if (response.success && response.entries) {
                            renderEntries(response.entries);
                            scrollToBottom();
                        }
                    },
                    error: function() {
                        toastr.error('Failed to fetch log updates');
                        stopTail();
                    }
                });
            }

            function renderEntries(entries) {
                var html = '';
                entries.forEach(function(entry) {
                    html += '<div class="log-entry log-level-' + entry.level + '">';
                    if (entry.timestamp) {
                        html += '<span class="log-timestamp">[' + entry.timestamp + ']</span>';
                    }
                    if (entry.level) {
                        html += '<span class="log-level-badge log-level-' + entry.level + '">' + entry.level.toUpperCase() + '</span>';
                    }
                    html += '<pre class="log-message">' + escapeHtml(entry.message) + '</pre>';
                    html += '</div>';
                });
                logContainer.html(html);
            }

            function scrollToBottom() {
                logContainer.scrollTop(logContainer[0].scrollHeight);
            }

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Auto-submit form on level change
            $('#level').on('change', function() {
                $(this).closest('form').submit();
            });
        });
    </script>
@stop
