@extends('layouts.app')

{{--
    Reader requests (RequestsPageController). The page is a frame: every list
    and action goes through the JSON API (/api/v1/requests…) from
    resources/js/requests.js, with the session cookie. Three areas:
      A. find something to request (a search across every source of a medium),
         and under it the Discover manga shelf (popular / new / top-rated
         lists, hidden until the browse call answers with lists)
      B. my requests
      C. requests to review — only when the list call answers can_decide
         (the admin, at home); the admin on the public address gets an
         explanation instead.
--}}

@section('title', 'Requests')

@section('content')
<div class="requests-page" data-requests-page
     data-source-counts='@json($sourceCounts)'
     data-owner-away="{{ $ownerAway ? '1' : '0' }}">

<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Requests</span>
        <h1 class="page-title mb-0">Request a series</h1>
        <p class="page-head-sub mb-0">Ask for a novel or manga that isn’t in the library yet, or send a book you have as an ePub. The owner decides; you’ll see here when it’s ready to read.</p>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('books.upload') }}" class="btn btn-secondary" id="reqUploadBook">Upload a book</a>
    </div>
</div>

{{-- C. Requests to review (the admin at home): first, so deciding needs no scrolling. --}}
@if($ownerAway)
<section class="req-section" aria-labelledby="reqAwayTitle" id="reqAway">
    <div class="req-section-head">
        <h2 class="req-section-title" id="reqAwayTitle">Requests to review</h2>
    </div>
    <div class="alert alert-secondary req-away" role="note">
        Approving and declining requests is only available on your home network (or over Tailscale), like every admin feature. Open Novarr there to review them.
    </div>
</section>
@endif
<section class="req-section" aria-labelledby="reqReviewTitle" id="reqReview" hidden>
    <div class="req-section-head">
        <h2 class="req-section-title" id="reqReviewTitle">Requests to review</h2>
        <div class="btn-group segmented" role="group" aria-label="Show" id="reqReviewTabs">
            <button type="button" class="btn btn-secondary active" data-review-tab="pending" aria-pressed="true">To review <span class="req-tab-count" id="reqReviewCount"></span></button>
            <button type="button" class="btn btn-secondary" data-review-tab="decided" aria-pressed="false">Decided</button>
        </div>
    </div>
    <p id="reqReviewStatus" class="library-status req-status" role="status" aria-live="polite"></p>
    <ul id="reqReviewList" class="req-list" role="list" aria-labelledby="reqReviewTitle"></ul>
    <div class="req-more" hidden id="reqReviewMoreWrap">
        <button type="button" class="btn btn-secondary" id="reqReviewMore">Load more</button>
    </div>
</section>

