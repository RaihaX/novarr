<?php

namespace App\Scraping;

/**
 * Outcome of one manga chapter download (PageDownloader::download()):
 * ok + the page count and bytes kept, or not ok with the failure category
 * ChapterScraper records ('rate_limited', 'not_found', 'no_content',
 * 'fetch_failed', 'partial_pages', 'too_few_pages') and a one-line reason
 * for the log. A 'too_few_pages' refusal carries the number of pages the
 * source listed ($listedPages), so the manga fallback can tell a short
 * chapter two sources agree on from a wrong one.
 */
final class PageDownloadResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $pages = 0,
        public readonly int $bytes = 0,
        public readonly ?string $category = null,
        public readonly ?string $message = null,
        public readonly ?int $listedPages = null,
    ) {
    }

    public static function success(int $pages, int $bytes): self
    {
        return new self(true, $pages, $bytes);
    }

    public static function failure(string $category, ?string $message = null, ?int $listedPages = null): self
    {
        return new self(false, 0, 0, $category, $message, $listedPages);
    }
}
