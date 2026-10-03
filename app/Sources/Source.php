<?php

namespace App\Sources;

use App\Novel;

/**
 * A scraping source adapter. Encapsulates the two genuinely source-specific
 * operations — building the table of contents and fetching metadata — so
 * adding a new site is a single class rather than edits scattered across
 * Helpers.php and the metadata commands.
 *
 * Chapter content extraction is generic (App\Scraping\ContentExtractor);
 * a source only supplies hints for it — where the body lives, what to
 * strip, and how a chapter split over several pages links to its next
 * part. Extend AbstractSource to get sensible defaults for those.
 */
interface Source
{
    /** Does this source handle the given novel (by URL/group)? */
    public function matches(Novel $novel): bool;

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
}
