@extends('layouts.app')

@section('content')
<div class="page-toolbar">
    <div class="d-flex align-items-center gap-3">
        <h1 class="mb-0">Add Novel</h1>
        <a href="{{ route('novels.create') }}" class="btn btn-sm btn-outline-secondary">Manual add</a>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <select id="discoverSource" class="form-select form-select-sm w-auto" aria-label="Source">
            <option value="novelbin">novelbin.me</option>
            <option value="empirenovel">empirenovel.com</option>
            <option value="novelfull">novelfull.com</option>
        </select>
        <div class="btn-group" role="group" aria-label="Browse mode" id="discoverTabs">
            <button type="button" class="btn btn-sm btn-outline-secondary discover-tab active" data-type="popular">Popular</button>
            <button type="button" class="btn btn-sm btn-outline-secondary discover-tab" data-type="completed">Completed</button>
        </div>
        <form id="discoverSearch" class="d-flex gap-2 flex-nowrap">
            <input type="search" id="discoverQuery" aria-label="Search source" class="form-control form-control-sm" placeholder="Search…" minlength="2">
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
        </form>
    </div>
</div>

<p class="text-muted" style="font-size: 13px;">Adding a novel queues a background command that fetches metadata and the cover, then you can scrape its TOC from the novel page.</p>

<div id="discoverStatus" class="text-muted py-5 text-center">Loading…</div>
<div id="discoverResults" class="poster-grid mb-4"></div>
@endsection

