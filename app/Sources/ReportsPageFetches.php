<?php

namespace App\Sources;

/**
 * Optional for a page-serving (manga) source: told about every page image
 * App\Scraping\PageDownloader fetched, successful or not — MangaDex requires
 * a report for each image served by a MangaDex@Home node. Best effort: an
 * implementation must never throw.
 */
interface ReportsPageFetches
{
    /**
     * @param array{url: string, headers: array<string, string>} $page the entry chapterPages() returned
     */
    public function reportPageFetch(array $page, bool $success, int $bytes, int $milliseconds, bool $cached): void;
}
