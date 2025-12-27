@extends('voyager::master')

@section('page_title', __('voyager::generic.viewing').' Novels')

@section('page_header')
    <div class="container-fluid">
        <h1 class="page-title">
            <i class="voyager-book"></i> Novels
        </h1>
        @can('add', app($dataType->model_name))
            <a href="{{ route('voyager.'.$dataType->slug.'.create') }}" class="btn btn-success btn-add-new">
                <i class="voyager-plus"></i> <span>{{ __('voyager::generic.add_new') }}</span>
            </a>
        @endcan
    </div>
@stop

@section('content')
    <div class="page-content browse container-fluid">
        @include('voyager::alerts')
        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-bordered">
                    <div class="panel-body">
                        @php
                            // Use withCount instead of eager loading all chapters to avoid memory issues
                            $novels = \App\Novel::with(['file', 'group'])
                                ->withCount(['chapters', 'chapters as downloaded_chapters_count' => function($query) {
                                    $query->where('status', 1);
                                }])
                                ->orderBy('name')
                                ->paginate(20);
                        @endphp

                        @if($novels->count() > 0)
                            <div class="table-responsive">
                                <table id="dataTable" class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 80px;">Cover</th>
                                            <th>Name</th>
                                            <th>Author</th>
                                            <th style="width: 120px;">Progress</th>
                                            <th style="width: 100px;">Chapters</th>
                                            <th style="width: 80px;">Status</th>
                                            <th style="width: 120px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($novels as $data)
                                            <tr>
                                                <td>
                                                    @if($data->file && $data->file->file_path)
                                                        <img src="{{ Storage::url($data->file->file_path) }}"
                                                             alt="{{ $data->name }}"
                                                             style="width: 60px; height: 80px; object-fit: cover; border-radius: 4px;">
                                                    @else
                                                        <div style="width: 60px; height: 80px; background: #eee; border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                                                            <i class="voyager-book" style="font-size: 24px; color: #ccc;"></i>
                                                        </div>
                                                    @endif
                                                </td>
                                                <td>
                                                    <strong><a href="{{ route('voyager.'.$dataType->slug.'.show', $data->id) }}">{{ $data->name }}</a></strong>
                                                    @if($data->group)
                                                        <br><small class="text-muted">{{ $data->group->name }}</small>
                                                    @endif
                                                </td>
                                                <td>{{ $data->author ?? '-' }}</td>
                                                <td>
                                                    @php
                                                        $downloadedChapters = $data->downloaded_chapters_count;
                                                        $totalChapters = $data->chapters_count;
                                                        $progress = $totalChapters > 0 ? round(($downloadedChapters / $totalChapters) * 100) : 0;
                                                    @endphp
                                                    <div class="progress" style="margin-bottom: 0; height: 20px;">
                                                        <div class="progress-bar {{ $progress == 100 ? 'progress-bar-success' : 'progress-bar-info' }}"
                                                             role="progressbar"
                                                             style="width: {{ $progress }}%; min-width: 30px;">
                                                            {{ $progress }}%
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">{{ $downloadedChapters }}/{{ $totalChapters }}</small>
                                                </td>
                                                <td>
                                                    <span class="label label-primary">{{ $data->no_of_chapters }}</span>
                                                </td>
                                                <td>
                                                    @if($data->status)
                                                        <span class="label label-success">Active</span>
                                                    @else
                                                        <span class="label label-default">Inactive</span>
                                                    @endif
                                                </td>
                                                <td class="no-sort no-click bread-actions">
                                                    @can('read', $data)
                                                        <a href="{{ route('voyager.'.$dataType->slug.'.show', $data->id) }}" title="View" class="btn btn-sm btn-warning pull-right view">
                                                            <i class="voyager-eye"></i>
                                                        </a>
                                                    @endcan
                                                    @can('edit', $data)
                                                        <a href="{{ route('voyager.'.$dataType->slug.'.edit', $data->id) }}" title="Edit" class="btn btn-sm btn-primary pull-right edit">
                                                            <i class="voyager-edit"></i>
                                                        </a>
                                                    @endcan
                                                    @can('delete', $data)
                                                        <button title="Delete" class="btn btn-sm btn-danger pull-right delete" data-id="{{ $data->id }}" id="delete-{{ $data->id }}">
                                                            <i class="voyager-trash"></i>
                                                        </button>
                                                    @endcan
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="pull-left">
                                <div role="status" class="show-res" aria-live="polite">
                                    Showing {{ $novels->firstItem() }} to {{ $novels->lastItem() }} of {{ $novels->total() }} entries
                                </div>
                            </div>
                            <div class="pull-right">
                                {{ $novels->appends(request()->query())->links() }}
                            </div>
                        @else
                            <div class="text-center">
                                <p>No novels found.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Delete Modal --}}
    <div class="modal modal-danger fade" tabindex="-1" id="delete_modal" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"><i class="voyager-trash"></i> Are you sure you want to delete this novel?</h4>
                </div>
                <div class="modal-footer">
                    <form action="#" id="delete_form" method="POST">
                        {{ method_field('DELETE') }}
                        {{ csrf_field() }}
                        <input type="submit" class="btn btn-danger pull-right delete-confirm" value="Yes, Delete This Novel">
                    </form>
                    <button type="button" class="btn btn-default pull-right" data-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>
@stop

@section('css')
    <style>
        .bread-actions .btn {
            margin-left: 3px;
        }
        .progress {
            margin-bottom: 5px;
        }
        #dataTable td {
            vertical-align: middle;
        }
    </style>
@stop

@section('javascript')
    <script>
        $(document).ready(function() {
            $('.delete').on('click', function(e) {
                var id = $(this).data('id');
                $('#delete_form').attr('action', '{{ route('voyager.'.$dataType->slug.'.destroy', '') }}/' + id);
                $('#delete_modal').modal('show');
            });
        });
    </script>
@stop
