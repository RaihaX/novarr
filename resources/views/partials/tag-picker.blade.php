@php
    $allTags = $allTags ?? \App\Tag::orderBy('name')->get(['id', 'name']);
    $selectedIds = $selectedIds ?? [];
@endphp
<div class="tag-picker" data-tag-picker data-create-url="{{ route('tags.store') }}">
    <button type="button" class="form-select text-start tag-picker-toggle">
        <span class="tag-picker-label text-muted">Select tags…</span>
    </button>
    <div class="tag-picker-menu d-none card">
        <div class="card-body p-2">
            @if($allTags->count() > 8)
                <input type="text" class="form-control form-control-sm tag-picker-search mb-2" placeholder="Filter tags…">
            @endif
            <div class="tag-picker-options">
                @forelse($allTags as $t)
                    <label class="tag-picker-option">
                        <input type="checkbox" name="tags[]" value="{{ $t->id }}" data-name="{{ $t->name }}" @checked(in_array($t->id, $selectedIds))>
                        <span>{{ $t->name }}</span>
                    </label>
                @empty
                    <div class="text-muted small px-1 py-2">No tags yet — add one below.</div>
                @endforelse
            </div>
            <div class="tag-picker-create input-group input-group-sm mt-2">
                <input type="text" class="form-control tag-picker-new" placeholder="New tag">
                <button type="button" class="btn btn-outline-secondary tag-picker-add">Add</button>
            </div>
        </div>
    </div>
</div>
