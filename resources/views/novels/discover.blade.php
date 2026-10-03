@extends('layouts.app')

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

<p id="discoverStatus" class="library-status">Loading…</p>
<div id="discoverResults" class="novel-card-grid mb-4"></div>
{{-- Results arrive in one batch; reveal them 20 at a time. --}}
<div id="discoverMoreWrap" class="discover-more d-none">
    <button type="button" id="discoverMore" class="btn btn-secondary">Load more</button>
</div>
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

    // Skeleton cards + a spinner while the (sometimes slow, Cloudflare-gated)
    // source is fetched, instead of a bare "Loading…" string.
    function showLoading() {
        clearTimeout(slowTimer);
        statusEl.classList.remove('d-none');
        statusEl.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>Loading…';

        resultsEl.innerHTML = '';
        for (let i = 0; i < 8; i++) {
            const sk = document.createElement('div');
            sk.className = 'novel-card novel-card-skeleton';
            sk.setAttribute('aria-hidden', 'true');
            sk.innerHTML = '<div class="novel-card-head">'
                + '<div class="skeleton-box"></div>'
                + '<div class="novel-card-body w-100"><div class="skeleton-line"></div><div class="skeleton-line short"></div></div>'
                + '</div>'
                + '<div class="novel-card-synopsis-skeleton">'
                + '<div class="skeleton-line"></div><div class="skeleton-line"></div><div class="skeleton-line short"></div>'
                + '</div>';
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
        pendingItems = [];
        moreWrap.classList.add('d-none');
    }

    async function loadList(type, q = '') {
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
                statusEl.textContent = data.message || 'Failed to load results.';
                return;
            }

            if (!data.items.length) {
                statusEl.textContent = 'No results found.';
                return;
            }

            statusEl.textContent = `${data.items.length} result${data.items.length === 1 ? '' : 's'} from ${source()}.com`;
            pendingItems = data.items.slice();
            renderNextPage();
        } catch (err) {
            endLoading();
            statusEl.textContent = 'Error: ' + err.message;
        }
    }

    // Gradient placeholder carrying the mono mark (handoff §4 cover recipe).
    function makePlaceholderMark() {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 32 32');
        svg.setAttribute('fill', 'currentColor');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('class', 'brand-mark novel-card-cover-mark');
        svg.innerHTML = '<path d="M6 5h5v22H6z"/><path d="M11 5h5l10 22h-5z"/>'
            + '<path d="M21 13h5v14h-5z"/><path d="M21 5h5v8l-2.5-2.2L21 13z" opacity="0.55"/>';
        return svg;
    }

    function renderCard(item) {
        const card = document.createElement('div');
        card.className = 'novel-card';

        const head = document.createElement('div');
        head.className = 'novel-card-head';

        const cover = document.createElement('div');
        cover.className = 'novel-card-cover';

        if (item.cover) {
            cover.classList.add('has-image');
            const img = document.createElement('img');
            img.src = item.cover;
            img.alt = 'Cover of ' + item.name;
            img.loading = 'lazy';
            img.addEventListener('error', () => {
                // Full-size cover missing? Retry the list thumbnail once,
                // then give up and show the placeholder ground.
                if (item.cover_thumb && img.src !== item.cover_thumb) {
                    img.src = item.cover_thumb;
                    return;
                }
                img.remove();
                cover.classList.remove('has-image');
                cover.appendChild(makePlaceholderMark());
            });
            cover.appendChild(img);
        } else {
            cover.appendChild(makePlaceholderMark());
        }

        const body = document.createElement('div');
        body.className = 'novel-card-body';

        const title = item.url ? document.createElement('a') : document.createElement('span');
        title.className = 'novel-card-title';
        title.title = item.name;
        title.textContent = item.name;
        if (item.url) {
            title.href = item.url;
            title.target = '_blank';
            title.rel = 'noopener';
        }

        const meta = document.createElement('span');
        meta.className = 'novel-card-meta';
        meta.textContent = item.author || 'Unknown author';

        body.append(title, meta);

        if (item.in_library) {
            const badge = document.createElement('span');
            badge.className = 'badge badge-downloaded';
            badge.textContent = 'In library';
            body.appendChild(badge);
        }

        head.append(cover, body);

        // Synopsis: clamped to three lines, expandable when there's more.
        // Only novelarrow's list API carries one; the search-only sources
        // return an empty string, so the block is simply skipped.
        let synopsis = null;
        if (item.description) {
            synopsis = document.createElement('p');
            synopsis.className = 'novel-card-synopsis';
            synopsis.textContent = item.description;
        }

        const foot = document.createElement('div');
        foot.className = 'novel-card-foot';

        const actions = document.createElement('div');
        actions.className = 'novel-card-actions';

        const btn = document.createElement('button');
        btn.type = 'button';
        // Secondary, not primary: a grid of identical primary buttons drowns
        // the page's real primary action (indigo is reserved for that).
        btn.className = 'btn btn-secondary poster-add discover-add';
        btn.textContent = item.in_library ? 'Already added' : 'Add to library';
        btn.disabled = item.in_library;
        btn.addEventListener('click', () => addNovel(btn, item));

        actions.appendChild(btn);
        foot.appendChild(actions);

        card.append(head);
        if (synopsis) card.append(synopsis);
        card.append(foot);
        resultsEl.appendChild(card);

        // A synopsis that already fits needs no toggle. Measured in a frame
        // callback so the whole batch lays out once rather than per card.
        if (synopsis) {
            requestAnimationFrame(() => {
                if (synopsis.scrollHeight <= synopsis.clientHeight + 1) {
                    return;
                }
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'novel-card-synopsis-toggle';
                toggle.textContent = 'More';
                toggle.setAttribute('aria-expanded', 'false');
                toggle.addEventListener('click', () => {
                    const expanded = synopsis.classList.toggle('is-expanded');
                    toggle.textContent = expanded ? 'Less' : 'More';
                    toggle.setAttribute('aria-expanded', String(expanded));
                });
                card.insertBefore(toggle, foot);
            });
        }
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
                    link.className = 'btn btn-secondary poster-add';
                    link.textContent = 'Open novel →';
                    btn.replaceWith(link);
                } else {
                    btn.className = 'btn btn-secondary poster-add';
                    btn.textContent = 'Added ✓';
                }
                Novarr.showToast(`"${item.name}" added — metadata and cover fetched. Open it to scrape the TOC.`, 'success');
            } else if ((result.output || '').includes('already exists')) {
                btn.className = 'btn btn-secondary poster-add';
                btn.textContent = 'Already added';
                Novarr.showToast(`"${item.name}" is already in your library.`, 'warning');
            } else {
                btn.disabled = false;
                btn.className = 'btn btn-secondary poster-add discover-add';
                btn.textContent = 'Add to library';
                Novarr.showToast(result.error || result.message || 'Failed to add novel.', 'danger');
            }
        } catch (err) {
            btn.disabled = false;
            btn.className = 'btn btn-secondary poster-add discover-add';
            btn.textContent = 'Add to library';
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
