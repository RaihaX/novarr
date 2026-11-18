<?php

namespace Tests\Feature;

use App\Http\Controllers\HomeController;
use App\Novel;
use App\NovelChapter;
use App\Services\NovelHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NovelHealth::firstPerNovel(): one UNION ALL of per-novel
 * `ORDER BY ... LIMIT 1` selects, replacing ROW_NUMBER() over an IN list
 * (which sorted every candidate chapter on production). Used by Home's
 * continue/in-progress/read-from lookups and the attention source URLs.
 */
class FirstPerNovelTest extends TestCase
{
    use RefreshDatabase;

    private function novel(string $name): Novel
    {
        return Novel::create(['name' => $name, 'author' => 'A', 'status' => 0, 'group_id' => 42, 'no_of_chapters' => 5]);
    }

    private function chapter(Novel $novel, float $n, array $attrs = []): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
            'label' => "Chapter {$n}", 'url' => "/n{$novel->id}/c{$n}",
        ]);
        $chapter->forceFill($this->takeReadState($chapter, $attrs))->save();

        return $chapter;
    }

    private function firstUnread(array $ids)
    {
        return NovelHealth::firstPerNovel($ids, fn($q) => app(\App\Services\ReadState::class)->whereUnread($q
            ->where('status', 1)->where('blacklist', 0)
            ->select(['id', 'novel_id', 'chapter']))
            ->ordered());
    }

    public function test_empty_candidate_list_runs_no_query(): void
    {
        DB::enableQueryLog();
        $this->assertTrue(NovelHealth::firstPerNovel([], fn($q) => $q)->isEmpty());
        $this->assertSame([], app(NovelHealth::class)->sourceUrlsFor([]));
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_one_union_statement_returns_each_novels_top_row(): void
    {
        $a = $this->novel('A');
        // Reading order is book, sort_key (NULL first), chapter, id.
        $this->chapter($a, 1, ['status' => 1, 'sort_key' => 2]);
        $aFirst = $this->chapter($a, 2, ['status' => 1, 'sort_key' => 1]);
        $this->chapter($a, 1, ['status' => 1, 'book' => 1]);
        $this->chapter($a, 0.5, ['status' => 1, 'read_at' => now()]);   // read
        $this->chapter($a, 0.6, ['status' => 1, 'blacklist' => 1]);     // blacklisted
        $this->chapter($a, 0.7, ['status' => 1])->delete();             // soft-deleted

        $b = $this->novel('B');
        $bFirst = $this->chapter($b, 9, ['status' => 1]);
        $this->chapter($b, 1); // not downloaded

        $c = $this->novel('C'); // nothing unread
        $this->chapter($c, 1, ['status' => 1, 'read_at' => now()]);

        DB::enableQueryLog();
        $rows = $this->firstUnread([$a->id, $b->id, $c->id, $a->id]);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $log);
        $this->assertStringContainsString('union all', $log[0]['query']);
        $this->assertStringNotContainsStringIgnoringCase('row_number', $log[0]['query']);
        $this->assertSame([$a->id, $b->id], $rows->keys()->sort()->values()->all());
        $this->assertSame($aFirst->id, $rows[$a->id]->id);
        $this->assertSame($bFirst->id, $rows[$b->id]->id);
        $this->assertInstanceOf(NovelChapter::class, $rows[$a->id]);
    }

    public function test_in_progress_order_is_latest_read_then_highest_id(): void
    {
        $n = $this->novel('Progress');
        $at = now()->subHour();
        $this->chapter($n, 1, ['status' => 1, 'read_at' => $at, 'progress' => 20]);
        $tie = $this->chapter($n, 2, ['status' => 1, 'read_at' => $at, 'progress' => 30]);
        $this->chapter($n, 3, ['status' => 1, 'read_at' => now(), 'progress' => 95]); // finished

        $items = app(HomeController::class)->continueReading(8);

        $this->assertSame($tie->id, $items[0]['next']->id);
        $this->assertTrue($items[0]['resume']);
    }

    public function test_many_candidates_are_chunked_into_valid_statements(): void
    {
        // More novel ids than one compound select may hold on SQLite (500).
        $n = $this->novel('Only real one');
        $first = $this->chapter($n, 1, ['status' => 1]);
        $ids = array_merge(range(1_000_000, 1_000_449), [$n->id]);

        $rows = $this->firstUnread($ids);

        $this->assertSame([$n->id], $rows->keys()->all());
        $this->assertSame($first->id, $rows[$n->id]->id);
    }

    public function test_union_compiles_to_parenthesised_members_on_mysql(): void
    {
        config(['database.connections.union_sql_check' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'unused', 'username' => 'unused', 'password' => '', 'prefix' => '']]);

        $member = fn(int $id) => NovelChapter::on('union_sql_check')->where('novel_id', $id)
            ->where('status', 0)->where('blacklist', 0)->select(['id', 'novel_id'])->ordered()->limit(1);
        $query = $member(11)->unionAll($member(12)->toBase());

        $one = 'select `id`, `novel_id` from `novel_chapters` where `novel_id` = ? and `status` = ? and `blacklist` = ? and `novel_chapters`.`deleted_at` is null order by `book` asc, `sort_key` asc, `chapter` asc, `id` asc limit 1';
        $this->assertSame("({$one}) union all ({$one})", $query->toSql());
        $this->assertSame([11, 0, 0, 12, 0, 0], $query->getBindings());
    }
}
