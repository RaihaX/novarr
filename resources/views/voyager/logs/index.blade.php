@extends('voyager::master')

@section('page_title', 'Log Files')

@section('page_header')
    <div class="container-fluid">
        <h1 class="page-title">
            <i class="voyager-file-text"></i> Log Files
        </h1>
    </div>
@stop

@section('content')
    <div class="page-content browse container-fluid">
        @include('voyager::alerts')

        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-bordered">
                    <div class="panel-heading">
                        <h3 class="panel-title">Available Log Files</h3>
                    </div>
                    <div class="panel-body">
                        @if(count($logFiles) > 0)
                            <div class="table-responsive voyager-table-scroll">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>File Name</th>
                                            <th>Size</th>
                                            <th>Last Modified</th>
                                            <th class="actions text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($logFiles as $file)
                                            <tr>
                                                <td>
                                                    <i class="voyager-file-text"></i>
                                                    {{ $file['name'] }}
                                                </td>
                                                <td>{{ $file['size'] }}</td>
                                                <td>{{ $file['modified'] }}</td>
                                                <td class="no-sort no-click text-right" id="bread-actions">
                                                    <a href="{{ route('voyager.logs.show', $file['name']) }}"
                                                       title="View"
                                                       class="btn btn-sm btn-warning view">
                                                        <i class="voyager-eye"></i> <span class="hidden-xs hidden-sm">View</span>
                                                    </a>
                                                    <a href="{{ route('voyager.logs.download', $file['name']) }}"
                                                       title="Download"
                                                       class="btn btn-sm btn-primary">
                                                        <i class="voyager-download"></i> <span class="hidden-xs hidden-sm">Download</span>
                                                    </a>
                                                    <button type="button"
                                                            title="Delete"
                                                            class="btn btn-sm btn-danger delete-log"
                                                            data-filename="{{ $file['name'] }}">
                                                        <i class="voyager-trash"></i> <span class="hidden-xs hidden-sm">Delete</span>
                                                    </button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="alert alert-info">
                                <i class="voyager-info-circled"></i> No log files found.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Delete Confirmation Modal --}}
    <div class="modal modal-danger fade" tabindex="-1" id="delete-modal" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title">
                        <i class="voyager-trash"></i> Delete Log File
                    </h4>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="delete-filename"></strong>?</p>
                    <p class="text-warning">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirm-delete">
                        <i class="voyager-trash"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
@stop

@section('javascript')
    <script>
        $(document).ready(function() {
            var deleteFilename = '';

            $('.delete-log').on('click', function() {
                deleteFilename = $(this).data('filename');
                $('#delete-filename').text(deleteFilename);
                $('#delete-modal').modal('show');
            });

            $('#confirm-delete').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).html('<i class="voyager-refresh"></i> Deleting...');

                $.ajax({
                    url: '{{ url("admin/logs") }}/' + encodeURIComponent(deleteFilename),
                    type: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        if (response.success) {
                            toastr.success(response.message);
                            $('#delete-modal').modal('hide');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            toastr.error(response.message);
                            btn.prop('disabled', false).html('<i class="voyager-trash"></i> Delete');
                        }
                    },
                    error: function(xhr) {
                        var message = xhr.responseJSON ? xhr.responseJSON.message : 'An error occurred';
                        toastr.error(message);
                        btn.prop('disabled', false).html('<i class="voyager-trash"></i> Delete');
                    }
                });
            });
        });
    </script>
@stop
