<?php

namespace App\Sources;

/**
 * A Discover source whose lists go on past the first page: the web
 * Discover page's "Load more" asks for page 2, 3, … while `has_more` says
 * there is another (App\Services\Discover::page()). Source::discover()
 * stays page 1's items, so callers that only want one page (API v1) are
 * unchanged.
 */
interface PagedDiscover
{
    /** The last page a client may ask for; beyond it a list is empty. */
    public const MAX_PAGE = 50;

    /**
     * One page (1-based) of one of discoverLists(): the items, in
     * Source::discover()'s shape, and whether the source has a page after
     * this one. A page may hold no items and still have more (a source
     * that filters its results). Beyond MAX_PAGE, or for a list the source
     * doesn't offer, ['items' => [], 'has_more' => false]. Null when the
     * source could not be reached.
     *
     * @return ?array{items: array<int, array>, has_more: bool}
     */
    public function discoverPage(string $type, ?string $query, int $page): ?array;
}
