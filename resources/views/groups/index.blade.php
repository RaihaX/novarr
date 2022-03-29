@extends('layouts.app')

@section('title', "Groups")

@section('content')
    <div class="row justify-content-center">
        <div class="col">
            <div class="card">
                <div class="card-body">
                    <table id="contentTable" class="table table-sm">
                        <thead>
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Label</th>
                                <th scope="col">URL</th>
                                <th scope="col">RSS</th>
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
                    <h5 class="modal-title">Group</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="formModal">
                        <input type="hidden" name="id" value="0" id="id">

                        <div class="form-group row">
                            <label for="label" class="col-sm-4 col-form-label text-md-right">{{ __('Name') }}</label>

                            <div class="col-md-6">
                                <input id="label" type="text" class="form-control" name="label" required autofocus>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="url" class="col-sm-4 col-form-label text-md-right">{{ __('URL') }}</label>

                            <div class="col-md-6">
                                <input id="url" type="text" class="form-control" name="url" required>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="rss" class="col-sm-4 col-form-label text-md-right">{{ __('RSS URL') }}</label>

                            <div class="col-md-6">
                                <input id="rss" type="text" class="form-control" name="rss" autofocus>
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
                ajax: '{!! route('groups.datatables') !!}',
                order: [[1, "asc"]],
                columns: [
                    { data: 'id', name: 'id' },
                    { data: 'label', name: 'label' },
                    { data: 'url', name: 'url' },
                    { data: 'rss', name: 'rss' }
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
                            $("#label").val(d.label);
                            $("#url").val(d.url);

                            $('#formModalDiv').modal({});
                        },
                        enabled: false
                    }, {
                        text: 'Delete',
                        className: 'btn-sm',
                        action: function () {
                            var d = table.rows({ selected: true }).data();

                            $.each(d, function(a, b) {
                                deleteItem(b.id);
                            });

                            $('#contentTable').DataTable().ajax.reload(null, false);
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
                table.button( 2 ).enable( selectedRows > 0 );
            } );
        });

        function saveForm() {
            var form = $("#formModal");
            var id = $("#id");
            var label = $("#label");
            var url = $("#url");
            var rss = $("#rss");

            if ( id.val() == 0 ) {
                $.ajax({
                    method: "POST",
                    url: "/groups",
                    data: {
                        "label": label.val(),
                        "url": url.val(),
                        "rss": rss.val()
                    }
                }).done(function() {
                    $('#contentTable').DataTable().ajax.reload(null, false);
                    $('#formModalDiv').modal('hide');
                });
            } else {
                $.ajax({
                    method: "PATCH",
                    url: "/groups/" + id.val(),
                    data: {
                        "label": label.val(),
                        "url": url.val(),
                        "rss": rss.val()
                    }
                }).done(function() {
                    $('#contentTable').DataTable().ajax.reload(null, false);
                    $('#formModalDiv').modal('hide');
                });
            }

            form.trigger('reset');
        }

        function deleteItem(id) {
            $.ajax({
                method: "DELETE",
                url: "/groups/" + id
            });
        }
    </script>
@endsection