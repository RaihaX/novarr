@extends('layouts.app')

@section('content')
<h1 class="page-title mb-4">Bookmarks</h1>

@if($grouped->isEmpty())
    <div class="empty-state">
        <x-brand-mark variant="mono" :size="40" class="empty-state-mark" />
        <div class="empty-state-title">No bookmarks yet</div>
        <p class="empty-state-body">Select any text while reading and tap &ldquo;Save highlight&rdquo; — saved passages collect here, grouped by novel.</p>
    </div>
@else
    @foreach($grouped as $novelName => $rows)
        <div class="card stack-card mb-3">
            <div class="stack-head">
                <a href="{{ route('novels.show', $rows->first()->novel_id) }}" class="stack-head-title">{{ $novelName }}</a>
                <span class="stack-head-count">{{ $rows->count() }} highlight{{ $rows->count() === 1 ? '' : 's' }}</span>
            </div>
            @foreach($rows as $bookmark)
                <div class="stack-row" data-bookmark="{{ $bookmark->id }}">
                    <blockquote class="bookmark-quote">{{ $bookmark->excerpt }}</blockquote>
                    @if($bookmark->note)
                        <div class="bookmark-note">{{ $bookmark->note }}</div>
                    @endif
                    <div class="bookmark-foot">
                        <a href="{{ route('chapters.show', $bookmark->novel_chapter_id) }}" class="bookmark-source">
                            {{ $bookmark->chapter->label ?: 'Chapter ' . $bookmark->chapter->chapter }} · {{ $bookmark->created_at->diffForHumans() }}
                        </a>
                        <button type="button" class="btn btn-danger bookmark-delete" data-id="{{ $bookmark->id }}">Delete</button>
                    </div>
                </div>
            @endforeach
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
