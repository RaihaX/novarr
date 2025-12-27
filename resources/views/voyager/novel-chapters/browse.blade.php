@extends('voyager::bread.browse')

@section('css')
    @parent
    <style>
        .chapter-status-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: bold;
        }

        .chapter-status-downloaded {
            background: #d4edda;
            color: #155724;
        }

        .chapter-status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .chapter-label {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .table-responsive {
            max-height: 70vh;
        }

        .novel-filter {
            margin-bottom: 15px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
        }

        .word-count {
            font-size: 0.85rem;
            color: #666;
        }

        .quick-actions .btn {
            padding: 4px 8px;
            font-size: 0.8rem;
            margin-right: 3px;
        }

        .bulk-actions {
            margin-bottom: 15px;
        }
    </style>
@stop

@section('content')
    <div class="page-content browse container-fluid">
        @include('voyager::alerts')
        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-bordered">
                    <div class="panel-body">
                        @if ($isServerSide)
                            <form method="get" class="form-search">
                                <div id="search-input">
                                    <div class="col-2">
                                        <select id="search_key" name="key">
                                            @foreach($searchNames as $key => $name)
                                                <option value="{{ $key }}" @if($search->key == $key || (empty($search->key) && $key == $defaultSearchKey)) selected @endif>{{ $name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-2">
                                        <select id="filter" name="filter">
                                            <option value="contains" @if($search->filter == "contains") selected @endif>contains</option>
                                            <option value="equals" @if($search->filter == "equals") selected @endif>=</option>
                                        </select>
                                    </div>
                                    <div class="input-group col-md-12">
                                        <input type="text" class="form-control" placeholder="{{ __('voyager::generic.search') }}" name="s" value="{{ $search->value }}">
                                        <span class="input-group-btn">
                                            <button class="btn btn-info btn-lg" type="submit">
                                                <i class="voyager-search"></i>
                                            </button>
                                        </span>
                                    </div>
                                </div>
                                @if (Request::has('sort_order') && Request::has('order_by'))
                                    <input type="hidden" name="sort_order" value="{{ Request::get('sort_order') }}">
                                    <input type="hidden" name="order_by" value="{{ Request::get('order_by') }}">
                                @endif
                            </form>
                        @endif

                        <div class="table-responsive voyager-table-scroll">
                            <table id="dataTable" class="table table-hover">
                                <thead>
                                    <tr>
                                        @if($showCheckboxColumn)
                                            <th class="dt-not-orderable">
                                                <input type="checkbox" class="select_all">
                                            </th>
                                        @endif
                                        @foreach($dataType->browseRows as $row)
                                            <th>
                                                @if ($isServerSide && in_array($row->field, $sortableColumns))
                                                    <a href="{{ $row->sortByUrl($orderBy, $sortOrder) }}">
                                                @endif
                                                {{ $row->getTranslatedAttribute('display_name') }}
                                                @if ($isServerSide)
                                                    @if ($row->isCurrentSortField($orderBy))
                                                        @if ($sortOrder == 'asc')
                                                            <i class="voyager-angle-up pull-right"></i>
                                                        @else
                                                            <i class="voyager-angle-down pull-right"></i>
                                                        @endif
                                                    @endif
                                                    </a>
                                                @endif
                                            </th>
                                        @endforeach
                                        <th>Word Count</th>
                                        <th class="actions text-right dt-not-orderable">{{ __('voyager::generic.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($dataTypeContent as $data)
                                        <tr>
                                            @if($showCheckboxColumn)
                                                <td>
                                                    <input type="checkbox" name="row_id" id="checkbox_{{ $data->getKey() }}" value="{{ $data->getKey() }}">
                                                </td>
                                            @endif
                                            @foreach($dataType->browseRows as $row)
                                                @php
                                                $rowDetails = $row->details;
                                                @endphp
                                                <td>
                                                    @if (isset($row->details->view_browse))
                                                        @include($row->details->view_browse, ['row' => $row, 'dataType' => $dataType, 'dataTypeContent' => $dataTypeContent, 'content' => $data->{$row->field}, 'view' => 'browse', 'options' => $row->details])
                                                    @elseif (isset($row->details->view))
                                                        @include($row->details->view, ['row' => $row, 'dataType' => $dataType, 'dataTypeContent' => $dataTypeContent, 'content' => $data->{$row->field}, 'action' => 'browse', 'view' => 'browse', 'options' => $row->details])
                                                    @elseif($row->type == 'relationship')
                                                        @include('voyager::formfields.relationship', ['view' => 'browse','options' => $row->details])
                                                    @elseif($row->type == 'checkbox')
                                                        @if($row->field == 'status')
                                                            <span class="chapter-status-badge {{ $data->{$row->field} ? 'chapter-status-downloaded' : 'chapter-status-pending' }}">
                                                                {{ $data->{$row->field} ? 'Downloaded' : 'Pending' }}
                                                            </span>
                                                        @else
                                                            @if(property_exists($rowDetails, 'on') && property_exists($rowDetails, 'off'))
                                                                @if($data->{$row->field})
                                                                    <span class="label label-info">{{ $rowDetails->on }}</span>
                                                                @else
                                                                    <span class="label label-primary">{{ $rowDetails->off }}</span>
                                                                @endif
                                                            @else
                                                                {{ $data->{$row->field} }}
                                                            @endif
                                                        @endif
                                                    @elseif($row->type == 'text' && $row->field == 'label')
                                                        <div class="chapter-label" title="{{ $data->{$row->field} }}">
                                                            {{ $data->{$row->field} }}
                                                        </div>
                                                    @elseif($row->type == 'text')
                                                        @include('voyager::multilingual.input-hidden-bread-browse')
                                                        <div>{{ mb_strlen( $data->{$row->field} ) > 200 ? mb_substr($data->{$row->field}, 0, 200) . ' ...' : $data->{$row->field} }}</div>
                                                    @elseif($row->type == 'text_area' || $row->type == 'rich_text_box')
                                                        <span class="text-muted">
                                                            {{ $data->{$row->field} ? mb_strlen(strip_tags($data->{$row->field})) . ' chars' : 'Empty' }}
                                                        </span>
                                                    @elseif($row->type == 'date' || $row->type == 'timestamp')
                                                        @if ( property_exists($rowDetails, 'format') && !is_null($data->{$row->field}) )
                                                            {{ \Carbon\Carbon::parse($data->{$row->field})->formatLocalized($rowDetails->format) }}
                                                        @else
                                                            {{ $data->{$row->field} }}
                                                        @endif
                                                    @else
                                                        @include('voyager::multilingual.input-hidden-bread-browse')
                                                        <span>{{ $data->{$row->field} }}</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td>
                                                <span class="word-count">
                                                    @if($data->description)
                                                        {{ number_format(str_word_count(strip_tags($data->description))) }} words
                                                    @else
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                </span>
                                            </td>
                                            <td class="no-sort no-click bread-actions">
                                                <div class="quick-actions">
                                                    @can('read', $data)
                                                        <a href="{{ route('voyager.'.$dataType->slug.'.show', $data->getKey()) }}" title="View" class="btn btn-sm btn-warning view">
                                                            <i class="voyager-eye"></i>
                                                        </a>
                                                    @endcan
                                                    @can('edit', $data)
                                                        <a href="{{ route('voyager.'.$dataType->slug.'.edit', $data->getKey()) }}" title="Edit" class="btn btn-sm btn-primary edit">
                                                            <i class="voyager-edit"></i>
                                                        </a>
                                                    @endcan
                                                    @can('delete', $data)
                                                        <a href="javascript:;" title="Delete" class="btn btn-sm btn-danger delete" data-id="{{ $data->getKey() }}" id="delete-{{ $data->getKey() }}">
                                                            <i class="voyager-trash"></i>
                                                        </a>
                                                    @endcan
                                                    @if($data->novel)
                                                        <a href="{{ route('voyager.novels.show', $data->novel_id) }}" title="View Novel" class="btn btn-sm btn-info">
                                                            <i class="voyager-book"></i>
                                                        </a>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($isServerSide)
                            <div class="pull-left">
                                <div role="status" class="show-res" aria-live="polite">{{ trans_choice(
                                    'voyager::generic.showing_entries', $dataTypeContent->total(), [
                                        'from' => $dataTypeContent->firstItem(),
                                        'to' => $dataTypeContent->lastItem(),
                                        'all' => $dataTypeContent->total()
                                    ]) }}</div>
                            </div>
                            <div class="pull-right">
                                {{ $dataTypeContent->appends([
                                    's' => $search->value,
                                    'filter' => $search->filter,
                                    'key' => $search->key,
                                    'order_by' => $orderBy,
                                    'sort_order' => $sortOrder,
                                    'showSoftDeleted' => $showSoftDeleted,
                                ])->links() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Single delete modal --}}
    <div class="modal modal-danger fade" tabindex="-1" id="delete_modal" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('voyager::generic.close') }}"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"><i class="voyager-trash"></i> {{ __('voyager::generic.delete_question') }} {{ strtolower($dataType->getTranslatedAttribute('display_name_singular')) }}?</h4>
                </div>
                <div class="modal-footer">
                    <form action="#" id="delete_form" method="POST">
                        {{ method_field('DELETE') }}
                        {{ csrf_field() }}
                        <input type="submit" class="btn btn-danger pull-right delete-confirm" value="{{ __('voyager::generic.delete_confirm') }}">
                    </form>
                    <button type="button" class="btn btn-default pull-right" data-dismiss="modal">{{ __('voyager::generic.cancel') }}</button>
                </div>
            </div>
        </div>
    </div>
@stop

@section('javascript')
    @parent
    <script>
        $(document).ready(function() {
            var deleteFormAction;
            $('td').on('click', '.delete', function (e) {
                $('#delete_form')[0].action = '{{ route('voyager.'.$dataType->slug.'.destroy', '__id') }}'.replace('__id', $(this).data('id'));
                $('#delete_modal').modal('show');
            });
        });
    </script>
@stop
