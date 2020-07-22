@extends('layouts.app')

@section('content')
    <div class="row justify-content-center">
        <div class="col">
            <div class="card">
                <div class="card-header">Novels</div>

                <div class="card-body">
                    <table id="contentTable" class="table table-sm">
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Author</th>
{{--                                <th scope="col">Translator</th>--}}
                                <th scope="col">Group</th>
                                <th scope="col">Current</th>
                                <th scope="col">Queue</th>
                                <th scope="col">Duplicate</th>
                                <th scope="col">Missing</th>
                                <th scope="col">Total</th>
                                <th scope="col">Progress</th>
                                <th scope="col">Alt</th>
                                <th scope="col">Status</th>
                                {{--<th scope="col"></th>--}}
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="formModalDiv" tabindex="-1" role="dialog" aria-labelledby="formModalCenterTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Novel</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="formModal" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="0" id="id">
                        <input type="hidden" name="_method" id="_method">

                        <div class="form-group row">
                            <label for="name" class="col-sm-4 col-form-label text-md-right">{{ __('Name') }}</label>

                            <div class="col-md-6">
                                <input id="name" type="text" class="form-control" name="name" required autofocus>
                            </div>
                        </div>

                        {{--<div class="form-group row">--}}
                            {{--<label for="description" class="col-sm-4 col-form-label text-md-right">{{ __('Description') }}</label>--}}

                            {{--<div class="col-md-6">--}}
                                {{--<textarea id="description" class="form-control" name="description"></textarea>--}}
                            {{--</div>--}}
                        {{--</div>--}}

                        {{--<div class="form-group row">--}}
                            {{--<label for="author" class="col-sm-4 col-form-label text-md-right">{{ __('Author') }}</label>--}}

                            {{--<div class="col-md-6">--}}
                                {{--<input id="author" type="text" class="form-control" name="author" required autofocus>--}}
                            {{--</div>--}}
                        {{--</div>--}}

                        <div class="form-group row">
                            <label for="translator" class="col-sm-4 col-form-label text-md-right">{{ __('Translator') }}</label>

                            <div class="col-md-6">
                                <input id="translator" type="text" class="form-control" name="translator" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="translator_url" class="col-sm-4 col-form-label text-md-right">{{ __('URL') }}</label>

                            <div class="col-md-6">
                                <input id="translator_url" type="text" class="form-control" name="translator_url" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="chapter_url" class="col-sm-4 col-form-label text-md-right">{{ __('Chapter URL') }}</label>

                            <div class="col-md-6">
                                <input id="chapter_url" type="text" class="form-control" name="chapter_url" autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="alternative_url" class="col-sm-4 col-form-label text-md-right">{{ __('Alternative URL') }}</label>

                            <div class="col-md-6">
                                <input id="alternative_url" type="text" class="form-control" name="alternative_url" autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="unique_id" class="col-sm-4 col-form-label text-md-right">{{ __('Unique ID') }}</label>

                            <div class="col-md-6">
                                <input id="unique_id" type="text" class="form-control" name="unique_id" value="0" autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="json" class="col-sm-4 col-form-label text-md-right">{{ __('JSON') }}</label>

                            <div class="col-md-6">
                                <input id="json" type="text" class="form-control" name="json" autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="group_id" class="col-sm-4 col-form-label text-md-right">{{ __('Translator Group') }}</label>

                            <div class="col-md-6">
                                <select name="group_id" id="group_id" class="form-control" required autofocus>
                                    @foreach ($groups as $item)
                                        <option value="{{ $item->id }}">{{ $item->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="language_id" class="col-sm-4 col-form-label text-md-right">{{ __('Language') }}</label>

                            <div class="col-md-6">
                                <select name="language_id" id="language_id" class="form-control" required autofocus>
                                    @foreach ($languages as $item)
                                        <option value="{{ $item->id }}">{{ $item->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="no_of_chapters" class="col-sm-4 col-form-label text-md-right">{{ __('No. of Chapters') }}</label>

                            <div class="col-md-6">
                                <input id="no_of_chapters" type="text" class="form-control" name="no_of_chapters" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="status" class="col-sm-4 col-form-label text-md-right">{{ __('Status') }}</label>

                            <div class="col-md-6">
                                <select class="form-control" name="status" id="status">
                                    <option value="0">Active</option>
                                    <option value="1">Completed</option>
                                    <option value="2">Dropped</option>
                                    <option value="3">Freeze</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="image" class="col-sm-4 col-form-label text-md-right">{{ __('Image') }}</label>

                            <div class="col-md-6">
                                <input id="image" type="file" class="form-control" name="image" autofocus>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="saveForm();">Save changes</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    <script type="text/javascript">
        $(function() {
            var table = $('#contentTable').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,
                pageLength: 25,
                select: true,
                order: [[0, "asc"]],
                ajax: '{!! route('novels.datatables') !!}',
                columns: [
                    {
                        data: 'name',
                        name: 'name',
                        render: function (data, type, row, meta) {
                            return '<a href="novels/' + row.id + '">' + data + '</a>';
                        }
                    }, {
                        data: 'author', name: 'author'
                    // }, {
                    //     data: 'translator', name: 'translator'
                    }, {
                        data: 'group.label', name: 'group.label'
                    }, {
                        data: 'current_chapters',
                        name: 'current_chapters'
                    }, {
                        data: 'chapters_not_downloaded',
                        name: 'chapters_not_downloaded'
                    }, {
                        data: 'duplicate_chapters',
                        name: 'duplicate_chapters'
                    }, {
                        data: 'missing_chapters',
                        name: 'missing_chapters'
                    }, {
                        data: 'no_of_chapters',
                        name: 'no_of_chapters'
                    }, {
                        data: 'progress',
                        name: 'progress'
                    }, {
                        data: 'alternative_url',
                        name: 'alternative_url',
                        render: function (data, type, row, meta) {
                            var d = "No";

                            if ( data !== null ) {
                                d = "Yes";
                            }

                            return d;
                        }
                    }, {
                        data: 'status',
                        name: 'status',
                        render: function (data, type, row, meta) {
                            var d = "";

                            switch (data) {
                                case 0:
                                    d = "Active";
                                    break;
                                case "1":
                                    d = "Completed";
                                    break;
                                case "2":
                                    d = "Dropped";
                                    break;
                                case "3":
                                    d = "Freeze";
                                    break;
                            }

                            return d;
                        }
                    }
                ],
                lengthChange: false,
                buttons: [
                    {
                        text: 'Create',
                        className: 'btn-sm',
                        action: function () {
                            $("#formModal").trigger('reset');
                            $('#formModalDiv').modal({});
                        }
                    }, {
                        text: 'Edit',
                        className: 'btn-sm',
                        action: function () {
                            var d = table.rows({ selected: true }).data()[0];

                            $("#id").val(d.id);
                            $("#name").val(d.name);
                            $("#description").val(d.description);
                            $("#author").val(d.author);
                            $("#translator").val(d.translator);
                            $("#translator_url").val(d.translator_url);
                            $("#chapter_url").val(d.chapter_url);
                            $("#alternative_url").val(d.alternative_url);
                            $("#unique_id").val(d.unique_id);
                            $("#json").val(d.json);
                            $("#group_id").val(d.group_id);
                            $("#language_id").val(d.language_id);
                            $("#no_of_chapters").val(d.no_of_chapters);
                            $("#status").val(d.status);

                            $('#formModalDiv').modal({});
                        },
                        enabled: false
                    }
                ],
                initComplete: function () {
                    table.buttons().container().appendTo( '#contentTable_wrapper .col-md-6:eq(0)' );
                }
            });

            table.on( 'select deselect', function () {
                var selectedRows = table.rows( { selected: true } ).count();

                table.button( 1 ).enable( selectedRows === 1 );
            } );
        });

        function saveForm() {
            var form = $("#formModal");
            var id = $("#id");
            var _method = $("#_method");

            form.find('input[type="file"]').each(function() {
                var input = $(this);

                if ( input[0].files.length == 0 ) {
                    input.prop('disabled', true);
                }
            });

            if ( id.val() == 0 ) {
                _method.val("POST");
                var formData = new FormData(form[0]);

                $.ajax({
                    method: "POST",
                    url: "/novels",
                    contentType: false,
                    processData: false,
                    data: formData
                }).done(function() {
                    $('#contentTable').DataTable().ajax.reload(null, false);
                    $('#formModalDiv').modal('hide');

                    form.trigger('reset');
                });
            } else {
                _method.val("PATCH");
                var formData = new FormData(form[0]);

                $.ajax({
                    method: "POST",
                    url: "/novels/" + id.val(),
                    contentType: false,
                    processData: false,
                    data: formData
                }).done(function() {
                    $('#contentTable').DataTable().ajax.reload(null, false);
                    $('#formModalDiv').modal('hide');

                    form.trigger('reset');
                });
            }
        }
    </script>
@endsection