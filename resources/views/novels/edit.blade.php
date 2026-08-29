@extends('layouts.app')

@section('content')
<div class="novel-form-column">
    <a href="{{ route('novels.show', $novel->id) }}" class="back-link">
        <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Back to {{ Str::limit($novel->name, 40) }}
    </a>

    <div class="page-head">
        <div class="page-head-titles">
            <span class="page-head-kicker">Novel #{{ $novel->id }}</span>
            <h1 class="page-title mb-0">Edit novel</h1>
        </div>
        <div class="page-head-actions">
            <a href="{{ route('novels.show', $novel->id) }}" class="btn btn-secondary">View novel</a>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('novels.update', $novel->id) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="novel-form">
            <div class="novel-form-section">
                <div class="novel-form-section-head">
                    <span class="novel-form-section-title">Identity</span>
                    <span class="novel-form-section-note">Fix titles here, then run Refresh Metadata</span>
                </div>
                <div class="form-grid">
                    <div class="field field-full">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $novel->name) }}" required>
                        <div class="form-text">Used to build NovelUpdates / NovelArrow URLs for metadata.</div>
                    </div>
                    <div class="field field-half">
                        <label for="author" class="form-label">Author</label>
                        <input type="text" name="author" id="author" class="form-control" value="{{ old('author', $novel->author) }}">
                    </div>
                    <div class="field field-quarter">
                        <label for="no_of_chapters" class="form-label">Total chapters</label>
                        <input type="number" name="no_of_chapters" id="no_of_chapters" class="form-control" value="{{ old('no_of_chapters', $novel->no_of_chapters) }}" min="0">
                    </div>
                    <div class="field field-quarter">
                        <label for="group_id" class="form-label">Group</label>
                        <select name="group_id" id="group_id" class="form-select">
                            @foreach($groups as $group)
                                <option value="{{ $group->id }}" @selected(old('group_id', $novel->group_id) == $group->id)>{{ $group->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field field-full">
                        <label for="description" class="form-label">Synopsis</label>
                        <textarea name="description" id="description" class="form-control" rows="5">{{ old('description', $novel->description) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="novel-form-section">
                <div class="novel-form-section-head">
                    <span class="novel-form-section-title">Sources</span>
                    <span class="novel-form-section-note">Where the scraper reads from</span>
                </div>
                <div class="form-grid">
                    <div class="field field-full">
                        <label for="translator_url" class="form-label">Source URL <span class="field-hint">translator_url — used for TOC scraping</span></label>
                        <input type="url" name="translator_url" id="translator_url" class="form-control" value="{{ old('translator_url', $novel->translator_url) }}">
                    </div>
                    <div class="field field-full">
                        <label for="novelupdates_url" class="form-label">NovelUpdates URL <span class="field-hint">optional — overrides metadata lookup for aliased titles</span></label>
                        <input type="url" name="novelupdates_url" id="novelupdates_url" class="form-control" value="{{ old('novelupdates_url', $novel->novelupdates_url) }}" placeholder="https://www.novelupdates.com/series/…">
                        <div class="form-text">Set this when the title differs from NovelUpdates. Leave blank to auto-resolve, then run Refresh Metadata.</div>
                    </div>
                    <div class="field field-half">
                        <label for="chapter_url" class="form-label">Chapter URL base</label>
                        <input type="url" name="chapter_url" id="chapter_url" class="form-control" value="{{ old('chapter_url', $novel->chapter_url) }}">
                    </div>
                    <div class="field field-half">
                        <label for="alternative_url" class="form-label">Alternative URL base</label>
                        <input type="url" name="alternative_url" id="alternative_url" class="form-control" value="{{ old('alternative_url', $novel->alternative_url) }}">
                    </div>
                </div>
            </div>

            <div class="novel-form-section">
                <div class="novel-form-section-head">
                    <span class="novel-form-section-title">Presentation</span>
                    <span class="novel-form-section-note">Tags and cover art</span>
                </div>
                <div class="form-grid">
                    <div class="field field-half">
                        <label class="form-label">Tags</label>
                        @include('partials.tag-picker', ['selectedIds' => $novel->tags->pluck('id')->all()])
                    </div>
                    <div class="field field-half">
                        <label for="image" class="form-label">Replace cover image <span class="field-hint">optional</span></label>
                        <input type="file" name="image" id="image" class="form-control" accept="image/*">
                    </div>
                </div>
            </div>

            <div class="novel-form-actions">
                <button type="submit" class="btn btn-primary">Save changes</button>
                <a href="{{ route('novels.show', $novel->id) }}" class="btn btn-secondary">Cancel</a>
                <span class="novel-form-actions-note">ID {{ $novel->id }}</span>
            </div>
        </div>
    </form>
</div>
@endsection
