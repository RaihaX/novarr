@extends('layouts.app')

@section('title', 'Downloads')

@section('content')
<div class="lib-page">
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">On this device</span>
        <h1 class="page-title mb-0">Downloads</h1>
        <p class="page-head-sub mb-0">Novels cached in this browser — they open and read without a connection.</p>
    </div>
    <div class="page-head-actions">
        <x-status state="attention" id="offlineBadge" class="d-none" label="Offline" />
        <a href="{{ route('novels.index') }}" class="btn btn-secondary">Library</a>
    </div>
</div>

<div class="d-flex flex-wrap align-items-center gap-3 mb-4">
    <p class="library-status mb-0" id="libStatus" role="status">
        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>Loading downloaded novels…
    </p>
    {{-- Reading progress made offline waits here until the next connection. --}}
    <span class="dl-note" id="dlQueueNote" hidden>
        <x-icon name="refresh-cw" :size="12" />
        <span><span class="mono-num" id="dlQueueCount">0</span> <span id="dlQueueWord">reading updates</span> waiting to sync</span>
    </span>
</div>

<ul class="lib-grid" id="libGrid" role="list">
    @for($i = 0; $i < 7; $i++)
        <li class="disc-skeleton" aria-hidden="true">
            <div class="skeleton-cover"></div>
            <div class="skeleton-line"></div>
            <div class="skeleton-line short"></div>
        </li>
    @endfor
</ul>

{{-- One cached novel; cloned and filled by the page script. --}}
<template id="dlTileTpl">
    <li class="lib-tile dl-tile">
        <a class="lib-tile-link">
            <x-cover :novel="['name' => 'Title', 'author' => 'Author']" :chapters="1" decorative />
            <span class="lib-tile-title"></span>
            <span class="lib-tile-sub"></span>
        </a>
        <button type="button" class="btn btn-ghost dl-remove"><x-icon name="trash-2" :size="12" /> Remove</button>
    </li>
</template>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const grid = document.getElementById('libGrid');
        const status = document.getElementById('libStatus');
        const tpl = document.getElementById('dlTileTpl');
        if (!grid || !tpl) return;

        const titleStep = (name) => name.length <= 18 ? 'l' : (name.length <= 44 ? 'm' : 's');
        const plural = (n, one, many) => `${n.toLocaleString()} ${n === 1 ? one : many}`;

        // Cover tile per cached novel: same cover as the Library grid, the
        // offline chapter count as the mono subline, and a Remove action.
        function tile(novel) {
            const el = tpl.content.firstElementChild.cloneNode(true);
            const link = el.querySelector('.lib-tile-link');
            link.href = novel.url;

            // Text is assigned as properties so a title can never break out
            // of the markup.
            const name = novel.name || 'Untitled';
            const t = el.querySelector('.cover-title');
            t.textContent = name;
            t.className = `cover-title cover-title-${titleStep(name)}`;
            el.querySelector('.cover-author').textContent = novel.author || '';
            el.querySelector('.cover-count').textContent = `${(novel.chapterCount || 0).toLocaleString()} CH`;
            el.querySelector('.lib-tile-title').textContent = name;
            el.querySelector('.lib-tile-sub').textContent =
                `${plural(novel.chapterCount || 0, 'CHAPTER', 'CHAPTERS')} OFFLINE`;
            link.title = name;

            if (novel.cover) {
                const cover = el.querySelector('.cover');
                const img = document.createElement('img');
                img.alt = '';
                img.width = 180;
                img.height = 270;
                img.loading = 'lazy';
                img.decoding = 'async';
                img.addEventListener('error', () => { img.remove(); cover.classList.remove('has-image'); });
                img.src = novel.cover;
                cover.classList.add('has-image');
                cover.insertBefore(img, cover.querySelector('.cover-type').nextSibling);
            }

            const remove = el.querySelector('.dl-remove');
            remove.setAttribute('aria-label', `Remove ${name} from this device`);
            remove.addEventListener('click', async () => {
                const ok = await Novarr.confirmDialog(
                    `Remove the offline copy of "${name}" from this device? The novel stays in your library.`,
                    { title: 'Remove download', confirmText: 'Remove', danger: true }
                );
                if (!ok) return;
                remove.disabled = true;
                try {
                    await Novarr.removeNovel(novel.id);
                    Novarr.showToast(`Removed "${name}" from this device.`, 'success');
                    render();
                } catch (err) {
                    remove.disabled = false;
                    Novarr.showToast('Error: ' + err.message, 'danger');
                }
            });

            return el;
        }

        function render() {
            if (!window.Novarr?.getLibrary || !document.body.contains(grid)) return;

            document.getElementById('offlineBadge')?.classList.toggle('d-none', navigator.onLine);

            Novarr.getLibrary().then((novels) => {
                grid.innerHTML = '';

                if (!novels.length) {
                    status.textContent = 'Nothing downloaded yet';
                    grid.innerHTML = `
                        <li class="novels-empty">
                            <span class="novels-empty-title">No offline novels</span>
                            <p class="novels-empty-body">Open a novel and choose “Download for offline” to keep its chapters on this device.</p>
                        </li>`;
                    return;
                }

                novels.sort((a, b) => (b.downloadedAt || 0) - (a.downloadedAt || 0));
                const chapters = novels.reduce((sum, n) => sum + (n.chapterCount || 0), 0);
                status.textContent = `${plural(novels.length, 'novel', 'novels')} · ${plural(chapters, 'chapter', 'chapters')} available offline`;

                for (const n of novels) grid.appendChild(tile(n));
            }).catch(() => {
                status.textContent = 'This browser can’t store offline novels (private window or blocked site data).';
                grid.innerHTML = '';
            });
        }

        // Pending offline read-marks: the size on load, then live updates.
        function showQueue(size) {
            const note = document.getElementById('dlQueueNote');
            if (!note) return;
            note.hidden = !(size > 0);
            document.getElementById('dlQueueCount').textContent = (size || 0).toLocaleString();
            document.getElementById('dlQueueWord').textContent = size === 1 ? 'reading update' : 'reading updates';
        }
        function refreshQueue() {
            window.Novarr?.offlineQueueSize?.().then(showQueue).catch(() => {});
        }

        // Listeners are window-level, so bind them once per browser session
        // (Turbo re-runs this script on every visit); each one re-finds the
        // page's elements and no-ops elsewhere.
        if (!window.__novarrDownloadsBound) {
            window.__novarrDownloadsBound = true;
            window.addEventListener('novarr:offline-queue', (e) => showQueue(e.detail?.size ?? 0));
            window.addEventListener('online', () => window.__novarrDownloadsRender?.());
            window.addEventListener('offline', () => window.__novarrDownloadsRender?.());
        }
        window.__novarrDownloadsRender = render;

        const start = () => { render(); refreshQueue(); };
        if (window.Novarr?.getLibrary) start();
        else window.addEventListener('load', start, { once: true });
    })();
</script>
@endpush
