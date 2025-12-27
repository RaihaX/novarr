@extends('voyager::master')

@section('page_title', __('voyager::generic.viewing').' Chapter')

@section('page_header')
    <h1 class="page-title">
        <i class="voyager-file-text"></i>
        Chapter {{ $dataTypeContent->chapter }}
        @if($dataTypeContent->novel)
            - {{ $dataTypeContent->novel->name }}
        @endif
    </h1>
@stop

@section('content')
    <div class="page-content read container-fluid">
        <div class="row">
            {{-- Chapter Info Panel --}}
            <div class="col-md-4">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">Chapter Information</h3>
                    </div>
                    <div class="panel-body">
                        <table class="table table-condensed">
                            <tr>
                                <th style="width: 40%;">Novel</th>
                                <td>
                                    @if($dataTypeContent->novel)
                                        <a href="{{ route('voyager.novels.show', $dataTypeContent->novel_id) }}">
                                            {{ $dataTypeContent->novel->name }}
                                        </a>
                                    @else
                                        <span class="text-muted">Unknown</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Chapter</th>
                                <td><strong>{{ $dataTypeContent->chapter }}</strong></td>
                            </tr>
                            <tr>
                                <th>Label</th>
                                <td>{{ $dataTypeContent->label ?? '-' }}</td>
                            </tr>
                            <tr>
                                <th>Book</th>
                                <td>
                                    @if($dataTypeContent->book)
                                        <span class="label label-default">{{ $dataTypeContent->book }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Status</th>
                                <td>
                                    @if($dataTypeContent->status)
                                        <span class="label label-success"><i class="voyager-check"></i> Downloaded</span>
                                    @else
                                        <span class="label label-warning"><i class="voyager-x"></i> Pending</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Blacklist</th>
                                <td>
                                    @if($dataTypeContent->blacklist)
                                        <span class="label label-danger">Yes</span>
                                    @else
                                        <span class="label label-default">No</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Double Chapter</th>
                                <td>
                                    @if($dataTypeContent->double_chapter)
                                        <span class="label label-info">Yes</span>
                                    @else
                                        <span class="label label-default">No</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Unique ID</th>
                                <td><code>{{ $dataTypeContent->unique_id ?? '-' }}</code></td>
                            </tr>
                            <tr>
                                <th>Download Date</th>
                                <td>
                                    @if($dataTypeContent->download_date)
                                        {{ \Carbon\Carbon::parse($dataTypeContent->download_date)->format('M d, Y H:i') }}
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Created</th>
                                <td>{{ $dataTypeContent->created_at->format('M d, Y H:i') }}</td>
                            </tr>
                            <tr>
                                <th>Updated</th>
                                <td>{{ $dataTypeContent->updated_at->format('M d, Y H:i') }}</td>
                            </tr>
                        </table>

                        @if($dataTypeContent->url)
                            <div style="margin-top: 15px;">
                                <strong>Source URL</strong><br>
                                <a href="{{ $dataTypeContent->url }}" target="_blank" class="text-muted" style="word-break: break-all;">
                                    {{ \Illuminate\Support\Str::limit($dataTypeContent->url, 50) }}
                                    <i class="voyager-external"></i>
                                </a>
                            </div>
                        @endif

                        @if($dataTypeContent->html_file)
                            <div style="margin-top: 15px;">
                                <strong>HTML File</strong><br>
                                <code>{{ $dataTypeContent->html_file }}</code>
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

                {{-- Navigation --}}
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">Navigation</h3>
                    </div>
                    <div class="panel-body">
                        <div class="row">
                            <div class="col-xs-6">
                                @php
                                    $prevChapter = \App\NovelChapter::where('novel_id', $dataTypeContent->novel_id)
                                        ->where('chapter', '<', $dataTypeContent->chapter)
                                        ->orderBy('chapter', 'desc')
                                        ->first();
                                @endphp
                                @if($prevChapter)
                                    <a href="{{ route('voyager.'.$dataType->slug.'.show', $prevChapter->id) }}" class="btn btn-default btn-block">
                                        <i class="voyager-angle-left"></i> Previous
                                        <br><small>Ch. {{ $prevChapter->chapter }}</small>
                                    </a>
                                @else
                                    <button class="btn btn-default btn-block" disabled>
                                        <i class="voyager-angle-left"></i> Previous
                                        <br><small>N/A</small>
                                    </button>
                                @endif
                            </div>
                            <div class="col-xs-6">
                                @php
                                    $nextChapter = \App\NovelChapter::where('novel_id', $dataTypeContent->novel_id)
                                        ->where('chapter', '>', $dataTypeContent->chapter)
                                        ->orderBy('chapter', 'asc')
                                        ->first();
                                @endphp
                                @if($nextChapter)
                                    <a href="{{ route('voyager.'.$dataType->slug.'.show', $nextChapter->id) }}" class="btn btn-default btn-block">
                                        Next <i class="voyager-angle-right"></i>
                                        <br><small>Ch. {{ $nextChapter->chapter }}</small>
                                    </a>
                                @else
                                    <button class="btn btn-default btn-block" disabled>
                                        Next <i class="voyager-angle-right"></i>
                                        <br><small>N/A</small>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Content Panel --}}
            <div class="col-md-8">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="voyager-paragraph"></i> Chapter Content
                        </h3>
                    </div>
                    <div class="panel-body">
                        @if($dataTypeContent->description)
                            <div class="chapter-content" style="max-height: 600px; overflow-y: auto; padding: 15px; background: #fafafa; border-radius: 4px; line-height: 1.8;">
                                {!! $dataTypeContent->description !!}
                            </div>
                        @else
                            <div class="text-center" style="padding: 60px;">
                                <i class="voyager-file-text" style="font-size: 64px; color: #ccc;"></i>
                                <p class="text-muted" style="margin-top: 15px;">No content available for this chapter.</p>
                                @if(!$dataTypeContent->status)
                                    <p class="text-warning">This chapter has not been downloaded yet.</p>
                                @endif
                            </div>
                        @endif
                    </div>
                    @if($dataTypeContent->description)
                        <div class="panel-footer">
                            <small class="text-muted">
                                Content length: {{ strlen(strip_tags($dataTypeContent->description)) }} characters
                            </small>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

@section('css')
    <style>
        .chapter-content p {
            margin-bottom: 1em;
        }
        .chapter-content {
            font-size: 15px;
        }
    </style>
@stop
