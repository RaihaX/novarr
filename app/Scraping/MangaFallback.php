<?php

namespace App\Scraping;

use App\Console\Commands\ChapterScraper;
use App\MangaAlternate;
use App\MangaAlternateSearch;
use App\Novel;
use App\NovelChapter;
use App\NovelRequest;
use App\Services\Discover;
use App\Services\DownloadLanes;
use App\Services\Requests\RequestSearch;
use App\Services\Requests\SearchRelevance;
use App\Services\SourceHealth;
use App\Sources\ProvidesSeriesTitles;
use App\Sources\Source;
use App\Sources\SourceResolver;
use App\Sources\SuwayomiSource;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Automatic fallback source for manga chapters their own source keeps
 * failing on (`php artisan manga:fill-missing`, hourly). Manga only: two
 * translations of a text novel cannot be matched safely by chapter number.
 *
 * 1. Qualifying chapters (qualifyingQuery()): a manga's chapters that are
 *    pending, not blacklisted, not deleted, of a series that is not paused,
 *    whose last failure is the source's content failure —
 *      partial_pages / no_content / not_found with at least
 *        novarr.manga.fallback_after_attempts attempts;
 *      fetch_failed with that many attempts OR that many failed runs of the
 *        series (novels.scrape_failures): ChapterScraper::recordFailure()
 *        never counts a blocking failure past 1 attempt, so the chapter's
 *        own count cannot reach the threshold;
 *      or parked by SourceUnavailable: the series has a re-check streak
 *        (novels.source_fail_streak > 0) and the chapter's last failure is a
 *        content reason (not_found / no_content) — SourceUnavailable's group
 *        (groupQuery()), whatever its attempts.
 *    Never rate_limited (pace, not a broken chapter), never a chapter set
 *    aside (unavailable_until in the future, see 4.).
 *
 * 2. Alternates (refresh()): the other installed manga sources, in the
 *    reader-request search order (RequestSearch::sources(): source_order,
 *    source_name_preference, families), the series' own source left out;
 *    when a mirror family's best-placed member has no such series, the
 *    family's other members are searched in turn and the first that has it
 *    stands in at that rank (RequestSearch::standIns()),
 *    searched one at a time, at most fallback_max_sources per run; a cycle
 *    walks every source over several runs and starts again every
 *    fallback_search_days (or at once when every alternate it found went
 *    unusable). A search hit matches only when one of its titles normalises
 *    (normalizeTitle()) EXACTLY to the Novarr name, a title the series' own
 *    source reports (cached per cycle) or a title a reader request for the
 *    series carried. "Colored" editions match only a colored series. Of
 *    several exact hits on one source the one whose chapter count is
 *    closest to the series' own is kept.
 *
 * 3. Filling (processNovel(), fillChapter()): lowest chapter first, at most
 *    fallback_chapters_per_run per series, within the run's time budget;
 *    each chapter from the first alternate (preference order) that lists
 *    the same chapter number, through PageDownloader with the alternate's
 *    source and chapter URL (the row's url never changes). Success marks
 *    the row as ChapterScraper does and sets filled_from / filled_at. A
 *    failed alternate is counted and dropped at MAX_FAILURES consecutive
 *    failures until a refresh finds it again. Each download holds the
 *    alternate's download lane (DownloadLanes: a busy lane is skipped this
 *    run) and waits the lane's pace (HostPace) between chapters.
 *    A page list below minPages() is refused ('too_few_pages') before any
 *    image; that is our caution, not the alternate's fault, so it is never
 *    counted against it (no failure, no SourceHealth failure). When no
 *    alternate filled the chapter and at least two refused it with the SAME
 *    page count (>= 1), that is taken as the chapter's real length (a short
 *    extra) and it is downloaded from the first of them with the floor
 *    lowered to that count. One alternate alone below the floor stays
 *    refused.
 *
 * 4. Setting aside (setAside()): a chapter's attempt is conclusive when
 *    every alternate was considered and each one does not list the number,
 *    failed to deliver it now, or is dropped — and none was skipped for a
 *    passing reason (the run's deadline, a busy / rate-limited /
 *    cooling-down lane, a lane wait past the deadline, an unreadable chapter
 *    list, a rate_limited download). An unnumbered chapter is conclusively
 *    missing; so is every chapter of a series no source has at all once the
 *    cycle is complete. After a conclusive "missing" or "failed", and only
 *    once the series' search cycle is complete (every source searched), the
 *    chapter is set aside: novel_chapters.unavailable_until = the later of
 *    now + 1 day and cycle_started_at + fallback_search_days;
 *    next_attempt_at at least that; attempts raised to the threshold so it
 *    qualifies again at once when the date passes (last_failure_reason is
 *    kept). Nothing retries it before then: this class's queries leave it
 *    out, and so does ChapterScraper::whereDue() (whatever resets
 *    next_attempt_at). So one failing chapter cannot use up an alternate's
 *    failures run after run. A download of it (own source or a fill,
 *    ChapterScraper::updateMangaChapter()) clears the column. Never in a dry
 *    run (it says what it would do).
 *
 * 5. Recovery: a series with nothing stuck left after a run that filled or
 *    set aside a chapter has its failure state cleared (scrape_failures,
 *    the park streak); last_scrape_issue becomes null, or one calm line when
 *    set-aside chapters remain (setAsideMessage(): "3 chapters are not
 *    available from any source right now (…). Novarr looks again on …"), so
 *    it leaves Needs attention. The Health page's "Not available on the
 *    source" panel lists those series beside the parked ones
 *    (unavailableNovels()), and the series page shows such a chapter as
 *    "Unavailable".
 */
class MangaFallback
{
    /** Last-failure categories that make a chapter a candidate. */
    public const REASONS = ['partial_pages', 'no_content', 'not_found', 'fetch_failed'];

    /** Of those, the ones SourceUnavailable parks (its CONTENT_REASONS a manga can have). */
    public const PARKED_REASONS = ['not_found', 'no_content'];

    /** Consecutive failed fills after which an alternate is left alone until its next refresh. */
    public const MAX_FAILURES = 5;

    /** Two chapter numbers are the same within this. */
    public const NUMBER_EPSILON = 0.001;

    /** Chapter lists fetched per source when it has several exact title hits. */
    public const MAX_EXACT_TOCS = 3;

    /** Trailing tags that name how a copy is published, not another work (never the colour ones). */
    public const NEUTRAL_TAGS = ['official', 'manga', 'manhwa', 'manhua', 'webtoon', 'comic', 'english', 'eng'];

    /** A colored edition (a different set of pages). */
    public const COLORED = '/\b(?:colou?red|full[\s-]*colou?r)\b/iu';

    /** Unix time after which no more work is started (null = none). */
    private ?float $deadline = null;

    private bool $dryRun = false;

    /** @var \Closure(string): void|null */
    private ?\Closure $output = null;

    /** series URL => TOC rows (null = could not be had), for this run. */
    private array $tocs = [];

    /** lane => unix time the next chapter may start. */
    private array $nextAllowedAt = [];

    /** Lanes that rate-limited us this run. */
    private array $blockedLanes = [];

    private ?HostPace $pace = null;

    // ---- Configuration -------------------------------------------------------

    public static function enabled(): bool
    {
        return (bool) config('novarr.manga.fallback_enabled', true);
    }

    public static function attemptsThreshold(): int
    {
        return max(1, (int) config('novarr.manga.fallback_after_attempts', 3));
    }

    public static function searchDays(): int
    {
        return max(1, (int) config('novarr.manga.fallback_search_days', 7));
    }

    public static function maxSources(): int
    {
        return max(1, (int) config('novarr.manga.fallback_max_sources', 8));
    }

    public static function chaptersPerRun(): int
    {
        return max(1, (int) config('novarr.manga.fallback_chapters_per_run', 30));
    }

    public static function maxMinutes(): int
    {
        return max(1, (int) config('novarr.manga.fallback_max_minutes', 20));
    }

    // ---- Qualifying ------------------------------------------------------------

    /**
     * The novel's chapters that qualify (see the class doc), lowest chapter
     * first. The novel-level facts (manga, paused, failed runs, park streak)
     * are read from $novel.
     */
    public static function qualifyingQuery(Novel $novel)
    {
        $threshold = self::attemptsThreshold();
        $failedRuns = (int) ($novel->scrape_failures ?? 0) >= $threshold;
        $parked = (int) ($novel->source_fail_streak ?? 0) > 0;

        $query = NovelChapter::query()->where('novel_id', $novel->id);
        if (!$novel->isManga() || $novel->paused_at !== null) {
            return $query->whereRaw('1 = 0');
        }

        return self::notSetAside($query
            ->where('status', 0)
            ->where('blacklist', 0))
            ->where(function ($q) use ($threshold, $failedRuns, $parked) {
                $q->where(fn($r) => $r->whereIn('last_failure_reason', ['partial_pages', 'no_content', 'not_found'])
                    ->where('attempts', '>=', $threshold));
                $q->orWhere(fn($r) => $r->where('last_failure_reason', 'fetch_failed')
                    ->when(!$failedRuns, fn($a) => $a->where('attempts', '>=', $threshold)));
                if ($parked) {
                    // SourceUnavailable::groupQuery(): the parked group.
                    $q->orWhereIn('last_failure_reason', self::PARKED_REASONS);
                }
            })
            ->orderBy('chapter')
            ->orderBy('id');
    }

    /**
     * Manga series (not paused, not deleted) that have a qualifying chapter,
     * fewest-stuck-first is not needed: by id. $novelId limits it to one.
     *
     * @return Collection<int, Novel>
     */
    public static function candidateNovels(?int $novelId = null): Collection
    {
        $ids = self::notSetAside(NovelChapter::query()
            ->where('status', 0)
            ->where('blacklist', 0))
            ->whereIn('last_failure_reason', self::REASONS)
            ->when($novelId !== null, fn($q) => $q->where('novel_id', $novelId))
            ->distinct()
            ->pluck('novel_id')
            ->all();
        if ($ids === []) {
            return collect();
        }

        return Novel::query()->manga()->whereNull('paused_at')->whereIn('id', $ids)->orderBy('id')->get()
            ->filter(fn(Novel $novel) => self::qualifyingQuery($novel)->exists())
            ->values();
    }

    /** Pending chapters still failing for one of REASONS (any attempts), set-aside ones left out. */
    private static function stillFailing(Novel $novel): bool
    {
        return self::notSetAside(NovelChapter::query()
            ->where('novel_id', $novel->id)
            ->where('status', 0)
            ->where('blacklist', 0))
            ->whereIn('last_failure_reason', self::REASONS)
            ->exists();
    }

    /** Leave out chapters set aside as unavailable everywhere (unavailable_until in the future). */
    public static function notSetAside($query)
    {
        return $query->where(fn($q) => $q->whereNull('unavailable_until')->orWhere('unavailable_until', '<=', Carbon::now()));
    }

    // ---- Titles ----------------------------------------------------------------

    /**
     * The comparable form of a title: neutral publication tags ("(Official)")
     * dropped, "&" read as "and", lower case, accents and punctuation gone,
     * every "the" dropped, single spaces. Colour tags stay ("(Colored)" is
     * another set of pages). Pure.
     */
    public static function normalizeTitle(string $title): string
    {
        $title = html_entity_decode(trim($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tags = implode('|', array_map(fn($t) => preg_quote($t, '/'), self::NEUTRAL_TAGS));
        do {
            $before = $title;
            $title = preg_replace('/\s*[\(\[\{]\s*(?:' . $tags . ')\s*[\)\]\}]\s*$/iu', '', $title) ?? $title;
        } while ($title !== $before);

        $title = SearchRelevance::normalize(str_replace('&', ' and ', $title));
        $words = array_filter(explode(' ', $title), fn($w) => $w !== '' && $w !== 'the');

        return implode(' ', $words);
    }

    /** Normalised keys of titles ('' dropped). Pure. */
    public static function titleKeys(array $titles): array
    {
        $keys = [];
        foreach ($titles as $title) {
            $key = is_scalar($title) ? self::normalizeTitle((string) $title) : '';
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    public static function isColored(string $title): bool
    {
        return preg_match(self::COLORED, $title) === 1;
    }

    /**
     * Does a search hit (['name', 'alt_titles'?]) name the series exactly?
     * $wanted are the series' normalised titles. A colored edition matches
     * only a colored series. Pure.
     */
    public static function matchesTitles(array $item, array $wanted, bool $seriesColored): bool
    {
        $name = (string) ($item['name'] ?? '');
        if (!$seriesColored && self::isColored($name)) {
            return false;
        }
        $titles = array_merge([$name], array_filter((array) ($item['alt_titles'] ?? []), 'is_scalar'));
        foreach ($titles as $title) {
            if (!$seriesColored && self::isColored((string) $title)) {
                continue;
            }
            $key = self::normalizeTitle((string) $title);
            if ($key !== '' && in_array($key, $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    // ---- Run -------------------------------------------------------------------

    /**
     * One run over every candidate series (or $novelId). Returns counts.
     *
     * @param  \Closure(string): void|null  $output  a line for the console
     * @return array{novels: int, filled: int, failed: int, searched: int, planned: int, set_aside: int}
     */
    public function run(?int $novelId = null, bool $dryRun = false, bool $refresh = false, ?int $maxMinutes = null, ?\Closure $output = null): array
    {
        $this->dryRun = $dryRun;
        $this->output = $output;
        $this->deadline = $this->clock() + 60 * max(1, $maxMinutes ?? self::maxMinutes());
        $this->tocs = [];
        $this->nextAllowedAt = [];
        $this->blockedLanes = [];
        $stats = ['novels' => 0, 'filled' => 0, 'failed' => 0, 'searched' => 0, 'planned' => 0, 'set_aside' => 0];

        $novels = self::candidateNovels($novelId);
        if ($novels->isEmpty()) {
            $this->say($novelId !== null ? 'Nothing to fill: the series has no chapter that qualifies.' : 'Nothing to fill: no manga chapter qualifies.');
            return $stats;
        }

        foreach ($novels as $novel) {
            if ($this->pastDeadline()) {
                $this->note("Time budget used up; the rest waits for the next run.");
                break;
            }
            $stats['novels']++;

            $lock = $dryRun ? null : ChapterScraper::novelLock((int) $novel->id);
            if ($lock !== null && !$lock->get()) {
                $this->note("{$novel->name}: another download of this series is running; skipped.");
                continue;
            }
            try {
                $result = $this->processNovel($novel, $refresh);
                foreach ($result as $key => $n) {
                    $stats[$key] += $n;
                }
            } catch (\Throwable $e) {
                Log::error("Manga fallback: {$novel->name} (ID {$novel->id}) failed: " . $e->getMessage());
                $this->say("  ✗ {$novel->name}: " . $e->getMessage());
            } finally {
                $lock?->release();
            }
        }

        return $stats;
    }

    /** @return array{filled: int, failed: int, searched: int, planned: int, set_aside: int} */
    private function processNovel(Novel $novel, bool $refresh): array
    {
        $stats = ['filled' => 0, 'failed' => 0, 'searched' => 0, 'planned' => 0, 'set_aside' => 0];
        $stuck = self::qualifyingQuery($novel)->count();
        $this->note("{$novel->name}: {$stuck} chapter(s) its source keeps failing on.");

        [$alternates, $searched, $state] = $this->alternates($novel, $refresh);
        $stats['searched'] = $searched;
        // Every source searched in this cycle: a chapter none of them can
        // provide may be set aside (setAside()). Mid-cycle it may not.
        $cycleComplete = $state !== null && $state->cycleComplete();
        $usable = $alternates->filter(fn(MangaAlternate $a) => $a->failures < self::MAX_FAILURES)->values();
        if ($usable->isEmpty() && ($alternates->isNotEmpty() || !$cycleComplete)) {
            // Still searching, or everything it found went unusable (a new
            // search comes next run): nothing is concluded about the chapters.
            $this->note("{$novel->name}: no other source has it (yet).");
            return $stats;
        }
        if ($usable->isEmpty()) {
            $this->note("{$novel->name}: no other source has it (every source searched).");
        } else {
            $this->note("{$novel->name}: other sources: " . $usable->map(fn($a) => "{$a->source_name} ({$a->chapter_count} ch.)")->implode(', '));
        }

        $minPages = self::minPages($novel);
        $chapters = self::qualifyingQuery($novel)->limit(self::chaptersPerRun())->get();
        $hadUsable = $usable->isNotEmpty();

        foreach ($chapters as $chapter) {
            if ($this->pastDeadline()) {
                $this->note("Time budget used up; the rest waits for the next run.");
                break;
            }
            $result = $this->fillChapter($novel, $chapter, $usable, $minPages);
            $outcome = $result['outcome'];
            if ($outcome === 'filled') {
                $stats['filled']++;
            } elseif ($outcome === 'planned') {
                $stats['planned']++;
            } elseif ($outcome === 'failed') {
                $stats['failed']++;
            }
            if (in_array($outcome, ['missing', 'failed'], true) && $result['conclusive'] && $cycleComplete) {
                if ($this->setAside($novel, $chapter, $state)) {
                    $stats['set_aside']++;
                }
            }
            $usable = $usable->filter(fn(MangaAlternate $a) => $a->failures < self::MAX_FAILURES)->values();
            if ($hadUsable && $usable->isEmpty()) {
                // Every alternate went unusable during this run: the chapters
                // after this one were never tried on them, so nothing is
                // concluded about them (a new search comes next run).
                break;
            }
        }

        if (!$this->dryRun && ($stats['filled'] > 0 || $stats['set_aside'] > 0) && !self::stillFailing($novel)) {
            $aside = self::setAsideChapters((int) $novel->id);
            if ($aside->isEmpty()) {
                $this->clearFailureState($novel);
            } else {
                $this->clearFailureState($novel, self::setAsideMessage($aside));
            }
        }

        return $stats;
    }

    /**
     * Fill one chapter from the first alternate that has its number.
     *
     * 'outcome': 'filled', 'planned' (dry run), 'failed' (an alternate listed
     * it but none delivered it), 'missing' (no alternate asked lists the
     * number) or 'deferred' (out of time). 'conclusive': every alternate was
     * considered and each either does not list the number, failed to deliver
     * it now or is dropped (failures >= MAX_FAILURES) — none was skipped for a
     * passing reason (the run's deadline, a busy / rate-limited / cooling-down
     * lane, a lane wait past the deadline, an unreadable chapter list, a
     * rate_limited download).
     *
     * A page list below $minPages is refused ('too_few_pages'), which is not
     * the alternate's fault and not counted against it. When at least two
     * alternates refused it with the same page count, that count is taken as
     * the chapter's real length and it is downloaded from the first of them.
     *
     * @return array{outcome: string, conclusive: bool}
     */
    private function fillChapter(Novel $novel, NovelChapter $chapter, Collection $alternates, int $minPages): array
    {
        $number = (float) $chapter->chapter;
        $label = $chapter->label ?: "Chapter {$chapter->chapter}";
        if ($number <= 0) {
            // Unnumbered (oneshot / special): nothing safe to match on.
            $this->note("  {$label}: no chapter number to match on; skipped.");
            return ['outcome' => 'missing', 'conclusive' => true];
        }

        $conclusive = true;
        $listedBy = [];   // source names that list the number but did not deliver it
        $refusals = [];   // too_few_pages refusals, in preference order
        foreach ($alternates as $alternate) {
            if ($alternate->failures >= self::MAX_FAILURES) {
                continue;
            }
            if ($this->pastDeadline()) {
                $conclusive = false;
                break;
            }
            $lane = self::laneOf($alternate);
            if (isset($this->blockedLanes[$lane]) || ChapterScraper::hostCoolingDown($lane)) {
                $conclusive = false;
                continue;
            }

            $source = SourceResolver::forUrl($alternate->series_url);
            $fresh = !array_key_exists($alternate->series_url, $this->tocs);
            $rows = $this->toc($source, $alternate);
            if ($rows === null) {
                // Counted once per run, not once per chapter.
                if ($fresh) {
                    $this->countFailure($alternate, 'fetch_failed', "{$novel->name}: the chapter list on {$alternate->source_name} could not be read");
                }
                $conclusive = false;
                continue;
            }
            $row = self::findNumber($rows, $number);
            if ($row === null) {
                continue;
            }

            if ($this->dryRun) {
                $this->say("  would fetch {$label} from {$alternate->source_name}: {$row['url']}");
                return ['outcome' => 'planned', 'conclusive' => false];
            }

            $result = $this->downloadFrom($novel, $chapter, $alternate, $source, $lane, (string) $row['url'], $minPages, $label);
            if ($result === 'filled') {
                return ['outcome' => 'filled', 'conclusive' => true];
            }
            if ($result === 'deferred') {
                return ['outcome' => 'deferred', 'conclusive' => false];
            }
            $listedBy[] = $alternate->source_name;
            if ($result instanceof PageDownloadResult) {
                // too_few_pages: remembered for the agreement below.
                $refusals[] = ['alternate' => $alternate, 'source' => $source, 'lane' => $lane, 'url' => (string) $row['url'], 'pages' => $result->listedPages];
            } elseif ($result === 'passing') {
                $conclusive = false;
            }
        }

        // A short chapter two sources agree on is the chapter.
        $agreed = $this->pastDeadline() ? null : self::agreedShortCount($refusals);
        if ($agreed !== null) {
            [$count, $first, $names] = $agreed;
            $this->note("  {$label}: {$count} pages on both " . implode(' and ', $names) . "; taking it from {$first['alternate']->source_name} as a short chapter.");
            $result = $this->downloadFrom($novel, $chapter, $first['alternate'], $first['source'], $first['lane'], $first['url'], $count, $label);
            if ($result === 'filled') {
                return ['outcome' => 'filled', 'conclusive' => true];
            }
            if ($result === 'deferred') {
                return ['outcome' => 'deferred', 'conclusive' => false];
            }
            if ($result === 'passing') {
                $conclusive = false;
            }
        }

        $numberText = self::numberText($number);
        if ($listedBy === []) {
            $this->note($conclusive
                ? "  {$label}: no other source lists chapter {$numberText}."
                : "  {$label}: none of the other sources asked this run lists chapter {$numberText}; some could not be asked, so it is tried again next run.");

            return ['outcome' => 'missing', 'conclusive' => $conclusive];
        }

        $names = implode(', ', array_unique($listedBy));
        $this->note("  {$label}: listed on {$names}, but " . (count(array_unique($listedBy)) === 1 ? 'it' : 'none of them') . ' could deliver it'
            . ($conclusive ? '.' : ' this run; tried again next run.'));

        return ['outcome' => 'failed', 'conclusive' => $conclusive];
    }

    /**
     * One download from an alternate inside its lane. Returns 'filled',
     * 'deferred' (the lane's pace would pass the deadline), 'passing' (busy
     * lane or rate limit: not this run), 'failed' (counted against the
     * alternate) or, for a too_few_pages refusal (not counted), the result.
     */
    private function downloadFrom(Novel $novel, NovelChapter $chapter, MangaAlternate $alternate, Source $source, string $lane, string $url, int $minPages, string $label): string|PageDownloadResult
    {
        if (!$this->waitForLane($lane)) {
            // The lane's pace would run past the time budget: next run.
            return 'deferred';
        }
        $lock = DownloadLanes::lock($lane);
        if (!$lock->get()) {
            $this->note("  {$alternate->source_name} is downloading for another series; not used this run.");
            $this->blockedLanes[$lane] = true;
            return 'passing';
        }
        try {
            $result = $this->downloader()->download($chapter, $source, $url, $minPages);
        } finally {
            $lock->release();
        }
        $this->nextAllowedAt[$lane] = $this->clock() + $this->hostPace()->delay($lane);
        $this->hostPace()->record($lane, $result->ok ? null : $result->category);

        if ($result->ok) {
            $this->markFilled($chapter, $alternate, $result->pages);
            $alternate->forceFill(['failures' => 0])->save();
            SourceHealth::record($alternate->source_key, SourceHealth::KIND_CHAPTER, true);
            Log::info("Manga fallback: {$novel->name} {$label} filled from {$alternate->source_name} ({$result->pages} pages, {$url}).");
            $this->say("  ✓ {$label}: {$result->pages} pages from {$alternate->source_name}");

            return 'filled';
        }

        $category = $result->category ?: 'fetch_failed';
        $why = "{$novel->name} {$label} from {$alternate->source_name}: {$category}" . ($result->message ? " — {$result->message}" : '');
        if ($category === 'too_few_pages') {
            // A refusal on our side, not a broken alternate: never counted.
            Log::info("Manga fallback: {$why}; refused, not counted against the source.");
            $this->say("  – {$why}");

            return $result;
        }
        if ($category === 'rate_limited') {
            // Pace, not a broken alternate: leave the lane alone this run.
            $this->blockedLanes[$lane] = true;
            SourceHealth::record($alternate->source_key, SourceHealth::KIND_CHAPTER, false, $category, $why);
            Log::info("Manga fallback: {$why}; not using that source again this run.");
            $this->say("  ✗ {$why}");

            return 'passing';
        }
        $this->countFailure($alternate, $category, $why);

        return 'failed';
    }

    /**
     * Of too_few_pages refusals (preference order), the page count at least
     * two of them listed (>= 1), the first of those and the names of all of
     * them — the count whose first refusal comes earliest. Null when no two
     * agree. Pure.
     *
     * @param  array<int, array{alternate: MangaAlternate, pages: ?int}>  $refusals
     * @return array{0: int, 1: array, 2: string[]}|null
     */
    public static function agreedShortCount(array $refusals): ?array
    {
        $groups = [];
        foreach ($refusals as $refusal) {
            $pages = (int) ($refusal['pages'] ?? 0);
            if ($pages >= 1) {
                $groups[$pages][] = $refusal;
            }
        }
        foreach ($groups as $pages => $group) {
            if (count($group) >= 2) {
                // Groups keep the order of their first refusal: the earliest wins.
                $names = array_values(array_unique(array_map(fn($r) => $r['alternate']->source_name, $group)));

                return [(int) $pages, $group[0], $names];
            }
        }

        return null;
    }

    /**
     * Set a chapter no source can provide aside: unavailable_until = the
     * later of a day from now and the end of the current search cycle;
     * next_attempt_at at least that; attempts raised to the threshold so it
     * qualifies at once when it comes back. last_failure_reason untouched.
     * Dry run: only says so. Returns whether it was set aside.
     */
    private function setAside(Novel $novel, NovelChapter $chapter, MangaAlternateSearch $state): bool
    {
        $until = self::setAsideUntil($state);
        $label = $chapter->label ?: "Chapter {$chapter->chapter}";
        $date = self::dateText($until);
        if ($this->dryRun) {
            $this->say("  would set {$label} aside until {$date}: no source can provide it");
            return false;
        }

        $next = $chapter->next_attempt_at;
        $chapter->forceFill([
            'unavailable_until' => $until,
            'next_attempt_at' => $next !== null && $next->gt($until) ? $next : $until,
            'attempts' => max((int) $chapter->attempts, self::attemptsThreshold()),
        ])->saveQuietly();
        $this->note("  {$label}: no source can provide it; set aside until {$date}.");
        Log::info("Manga fallback: {$novel->name} {$label} set aside until {$until->toDateTimeString()} (no source can provide it).");

        return true;
    }

    /** The later of a day from now and the cycle's start + fallback_search_days. */
    public static function setAsideUntil(?MangaAlternateSearch $state): Carbon
    {
        $until = Carbon::now()->addDay();
        $cycleEnd = $state?->cycle_started_at?->copy()->addDays(self::searchDays());
        if ($cycleEnd !== null && $cycleEnd->gt($until)) {
            $until = Carbon::instance($cycleEnd);
        }

        return $until;
    }

    /** The series' chapters set aside now (pending, unavailable_until in the future), lowest first. */
    public static function setAsideChapters(int $novelId): Collection
    {
        return NovelChapter::query()
            ->where('novel_id', $novelId)
            ->where('status', 0)
            ->where('blacklist', 0)
            ->where('unavailable_until', '>', Carbon::now())
            ->orderBy('chapter')
            ->orderBy('id')
            ->get(['id', 'chapter', 'label', 'unavailable_until']);
    }

    /**
     * The calm issue line of a series whose only stuck chapters are set
     * aside: "3 chapters are not available from any source right now (45.5,
     * 146.5, 723). Novarr looks again on 1 Dec 2026." At most five numbers.
     * Pure but for the app timezone.
     */
    public static function setAsideMessage(Collection $chapters): string
    {
        $n = $chapters->count();
        $names = $chapters->take(5)->map(fn($c) => (float) $c->chapter > 0 ? self::numberText((float) $c->chapter) : ($c->label ?: '?'))->all();
        $list = implode(', ', $names) . ($n > 5 ? ' and ' . ($n - 5) . ' more' : '');
        $earliest = $chapters->min(fn($c) => $c->unavailable_until?->getTimestamp() ?? PHP_INT_MAX);
        $when = $earliest !== null && $earliest !== PHP_INT_MAX ? self::dateText(Carbon::createFromTimestamp($earliest)) : 'later';

        return ($n === 1 ? '1 chapter is' : "{$n} chapters are") . " not available from any source right now ({$list}). Novarr looks again on {$when}.";
    }

    /** "1 Dec 2026" in the app timezone. */
    public static function dateText(Carbon $time): string
    {
        return $time->copy()->setTimezone(config('app.timezone'))->format('j M Y');
    }

    /** Mark the row downloaded as ChapterScraper does, plus where the pages came from. url untouched. */
    private function markFilled(NovelChapter $chapter, MangaAlternate $alternate, int $pages): void
    {
        $chapter->forceFill([
            'filled_from' => mb_substr($alternate->source_name, 0, 191),
            'filled_at' => Carbon::now(),
        ]);
        ChapterScraper::updateMangaChapter($chapter, $pages);
    }

    private function countFailure(MangaAlternate $alternate, string $category, string $why): void
    {
        $alternate->forceFill([
            'failures' => min(65535, $alternate->failures + 1),
            'last_failed_at' => Carbon::now(),
        ]);
        if (!$this->dryRun) {
            $alternate->save();
            SourceHealth::record($alternate->source_key, SourceHealth::KIND_CHAPTER, false, $category, $why);
        }
        $dropped = $alternate->failures >= self::MAX_FAILURES ? '; not used again until the next search' : '';
        Log::warning("Manga fallback: {$why} (failure {$alternate->failures} in a row{$dropped}).");
        $this->say("  ✗ {$why}");
    }

    /**
     * The manual fix's last step: scrape_failures, last_scrape_issue, the
     * park streak. $issue: the calm line when set-aside chapters remain.
     */
    private function clearFailureState(Novel $novel, ?string $issue = null): void
    {
        $novel->refresh();
        SourceUnavailable::release($novel);
        $novel->forceFill([
            'scrape_failures' => 0,
            'last_scrape_issue' => $issue,
            'source_fail_streak' => 0,
            'source_recheck_at' => null,
        ])->saveQuietly();
        if ($issue === null) {
            Log::info("Manga fallback: {$novel->name} has no stuck chapters left; its failure state is cleared.");
            $this->say("  {$novel->name}: nothing stuck left; failure state cleared.");
        } else {
            Log::info("Manga fallback: {$novel->name}: {$issue} Its failure state is cleared.");
            $this->say("  {$novel->name}: {$issue} Failure state cleared.");
        }
    }

    /**
     * Smallest page count accepted: a third of the series' median downloaded
     * chapter, at least 3 unless its chapters are typically shorter; 3 when
     * nothing is downloaded yet.
     */
    public static function minPages(Novel $novel): int
    {
        $counts = NovelChapter::query()
            ->where('novel_id', $novel->id)
            ->where('status', 1)
            ->where('page_count', '>', 0)
            ->orderBy('page_count')
            ->pluck('page_count')
            ->map(fn($n) => (int) $n)
            ->all();
        if ($counts === []) {
            return 3;
        }
        $n = count($counts);
        $median = $n % 2 ? $counts[intdiv($n, 2)] : ($counts[$n / 2 - 1] + $counts[$n / 2]) / 2;

        return (int) max(1, min(3, (int) floor($median)), (int) ceil($median / 3));
    }

    /** The first TOC row with this chapter number, in the alternate's order. Pure. */
    public static function findNumber(array $rows, float $number): ?array
    {
        foreach ($rows as $row) {
            $raw = $row['chapter'] ?? null;
            if (is_numeric($raw) && abs((float) $raw - $number) < self::NUMBER_EPSILON && !empty($row['url'])) {
                return $row;
            }
        }

        return null;
    }

    /** The alternate's download lane: its Suwayomi source, else its host. */
    public static function laneOf(MangaAlternate $alternate): string
    {
        if (str_starts_with($alternate->source_key, SuwayomiSource::LANE_PREFIX)) {
            return $alternate->source_key;
        }
        $host = parse_url($alternate->series_url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : DownloadLanes::NO_HOST;
    }

    /** The alternate's chapter list, once per run. Null when it could not be had. */
    private function toc(Source $source, MangaAlternate $alternate): ?array
    {
        $url = $alternate->series_url;
        if (!array_key_exists($url, $this->tocs)) {
            $this->tocs[$url] = $this->fetchToc($source, $url, $alternate->title);
        }

        return $this->tocs[$url];
    }

    private function fetchToc(Source $source, string $url, string $title): ?array
    {
        $probe = new Novel();
        $probe->forceFill(['name' => $title, 'translator_url' => $url, 'medium' => Novel::MEDIUM_MANGA]);
        try {
            $rows = $source->tableOfContents($probe);
        } catch (\Throwable $e) {
            Log::warning("Manga fallback: chapter list of {$url} failed: " . $e->getMessage());
            return null;
        }

        return is_array($rows) && $rows !== [] ? array_values($rows) : null;
    }

    // ---- Alternates ------------------------------------------------------------

    /**
     * The series' alternates in preference order (refreshed when due) and
     * how many sources were searched for it this run.
     *
     * @return array{0: Collection<int, MangaAlternate>, 1: int, 2: ?MangaAlternateSearch}
     */
    private function alternates(Novel $novel, bool $force): array
    {
        $state = MangaAlternateSearch::where('novel_id', $novel->id)->first();
        $alternates = MangaAlternate::where('novel_id', $novel->id)->orderBy('rank')->orderBy('id')->get();

        if (!self::refreshDue($state, $alternates, $force)) {
            return [$alternates, 0, $state];
        }

        return $this->refresh($novel, $state, $alternates, $force);
    }

    /**
     * Is a search due? Never searched, forced, the cycle is older than
     * fallback_search_days, the cycle has sources left, or every alternate
     * went unusable after the last full cycle. Pure but for the clock.
     */
    public static function refreshDue(?MangaAlternateSearch $state, Collection $alternates, bool $force = false): bool
    {
        if ($force || $state === null || $state->cycle_started_at === null) {
            return true;
        }
        if ($state->cycle_started_at->lt(Carbon::now()->subDays(self::searchDays()))) {
            return true;
        }
        if (!$state->cycleComplete()) {
            return true;
        }

        $usable = $alternates->filter(fn(MangaAlternate $a) => $a->failures < self::MAX_FAILURES);
        if ($usable->isEmpty() && $alternates->isNotEmpty()) {
            // Everything it found has since failed: look again now.
            $lastFailed = $alternates->max(fn(MangaAlternate $a) => $a->last_failed_at?->getTimestamp() ?? 0);

            return $lastFailed >= $state->cycle_completed_at->getTimestamp();
        }

        return false;
    }

    /** @return array{0: Collection<int, MangaAlternate>, 1: int, 2: MangaAlternateSearch} */
    private function refresh(Novel $novel, ?MangaAlternateSearch $state, Collection $alternates, bool $force): array
    {
        $now = Carbon::now();
        $state ??= new MangaAlternateSearch(['novel_id' => $novel->id]);
        $newCycle = $force
            || !$state->exists
            || $state->cycle_started_at === null
            || $state->cycle_started_at->lt($now->copy()->subDays(self::searchDays()))
            || $state->cycleComplete();

        if ($newCycle) {
            $state->forceFill(['cursor' => 0, 'cycle_started_at' => $now, 'cycle_completed_at' => null]);
            $titles = $this->ownSourceTitles($novel);
            if ($titles !== null) {
                $state->source_titles = $titles;
            }
        } elseif (empty($state->source_titles)) {
            $titles = $this->ownSourceTitles($novel);
            if ($titles !== null) {
                $state->source_titles = $titles;
            }
        }

        $wantedTitles = self::wantedTitles($novel, (array) ($state->source_titles ?? []));
        $wanted = self::titleKeys($wantedTitles);
        $colored = self::isColored(implode(' ', $wantedTitles));
        $queries = self::searchQueries($novel, (array) ($state->source_titles ?? []));
        $ownCount = NovelChapter::where('novel_id', $novel->id)->count();

        $sources = $this->searchSources($novel);
        $standIns = $this->standIns($novel);
        $keys = array_keys($sources);
        $cursor = min((int) $state->cursor, count($keys));
        $slice = array_slice($keys, $cursor, self::maxSources());

        $byKey = $alternates->keyBy('source_key');
        $searched = 0;
        foreach ($slice as $offset => $key) {
            if ($this->pastDeadline()) {
                break;
            }
            $source = $sources[$key];
            $searched++;
            $found = $this->searchSource($novel, $key, $source, $queries, $wanted, $colored, $ownCount);
            $rank = $cursor + $offset;

            if ($found === false) {
                // The source could not be asked: keep what was known.
                continue;
            }
            if ($found === null) {
                $existing = $byKey->get($key);
                if ($existing !== null) {
                    if (!$this->dryRun) {
                        $existing->delete();
                    }
                    $byKey->forget($key);
                }
                // A mirror family's best-placed member has no such series:
                // its other members (left out of the search order) are
                // asked in turn, best placed first; the first that has it
                // stands in at the same rank.
                foreach ($standIns[$key] ?? [] as $standInKey => $standIn) {
                    if ($this->pastDeadline()) {
                        break;
                    }
                    $standInFound = $this->searchSource($novel, (string) $standInKey, $standIn, $queries, $wanted, $colored, $ownCount);
                    if (is_array($standInFound)) {
                        $this->note('  ' . self::displayName($source, $key) . ' has no such series: trying its mirror ' . self::displayName($standIn, (string) $standInKey));
                        [$key, $source, $found] = [(string) $standInKey, $standIn, $standInFound];
                        break;
                    }
                }
                if ($found === null) {
                    continue;
                }
            } else {
                // The member itself has it: a stand-in found in an earlier cycle gives way.
                foreach (array_keys($standIns[$key] ?? []) as $standInKey) {
                    $old = $byKey->get((string) $standInKey);
                    if ($old !== null) {
                        if (!$this->dryRun && $old->exists) {
                            $old->delete();
                        }
                        $byKey->forget((string) $standInKey);
                    }
                }
            }
            $existing = $byKey->get($key);

            $alternate = $existing ?? new MangaAlternate(['novel_id' => $novel->id, 'source_key' => $key, 'found_at' => $now]);
            $alternate->forceFill([
                'source_name' => mb_substr(self::displayName($source, $key), 0, 191),
                'series_url' => $found['url'],
                'title' => mb_substr($found['title'], 0, 255),
                'rank' => $rank,
                'chapter_count' => $found['count'],
                'max_chapter' => $found['max'],
                'checked_at' => $now,
                // Found again: a fresh start.
                'failures' => 0,
            ]);
            if ($existing !== null && $existing->series_url !== $found['url']) {
                $alternate->found_at = $now;
            }
            if (!$this->dryRun) {
                $alternate->save();
            }
            $byKey->put($key, $alternate);
            $this->note("  found on {$alternate->source_name}: \"{$alternate->title}\" ({$alternate->chapter_count} chapters)");
        }

        $state->cursor = $cursor + $searched;
        $state->sources_total = count($keys);
        $state->searched_at = $now;
        if ($state->cursor >= count($keys)) {
            $state->cycle_completed_at = $now;
            // Sources searched in no part of this cycle (uninstalled since): forget them.
            $searchable = $sources;
            foreach ($standIns as $members) {
                $searchable += $members;
            }
            foreach ($byKey as $key => $alternate) {
                if (!isset($searchable[$key]) || ($alternate->checked_at !== null && $alternate->checked_at->lt($state->cycle_started_at))) {
                    if (!$this->dryRun && $alternate->exists) {
                        $alternate->delete();
                    }
                    $byKey->forget($key);
                }
            }
        }
        if (!$this->dryRun) {
            $state->save();
        }

        $list = $byKey->values()->sortBy([['rank', 'asc'], ['id', 'asc']])->values();
        Log::info("Manga fallback: searched {$searched} source(s) for {$novel->name}; " . ($list->isEmpty() ? 'no other source has it so far' : 'alternates: ' . $list->pluck('source_name')->implode(', ')) . '.');

        return [$list, $searched, $state];
    }

    /**
     * Search one source. The best exact hit as ['url', 'title', 'count',
     * 'max'], null when it has none, false when the source could not be asked.
     */
    private function searchSource(Novel $novel, string $key, Source $source, array $queries, array $wanted, bool $colored, int $ownCount): array|null|false
    {
        $hits = [];
        $answered = false;
        foreach ($queries as $query) {
            $items = RequestSearch::ask($source, $key, $query);
            if ($items === null) {
                continue;
            }
            $answered = true;
            foreach ($items as $item) {
                $url = (string) ($item['url'] ?? '');
                if ($url === '' || isset($hits[$url]) || self::isOwnSeries($novel, $source, $url)) {
                    continue;
                }
                if (self::matchesTitles($item, $wanted, $colored)) {
                    $hits[$url] = (string) ($item['name'] ?? '');
                }
            }
        }
        if (!$answered) {
            Log::info("Manga fallback: {$key} could not be searched for {$novel->name}.");
            return false;
        }
        if ($hits === []) {
            return null;
        }

        $best = null;
        $tocSource = SourceResolver::forUrl((string) array_key_first($hits));
        foreach (array_slice($hits, 0, self::MAX_EXACT_TOCS, true) as $url => $title) {
            $rows = $this->tocs[$url] ??= $this->fetchToc($tocSource, $url, $title);
            if ($rows === null) {
                continue;
            }
            $numbers = array_map('floatval', array_filter(array_column($rows, 'chapter'), 'is_numeric'));
            $candidate = ['url' => $url, 'title' => $title, 'count' => count($rows), 'max' => $numbers === [] ? null : max($numbers)];
            if ($best === null || abs($candidate['count'] - $ownCount) < abs($best['count'] - $ownCount)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /** Is $url the series itself (same source, same series)? */
    private static function isOwnSeries(Novel $novel, Source $source, string $url): bool
    {
        $own = (string) $novel->translator_url;
        if ($own === '') {
            return false;
        }
        try {
            $slug = $source->librarySlug($url);

            return $slug !== null && $slug === $source->librarySlug($own, stored: true);
        } catch (\Throwable) {
            return rtrim(strtolower($url), '/') === rtrim(strtolower($own), '/');
        }
    }

    /**
     * The installed manga sources to search, in the reader-request search
     * order, the series' own source left out. Overridable for tests.
     *
     * @return array<string, Source>
     */
    protected function searchSources(Novel $novel): array
    {
        $own = SourceHealth::keyForNovel($novel);
        $sources = app(RequestSearch::class)->sources(app(Discover::class), Novel::MEDIUM_MANGA);

        return array_filter($sources, fn(Source $s, string $key) => $key !== $own, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The mirror-family members left out of searchSources(), per kept
     * member (RequestSearch::standIns()), the series' own source left out.
     * Overridable for tests.
     *
     * @return array<string, array<string, Source>>
     */
    protected function standIns(Novel $novel): array
    {
        $own = SourceHealth::keyForNovel($novel);
        $result = [];
        foreach (app(RequestSearch::class)->standIns(app(Discover::class), Novel::MEDIUM_MANGA) as $winner => $members) {
            unset($members[$own]);
            if ($members !== []) {
                $result[$winner] = $members;
            }
        }

        return $result;
    }

    /** The titles the series' own source reports for it, or null when it could not say. */
    private function ownSourceTitles(Novel $novel): ?array
    {
        try {
            $source = SourceResolver::for($novel);
            if (!$source instanceof ProvidesSeriesTitles) {
                return null;
            }
            $titles = $source->seriesTitles($novel);
        } catch (\Throwable $e) {
            Log::info("Manga fallback: the own source's title of {$novel->name} could not be had: " . $e->getMessage());
            return null;
        }

        return $titles === null ? null : array_values(array_slice(array_filter(array_map('strval', $titles), fn($t) => trim($t) !== ''), 0, 30));
    }

    /** Every title the series is known by: the Novarr name, the own source's, reader requests'. */
    public static function wantedTitles(Novel $novel, array $sourceTitles): array
    {
        $titles = array_merge([(string) $novel->name], $sourceTitles);
        try {
            $titles = array_merge($titles, NovelRequest::where('novel_id', $novel->id)->pluck('title')->all());
        } catch (\Throwable) {
            // No requests table: the name and source titles are enough.
        }

        return array_values(array_unique(array_filter(array_map(fn($t) => trim((string) $t), $titles), fn($t) => $t !== '')));
    }

    /** What to type into a source's search: the Novarr name, and the own source's title when it differs. */
    public static function searchQueries(Novel $novel, array $sourceTitles): array
    {
        $queries = [];
        foreach (array_merge([(string) $novel->name], array_slice($sourceTitles, 0, 1)) as $title) {
            $key = self::normalizeTitle($title);
            if ($key !== '' && !isset($queries[$key])) {
                $queries[$key] = trim($title);
            }
        }

        return array_values($queries);
    }

    /** "Weeb Central", "MangaDex" (a Discover label's medium suffix dropped). */
    public static function displayName(Source $source, string $key): string
    {
        $name = RequestSearch::displayName($source, $key);

        return trim(preg_replace('/\s*\((?:manga|novel)\)\s*$/i', '', $name) ?? $name) ?: $key;
    }

    // ---- Pace --------------------------------------------------------------------

    /** Wait out the lane's pace; false (no wait) when that would pass the run's deadline. */
    private function waitForLane(string $lane): bool
    {
        $wait = (int) ceil(($this->nextAllowedAt[$lane] ?? 0.0) - $this->clock());
        if ($wait > 0 && $this->deadline !== null && $this->clock() + $wait > $this->deadline) {
            return false;
        }
        if ($wait > 0) {
            Log::info("Manga fallback: waiting {$wait} seconds before the next chapter from " . DownloadLanes::label($lane) . '.');
            $this->pause($wait);
        }

        return true;
    }

    private function hostPace(): HostPace
    {
        return $this->pace ??= app(HostPace::class);
    }

    protected function downloader(): PageDownloader
    {
        return app(PageDownloader::class);
    }

    /** Sleep between chapters on one lane. Overridable so tests don't wait. */
    protected function pause(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    protected function clock(): float
    {
        return microtime(true);
    }

    private function pastDeadline(): bool
    {
        return $this->deadline !== null && $this->clock() >= $this->deadline;
    }

    private function say(string $line): void
    {
        if ($this->output !== null) {
            ($this->output)($line);
        }
    }

    /** A line for the console and the log. */
    private function note(string $line): void
    {
        Log::info('Manga fallback: ' . trim($line));
        $this->say($line);
    }

    private static function numberText(float $number): string
    {
        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }

    // ---- Views -------------------------------------------------------------------

    /**
     * Chapters filled from other sources per source name, most first.
     *
     * @return array<string, int>
     */
    public static function filledCounts(int $novelId): array
    {
        return self::filledByNovel([$novelId])[$novelId] ?? [];
    }

    /** @return array<int, array<string, int>> novel id => source name => chapters */
    private static function filledByNovel(array $novelIds): array
    {
        if ($novelIds === []) {
            return [];
        }
        $rows = NovelChapter::query()
            ->whereIn('novel_id', $novelIds)
            ->whereNotNull('filled_from')
            ->selectRaw('novel_id, filled_from, COUNT(*) as n')
            ->groupBy('novel_id', 'filled_from')
            ->toBase()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->novel_id][(string) $row->filled_from] = (int) $row->n;
        }
        foreach ($out as &$counts) {
            arsort($counts);
        }

        return $out;
    }

    /** "N chapters filled from other sources (Weeb Central 7, MangaDex 126)", or null when none. */
    public static function filledSummary(int $novelId): ?string
    {
        $counts = self::filledCounts($novelId);
        if ($counts === []) {
            return null;
        }
        $total = array_sum($counts);
        $parts = [];
        foreach ($counts as $name => $n) {
            $parts[] = "{$name} {$n}";
        }

        return number_format($total) . ' ' . ($total === 1 ? 'chapter' : 'chapters') . ' filled from other sources (' . implode(', ', $parts) . ')';
    }

    /**
     * Set-aside chapters per series (pending, not blacklisted, unavailable_until
     * in the future): how many, the earliest unavailable_until, and how many
     * of them SourceUnavailable counts as parked too (content reason, waiting
     * for a future next_attempt_at). One grouped query.
     *
     * @param  int[]|null  $novelIds  null = every series
     * @return array<int, array{count: int, until: Carbon, parked_too: int}>
     */
    public static function setAsideCounts(?array $novelIds = null): array
    {
        if ($novelIds === []) {
            return [];
        }
        $now = Carbon::now();
        $reasons = SourceUnavailable::CONTENT_REASONS;
        $marks = implode(', ', array_fill(0, count($reasons), '?'));
        $rows = NovelChapter::query()
            ->when($novelIds !== null, fn($q) => $q->whereIn('novel_id', $novelIds))
            ->where('status', 0)
            ->where('blacklist', 0)
            ->where('unavailable_until', '>', $now)
            ->selectRaw(
                "novel_id, COUNT(*) as n, MIN(unavailable_until) as until, "
                . "SUM(CASE WHEN last_failure_reason IN ({$marks}) AND next_attempt_at > ? THEN 1 ELSE 0 END) as parked_too",
                [...$reasons, $now->format('Y-m-d H:i:s')]
            )
            ->groupBy('novel_id')
            ->toBase()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->novel_id] = [
                'count' => (int) $row->n,
                'until' => Carbon::parse($row->until),
                'parked_too' => (int) $row->parked_too,
            ];
        }

        return $out;
    }

    /**
     * The Health page's "Not available on the source" rows: the parked series
     * (SourceUnavailable::parkedNovels()) and the series with set-aside
     * chapters, one row per series, soonest re-check first. Each row has
     * `parked_count` (chapters, a chapter both parked and set aside counted
     * once), `panel_reason` (plain words) and `panel_recheck_at` (Carbon or
     * null = next run).
     *
     * @param  Collection<int, Novel>  $parked
     * @return Collection<int, Novel>
     */
    public static function unavailableNovels(Collection $parked): Collection
    {
        $aside = self::setAsideCounts();
        $rows = $parked->keyBy('id');
        $missing = array_diff(array_keys($aside), $rows->keys()->map(fn($id) => (int) $id)->all());
        if ($missing !== []) {
            foreach (Novel::whereIn('id', $missing)->get(['id', 'name', 'source_fail_streak', 'source_recheck_at']) as $novel) {
                $novel->parked_count = 0;
                $novel->parked_reasons = [];
                $rows->put($novel->id, $novel);
            }
        }

        return $rows->each(function (Novel $novel) use ($aside) {
            $set = $aside[(int) $novel->id] ?? null;
            $parkedCount = (int) ($novel->parked_count ?? 0);
            $reasons = [];
            $recheck = $parkedCount > 0 ? $novel->source_recheck_at : null;
            if ($parkedCount > 0) {
                $reasons[] = SourceUnavailable::reasonWords((array) ($novel->parked_reasons ?? []));
            }
            if ($set !== null) {
                $overlap = $parkedCount > 0 ? min($set['parked_too'], $parkedCount) : 0;
                $novel->parked_count = $parkedCount + $set['count'] - $overlap;
                $reasons[] = $parkedCount > 0
                    ? 'no source has ' . ($set['count'] === 1 ? '1 of them' : "{$set['count']} of them")
                    : 'no source has ' . ($set['count'] === 1 ? 'this chapter' : 'these chapters');
                $recheck = $recheck === null || $set['until']->lt($recheck) ? $set['until'] : $recheck;
            }
            $novel->panel_reason = implode('; ', $reasons);
            $novel->panel_recheck_at = $recheck;
        })
            ->sortBy(fn(Novel $n) => $n->panel_recheck_at?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * One short note per series for the Health page's "Not available on the
     * source" panel: "Filled N from X" when any were; "N chapters on no
     * source (looks again <date>)" when some are set aside; then "Looking on
     * other sources" or "No other source has it (checked <date>)" while
     * chapters still wait for the fallback.
     *
     * @return array<int, string> novel id => note
     */
    public static function healthNotes(array $novelIds): array
    {
        $novelIds = array_values(array_unique(array_map('intval', $novelIds)));
        if ($novelIds === []) {
            return [];
        }
        $filled = self::filledByNovel($novelIds);
        $aside = self::setAsideCounts($novelIds);
        $states = MangaAlternateSearch::whereIn('novel_id', $novelIds)->get()->keyBy('novel_id');
        $usable = MangaAlternate::whereIn('novel_id', $novelIds)
            ->where('failures', '<', self::MAX_FAILURES)
            ->selectRaw('novel_id, COUNT(*) as n')
            ->groupBy('novel_id')
            ->pluck('n', 'novel_id')
            ->all();
        $manga = Novel::whereIn('id', $novelIds)->manga()->get()->keyBy('id');

        $notes = [];
        foreach ($novelIds as $id) {
            $parts = [];
            foreach ($filled[$id] ?? [] as $name => $n) {
                $parts[] = "Filled {$n} from {$name}";
            }
            $novel = $manga->get($id);
            if ($novel !== null && self::enabled()) {
                $set = $aside[$id] ?? null;
                if ($set !== null) {
                    $parts[] = ($set['count'] === 1 ? '1 chapter' : "{$set['count']} chapters") . ' on no source (looks again ' . self::dateText($set['until']) . ')';
                }
                // With set-aside chapters, the search note only while others still wait.
                if ($set === null || self::qualifyingQuery($novel)->exists()) {
                    $state = $states->get($id);
                    if ($state !== null && $state->cycleComplete() && (int) ($usable[$id] ?? 0) === 0) {
                        $parts[] = 'No other source has it (checked ' . $state->cycle_completed_at->format('j M Y') . ')';
                    } else {
                        $parts[] = 'Looking on other sources';
                    }
                }
            }
            if ($parts !== []) {
                $notes[$id] = implode(' · ', $parts);
            }
        }

        return $notes;
    }
}
