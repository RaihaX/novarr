<?php

namespace Tests\Feature;

use App\Http\Controllers\NovelController;
use App\Http\Helpers\CacheHelper;
use App\Novel;
use App\NovelChapter;
use App\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Library drives its counts and sorts from one cached grouped aggregate
 * instead of correlated subqueries per novel. These tests pin the new path to
 * the old SQL (reproduced in referenceIds()) for every sort, chip and view.
 */
class LibraryAggregatesTest extends TestCase
{
    use RefreshDatabase;

    private const SORTS = ['read', 'name', 'progress', 'updated', 'chapters'];

    private const FILTERS = ['all', 'reading', 'new', 'attention', 'finished', 'translated', 'original'];

    private Carbon $base;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('dashboard_attention', [], 900);
        $this->base = Carbon::parse('2026-08-01 12:00:00');
    }

    private function novel(string $name, int $advertised = 10, array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => $name,
            'author' => 'Author',
            'status' => 0,
            'group_id' => 2, // identity irrelevant; avoid the special-cased 1, 3 and 6
            'no_of_chapters' => $advertised,
        ], $attrs));
    }

    /** One chapter with explicit state; $readAt / $downloadedAt are hour offsets from the base time. */
    private function chapter(Novel $novel, int $n, bool $downloaded, ?int $readAt = null, ?int $downloadedAt = null, bool $blacklist = false, bool $deleted = false): NovelChapter
    {
        $c = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n}",
            'url' => "https://example.test/{$novel->id}/{$n}",
        ]);
        $c->forceFill([
            'status' => $downloaded ? 1 : 0,
            'blacklist' => $blacklist ? 1 : 0,
            'download_date' => $downloaded ? $this->base->copy()->addHours($downloadedAt ?? 0) : null,
            'deleted_at' => $deleted ? $this->base : null,
        ])->saveQuietly();
        if ($readAt !== null) {
            $this->markRead($c, null, $this->base->copy()->addHours($readAt));
        }

        return $c;
    }

    /** $toc chapters: the first $downloaded downloaded, the first $read read at hour $readAt. */
    private function chapters(Novel $novel, int $toc, int $downloaded, int $read = 0, int $readAt = 0, int $downloadedAt = 0): void
    {
        for ($n = 1; $n <= $toc; $n++) {
            $this->chapter($novel, $n, $n <= $downloaded, $n <= $read ? $readAt : null, $downloadedAt);
        }
    }

    /** A varied library of 30+ novels (two table pages). */
    private function library(): array
    {
        $n = [];

        $n['never'] = $this->novel('alpha never read', 5);                 // lowercase: collation-sensitive name order
        $this->chapters($n['never'], 5, 2, 0, 0, 3);

        $n['full'] = $this->novel('Beta Fully Read', 6, ['origin' => 'original']);
        $this->chapters($n['full'], 6, 6, 6, 40, 10);

        $n['partly'] = $this->novel('Gamma Partly Downloaded', 20, ['origin' => 'translated']);
        $this->chapters($n['partly'], 10, 3, 1, 30, 20);

        // A blacklisted chapter read last: the latest read for the read sort
        // counted it, the source/downloaded counts did not.
        $n['blacklist'] = $this->novel('Delta Blacklisted', 0);
        $this->chapters($n['blacklist'], 6, 4, 2, 10, 5);
        $this->chapter($n['blacklist'], 7, true, 90, 95, blacklist: true);
        $this->chapter($n['blacklist'], 8, false, null, null, blacklist: true);

        // Soft-deleted rows, including the newest read and download, count for nothing.
        $n['deleted'] = $this->novel('Epsilon Soft Deleted', 4);
        $this->chapters($n['deleted'], 4, 4, 2, 20, 15);
        $this->chapter($n['deleted'], 5, true, 99, 99, deleted: true);
        $this->chapter($n['deleted'], 6, true, null, 98, deleted: true);

        $n['paused'] = $this->novel('Zeta Paused', 12, ['paused_at' => $this->base]);
        $this->chapters($n['paused'], 12, 6, 3, 25, 1);

        $n['completed'] = $this->novel('Eta Completed', 8, ['status' => 1, 'origin' => 'translated']);
        $this->chapters($n['completed'], 8, 8, 2, 35, 12);

        // Exact ties on every key: name breaks them.
        $n['tieB'] = $this->novel('Iota Tie', 6);
        $this->chapters($n['tieB'], 6, 3, 2, 50, 50);
        $n['tieA'] = $this->novel('Theta Tie', 6);
        $this->chapters($n['tieA'], 6, 3, 2, 50, 50);

        // No chapters: progress NULL (no denominator) vs 0 (advertised).
        $n['empty'] = $this->novel('Kappa Empty', 0);
        $n['emptyAdvertised'] = $this->novel('Lambda Empty Advertised', 50);

        // Ratios equal to 5 decimal places (MariaDB's DECIMAL division ties
        // them; SQLite's doubles do not). Name order is the opposite of the
        // exact-ratio order, so either driver's rule is visible.
        $n['nearMu'] = $this->novel('Mu Near Tie', 30001);
        $this->chapters($n['nearMu'], 1, 1, 0, 0, 7);
        $n['nearNu'] = $this->novel('Nu Near Tie', 30000);
        $this->chapters($n['nearNu'], 1, 1, 0, 0, 7);

        // Downloaded after the last read: "new".
        $n['fresh'] = $this->novel('Omicron Fresh', 10);
        $this->chapters($n['fresh'], 10, 8, 4, 60, 30);
        NovelChapter::where('novel_id', $n['fresh']->id)->whereIn('chapter', [6, 7])
            ->update(['download_date' => $this->base->copy()->addHours(70)]);

        // Fillers with varied counts and dates.
        for ($i = 1; $i <= 18; $i++) {
            $f = $this->novel(sprintf('Filler %02d', $i), $i % 4 === 0 ? 30 : 0, ['origin' => $i % 3 === 0 ? 'translated' : null]);
            $toc = ($i * 7) % 9 + 1;
            $this->chapters($f, $toc, ($i * 5) % ($toc + 1), $i % 5 === 0 ? 0 : min($toc, $i % 4), 100 + ($i % 6), 80 + ($i % 7));
            $n["filler{$i}"] = $f;
        }

        // A soft-deleted novel never appears.
        $gone = $this->novel('Pi Deleted Novel', 3);
        $this->chapters($gone, 3, 3, 3, 200, 200);
        $gone->delete();

        $tag = Tag::create(['name' => 'Cultivation']);
        foreach (['partly', 'blacklist', 'tieA', 'tieB', 'filler3', 'filler7', 'empty'] as $k) {
            $n[$k]->tags()->attach($tag->id);
        }
        $n['tag'] = $tag;

        Cache::put('dashboard_attention', [
            ['id' => $n['paused']->id, 'name' => 'x', 'reason' => 'y', 'url' => null],
            ['id' => $n['filler5']->id, 'name' => 'x', 'reason' => 'y', 'url' => null],
        ], 900);

        return $n;
    }

    /**
     * The previous NovelController::index() query, verbatim: withCount
     * subqueries, correlated ORDER BY expressions, then name.
     *
     * @return array{ids: int[], total: int}
     */
    private function referenceIds(array $params, int $perPage, int $page): array
    {
        $query = Novel::with('file')
            ->select(['id', 'name', 'author', 'status', 'paused_at', 'group_id', 'language_id', 'no_of_chapters', 'scrape_failures', 'toc_failures', 'origin', 'origin_language'])
            ->withCount([
                'chapters',
                'chapters as downloaded_chapters_count' => fn($q) => $q->where('status', 1)->where('blacklist', 0),
                'chapters as source_chapters_count' => fn($q) => $q->where('blacklist', 0),
            ]);

        if (!empty($params['search'])) {
            $query->where('name', 'like', '%' . $params['search'] . '%');
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }
        if (!empty($params['tag'])) {
            $query->whereHas('tags', fn($q) => $q->where('tags.id', $params['tag']));
        }

        // Chip sets, as index() builds them from the (unchanged) reading cache.
        $reading = NovelController::libraryReading()['reading'];
        $attentionIds = \App\Services\NovelHealth::attentionIds();
        $sets = [
            'reading' => array_keys(array_filter($reading, fn($r) => $r['read'] > 0 && !$r['finished'])),
            'new' => array_keys(array_filter($reading, fn($r) => $r['new'] > 0)),
            'attention' => array_values(array_intersect(array_keys($attentionIds), array_keys($reading))),
            'finished' => array_keys(array_filter($reading, fn($r) => $r['finished'])),
        ];
        $origins = Novel::whereIn('origin', ['translated', 'original'])->pluck('origin', 'id');
        foreach (['translated', 'original'] as $origin) {
            $sets[$origin] = array_values(array_intersect(array_keys($origins->filter(fn($o) => $o === $origin)->all()), array_keys($reading)));
        }

        $filter = $params['filter'] ?? 'all';
        if ($filter === 'offline') {
            $ids = array_filter(array_map('intval', explode(',', (string) ($params['ids'] ?? ''))));
            $query->whereIn('id', $ids ?: [0]);
        } elseif ($filter !== 'all') {
            $query->whereIn('id', $sets[$filter] ?: [0]);
        }

        match ($params['sort']) {
            'progress' => $query->orderByRaw('(downloaded_chapters_count * 1.0 / NULLIF(CASE WHEN COALESCE(no_of_chapters, 0) > source_chapters_count THEN no_of_chapters ELSE source_chapters_count END, 0)) DESC'),
            'chapters' => $query->orderByDesc('chapters_count'),
            'updated' => $query->orderByDesc(
                NovelChapter::select('download_date')->whereColumn('novel_id', 'novels.id')->orderByDesc('download_date')->limit(1)
            ),
            'name' => $query->orderBy('name'),
            // The owner's latest read of a not soft-deleted chapter (was MAX(read_at) over the novel's chapters).
            default => $query->orderByDesc(DB::table('chapter_reads as r')
                ->join('novel_chapters as c', 'c.id', '=', 'r.novel_chapter_id')
                ->whereNull('c.deleted_at')
                ->where('r.user_id', $this->owner()->id)
                ->whereColumn('r.novel_id', 'novels.id')
                ->selectRaw('MAX(r.read_at)')),
        };
        $query->orderBy('name');

        $p = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'ids' => $p->getCollection()->map(fn($novel) => [
                $novel->id, $novel->chapters_count, $novel->downloaded_chapters_count, $novel->source_chapters_count,
            ])->all(),
            'total' => $p->total(),
        ];
    }

    /** @return array{ids: array, total: int} */
    private function actualIds(array $params): array
    {
        $p = $this->get(route('novels.index', $params))->assertOk()->viewData('novels');
        $this->assertSame($params['page'] ?? 1, $p->currentPage());

        return [
            'ids' => collect($p->items())->map(fn($novel) => [
                $novel->id, $novel->chapters_count, $novel->downloaded_chapters_count, $novel->source_chapters_count,
            ])->all(),
            'total' => $p->total(),
        ];
    }

    private function assertSameAsBefore(array $params): void
    {
        $perPage = $params['view'] === 'grid' ? 42 : 25;
        $actual = $this->actualIds($params);
        $expected = $this->referenceIds($params, $perPage, $params['page'] ?? 1);
        $this->assertSame($expected, $actual, 'Mismatch for ' . json_encode($params));
    }

    public function test_every_sort_and_chip_matches_the_previous_query_in_both_views(): void
    {
        $n = $this->library();
        $offlineIds = implode(',', [$n['full']->id, $n['empty']->id, $n['tieA']->id, $n['tieB']->id]);

        foreach (self::SORTS as $sort) {
            foreach ([...self::FILTERS, 'offline'] as $filter) {
                $params = ['sort' => $sort, 'filter' => $filter] + ($filter === 'offline' ? ['ids' => $offlineIds] : []);
                $this->assertSameAsBefore($params + ['view' => 'grid']);
                $this->assertSameAsBefore($params + ['view' => 'table']);
                $this->assertSameAsBefore($params + ['view' => 'table', 'page' => 2]);
            }
            // Text, tag and status filters, alone and with a chip.
            $this->assertSameAsBefore(['sort' => $sort, 'view' => 'grid', 'search' => 'tie']);
            $this->assertSameAsBefore(['sort' => $sort, 'view' => 'table', 'search' => 'a']);
            $this->assertSameAsBefore(['sort' => $sort, 'view' => 'table', 'search' => 'a', 'page' => 2]);
            $this->assertSameAsBefore(['sort' => $sort, 'view' => 'grid', 'tag' => $n['tag']->id]);
            $this->assertSameAsBefore(['sort' => $sort, 'view' => 'grid', 'tag' => $n['tag']->id, 'filter' => 'reading']);
            $this->assertSameAsBefore(['sort' => $sort, 'view' => 'grid', 'status' => 1]);
            $this->assertSameAsBefore(['sort' => $sort, 'view' => 'table', 'status' => 0, 'filter' => 'attention']);
            // Past the last page: empty, same total.
            $this->assertSameAsBefore(['sort' => $sort, 'view' => 'table', 'page' => 5]);
        }
    }

    public function test_known_orderings(): void
    {
        $n = $this->library();
        $ids = fn(array $p) => collect($this->get(route('novels.index', $p + ['view' => 'grid']))->viewData('novels')->items())->pluck('id')->all();

        $read = $ids(['sort' => 'read']);
        $pos = fn(array $list, string $k) => array_search($n[$k]->id, $list, true);

        // Fillers are read at hours 100-105, after everything else named here;
        // the blacklisted chapter read at hour 90 still counts for Delta.
        $this->assertLessThan($pos($read, 'blacklist'), $pos($read, 'filler1'));
        $this->assertLessThan($pos($read, 'fresh'), $pos($read, 'blacklist'));
        // Soft-deleted rows don't count: Epsilon's live max is hour 20, after Gamma's 30.
        $this->assertLessThan($pos($read, 'deleted'), $pos($read, 'partly'));
        // Equal keys keep name order (Iota before Theta).
        $this->assertSame($pos($read, 'tieB') + 1, $pos($read, 'tieA'));
        // Never-read novels (NULL) come after every read one.
        $neverRead = ['never', 'empty', 'emptyAdvertised', 'nearMu', 'nearNu'];
        $firstNull = min(array_map(fn($k) => $pos($read, $k), $neverRead));
        $this->assertGreaterThan($pos($read, 'full'), $firstNull);
        $this->assertGreaterThan($pos($read, 'deleted'), $firstNull);

        // Progress: Kappa has no denominator (NULL) and sorts after the 0 % Lambda.
        $progress = $ids(['sort' => 'progress']);
        $this->assertSame($n['empty']->id, end($progress));
        $this->assertLessThan($pos($progress, 'empty'), $pos($progress, 'emptyAdvertised'));
    }

    public function test_the_library_runs_no_correlated_count_and_few_queries_when_warm(): void
    {
        $this->library();

        foreach (['grid', 'table'] as $view) {
            foreach (self::SORTS as $sort) {
                Cache::forget(CacheHelper::libraryReadingKey($this->owner()->id));
                Cache::forget(CacheHelper::LIBRARY_AGGREGATES_KEY);

                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->get(route('novels.index', ['view' => $view, 'sort' => $sort]))->assertOk();
                $cold = array_column(DB::getQueryLog(), 'query');

                DB::flushQueryLog();
                $this->get(route('novels.index', ['view' => $view, 'sort' => $sort]))->assertOk();
                $warm = array_column(DB::getQueryLog(), 'query');
                DB::disableQueryLog();

                foreach ([...$cold, ...$warm] as $sql) {
                    $this->assertStringNotContainsString('select count(*) from "novel_chapters" where "novels"."id"', $sql);
                    $this->assertStringNotContainsString('where "novel_id" = "novels"."id"', $sql);
                }
                // Cold: reading index (2) + aggregates (1) + origins + candidates
                // + page novels + covers + tags, and on the very first page the
                // admin's pending-requests nav badge (cached 10 minutes, so not
                // forgotten here). Warm: the five page queries only.
                $this->assertLessThanOrEqual(9, count($cold), implode("\n", $cold));
                $this->assertLessThanOrEqual(5, count($warm), implode("\n", $warm));
                $this->assertSame(1, count(array_filter($cold, fn($q) => str_contains($q, 'MAX(download_date)'))));
                $this->assertSame([], array_values(array_filter($warm, fn($q) => str_contains($q, 'from "novel_chapters"'))));
            }
        }
    }

    public function test_opening_a_chapter_or_toggling_read_invalidates_the_readers_index_only(): void
    {
        $key = CacheHelper::libraryReadingKey($this->owner()->id);
        $a = $this->novel('Aaa Read Before');
        $this->chapters($a, 3, 3, 1, 10, 0);
        $b = $this->novel('Bbb Read Later');
        $this->chapter($b, 1, true, null, 0);
        $target = $this->chapter($b, 2, true, null, 0);

        $order = fn() => collect($this->get(route('novels.index', ['view' => 'grid', 'sort' => 'read']))->viewData('novels')->items())->pluck('id')->all();

        $this->assertSame([$a->id, $b->id], $order()); // warms the cache
        $this->assertTrue(Cache::has(CacheHelper::LIBRARY_AGGREGATES_KEY));
        $this->assertTrue(Cache::has($key));

        // Opening a downloaded chapter marks it read now: b becomes the most
        // recent. Only the reader's index is dropped; the chapter counts,
        // the same for everyone, stay.
        $this->get(route('chapters.show', $target->id))->assertOk();
        $this->assertFalse(Cache::has($key));
        $this->assertTrue(Cache::has(CacheHelper::LIBRARY_AGGREGATES_KEY));
        $this->assertSame([$b->id, $a->id], $order());

        // Toggling it unread again puts b back last.
        $this->post(route('chapters.toggle_read', $target->id))->assertOk();
        $this->assertFalse(Cache::has($key));
        $this->assertSame([$a->id, $b->id], $order());
    }

    public function test_clear_novel_cache_forgets_the_aggregates(): void
    {
        $key = CacheHelper::libraryReadingKey($this->owner()->id);
        Cache::put(CacheHelper::LIBRARY_AGGREGATES_KEY, ['x'], 60);
        $this->assertSame(['x'], CacheHelper::rememberForReader($key, CacheHelper::epoch(), 60, fn() => ['x']));

        CacheHelper::clearNovelCache(123);

        $this->assertFalse(Cache::has(CacheHelper::LIBRARY_AGGREGATES_KEY));
        // The reader's entry is stale: computed under the previous epoch.
        $this->assertSame(['fresh'], CacheHelper::rememberForReader($key, CacheHelper::epoch(), 60, fn() => ['fresh']));
    }
}
