@extends('layouts.app')

@section('content')
<div class="mb-3">
    <a href="{{ route('novels.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Back to Novels</a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">Add Novel</h4>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('novels.store') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="name" class="form-control" required value="{{ old('name') }}">
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="author" class="form-label">Author</label>
                            <input type="text" name="author" id="author" class="form-control" value="{{ old('author') }}">
                        </div>
                        <div class="col-md-6">
                            <label for="external_url" class="form-label">Source URL</label>
                            <input type="url" name="external_url" id="external_url" class="form-control" value="{{ old('external_url') }}" placeholder="https://...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="4">{{ old('description') }}</textarea>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="0">Active</option>
                                <option value="1">Completed</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="no_of_chapters" class="form-label">Total Chapters</label>
                            <input type="number" name="no_of_chapters" id="no_of_chapters" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-md-4">
                            <label for="image" class="form-label">Cover Image</label>
                            <input type="file" name="image" id="image" class="form-control" accept="image/*">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="translator_url" class="form-label">Translator URL</label>
                        <input type="url" name="translator_url" id="translator_url" class="form-control" value="{{ old('translator_url') }}" placeholder="https://...">
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Create Novel</button>
                        <a href="{{ route('novels.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0">Or use the command</h6>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">You can also create a novel via the <strong>Create Novel</strong> command, which auto-fetches metadata and cover from the source URL.</p>
                <a href="{{ route('commands.form', 'create_novel') }}" class="btn btn-sm btn-outline-primary">Go to Create Novel Command</a>
            </div>
        </div>
    </div>
</div>
@endsection
