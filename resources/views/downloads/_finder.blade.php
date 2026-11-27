{{--
    "Search Usenet & torrents" (owner only; App\Downloads): a Prowlarr search
    across every indexer, its ranked results with Grab, and the grabs' live
    status. One partial for every place (the wanted-book page, a book's
    "Find the audiobook", the audiobooks page); resources/js/download-finder.js.

    @include('downloads._finder', [
        'kind' => 'ebook' | 'audiobook' | 'comic',
        'query' => the search box's starting text,
        'requestId' => a wanted book request's id (or null),
        'novelId' => the book an audiobook is for (or null),
        'grabs' => its grabs so far (App\DownloadGrab, newest first),
        'heading' => the panel's title, 'finderId' => a unique id,
    ])
--}}
@php
    $grabs = $grabs ?? collect();
    $finderId = $finderId ?? 'downloadFinder';
@endphp
<section class="dl-finder" data-download-finder data-kind="{{ $kind }}"
         data-request-id="{{ $requestId ?? '' }}" data-novel-id="{{ $novelId ?? '' }}"
         id="{{ $finderId }}" aria-labelledby="{{ $finderId }}Title">
    <h2 class="req-section-title" id="{{ $finderId }}Title">{{ $heading ?? 'Search Usenet & torrents' }}</h2>
    <p class="form-text">
        Every Prowlarr indexer is searched ({{ $kind === 'audiobook' ? 'audiobooks' : ($kind === 'comic' ? 'comics: Books/Comics and Books' : 'ebooks') }}); usenet results go to NZBGet, torrents to Transmission (they keep seeding).
        @if($kind === 'ebook' && ($requestId ?? null)) A finished ePub that is clearly this book is added at once; otherwise it waits here for your review. @endif
    </p>
    <form class="dl-finder-form" data-finder-form>
        <input type="search" name="q" class="form-control" value="{{ $query ?? '' }}" maxlength="255"
               aria-label="Search for" placeholder="{{ $kind === 'audiobook' ? 'Title and author of the audiobook' : ($kind === 'comic' ? 'Series, with an issue or volume (Saga v01)' : 'Title and author') }}">
        <button type="submit" class="btn btn-primary"><x-icon name="search" :size="14" /> Search Usenet &amp; torrents</button>
    </form>
    <p class="form-text dl-finder-note" data-finder-note role="status" aria-live="polite"></p>
    {{-- One card per release (title, then indexer · size · age or seeders · why), Grab always in view: fits any width. --}}
    <ul class="dl-results" data-finder-results aria-label="Results" hidden></ul>
    <h3 class="dl-grabs-title" data-finder-grabs-title @if($grabs->isEmpty()) hidden @endif>Downloads</h3>
    <ul class="dl-grabs" data-finder-grabs @if($grabs->isEmpty()) hidden @endif>
        @foreach($grabs as $grab)
            @include('downloads._grab', ['grab' => $grab])
        @endforeach
    </ul>
</section>
