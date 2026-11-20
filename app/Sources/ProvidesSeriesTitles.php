<?php

namespace App\Sources;

use App\Novel;

/**
 * A manga source that can say what it calls a series: its title and any
 * alternative titles. App\Scraping\MangaFallback matches other sources'
 * search results against these as well as the Novarr name, so a series the
 * admin renamed in Novarr still finds its alternates. One cheap request;
 * the caller caches the answer.
 */
interface ProvidesSeriesTitles
{
    /**
     * The series' titles on this source, main title first; null when the
     * source could not be asked (or the novel is not one of its series).
     *
     * @return ?string[]
     */
    public function seriesTitles(Novel $novel): ?array;
}
