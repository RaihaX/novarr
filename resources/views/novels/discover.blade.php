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
@foreach ($sources as $key => $option)
        <option value="{{ $key }}">{{ $option['label'] }}</option>
@endforeach
    </select>
    <div class="btn-group segmented" role="group" aria-label="Browse mode" id="discoverTabs">
        <button type="button" class="btn btn-secondary discover-tab active" data-type="popular">Popular</button>
        <button type="button" class="btn btn-secondary discover-tab" data-type="new">New</button>
        <button type="button" class="btn btn-secondary discover-tab" data-type="random" title="A random page of the catalogue; click again for another">Random</button>
        <button type="button" class="btn btn-secondary discover-tab" data-type="completed">Completed</button>
    </div>
    <form id="discoverSearch" class="d-flex gap-2 flex-nowrap">
        <input type="search" id="discoverQuery" aria-label="Search source" class="form-control" placeholder="Search…" minlength="2">
        <button type="submit" class="btn btn-primary">Search</button>
    </form>
</div>

<p id="discoverStatus" class="library-status" role="status" aria-live="polite">Loading…</p>
<ul id="discoverResults" class="disc-grid mb-4" role="list" aria-busy="true"></ul>
{{-- Browse lists leave out novels already in the library; say how many. --}}
<p id="discoverHidden" class="library-status text-muted small d-none"></p>
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
    // Host shown for each source key ("novelarrow" now lives on novelping.com).
    const SOURCE_HOST = @json($sourceHosts);
    // Lists each source offers (search / popular / new / random / completed).
    const SOURCE_LISTS = @json($sourceLists);
    const sourceHost = () => SOURCE_HOST[source()] || `${source()}.com`;
    const hiddenEl = document.getElementById('discoverHidden');

    function showHidden(count) {
        hiddenEl.textContent = count > 0 ? `${count} already in your library hidden` : '';
        hiddenEl.classList.toggle('d-none', !(count > 0));
    }

    // Client-side reveal: the browse endpoint returns one page of a list
    // (up to 40); render it PAGE_SIZE at a time behind "Load more". Once
    // it is all shown and the source said `has_more` (manga sources), the
    // button fetches the next page (decisions in resources/js/discover-paging.js).
    const PAGE_SIZE = 20;
    const moreWrap = document.getElementById('discoverMoreWrap');
    const moreBtn = document.getElementById('discoverMore');
    let pendingItems = [];
    // The list being paged; replaced (so in-flight fetches are dropped) by every loadList().
    let paging = { type: null, q: '', page: 1, hasMore: false, seen: new Set(), shown: 0, hidden: 0, busy: false };
    const pagingLib = () => window.Novarr && window.Novarr.discoverPaging;

    function updateMore() {
        const lib = pagingLib();
        const visible = lib ? lib.showMore(pendingItems.length, paging.hasMore) : pendingItems.length > 0;
        moreWrap.classList.toggle('d-none', !visible);
        if (!paging.busy) {
            moreBtn.textContent = pendingItems.length > 0 ? `Load more (${pendingItems.length})` : 'Load more';
        }
    }

    function renderNextPage() {
        pendingItems.splice(0, PAGE_SIZE).forEach(renderCard);
        updateMore();
    }

    function revealMore() {
        const firstNew = resultsEl.children.length;
        renderNextPage();
        // Keep keyboard focus in the list: move it to the first newly shown card's button.
        resultsEl.children[firstNew]?.querySelector('button, a')?.focus({ preventScroll: true });
    }

    function setMoreBusy(busy) {
        paging.busy = busy;
        moreBtn.disabled = busy;
        moreBtn.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            moreBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading…';
        }
        updateMore();
    }

    // Fetch the next page(s) of the current list: pages that add nothing new
    // are followed automatically, up to AUTO_PAGES per click.
    async function fetchMore() {
        const lib = pagingLib();
        const ctx = paging;
        if (!lib || ctx.busy) return;
        setMoreBusy(true);
        let fetched = 0;
        try {
            while (true) {
                const params = new URLSearchParams({ type: ctx.type, source: ctx.source, page: String(ctx.page + 1) });
                if (ctx.q) params.set('q', ctx.q);
                const response = await fetch(`{{ route('novels.discover.browse') }}?${params}`, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();
                if (ctx !== paging) return;   // the list was switched meanwhile
                if (!data.success) throw new Error(data.message || 'Failed to load more results — try again shortly.');

                fetched++;
                ctx.page = Number(data.page) || ctx.page + 1;
                ctx.hasMore = Boolean(data.has_more);
                ctx.hidden += Number(data.hidden) || 0;
                showHidden(ctx.hidden);
                const fresh = lib.freshItems(data.items, ctx.seen);
                if (fresh.length) {
                    resultsEl.querySelector('.novels-empty')?.remove();
                    ctx.shown += fresh.length;
                    statusEl.textContent = `${ctx.shown} result${ctx.shown === 1 ? '' : 's'} from ${sourceHost()}`;
                    pendingItems.push(...fresh);
                }
                if (!lib.keepFetching(fresh.length, ctx.hasMore, fetched)) break;
            }
        } catch (err) {
            if (ctx === paging) Novarr.showToast(`Couldn’t load more: ${err.message}`, 'danger');
        } finally {
            if (ctx === paging) setMoreBusy(false);
        }
        if (ctx === paging && pendingItems.length) revealMore();
    }

    moreBtn.addEventListener('click', () => {
        const lib = pagingLib();
        const action = lib ? lib.moreAction(pendingItems.length, paging.hasMore) : (pendingItems.length ? 'reveal' : 'none');
        if (action === 'reveal') revealMore();
        else if (action === 'fetch') fetchMore();
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
        showHidden(0);
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

    // A new list (source / tab / search): paging starts over.
    function resetPaging(type = null, q = '') {
        if (paging.busy) moreBtn.disabled = false;
        paging = { type, q, source: source(), page: 1, hasMore: false, seen: new Set(), shown: 0, hidden: 0, busy: false };
        moreBtn.setAttribute('aria-busy', 'false');
        pendingItems = [];
        updateMore();
    }

    async function loadList(type, q = '') {
        lastQuery = q;
        showLoading();
        resetPaging(type, q);
        const ctx = paging;

        const params = new URLSearchParams({ type, source: source() });
        if (q) params.set('q', q);

        try {
            const response = await fetch(`{{ route('novels.discover.browse') }}?${params}`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();
            if (ctx !== paging) return;   // a newer list was asked for meanwhile
            endLoading();

            if (!data.success) {
                statusEl.textContent = '';
                showEmpty('Couldn’t reach the source', data.message || 'Failed to load results — try again shortly.');
                return;
            }

            const hidden = Number(data.hidden) || 0;
            showHidden(hidden);
            ctx.hidden = hidden;
            ctx.hasMore = Boolean(data.has_more);
            ctx.page = Number(data.page) || 1;
            data.items.forEach(item => { if (item.url) ctx.seen.add(String(item.url)); });
            ctx.shown = data.items.length;

            if (!data.items.length) {
                // Nothing on this page, but the source may have more.
                updateMore();
                statusEl.textContent = '';
                showEmpty(
                    q ? `Nothing on ${sourceHost()} matches “${q}”`
                        : (hidden > 0 ? 'Everything here is already in your library' : 'No results'),
                    hidden > 0 && !q
                        ? 'Search for a title instead, or browse another list. You can also add a novel by its URL.'
                        : 'Try a shorter title or the author’s name, or search another source. You can also add a novel by its URL.'
                );
                return;
            }

            statusEl.textContent = `${data.items.length} result${data.items.length === 1 ? '' : 's'} from ${sourceHost()}`;
            pendingItems = data.items.slice();
            renderNextPage();
        } catch (err) {
            if (ctx !== paging) return;
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

        if (item.cover_thumb || item.cover) {
            const img = document.createElement('img');
            img.alt = '';
            img.width = 180;
            img.height = 270;
            img.loading = 'lazy';
            img.decoding = 'async';
            // The source's list thumbnail first (a fraction of the bytes);
            // if it is missing, retry the full-size cover once, then fall
            // back to the typographic cover.
            const first = item.cover_thumb || item.cover;
            img.addEventListener('error', () => {
                if (item.cover && first !== item.cover && img.dataset.retried !== '1') {
                    img.dataset.retried = '1';
                    img.src = item.cover;
                    return;
                }
                img.remove();
                cover.classList.remove('has-image');
            });
            img.src = first;
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
        // Total chapters, when the source reports it. (The typographic cover
        // also carries it, but a real cover image hides that.)
        if (item.chapters) {
            const n = Number(item.chapters).toLocaleString();
            const chapters = document.createElement('span');
            chapters.className = 'disc-chapters';
            chapters.textContent = `${n} ch`;
            chapters.title = `${n} chapters`;
            chapters.setAttribute('aria-label', `${n} chapters`);
            meta.appendChild(chapters);
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

    // Sources without browse lists are search-only.
    sourceEl.addEventListener('change', () => {
        const src = source();
        const searchOnly = !(SOURCE_LISTS[src] || []).includes('popular');
        tabsEl.classList.toggle('d-none', searchOnly);
        document.getElementById('discoverQuery').placeholder = `Search ${sourceHost()}…`;
        document.getElementById('discoverQuery').value = '';
        if (searchOnly) {
            statusEl.textContent = `Search ${sourceHost()} to find a novel to add.`;
            statusEl.classList.remove('d-none');
            resultsEl.innerHTML = '';
            showHidden(0);
            resetPaging();
        } else {
            tabs.forEach((t, i) => t.classList.toggle('active', i === 0));
            loadList('popular');
        }
    });

    loadList('popular');

})();
</script>
@endpush
