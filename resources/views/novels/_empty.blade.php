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
    $onlyChip = isset($chipCopy[$filter]) && !request('search') && !request()->filled('status') && !request()->filled('tag');
@endphp
<div class="novels-empty">
    <x-brand-mark variant="mono" :size="28" class="novels-empty-icon" />
    @if($onlyChip)
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
    @else
        <span class="novels-empty-title">Your library is empty</span>
        <p class="novels-empty-body">Add a {{ $medium === \App\Novel::MEDIUM_MANGA ? 'manga' : 'web novel' }} and Novarr will fetch its metadata and cover, then keep scraping new chapters for you.</p>
        <div class="novels-empty-actions">
            <a href="{{ route('novels.discover', ['medium' => $medium]) }}" class="btn btn-primary">Add your first {{ $mediumNoun }}</a>
            <a href="{{ route('novels.create', ['medium' => $medium]) }}" class="btn btn-secondary">Add manually</a>
        </div>
    @endif
</div>
