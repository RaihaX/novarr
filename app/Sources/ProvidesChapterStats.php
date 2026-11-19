<?php

namespace App\Sources;

/**
 * A manga source that can tell, for a series URL, what it would download:
 * used to rank a reader request's shortlist
 * (App\Services\Requests\CandidateEvaluator). Never creates library rows.
 */
interface ProvidesChapterStats
{
    /**
     * The series' chapter list in the given languages (the source's own
     * where it serves one language): null when it could not be had.
     *
     * @param  string[]  $languages  preferred language codes ("en")
     * @return ?array{
     *     numbers: string[],          one per chapter (the TOC's numbers, "0" for unnumbered),
     *     languages: string[],        the languages these chapters are in (or that were asked for),
     *     in_preferred_language: bool, whether these chapters are in a preferred language,
     *     updated_at: ?string,        ISO-8601 of the newest chapter, when the source gives one
     *     requests: int               source requests made
     * }
     */
    public function chapterStats(string $url, array $languages): ?array;
}
