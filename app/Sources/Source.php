<?php

namespace App\Sources;

use App\Novel;

/**
 * A scraping source adapter: everything site-specific about one site —
 * which novels/URLs it handles, its table of contents, its metadata, an
 * optional chapter API fast path, content-extraction hints, and how it
 * appears on the Discover page. Adapters are registered in config
 * novarr.sources (see SourceResolver).
 *
 * Chapter content extraction is generic (App\Scraping\ContentExtractor);
 * a source only supplies hints for it — where the body lives, what to
 * strip, and how a chapter split over several pages links to its next
 * part. Extend AbstractSource to get sensible defaults for everything
 * optional.
 */
interface Source
{
    /** Does this source handle the given novel (by URL/group)? */
    public function matches(Novel $novel): bool;

    /** Does this source handle the given (novel or chapter) URL? */
    public function matchesUrl(string $url): bool;

    /**
     * Full chapter list as TOC rows (label, url, chapter, book), unfinalised
     * — the caller runs finalizeTocResult().
     */
    public function tableOfContents(Novel $novel): array;

    /**
     * Metadata for the novel:
     * ['description','author','no_of_chapters','image','genres','cover_candidates'].
     * cover_candidates is an ordered list of cover URLs to try downloading.
     */
    public function metadata(Novel $novel): array;

    /** Short label for logs/UI, e.g. "novelarrow". */
    public function name(): string;

    /**
     * What this source serves: 'novel' (chapter text) or 'manga' (page
     * images). A novel's `medium` is set from it when the novel is created.
     */
    public function medium(): string;

    /**
     * The page images of a manga chapter, in reading order. Null: this
     * source serves no pages (every text source). Otherwise a list of
     * ['url' => string, 'headers' => array<string,string>]; [] with $failure
     * set when the list could not be had: 'rate_limited', 'not_found',
     * 'no_content' or 'fetch_failed' (ChapterScraper's categories, so a dead
     * chapter parks and a rate limit backs off as for text).
     *
     * @return ?array<int, array{url: string, headers: array<string, string>}>
     */
    public function chapterPages(string $url, ?string &$failure = null): ?array;

    /**
     * Optional API fast path for a chapter. Null: this source has no fast
     * path for this URL (the page is fetched and extracted). Otherwise the
     * chapter's "<p>…</p>" paragraphs; [] when the fast path came back
     * empty, with $failure saying why ('rate_limited' stops the fetch;
     * anything else falls through to the page fetch).
     */
    public function chapterContent(string $url, ?string &$failure = null): ?array;

    /**
     * Ordered CSS selectors for the chapter body container. The first whose
     * paragraphs look like a whole chapter wins.
     *
     * @return string[]
     */
    public function contentSelectors(): array;

    /**
     * CSS selectors for nodes removed from a chapter page before extraction
     * (ad slots, nav bars, "report chapter" notices, share bars).
     *
     * @return string[]
     */
    public function removeSelectors(): array;

    /**
     * Selector for a "next part" link when the site splits one chapter over
     * several pages, or null when it never does.
     */
    public function supportsMultiPage(): ?string;

    /** Discover: the source picker's option text. */
    public function discoverLabel(): string;

    /** Discover: host named in status lines and errors ("novelfull.com"). */
    public function discoverHost(): string;

    /**
     * Discover: the lists this source offers, any of 'search', 'popular',
     * 'completed' (and the others in Discover::TYPES; 'trending' — new and
     * popular — and 'top' — top rated — are offered to readers by the
     * Requests page's Discover shelf, RequestBrowse). [] keeps the source
     * off the Discover page.
     *
     * @return string[]
     */
    public function discoverLists(): array;

    /**
     * Discover: result items for one of discoverLists() (query given for
     * 'search'), each ['name', 'url', 'cover', 'author', …], or null when
     * the site could not be reached.
     */
    public function discover(string $type, ?string $query = null): ?array;

    /**
     * Discover "in library" matching by slug: this source's slug for a URL,
     * or null when the URL isn't one of its slug URLs. $stored is true for
     * a library novel's stored URL (may accept legacy hosts).
     */
    public function librarySlug(string $url, bool $stored = false): ?string;

    /**
     * The download lane of a novel when its chapter host alone does not
     * name the upstream site (App\Services\DownloadLanes: at most one
     * download runs per lane). Null: the lane is the chapter host. Must not
     * make a request — it runs on hot paths; a lane that needs the network
     * to be known is backfilled by DownloadLanes.
     */
    public function downloadLane(Novel $novel): ?string;
}
