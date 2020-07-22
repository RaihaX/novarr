@extends('layouts.app')

@section('content')
    <div class="row justify-content-center">
        <div class="col">
            <div class="card">
                <div class="card-header"><span id="name_content"></span></div>

                <div class="card-body">
                    <div class="row">
                        <div class="col">
                            <div class="btn-group btn-group-sm pb-2" role="group" aria-label="Toolbar" id="button_content">
                                <a class="btn btn-info" href="#" onclick="editNovel()" role="button">Edit</a>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-2">
                            <figure class="figure">
                                <img src="{{ isset($data->file) ? asset(str_replace("public/", "storage/", $data->file->file_path)) : "/images/placeholder.png" }}" class="figure-img img-fluid rounded" alt="{{ $data->name }}">
                            </figure>
                        </div>
                        <div class="col-10">
                            <p><strong>Author:</strong> <span id="author_content"></span></p>
                            <p id="description_content"></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <table id="contentTable" class="table table-sm">
                        <thead>
                        <tr>
                            <th scope="col">Chapter</th>
                            <th scope="col">Label</th>
                            <th scope="col">Created</th>
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
                    <h5 class="modal-title">Language</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="formModal">
                        <input type="hidden" name="id" id="id" value="0">
                        <input type="hidden" name="novel_id" id="novel_id" value="{{ $data->id }}">

                        <div class="form-group row">
                            <label for="label" class="col-sm-4 col-form-label text-md-right">{{ __('Label') }}</label>

                            <div class="col-md-6">
                                <input id="label" type="text" class="form-control" name="label" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="book" class="col-sm-4 col-form-label text-md-right">{{ __('Book') }}</label>

                            <div class="col-md-6">
                                <input id="book" type="text" class="form-control" name="book">
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="chapter" class="col-sm-4 col-form-label text-md-right">{{ __('Chapter') }}</label>

                            <div class="col-md-6">
                                <input id="chapter" type="text" class="form-control" name="chapter" required>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="double_chapter" class="col-sm-4 col-form-label text-md-right">{{ __('Double Chapter?') }}</label>

                            <div class="col-md-6">
                                <select class="form-control" name="double_chapter" id="double_chapter">
                                    <option value="0">No</option>
                                    <option value="1">Yes</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="url" class="col-sm-4 col-form-label text-md-right">{{ __('URL') }}</label>

                            <div class="col-md-6">
                                <input id="url" type="text" class="form-control" name="url" required>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="description" class="col-sm-4 col-form-label text-md-right">{{ __('Description') }}</label>

                            <div class="col-md-6">
                                <textarea id="description" class="form-control" name="description"></textarea>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="status" class="col-sm-4 col-form-label text-md-right">{{ __('Status') }}</label>

                            <div class="col-md-6">
                                <select class="form-control" name="status" id="status">
                                    <option value="0">Not Complete</option>
                                    <option value="1">Complete</option>
                                </select>
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

    <div class="modal fade" id="chapterModalDiv" tabindex="-1" role="dialog" aria-labelledby="formModalCenterTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="chapter_title"></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="chapter_body">

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    <script type="text/javascript">
        var _manga_id = '{{ $data->id }}';

        $(function() {
            var table = $('#contentTable').DataTable({
                pageLength: 100,
                processing: true,
                serverSide: true,
                select: true,
                responsive: true,
                order: [[ 0, "asc" ], [1, "asc"]],
                ajax: '{!! route('mangachapters.datatables', ['id' => $data->id]) !!}',
                columns: [
                    {
                        data: 'chapter',
                        name: 'chapter'
                    }, {
                        data: 'label',
                        name: 'label'
                    }, {
                        data: 'created_at',
                        name: 'created_at',
                        render: function(data, type, row, meta) {
                            return moment(data).format("DD-MM-YYYY hh:mm A");
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

                            $.ajax({
                                method: 'GET',
                                url: '/novelchapters/' + d.id
                            }).done(function(d) {
                                $("#id").val(d.id);
                                $("#novel_id").val(d.novel_id);
                                $("#label").val(d.label);
                                $("#book").val(d.book);
                                $("#chapter").val(d.chapter);
                                $("#double_chapter").val(d.double_chapter);
                                $("#url").val(d.url);
                                $("#description").val(d.description);
                                $("#status").val(d.status);

                                $('#formModalDiv').modal({});
                            });
                        },
                        enabled: false
                    }, {
                        text: 'Delete',
                        className: 'btn-sm',
                        action: function() {
                            var d = table.rows({ selected: true }).data();

                            $.each(d, function(a, b) {
                                deleteItem(b.id);
                            });

                            $('#contentTable').DataTable().ajax.reload(null, false);
                            getNovel(_novel_id);
                        },
                        enabled: false
                    }
                ],
                initComplete: function () {
                    table.buttons().container().appendTo( '#contentTable_wrapper .col-md-6:eq(0)' );
                }
            });

            getManga(_manga_id)
        });

        function getManga(id) {
            $.ajax({
                method: "GET",
                url: "/mangas/getmanga/" + id
            }).done(function(d) {
                var name_content = $("#name_content");
                name_content.empty().append(d.data.name);

                var author_content = $("#author_content");
                author_content.empty().append(d.data.author);

                var description_content = $("#description_content");
                description_content.empty().append(d.data.description);
            });
        }
    </script>
@endsection