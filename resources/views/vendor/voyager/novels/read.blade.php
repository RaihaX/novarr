@extends('voyager::master')

@section('page_title', __('voyager::generic.viewing').' Novel')

@section('page_header')
    <h1 class="page-title">
        <i class="voyager-book"></i> {{ $dataTypeContent->name }}
    </h1>
    @include('voyager::multilingual.language-selector')
@stop

@section('content')
    <div class="page-content read container-fluid">
        <div class="row">
            {{-- Novel Info Panel --}}
            <div class="col-md-4">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">Novel Information</h3>
                    </div>
                    <div class="panel-body" style="padding-top: 0;">
                        {{-- Cover Image --}}
                        <div class="text-center" style="margin-bottom: 20px;">
                            @if($dataTypeContent->file && $dataTypeContent->file->file_path)
                                <img src="{{ Storage::url($dataTypeContent->file->file_path) }}"
                                     alt="{{ $dataTypeContent->name }}"
                                     style="max-width: 200px; max-height: 300px; object-fit: cover; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                            @else
                                <div style="width: 200px; height: 300px; background: #f5f5f5; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin: 0 auto;">
                                    <i class="voyager-book" style="font-size: 64px; color: #ccc;"></i>
                                </div>
                            @endif
                        </div>

                        <table class="table table-condensed">
                            <tr>
                                <th style="width: 40%;">Name</th>
                                <td>{{ $dataTypeContent->name }}</td>
                            </tr>
                            <tr>
                                <th>Author</th>
                                <td>{{ $dataTypeContent->author ?? '-' }}</td>
                            </tr>
                            <tr>
                                <th>Translator</th>
                                <td>{{ $dataTypeContent->translator ?? '-' }}</td>
                            </tr>
                            <tr>
                                <th>Group</th>
                                <td>{{ $dataTypeContent->group->name ?? '-' }}</td>
                            </tr>
                            <tr>
                                <th>Language</th>
                                <td>{{ $dataTypeContent->language->name ?? '-' }}</td>
                            </tr>
                            <tr>
                                <th>Status</th>
                                <td>
                                    @if($dataTypeContent->status)
                                        <span class="label label-success">Active</span>
                                    @else
                                        <span class="label label-default">Inactive</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Total Chapters</th>
                                <td><span class="label label-primary">{{ $dataTypeContent->no_of_chapters }}</span></td>
                            </tr>
                            <tr>
                                <th>Created</th>
                                <td>{{ $dataTypeContent->created_at->format('M d, Y') }}</td>
                            </tr>
                        </table>

                        {{-- Progress --}}
                        @php
                            $downloadedChapters = $dataTypeContent->chapters()->where('status', 1)->count();
                            $totalChapters = $dataTypeContent->chapters()->count();
                            $progress = $totalChapters > 0 ? round(($downloadedChapters / $totalChapters) * 100) : 0;
                        @endphp
                        <div style="margin-top: 15px;">
                            <strong>Download Progress</strong>
                            <div class="progress" style="height: 25px; margin-top: 5px;">
                                <div class="progress-bar {{ $progress == 100 ? 'progress-bar-success' : 'progress-bar-info' }}"
                                     role="progressbar"
                                     style="width: {{ $progress }}%; line-height: 25px;">
                                    {{ $downloadedChapters }}/{{ $totalChapters }} ({{ $progress }}%)
                                </div>
                            </div>
                        </div>

                        {{-- URLs --}}
                        @if($dataTypeContent->translator_url)
                            <div style="margin-top: 15px;">
                                <strong>Source URL</strong><br>
                                <a href="{{ $dataTypeContent->translator_url }}" target="_blank" class="text-muted" style="word-break: break-all;">
                                    {{ \Illuminate\Support\Str::limit($dataTypeContent->translator_url, 50) }}
                                    <i class="voyager-external"></i>
                                </a>
                            </div>
                        @endif
                    </div>
                    <div class="panel-footer">
                        @can('edit', $dataTypeContent)
                            <a href="{{ route('voyager.'.$dataType->slug.'.edit', $dataTypeContent->id) }}" class="btn btn-primary">
                                <i class="voyager-edit"></i> Edit
                            </a>
                        @endcan
                        <a href="{{ route('voyager.'.$dataType->slug.'.index') }}" class="btn btn-default">
                            <i class="voyager-list"></i> Back to List
                        </a>
                    </div>
                </div>

                {{-- Description Panel --}}
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
            </div>

            {{-- Chapters Panel --}}
            <div class="col-md-8">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="voyager-file-text"></i> Chapters
                            <span class="badge">{{ $totalChapters }}</span>
                        </h3>
                        <div class="panel-actions">
                            <a href="{{ route('voyager.novel-chapters.index') }}?novel_id={{ $dataTypeContent->id }}" class="btn btn-sm btn-default">
                                View All in BREAD
                            </a>
                        </div>
                    </div>
                    <div class="panel-body" style="padding: 0;">
                        @php
                            $chapters = $dataTypeContent->chapters()->orderBy('chapter', 'asc')->paginate(50);
                        @endphp

                        @if($chapters->count() > 0)
                            <div class="table-responsive">
                                <table class="table table-hover table-striped" style="margin-bottom: 0;">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">Chapter</th>
                                            <th>Label</th>
                                            <th style="width: 60px;">Book</th>
                                            <th style="width: 100px;">Status</th>
                                            <th style="width: 80px;">Blacklist</th>
                                            <th style="width: 100px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($chapters as $chapter)
                                            <tr class="{{ $chapter->blacklist ? 'warning' : '' }}">
                                                <td>
                                                    <strong>{{ $chapter->chapter }}</strong>
                                                </td>
                                                <td>
                                                    {{ $chapter->label ?? '-' }}
                                                    @if($chapter->double_chapter)
                                                        <span class="label label-info" title="Double Chapter">2x</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($chapter->book)
                                                        <span class="label label-default">{{ $chapter->book }}</span>
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($chapter->status)
                                                        <span class="label label-success">
                                                            <i class="voyager-check"></i> Downloaded
                                                        </span>
                                                    @else
                                                        <span class="label label-warning">
                                                            <i class="voyager-x"></i> Pending
                                                        </span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($chapter->blacklist)
                                                        <span class="label label-danger">Yes</span>
                                                    @else
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <a href="{{ route('voyager.novel-chapters.show', $chapter->id) }}"
                                                       class="btn btn-xs btn-warning" title="View">
                                                        <i class="voyager-eye"></i>
                                                    </a>
                                                    <a href="{{ route('voyager.novel-chapters.edit', $chapter->id) }}"
                                                       class="btn btn-xs btn-primary" title="Edit">
                                                        <i class="voyager-edit"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="panel-footer">
                                <div class="row">
                                    <div class="col-sm-6">
                                        <small class="text-muted">
                                            Showing {{ $chapters->firstItem() }} to {{ $chapters->lastItem() }} of {{ $chapters->total() }} chapters
                                        </small>
                                    </div>
                                    <div class="col-sm-6 text-right">
                                        {{ $chapters->appends(request()->query())->links() }}
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="text-center" style="padding: 40px;">
                                <i class="voyager-file-text" style="font-size: 48px; color: #ccc;"></i>
                                <p class="text-muted" style="margin-top: 10px;">No chapters found for this novel.</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Chapter Statistics --}}
                <div class="row">
                    <div class="col-sm-4">
                        <div class="panel panel-bordered">
                            <div class="panel-body text-center">
                                <h3 style="margin: 0; color: #1abc9c;">{{ $downloadedChapters }}</h3>
                                <small class="text-muted">Downloaded</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="panel panel-bordered">
                            <div class="panel-body text-center">
                                @php
                                    $pendingChapters = $dataTypeContent->chapters()->where('status', 0)->where('blacklist', 0)->count();
                                @endphp
                                <h3 style="margin: 0; color: #f39c12;">{{ $pendingChapters }}</h3>
                                <small class="text-muted">Pending</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="panel panel-bordered">
                            <div class="panel-body text-center">
                                @php
                                    $blacklistedChapters = $dataTypeContent->chapters()->where('blacklist', 1)->count();
                                @endphp
                                <h3 style="margin: 0; color: #e74c3c;">{{ $blacklistedChapters }}</h3>
                                <small class="text-muted">Blacklisted</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop

@section('css')
    <style>
        .panel-actions {
            position: absolute;
            right: 15px;
            top: 10px;
        }
        .panel-heading {
            position: relative;
        }
        .table > tbody > tr.warning > td {
            background-color: #fcf8e3;
        }
        .pagination {
            margin: 0;
        }
    </style>
@stop
