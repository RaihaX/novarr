@extends('voyager::bread.browse')

@section('css')
    @parent
    <style>
        .novel-cover-thumb {
            width: 50px;
            height: 70px;
            object-fit: cover;
            border-radius: 4px;
        }

        .chapter-progress {
            width: 100px;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
        }

        .chapter-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #22A7F0, #1abc9c);
            border-radius: 4px;
        }

        .chapter-stats {
            font-size: 0.85rem;
            color: #666;
        }

        .novel-status-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: bold;
        }

        .novel-status-active {
            background: #d4edda;
            color: #155724;
        }

        .novel-status-completed {
            background: #cce5ff;
            color: #004085;
        }

        .quick-actions .btn {
            padding: 4px 8px;
            font-size: 0.8rem;
            margin-right: 3px;
        }

        .table-responsive {
            max-height: 70vh;
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
                                        <th>Cover</th>
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
                                        <th>Progress</th>
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
                                            <td>
                                                @if($data->cover)
                                                    <img src="{{ Voyager::image($data->cover) }}"
                                                         class="novel-cover-thumb"
                                                         alt="{{ $data->name }}">
                                                @else
                                                    <div class="novel-cover-thumb" style="background: #eee; display: flex; align-items: center; justify-content: center;">
                                                        <i class="voyager-book" style="color: #ccc;"></i>
                                                    </div>
                                                @endif
                                            </td>
                                            @foreach($dataType->browseRows as $row)
                                                @php
                                                $rowDetails = $row->details;
                                                @endphp
                                                <td>
                                                    @if (isset($row->details->view_browse))
                                                        @include($row->details->view_browse, ['row' => $row, 'dataType' => $dataType, 'dataTypeContent' => $dataTypeContent, 'content' => $data->{$row->field}, 'view' => 'browse', 'options' => $row->details])
                                                    @elseif (isset($row->details->view))
                                                        @include($row->details->view, ['row' => $row, 'dataType' => $dataType, 'dataTypeContent' => $dataTypeContent, 'content' => $data->{$row->field}, 'action' => 'browse', 'view' => 'browse', 'options' => $row->details])
                                                    @elseif($row->type == 'image')
                                                        <img src="@if( !filter_var($data->{$row->field}, FILTER_VALIDATE_URL)){{ Voyager::image( $data->{$row->field} ) }}@else{{ $data->{$row->field} }}@endif" style="width:100px">
                                                    @elseif($row->type == 'relationship')
                                                        @include('voyager::formfields.relationship', ['view' => 'browse','options' => $row->details])
                                                    @elseif($row->type == 'select_multiple')
                                                        @if(property_exists($rowDetails, 'relationship'))
                                                            @foreach($data->{$row->field} as $item)
                                                                {{ $item->{$row->field} }}
                                                            @endforeach
                                                        @elseif(property_exists($rowDetails, 'options'))
                                                            @if (!empty(json_decode($data->{$row->field})))
                                                                @foreach(json_decode($data->{$row->field}) as $item)
                                                                    @if (@$rowDetails->options->{$item})
                                                                        {{ $rowDetails->options->{$item} . (!$loop->last ? ', ' : '') }}
                                                                    @endif
                                                                @endforeach
                                                            @else
                                                                {{ __('voyager::generic.none') }}
                                                            @endif
                                                        @endif
                                                    @elseif($row->type == 'multiple_checkbox' && property_exists($rowDetails, 'options'))
                                                        @if (@count(json_decode($data->{$row->field})) > 0)
                                                            @foreach(json_decode($data->{$row->field}) as $item)
                                                                @if (@$rowDetails->options->{$item})
                                                                    {{ $rowDetails->options->{$item} . (!$loop->last ? ', ' : '') }}
                                                                @endif
                                                            @endforeach
                                                        @else
                                                            {{ __('voyager::generic.none') }}
                                                        @endif
                                                    @elseif(($row->type == 'select_dropdown' || $row->type == 'radio_btn') && property_exists($rowDetails, 'options'))
                                                        @if($row->field == 'status')
                                                            <span class="novel-status-badge {{ $data->{$row->field} ? 'novel-status-completed' : 'novel-status-active' }}">
                                                                {{ $data->{$row->field} ? 'Completed' : 'Active' }}
                                                            </span>
                                                        @else
                                                            {{ $rowDetails->options->{$data->{$row->field}} ?? '' }}
                                                        @endif
                                                    @elseif($row->type == 'date' || $row->type == 'timestamp')
                                                        @if ( property_exists($rowDetails, 'format') && !is_null($data->{$row->field}) )
                                                            {{ \Carbon\Carbon::parse($data->{$row->field})->formatLocalized($rowDetails->format) }}
                                                        @else
                                                            {{ $data->{$row->field} }}
                                                        @endif
                                                    @elseif($row->type == 'checkbox')
                                                        @if($row->field == 'status')
                                                            <span class="novel-status-badge {{ $data->{$row->field} ? 'novel-status-completed' : 'novel-status-active' }}">
                                                                {{ $data->{$row->field} ? 'Completed' : 'Active' }}
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
                                                    @elseif($row->type == 'color')
                                                        <span class="badge badge-lg" style="background-color: {{ $data->{$row->field} }}">{{ $data->{$row->field} }}</span>
                                                    @elseif($row->type == 'text')
                                                        @include('voyager::multilingual.input-hidden-bread-browse')
                                                        <div>{{ mb_strlen( $data->{$row->field} ) > 200 ? mb_substr($data->{$row->field}, 0, 200) . ' ...' : $data->{$row->field} }}</div>
                                                    @elseif($row->type == 'text_area')
                                                        @include('voyager::multilingual.input-hidden-bread-browse')
                                                        <div>{{ mb_strlen( $data->{$row->field} ) > 200 ? mb_substr($data->{$row->field}, 0, 200) . ' ...' : $data->{$row->field} }}</div>
                                                    @elseif($row->type == 'file' && !empty($data->{$row->field}) )
                                                        @include('voyager::multilingual.input-hidden-bread-browse')
                                                        @if(json_decode($data->{$row->field}) !== null)
                                                            @foreach(json_decode($data->{$row->field}) as $file)
                                                                <a href="{{ Storage::disk(config('voyager.storage.disk'))->url($file->download_link) ?: '' }}" target="_blank">
                                                                    {{ $file->original_name ?: '' }}
                                                                </a>
                                                                <br/>
                                                            @endforeach
                                                        @else
                                                            <a href="{{ Storage::disk(config('voyager.storage.disk'))->url($data->{$row->field}) }}" target="_blank">
                                                                Download
                                                            </a>
                                                        @endif
                                                    @elseif($row->type == 'rich_text_box')
                                                        @include('voyager::multilingual.input-hidden-bread-browse')
                                                        <div>{{ mb_strlen( strip_tags($data->{$row->field}, '<b><i><u>') ) > 200 ? mb_substr(strip_tags($data->{$row->field}, '<b><i><u>'), 0, 200) . ' ...' : strip_tags($data->{$row->field}, '<b><i><u>') }}</div>
                                                    @elseif($row->type == 'coordinates')
                                                        @include('voyager::partials.coordinates-static-image')
                                                    @elseif($row->type == 'multiple_images')
                                                        @php $images = json_decode($data->{$row->field}); @endphp
                                                        @if($images)
                                                            @php $images = array_slice($images, 0, 3); @endphp
                                                            @foreach($images as $image)
                                                                <img src="@if( !filter_var($image, FILTER_VALIDATE_URL)){{ Voyager::image( $image ) }}@else{{ $image }}@endif" style="width:50px">
                                                            @endforeach
                                                        @endif
                                                    @elseif($row->type == 'media_picker')
                                                        @php
                                                            if (is_array($data->{$row->field})) {
                                                                $files = $data->{$row->field};
                                                            } else {
                                                                $files = json_decode($data->{$row->field});
                                                            }
                                                        @endphp
                                                        @if ($files)
                                                            @if (property_exists($rowDetails, 'show_as_images') && $rowDetails->show_as_images)
                                                                @foreach (array_slice($files, 0, 3) as $file)
                                                                    <img src="@if( !filter_var($file, FILTER_VALIDATE_URL)){{ Voyager::image( $file ) }}@else{{ $file }}@endif" style="width:50px">
                                                                @endforeach
                                                            @else
                                                                <ul>
                                                                    @foreach (array_slice($files, 0, 3) as $file)
                                                                        <li>{{ $file }}</li>
                                                                    @endforeach
                                                                </ul>
                                                            @endif
                                                            @if (count($files) > 3)
                                                                {{ __('voyager::media.files_more', ['count' => (count($files) - 3)]) }}
                                                            @endif
                                                        @elseif (is_array($files) && count($files) == 0)
                                                            {{ trans_choice('voyager::media.files', 0) }}
                                                        @elseif ($data->{$row->field} != '')
                                                            @if (property_exists($rowDetails, 'show_as_images') && $rowDetails->show_as_images)
                                                                <img src="@if( !filter_var($data->{$row->field}, FILTER_VALIDATE_URL)){{ Voyager::image( $data->{$row->field} ) }}@else{{ $data->{$row->field} }}@endif" style="width:50px">
                                                            @else
                                                                {{ $data->{$row->field} }}
                                                            @endif
                                                        @else
                                                            {{ trans_choice('voyager::media.files', 0) }}
                                                        @endif
                                                    @else
                                                        @include('voyager::multilingual.input-hidden-bread-browse')
                                                        <span>{{ $data->{$row->field} }}</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td>
                                                @php
                                                    $totalChapters = $data->no_of_chapters ?? 0;
                                                    $downloadedChapters = $data->chapters()->where('status', 1)->count();
                                                    $percentage = $totalChapters > 0 ? round(($downloadedChapters / $totalChapters) * 100) : 0;
                                                @endphp
                                                <div class="chapter-progress">
                                                    <div class="chapter-progress-bar" style="width: {{ $percentage }}%"></div>
                                                </div>
                                                <div class="chapter-stats">
                                                    {{ $downloadedChapters }}/{{ $totalChapters }} ({{ $percentage }}%)
                                                </div>
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
                                                    <a href="{{ route('voyager.novel-chapters.index') }}?key=novel_id&filter=equals&s={{ $data->getKey() }}" title="View Chapters" class="btn btn-sm btn-info">
                                                        <i class="voyager-list"></i>
                                                    </a>
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
