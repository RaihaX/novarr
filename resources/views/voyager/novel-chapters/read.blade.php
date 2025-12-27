@extends('voyager::bread.read')

@section('css')
    @parent
    <style>
        .chapter-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            padding: 15px;
            background: #f5f5f5;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        .chapter-meta-item {
            display: flex;
            flex-direction: column;
        }

        .chapter-meta-label {
            font-size: 0.75rem;
            color: #666;
            text-transform: uppercase;
        }

        .chapter-meta-value {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
        }

        .chapter-content {
            overflow-y: auto;
            max-height: 70vh;
            padding: 25px;
            background: #fafafa;
            border: 1px solid #e3e3e3;
            border-radius: 4px;
            line-height: 1.9;
            font-size: 15px;
        }

        .chapter-content p {
            margin-bottom: 1em;
        }

        .chapter-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .chapter-stat {
            background: #fff;
            border: 1px solid #e3e3e3;
            padding: 10px 15px;
            border-radius: 4px;
            text-align: center;
        }

        .chapter-stat-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #22A7F0;
        }

        .chapter-stat-label {
            font-size: 0.8rem;
            color: #666;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-downloaded {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .chapter-navigation {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e3e3e3;
        }

        .action-buttons {
            margin-bottom: 20px;
        }

        .action-buttons .btn {
            margin-right: 10px;
        }
    </style>
@stop

@section('content')
    <div class="page-content read container-fluid">
        @include('voyager::alerts')

        <div class="row">
            <div class="col-md-12">
                {{-- Action Buttons --}}
                <div class="action-buttons">
                    <a href="{{ route('voyager.novel-chapters.index') }}?key=novel_id&filter=equals&s={{ $dataTypeContent->novel_id }}"
                       class="btn btn-default">
                        <i class="voyager-angle-left"></i> Back to Chapters
                    </a>
                    @if($dataTypeContent->novel)
                        <a href="{{ route('voyager.novels.show', $dataTypeContent->novel_id) }}"
                           class="btn btn-info">
                            <i class="voyager-book"></i> View Novel
                        </a>
                    @endif
                    <a href="{{ route('voyager.novel-chapters.edit', $dataTypeContent->id) }}"
                       class="btn btn-primary">
                        <i class="voyager-edit"></i> Edit
                    </a>
                </div>

                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            {{ $dataTypeContent->label ?? 'Chapter ' . $dataTypeContent->chapter }}
                        </h3>
                    </div>
                    <div class="panel-body">
                        {{-- Chapter Meta --}}
                        <div class="chapter-meta">
                            <div class="chapter-meta-item">
                                <span class="chapter-meta-label">Novel</span>
                                <span class="chapter-meta-value">
                                    @if($dataTypeContent->novel)
                                        <a href="{{ route('voyager.novels.show', $dataTypeContent->novel_id) }}">
                                            {{ $dataTypeContent->novel->name }}
                                        </a>
                                    @else
                                        <span class="text-muted">Unknown</span>
                                    @endif
                                </span>
                            </div>
                            <div class="chapter-meta-item">
                                <span class="chapter-meta-label">Book</span>
                                <span class="chapter-meta-value">{{ $dataTypeContent->book ?? 0 }}</span>
                            </div>
                            <div class="chapter-meta-item">
                                <span class="chapter-meta-label">Chapter</span>
                                <span class="chapter-meta-value">{{ $dataTypeContent->chapter }}</span>
                            </div>
                            <div class="chapter-meta-item">
                                <span class="chapter-meta-label">Status</span>
                                <span class="chapter-meta-value">
                                    <span class="status-badge {{ $dataTypeContent->status ? 'status-downloaded' : 'status-pending' }}">
                                        {{ $dataTypeContent->status ? 'Downloaded' : 'Pending' }}
                                    </span>
                                </span>
                            </div>
                        </div>

                        {{-- Chapter Stats --}}
                        @php
                            $content = strip_tags($dataTypeContent->description ?? '');
                            $wordCount = str_word_count($content);
                            $charCount = mb_strlen($content);
                        @endphp
                        <div class="chapter-stats">
                            <div class="chapter-stat">
                                <div class="chapter-stat-value">{{ number_format($wordCount) }}</div>
                                <div class="chapter-stat-label">Words</div>
                            </div>
                            <div class="chapter-stat">
                                <div class="chapter-stat-value">{{ number_format($charCount) }}</div>
                                <div class="chapter-stat-label">Characters</div>
                            </div>
                            @if($dataTypeContent->created_at)
                                <div class="chapter-stat">
                                    <div class="chapter-stat-value">{{ $dataTypeContent->created_at->format('M d, Y') }}</div>
                                    <div class="chapter-stat-label">Added</div>
                                </div>
                            @endif
                        </div>

                        {{-- Source URL --}}
                        @if($dataTypeContent->url)
                            <div class="alert alert-info">
                                <i class="voyager-external"></i>
                                <strong>Source:</strong>
                                <a href="{{ $dataTypeContent->url }}" target="_blank">
                                    {{ Str::limit($dataTypeContent->url, 80) }}
                                </a>
                            </div>
                        @endif

                        {{-- Chapter Content --}}
                        <h4><i class="voyager-file-text"></i> Content</h4>
                        <div class="chapter-content">
                            @if($dataTypeContent->description)
                                {!! $dataTypeContent->description !!}
                            @else
                                <p class="text-muted text-center">
                                    <i class="voyager-info-circled"></i>
                                    No content available. This chapter may not have been downloaded yet.
                                </p>
                            @endif
                        </div>

                        {{-- Chapter Navigation --}}
                        @php
                            $prevChapter = \App\NovelChapter::where('novel_id', $dataTypeContent->novel_id)
                                ->where(function($q) use ($dataTypeContent) {
                                    $q->where('book', '<', $dataTypeContent->book)
                                      ->orWhere(function($q2) use ($dataTypeContent) {
                                          $q2->where('book', $dataTypeContent->book)
                                             ->where('chapter', '<', $dataTypeContent->chapter);
                                      });
                                })
                                ->orderBy('book', 'desc')
                                ->orderBy('chapter', 'desc')
                                ->first();

                            $nextChapter = \App\NovelChapter::where('novel_id', $dataTypeContent->novel_id)
                                ->where(function($q) use ($dataTypeContent) {
                                    $q->where('book', '>', $dataTypeContent->book)
                                      ->orWhere(function($q2) use ($dataTypeContent) {
                                          $q2->where('book', $dataTypeContent->book)
                                             ->where('chapter', '>', $dataTypeContent->chapter);
                                      });
                                })
                                ->orderBy('book', 'asc')
                                ->orderBy('chapter', 'asc')
                                ->first();
                        @endphp
                        <div class="chapter-navigation">
                            <div>
                                @if($prevChapter)
                                    <a href="{{ route('voyager.novel-chapters.show', $prevChapter->id) }}"
                                       class="btn btn-default">
                                        <i class="voyager-angle-left"></i>
                                        Previous: {{ Str::limit($prevChapter->label, 30) }}
                                    </a>
                                @endif
                            </div>
                            <div>
                                @if($nextChapter)
                                    <a href="{{ route('voyager.novel-chapters.show', $nextChapter->id) }}"
                                       class="btn btn-default">
                                        Next: {{ Str::limit($nextChapter->label, 30) }}
                                        <i class="voyager-angle-right"></i>
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop
