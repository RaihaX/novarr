@extends('layouts.app')

@section('title', 'Add novel')

@section('content')
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Discover</span>
        <h1 class="page-title mb-0">Add novel</h1>
        <p class="page-head-sub mb-0">Adding queues a background command that fetches metadata and the cover; scrape the table of contents from the novel page afterwards.</p>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('novels.create') }}" class="btn btn-secondary">Manual add</a>
    </div>
</div>

<div class="filter-bar mb-4">
    <select id="discoverSource" class="form-select" aria-label="Source">
        <option value="novelarrow">novelarrow.com</option>
        <option value="empirenovel">empirenovel.com</option>
        <option value="novelfull">novelfull.com</option>
    </select>
    <div class="btn-group segmented" role="group" aria-label="Browse mode" id="discoverTabs">
        <button type="button" class="btn btn-secondary discover-tab active" data-type="popular">Popular</button>
        <button type="button" class="btn btn-secondary discover-tab" data-type="completed">Completed</button>
    </div>
    <form id="discoverSearch" class="d-flex gap-2 flex-nowrap">
        <input type="search" id="discoverQuery" aria-label="Search source" class="form-control" placeholder="Search…" minlength="2">
        <button type="submit" class="btn btn-primary">Search</button>
    </form>
</div>

<p id="discoverStatus" class="library-status" role="status" aria-live="polite">Loading…</p>
<ul id="discoverResults" class="disc-grid mb-4" role="list" aria-busy="true"></ul>
{{-- Results arrive in one batch; reveal them 20 at a time. --}}
<div id="discoverMoreWrap" class="discover-more d-none">
    <button type="button" id="discoverMore" class="btn btn-secondary">Load more</button>
</div>

{{-- One result card. The cover is the shared cover component (typographic until an
     image is set); the page script clones this and fills it per result. --}}
<template id="discCardTpl">
    <li class="disc-card">
        <div class="disc-cover-wrap">
            <x-cover :novel="['name' => 'Title', 'author' => 'Author']" :chapters="1" decorative />
            <button type="button" class="disc-add">
                <span class="disc-add-plus" aria-hidden="true">+</span><span class="disc-add-label">Add</span>
            </button>
        </div>
        <a class="disc-title" target="_blank" rel="noopener"></a>
        <div class="disc-meta"><span class="disc-author"></span></div>
        <p class="disc-synopsis"></p>
    </li>
