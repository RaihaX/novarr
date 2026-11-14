<?php

namespace App\Scraping;

use App\Novel;
use App\NovelChapter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Chapters the source still LISTS in its table of contents but no longer
 * SERVES (404, empty answer, stub page). Retrying each one on its own
 * backoff spends a request slot per chapter on a host everything else is
 * waiting for, so when ChapterScraper's content circuit trips (three such
 * answers in a row in one novel) the whole group is parked:
 *
 *  - novels.source_fail_streak goes up and novels.source_recheck_at is set
 *    to now + recheckSeconds(streak) (6 h, 12 h, 24 h ... capped at 7 days);
 *  - one bulk UPDATE moves next_attempt_at of the novel's pending,
 *    non-blacklisted chapters whose last failure was a content reason to
 *    source_recheck_at (never earlier than it already was; attempts are not
 *    touched).
 *
 * When the re-check comes due the sweep spends at most three requests on the
 * novel (the canary): it trips again and parks them for longer, or a chapter
 * downloads and release() sets the group due at once.
 */
class SourceUnavailable
{
    /** Failure categories that mean "the site answered, but not with this chapter". */
    public const CONTENT_REASONS = ['not_found', 'no_content', 'short_content'];

    /** First re-check delay (6 h); doubles per streak. */
    public const RECHECK_BASE_SECONDS = 21600;

    /** Longest re-check delay (7 days). */
    public const RECHECK_CAP_SECONDS = 604800;

    public static function isContentFailure(?string $category): bool
    {
        return $category !== null && in_array($category, self::CONTENT_REASONS, true);
    }

    /** Seconds until the re-check for a streak (1 = first trip). Pure. */
    public static function recheckSeconds(int $streak): int
    {
        $exponent = min(max($streak, 1) - 1, 20);

        return (int) min(self::RECHECK_BASE_SECONDS * (2 ** $exponent), self::RECHECK_CAP_SECONDS);
    }

    /**
     * The novel's chapters a park/release acts on: pending, not blacklisted,
     * last failed for a content reason. Query builder (no updated_at touch),
     * soft-delete scope applied.
     */
    private static function groupQuery(int $novelId)
    {
        return NovelChapter::query()
            ->where('novel_id', $novelId)
            ->where('status', 0)
            ->where('blacklist', 0)
            ->whereIn('last_failure_reason', self::CONTENT_REASONS)
            ->toBase();
    }

    /**
     * Park the novel's content-failed chapters until the next re-check.
     * Returns the re-check time and how many chapters are now parked.
     *
     * @return array{recheck_at: Carbon, parked: int, streak: int}
     */
    public static function park(Novel $novel): array
    {
        $streak = (int) ($novel->source_fail_streak ?? 0) + 1;
        $recheckAt = Carbon::now()->addSeconds(self::recheckSeconds($streak));

        $novel->forceFill([
            'source_fail_streak' => min($streak, 65535),
            'source_recheck_at' => $recheckAt,
        ])->saveQuietly();

        // Never shorten a later next_attempt_at.
        self::groupQuery((int) $novel->id)
            ->where(fn($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<', $recheckAt))
            ->update(['next_attempt_at' => $recheckAt]);

        $parked = self::groupQuery((int) $novel->id)
            ->where('next_attempt_at', '>=', $recheckAt)
            ->count();

        return ['recheck_at' => $recheckAt, 'parked' => $parked, 'streak' => $streak];
    }

    /**
     * A chapter of the novel downloaded: if it had parked chapters, clear the
     * streak and set the parked group due now. Returns how many were released.
     */
    public static function release(Novel $novel): int
    {
        if ((int) ($novel->source_fail_streak ?? 0) <= 0) {
            return 0;
        }

        $novel->forceFill(['source_fail_streak' => 0, 'source_recheck_at' => null])->saveQuietly();

        $released = self::groupQuery((int) $novel->id)
            ->whereNotNull('next_attempt_at')
            ->update(['next_attempt_at' => null]);

        Log::info("{$novel->name}: a chapter downloaded again; released {$released} parked chapter(s) for the next run.");

        return $released;
    }

    /**
     * Parked chapters per novel (pending, content-failed, waiting for a
     * future re-check) for novels with a streak. One grouped query.
     *
     * @param  int[]|null  $novelIds  null = every novel with a streak
     * @return array<int, array{count: int, reasons: array<string, int>}>  keyed by novel id
     */
    public static function parkedCounts(?array $novelIds = null): array
    {
        if ($novelIds === null) {
            $novelIds = Novel::where('source_fail_streak', '>', 0)->pluck('id')->all();
        }
        if ($novelIds === []) {
            return [];
        }

        $rows = NovelChapter::query()
            ->whereIn('novel_id', $novelIds)
            ->where('status', 0)
            ->where('blacklist', 0)
            ->whereIn('last_failure_reason', self::CONTENT_REASONS)
            ->where('next_attempt_at', '>', Carbon::now())
            ->selectRaw('novel_id, last_failure_reason, COUNT(*) as n')
            ->groupBy('novel_id', 'last_failure_reason')
            ->toBase()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->novel_id;
            $out[$id] ??= ['count' => 0, 'reasons' => []];
            $out[$id]['count'] += (int) $row->n;
            $out[$id]['reasons'][$row->last_failure_reason] = (int) $row->n;
        }

        return $out;
    }

    /**
     * Novels with parked chapters, soonest re-check first, each with
     * `parked_count` and `parked_reasons` set. For the Health page.
     *
     * @return \Illuminate\Support\Collection<int, Novel>
     */
    public static function parkedNovels()
    {
        $novels = Novel::where('source_fail_streak', '>', 0)
            ->orderBy('source_recheck_at')
            ->get(['id', 'name', 'source_fail_streak', 'source_recheck_at']);

        $counts = self::parkedCounts($novels->modelKeys());

        return $novels
            ->filter(fn(Novel $n) => isset($counts[$n->id]))
            ->each(function (Novel $n) use ($counts) {
                $n->parked_count = $counts[$n->id]['count'];
                $n->parked_reasons = $counts[$n->id]['reasons'];
            })
            ->values();
    }

    /** The reasons of a parked group in plain words ("not found or empty"). Pure. */
    public static function reasonWords(array $reasons): string
    {
        $words = [];
        foreach (['not_found' => 'not found', 'no_content' => 'empty', 'short_content' => 'only a stub'] as $key => $word) {
            if (!empty($reasons[$key])) {
                $words[] = $word;
            }
        }

        return $words === [] ? 'not found or empty' : implode(' or ', $words);
    }
}
