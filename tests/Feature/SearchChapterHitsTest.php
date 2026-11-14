<?php

namespace Tests\Feature;

use App\Http\Controllers\SearchController;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chapter hits on /search: label and text matches fetched separately (never
 * OR across tables), merged label-first, simple pagination without a COUNT,
 * slim chapter rows and SQL-cut snippets (no chapter bodies loaded).
 *
 * On SQLite the hits come from LIKE; the MySQL/MariaDB FULLTEXT statements
 * are checked as compiled SQL against a mysql-grammar connection (no query
 * is sent to it).
 */
class SearchChapterHitsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Library-wide search uses FULLTEXT on MySQL/MariaDB, and InnoDB's
     * FULLTEXT index does not see rows inserted inside the uncommitted
     * transaction RefreshDatabase wraps each test in. Those statements are
     * checked for shape below and were run by hand against production; the
     * behaviour around them is tested here on the LIKE path.
     */
    private function skipWhereFullTextCannotSeeTestRows(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('InnoDB FULLTEXT cannot see rows from an uncommitted test transaction.');
        }
    }

    private function novel(string $name): Novel
    {
        // Group 1/3/6 have special handling elsewhere; identity is irrelevant here.
        return Novel::create(['name' => $name, 'author' => 'Author', 'status' => 0, 'group_id' => 42, 'no_of_chapters' => 10]);
    }

    private function chapter(Novel $novel, float $n, string $label, ?string $body, array $attrs = []): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => $label,
            'url' => "https://www.source.test/{$novel->id}/chapter-{$n}",
        ]);
        if ($body !== null) {
            $chapter->description = $body;
            $chapter->status = 1;
            $chapter->download_date = now();
        }
        $chapter->forceFill($attrs)->save();

        return $chapter;
    }

    public function test_label_hits_come_first_then_text_hits_without_duplicates(): void
    {
        $this->skipWhereFullTextCannotSeeTestRows();

        $novel = $this->novel('Zeta Chronicle');
        $textOnly = $this->chapter($novel, 1, 'Chapter 1', '<p>The dragon slept.</p>');
        $both = $this->chapter($novel, 2, 'The Dragon Wakes', '<p>A dragon roared.</p>');
        $labelOnly = $this->chapter($novel, 3, 'Dragon Gate', '<p>Nothing here.</p>');
        $this->chapter($novel, 4, 'Unrelated', '<p>Quiet.</p>');

        $response = $this->get(route('search.index', ['q' => 'dragon']))->assertOk();

        $ids = $response->viewData('results')->map(fn($r) => $r['chapter']->id)->all();
        $this->assertSame([$both->id, $labelOnly->id, $textOnly->id], $ids);
        $response->assertSee('3 matching chapter(s)');
    }

    public function test_filters_exclude_undownloaded_blacklisted_and_deleted_chapters(): void
    {
        $this->skipWhereFullTextCannotSeeTestRows();

        $novel = $this->novel('Filters');
        $ok = $this->chapter($novel, 1, 'Comet one', '<p>comet</p>');
        $this->chapter($novel, 2, 'Comet queued', null);
        $this->chapter($novel, 3, 'Comet hidden', '<p>comet</p>', ['blacklist' => 1]);
        $this->chapter($novel, 4, 'Comet gone', '<p>comet</p>')->delete();
        // Downloaded body but status reset: excluded from text hits too.
        $this->chapter($novel, 5, 'Plain', '<p>comet</p>', ['status' => 0]);

        $ids = $this->get(route('search.index', ['q' => 'comet']))->assertOk()
            ->viewData('results')->map(fn($r) => $r['chapter']->id)->all();

        $this->assertSame([$ok->id], $ids);
    }

    public function test_novel_scope_is_applied_before_the_limit(): void
    {
        $other = $this->novel('Crowded');
        // Enough hits elsewhere to fill a whole page and more.
        for ($i = 1; $i <= SearchController::PER_PAGE + 5; $i++) {
            $this->chapter($other, $i, "Lantern {$i}", '<p>lantern</p>');
        }
        $mine = $this->novel('Mine');
        $a = $this->chapter($mine, 1, 'Chapter 1', '<p>a lantern lit</p>');
        $b = $this->chapter($mine, 2, 'Lantern Hall', '<p>none</p>');

        $response = $this->get(route('search.index', ['q' => 'lantern', 'novel' => $mine->id]))->assertOk();

        $this->assertSame([$b->id, $a->id], $response->viewData('results')->map(fn($r) => $r['chapter']->id)->all());
        $this->assertTrue($response->viewData('novels')->isEmpty());

        // The limited queries themselves carry the novel filter.
        $this->assertSame([$b->id], SearchController::labelHitsQuery('lantern', $mine->id, 1)->pluck('novel_chapters.id')->all());
        $this->assertSame([$a->id], SearchController::contentHitsQuery('lantern', $mine->id, 1)->pluck('chapter_texts.novel_chapter_id')->all());
    }

    public function test_simple_pagination_has_a_next_page_and_no_count_query(): void
    {
        $this->skipWhereFullTextCannotSeeTestRows();

        $novel = $this->novel('Paged');
        $chapters = [];
        for ($i = 1; $i <= SearchController::PER_PAGE + 3; $i++) {
            $chapters[] = $this->chapter($novel, $i, "Chapter {$i}", '<p>meteor shower</p>')->id;
        }

        DB::enableQueryLog();
        $first = $this->get(route('search.index', ['q' => 'meteor']))->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $paginator = $first->viewData('paginator');
        $this->assertInstanceOf(\Illuminate\Pagination\Paginator::class, $paginator);
        $this->assertTrue($paginator->hasMorePages());
        $this->assertCount(SearchController::PER_PAGE, $first->viewData('results'));
        $this->assertSame(array_slice($chapters, 0, SearchController::PER_PAGE), $first->viewData('results')->map(fn($r) => $r['chapter']->id)->all());
        $first->assertSee('Matches 1–40')->assertSee('more on the next page')->assertSee('page=2', false);
        foreach ($log as $entry) {
            // paginate()'s total ran as "select count(*) as aggregate".
            $this->assertStringNotContainsStringIgnoringCase('as aggregate', $entry['query']);
        }

        $second = $this->get(route('search.index', ['q' => 'meteor', 'page' => 2]))->assertOk();
        $this->assertSame(array_slice($chapters, SearchController::PER_PAGE), $second->viewData('results')->map(fn($r) => $r['chapter']->id)->all());
        $this->assertFalse($second->viewData('paginator')->hasMorePages());
        $second->assertSee('Matches 41–43')->assertDontSee('more on the next page');
    }

    public function test_no_chapter_body_is_loaded_and_snippets_are_plain_text(): void
    {
        $this->skipWhereFullTextCannotSeeTestRows();

        $novel = $this->novel('Snippets');
        $long = str_repeat('<p>Filler words go here.</p>', 30);
        $chapter = $this->chapter($novel, 1, 'Chapter 1', $long . '<p class="x">The <b>Phoenix</b> &amp; the sea.</p>' . $long);

        DB::enableQueryLog();
        $response = $this->get(route('search.index', ['q' => 'phoenix']))->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $row = $response->viewData('results')->first();
        $this->assertSame($chapter->id, $row['chapter']->id);
        $this->assertFalse($row['chapter']->relationLoaded('text'));
        $this->assertStringContainsString('The Phoenix &amp; the sea.', $row['snippet']);
        $this->assertStringStartsWith('… ', $row['snippet']);
        $this->assertStringNotContainsString('<', $row['snippet']);
        $this->assertStringNotContainsString('>', $row['snippet']);

        // Only the SQL excerpt reads content in the select list.
        foreach ($log as $entry) {
            $select = strtolower(explode(' from ', $entry['query'])[0]);
            if (str_contains($select, 'content')) {
                $this->assertStringContainsString('substr(content', $select, $entry['query']);
            }
            $this->assertStringNotContainsString('chapter_texts.*', $entry['query']);
        }
    }

    public function test_snippet_falls_back_to_the_start_when_no_needle_is_found(): void
    {
        $this->skipWhereFullTextCannotSeeTestRows();

        $novel = $this->novel('Fallback');
        $chapter = $this->chapter($novel, 1, 'Chapter 1', '<p>Opening line about rivers.</p>');

        $row = SearchController::snippetQuery([$chapter->id], ['oceans'])->first();

        $this->assertSame(1, (int) $row->excerpt_start);
        $this->assertSame('<p>Opening line about rivers.</p>', $row->excerpt);
        $this->assertSame('Opening line about rivers.', SearchController::cleanSnippet($row->excerpt, 1, 'oceans'));

        // The second needle (a word) is used when the phrase is absent.
        $row = SearchController::snippetQuery([$chapter->id], SearchController::needles('blue rivers'))->first();
        $this->assertSame(1, (int) $row->excerpt_start); // within the first 80 characters
        $this->assertSame('Opening line about rivers. …', SearchController::cleanSnippet($row->excerpt, 1, 'blue rivers'));
    }

    public function test_merge_hit_ids_dedupes_label_first_and_slices_the_page(): void
    {
        $this->assertSame([1, 2, 3, 4], SearchController::mergeHitIds([1, 2], [2, 3, 1, 4], 1, 3));
        $this->assertSame([4, 5], SearchController::mergeHitIds([1, 2], [2, 3, 1, 4, 5], 2, 3));
        $this->assertSame([], SearchController::mergeHitIds([1], [], 2, 3));
        $this->assertSame([7], SearchController::mergeHitIds([], ['7'], 0, 3));
    }

    public function test_clean_snippet_drops_cut_tag_and_entity_fragments(): void
    {
        $this->assertSame('… Hello world. …', SearchController::cleanSnippet('ass="x">Hello <i>world</i>.</p><p cla', 50, 'world'));
        $this->assertSame('… Tea and cake', SearchController::cleanSnippet('quot; Tea and cake &am', 9, 'nothing'));
        $this->assertSame('', SearchController::cleanSnippet('<p></p>', 1, 'x'));
        $this->assertSame([ 'blue rivers', 'blue', 'rivers'], SearchController::needles('blue rivers'));
        $this->assertSame(['rivers'], SearchController::needles('rivers'));
    }

    public function test_fulltext_statements_compile_for_mysql_without_or_across_tables(): void
    {
        config(['database.connections.search_sql_check' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'unused', 'username' => 'unused', 'password' => '', 'prefix' => '']]);

        $label = SearchController::labelHitsQuery('dragon', 0, 41, 'search_sql_check');
        $this->assertSame(
            'select `novel_chapters`.`id` from `novel_chapters` where `novel_chapters`.`status` = ? and `novel_chapters`.`blacklist` = ? and match (`novel_chapters`.`label`) against (? in natural language mode) and `novel_chapters`.`deleted_at` is null limit 41',
            $label->toSql()
        );
        $this->assertSame([1, 0, 'dragon'], $label->getBindings());

        // Within one novel neither query may use MATCH: the FULLTEXT index
        // would walk every match in the library to find this novel's.
        foreach ([
            SearchController::labelHitsQuery('dragon', 7, 41, 'search_sql_check'),
            SearchController::contentHitsQuery('dragon', 7, 41, 'search_sql_check'),
        ] as $scoped) {
            $this->assertStringNotContainsString('match (', $scoped->toSql());
            $this->assertStringContainsString('`novel_chapters`.`novel_id` = ?', $scoped->toSql());
            $this->assertStringContainsString("LIKE ? ESCAPE '!'", $scoped->toSql());
            $this->assertContains('%dragon%', $scoped->getBindings());
        }

        $content = SearchController::contentHitsQuery('dragon', 0, 41, 'search_sql_check');
        $this->assertSame(
            'select `chapter_texts`.`novel_chapter_id` from `chapter_texts` inner join `novel_chapters` on `novel_chapters`.`id` = `chapter_texts`.`novel_chapter_id` where `novel_chapters`.`status` = ? and `novel_chapters`.`blacklist` = ? and `novel_chapters`.`deleted_at` is null and match (`chapter_texts`.`content`) against (? in natural language mode) limit 41',
            $content->toSql()
        );
        $this->assertSame([1, 0, 'dragon'], $content->getBindings());

        $snippet = SearchController::snippetQuery([5, 6], ['blue rivers', 'blue'], 'search_sql_check');
        $start = 'GREATEST(COALESCE(NULLIF(LOCATE(?, content), 0), NULLIF(LOCATE(?, content), 0), 1) - 80, 1)';
        $this->assertSame(
            "select `novel_chapter_id`, SUBSTRING(content, {$start}, 300) as excerpt, {$start} as excerpt_start from `chapter_texts` where `novel_chapter_id` in (?, ?)",
            $snippet->toSql()
        );
        $this->assertSame(['blue rivers', 'blue', 'blue rivers', 'blue', 5, 6], $snippet->getBindings());
    }
}
