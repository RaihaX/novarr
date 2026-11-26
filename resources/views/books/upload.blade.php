@extends('layouts.app')

{{-- Any reader sends an ePub; the owner matches it to the right book and approves it (BookUploadController). --}}

@section('title', 'Upload a book')

@section('content')
<div class="novel-form-column">
    <a href="{{ route('requests.index') }}" class="back-link">
        <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Back to requests
    </a>

    <div class="page-head">
        <div class="page-head-titles">
            <span class="page-head-kicker">Books</span>
            <h1 class="page-title mb-0">Upload a book</h1>
            <p class="page-head-sub mb-0">Send an ePub you have. The owner checks it is the right book, fixes its details and cover, and adds it to the library. You’ll hear when it’s ready to read.</p>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            @foreach($errors->all() as $error)
                <p class="mb-0">{{ $error }}</p>
            @endforeach
            @if(session('in_library'))
                <p class="mb-0 mt-1"><a href="{{ session('in_library') }}">Open it in the library</a></p>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('books.upload.store') }}" enctype="multipart/form-data" class="book-upload-form">
        @csrf
        <div class="novel-form">
            <div class="novel-form-section">
                <div class="form-grid">
                    <div class="field field-full">
                        <label for="epub" class="form-label">ePub files <span class="field-required">*</span></label>
                        <input type="file" name="epub[]" id="epub" class="form-control" required multiple
                               accept=".epub,application/epub+zip" aria-describedby="epubHelp">
                        <p class="form-text mb-0" id="epubHelp">One or several .epub files (up to {{ $maxFiles }} at a time, {{ $maxMb }} MB each). Files from Calibre, publishers and most stores (without DRM) work. A file with wrong or missing details is fine: the owner matches it.</p>
                    </div>
                    <div class="field field-full">
                        <label for="note" class="form-label">Note for the owner</label>
                        <textarea name="note" id="note" class="form-control" rows="2" maxlength="500"
                                  placeholder="Optional: the real title or author if the file gets it wrong, the series, the edition…">{{ old('note') }}</textarea>
                    </div>
                </div>
            </div>
            <div class="novel-form-section novel-form-actions">
                <button type="submit" class="btn btn-primary" data-busy-label="Uploading…">Send to the owner</button>
                <span class="form-text" id="epubCount" aria-live="polite"></span>
            </div>
        </div>
    </form>
</div>

<script>
    // How many files are chosen, on the button's side.
    // and whether they fit in one upload (the web server's limit for one request).
    const maxBatch = {{ (int) config('novarr.books.max_batch_mb', 48) }} * 1024 * 1024;
    document.getElementById('epub')?.addEventListener('change', (event) => {
        const files = [...event.currentTarget.files];
        const total = files.reduce((sum, f) => sum + f.size, 0);
        const button = event.currentTarget.form.querySelector('[type=submit]');
        const tooBig = total > maxBatch;
        button.disabled = tooBig;
        document.getElementById('epubCount').textContent = tooBig
            ? `These ${files.length} files are ${(total / 1048576).toFixed(0)} MB together: send them in smaller batches (up to ${maxBatch / 1048576} MB at a time).`
            : (files.length > 1 ? `${files.length} books chosen` : '');
    });
    // A large file takes a while: say so, and stop a second submit.
    document.querySelector('.book-upload-form')?.addEventListener('submit', (event) => {
        const button = event.currentTarget.querySelector('[data-busy-label]');
        if (button) {
            button.disabled = true;
            button.textContent = button.dataset.busyLabel;
        }
    });
</script>
@endsection