</template>
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

    // Client-side reveal: the browse endpoint returns every result at once
    // (up to 40); render them PAGE_SIZE at a time behind "Load more".
    const PAGE_SIZE = 20;
    const moreWrap = document.getElementById('discoverMoreWrap');
    const moreBtn = document.getElementById('discoverMore');
    let pendingItems = [];

    function renderNextPage() {
        pendingItems.splice(0, PAGE_SIZE).forEach(renderCard);
        moreWrap.classList.toggle('d-none', pendingItems.length === 0);
        moreBtn.textContent = `Load more (${pendingItems.length})`;
    }
    moreBtn.addEventListener('click', () => {
        const firstNew = resultsEl.children.length;
        renderNextPage();
        // Keep keyboard focus in the list: move it to the first newly shown card's button.
        resultsEl.children[firstNew]?.querySelector('button, a')?.focus({ preventScroll: true });
    });

    let slowTimer = null;
    let lastQuery = '';

    // Cover-shaped skeletons while the (sometimes slow, Cloudflare-gated)
    // source is fetched.
    function showLoading() {
        clearTimeout(slowTimer);
        statusEl.classList.remove('d-none');
        statusEl.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>Searching…';
        resultsEl.setAttribute('aria-busy', 'true');

        resultsEl.innerHTML = '';
        for (let i = 0; i < 12; i++) {
            const sk = document.createElement('li');
            sk.className = 'disc-skeleton';
            sk.setAttribute('aria-hidden', 'true');
            sk.innerHTML = '<div class="skeleton-cover"></div><div class="skeleton-line"></div><div class="skeleton-line short"></div>';
            resultsEl.appendChild(sk);
        }

        // Reassure the user when a scrape is taking a while.
        slowTimer = setTimeout(() => {
            statusEl.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>Still working — the source can be slow…';
        }, 6000);
    }

    function endLoading() {
        clearTimeout(slowTimer);
        resultsEl.innerHTML = '';
        resultsEl.setAttribute('aria-busy', 'false');
        pendingItems = [];
        moreWrap.classList.add('d-none');
    }

    // Empty / error state inside the grid (spans every column).
    function showEmpty(title, body) {
        const li = document.createElement('li');
        li.className = 'novels-empty';
        li.innerHTML = '<span class="novels-empty-title"></span><p class="novels-empty-body"></p>'
            + '<div class="novels-empty-actions"><a class="btn btn-secondary" href="{{ route('novels.create') }}">Add manually</a></div>';
        li.querySelector('.novels-empty-title').textContent = title;
        li.querySelector('.novels-empty-body').textContent = body;
        resultsEl.appendChild(li);
    }

    async function loadList(type, q = '') {
        lastQuery = q;
        showLoading();
        pendingItems = [];
        moreWrap.classList.add('d-none');

        const params = new URLSearchParams({ type, source: source() });
        if (q) params.set('q', q);

        try {
            const response = await fetch(`{{ route('novels.discover.browse') }}?${params}`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();
            endLoading();

            if (!data.success) {
                statusEl.textContent = '';
                showEmpty('Couldn’t reach the source', data.message || 'Failed to load results — try again shortly.');
                return;
            }

            if (!data.items.length) {
                statusEl.textContent = '';
                showEmpty(
                    q ? `Nothing on ${source()}.com matches “${q}”` : 'No results',
                    'Try a shorter title or the author’s name, or search another source. You can also add a novel by its URL.'
                );
                return;
            }

            statusEl.textContent = `${data.items.length} result${data.items.length === 1 ? '' : 's'} from ${source()}.com`;
            pendingItems = data.items.slice();
            renderNextPage();
        } catch (err) {
            endLoading();
            statusEl.textContent = '';
            showEmpty('Something went wrong', err.message);
        }
    }

    const tpl = document.getElementById('discCardTpl');

    // Title steps by length, as the cover component / the ePub cover (≤18 / ≤44 / longer).
    const titleStep = (name) => name.length <= 18 ? 'l' : (name.length <= 44 ? 'm' : 's');

    const STATUS_CLASS = { Completed: 'badge-completed', Ongoing: 'badge-active' };

    function renderCard(item) {
        const card = tpl.content.firstElementChild.cloneNode(true);
        const cover = card.querySelector('.cover');

        // Typographic cover — the fallback under (and instead of) the image.
        const t = card.querySelector('.cover-title');
        t.textContent = item.name.length > 90 ? item.name.slice(0, 88) + '…' : item.name;
        t.className = `cover-title cover-title-${titleStep(item.name)}`;
        card.querySelector('.cover-author').textContent = item.author || '';
        const count = card.querySelector('.cover-count');
        if (item.chapters) count.textContent = `${Number(item.chapters).toLocaleString()} CH`;
        else count.remove();

        if (item.cover) {
            const img = document.createElement('img');
            img.alt = '';
            img.width = 180;
            img.height = 270;
            img.loading = 'lazy';
            img.decoding = 'async';
            img.addEventListener('error', () => {
                // Full-size cover missing? Retry the list thumbnail once, then
                // fall back to the typographic cover.
                if (item.cover_thumb && img.src !== item.cover_thumb) {
                    img.src = item.cover_thumb;
                    return;
                }
                img.remove();
                cover.classList.remove('has-image');
            });
            img.src = item.cover;
            cover.classList.add('has-image');
            cover.insertBefore(img, cover.querySelector('.cover-type').nextSibling);
        }

        const title = card.querySelector('.disc-title');
        title.textContent = item.name;
        title.title = item.name;
        if (item.url) title.href = item.url;
        else title.removeAttribute('href');

        const meta = card.querySelector('.disc-meta');
        card.querySelector('.disc-author').textContent = item.author || 'Unknown author';
        if (item.status) {
            const pill = document.createElement('span');
            pill.className = 'badge ' + (STATUS_CLASS[item.status] || 'badge-paused');
            pill.textContent = item.status;
            meta.appendChild(pill);
        }

        // Synopsis: clamped to three lines, expandable when there's more.
        // The search-only sources may return none — the block is dropped.
        const synopsis = card.querySelector('.disc-synopsis');
        if (item.description) synopsis.textContent = item.description;
        else synopsis.remove();

        const btn = card.querySelector('.disc-add');
        if (item.in_library) {
            markAdded(btn, 'In library');
        } else {
            btn.setAttribute('aria-label', `Add ${item.name} to library`);
            btn.title = 'Add to library';
            btn.addEventListener('click', () => addNovel(btn, item));
        }

        resultsEl.appendChild(card);

        // A synopsis that already fits needs no toggle. Measured in a frame
        // callback so the whole batch lays out once rather than per card.
        if (item.description) {
            requestAnimationFrame(() => {
                if (synopsis.scrollHeight <= synopsis.clientHeight + 1) return;
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'disc-more';
                toggle.textContent = 'More';
                toggle.setAttribute('aria-expanded', 'false');
                toggle.addEventListener('click', () => {
                    const expanded = synopsis.classList.toggle('is-expanded');
                    toggle.textContent = expanded ? 'Less' : 'More';
                    toggle.setAttribute('aria-expanded', String(expanded));
                });
                card.appendChild(toggle);
            });
        }
    }

    function markAdded(btn, label, href = null) {
        const el = href ? document.createElement('a') : btn;
        if (href) {
            el.href = href;
            el.className = btn.className;
            btn.replaceWith(el);
        } else {
            el.disabled = true;
        }
        el.classList.add('is-added');
        el.removeAttribute('aria-label');
        el.removeAttribute('title');
        el.innerHTML = '<span class="disc-add-label"></span>';
        el.querySelector('.disc-add-label').textContent = label;
    }

    async function addNovel(btn, item) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span class="disc-add-label">Adding…</span>';

        const reset = () => {
            btn.disabled = false;
            btn.innerHTML = '<span class="disc-add-plus" aria-hidden="true">+</span><span class="disc-add-label">Add</span>';
        };

        try {
            const result = await Novarr.executeCommand({
                command: 'create_novel',
                name: item.name,
                url: item.url,
            });

            if (result.success && !(result.output || '').includes('cancelled')) {
                // Command prints "New Novel ID: 70" — link straight to it.
                const idMatch = (result.output || '').match(/New Novel ID:\s*(\d+)/);
                markAdded(btn, idMatch ? 'Open novel →' : 'Added', idMatch ? `/novels/${idMatch[1]}` : null);
                Novarr.showToast(`"${item.name}" added — metadata and cover fetched. Open it to scrape the TOC.`, 'success');
            } else if ((result.output || '').includes('already exists')) {
                markAdded(btn, 'In library');
                Novarr.showToast(`"${item.name}" is already in your library.`, 'warning');
            } else {
                reset();
                Novarr.showToast(result.error || result.message || 'Failed to add novel.', 'danger');
            }
        } catch (err) {
            reset();
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

    // Only novelarrow has browse lists; other sources are search-only.
    sourceEl.addEventListener('change', () => {
        const src = source();
        const searchOnly = src !== 'novelarrow';
        tabsEl.classList.toggle('d-none', searchOnly);
        document.getElementById('discoverQuery').placeholder = `Search ${src}.com…`;
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
