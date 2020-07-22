@extends('layouts.app')

@section('content')
    <div class="row justify-content-center">
        <div class="col">
            <div class="card">
                <div class="card-header">Mangas</div>

                <div class="card-body">
                    <table id="contentTable" class="table table-sm">
                        <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Author</th>
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

                        <div class="form-group row">
                            <label for="description" class="col-sm-4 col-form-label text-md-right">{{ __('Description') }}</label>

                            <div class="col-md-6">
                                <textarea id="description" class="form-control" name="description"></textarea>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="author" class="col-sm-4 col-form-label text-md-right">{{ __('Author') }}</label>

                            <div class="col-md-6">
                                <input id="author" type="text" class="form-control" name="author" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="translator_url" class="col-sm-4 col-form-label text-md-right">{{ __('URL') }}</label>

                            <div class="col-md-6">
                                <input id="translator_url" type="text" class="form-control" name="translator_url" required autofocus>
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
                select: true,
                order: [[0, "asc"]],
                ajax: '{!! route('mangas.datatables') !!}',
                columns: [
                    {
                        data: 'name',
                        name: 'name',
                        render: function (data, type, row, meta) {
                            return '<a href="mangas/' + row.id + '">' + data + '</a>';
                        }
                    }, {
                        data: 'author', name: 'author'
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
                            $("#url").val(d.url);
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
                    url: "/mangas",
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
                    url: "/mangas/" + id.val(),
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