@push('scripts')
<script>
(function(){

    const resultsEl = document.getElementById('discoverResults');
    const statusEl = document.getElementById('discoverStatus');
    const tabs = document.querySelectorAll('.discover-tab');
    const sourceEl = document.getElementById('discoverSource');
    const tabsEl = document.getElementById('discoverTabs');

    const source = () => sourceEl.value;

    let slowTimer = null;

    // Skeleton poster tiles + a spinner while the (sometimes slow, Cloudflare-
    // gated) source is fetched, instead of a bare "Loading…" string.
    function showLoading() {
        clearTimeout(slowTimer);
        statusEl.classList.remove('d-none');
        statusEl.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Loading…';

        resultsEl.innerHTML = '';
        for (let i = 0; i < 12; i++) {
            const sk = document.createElement('div');
            sk.className = 'poster-card poster-skeleton';
            sk.setAttribute('aria-hidden', 'true');
            sk.innerHTML = '<div class="poster-cover skeleton-box"></div>'
                + '<div class="skeleton-line mt-2"></div>'
                + '<div class="skeleton-line short"></div>';
            resultsEl.appendChild(sk);
        }

        // Reassure the user when a scrape is taking a while.
        slowTimer = setTimeout(() => {
            statusEl.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Still working — the source can be slow…';
        }, 6000);
    }

    function endLoading() {
        clearTimeout(slowTimer);
        resultsEl.innerHTML = '';
    }

    async function loadList(type, q = '') {
        showLoading();

        const params = new URLSearchParams({ type, source: source() });
        if (q) params.set('q', q);

        try {
            const response = await fetch(`{{ route('novels.discover.browse') }}?${params}`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();
            endLoading();

            if (!data.success) {
                statusEl.textContent = data.message || 'Failed to load results.';
                return;
            }

            if (!data.items.length) {
                statusEl.textContent = 'No results found.';
                return;
            }

            statusEl.classList.add('d-none');
            data.items.forEach(renderCard);
        } catch (err) {
            endLoading();
            statusEl.textContent = 'Error: ' + err.message;
        }
    }

    function renderCard(item) {
        const card = document.createElement('div');
        card.className = 'poster-card';

        const coverWrap = document.createElement('div');
        coverWrap.className = 'poster-cover';

        if (item.cover) {
            const img = document.createElement('img');
            img.src = item.cover;
            img.alt = 'Cover of ' + item.name;
            img.loading = 'lazy';
            img.addEventListener('error', () => {
                // Full-size cover missing? Retry the list thumbnail once,
                // then give up and show the placeholder tile.
                if (item.cover_thumb && img.src !== item.cover_thumb) {
                    img.src = item.cover_thumb;
                    return;
                }
                const ph = document.createElement('div');
                ph.className = 'poster-cover-placeholder';
                const span = document.createElement('span');
                span.textContent = item.name;
                ph.appendChild(span);
                img.replaceWith(ph);
            });
            coverWrap.appendChild(img);
        } else {
            const ph = document.createElement('div');
            ph.className = 'poster-cover-placeholder';
            const span = document.createElement('span');
            span.textContent = item.name;
            ph.appendChild(span);
            coverWrap.appendChild(ph);
        }

        if (item.in_library) {
            const badge = document.createElement('span');
            badge.className = 'poster-badge badge bg-success';
            badge.textContent = 'In library';
            coverWrap.appendChild(badge);
        }

        const title = document.createElement('div');
        title.className = 'poster-title';
        title.title = item.name;
        title.textContent = item.name;

        const meta = document.createElement('div');
        meta.className = 'poster-meta';
        meta.textContent = item.author || '';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm w-100 poster-add ' + (item.in_library ? 'btn-outline-secondary' : 'btn-success');
        btn.textContent = item.in_library ? 'Already added' : '+ Add';
        btn.disabled = item.in_library;
        btn.addEventListener('click', () => addNovel(btn, item));

        card.append(coverWrap, title, meta, btn);
        resultsEl.appendChild(card);
    }

    async function addNovel(btn, item) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Adding…';

        try {
            const result = await Novarr.executeCommand({
                command: 'create_novel',
                name: item.name,
                url: item.url,
            });

            if (result.success && !(result.output || '').includes('cancelled')) {
                // Command prints "New Novel ID: 70" — link straight to it.
                const idMatch = (result.output || '').match(/New Novel ID:\s*(\d+)/);
                if (idMatch) {
                    const link = document.createElement('a');
                    link.href = `/novels/${idMatch[1]}`;
                    link.className = 'btn btn-sm w-100 poster-add btn-info';
                    link.textContent = 'Open novel →';
                    btn.replaceWith(link);
                } else {
                    btn.className = 'btn btn-sm w-100 poster-add btn-outline-secondary';
                    btn.textContent = 'Added ✓';
                }
                Novarr.showToast(`"${item.name}" added — metadata and cover fetched. Open it to scrape the TOC.`, 'success');
            } else if ((result.output || '').includes('already exists')) {
                btn.className = 'btn btn-sm w-100 poster-add btn-outline-secondary';
                btn.textContent = 'Already added';
                Novarr.showToast(`"${item.name}" is already in your library.`, 'warning');
            } else {
                btn.disabled = false;
                btn.className = 'btn btn-sm w-100 poster-add btn-success';
                btn.textContent = '+ Add';
                Novarr.showToast(result.error || result.message || 'Failed to add novel.', 'danger');
            }
        } catch (err) {
            btn.disabled = false;
            btn.className = 'btn btn-sm w-100 poster-add btn-success';
            btn.textContent = '+ Add';
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }

    tabs.forEach(tab => tab.addEventListener('click', () => {
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('discoverQuery').value = '';
        loadList(tab.dataset.type);
    }));

    document.getElementById('discoverSearch').addEventListener('submit', e => {
        e.preventDefault();
        const q = document.getElementById('discoverQuery').value.trim();
        if (q.length < 2) {
            Novarr.showToast('Enter at least 2 characters to search.', 'warning');
            return;
        }
        tabs.forEach(t => t.classList.remove('active'));
        loadList('search', q);
    });

    // Only novelbin has browse lists; other sources are search-only.
    sourceEl.addEventListener('change', () => {
        const src = source();
        const searchOnly = src !== 'novelbin';
        tabsEl.classList.toggle('d-none', searchOnly);
        document.getElementById('discoverQuery').placeholder = `Search ${src === 'novelbin' ? 'novelbin.me' : src + '.com'}…`;
        document.getElementById('discoverQuery').value = '';
        if (searchOnly) {
            statusEl.textContent = `Search ${src}.com to find a novel to add.`;
            statusEl.classList.remove('d-none');
            resultsEl.innerHTML = '';
        } else {
            tabs.forEach((t, i) => t.classList.toggle('active', i === 0));
            loadList('popular');
        }
    });

    loadList('popular');

})();
</script>
@endpush
