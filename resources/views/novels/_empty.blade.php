{{--
    Empty-state for the novels list. Distinguishes a genuinely empty library
    (first-run onboarding CTA) from a chip / filter that matched nothing
    (chip-specific copy, offer the whole library back).
    `.novels-empty` spans the full width inside the grid and is inert in the
    table and list contexts.
--}}
@php
    $filter ??= 'all';
    $medium ??= \App\Novel::MEDIUM_NOVEL;
    $mediumNoun = \App\Http\Helpers\LibraryMedium::noun($medium);
    $mediumPlural = \App\Http\Helpers\LibraryMedium::plural($medium);
    $chipCopy = [
        'reading' => ['Nothing in progress', 'Novels you have started and not finished show up here.'],
        'new' => ['You’re caught up', 'No chapters have arrived since you last read. New ones land here as the scraper finds them.'],
        'attention' => ['Nothing needs attention', 'Every source is scraping normally.'],
        'offline' => ['Nothing downloaded on this device', 'Open a novel and choose “Download for offline” to keep its chapters in this browser.'],
        'finished' => ['No finished novels yet', 'Novels you have read to the last known chapter show up here.'],
    ];
    $activeList ??= null;
    $onlyChip = isset($chipCopy[$filter]) && !request('search') && !request()->filled('status') && !request()->filled('tag') && !$activeList;
    // A reading list on its own (no chip, search, tag or status): its own copy.
    $onlyList = $activeList && $filter === 'all' && !request('search') && !request()->filled('status') && !request()->filled('tag');
    $otherMedium = $medium === \App\Novel::MEDIUM_MANGA ? \App\Novel::MEDIUM_NOVEL : \App\Novel::MEDIUM_MANGA;
@endphp
<div class="novels-empty">
    <x-brand-mark variant="mono" :size="28" class="novels-empty-icon" />
    @if($onlyList)
        <span class="novels-empty-title">No {{ $mediumPlural }} in “{{ $activeList['name'] }}” yet</span>
        @if($activeList['other'] > 0)
            <p class="novels-empty-body">This list has {{ number_format($activeList['other']) }} {{ \App\Http\Helpers\LibraryMedium::countNoun($otherMedium, $activeList['other']) }} in the {{ \App\Http\Helpers\LibraryMedium::title($otherMedium) }} library.</p>
            <div class="novels-empty-actions">
                <a href="{{ route('novels.index', ['medium' => $otherMedium, 'list' => $activeList['id']]) }}" class="btn btn-secondary">Show {{ \App\Http\Helpers\LibraryMedium::title($otherMedium) }}</a>
                <a href="{{ route('novels.index', ['medium' => $medium]) }}" class="btn btn-ghost">Show all {{ $mediumPlural }}</a>
            </div>
        @else
            <p class="novels-empty-body">Open a series and use its <strong>Lists</strong> button to add it here.</p>
            <div class="novels-empty-actions">
                <a href="{{ route('novels.index', ['medium' => $medium]) }}" class="btn btn-secondary">Show all {{ $mediumPlural }}</a>
            </div>
        @endif
    @elseif($onlyChip)
        <span class="novels-empty-title">{{ $chipCopy[$filter][0] }}</span>
        <p class="novels-empty-body">{{ $chipCopy[$filter][1] }}</p>
        <div class="novels-empty-actions">
            <a href="{{ route('novels.index', ['medium' => $medium]) }}" class="btn btn-secondary">Show all {{ $mediumPlural }}</a>
        </div>
    @elseif($hasFilters)
        <span class="novels-empty-title">No {{ $mediumPlural }} match those filters</span>
        <p class="novels-empty-body">Try a different search or tag, or clear the filters to see the whole library.</p>
        <div class="novels-empty-actions">
            <a href="{{ route('novels.index', ['medium' => $medium]) }}" class="btn btn-secondary">Clear filters</a>
        </div>
    @elsecannot('admin')
        {{-- A reader cannot add series: a friendly "nothing yet" instead. --}}
        <span class="novels-empty-title">Nothing here yet</span>
        <p class="novels-empty-body">No {{ $mediumPlural }} have been added to this library yet. Check back later.</p>
    @else
        <span class="novels-empty-title">Your library is empty</span>
        <p class="novels-empty-body">Add a {{ $medium === \App\Novel::MEDIUM_MANGA ? 'manga' : 'web novel' }} and Novarr will fetch its metadata and cover, then keep scraping new chapters for you.</p>
        <div class="novels-empty-actions">
            <a href="{{ route('novels.discover', ['medium' => $medium]) }}" class="btn btn-primary">Add your first {{ $mediumNoun }}</a>
            <a href="{{ route('novels.create', ['medium' => $medium]) }}" class="btn btn-secondary">Add manually</a>
        </div>
    @endif
</div>
