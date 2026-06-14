@extends('layouts.app')

@section('content')
<div class="d-flex align-items-center gap-3 mb-1">
    <h1 class="mb-0">Offline Library</h1>
    <span id="offlineBadge" class="badge bg-warning text-dark d-none">Offline</span>
</div>
<p class="text-muted" id="libStatus">Loading your downloaded novels…</p>

<div class="poster-grid" id="libGrid"></div>
@endsection

@push('scripts')
<script>
    (function () {
        function render() {
            if (!window.Novarr?.getLibrary) return;

            document.getElementById('offlineBadge').classList.toggle('d-none', navigator.onLine);

            Novarr.getLibrary().then((novels) => {
                const grid = document.getElementById('libGrid');
                const status = document.getElementById('libStatus');
                grid.innerHTML = '';

                if (!novels.length) {
                    status.textContent = 'No novels downloaded yet. Open a novel and tap “Download for offline”.';
                    return;
                }

                novels.sort((a, b) => (b.downloadedAt || 0) - (a.downloadedAt || 0));
                status.textContent = `${novels.length} novel${novels.length > 1 ? 's' : ''} available offline.`;

                for (const n of novels) {
                    const card = document.createElement('a');
                    card.href = n.url;
                    card.className = 'poster-card';
                    card.innerHTML = `
                        <div class="poster-cover">
                            ${n.cover
                                ? `<img src="${n.cover}" alt="" loading="lazy">`
                                : `<div class="d-flex align-items-center justify-content-center h-100 text-muted">No Cover</div>`}
                        </div>
                        <div class="poster-title"></div>
                        <div class="poster-meta">${n.chapterCount} chapter${n.chapterCount === 1 ? '' : 's'} offline</div>`;
                    card.querySelector('.poster-title').textContent = n.name;
                    grid.appendChild(card);
                }
            });
        }

        if (window.Novarr?.getLibrary) render();
        else window.addEventListener('load', render, { once: true });
        window.addEventListener('online', render);
        window.addEventListener('offline', render);
    })();
</script>
@endpush
