<?php

namespace App\Comics;

use App\NovelRequest;
use App\Services\Discover;
use App\Services\Requests\RequestSearch;
use App\Sources\ComicCatalogueSource;
use App\Sources\SuwayomiSource;

/**
 * A comic request looked up on the Suwayomi comic sources
 * (config novarr.suwayomi.comic_sources, in that order).
 *
 * Comic sites name a run with its years ("Batman (2016-)", "Batman (2011 -
 * 2016)"), and one title has many runs, so a hit matches when its title
 * without the years is the request's and its start year agrees with the
 * request's (from the request title, else its catalogue candidate's start
 * year). The pick is the first match in source order, and only when the
 * matches agree on one run: with no known year and several runs it is left
 * to the owner (the matches become the request's candidates).
 */
class SuwayomiComicFinder
{
    public function __construct(private Discover $discover) {}

    /** Is there anything to ask: Suwayomi configured and a comic source named? */
    public static function enabled(): bool
    {
        return SuwayomiSource::configured() && SuwayomiSource::comicSources() !== [];
    }

    /**
     * A title without its run years and edition brackets, normalised:
     * "Absolute Batman (2024-)" and "Absolute Batman" are one. Pure.
     */
    public static function titleKey(string $title): string
    {
        $title = preg_replace('/\((?:[^()]*\b(?:19|20)\d{2}\b[^()]*)\)/u', ' ', $title) ?? $title;
        $title = preg_replace('/\[[^\]]*\]/u', ' ', $title) ?? $title;

        return Discover::normalizeName($title);
    }

    /** The start year a title names in brackets ("Batman (2016-)" → 2016), else null. Pure. */
    public static function year(?string $title): ?int
    {
        return preg_match('/\(\s*((?:19|20)\d{2})\b/u', (string) $title, $m) ? (int) $m[1] : null;
    }

    /** The year the request is for: its title's, else its catalogue candidate's start year. */
    public static function wantedYear(NovelRequest $request): ?int
    {
        if (($year = self::year((string) $request->title)) !== null) {
            return $year;
        }
        foreach ($request->candidateList() as $candidate) {
            if (($candidate['source'] ?? null) === ComicCatalogueSource::KEY
                && preg_match('/^(?:19|20)\d{2}$/', (string) ($candidate['status'] ?? ''))) {
                return (int) $candidate['status'];
            }
        }

        return null;
    }

    /**
     * Matches for the request on every comic source, in source order, and
     * the one to add (null when none, or when the runs are ambiguous).
     *
     * @return array{matches: array<int, array{source: string, source_name: string, url: string, title: string, cover_url: ?string, year: ?int}>, pick: ?array}
     */
    public function find(NovelRequest $request): array
    {
        $wantedKey = self::titleKey((string) $request->title);
        if ($wantedKey === '') {
            return ['matches' => [], 'pick' => null];
        }
        $wantedYear = self::wantedYear($request);
        // The sites search on words: the years would only narrow it wrongly.
        $query = trim(preg_replace('/\([^()]*\)/u', ' ', (string) $request->title) ?? '') ?: (string) $request->title;

        $matches = [];
        foreach (RequestSearch::suwayomiComicSources($this->discover) as $key => $source) {
            foreach (RequestSearch::ask($source, (string) $key, $query) ?? [] as $item) {
                $title = trim((string) ($item['name'] ?? $item['title'] ?? ''));
                $url = (string) ($item['url'] ?? '');
                if ($url === '' || self::titleKey($title) !== $wantedKey) {
                    continue;
                }
                $year = self::year($title);
                if ($wantedYear !== null && $year !== null && $year !== $wantedYear) {
                    continue;
                }
                $matches[] = [
                    'source' => (string) $key,
                    'source_name' => (string) ($source->sourceName() ?? $key),
                    'url' => $url,
                    'title' => $title,
                    'cover_url' => ($item['cover'] ?? '') !== '' ? (string) $item['cover'] : null,
                    'year' => $year,
                ];
            }
        }

        return ['matches' => $matches, 'pick' => self::pick($matches, $wantedYear)];
    }

    /**
     * The match to add: with a wanted year, the first of that year (else the
     * first without one); without, the first, if every match is one run
     * (one year, or none named). Pure.
     */
    public static function pick(array $matches, ?int $wantedYear): ?array
    {
        if ($matches === []) {
            return null;
        }
        if ($wantedYear !== null) {
            foreach ($matches as $match) {
                if ($match['year'] === $wantedYear) {
                    return $match;
                }
            }

            return $matches[0];
        }
        $years = array_unique(array_filter(array_column($matches, 'year'), fn($y) => $y !== null));

        return count($years) <= 1 ? $matches[0] : null;
    }
}
