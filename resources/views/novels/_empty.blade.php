{{--
    Empty-state for the novels list. Distinguishes a genuinely empty library
    (first-run onboarding CTA) from a filter that matched nothing (offer Clear).
    `.novels-empty` spans the full width inside the poster / card grid and is
    inert in the table and list contexts.
--}}
<div class="novels-empty">
    @if($hasFilters)
        <x-brand-mark variant="mono" :size="28" class="novels-empty-icon" />
        <span class="novels-empty-title">No novels match those filters</span>
        <p class="novels-empty-body">Try a different status or tag, or clear the filters to see the whole library.</p>
        <div class="novels-empty-actions">
            <a href="{{ route('novels.index') }}" class="btn btn-secondary">Clear filters</a>
        </div>
    @else
        <x-brand-mark variant="mono" :size="28" class="novels-empty-icon" />
        <span class="novels-empty-title">Your library is empty</span>
        <p class="novels-empty-body">Add a web novel and Novarr will fetch its metadata and cover, then keep scraping new chapters for you.</p>
        <div class="novels-empty-actions">
            <a href="{{ route('novels.discover') }}" class="btn btn-primary">Add your first novel</a>
            <a href="{{ route('novels.create') }}" class="btn btn-secondary">Add manually</a>
        </div>
    @endif
</div>
