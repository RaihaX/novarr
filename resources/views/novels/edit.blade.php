@extends('layouts.app')

@section('content')
<div class="mb-3">
    <a href="{{ route('novels.show', $novel->id) }}" class="btn btn-outline-secondary btn-sm">&larr; Back to {{ Str::limit($novel->name, 40) }}</a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">Edit Novel</h4>
            </div>
            <div class="card-body">
                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('novels.update', $novel->id) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $novel->name) }}" required>
                        <div class="form-text">Used to build NovelUpdates/NovelBin URLs for metadata — fix typos here, then run Refresh Metadata.</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="author" class="form-label">Author</label>
                            <input type="text" name="author" id="author" class="form-control" value="{{ old('author', $novel->author) }}">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="no_of_chapters" class="form-label">Total Chapters</label>
                            <input type="number" name="no_of_chapters" id="no_of_chapters" class="form-control" value="{{ old('no_of_chapters', $novel->no_of_chapters) }}" min="0">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="group_id" class="form-label">Group</label>
                            <select name="group_id" id="group_id" class="form-select">
                                @foreach($groups as $group)
                                    <option value="{{ $group->id }}" @selected(old('group_id', $novel->group_id) == $group->id)>{{ $group->label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="translator_url" class="form-label">Source URL <span class="text-muted">(translator_url — used for TOC scraping)</span></label>
                        <input type="url" name="translator_url" id="translator_url" class="form-control" value="{{ old('translator_url', $novel->translator_url) }}">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="chapter_url" class="form-label">Chapter URL base</label>
                            <input type="url" name="chapter_url" id="chapter_url" class="form-control" value="{{ old('chapter_url', $novel->chapter_url) }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="alternative_url" class="form-label">Alternative URL base</label>
                            <input type="url" name="alternative_url" id="alternative_url" class="form-control" value="{{ old('alternative_url', $novel->alternative_url) }}">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Synopsis</label>
                        <textarea name="description" id="description" class="form-control" rows="5">{{ old('description', $novel->description) }}</textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Tags</label>
                        @include('partials.tag-picker', ['selectedIds' => $novel->tags->pluck('id')->all()])
                    </div>

                    <div class="mb-4">
                        <label for="image" class="form-label">Replace cover image <span class="text-muted">(optional)</span></label>
                        <input type="file" name="image" id="image" class="form-control" accept="image/*">
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Save changes</button>
                        <a href="{{ route('novels.show', $novel->id) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
