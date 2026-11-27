@extends('layouts.app')

{{-- The owner's review of an audiobook a reader uploaded
     (AudiobookUploadController::review()): what the files say, a sample of
     each, the suggested book (or another, searched), duplicates, and
     Approve / Decline. --}}

@php
    $pending = $upload->status === \App\AudiobookUpload::STATUS_PENDING;
    $duration = !empty($probe['duration_ms']) ? \App\Audiobooks\AudiobookLibrary::humanDuration((int) $probe['duration_ms']) : null;
    $chapters = (array) ($probe['chapters'] ?? []);
    $fmt = function (int $ms) {
        $s = intdiv(max(0, $ms), 1000);
        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    };
@endphp

@section('title', 'Review ' . $upload->displayTitle())

@section('content')
<div class="book-review abu-review">
    <div class="book-review-top">
        <a href="{{ route('downloads.index', ['tab' => 'attention']) }}" class="back-link">
            <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Needs you
        </a>
        @if($pending && $queueLeft > 1)
            <span class="book-review-queue">{{ $queueLeft }} audiobooks waiting</span>
        @endif
        @if(session('success_link'))
            <a href="{{ session('success_link') }}" class="book-review-queue">Open the audiobook you just added</a>
        @endif
    </div>

    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            @foreach($errors->all() as $error)<p class="mb-0">{{ $error }}</p>@endforeach
        </div>
    @endif

    @if(!$pending)
        <div class="alert {{ $upload->status === 'approved' ? 'alert-success' : 'alert-secondary' }}" role="status" id="uploadDecided">
            @switch($upload->status)
                @case('approved')
                    This audiobook is in the library.
                    @if($imported && !$imported->trashed()) <a href="{{ route('audiobooks.show', $imported->id) }}">Open “{{ $imported->title }}”</a>@endif
                    @break
                @case('declined')
                    This upload was declined{{ $upload->decline_reason ? ': ' . $upload->decline_reason : '.' }} Its files were deleted.
                    @break
                @case('failed')
                    This upload failed: {{ $upload->error ?: 'the files could not be used.' }}
                    @break
                @default
                    This upload is not finished yet ({{ strtolower($upload->statusLabel()) }}).
            @endswitch
        </div>
    @endif

    @if($pending && $duplicate)
        <section class="book-existing" aria-labelledby="duplicateTitle" id="uploadDuplicate">
            <div class="book-existing-main">
                <h2 class="book-existing-title" id="duplicateTitle">Already in the library</h2>
                <p class="mb-2">These are the same files as <a href="{{ route('audiobooks.show', $duplicate->id) }}"><strong>{{ $duplicate->title }}</strong></a>{{ $duplicate->novel ? ' (the audiobook of “' . $duplicate->novel->name . '”)' : '' }}{{ $duplicate->trashed() ? ', which was removed from the library' : '' }}.</p>
                @unless($duplicate->trashed())
                    <form method="POST" action="{{ route('audiobooks.uploads.use_existing', $upload->id) }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="audiobook_id" value="{{ $duplicate->id }}">
                        <button type="submit" class="btn btn-primary btn-sm">Use the existing one</button>
                    </form>
                @endunless
                <span class="form-text">{{ $uploader?->name ?? 'The reader' }} is told it is in the library, and these files are deleted.</span>
            </div>
        </section>
    @endif

    {{-- The files at a glance --}}
    <section class="book-file-strip" aria-labelledby="fileTitle">
        <div class="cover book-file-cover">
            @if($cover)<img src="{{ $cover }}" alt="The files’ cover">@endif
        </div>
        <div class="book-file-main">
            <span class="page-head-kicker">Uploaded by {{ $uploader?->name ?? 'someone' }}{{ $upload->created_at ? ' · ' . $upload->created_at->diffForHumans() : '' }}</span>
            <h1 class="book-file-title" id="fileTitle">{{ $probe['title'] ?? $upload->displayTitle() }}</h1>
            <p class="book-file-facts">
                {{ $probe['author'] ?? 'No author in the files' }}
                · {{ !empty($probe['narrator']) ? 'read by ' . $probe['narrator'] : 'no narrator in the files' }}
                @if($duration) · {{ $duration }} @endif
                · {{ (int) ($probe['parts'] ?? $audioFiles->count()) }} {{ \Illuminate\Support\Str::plural('file', (int) ($probe['parts'] ?? $audioFiles->count())) }}
                · {{ (int) ($probe['chapter_count'] ?? count($chapters)) }} {{ \Illuminate\Support\Str::plural('chapter', (int) ($probe['chapter_count'] ?? count($chapters))) }}{{ !empty($probe['embedded_chapters']) ? '' : ' (one per file)' }}
                · {{ \App\Audiobooks\AudiobookUploads::human((int) $upload->total_bytes) }}
                @if(!empty($probe['year'])) · {{ $probe['year'] }} @endif
            </p>
            @if($upload->note)
                <p class="book-file-note"><span class="req-row-label">{{ $uploader?->name ? $uploader->name . '’s' : 'Their' }} note:</span> {{ $upload->note }}</p>
            @endif

            @if($pending && $audioFiles->isNotEmpty())
                <div class="abu-sample">
                    <span class="req-row-label">Sample (30 seconds):</span>
                    @php $first = $audioFiles->first(); @endphp
                    <audio controls preload="none" src="{{ route('audiobooks.uploads.sample', [$upload->id, $first['index']]) }}#t=0,30" id="uploadSample" aria-label="Sample of {{ $first['name'] }}"></audio>
                </div>
            @endif

            <details class="book-file-inspect">
                <summary>Files and chapters</summary>
                <div class="book-file-inspect-body">
                    <ol class="book-file-labels" aria-label="Files">
                        @foreach($audioFiles as $f)
                            <li>
                                {{ $f['name'] }} <span class="text-muted">({{ \App\Audiobooks\AudiobookUploads::human((int) $f['size']) }})</span>
                                @if($pending && !empty($f['path']))
                                    · <a href="{{ route('audiobooks.uploads.sample', [$upload->id, $f['index']]) }}" target="_blank" rel="noopener">play</a>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    @if($chapters !== [])
                        <ol class="book-file-labels" aria-label="Chapters">
                            @foreach(array_slice($chapters, 0, 60) as $c)
                                <li><span class="mono-num">{{ $fmt((int) $c['start_ms']) }}</span> {{ $c['title'] }}</li>
                            @endforeach
                            @if(count($chapters) > 60)<li class="text-muted">… and {{ count($chapters) - 60 }} more</li>@endif
                        </ol>
                    @endif
                    @if(!empty($probe['description']))
                        <blockquote class="book-file-excerpt">{{ \Illuminate\Support\Str::limit($probe['description'], 600) }}</blockquote>
                    @endif
                </div>
            </details>
        </div>
    </section>

    @if($pending)
    <div class="book-review-work">
        <section class="book-review-panel" aria-labelledby="whichBookTitle">
            <h2 class="req-section-title" id="whichBookTitle">Which book is it?</h2>
            @if($suggested)
                <p class="form-text mb-2">
                    {{ $upload->novel_id ? 'Uploaded for' : 'Novarr suggests' }}
                    <a href="{{ route('novels.show', $suggested->id) }}"><strong>{{ $suggested->name }}</strong></a>{{ $suggested->author ? ' · ' . $suggested->author : '' }}.
                    @if($chosen && $chosen->id !== $suggested->id)
                        <a href="{{ route('audiobooks.uploads.review', [$upload->id, 'novel_id' => $suggested->id]) }}">Use it</a>
                    @endif
                </p>
            @else
                <p class="form-text mb-2">No book in the library has this title and author. Search for it, or add it standalone.</p>
            @endif

            <form method="GET" action="{{ route('audiobooks.uploads.review', $upload->id) }}" class="book-review-search" role="search">
                <input type="search" name="q" class="form-control" placeholder="Title or author" aria-label="Book to search for" value="{{ $q }}">
                <button type="submit" class="btn btn-secondary">Search</button>
            </form>
            @if($searched)
                <ul class="book-review-matches" aria-label="Books found">
                    @forelse($results as $r)
                        <li class="book-match {{ $chosen && $chosen->id === $r->id ? 'is-chosen' : '' }}">
                            <div class="book-match-body">
                                <span class="book-match-title">{{ $r->name }}</span>
                                <span class="book-match-meta">{{ $r->author ?: 'Unknown author' }}{{ isset($linked[$r->id]) ? ' · has an audiobook' : '' }}</span>
                            </div>
                            <a href="{{ route('audiobooks.uploads.review', [$upload->id, 'novel_id' => $r->id, 'q' => $q]) }}" class="btn btn-sm btn-ghost">Use this</a>
                        </li>
                    @empty
                        <li class="book-match"><span class="book-match-meta">No book found. Try fewer words, or the author’s surname.</span></li>
                    @endforelse
                </ul>
            @endif
        </section>

        <aside class="book-review-panel book-review-apply" aria-labelledby="decideTitle">
            <h2 class="req-section-title" id="decideTitle">Add it</h2>
            @if($chosen)
                <p class="mb-2">As the audiobook of <strong>{{ $chosen->name }}</strong>{{ $chosen->author ? ' · ' . $chosen->author : '' }}.</p>
                @if($bookAudiobook)
                    <div class="alert alert-warning" role="status" id="bookHasAudiobook">
                        “{{ $chosen->name }}” already has an audiobook: <a href="{{ route('audiobooks.show', $bookAudiobook->id) }}">{{ $bookAudiobook->title }}</a>{{ $bookAudiobook->trashed() ? ' (removed)' : '' }}.
                        Replace it with this one (the old one is removed from the library, its files kept), or keep the existing one.
                    </div>
                @endif
                <form method="POST" action="{{ route('audiobooks.uploads.approve', $upload->id) }}" class="mb-2">
                    @csrf
                    <input type="hidden" name="novel_id" value="{{ $chosen->id }}">
                    @if($bookAudiobook)
                        <input type="hidden" name="replace" value="1">
                        <button type="submit" class="btn btn-primary" id="approveReplace">Replace it with this one</button>
                    @else
                        <button type="submit" class="btn btn-primary" id="approveBtn">Add to the library</button>
                    @endif
                </form>
                @if($bookAudiobook && !$bookAudiobook->trashed())
                    <form method="POST" action="{{ route('audiobooks.uploads.use_existing', $upload->id) }}" class="mb-2">
                        @csrf
                        <input type="hidden" name="audiobook_id" value="{{ $bookAudiobook->id }}">
                        <button type="submit" class="btn btn-secondary btn-sm">Keep the existing one</button>
                    </form>
                @endif
            @endif
            <form method="POST" action="{{ route('audiobooks.uploads.approve', $upload->id) }}" class="mb-3">
                @csrf
                <input type="hidden" name="standalone" value="1">
                <button type="submit" class="btn {{ $chosen ? 'btn-ghost btn-sm' : 'btn-primary' }}" id="approveStandalone">Add it standalone (no book)</button>
            </form>

            <form method="POST" action="{{ route('audiobooks.uploads.decline', $upload->id) }}" class="abu-decline">
                @csrf
                <label for="declineReason" class="form-label">Decline (the reader sees the reason)</label>
                <textarea name="reason" id="declineReason" class="form-control mb-2" rows="2" maxlength="500" placeholder="Optional: poor sound, wrong book, incomplete…"></textarea>
                <button type="submit" class="btn btn-danger btn-sm" id="declineBtn">Decline and delete the files</button>
            </form>
        </aside>
    </div>
    @endif
</div>
@endsection
