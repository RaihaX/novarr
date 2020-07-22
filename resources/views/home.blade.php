@extends('layouts.app')

@section('content')
    <div class="row justify-content-center">
        <div class="col">
            <div class="card">
                <div class="card-header">Latest Updates</div>

                <div class="card-body">
                    <table id="latestChaptersTable" class="table table-sm">
                        <thead>
                        <tr>
                            <th scope="col">Novel</th>
                            <th scope="col">Book</th>
                            <th scope="col">Chapter</th>
                            <th scope="col">Label</th>
                            <th scope="col">Created At</th>
                        </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    <script type="text/javascript">
        $(function() {
            var latestChaptersTable = $('#latestChaptersTable').DataTable({
                processing: true,
                serverSide: true,
                select: true,
                responsive: true,
                order: [[4, "desc"]],
                ajax: '{!! route('home.datatables_latest_chapters') !!}',
                columns: [
                    {
                        data: 'name',
                        name: 'name',
                        render: function (data, type, row, meta) {
                            return '<a href="novels/' + row.novel_id + '">' + data + '</a>';
                        }
                    }, {
                        data: 'book',
                        name: 'book'
                    }, {
                        data: 'chapter',
                        name: 'chapter'
                    }, {
                        data: 'label',
                        name: 'label'
                    }, {
                        data: 'download_date',
                        name: 'download_date',
                        render: function(data, type, row, meta) {
                            if ( data === null ) {
                                return moment(row.created_at).format("DD-MM-YYYY hh:mm A");
                            } else {
                                return moment(data).format("DD-MM-YYYY hh:mm A");
                            }
                        }
                    }
                ],
                lengthChange: false,
                buttons: [
                    {
                        text: 'Reload',
                        className: 'btn-sm',
                        action: function ( e, dt, node, config ) {
                            dt.ajax.reload( null, false );
                        }
                    }
                ],
                initComplete: function () {
                    latestChaptersTable.buttons().container().appendTo( '#latestChaptersTable_wrapper .col-md-6:eq(0)' );
                }
            });
        });
    </script>
@endsection