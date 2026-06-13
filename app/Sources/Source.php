<?php

namespace App\Sources;

use App\Novel;

/**
 * A scraping source adapter. Encapsulates the two genuinely source-specific
 * operations — building the table of contents and fetching metadata — so
 * adding a new site is a single class rather than edits scattered across
 * Helpers.php and the metadata commands.
 *
 * Chapter *content* extraction is deliberately not here: chapterGenerator()
 * already pulls content generically via a shared multi-selector list that
 * covers every source, so there's nothing source-specific to encapsulate.
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

    /** Short label for logs/UI, e.g. "novelbin". */
    public function name(): string;
}
