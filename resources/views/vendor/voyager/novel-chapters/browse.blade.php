@extends('voyager::master')

@section('page_title', __('voyager::generic.viewing').' Novel Chapters')

@section('page_header')
    <div class="container-fluid">
        <h1 class="page-title">
            <i class="voyager-file-text"></i> Novel Chapters
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

        {{-- Filter Panel --}}
        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title"><i class="voyager-search"></i> Filters</h3>
                    </div>
                    <div class="panel-body">
                        <form method="GET" action="{{ route('voyager.'.$dataType->slug.'.index') }}" class="form-inline">
                            <div class="form-group" style="margin-right: 15px;">
                                <label for="novel_id">Novel:</label>
                                <select name="novel_id" id="novel_id" class="form-control">
                                    <option value="">All Novels</option>
                                    @foreach(\App\Novel::orderBy('name')->get() as $novel)
                                        <option value="{{ $novel->id }}" {{ request('novel_id') == $novel->id ? 'selected' : '' }}>
                                            {{ $novel->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group" style="margin-right: 15px;">
                                <label for="status">Status:</label>
                                <select name="status" id="status" class="form-control">
                                    <option value="">All</option>
                                    <option value="1" {{ request('status') === '1' ? 'selected' : '' }}>Downloaded</option>
                                    <option value="0" {{ request('status') === '0' ? 'selected' : '' }}>Pending</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-right: 15px;">
                                <label for="blacklist">Blacklist:</label>
                                <select name="blacklist" id="blacklist" class="form-control">
                                    <option value="">All</option>
                                    <option value="1" {{ request('blacklist') === '1' ? 'selected' : '' }}>Blacklisted</option>
                                    <option value="0" {{ request('blacklist') === '0' ? 'selected' : '' }}>Normal</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="voyager-search"></i> Filter</button>
                            <a href="{{ route('voyager.'.$dataType->slug.'.index') }}" class="btn btn-default">Clear</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-bordered">
                    <div class="panel-body">
                        @php
                            // Build query with filters
                            $query = \App\NovelChapter::with('novel');

                            if(request('novel_id')) {
                                $query->where('novel_id', request('novel_id'));
                            }
                            if(request('status') !== null && request('status') !== '') {
                                $query->where('status', request('status'));
                            }
                            if(request('blacklist') !== null && request('blacklist') !== '') {
                                $query->where('blacklist', request('blacklist'));
                            }

                            $chapters = $query->orderBy('novel_id')->orderBy('chapter')->paginate(50);
                        @endphp

                        @if($chapters->count() > 0)
                            <div class="table-responsive">
                                <table id="dataTable" class="table table-hover table-striped">
                                    <thead>
                                        <tr>
                                            <th style="width: 200px;">Novel</th>
                                            <th style="width: 80px;">Chapter</th>
                                            <th>Label</th>
                                            <th style="width: 60px;">Book</th>
                                            <th style="width: 100px;">Status</th>
                                            <th style="width: 80px;">Blacklist</th>
                                            <th style="width: 80px;">Double</th>
                                            <th style="width: 100px;">Downloaded</th>
                                            <th style="width: 100px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($chapters as $data)
                                            <tr class="{{ $data->blacklist ? 'warning' : '' }}">
                                                <td>
                                                    @if($data->novel)
                                                        <a href="{{ route('voyager.novels.show', $data->novel_id) }}">
                                                            {{ \Illuminate\Support\Str::limit($data->novel->name, 30) }}
                                                        </a>
                                                    @else
                                                        <span class="text-muted">Unknown</span>
                                                    @endif
                                                </td>
                                                <td><strong>{{ $data->chapter }}</strong></td>
                                                <td>{{ \Illuminate\Support\Str::limit($data->label, 50) ?? '-' }}</td>
                                                <td>
                                                    @if($data->book)
                                                        <span class="label label-default">{{ $data->book }}</span>
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($data->status)
                                                        <span class="label label-success"><i class="voyager-check"></i> Yes</span>
                                                    @else
                                                        <span class="label label-warning"><i class="voyager-x"></i> No</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($data->blacklist)
                                                        <span class="label label-danger">Yes</span>
                                                    @else
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($data->double_chapter)
                                                        <span class="label label-info">Yes</span>
                                                    @else
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($data->download_date)
                                                        <small>{{ \Carbon\Carbon::parse($data->download_date)->format('M d, Y') }}</small>
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                                <td class="no-sort no-click bread-actions">
                                                    @can('read', $data)
                                                        <a href="{{ route('voyager.'.$dataType->slug.'.show', $data->id) }}" title="View" class="btn btn-sm btn-warning">
                                                            <i class="voyager-eye"></i>
                                                        </a>
                                                    @endcan
                                                    @can('edit', $data)
                                                        <a href="{{ route('voyager.'.$dataType->slug.'.edit', $data->id) }}" title="Edit" class="btn btn-sm btn-primary">
                                                            <i class="voyager-edit"></i>
                                                        </a>
                                                    @endcan
                                                    @can('delete', $data)
                                                        <button title="Delete" class="btn btn-sm btn-danger delete" data-id="{{ $data->id }}">
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
                                    Showing {{ $chapters->firstItem() }} to {{ $chapters->lastItem() }} of {{ $chapters->total() }} entries
                                </div>
                            </div>
                            <div class="pull-right">
                                {{ $chapters->appends(request()->query())->links() }}
                            </div>
                        @else
                            <div class="text-center">
                                <p>No chapters found.</p>
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
                    <h4 class="modal-title"><i class="voyager-trash"></i> Are you sure you want to delete this chapter?</h4>
                </div>
                <div class="modal-footer">
                    <form action="#" id="delete_form" method="POST">
                        {{ method_field('DELETE') }}
                        {{ csrf_field() }}
                        <input type="submit" class="btn btn-danger pull-right delete-confirm" value="Yes, Delete">
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
            margin-left: 2px;
        }
        #dataTable td {
            vertical-align: middle;
        }
        .table > tbody > tr.warning > td {
            background-color: #fcf8e3;
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
