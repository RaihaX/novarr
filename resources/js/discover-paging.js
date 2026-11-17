/**
 * Discover "Load more" paging decisions (resources/views/novels/discover.blade.php,
 * through window.Novarr.discoverPaging). Pure — `yarn test:js`.
 *
 * The browse endpoint answers one page of a source's list plus `has_more`.
 * "Load more" first reveals the already-fetched items; once those are shown
 * and the source has more, it fetches the next page. A fetched page that adds
 * nothing new (every item a duplicate of an earlier page, or hidden because
 * it is in the library) is followed by the next one, at most AUTO_PAGES pages
 * per click.
 */

/** Pages one click may fetch while they keep adding nothing new. */
export const AUTO_PAGES = 3;

/**
 * The items of a fetched page not seen before, by `url` (sites can shift
 * items between pages). Adds their urls to `seen` (a Set). Items without a
 * url are kept.
 */
export function freshItems(items, seen) {
    const fresh = [];
    for (const item of items || []) {
        const url = item && item.url ? String(item.url) : '';
        if (url !== '') {
            if (seen.has(url)) continue;
            seen.add(url);
        }
        fresh.push(item);
    }
    return fresh;
}

/** What "Load more" does now: 'reveal' fetched items, 'fetch' the next page, or 'none'. */
export function moreAction(pendingCount, hasMore) {
    if (pendingCount > 0) return 'reveal';
    return hasMore ? 'fetch' : 'none';
}

/** Is the "Load more" button shown? */
export function showMore(pendingCount, hasMore) {
    return moreAction(pendingCount, hasMore) !== 'none';
}

/** After `fetched` pages in this click added `added` new items: fetch another without a click? */
export function keepFetching(added, hasMore, fetched) {
    return added === 0 && Boolean(hasMore) && fetched < AUTO_PAGES;
}
