@extends('voyager::bread.read')

@section('css')
    @parent
    <style>
        .novel-cover-large {
            max-width: 200px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .novel-info-panel {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .novel-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .novel-author {
            color: #666;
            margin-bottom: 15px;
        }

        .novel-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin: 20px 0;
        }

        .novel-stat-item {
            background: #fff;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            text-align: center;
            min-width: 120px;
        }

        .novel-stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #22A7F0;
        }

        .novel-stat-label {
            font-size: 0.85rem;
            color: #666;
        }

        .chapter-progress-large {
            height: 12px;
            background: #e9ecef;
            border-radius: 6px;
            overflow: hidden;
            margin: 10px 0;
        }

        .chapter-progress-bar-large {
            height: 100%;
            background: linear-gradient(90deg, #22A7F0, #1abc9c);
            border-radius: 6px;
            transition: width 0.5s ease;
        }

        .chapters-table-container {
            max-height: 60vh;
            overflow-y: auto;
        }

        .quick-action-panel {
            background: #fff;
            border: 1px solid #e3e3e3;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .quick-action-panel h4 {
            margin-bottom: 15px;
            color: #333;
        }

        .quick-action-btn {
            margin-right: 10px;
            margin-bottom: 10px;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-completed {
            background: #cce5ff;
            color: #004085;
        }
    </style>
@stop

@section('content')
    <div class="page-content read container-fluid">
        @include('voyager::alerts')

        <div class="row">
            {{-- Novel Details Column --}}
            <div class="col-md-4">
                <div class="panel panel-bordered" style="padding-bottom:5px;">
                    <div class="panel-heading" style="border-bottom:0;">
                        <h3 class="panel-title">Novel Details</h3>
                    </div>
                    <div class="panel-body" style="padding-top:0;">
                        <div class="text-center">
                            @if($dataTypeContent->cover)
                                <img src="{{ Voyager::image($dataTypeContent->cover) }}"
                                     class="novel-cover-large"
                                     alt="{{ $dataTypeContent->name }}">
                            @else
                                <div class="novel-cover-large" style="background: #eee; height: 280px; display: flex; align-items: center; justify-content: center;">
                                    <i class="voyager-book" style="font-size: 4rem; color: #ccc;"></i>
                                </div>
                            @endif
                        </div>

                        <div class="novel-info-panel" style="margin-top: 20px;">
                            <h4 class="novel-title">{{ $dataTypeContent->name }}</h4>
                            <p class="novel-author">
                                <i class="voyager-person"></i> {{ $dataTypeContent->author ?? 'Unknown Author' }}
                            </p>
                            <p>
                                <span class="status-badge {{ $dataTypeContent->status ? 'status-completed' : 'status-active' }}">
                                    {{ $dataTypeContent->status ? 'Completed' : 'Active' }}
                                </span>
                            </p>
                        </div>

                        @php
                            $totalChapters = $dataTypeContent->no_of_chapters ?? 0;
                            $downloadedChapters = $dataTypeContent->chapters()->where('status', 1)->count();
                            $pendingChapters = $dataTypeContent->chapters()->where('status', 0)->count();
                            $percentage = $totalChapters > 0 ? round(($downloadedChapters / $totalChapters) * 100) : 0;
                        @endphp

                        <div class="novel-stats">
                            <div class="novel-stat-item">
                                <div class="novel-stat-value">{{ $totalChapters }}</div>
                                <div class="novel-stat-label">Total Chapters</div>
                            </div>
                            <div class="novel-stat-item">
                                <div class="novel-stat-value">{{ $downloadedChapters }}</div>
                                <div class="novel-stat-label">Downloaded</div>
                            </div>
                            <div class="novel-stat-item">
                                <div class="novel-stat-value">{{ $pendingChapters }}</div>
                                <div class="novel-stat-label">Pending</div>
                            </div>
                        </div>

                        <div>
                            <strong>Download Progress: {{ $percentage }}%</strong>
                            <div class="chapter-progress-large">
                                <div class="chapter-progress-bar-large" style="width: {{ $percentage }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Quick Actions --}}
                <div class="quick-action-panel">
                    <h4><i class="voyager-lightning"></i> Quick Actions</h4>
                    <a href="{{ route('voyager.commands.form', 'toc') }}?novel_id={{ $dataTypeContent->id }}"
                       class="btn btn-info quick-action-btn">
                        <i class="voyager-list"></i> Scrape TOC
                    </a>
                    <a href="{{ route('voyager.commands.form', 'chapter') }}?novel_id={{ $dataTypeContent->id }}"
                       class="btn btn-primary quick-action-btn">
                        <i class="voyager-download"></i> Download Chapters
                    </a>
                    <a href="{{ route('voyager.commands.form', 'epub') }}?novel_id={{ $dataTypeContent->id }}"
                       class="btn btn-success quick-action-btn">
                        <i class="voyager-book"></i> Generate ePub
                    </a>
                    <a href="{{ route('voyager.commands.form', 'normalize_labels') }}?novel_id={{ $dataTypeContent->id }}"
                       class="btn btn-warning quick-action-btn">
                        <i class="voyager-tag"></i> Normalize Labels
                    </a>
                </div>
            </div>

            {{-- Chapters Column --}}
            <div class="col-md-8">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="voyager-list"></i> Chapters
                        </h3>
                        <div class="panel-actions">
                            <a href="{{ route('voyager.novel-chapters.index') }}?key=novel_id&filter=equals&s={{ $dataTypeContent->id }}"
                               class="btn btn-sm btn-primary">
                                View All Chapters
                            </a>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="chapters-table-container">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Book</th>
                                        <th>Chapter</th>
                                        <th>Label</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($dataTypeContent->chapters()->orderBy('book')->orderBy('chapter')->limit(50)->get() as $chapter)
                                        <tr>
                                            <td>{{ $chapter->book }}</td>
                                            <td>{{ $chapter->chapter }}</td>
                                            <td>{{ Str::limit($chapter->label, 40) }}</td>
                                            <td>
                                                @if($chapter->status)
                                                    <span class="label label-success">Downloaded</span>
                                                @else
                                                    <span class="label label-warning">Pending</span>
                                                @endif
                                            </td>
                                            <td>
                                                <a href="{{ route('voyager.novel-chapters.show', $chapter->id) }}"
                                                   class="btn btn-xs btn-warning">
                                                    <i class="voyager-eye"></i>
                                                </a>
                                                <a href="{{ route('voyager.novel-chapters.edit', $chapter->id) }}"
                                                   class="btn btn-xs btn-primary">
                                                    <i class="voyager-edit"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">
                                                No chapters found. Run "Scrape TOC" to fetch chapters.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                            @if($dataTypeContent->chapters()->count() > 50)
                                <div class="text-center text-muted">
                                    Showing first 50 chapters.
                                    <a href="{{ route('voyager.novel-chapters.index') }}?key=novel_id&filter=equals&s={{ $dataTypeContent->id }}">
                                        View all {{ $dataTypeContent->chapters()->count() }} chapters
                                    </a>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Novel Description --}}
                @if($dataTypeContent->description)
                    <div class="panel panel-bordered">
                        <div class="panel-heading">
                            <h3 class="panel-title">Description</h3>
                        </div>
                        <div class="panel-body">
                            {!! $dataTypeContent->description !!}
                        </div>
                    </div>
                @endif

                {{-- Additional Info --}}
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">Additional Information</h3>
                    </div>
                    <div class="panel-body">
                        <table class="table table-bordered">
                            <tr>
                                <th style="width: 200px;">External URL</th>
                                <td>
                                    @if($dataTypeContent->external_url)
                                        <a href="{{ $dataTypeContent->external_url }}" target="_blank">
                                            {{ $dataTypeContent->external_url }}
                                            <i class="voyager-external"></i>
                                        </a>
                                    @else
                                        <span class="text-muted">Not set</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Translator URL</th>
                                <td>
                                    @if($dataTypeContent->translator_url)
                                        <a href="{{ $dataTypeContent->translator_url }}" target="_blank">
                                            {{ $dataTypeContent->translator_url }}
                                            <i class="voyager-external"></i>
                                        </a>
                                    @else
                                        <span class="text-muted">Not set</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Group</th>
                                <td>{{ $dataTypeContent->group->name ?? 'Not assigned' }}</td>
                            </tr>
                            <tr>
                                <th>Language</th>
                                <td>{{ $dataTypeContent->language->name ?? 'Not set' }}</td>
                            </tr>
                            <tr>
                                <th>Created</th>
                                <td>{{ $dataTypeContent->created_at->format('Y-m-d H:i:s') }}</td>
                            </tr>
                            <tr>
                                <th>Last Updated</th>
                                <td>{{ $dataTypeContent->updated_at->format('Y-m-d H:i:s') }}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop
