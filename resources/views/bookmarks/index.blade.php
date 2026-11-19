@extends('layouts.app')

@section('title', 'Highlights')

@section('content')
<h1 class="page-title mb-4">Highlights</h1>

{{-- Shown from the start when there are none, and by the script once the
     last one is deleted. --}}
<div class="empty-state {{ $grouped->isEmpty() ? '' : 'd-none' }}" id="highlightsEmpty">
    <x-brand-mark variant="mono" :size="40" class="empty-state-mark" />
    <div class="empty-state-title">No highlights yet</div>
    <p class="empty-state-body">Select any text while reading and tap &ldquo;Save highlight&rdquo; — saved passages collect here, grouped by novel.</p>
</div>
@unless($grouped->isEmpty())
    @foreach($grouped as $novelName => $rows)
        <div class="card stack-card mb-3" data-highlight-group>
            <div class="stack-head">
                <a href="{{ route('novels.show', $rows->first()->novel_id) }}" class="stack-head-title">{{ $novelName }}</a>
                <span class="stack-head-count" data-highlight-count>{{ $rows->count() }} highlight{{ $rows->count() === 1 ? '' : 's' }}</span>
            </div>
            @foreach($rows as $bookmark)
                <div class="stack-row" data-bookmark="{{ $bookmark->id }}">
                    <blockquote class="bookmark-quote">{{ $bookmark->excerpt }}</blockquote>
                    @if($bookmark->note)
                        <div class="bookmark-note">{{ $bookmark->note }}</div>
                    @endif
                    <div class="bookmark-foot">
                        {{-- Opens the chapter at this passage (the reader scrolls
                             to the highlight and pulses it). --}}
                        <a href="{{ route('chapters.show', ['id' => $bookmark->novel_chapter_id, 'lookup' => 1, 'highlight' => $bookmark->id]) }}" class="bookmark-source">
                            {{ $bookmark->chapter->label ?: 'Chapter ' . $bookmark->chapter->chapter }} · {{ $bookmark->created_at->diffForHumans() }}
                        </a>
                        <button type="button" class="btn btn-danger bookmark-delete" data-id="{{ $bookmark->id }}">Delete</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
@endunless
@endsection

@push('scripts')
<script>
(() => {
    document.querySelectorAll('.bookmark-delete').forEach(btn => btn.addEventListener('click', async () => {
        if (!await Novarr.confirmDialog('Delete this highlight?', { title: 'Delete highlight', confirmText: 'Delete', danger: true })) return;
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
                const group = btn.closest('[data-highlight-group]');
                btn.closest('[data-bookmark]').remove();
                // Keep the novel's count true; drop its card with its last one.
                const left = group ? group.querySelectorAll('[data-bookmark]').length : 0;
                if (group && left === 0) group.remove();
                else if (group) group.querySelector('[data-highlight-count]').textContent = `${left} highlight${left === 1 ? '' : 's'}`;
                if (!document.querySelector('[data-highlight-group]')) document.getElementById('highlightsEmpty')?.classList.remove('d-none');
                Novarr.showToast('Highlight deleted.', 'success');
            } else {
                btn.disabled = false;
                Novarr.showToast(data.message || 'Could not delete the highlight.', 'danger');
            }
        } catch (err) {
            btn.disabled = false;
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }));
})();
</script>
@endpush
