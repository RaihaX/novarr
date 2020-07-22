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
                                <a class="btn btn-info" href="#" onclick="getMetadata({{ $data->id }})" role="button">Get Metadata</a>
                                <a class="btn btn-info" href="#" onclick="updateTOC({{ $data->id }})" role="button">Update TOC</a>
                                @if ( $new_chapters > 0 )
                                    <a class="btn btn-info" href="#" onclick="downloadNewChapters({{ $data->id }});" role="button">Download New Chapters ({{ $new_chapters }})</a>
                                    <a class="btn btn-info" href="#" onclick="convertQidianToPirateSite({{ $data->id }});" role="button">Convert URL</a>
                                @endif

                                @if ( count($missing_chapters) > 0 )
                                    <a class="btn btn-info" href="#" onclick="downloadMissingChapters({{ $data->id }});" role="button">Create Missing Chapters</a>
                                @endif
                                <a class="btn btn-info" href="#" onclick="generateEpub({{ $data->id }})" role="button">Generate ePUB</a>
                                <a class="btn btn-info"  href="{{ route('novels.download_epub', ['id' => $data->id]) }}" target="_blank" role="button">Download ePUB</a>
                                <a class="btn btn-info" href="#" onclick="deleteAll({{ $data->id }})" role="button">Delete All Chapters</a>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-2">
                            <figure class="figure">
                                <img src="{{ isset($data->file) ? Storage::url($data->file->file_path) : "/images/placeholder.png" }}" class="figure-img img-fluid rounded" alt="{{ $data->name }}">
                            </figure>
                        </div>
                        <div class="col-10">
                            <p><strong>Author:</strong> <span id="author_content"></span></p>
                            <p><strong>Translator:</strong> <span id="translator_content"></span></p>
                            <p><strong>Group:</strong> <span id="group_content"></span></p>
                            <p><strong>Language:</strong> <span id="language_content"></span></p>
                            <p><strong>Number of Chapters:</strong> <span id="chapters_content"></span></p>
                            <p id="description_content"></p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <div class="progress">
                                <div class="progress-bar" id="progress_bar_content" role="progressbar" style="width: {{ $progress }}%" aria-valuenow="" aria-valuemin="0" aria-valuemax="100">{{ $progress }}%</div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col" id="missing_duplicate"></div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <table id="contentTable" class="table table-sm">
                        <thead>
                            <tr>
                                <th scope="col">Book</th>
                                <th scope="col">Chapter</th>
                                <th scope="col">Label</th>
                                <th scope="col">Status</th>
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

    <div class="modal fade" id="novelModalDiv" tabindex="-1" role="dialog" aria-labelledby="formModalCenterTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Novel</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="novelFormModal" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="{{ $data->id }}" id="novel_modal_id">
                        <input type="hidden" name="_method" id="_method">

                        <div class="form-group row">
                            <label for="name" class="col-sm-4 col-form-label text-md-right">{{ __('Name') }}</label>

                            <div class="col-md-6">
                                <input id="name" type="text" class="form-control" name="name" value="{{ $data->name }}" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="description" class="col-sm-4 col-form-label text-md-right">{{ __('Description') }}</label>

                            <div class="col-md-6">
                                <textarea id="description" class="form-control" name="description">{{ $data->description }}</textarea>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="author" class="col-sm-4 col-form-label text-md-right">{{ __('Author') }}</label>

                            <div class="col-md-6">
                                <input id="author" type="text" class="form-control" name="author" value="{{ $data->author }}" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="translator" class="col-sm-4 col-form-label text-md-right">{{ __('Translator') }}</label>

                            <div class="col-md-6">
                                <input id="translator" type="text" class="form-control" name="translator" value="{{ $data->translator }}" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="translator_url" class="col-sm-4 col-form-label text-md-right">{{ __('URL') }}</label>

                            <div class="col-md-6">
                                <input id="translator_url" type="text" class="form-control" name="translator_url" value="{{ $data->translator_url }}" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="chapter_url" class="col-sm-4 col-form-label text-md-right">{{ __('Chapter URL') }}</label>

                            <div class="col-md-6">
                                <input id="chapter_url" type="text" class="form-control" name="chapter_url" value="{{ $data->chapter_url }}" autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="alternative_url" class="col-sm-4 col-form-label text-md-right">{{ __('Alternative URL') }}</label>

                            <div class="col-md-6">
                                <input id="alternative_url" type="text" class="form-control" name="alternative_url" value="{{ $data->alternative_url }}" autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="unique_id" class="col-sm-4 col-form-label text-md-right">{{ __('Unique ID') }}</label>

                            <div class="col-md-6">
                                <input id="unique_id" type="text" class="form-control" name="unique_id" value="{{ $data->unique_id }}" autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="json" class="col-sm-4 col-form-label text-md-right">{{ __('JSON') }}</label>

                            <div class="col-md-6">
                                <input id="json" type="text" class="form-control" name="json" value="{{ $data->json }}" autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="group_id" class="col-sm-4 col-form-label text-md-right">{{ __('Translator Group') }}</label>

                            <div class="col-md-6">
                                <select name="group_id" id="group_id" class="form-control" required autofocus>
                                    @foreach ($groups as $item)
                                        <option value="{{ $item->id }}" {{ $data->group_id == $item->id ? "selected" : "" }}>{{ $item->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="language_id" class="col-sm-4 col-form-label text-md-right">{{ __('Language') }}</label>

                            <div class="col-md-6">
                                <select name="language_id" id="language_id" class="form-control" required autofocus>
                                    @foreach ($languages as $item)
                                        <option value="{{ $item->id }}" {{ $data->language_id == $item->id ? "selected" : "" }}>{{ $item->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="no_of_chapters" class="col-sm-4 col-form-label text-md-right">{{ __('No. of Chapters') }}</label>

                            <div class="col-md-6">
                                <input id="no_of_chapters" type="text" class="form-control" name="no_of_chapters" value="{{ $data->no_of_chapters }}" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="status" class="col-sm-4 col-form-label text-md-right">{{ __('Status') }}</label>

                            <div class="col-md-6">
                                <select class="form-control" name="status" id="status">
                                    <option value="0" {{ $data->status == 0 ? "selected" : "" }}>Active</option>
                                    <option value="1" {{ $data->status == 1 ? "selected" : "" }}>Completed</option>
                                    <option value="2" {{ $data->status == 2 ? "selected" : "" }}>Dropped</option>
                                    <option value="3" {{ $data->status == 3 ? "selected" : "" }}>Freeze</option>
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
                    <button type="button" class="btn btn-primary" onclick="saveNovel();">Save changes</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    <script type="text/javascript">
        var _novel_id = '{{ $data->id }}';

        $(function() {
            var table = $('#contentTable').DataTable({
                pageLength: 100,
                processing: true,
                serverSide: true,
                select: true,
                responsive: true,
                order: [[3, "asc"], [ 0, "asc" ], [1, "asc"]],
                ajax: '{!! route('novelchapters.datatables', ['id' => $data->id]) !!}',
                columns: [
                    {
                        data: 'book',
                        name: 'book'
                    }, {
                        data: 'chapter',
                        name: 'chapter'
                    }, {
                        data: 'label',
                        name: 'label'
                    }, {
                        data: 'status',
                        name: 'status',
                        render: function (data, type, row, meta) {
                            var d = "";

                            switch (data) {
                                case 0:
                                    d = "Not Complete";
                                    break;
                                case "1":
                                    d = "Complete";
                                    break;
                            }

                            return d;
                        }
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
                        text: 'Preview',
                        className: 'btn-sm',
                        action: function () {
                            var d = table.rows({ selected: true }).data()[0];
                            $("#chapter_title").html(d.label);

                            $.ajax({
                                method: "GET",
                                url: "/novelchapters/chapter_scraper/" + d.id + "?preview=1"
                            }).done(function(d) {
                                $("#chapter_body").html(d);

                                $('#chapterModalDiv').modal({});
                            });
                        },
                        enabled: false
                    }, {
                        text: 'Read',
                        className: 'btn-sm',
                        action: function () {
                            var d = table.rows({ selected: true }).data()[0];

                            $.ajax({
                                method: "GET",
                                url: "/novelchapters/" + d.id
                            }).done(function(d) {
                                $("#chapter_title").html(d.label);
                                $("#chapter_body").html(d.description);

                                $('#chapterModalDiv').modal({});
                            });
                        },
                        enabled: false
                    }, {
                        text: 'Download',
                        className: 'btn-sm',
                        action: function() {
                            var d = table.rows({ selected: true }).data();

                            $.each(d, function(a, b) {
                                $.ajax({
                                    method: "GET",
                                    url: "/novelchapters/chapter_scraper/" + b.id
                                });
                            });

                            $('#contentTable').DataTable().ajax.reload(null, false);
                            getNovel(_novel_id);
                        },
                        enabled: false
                    }, {
                        text: 'Create HTML',
                        className: 'btn-sm',
                        action: function() {
                            var d = table.rows({ selected: true }).data();
                            var chapters = [];

                            $.each(d, function(a, b) {
                                chapters.push(b.id);
                            });

                            $.ajax({
                                method: "POST",
                                data: { id: JSON.stringify(chapters) },
                                url: "/novelchapters/generate_chapter_file"
                            }).done(function() {
                                $('#contentTable').DataTable().ajax.reload(null, false);
                                getNovel(_novel_id);
                            });
                        },
                        enabled: false
                    }, {
                        text: 'Blacklist',
                        className: 'btn-sm',
                        action: function() {
                            var d = table.rows({ selected: true }).data()[0];

                            $.ajax({
                                method: "GET",
                                url: "/novelchapters/blacklist/" + d.id
                            }).done(function() {
                                $('#contentTable').DataTable().ajax.reload(null, false);
                                getNovel(_novel_id);
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

            table.on( 'select deselect', function () {
                var selectedRows = table.rows( { selected: true } ).count();

                table.button( 1 ).enable( selectedRows === 1 );
                table.button( 2 ).enable( selectedRows === 1 );
                table.button( 3 ).enable( selectedRows === 1 );
                table.button( 4 ).enable( selectedRows > 0 );
                table.button( 5 ).enable( selectedRows > 0 );
                table.button( 6 ).enable( selectedRows === 1 );
                table.button( 7 ).enable( selectedRows > 0 );
            } );

            getNovel(_novel_id);
        });

        function editNovel() {
            $('#novelModalDiv').modal({});
        }

        function saveNovel() {
            var form = $("#novelFormModal");
            var id = $("#novel_modal_id");
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
                    $('#novelModalDiv').modal('hide');

                    getNovel(_novel_id);
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
                    $('#novelModalDiv').modal('hide');

                    getNovel(_novel_id);
                });
            }
        }

        function saveForm() {
            var form = $("#formModal");
            var id = $("#id");

            if ( id.val() == 0 ) {
                $.ajax({
                    method: "POST",
                    url: "/novelchapters",
                    data: form.serialize()
                }).done(function() {
                    $('#contentTable').DataTable().ajax.reload(null, false);
                    $('#formModalDiv').modal('hide');
                });
            } else {
                $.ajax({
                    method: "PATCH",
                    url: "/novelchapters/" + id.val(),
                    data: form.serialize()
                }).done(function() {
                    $('#contentTable').DataTable().ajax.reload(null, false);
                    $('#formModalDiv').modal('hide');
                });
            }

            form.trigger('reset');
            id.val(0);

            getNovel(_novel_id);
        }

        function deleteItem(id) {
            $.ajax({
                method: "DELETE",
                url: "/novelchapters/" + id
            });
        }

        function getNovel(id) {
            $.ajax({
                method: "GET",
                url: "/novels/getnovel/" + id
            }).done(function(d) {
                var name_content = $("#name_content");
                name_content.empty().append(d.data.name);

                var author_content = $("#author_content");
                author_content.empty().append(d.data.author);

                var translator_content = $("#translator_content");
                translator_content.empty().append(d.data.translator);

                var group_content = $("#group_content");
                group_content.empty().append(d.data.group.label);

                var language_content = $("#language_content");
                language_content.empty().append(d.data.language.label);

                var chapters_content = $("#chapters_content");
                chapters_content.empty().append(d.current_chapters + ' (' + d.current_chapters_not_downloaded + ') / ' + d.data.no_of_chapters);

                var description_content = $("#description_content");
                description_content.empty().append(d.data.description);

                var progress_bar_content = $("#progress_bar_content");
                progress_bar_content.empty().attr('aria-valuenow', d.progress).append(d.progress + '%');

                var missing_duplicate_content = $("#missing_duplicate");
                missing_duplicate_content.empty();

                if ( Object.keys(d.missing_chapters).length > 0 ) {
                    var p = $("<p>").addClass('small').append('Missing Chapters: ');

                    $.each(d.missing_chapters, function(a, b) {
                        p.append(b + ' ');
                    });

                    missing_duplicate_content.append(p);
                }

                if ( d.duplicate_chapters.length > 0 ) {
                    var p = $("<p>").addClass('small').append('Duplicate Chapters: ');

                    $.each(d.duplicate_chapters, function(a, b) {
                        p.append(b.chapter + ' (' + b.book + ') ');
                    });

                    missing_duplicate_content.append(p);
                }
            });
        }

        function downloadNewChapters(id) {
            $.ajax({
                method: "GET",
                url: "/novelchapters/new_chapters_scraper/" + id
            }).done(function() {
                $('#contentTable').DataTable().ajax.reload(null, false);

                getNovel(_novel_id);
            });
        }

        function generateEpub(id) {
            $.ajax({
                method: "POST",
                data: { novel_id: id },
                url: "/novelchapters/generate_chapter_file"
            }).done(function() {
                $('#contentTable').DataTable().ajax.reload(null, false);
                getNovel(_novel_id);
            });
        }

        function getMetadata(id) {
            $.ajax({
                method: "GET",
                url: "/novels/getmetadata/" + id
            }).done(function() {
                $('#contentTable').DataTable().ajax.reload(null, false);
                getNovel(_novel_id);
            });
        }

        function downloadMissingChapters(id) {
            $.ajax({
                method: "GET",
                url: "/novelchapters/missing_chapters/" + id
            }).done(function() {
                $('#contentTable').DataTable().ajax.reload(null, false);

                getNovel(_novel_id);
            });
        }

        function convertQidianToPirateSite(id) {
            $.ajax({
                method: "GET",
                url: "/novelchapters/qidian_pirate/" + id
            }).done(function() {
                $('#contentTable').DataTable().ajax.reload(null, false);

                getNovel(_novel_id);
            });
        }

        function updateTOC(id) {
            $.ajax({
                method: "GET",
                url: "/novelchapters/scraper/" + id
            }).done(function() {
                $('#contentTable').DataTable().ajax.reload(null, false);

                getNovel(_novel_id);
            });
        }

        function deleteAll(id) {
            $.ajax({
                method: "GET",
                url: "/novelchapters/delete_all_chapters/" + id
            }).done(function() {
                $('#contentTable').DataTable().ajax.reload(null, false);

                getNovel(_novel_id);
            });
        }
    </script>
@endsection