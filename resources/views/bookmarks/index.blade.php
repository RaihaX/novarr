@extends('layouts.app')

@section('content')
<h1 class="mb-4">Bookmarks</h1>

@if($grouped->isEmpty())
    <div class="text-center text-muted py-5">
        <div style="font-size: 2rem;" aria-hidden="true">🔖</div>
        <p class="mb-1">No bookmarks yet.</p>
        <p style="font-size: 13px;">Select any text while reading and tap “Save highlight”.</p>
    </div>
@else
    @foreach($grouped as $novelName => $rows)
        <div class="card mb-3">
            <div class="card-header">
                <a href="{{ route('novels.show', $rows->first()->novel_id) }}" class="fw-semibold text-decoration-none">{{ $novelName }}</a>
                <span class="text-muted ms-2" style="font-size: 13px;">{{ $rows->count() }} highlight{{ $rows->count() === 1 ? '' : 's' }}</span>
            </div>
            <div class="list-group list-group-flush">
                @foreach($rows as $bookmark)
                    <div class="list-group-item bg-transparent" data-bookmark="{{ $bookmark->id }}">
                        <blockquote class="mb-1" style="font-size: 14px; border-left: 3px solid #3987e5; padding-left: 10px;">
                            {{ $bookmark->excerpt }}
                        </blockquote>
                        @if($bookmark->note)
                            <div class="text-info mb-1" style="font-size: 13px;">📝 {{ $bookmark->note }}</div>
                        @endif
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="{{ route('chapters.show', $bookmark->novel_chapter_id) }}" class="text-decoration-none text-muted" style="font-size: 12px;">
                                {{ $bookmark->chapter->label ?: 'Chapter ' . $bookmark->chapter->chapter }} · {{ $bookmark->created_at->diffForHumans() }}
                            </a>
                            <button type="button" class="btn btn-sm btn-link text-danger text-decoration-none bookmark-delete" data-id="{{ $bookmark->id }}" style="font-size: 12px;">Delete</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
@endif
@endsection

@push('scripts')
<script>
(() => {
    document.querySelectorAll('.bookmark-delete').forEach(btn => btn.addEventListener('click', async () => {
        if (!await Novarr.confirmDialog('Delete this bookmark?', { title: 'Delete bookmark', confirmText: 'Delete', danger: true })) return;
        btn.disabled = true;
        try {
            const res = await fetch(`/bookmarks/${btn.dataset.id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (data.success) {
                btn.closest('[data-bookmark]').remove();
                Novarr.showToast('Bookmark deleted.', 'success');
            }
        } catch (err) {
            btn.disabled = false;
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }));
})();
</script>
@endpush
