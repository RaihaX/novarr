<?php

namespace App\Services\Requests;

use App\Http\Controllers\Api\V1\DiscoverController;
use App\Jobs\RunRequestSearch;
use App\Services\Discover;
use App\Sources\Source;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A reader's search for something to request (POST/GET
 * /api/v1/requests/searches): one query string sent to EVERY searchable
 * source of the medium — never a URL — run in the background by
 * RunRequestSearch after the POST's response, like DiscoverSearch. The one
 * exception: a book search whose text is a supported book page
 * (App\Books\BookLinkReader: Goodreads, Amazon, …) reads that page for ONE
 * result (askLink()), remembered under its own cache key (cacheKey()); a
 * link that cannot be read fails the search with the reader's reason.
 *
 * Limits (config novarr.requests.*), checked in this order on start():
 *   - one running search per account                 → 429 search_running
 *   - searches_per_window per search_window_minutes  → 429 search_rate_limited
 *   - max_concurrent_searches across ALL accounts    → 429 search_busy
 *
 * Concurrency slots are cache entries added atomically (Cache::add):
 * "request_search_slot_{n}" for n < max_concurrent_searches, plus
 * "request_search_user_{id}" per account, each holding the search id. The
 * job frees both when it ends (done, failed or thrown); every slot also
 * expires on its own after slotSeconds() (the time budget plus a grace),
 * so a worker that dies can never keep one forever. A record still running
 * past the same time is reported failed and frees its slots.
 *
 * The record (cache "request_search_{id}", kept RECORD_TTL_MINUTES):
 * {id, user_id, status: running|done|failed, message, query, medium,
 * groups, skipped_sources, sources_asked, created_at, finished_at}. A
 * request made from a search names this record and a group key; the
 * server copies that group's hits from here (Requests::createFromSearch()).
 *
 * The sources are asked by a SearchRunner: ProcessSearchRunner asks up to
 * novarr.requests.search_parallelism lanes at once, each in its own PHP
 * process; a lane holds sources of one host (lanes()), asked one after
 * another. A host with several sources (the Suwayomi server) is spread
 * over up to novarr.requests.search_host_parallelism lanes, so one slow
 * source there holds up only its own lane and the host never gets more
 * than that many calls at once.
 *
 * A source running longer than novarr.requests.source_timeout_seconds is
 * given up (reason 'timeout') and its lane goes on without it
 * (ProcessSearchRunner only; the inline runner cannot interrupt a call).
 * Each source's hits are filtered for resemblance to the query
 * (SearchRelevance) before grouping.
 *
 * Results are remembered (cache "request_search_results_v2_*", see
 * cacheKey()) for novarr.requests.search_cache_minutes per medium +
 * normalised query, or PARTIAL_CACHE_MINUTES when a source was skipped. A
 * search for remembered results — by anyone — is recorded as done at once:
 * no slot, no rate-limit hit, nothing queued. Only the source answers are
 * remembered; what depends on the reader (requested, in_library, signed
 * covers) is worked out when the record is read (RequestPresenter).
 */
class RequestSearch
{
    public const RECORD_TTL_MINUTES = 30;

    /** The source key of a result read from a pasted book link (BookLinkReader). */
    public const BOOK_LINK_SOURCE = 'book-link';

    /** Seconds a slot outlives the time budget (a source call already under way may overrun it). */
    public const SLOT_GRACE_SECONDS = 120;

    /** Work groups kept per search. */
    public const MAX_RESULTS = 60;

    public const BUSY = 'Search is busy right now. Try again in a moment.';

    public const RUNNING = 'You already have a search running. Wait for it to finish.';

    public const TOO_SLOW = 'The search took too long. Try again.';

    public const JOB_FAILED = 'The search failed. Try again later.';

    /** Seconds a client is told to wait when every slot is taken. */
    public const BUSY_RETRY_SECONDS = 15;

    /** How long results that skipped a source are remembered (so it is retried soon). */
    public const PARTIAL_CACHE_MINUTES = 2;

    public static function key(string $id): string
    {
        return "request_search_{$id}";
    }

    public static function slotKey(int $n): string
    {
        return "request_search_slot_{$n}";
    }

    public static function userSlotKey(int $userId): string
    {
        return "request_search_user_{$userId}";
    }

