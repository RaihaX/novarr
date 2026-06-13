@extends('layouts.app')

@section('content')
<div class="page-toolbar">
    <div class="d-flex align-items-center gap-3">
        <h1 class="mb-0">Add Novel</h1>
        <a href="{{ route('novels.create') }}" class="btn btn-sm btn-outline-secondary">Manual add</a>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <div class="btn-group" role="group" aria-label="Browse mode">
            <button type="button" class="btn btn-sm btn-outline-secondary discover-tab active" data-type="popular">Popular</button>
            <button type="button" class="btn btn-sm btn-outline-secondary discover-tab" data-type="completed">Completed</button>
        </div>
        <form id="discoverSearch" class="d-flex gap-2">
            <input type="search" id="discoverQuery" aria-label="Search novelbin" class="form-control form-control-sm" placeholder="Search novelbin.me..." minlength="2">
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
        </form>
    </div>
</div>

<p class="text-muted" style="font-size: 13px;">Results from novelbin.me — adding a novel queues a background command that fetches metadata and the cover, then you can scrape its TOC from the novel page.</p>

<div id="discoverStatus" class="text-muted py-5 text-center">Loading…</div>
<div id="discoverResults" class="poster-grid mb-4"></div>
@endsection

@push('scripts')
<script>
    const resultsEl = document.getElementById('discoverResults');
    const statusEl = document.getElementById('discoverStatus');
    const tabs = document.querySelectorAll('.discover-tab');

    async function loadList(type, q = '') {
        statusEl.textContent = 'Loading…';
        statusEl.classList.remove('d-none');
        resultsEl.innerHTML = '';

        const params = new URLSearchParams({ type });
        if (q) params.set('q', q);

        try {
            const response = await fetch(`{{ route('novels.discover.browse') }}?${params}`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();

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
        btn.className = 'btn btn-sm w-100 mt-2 ' + (item.in_library ? 'btn-outline-secondary' : 'btn-success');
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
                btn.className = 'btn btn-sm w-100 mt-2 btn-outline-secondary';
                btn.textContent = 'Added ✓';
                Novarr.showToast(`"${item.name}" added — metadata and cover fetched. Open it to scrape the TOC.`, 'success');
            } else if ((result.output || '').includes('already exists')) {
                btn.className = 'btn btn-sm w-100 mt-2 btn-outline-secondary';
                btn.textContent = 'Already added';
                Novarr.showToast(`"${item.name}" is already in your library.`, 'warning');
            } else {
                btn.disabled = false;
                btn.className = 'btn btn-sm w-100 mt-2 btn-success';
                btn.textContent = '+ Add';
                Novarr.showToast(result.error || result.message || 'Failed to add novel.', 'danger');
            }
        } catch (err) {
            btn.disabled = false;
            btn.className = 'btn btn-sm w-100 mt-2 btn-success';
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
        if (q.length < 2) return;
        tabs.forEach(t => t.classList.remove('active'));
        loadList('search', q);
    });

    loadList('popular');
</script>
@endpush
