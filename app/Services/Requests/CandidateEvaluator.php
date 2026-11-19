<?php

namespace App\Services\Requests;

use App\Novel;
use App\NovelRequest;
use App\Services\Discover;
use App\Sources\ProvidesChapterStats;
use App\Sources\SourceResolver;
use App\Sources\SuwayomiSource;
use Illuminate\Support\Facades\Log;

/**
 * Evaluate and rank a manga request's shortlist (RankRequestCandidates).
 *
 * Each candidate's source is asked for its chapter list
 * (ProvidesChapterStats::chapterStats()): MangaDex reads the series feed in
 * the preferred languages (one request per 500 chapters, paced as the TOC
 * is); Suwayomi runs one fetchChapters (Suwayomi itself fetches the chapter
 * list from the site). Candidates are asked one after another, never in
 * parallel, within an overall time budget (config
 * novarr.requests.rank_budget_seconds); a candidate not reached in time, or
 * whose source failed, keeps an `error` and ranks last.
 *
 * Preferred languages: Suwayomi's configured languages (default English).
 * Nothing is written to the library: only the request's candidates.
 */
class CandidateEvaluator
{
    public const OUT_OF_TIME = 'not checked in time';
    public const NO_ANSWER = 'the source did not answer';
    public const NOT_SUPPORTED = 'this source cannot be checked';
    public const GONE = 'the source is no longer set up';

    /** @return string[] */
    public static function preferredLanguages(): array
    {
        return SuwayomiSource::languages();
    }

    public function rank(NovelRequest $request): NovelRequest
    {
        $candidates = $request->candidateList();
        if ($request->medium !== Novel::MEDIUM_MANGA || $candidates === []) {
            return $request;
        }

        $languages = static::preferredLanguages();
        $deadline = microtime(true) + max(1, (int) config('novarr.requests.rank_budget_seconds', 300));
        $evaluated = [];
        foreach ($candidates as $candidate) {
            $evaluated[] = microtime(true) >= $deadline
                ? static::withError($candidate, self::OUT_OF_TIME)
                : $this->evaluate($candidate, $languages);
        }

        $ranked = CandidateRanking::rank(
            $evaluated,
            $this->sourceOrder($evaluated),
            (int) config('novarr.requests.rank_tie_chapters', CandidateRanking::TIE_CHAPTERS),
            (float) config('novarr.requests.rank_tie_percent', CandidateRanking::TIE_PERCENT),
        );
        $reasons = CandidateRanking::reasons($ranked, $languages, now());
        foreach ($ranked as $i => &$candidate) {
            unset($candidate['order'], $candidate['top_tier']);
            $candidate['reason'] = $reasons[$i];
        }
        unset($candidate);

        $request->candidates = $ranked;
        $request->candidates_ranked_at = now();
        $request->save();

        return $request;
    }

    /** One candidate with its evaluation fields (or an `error`). */
    public function evaluate(array $candidate, array $languages): array
    {
        $key = (string) ($candidate['source'] ?? '');
        $url = (string) ($candidate['url'] ?? '');
        try {
            $source = $key !== '' ? SourceResolver::get($key) : null;
        } catch (\Throwable) {
            $source = null;
        }
        if ($source === null) {
            return static::withError($candidate, self::GONE);
        }
        if (!$source instanceof ProvidesChapterStats) {
            return static::withError($candidate, self::NOT_SUPPORTED);
        }

        try {
            $stats = $source->chapterStats($url, $languages);
        } catch (\Throwable $e) {
            Log::warning("Request candidate {$key} {$url} could not be evaluated: " . $e->getMessage());
            $stats = null;
        }
        if ($stats === null) {
            return static::withError($candidate, self::NO_ANSWER);
        }

        $numbers = array_values((array) ($stats['numbers'] ?? []));
        $preferred = (bool) ($stats['in_preferred_language'] ?? false);

        return array_merge($candidate, [
            'languages' => array_values((array) ($stats['languages'] ?? [])),
            'in_preferred_language' => $preferred,
            'chapters_available' => $preferred ? count($numbers) : 0,
            'chapters_total' => count($numbers),
            'highest_chapter' => CandidateRanking::highest($numbers),
            'gaps' => CandidateRanking::gaps($numbers),
            'updated_at' => $stats['updated_at'] ?? null,
            'error' => null,
            'requests' => (int) ($stats['requests'] ?? 1),
            'evaluated_at' => now()->toIso8601String(),
        ]);
    }

    public static function withError(array $candidate, string $error): array
    {
        return array_merge($candidate, [
            'languages' => [],
            'in_preferred_language' => false,
            'chapters_available' => null,
            'chapters_total' => null,
            'highest_chapter' => null,
            'gaps' => null,
            'updated_at' => null,
            'error' => $error,
            'evaluated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Source key => position: config novarr.requests.source_order, then the
     * manga sources in Discover order, then any candidate source no longer
     * on Discover in shortlist order.
     *
     * @return array<string, int>
     */
    private function sourceOrder(array $candidates): array
    {
        try {
            $keys = array_map('strval', array_keys(app(Discover::class)->sourcesFor(Novel::MEDIUM_MANGA)));
        } catch (\Throwable) {
            $keys = [];
        }
        foreach ($candidates as $candidate) {
            $key = (string) ($candidate['source'] ?? '');
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return CandidateRanking::sourceOrder((array) config('novarr.requests.source_order', []), $keys);
    }
}
