@extends('layouts.app')

@section('content')
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">On this device</span>
        <h1 class="page-title mb-0">Offline library</h1>
        <p class="page-head-sub mb-0">Novels cached in this browser — they open and read without a connection.</p>
    </div>
    <div class="page-head-actions">
        <span id="offlineBadge" class="badge badge-attention d-none">Offline</span>
        <a href="{{ route('novels.index') }}" class="btn btn-secondary">All novels</a>
    </div>
</div>

<p class="library-status" id="libStatus">
    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>Loading downloaded novels…
</p>

<div class="novel-card-grid" id="libGrid">
    @for($i = 0; $i < 4; $i++)
        <div class="novel-card novel-card-skeleton" aria-hidden="true">
            <div class="novel-card-head">
                <div class="skeleton-box"></div>
                <div class="novel-card-body w-100">
                    <div class="skeleton-line"></div>
                    <div class="skeleton-line short"></div>
                </div>
            </div>
        </div>
    @endfor
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const dateFmt = new Intl.DateTimeFormat(undefined, { day: '2-digit', month: 'short', year: 'numeric' });

        // Card per the handoff's novel-card recipe: 72×104 cover (gradient
        // placeholder with the mono mark), title / meta / status badge, and a
        // mono count row in the footer.
        function card(novel) {
            const el = document.createElement('a');
            el.className = 'novel-card';
            el.href = novel.url;

            el.innerHTML = `
                <div class="novel-card-head">
                    <div class="novel-card-cover">
                        <svg class="brand-mark novel-card-cover-mark" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true">
                            <path d="M6 5h5v22H6z"/><path d="M11 5h5l10 22h-5z"/><path d="M21 13h5v14h-5z"/>
                            <path d="M21 5h5v8l-2.5-2.2L21 13z" opacity="0.55"/>
                        </svg>
                    </div>
                    <div class="novel-card-body">
                        <span class="novel-card-title"></span>
                        <span class="novel-card-meta"></span>
                        <span class="badge badge-downloaded">Downloaded</span>
                    </div>
                </div>
                <div class="novel-card-foot">
                    <div class="novel-card-counts">
                        <span class="novel-card-chapters"></span>
                        <span class="novel-card-when"></span>
                    </div>
                </div>`;

            // Text is assigned as properties so a title can never break out of
            // the markup.
            el.querySelector('.novel-card-title').textContent = novel.name;
            el.querySelector('.novel-card-meta').textContent = novel.author || 'Unknown author';
            el.querySelector('.novel-card-chapters').textContent =
                `OFFLINE ${novel.chapterCount} ${novel.chapterCount === 1 ? 'CHAPTER' : 'CHAPTERS'}`;
            el.querySelector('.novel-card-when').textContent =
                novel.downloadedAt ? dateFmt.format(new Date(novel.downloadedAt)).toUpperCase() : '';

            if (novel.cover) {
                const cover = el.querySelector('.novel-card-cover');
                cover.classList.add('has-image');
                cover.innerHTML = '';
                const img = document.createElement('img');
                img.loading = 'lazy';
                img.src = novel.cover;
                img.alt = 'Cover of ' + novel.name;
                img.addEventListener('error', () => {
                    cover.classList.remove('has-image');
                    img.remove();
                });
                cover.appendChild(img);
            }

            return el;
        }

        function render() {
            if (!window.Novarr?.getLibrary) return;

            document.getElementById('offlineBadge').classList.toggle('d-none', navigator.onLine);

            Novarr.getLibrary().then((novels) => {
                const grid = document.getElementById('libGrid');
                const status = document.getElementById('libStatus');
                grid.innerHTML = '';

                if (!novels.length) {
                    status.textContent = 'Nothing downloaded yet';
                    grid.innerHTML = `
                        <div class="novels-empty">
                            <span class="novels-empty-title">No offline novels</span>
                            <p class="novels-empty-body">Open a novel and choose “Download for offline” to keep its chapters on this device.</p>
                        </div>`;
                    return;
                }

                novels.sort((a, b) => (b.downloadedAt || 0) - (a.downloadedAt || 0));
                status.textContent = `${novels.length} novel${novels.length > 1 ? 's' : ''} available offline`;

                for (const n of novels) grid.appendChild(card(n));
            });
        }

        if (window.Novarr?.getLibrary) render();
        else window.addEventListener('load', render, { once: true });
        window.addEventListener('online', render);
        window.addEventListener('offline', render);
    })();
</script>
@endpush