    public static function isId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    public static function budgetSeconds(): int
    {
        return max(1, (int) config('novarr.requests.search_budget_seconds', 90));
    }

    /** How long a slot (and a running record) lasts without its job. */
    public static function slotSeconds(): int
    {
        return static::budgetSeconds() + self::SLOT_GRACE_SECONDS;
    }

    public static function maxConcurrent(): int
    {
        return max(1, (int) config('novarr.requests.max_concurrent_searches', 3));
    }

    /** Sources (lanes) asked at the same time by one search. */
    public static function parallelism(): int
    {
        return max(1, (int) config('novarr.requests.search_parallelism', 5));
    }

    public static function cacheMinutes(): int
    {
        return max(0, (int) config('novarr.requests.search_cache_minutes', 10));
    }

    /** The query as results are remembered under: lower case, single spaces. */
    public static function normalizeQuery(string $query): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''));
    }

    public static function cacheKey(string $medium, string $query): string
    {
        // A pasted book link: by its canonical page, apart from any text search.
        if (($link = static::bookLink($medium, $query)) !== null) {
            return 'request_search_link_v1_' . sha1($link);
        }
        // v2: hits are filtered for relevance (SearchRelevance).
        return 'request_search_results_v2_' . sha1($medium . '|' . static::normalizeQuery($query));
    }

    /**
     * Take the slots, record the search and queue it. Returns the record.
     *
     * @throws RequestRefused 429 when a limit is reached
     */
    public function start(int $userId, string $query, string $medium): array
    {
        $id = bin2hex(random_bytes(16));
        $ttl = static::slotSeconds();

        // Remembered results: done at once, no slot, no rate-limit hit.
        $remembered = $this->remembered($medium, $query);
        if ($remembered !== null) {
            $now = now()->toIso8601String();
            $record = [
                'id' => $id,
                'user_id' => $userId,
                'status' => 'done',
                'message' => null,
                'query' => $query,
                'medium' => $medium,
                'groups' => $remembered['groups'],
                'skipped_sources' => $remembered['skipped_sources'],
                'sources_asked' => $remembered['sources_asked'],
                'slot' => null,
                'cached' => true,
                'created_at' => $now,
                'finished_at' => $now,
            ];
            $this->store($record);

            return $record;
        }

        // One running search per account.
        if (!Cache::add(static::userSlotKey($userId), $id, $ttl)) {
            throw new RequestRefused(429, self::RUNNING, 'search_running', [], ['Retry-After' => '5']);
        }

        // The account's rate limit.
        $limiterKey = 'request-search|' . $userId;
        $max = max(1, (int) config('novarr.requests.searches_per_window', 12));
        if (RateLimiter::tooManyAttempts($limiterKey, $max)) {
            $this->forgetIfMine(static::userSlotKey($userId), $id);
            $wait = max(1, RateLimiter::availableIn($limiterKey));
            $minutes = (int) ceil($wait / 60);
            throw new RequestRefused(429, 'Too many searches. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.', 'search_rate_limited', [], ['Retry-After' => (string) $wait]);
        }

        // A slot of the whole server.
        $slot = null;
        for ($n = 0; $n < static::maxConcurrent(); $n++) {
            if (Cache::add(static::slotKey($n), $id, $ttl)) {
                $slot = $n;
                break;
            }
        }
        if ($slot === null) {
            $this->forgetIfMine(static::userSlotKey($userId), $id);
            throw new RequestRefused(429, self::BUSY, 'search_busy', [], ['Retry-After' => (string) self::BUSY_RETRY_SECONDS]);
        }

        RateLimiter::hit($limiterKey, max(1, (int) config('novarr.requests.search_window_minutes', 10)) * 60);

        $record = [
            'id' => $id,
            'user_id' => $userId,
            'status' => 'running',
            'message' => null,
            'query' => $query,
            'medium' => $medium,
            'groups' => null,
            'skipped_sources' => [],
            'sources_asked' => [],
            'slot' => $slot,
            'created_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];
        $this->store($record);

        try {
            RunRequestSearch::dispatchAfterResponse($id);
        } catch (\Throwable $e) {
            // Never ran (the queue is unreachable): nothing may stay held.
            $this->release($record);
            Cache::forget(static::key($id));
            throw $e;
        }

        return $this->record($id) ?? $record;
    }

    /**
     * The record, or null when unknown or expired. A record running longer
     * than slotSeconds() is stored (and answered) as failed, its slots freed.
     */
    public function find(string $id): ?array
    {
        if (!static::isId($id)) {
            return null;
        }
        $record = $this->record($id);
        if ($record === null) {
            return null;
        }

        if ($record['status'] === 'running'
            && Carbon::parse($record['created_at'])->lte(now()->subSeconds(static::slotSeconds()))) {
            $record = array_merge($record, ['status' => 'failed', 'message' => self::TOO_SLOW, 'finished_at' => now()->toIso8601String()]);
            $this->store($record);
            $this->release($record);
        }

        return $record;
    }

    /**
     * The searchable sources of a medium, in search order (searchOrder()):
     * config novarr.requests.source_order first (by key), then
     * novarr.requests.source_name_preference (by display name), then the
     * rest in Discover order (hidden Discover sources and Suwayomi's
     * language / excluded-source settings apply, as on Discover); of each
     * novarr.requests.source_families family only the best-placed member;
     * at most max_sources_per_search.
     *
     * The names cost no request: a Suwayomi entry carries the name its
     * source listing gave (SuwayomiSource::sourceList(), cached an hour and
     * already read by sourcesFor()); any other source's is its label.
     *
     * @return array<string, Source>
     */
    public function sources(Discover $discover, string $medium): array
    {
        // Books: the catalogues (Google Books + Open Library), not a site to scrape.
        if ($medium === \App\Novel::MEDIUM_BOOK) {
            return [\App\Sources\BookCatalogueSource::KEY => new \App\Sources\BookCatalogueSource()];
        }
        // Comics: Comic Vine (with a key) and the catalogues' graphic novels,
        // then the Suwayomi comic sources, in their configured order.
        if ($medium === \App\Novel::MEDIUM_COMIC) {
            return [\App\Sources\ComicCatalogueSource::KEY => new \App\Sources\ComicCatalogueSource()]
                + static::suwayomiComicSources($discover);
        }
        $sources = array_filter($discover->sourcesFor($medium), fn(Source $s) => in_array('search', $s->discoverLists(), true));
        $names = [];
        foreach ($sources as $key => $source) {
            $names[(string) $key] = static::displayName($source, (string) $key);
        }
        $ordered = static::searchOrder(
            $names,
            (array) config('novarr.requests.source_order', []),
            (array) config('novarr.requests.source_name_preference', []),
            (array) config('novarr.requests.source_families', []),
        );
        $ordered = array_slice($ordered, 0, max(1, (int) config('novarr.requests.max_sources_per_search', 15)));

        $result = [];
        foreach ($ordered as $key) {
            $result[$key] = $sources[$key];
        }

        return $result;
    }

    /**
     * The Suwayomi comic sources on Discover (SuwayomiSource::isComicSource()),
     * keyed as Discover keys them, in novarr.suwayomi.comic_sources order.
     *
     * @return array<string, \App\Sources\SuwayomiSource>
     */
    public static function suwayomiComicSources(Discover $discover): array
    {
        $found = array_filter(
            $discover->sourcesFor(\App\Novel::MEDIUM_COMIC),
            fn(Source $s) => $s instanceof \App\Sources\SuwayomiSource && in_array('search', $s->discoverLists(), true)
        );
        $rank = array_flip(\App\Sources\SuwayomiSource::comicSources());
        uksort($found, fn($a, $b) => ($rank[mb_strtolower((string) $found[$a]->sourceName())] ?? PHP_INT_MAX)
            <=> ($rank[mb_strtolower((string) $found[$b]->sourceName())] ?? PHP_INT_MAX));

        return $found;
    }

    /**
     * A source's name as the reader knows it: a Suwayomi entry's own source
     * name ("Asura Scans"), else its Discover label ("MangaDex (manga)").
     */
    public static function displayName(Source $source, string $key): string
    {
        try {
            if ($source instanceof \App\Sources\SuwayomiSource && ($name = $source->sourceName()) !== null && $name !== '') {
                return $name;
            }

            return $source->discoverLabel();
        } catch (\Throwable) {
            return $key;
        }
    }

    /**
     * Whether a display name is a preference / family entry: the same
     * normalised name, or that name followed by more words ("MANGA Plus" is
     * "MANGA Plus by SHUEISHA", "Webtoons" is "Webtoons.com", "MangaDex" is
     * "MangaDex (manga)"; "Mangago" is not "Mangagoyaoi"). Case-insensitive.
     */
    public static function nameMatches(string $name, string $wanted): bool
    {
        $name = Discover::normalizeName($name);
        $wanted = Discover::normalizeName($wanted);

        return $wanted !== '' && ($name === $wanted || str_starts_with($name, $wanted . ' '));
    }

    /**
     * Source keys in search order, family losers left out. Pure.
     *   1. keys placed by $configured (CandidateRanking::sourceOrder() rules:
     *      "mangadex" or a prefix "suwayomi" of "suwayomi:123"), in that order;
     *   2. keys whose name matches a $preference entry (nameMatches()), in
     *      preference order;
     *   3. the rest, in their given order.
     * Then of each $families entry (a list of names) only the first key in
     * that order whose name matches a member is kept.
     *
     * @param  array<string, string>  $names  key => display name, in Discover order
     * @param  string[]  $configured  novarr.requests.source_order
     * @param  string[]  $preference  novarr.requests.source_name_preference
     * @param  array<int, string[]>  $families  novarr.requests.source_families
     * @return string[]
     */
    public static function searchOrder(array $names, array $configured, array $preference, array $families): array
    {
        $keys = array_map('strval', array_keys($names));
        $configured = array_values(array_filter(array_map(fn($w) => trim((string) $w), $configured), fn($w) => $w !== ''));
        $preference = array_values(array_filter(array_map(fn($w) => trim((string) $w), $preference), fn($w) => $w !== ''));

        // [tier, place within the tier, Discover position] per key.
        $rank = [];
        foreach ($keys as $position => $key) {
            $rank[$key] = [2, 0, $position];
            foreach ($configured as $i => $wanted) {
                if ($key === $wanted || str_starts_with($key, $wanted . ':')) {
                    $rank[$key] = [0, $i, $position];
                    continue 2;
                }
            }
            foreach ($preference as $i => $wanted) {
                if (static::nameMatches((string) $names[$key], $wanted)) {
                    $rank[$key] = [1, $i, $position];
                    continue 2;
                }
            }
        }
        usort($keys, fn($a, $b) => $rank[$a] <=> $rank[$b]);

        foreach ($families as $family) {
            $members = array_values(array_filter(array_map(fn($m) => trim((string) $m), (array) $family), fn($m) => $m !== ''));
            if (count($members) < 2) {
                continue;
            }
            $kept = false;
            $keys = array_values(array_filter($keys, function (string $key) use ($names, $members, &$kept) {
                foreach ($members as $member) {
                    if (static::nameMatches((string) $names[$key], $member)) {
                        if ($kept) {
                            return false;
                        }
                        $kept = true;

                        return true;
                    }
                }

                return true;
            }));
        }

        return $keys;
    }

    /**
     * The family members searchOrder() left out, per kept member: kept key
     * => the other members' keys, best placed first. A key in no family, or
     * alone of its family, has no entry. Pure.
     *
     * @param  array<string, string>  $names  key => display name, in Discover order
     * @return array<string, string[]>
     */
    public static function familyStandIns(array $names, array $configured, array $preference, array $families): array
    {
        $full = static::searchOrder($names, $configured, $preference, []);
        $kept = array_flip(static::searchOrder($names, $configured, $preference, $families));

        $standIns = [];
        foreach ($families as $family) {
            $members = array_values(array_filter(array_map(fn($m) => trim((string) $m), (array) $family), fn($m) => $m !== ''));
            if (count($members) < 2) {
                continue;
            }
            $inFamily = array_values(array_filter($full, function (string $key) use ($names, $members) {
                foreach ($members as $member) {
                    if (static::nameMatches((string) $names[$key], $member)) {
                        return true;
                    }
                }

                return false;
            }));
            $winner = null;
            foreach ($inFamily as $key) {
                if (isset($kept[$key])) {
                    $winner = $key;
                    break;
                }
            }
            $others = array_values(array_filter($inFamily, fn(string $key) => $key !== $winner && !isset($kept[$key])));
            if ($winner !== null && $others !== []) {
                $standIns[$winner] = $others;
            }
        }

        return $standIns;
    }

    /**
     * familyStandIns() for the medium's searchable sources (sources()):
     * kept key => [other member key => Source], best placed first.
     *
     * @return array<string, array<string, Source>>
     */
    public function standIns(Discover $discover, string $medium): array
    {
        if (in_array($medium, [\App\Novel::MEDIUM_BOOK, \App\Novel::MEDIUM_COMIC], true)) {
            return [];
        }
        $sources = array_filter($discover->sourcesFor($medium), fn(Source $s) => in_array('search', $s->discoverLists(), true));
        $names = [];
        foreach ($sources as $key => $source) {
            $names[(string) $key] = static::displayName($source, (string) $key);
        }

        $result = [];
        foreach (static::familyStandIns(
            $names,
            (array) config('novarr.requests.source_order', []),
            (array) config('novarr.requests.source_name_preference', []),
            (array) config('novarr.requests.source_families', []),
        ) as $winner => $others) {
            foreach ($others as $key) {
                $result[$winner][$key] = $sources[$key];
            }
        }

        return $result;
    }

    /**
     * Seconds one source may take in ProcessSearchRunner before its lane is
     * stopped and the lane's remaining sources go on in a new process;
     * 0 (or less) turns the limit off. Fractions are honoured (tests).
     */
    public static function sourceTimeoutSeconds(): float
    {
        return max(0.0, (float) config('novarr.requests.source_timeout_seconds', 20));
    }

    /** Lanes one host's sources are spread over (calls that host gets at the same time). */
    public static function hostParallelism(): int
    {
        return max(1, (int) config('novarr.requests.search_host_parallelism', 4));
    }

    /**
     * The sources grouped into lanes; a lane's sources are asked one after
     * another. Sources on one host (every Suwayomi entry is the one Suwayomi
     * server) are dealt in turn over up to $perHost lanes (default:
     * hostParallelism()), so the host gets at most that many calls at once
     * and a slow source delays only the ones behind it in its lane. Lanes
     * with more sources come first (they take longest), then search order;
     * within a lane, search order. Pure but for the sources' own
     * discoverHost().
     *
     * @param  array<string, Source>  $sources  in search order
     * @return array<int, string[]>
     */
    public static function lanes(array $sources, ?int $perHost = null): array
    {
        $perHost = max(1, $perHost ?? static::hostParallelism());
        $hosts = [];
        foreach ($sources as $key => $source) {
            try {
                $host = strtolower(trim($source->discoverHost()));
            } catch (\Throwable) {
                $host = '';
            }
            $hosts[$host !== '' ? $host : 'source:' . $key][] = (string) $key;
        }
        $lanes = [];
        foreach ($hosts as $keys) {
            $split = array_fill(0, min($perHost, count($keys)), []);
            foreach ($keys as $i => $key) {
                $split[$i % count($split)][] = $key;
            }
            array_push($lanes, ...$split);
        }
        // Search order of each lane's first source: the tie-break.
        $order = array_flip(array_map('strval', array_keys($sources)));
        usort($lanes, fn(array $a, array $b) => (count($b) <=> count($a)) ?: ($order[$a[0]] <=> $order[$b[0]]));

        return $lanes;
    }

    /**
     * Ask one source for the query: its raw Discover items, or null when it
     * failed (threw or answered null). Used in this process
     * (InlineSearchRunner) and in a lane process (requests:search-lane).
     */
    public static function ask(Source $source, string $key, string $query): ?array
    {
        try {
            $items = $source->discover('search', $query);
        } catch (\Throwable $e) {
            Log::warning("Request search: {$key} threw: " . $e->getMessage());

            return null;
        }

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : null;
    }

    /** Run a recorded search (the job's work); a no-op when it is gone or no longer running. */
    public function run(string $id, Discover $discover): void
    {
        $record = $this->record($id);
        if ($record === null || $record['status'] !== 'running') {
            return;
        }

        $medium = (string) $record['medium'];
        $query = (string) $record['query'];
        try {
            $outcome = $this->askSources($medium, $query, $discover);
        } catch (\App\Books\BookLinkFailed $e) {
            // A pasted link that could not be read: the reason is the reader's message.
            $this->finish($id, ['status' => 'failed', 'message' => $e->getMessage()]);

            return;
        }

        $this->finish($id, ['status' => 'done', 'message' => null] + $outcome);
        $this->remember($medium, $query, $outcome);
    }

    /**
     * A search's outcome without a record: the remembered results when there
     * are any, else every searchable source of the medium asked now (within
     * budgetSeconds()) and the outcome remembered, as run() does. Takes no
     * slot and no rate-limit hit: for the server's own lookups (the shortlist
     * of a request made from the Discover shelf, FindRequestCandidates).
     *
     * @return array{groups: array, skipped_sources: array, sources_asked: array}
     */
    public function collect(string $medium, string $query, Discover $discover): array
    {
        $remembered = $this->remembered($medium, $query);
        if ($remembered !== null) {
            return $remembered;
        }

        try {
            $outcome = $this->askSources($medium, $query, $discover);
        } catch (\App\Books\BookLinkFailed) {
            return ['groups' => [], 'skipped_sources' => [], 'sources_asked' => []];
        }
        $this->remember($medium, $query, $outcome);

        return $outcome;
    }

    /**
     * Ask the sources and build the outcome: hits filtered for relevance and
     * grouped into works, the sources skipped and those asked.
     *
     * @return array{groups: array, skipped_sources: array, sources_asked: array}
     */
    private function askSources(string $medium, string $query, Discover $discover): array
    {
        if (static::bookLink($medium, $query) !== null) {
            return $this->askLink($query, $medium);
        }
        $deadline = (int) now()->getPreciseTimestamp(3) + static::budgetSeconds() * 1000;
        $sources = $this->sources($discover, $medium);
        // The catalogue source is not in the registry a lane process loads: asked here.
        $runner = in_array($medium, [\App\Novel::MEDIUM_BOOK, \App\Novel::MEDIUM_COMIC], true) ? new InlineSearchRunner() : $this->runner();
        $answers = $runner->run($medium, $query, static::lanes($sources), $sources, $deadline);

        // Hits in search order, whatever order the sources answered in.
        $hits = [];
        $skipped = [];
        $asked = [];
        foreach ($sources as $key => $source) {
            $key = (string) $key;
            $answer = $answers[$key] ?? ['items' => null, 'reason' => 'failed', 'asked' => false];
            if (!empty($answer['asked'])) {
                $asked[] = $key;
            }
            if (!is_array($answer['items'] ?? null)) {
                // A source stopped at a time limit never reports itself
                // (its process was killed): count it here, so a source that
                // always times out shows on the Sources health page.
                if ($answer['reason'] === 'timeout' && !empty($answer['asked'])) {
                    \App\Services\SourceHealth::record($key, \App\Services\SourceHealth::KIND_SEARCH, false, 'timeout', 'Request search: no answer within the time limit.');
                }
                $skipped[] = ['source' => $key, 'source_name' => static::label($source, $key), 'reason' => $answer['reason'] === 'timeout' ? 'timeout' : 'failed'];
                continue;
            }
            $name = static::label($source, $key);
            // Hits that don't resemble the query go (SearchRelevance) — but
            // not for an ISBN: no title resembles "978-0-…", the catalogue
            // looked that exact book up.
            $items = static::isIsbnQuery($medium, $query) ? $answer['items'] : SearchRelevance::filter($answer['items'], $query);
            foreach ($items as $item) {
                if (is_array($item)) {
                    $hits[] = ['key' => $key, 'name' => $name, 'item' => DiscoverController::item($item, $key)];
                }
            }
        }

        return [
            'groups' => static::group($hits, $medium, $query),
            'skipped_sources' => $skipped,
            'sources_asked' => $asked,
        ];
    }

    /**
     * A book search for an ISBN ("978-0-306-40615-7", "030640615X"): ten or
     * thirteen digits (a final X) with only spaces or hyphens between, as
     * BookCatalogueSource looks one up. Pure.
     */
    public static function isIsbnQuery(string $medium, string $query): bool
    {
        if (!in_array($medium, [\App\Novel::MEDIUM_BOOK, \App\Novel::MEDIUM_COMIC], true) || !preg_match('/^[0-9X\s-]+$/i', trim($query))) {
            return false;
        }

        return in_array(strlen(preg_replace('/[^0-9X]/', '', strtoupper($query)) ?? ''), [10, 13], true);
    }

    /**
     * A book search whose text is a supported book page (BookLinkReader):
     * the link's canonical URL, else null (another medium, plain text, or a
     * link of another host — the controller refuses those).
     */
    public static function bookLink(string $medium, string $query): ?string
    {
        return in_array($medium, [\App\Novel::MEDIUM_BOOK, \App\Novel::MEDIUM_COMIC], true) ? \App\Books\BookLinkReader::canonical($query) : null;
    }

    /**
     * The outcome of a pasted book link: ONE group, shaped as a catalogue
     * hit's (group()), from source BOOK_LINK_SOURCE named after the site
     * ("Goodreads"), its url the canonical page. The group and its hit also
     * carry the book's series, series_index, isbn, isbn10, language and year
     * (Requests::candidatesFromHits() keeps them on the request).
     *
     * @throws \App\Books\BookLinkFailed  the page could not be read
     */
    private function askLink(string $query, string $medium = \App\Novel::MEDIUM_BOOK): array
    {
        $book = app(\App\Books\BookLinkReader::class)->read($query);
        $item = DiscoverController::item([
            'name' => $book['title'],
            'author' => implode(', ', array_slice($book['authors'], 0, 3)) ?: null,
            'cover' => $book['cover_url'],
            'description' => $book['description'],
            'url' => $book['url'],
            'status' => $book['year'] !== null ? (string) $book['year'] : null,
        ], self::BOOK_LINK_SOURCE);
        $groups = static::group([['key' => self::BOOK_LINK_SOURCE, 'name' => $book['site_name'], 'item' => $item]], $medium, $book['title']);

        $extra = [
            'series' => $book['series'],
            'series_index' => $book['series_index'],
            'isbn' => $book['isbn'],
            'isbn10' => $book['isbn10'],
            'language' => $book['language'],
            'year' => $book['year'],
        ];
        foreach ($groups as &$group) {
            $group += ['series' => $book['series'], 'series_index' => $book['series_index'], 'isbn' => $book['isbn']];
            foreach ($group['hits'] as &$hit) {
                $hit += $extra;
            }
            unset($hit);
        }
        unset($group);

        return ['groups' => $groups, 'skipped_sources' => [], 'sources_asked' => [self::BOOK_LINK_SOURCE]];
    }

    /** The runner the container gives (ProcessSearchRunner, or InlineSearchRunner). */
    protected function runner(): SearchRunner
    {
        return app(SearchRunner::class);
    }

    /**
     * Remember a finished search's answers: search_cache_minutes, or
     * PARTIAL_CACHE_MINUTES when a source was skipped (failed or out of
     * time), so a flaky source is asked again soon.
     */
    private function remember(string $medium, string $query, array $outcome): void
    {
        $minutes = static::cacheMinutes();
        if ($minutes === 0) {
            return;
        }
        if ($outcome['skipped_sources'] !== []) {
            $minutes = min($minutes, self::PARTIAL_CACHE_MINUTES);
        }
        try {
            Cache::put(static::cacheKey($medium, $query), [
                'groups' => $outcome['groups'],
                'skipped_sources' => $outcome['skipped_sources'],
                'sources_asked' => $outcome['sources_asked'],
                'at' => now()->toIso8601String(),
            ], now()->addMinutes($minutes));
        } catch (\Throwable $e) {
            Log::warning('Request search: results not remembered: ' . $e->getMessage());
        }
    }

    /** Remembered answers for this medium + query, or null. */
    private function remembered(string $medium, string $query): ?array
    {
        if (static::cacheMinutes() === 0) {
            return null;
        }
        try {
            $cached = Cache::get(static::cacheKey($medium, $query));
        } catch (\Throwable) {
            return null;
        }

        return is_array($cached) && is_array($cached['groups'] ?? null) ? [
            'groups' => $cached['groups'],
            'skipped_sources' => array_values((array) ($cached['skipped_sources'] ?? [])),
            'sources_asked' => array_values((array) ($cached['sources_asked'] ?? [])),
        ] : null;
    }

    /**
     * Hits → works: hits of the same normalised title (Discover::normalizeName)
     * form one group, its title / author / cover / description from the
     * first hit that has one (search order), one hit per source. Groups whose
     * title is exactly the query come first, then those found on more
     * sources, then those whose title starts with the query, then
     * first-seen order. Pure.
     *
     * @param  array<int, array{key: string, name: string, item: array}>  $hits
     * @return array<int, array>
     */
    public static function group(array $hits, string $medium, string $query): array
    {
        $groups = [];
        $seen = 0;
        foreach ($hits as $hit) {
            $item = $hit['item'];
            $normalized = Discover::normalizeName((string) $item['title']);
            if ($normalized === '' || (string) ($item['url'] ?? '') === '') {
                continue;
            }
            // Books: the same title by another author is another book.
            $key = static::groupKey($medium, in_array($medium, [\App\Novel::MEDIUM_BOOK, \App\Novel::MEDIUM_COMIC], true)
                ? $normalized . '|' . Discover::normalizeName((string) ($item['author'] ?? ''))
                : $normalized);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'title' => (string) $item['title'],
                    'normalized_title' => $normalized,
                    'author' => null,
                    'cover_url' => null,
                    'description' => null,
                    'medium' => $medium,
                    'hits' => [],
                    'seen' => $seen++,
                ];
            }
            if (isset($groups[$key]['hits'][$hit['key']])) {
                continue; // one hit per source
            }
            foreach (['author', 'cover_url', 'description'] as $field) {
                $groups[$key][$field] ??= $item[$field] ?? null;
            }
            $groups[$key]['hits'][$hit['key']] = [
                'source' => $hit['key'],
                'source_name' => $hit['name'],
                'url' => (string) $item['url'],
                'title' => (string) $item['title'],
                'cover_url' => $item['cover_url'] ?? null,
                'chapters' => $item['chapters'] ?? null,
                'status' => $item['status'] ?? null,
            ];
        }

        $wanted = Discover::normalizeName($query);
        $groups = array_values($groups);
        $starts = fn(array $g) => $wanted !== '' && str_starts_with($g['normalized_title'], $wanted);
        usort($groups, fn(array $a, array $b) => (($b['normalized_title'] === $wanted) <=> ($a['normalized_title'] === $wanted))
            ?: (count($b['hits']) <=> count($a['hits']))
            ?: ($starts($b) <=> $starts($a))
            ?: ($a['seen'] <=> $b['seen']));

        return array_map(function (array $group) {
            unset($group['seen']);
            $group['hits'] = array_values($group['hits']);

            return $group;
        }, array_slice($groups, 0, self::MAX_RESULTS));
    }

    /** A result key: stable for the same work (medium + normalised title) across searches. */
    public static function groupKey(string $medium, string $normalizedTitle): string
    {
        return substr(hash('sha256', $medium . '|' . $normalizedTitle), 0, 16);
    }

    /** End a running search as failed (the job threw or was given up). */
    public function fail(string $id, string $message = self::JOB_FAILED): void
    {
        $this->finish($id, ['status' => 'failed', 'message' => $message]);
    }

    /** Free the record's slots (only those it still holds). */
    public function release(array $record): void
    {
        $id = (string) $record['id'];
        if (isset($record['slot'])) {
            $this->forgetIfMine(static::slotKey((int) $record['slot']), $id);
        }
        $this->forgetIfMine(static::userSlotKey((int) $record['user_id']), $id);
    }

    public function releaseById(string $id): void
    {
        $record = $this->record($id);
        if ($record !== null) {
            $this->release($record);
        }
    }

    private function forgetIfMine(string $key, string $id): void
    {
        try {
            if (Cache::get($key) === $id) {
                Cache::forget($key);
            }
        } catch (\Throwable $e) {
            // The slot then expires by itself.
        }
    }

    /** A source's name on a hit (its Discover label; the key when that throws). */
    public static function label(Source $source, string $key): string
    {
        try {
            return $source->discoverLabel();
        } catch (\Throwable) {
            return $key;
        }
    }

    private function finish(string $id, array $outcome): void
    {
        $record = $this->record($id);
        if ($record === null || $record['status'] !== 'running') {
            Log::info("Request search {$id} finished after it was resolved or expired; outcome dropped.");

            return;
        }

        $this->store(array_merge($record, $outcome, ['finished_at' => now()->toIso8601String()]));
    }

    private function record(string $id): ?array
    {
        $record = Cache::get(static::key($id));

        return is_array($record) ? $record : null;
    }

    private function store(array $record): void
    {
        Cache::put(static::key($record['id']), $record, now()->addMinutes(self::RECORD_TTL_MINUTES));
    }
}