{{-- A. Find something to request --}}
<section class="req-section" aria-labelledby="reqFindTitle">
    <div class="req-section-head">
        <h2 class="req-section-title" id="reqFindTitle">Find something to request</h2>
    </div>
    <form id="reqSearchForm" class="filter-bar req-search" role="search" novalidate>
        <input type="search" id="reqQuery" name="q" class="form-control req-query" maxlength="100" autocomplete="off"
               placeholder="Title or author" aria-label="Title or author to search for" enterkeyhint="search">
        <div class="btn-group segmented" role="group" aria-label="Kind" id="reqMedium">
            <button type="button" class="btn btn-secondary active" data-medium="novel" aria-pressed="true">Novels</button>
            <button type="button" class="btn btn-secondary" data-medium="manga" aria-pressed="false">Manga</button>
        </div>
        <button type="submit" class="btn btn-primary" id="reqSearchBtn">Search</button>
    </form>
    <p class="req-free-link">
        <button type="button" class="req-linkbtn" data-req-freetext>Can’t find it? Request by title</button>
    </p>

    <p id="reqSearchStatus" class="library-status req-status" role="status" aria-live="polite"></p>
    {{-- Refusals (429 limits, failed / expired searches): the API's message, verbatim. --}}
    <div id="reqSearchError" class="alert alert-warning req-search-error" role="alert" hidden></div>
    <ul id="reqResults" class="disc-grid req-grid" role="list" aria-label="Search results"></ul>
    <p id="reqSkipped" class="req-muted-line" hidden></p>
</section>

{{-- A2. Discover manga: a shelf of popular lists (GET /api/v1/requests/browse).
     Hidden until the call answers with at least one list. --}}
<section class="req-section req-discover" id="reqDiscover" aria-labelledby="reqDiscoverTitle" hidden>
    <div class="req-section-head">
        <h2 class="req-section-title" id="reqDiscoverTitle">Discover manga</h2>
        {{-- One button per list, built from the answer's `lists`; left out with one list. --}}
        <div class="btn-group segmented req-discover-tabs" role="group" aria-label="Lists" id="reqDiscoverTabs" hidden></div>
    </div>
    <div class="req-discover-sub">
        <p class="req-muted-line">Request anything that isn’t in the library yet.</p>
        {{-- Mouse and trackpad only (CSS); touch and keyboard scroll the rail itself. --}}
        <div class="req-rail-nav" id="reqRailNav" hidden>
            <button type="button" class="btn btn-sm btn-secondary req-rail-arrow" id="reqRailPrev" aria-label="Scroll left" aria-controls="reqRail">
                <x-icon name="chevron-left" :size="16" />
            </button>
            <button type="button" class="btn btn-sm btn-secondary req-rail-arrow" id="reqRailNext" aria-label="Scroll right" aria-controls="reqRail">
                <x-icon name="chevron-right" :size="16" />
            </button>
        </div>
    </div>
    <p id="reqDiscoverStatus" class="library-status req-status" role="status" aria-live="polite"></p>
    {{-- A failed load: the API's message, verbatim, and a Retry. --}}
    <div id="reqDiscoverError" class="alert alert-warning req-discover-error" role="alert" hidden>
        <span class="req-discover-error-text"></span>
        <button type="button" class="btn btn-sm btn-secondary" id="reqDiscoverRetry">Retry</button>
    </div>
    <ul id="reqRail" class="req-rail" role="list" aria-label="Manga to request"></ul>
</section>

{{-- B. My requests --}}
<section class="req-section" aria-labelledby="reqMineTitle">
    <div class="req-section-head">
        <h2 class="req-section-title" id="reqMineTitle">My requests</h2>
        <span class="req-section-note" id="reqPendingUsed" aria-live="polite"></span>
    </div>
    <p id="reqMineStatus" class="library-status req-status" role="status" aria-live="polite">Loading…</p>
    <ul id="reqMine" class="req-list" role="list" aria-labelledby="reqMineTitle"></ul>
    <div class="req-more" hidden id="reqMineMoreWrap">
        <button type="button" class="btn btn-secondary" id="reqMineMore">Load more</button>
    </div>
</section>

{{-- ---- Templates (cloned and filled by resources/js/requests.js) ---- --}}

{{-- A search result: the Discover card, with its one action below. --}}
<template id="reqResultTpl">
    <li class="disc-card req-card">
        <div class="disc-cover-wrap">
            <x-cover :novel="['name' => 'Title', 'author' => 'Author']" decorative />
        </div>
        <span class="disc-title req-card-title"></span>
        <div class="disc-meta"><span class="disc-author"></span></div>
        <p class="req-sources"></p>
        <p class="disc-synopsis"></p>
        <div class="req-card-action"></div>
    </li>
</template>

{{-- A shelf card: cover, title, author, one muted line, the action. No synopsis
     (the request dialog shows it). --}}
<template id="reqShelfTpl">
    <li class="disc-card req-card req-shelf-card">
        <div class="disc-cover-wrap req-shelf-cover">
            <x-cover :novel="['name' => 'Title', 'author' => 'Author']" decorative />
        </div>
        <span class="disc-title req-card-title"></span>
        <div class="disc-meta"><span class="disc-author"></span></div>
        <p class="req-shelf-meta"></p>
        <div class="req-card-action"></div>
    </li>
</template>

{{-- A request row (my requests, review, decided). --}}
<template id="reqRowTpl">
    <li class="req-row">
        <div class="req-row-cover">
            <x-cover :novel="['name' => 'Title']" size="sm" decorative />
        </div>
        <div class="req-row-body">
            <p class="req-row-who"></p>
            <div class="req-row-head">
                <span class="req-row-title"></span>
                <span class="badge badge-muted req-medium"></span>
                <span class="badge req-status-badge"></span>
            </div>
            <p class="req-row-meta"></p>
            <p class="req-row-joined"></p>
            <p class="req-row-also"></p>
            <p class="req-row-detail"></p>
            <p class="disc-synopsis req-row-synopsis"></p>
            <p class="req-row-note"></p>
            <p class="req-row-link"></p>
            <div class="req-row-decide"></div>
            <div class="req-row-actions"></div>
        </div>
    </li>
</template>

{{-- ---- Dialogs (Bootstrap modals: focus trapped, Esc closes) ---- --}}

{{-- Request a search result or a shelf card: an optional note. A shelf card
     also shows its synopsis and meta line (the card has no room for them). --}}
<div class="modal fade" id="reqNoteDialog" tabindex="-1" aria-hidden="true" aria-labelledby="reqNoteTitle">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="reqNoteForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="reqNoteTitle">Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="req-dialog-subject" id="reqNoteSubject"></p>
                <p class="req-dialog-meta" id="reqNoteMeta" hidden></p>
                <p class="req-dialog-synopsis" id="reqNoteSynopsis" hidden></p>
                <label class="form-label" for="reqNoteText">Note for the owner <span class="text-muted">(optional)</span></label>
                <textarea class="form-control" id="reqNoteText" name="note" rows="3" maxlength="500" aria-describedby="reqNoteCount"></textarea>
                <div class="form-text" id="reqNoteCount">0 / 500</div>
                <div class="alert alert-danger req-dialog-error mt-3 mb-0" role="alert" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Request</button>
            </div>
        </form>
    </div>
</div>

{{-- Request by title (free text). --}}
<div class="modal fade" id="reqFreeDialog" tabindex="-1" aria-hidden="true" aria-labelledby="reqFreeTitle">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="reqFreeForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="reqFreeTitle">Request by title</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="reqFreeName">Title</label>
                    <input type="text" class="form-control" id="reqFreeName" name="title" maxlength="255" required autocomplete="off">
                </div>
                <fieldset class="mb-3">
                    <legend class="form-label">Kind</legend>
                    <div class="req-radio-row">
                        <label class="req-radio"><input type="radio" name="medium" value="novel" checked> Novel</label>
                        <label class="req-radio"><input type="radio" name="medium" value="manga"> Manga</label>
                    </div>
                </fieldset>
                <div class="mb-3">
                    <label class="form-label" for="reqFreeLink">Link <span class="text-muted">(optional)</span></label>
                    <input type="url" class="form-control" id="reqFreeLink" name="link" maxlength="2048" inputmode="url" placeholder="https://" aria-describedby="reqFreeLinkHelp">
                    <div class="form-text" id="reqFreeLinkHelp">Where you read about it. The owner sees it as text; it is never opened for you.</div>
                </div>
                <label class="form-label" for="reqFreeNote">Note for the owner <span class="text-muted">(optional)</span></label>
                <textarea class="form-control" id="reqFreeNote" name="note" rows="3" maxlength="500" aria-describedby="reqFreeCount"></textarea>
                <div class="form-text" id="reqFreeCount">0 / 500</div>
                <div class="alert alert-danger req-dialog-error mt-3 mb-0" role="alert" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Request</button>
            </div>
        </form>
    </div>
</div>

{{-- Decline (admin): an optional reason, shown to the reader. --}}
<div class="modal fade" id="reqDeclineDialog" tabindex="-1" aria-hidden="true" aria-labelledby="reqDeclineTitle">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="reqDeclineForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="reqDeclineTitle">Decline request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="req-dialog-subject" id="reqDeclineSubject"></p>
                <label class="form-label" for="reqDeclineReason">Reason <span class="text-muted">(optional, shown to the reader)</span></label>
                <textarea class="form-control" id="reqDeclineReason" name="reason" rows="3" maxlength="500" aria-describedby="reqDeclineCount"></textarea>
                <div class="form-text" id="reqDeclineCount">0 / 500</div>
                <div class="alert alert-danger req-dialog-error mt-3 mb-0" role="alert" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Decline</button>
            </div>
        </form>
    </div>
</div>

</div>
@endsection
