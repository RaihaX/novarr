@extends('layouts.app')

{{-- A reader uploads an audiobook (AudiobookUploadController::create()):
     resources/js/audiobook-upload.js sends it in chunks through
     /api/v1/audiobook-uploads; the owner reviews it before it is added. --}}

@section('title', 'Upload an audiobook')

@section('content')
<div class="novel-form-column abu-page">
    <a href="{{ $book ? route('novels.show', $book->id) : route('audiobooks.index') }}" class="back-link">
        <x-icon name="chevron-left" :size="14" :stroke="1.5" /> {{ $book ? $book->name : 'Audiobooks' }}
    </a>

    <div class="page-head">
        <div class="page-head-titles">
            <span class="page-head-kicker">Audiobooks</span>
            <h1 class="page-title mb-0">Upload an audiobook</h1>
            <p class="page-head-sub mb-0">
                @if($book)
                    Send the audiobook of <strong>{{ $book->name }}</strong>{{ $book->author ? ' by ' . $book->author : '' }}.
                @else
                    Send an audiobook you have. The owner matches it to its book.
                @endif
                The owner listens to a sample and adds it to the library; you’ll get an email when it is there.
            </p>
        </div>
    </div>

    @if($hasAudiobook)
        <div class="alert alert-secondary" role="status">This book already has an audiobook. Upload another one only if it is a better recording (the owner decides which to keep).</div>
    @endif

    <div id="audiobookUpload" class="abu" data-config='@json($config)'>
        <div class="alert alert-warning abu-resume" role="status" data-abu-resume-banner hidden>
            <span data-abu-resume-text></span>
            <button type="button" class="btn btn-sm btn-ghost" data-abu-resume-forget>Cancel it</button>
        </div>

        <div class="abu-drop" data-abu-drop>
            <x-icon name="headphones" :size="22" />
            <p class="abu-drop-title mb-1">Drag the audiobook’s files or its folder here</p>
            <p class="form-text mb-2">
                One file ({{ implode(' ', array_map(fn($e) => '.' . $e, $config['formats'])) }}) or several: a folder of MP3s is fine, its cover picture comes along.
                Up to {{ \App\Audiobooks\AudiobookUploads::human($limits['max_file']) }} a file, {{ \App\Audiobooks\AudiobookUploads::human($limits['max_total']) }} in all.
            </p>
            <div class="abu-pickers">
                <label class="btn btn-secondary btn-sm mb-0">
                    Choose files
                    <input type="file" class="visually-hidden" multiple data-abu-files
                           accept="{{ implode(',', array_map(fn($e) => '.' . $e, array_merge($config['formats'], $config['images']))) }},audio/*">
                </label>
                <label class="btn btn-secondary btn-sm mb-0">
                    Choose a folder
                    <input type="file" class="visually-hidden" webkitdirectory directory multiple data-abu-folder>
                </label>
            </div>
            <p class="abu-summary mb-0" data-abu-summary aria-live="polite"></p>
        </div>

        <div class="alert alert-danger" role="alert" data-abu-errors hidden></div>

        <div class="field field-full abu-note">
            <label for="abuNote" class="form-label">Note for the owner</label>
            <textarea id="abuNote" class="form-control" rows="2" maxlength="1000" data-abu-note
                      placeholder="Optional: the narrator, the edition, which book it is if the files don’t say…"></textarea>
        </div>

        <div class="abu-actions">
            <button type="button" class="btn btn-primary" data-abu-start hidden>Upload</button>
            <button type="button" class="btn btn-secondary" data-abu-pause hidden>Pause</button>
            <button type="button" class="btn btn-primary" data-abu-resume hidden>Resume</button>
            <button type="button" class="btn btn-ghost" data-abu-cancel hidden>Cancel upload</button>
        </div>

        <p class="abu-warning" data-abu-warning hidden role="status">Don’t close this tab while it uploads. If it stops, it carries on from where it was.</p>
        <p class="abu-status" data-abu-status role="status" aria-live="polite"></p>

        <section class="abu-progress" data-abu-progress hidden aria-label="Upload progress">
            <progress class="abu-total-bar" max="100" value="0" data-abu-total-bar>0%</progress>
            <p class="abu-total-text mono-num mb-2" data-abu-total-text></p>
            <ol class="abu-files" data-abu-list></ol>
        </section>

        <div class="alert alert-success abu-done" role="status" data-abu-done hidden>
            <p class="mb-1" data-abu-done-text></p>
            <a href="{{ $config['requests_url'] }}">See it under My requests</a>
        </div>
    </div>
</div>
@endsection
