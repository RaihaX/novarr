<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Novel page + Library redesign (Novel.dc / NovelPhone.dc / Library.dc):
 * one progress card, exception-only chapter states with failure reasons,
 * the chapter filter's data, Library shelves and the table's Read /
 * Downloaded columns.
 */
class NovelPageRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('dashboard_attention', [], 900);
    }

    private function novel(string $name = 'Redesign Novel', array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => $name,
            'author' => 'Someone',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 0,
        ], $attrs));
    }

    private function chapter(Novel $novel, float $n, array $attrs = []): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n}",
            'url' => "https://example.test/{$novel->id}/c-{$n}",
        ]);
        $chapter->forceFill(array_merge(['status' => 1, 'download_date' => now()->subDay()], $attrs))->saveQuietly();

        return $chapter;
    }

    /** The <tr> of one chapter row in the server-rendered list. */
    private function row(string $html, int $id): string
    {
        $this->assertMatchesRegularExpression('#<tr [^>]*id="ch-' . $id . '"#', $html);
        preg_match('#<tr [^>]*id="ch-' . $id . '".*?</tr>#s', $html, $m);

        return $m[0];
    }

    // ---- Novel page --------------------------------------------------------

    public function test_a_failed_row_shows_its_reason_and_downloaded_rows_carry_no_badge(): void
    {
        $novel = $this->novel();
        $ok = $this->chapter($novel, 1);
        $read = $this->chapter($novel, 2, ['read_at' => now()]);
        $failed = $this->chapter($novel, 3, ['status' => 0, 'download_date' => null, 'attempts' => 2, 'last_failure_reason' => 'not_found']);
        $queued = $this->chapter($novel, 4, ['status' => 0, 'download_date' => null]);
        $review = $this->chapter($novel, 5, ['status' => 0, 'download_date' => null, 'attempts' => NovelChapter::REVIEW_ATTEMPTS, 'last_failure_reason' => 'no_content']);

        $html = $this->get(route('novels.show', $novel->id))->assertOk()->getContent();

        // Normal rows: no state badge at all ("Downloaded" was on every row).
        $this->assertStringNotContainsString('data-state="downloaded"', $html);
        $this->assertStringNotContainsString('badge', $this->row($html, $ok->id));
        $this->assertStringNotContainsString('badge', $this->row($html, $read->id));
        $this->assertStringContainsString('class="ch-read"', $this->row($html, $read->id));
        $this->assertStringContainsString('is-read', $this->row($html, $read->id));

        // Failed: the badge plus the reason in plain words.
        $failedRow = $this->row($html, $failed->id);
        $this->assertStringContainsString('badge badge-failed', $failedRow);
        $this->assertStringContainsString('The source answers 404 for this chapter; it may have been removed or renumbered there. · 2 attempts', $failedRow);

        // Queued: its badge, no reason line.
        $queuedRow = $this->row($html, $queued->id);
        $this->assertStringContainsString('badge badge-queued', $queuedRow);
        $this->assertStringNotContainsString('ch-reason', $queuedRow);

        // Needs review wins over Failed and still says why.
        $reviewRow = $this->row($html, $review->id);
        $this->assertStringContainsString('Needs review', $reviewRow);
        $this->assertStringContainsString('The page loaded but contains no chapter text.', $reviewRow);

        // The first unread downloaded chapter is marked as the current one.
        $this->assertStringContainsString('is-current', $this->row($html, $ok->id));
        $this->assertStringContainsString('Up next', $this->row($html, $ok->id));
    }

    public function test_exception_counts_show_only_when_non_zero(): void
    {
        $clean = $this->novel('Clean Novel');
        foreach ([1, 2, 3] as $n) {
            $this->chapter($clean, $n);
        }

        $html = $this->get(route('novels.show', $clean->id))->assertOk()->getContent();
        $this->assertStringContainsString('All 3 chapters downloaded', $html);
        $this->assertStringContainsString('Nothing queued or failed', $html);
        foreach (['nv-issues', '</span> queued', '</span> failed', 'missing from the numbering', 'duplicate(s)', 'data-state-filter="queued"', 'data-state-filter="failed"', 'Retry downloads now'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        // The four numbers are stated once: no metric tiles, no second meter.
        $this->assertStringNotContainsString('metric-strip', $html);
        $this->assertSame(1, substr_count($html, 'aria-label="Reading progress"'));

        $messy = $this->novel('Messy Novel');
        $this->chapter($messy, 1);
        $this->chapter($messy, 1);                                     // duplicate
        $this->chapter($messy, 4);                                     // gaps 2, 3
        $this->chapter($messy, 5, ['status' => 0, 'download_date' => null]);  // queued
        $this->chapter($messy, 6, ['status' => 0, 'download_date' => null, 'attempts' => 1, 'last_failure_reason' => 'cloudflare']);

        $html = $this->get(route('novels.show', $messy->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#tone-pending">1</span> queued#', $html);
        $this->assertMatchesRegularExpression('#tone-danger">1</span> failed#', $html);
        $this->assertMatchesRegularExpression('#tone-warning">2</span> missing from the numbering#', $html);
        $this->assertStringContainsString('Remove 1 duplicate(s)', $html);
        $this->assertStringContainsString('data-state-filter="queued"', $html);
        $this->assertStringContainsString('data-state-filter="failed"', $html);
        $this->assertStringContainsString('Retry downloads now', $html);
        $this->assertStringNotContainsString('need review', $html);
    }

    public function test_page_keeps_every_action_and_the_lead_slot(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1);

        $html = $this->get(route('novels.show', $novel->id))->assertOk()->getContent();

        foreach ([
            'data-scope="unread-next"', 'data-scope="range"', 'id="offlineRemove"',      // offline
            route('novels.download_epub', $novel->id), 'data-command="epub"', 'data-command="send_to_kindle"',
            route('novels.edit', $novel->id), 'id="pauseToggle"', 'data-command="metadata"', 'id="deleteNovel"',
            route('search.index', ['novel' => $novel->id]),                            // search in novel
            'id="frequentToggle"', 'id="editTags"',
            'data-command="toc"', 'data-command="chapter"', 'data-command="normalize_labels"', 'data-command="fix_chapters"',
            'data-command="clean_content"', 'data-command="chapter_cleaner"', route('novels.snapshots', $novel->id),
            'id="chReadUpTo"', 'id="chReadFrom"', 'id="chReadAll"', 'id="chUnreadAll"', 'id="chMarkRead"', 'id="chMarkUnread"',
            'action="' . route('novels.jump_chapter', $novel->id) . '"',               // go to chapter N
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }

        // The slot comment is the lead's include point, directly under the card.
        $this->assertMatchesRegularExpression('#</section>\s*\{\{-- source-unavailable#', file_get_contents(resource_path('views/novels/show.blade.php')));

        // Filter: labelled input, real toggle buttons, Literata title.
        $this->assertStringContainsString('<label for="chFilter" class="visually-hidden">', $html);
        $this->assertStringContainsString('<button type="button" class="nv-chip" data-state-filter="all" aria-pressed="true">', $html);
        $this->assertStringContainsString('<h1 class="nv-title">', $html);
    }

    public function test_jump_link_points_at_the_list_page_holding_the_next_chapter(): void
    {
        $novel = $this->novel();
        foreach (range(1, 120) as $n) {
            $this->chapter($novel, $n, $n <= 60 ? ['read_at' => now()] : []);
        }
        $next = NovelChapter::where('novel_id', $novel->id)->where('chapter', 61)->sole();

        $html = $this->get(route('novels.show', $novel->id))->assertOk()->getContent();

        // Chapter 61 is row 61 → page 2 of 50-row pages.
        $this->assertStringContainsString('page=2#ch-' . $next->id, $html);
        $this->assertStringContainsString('Jump to chapter 61', $html);
        $this->assertStringContainsString('Continue at chapter 61', $html);
    }

    public function test_chapters_json_carries_state_and_reason_and_stays_conditional(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1, ['read_at' => now()]);
        $this->chapter($novel, 2, ['status' => 0, 'download_date' => null, 'attempts' => 3, 'last_failure_reason' => 'short_content']);
        $this->chapter($novel, 3, ['status' => 0, 'download_date' => null]);

        $res = $this->getJson(route('novels.chapters_json', $novel->id))->assertOk();
        $res->assertJsonPath('chapters.0.state', 'downloaded')
            ->assertJsonPath('chapters.0.read', true)
            ->assertJsonPath('chapters.0.reason', null)
            ->assertJsonPath('chapters.1.state', 'failed')
            ->assertJsonPath('chapters.1.reason', 'The page had too little text to be the chapter.')
            ->assertJsonPath('chapters.1.attempts', 3)
            ->assertJsonPath('chapters.2.state', 'queued')
            ->assertJsonPath('chapters.2.date', null);

        $this->getJson(route('novels.chapters_json', $novel->id), ['If-None-Match' => $res->headers->get('ETag')])
            ->assertStatus(304);
    }

    // ---- Library -------------------------------------------------------------

    /** reading (4/10 read), finished (all read), unstarted (none read). */
    private function shelfLibrary(): array
    {
        $n = [];
        $n['reading'] = $this->novel('Bbb Reading', ['no_of_chapters' => 10]);
        foreach (range(1, 10) as $c) {
            $this->chapter($n['reading'], $c, $c <= 4 ? ['read_at' => now()->subHour()] : []);
        }
        $n['finished'] = $this->novel('Ccc Finished', ['no_of_chapters' => 3, 'origin' => 'translated', 'origin_language' => 'zh']);
        foreach (range(1, 3) as $c) {
            $this->chapter($n['finished'], $c, ['read_at' => now()->subDays(2)]);
        }
        $n['unstarted'] = $this->novel('Aaa Unstarted', ['no_of_chapters' => 5]);
        foreach (range(1, 2) as $c) {
            $this->chapter($n['unstarted'], $c);
        }

        return $n;
    }

    /** Tile ids per shelf heading, in page order. */
    private function shelves(string $html): array
    {
        preg_match_all('#<h2 class="lib-shelf-title" id="shelf-(\w+)">.*?</ul>#s', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [$block, $shelf]) {
            preg_match_all('#<li class="lib-tile" data-id="(\d+)"#', $block, $ids);
            $out[$shelf] = array_map('intval', $ids[1]);
        }

        return $out;
    }

    public function test_default_grid_groups_tiles_into_shelves(): void
    {
        $n = $this->shelfLibrary();

        $html = $this->get(route('novels.index', ['view' => 'grid', 'sort' => 'read']))->assertOk()->getContent();

        $this->assertSame([
            'reading' => [$n['reading']->id],
            'unstarted' => [$n['unstarted']->id],
            'finished' => [$n['finished']->id],
        ], $this->shelves($html));
        $this->assertStringContainsString('>Reading <span class="lib-shelf-count">1</span></h2>', $html);
        $this->assertStringContainsString('>Not started <span class="lib-shelf-count">1</span></h2>', $html);

        // Origin in the caption, not on the cover.
        $this->assertStringContainsString('<span class="lib-tile-origin">Translated · ZH</span>', $html);
        $this->assertStringNotContainsString('cover-chip', $html);
    }

    public function test_an_explicit_sort_or_filter_shows_one_flat_list(): void
    {
        $n = $this->shelfLibrary();

        foreach ([['sort' => 'name'], ['sort' => 'read', 'filter' => 'reading'], ['sort' => 'read', 'search' => 'a']] as $params) {
            $html = $this->get(route('novels.index', $params + ['view' => 'grid']))->assertOk()->getContent();
            $this->assertStringNotContainsString('lib-shelf-title', $html, json_encode($params));
            $this->assertStringContainsString('id="libGrid"', $html);
        }

        // Name order is kept as-is (no shelf grouping).
        $this->get(route('novels.index', ['view' => 'grid', 'sort' => 'name']))
            ->assertSeeInOrder(['Aaa Unstarted', 'Bbb Reading', 'Ccc Finished']);
    }

    public function test_table_shows_read_and_downloaded_columns(): void
    {
        $novel = $this->novel('Table Novel', ['no_of_chapters' => 10]);
        foreach (range(1, 10) as $c) {
            // 8 downloaded, 2 queued; 3 read.
            $this->chapter($novel, $c, $c <= 8 ? ($c <= 3 ? ['read_at' => now()] : []) : ['status' => 0, 'download_date' => null]);
        }

        $html = $this->get(route('novels.index', ['view' => 'table']))->assertOk()->getContent();

        $this->assertStringContainsString('<th class="col-read">Read</th>', $html);
        $this->assertStringContainsString('<th class="col-progress">Downloaded</th>', $html);
        $this->assertStringNotContainsString('>Progress</th>', $html);
        $this->assertStringContainsString('<span class="progress-value" data-read-percent>30%</span>', $html);
        $this->assertStringContainsString('<span class="progress-value" data-progress-percent>80%</span>', $html);
        // The narrow list says both, too.
        $this->assertStringContainsString('Read 3 of 10 · 30%', $html);
        $this->assertStringContainsString('Downloaded 8 of 10 on source · 80%', $html);

        // The grid's reading figure is the same one.
        $this->assertMatchesRegularExpression('#lib-tile-sub "[^>]*>3 / 10<#', $this->get(route('novels.index', ['view' => 'grid']))->getContent());
    }
}
