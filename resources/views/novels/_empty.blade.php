{{--
    Empty-state for the novels list. Distinguishes a genuinely empty library
    (first-run onboarding CTA) from a filter that matched nothing (offer Clear).
    `grid-column: 1 / -1` makes it span the full width inside the poster grid;
    it's inert in the table/list contexts.
--}}
<div class="text-center text-muted py-5" style="grid-column: 1 / -1;">
    @if($hasFilters)
        <p class="mb-2">No novels match your filters.</p>
        <a href="{{ route('novels.index') }}" class="btn btn-sm btn-outline-secondary">Clear filters</a>
    @else
        <div class="mb-3" style="font-size: 2.5rem; line-height: 1;" aria-hidden="true">📚</div>
        <p class="mb-1 fw-semibold text-body">Your library is empty</p>
        <p class="mb-3">Add your first web novel to get started.</p>
        <a href="{{ route('novels.discover') }}" class="btn btn-success">+ Add your first novel</a>
    @endif
</div>
