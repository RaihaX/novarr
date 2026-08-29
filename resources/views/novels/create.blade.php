@extends('layouts.app')

@section('content')
<div class="novel-form-column">
    <a href="{{ route('novels.index') }}" class="back-link">
        <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Back to novels
    </a>

    <div class="page-head">
        <div class="page-head-titles">
            <span class="page-head-kicker">Manual entry</span>
            <h1 class="page-title mb-0">Add novel</h1>
        </div>
        <div class="page-head-actions">
            <a href="{{ route('novels.discover') }}" class="btn btn-secondary">Browse sources</a>
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

    <form method="POST" action="{{ route('novels.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="novel-form">
            <div class="novel-form-section">
                <div class="novel-form-section-head">
                    <span class="novel-form-section-title">Identity</span>
                    <span class="novel-form-section-note">How the novel appears across the library</span>
                </div>
                <div class="form-grid">
                    <div class="field field-full">
                        <label for="name" class="form-label">Name <span class="field-required">*</span></label>
                        <input type="text" name="name" id="name" class="form-control" required value="{{ old('name') }}">
                    </div>
                    <div class="field field-half">
                        <label for="author" class="form-label">Author</label>
                        <input type="text" name="author" id="author" class="form-control" value="{{ old('author') }}">
                    </div>
                    <div class="field field-half">
                        <label for="image" class="form-label">Cover image</label>
                        <input type="file" name="image" id="image" class="form-control" accept="image/*">
                    </div>
                    <div class="field field-full">
                        <label for="description" class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="4">{{ old('description') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="novel-form-section">
                <div class="novel-form-section-head">
                    <span class="novel-form-section-title">Tracking</span>
                    <span class="novel-form-section-note">What the scraper should expect</span>
                </div>
                <div class="form-grid">
                    <div class="field field-third">
                        <label for="status" class="form-label">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="0">Active</option>
                            <option value="1">Completed</option>
                        </select>
                    </div>
                    <div class="field field-third">
                        <label for="no_of_chapters" class="form-label">Total chapters</label>
                        <input type="number" name="no_of_chapters" id="no_of_chapters" class="form-control" value="0" min="0">
                    </div>
                    <div class="field field-third">
                        <label class="form-label">Tags</label>
                        @include('partials.tag-picker', ['selectedIds' => []])
                    </div>
                    <div class="field field-full">
                        <label for="translator_url" class="form-label">Translator URL</label>
                        <input type="url" name="translator_url" id="translator_url" class="form-control" value="{{ old('translator_url') }}" placeholder="https://…">
                        <div class="form-text">The novel's page on the source site — used to scrape its table of contents.</div>
                    </div>
                </div>
            </div>

            <div class="novel-form-actions">
                <button type="submit" class="btn btn-primary">Create novel</button>
                <a href="{{ route('novels.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </div>
    </form>

    <div class="novel-form-aside">
        <span>Prefer automatic metadata? The <strong>Create Novel</strong> command fetches the title, author and cover from a source URL.</span>
        <a href="{{ route('commands.form', 'create_novel') }}" class="btn btn-secondary">Create Novel command</a>
    </div>
</div>
@endsection
