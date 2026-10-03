<?php

namespace Tests\Feature;

use App\File;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Round 4: the cover-forward Library grid (default), the table toggle, chip
 * counts/filters, the <x-cover> component and the Discover card template.
 */
class LibraryGridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // attentionIds() reads the dashboard cache; start every test clean.
        Cache::put('dashboard_attention', [], 900);
    }

    private function novel(string $name, int $advertised = 10, array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => $name,
            'author' => 'Some Author',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => $advertised,
        ], $attrs));
    }

    /** Create chapters 1..$toc; the first $downloaded downloaded, the first $read read. */
    private function chapters(Novel $novel, int $toc, int $downloaded, int $read, ?string $readAt = null, ?string $downloadedAt = null): void
    {
        foreach (range(1, $toc) as $n) {
            $c = NovelChapter::create([
                'novel_id' => $novel->id,
                'chapter' => $n,
                'book' => 0,
                'label' => "Chapter {$n}",
                'url' => "https://example.test/{$novel->id}/{$n}",
            ]);
            $c->forceFill([
                'status' => $n <= $downloaded ? 1 : 0,
                'download_date' => $n <= $downloaded ? ($downloadedAt ?? now()->subDays(10)) : null,
                'read_at' => $n <= $read ? ($readAt ?? now()->subDays(2)) : null,
            ])->saveQuietly();
        }
    }

    /** Library state used by most tests: one novel per chip. */
    private function library(): array
    {
        $reading = $this->novel('Reading Novel');
        $this->chapters($reading, 10, 10, 4);                       // 4 / 10

        $fresh = $this->novel('Fresh Chapters Novel');
        $this->chapters($fresh, 10, 8, 5, now()->subDays(2)->toDateTimeString(), now()->subDays(5)->toDateTimeString());
        // Two more downloaded after the last read → "2 NEW".
        NovelChapter::where('novel_id', $fresh->id)->whereIn('chapter', [6, 7])
            ->update(['download_date' => now()->subHour()]);

        $finished = $this->novel('Finished Novel', 5);
        $this->chapters($finished, 5, 5, 5);                        // FINISHED

        $unread = $this->novel('Untouched Novel', 40);
        $this->chapters($unread, 10, 4, 0);                         // NOT STARTED, 4 of 40 downloaded

        return compact('reading', 'fresh', 'finished', 'unread');
    }

    private function tileCount(string $html): int
    {
        return substr_count($html, 'class="lib-tile"');
    }

    public function test_grid_is_the_default_and_renders_one_tile_per_novel_with_reading_progress(): void
    {
        $n = $this->library();

        $html = $this->get(route('novels.index'))->assertOk()->getContent();

        $this->assertSame(4, $this->tileCount($html));
        $this->assertStringContainsString('id="libGrid"', $html);
        $this->assertStringNotContainsString('table-novels', $html);

        // Sublines: read/total, NEW in cyan, FINISHED in green, NOT STARTED muted.
        $this->assertMatchesRegularExpression('#lib-tile-sub "[^>]*>4 / 10<#', $html);
        $this->assertMatchesRegularExpression('#lib-tile-sub tone-pending"[^>]*>2 NEW<#', $html);
        $this->assertMatchesRegularExpression('#lib-tile-sub tone-success"[^>]*>FINISHED<#', $html);
        $this->assertMatchesRegularExpression('#lib-tile-sub tone-muted"[^>]*>NOT STARTED<#', $html);

        // Amber reading edge at the reading percentage; none at 0%.
        $this->assertStringContainsString('<span class="cover-progress" aria-hidden="true"><span style="width: 40%"></span></span>', $html);
        $this->assertStringContainsString('style="width: 100%"', $html);
        $this->assertSame(3, substr_count($html, 'class="cover-progress"'));

        // Whole-tile links to the novel page.
        $this->assertStringContainsString('href="' . route('novels.show', $n['reading']->id) . '" class="lib-tile-link"', $html);
    }

    public function test_view_table_renders_the_table_and_is_remembered(): void
    {
        $this->library();

        $html = $this->get(route('novels.index', ['view' => 'table']))->assertOk()->getContent();
        $this->assertStringContainsString('table-novels', $html);
        $this->assertSame(0, $this->tileCount($html));
        $this->assertStringContainsString('id="selectAll"', $html);

        // The session remembers the choice; the legacy ?view=list means table.
        $this->assertStringContainsString('table-novels', $this->get(route('novels.index'))->getContent());
        $this->assertStringContainsString('table-novels', $this->get(route('novels.index', ['view' => 'list']))->getContent());
        $this->assertSame(4, $this->tileCount($this->get(route('novels.index', ['view' => 'grid']))->getContent()));
    }

    public function test_chip_counts_and_filters(): void
    {
        $n = $this->library();
        Cache::put('dashboard_attention', [['id' => $n['unread']->id, 'name' => 'x', 'reason' => 'y', 'url' => null]], 900);

        $html = $this->get(route('novels.index', ['view' => 'grid']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-chip="new"[^>]*>\s*New chapters\s*<span class="lib-chip-count tone-pending">1</span>#', $html);
        $this->assertMatchesRegularExpression('#data-chip="attention"[^>]*>\s*Needs attention\s*<span class="lib-chip-count tone-warning">1</span>#', $html);

        $only = fn(string $filter) => $this->get(route('novels.index', ['view' => 'grid', 'filter' => $filter]))->assertOk()->getContent();

        $reading = $only('reading');
        $this->assertSame(2, $this->tileCount($reading));           // Reading + Fresh (partly read)
        $this->assertStringContainsString('Reading Novel', $reading);
        $this->assertStringNotContainsString('Finished Novel</span>', $reading);

        $new = $only('new');
        $this->assertSame(1, $this->tileCount($new));
        $this->assertStringContainsString('Fresh Chapters Novel', $new);

        $finished = $only('finished');
        $this->assertSame(1, $this->tileCount($finished));
        $this->assertStringContainsString('Finished Novel', $finished);

        $attention = $only('attention');
        $this->assertSame(1, $this->tileCount($attention));
        $this->assertStringContainsString('Untouched Novel', $attention);
        $this->assertStringContainsString('cover-flag', $attention);

        // Offline is resolved client-side: no ids yet → the page asks the
        // browser; with ids → exactly those novels.
        $this->assertStringContainsString('data-offline-pending', $only('offline'));
        $offline = $this->get(route('novels.index', ['view' => 'grid', 'filter' => 'offline', 'ids' => $n['finished']->id]))->getContent();
        $this->assertSame(1, $this->tileCount($offline));
        $this->assertStringContainsString('Finished Novel', $offline);

        // A chip that matches nothing gets its own empty copy.
        NovelChapter::query()->update(['download_date' => now()->subYear()]);
        Cache::forget('library_reading_v1'); // a direct DB write bypasses the model/controller hooks that bust it
        $this->assertStringContainsString('You’re caught up', $only('new'));
    }

    public function test_paused_novel_gets_the_muted_corner_flag_and_healthy_ones_none(): void
    {
        $paused = $this->novel('Paused Novel');
        $paused->paused_at = now();
        $paused->save();
        $this->chapters($paused, 3, 3, 1);
        $this->chapters($this->novel('Healthy Novel'), 3, 3, 1);

        $html = $this->get(route('novels.index', ['view' => 'grid']))->getContent();
        $this->assertSame(1, substr_count($html, 'cover-flag'));
        $this->assertMatchesRegularExpression('#class="badge badge-paused cover-flag"[^>]*>Paused<#', $html);
    }

    public function test_sort_last_read_puts_the_most_recently_read_first(): void
    {
        $old = $this->novel('Aaa Read Long Ago');
        $this->chapters($old, 2, 2, 1, now()->subDays(30)->toDateTimeString());
        $recent = $this->novel('Zzz Read Yesterday');
        $this->chapters($recent, 2, 2, 1, now()->subDay()->toDateTimeString());
        $this->chapters($this->novel('Mmm Never Read'), 2, 2, 0);

        $this->get(route('novels.index', ['view' => 'grid', 'sort' => 'read']))
            ->assertOk()
            ->assertSeeInOrder(['Zzz Read Yesterday', 'Aaa Read Long Ago', 'Mmm Never Read']);
        $this->get(route('novels.index', ['view' => 'grid', 'sort' => 'name']))
            ->assertSeeInOrder(['Aaa Read Long Ago', 'Mmm Never Read', 'Zzz Read Yesterday']);
    }

    public function test_cover_component_with_an_image(): void
    {
        $novel = $this->novel('Imaged Novel');
        File::forceCreate([
            'file_name' => 'c.jpg', 'file_path' => 'covers/c.jpg',
            'file_type' => Novel::class, 'file_id' => $novel->id,
        ]);
        $novel = Novel::with('file')->find($novel->id);

        $html = Blade::render('<x-cover :novel="$n" size="lg" :progress="25" />', ['n' => $novel]);

        $this->assertStringContainsString('class="cover cover-lg has-image"', $html);
        $this->assertMatchesRegularExpression('#<img src="[^"]*/storage/covers/c\.jpg" alt="Cover of Imaged Novel" width="240" height="360"\s+loading="lazy" decoding="async"#', $html);
        $this->assertStringContainsString('style="width: 25%"', $html);
        // The typographic cover is still drawn underneath as the fallback.
        $this->assertStringContainsString('cover-title cover-title-l', $html);
    }

    public function test_cover_component_without_an_image_is_typographic(): void
    {
        $html = Blade::render(
            '<x-cover :novel="$n" :progress="0" flag="failed" />',
            ['n' => ['name' => 'Ascending the Nine Heavens', 'author' => 'Feng Qingyang', 'chapters' => 1828]]
        );

        $this->assertStringContainsString('class="cover cover-md"', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<span class="cover-title cover-title-m">Ascending the Nine Heavens</span>', $html);
        $this->assertStringContainsString('<span class="cover-author">Feng Qingyang</span>', $html);
        $this->assertStringContainsString('<span class="cover-count">1,828 CH</span>', $html);
        $this->assertStringContainsString('<span class="visually-hidden">Cover of Ascending the Nine Heavens</span>', $html);
        // Failed reads "Failing" in the danger triad; 0% progress draws no edge.
        $this->assertMatchesRegularExpression('#class="badge badge-failed cover-flag"[^>]*>Failing<#', $html);
        $this->assertStringNotContainsString('cover-progress', $html);

        // Flags are for exceptions only.
        $this->assertStringNotContainsString('cover-flag', Blade::render('<x-cover :novel="[\'name\' => \'X\']" flag="completed" />'));
        // Long titles step down and are cut at 90 characters.
        $long = Blade::render('<x-cover :novel="$n" />', ['n' => ['name' => str_repeat('Word ', 30)]]);
        $this->assertStringContainsString('cover-title-s', $long);
        $this->assertStringContainsString('…</span>', $long);
    }

    public function test_discover_renders_the_cover_card_template(): void
    {
        $html = $this->get(route('novels.discover'))->assertOk()->getContent();

        $this->assertStringContainsString('<ul id="discoverResults" class="disc-grid mb-4"', $html);
        $this->assertStringContainsString('<template id="discCardTpl">', $html);
        $tpl = substr($html, strpos($html, '<template id="discCardTpl">'));
        $tpl = substr($tpl, 0, strpos($tpl, '</template>'));

        $this->assertStringContainsString('class="disc-card"', $tpl);
        $this->assertStringContainsString('class="cover cover-md"', $tpl);
        $this->assertStringContainsString('class="cover-count"', $tpl);
        $this->assertStringContainsString('<button type="button" class="disc-add">', $tpl);
        $this->assertStringContainsString('class="disc-synopsis"', $tpl);
        // Load more, skeleton and empty-state copy live in the page script.
        $this->assertStringContainsString('id="discoverMore"', $html);
        $this->assertStringContainsString('disc-skeleton', $html);
        $this->assertStringContainsString('Try a shorter title', $html);
    }

    public function test_downloads_page_renders_the_cover_tile_template_and_queue_note(): void
    {
        $html = $this->get(route('library'))->assertOk()->getContent();

        $this->assertStringContainsString('<template id="dlTileTpl">', $html);
        $this->assertStringContainsString('class="cover cover-md"', $html);
        $this->assertStringContainsString('dl-remove', $html);
        $this->assertStringContainsString('id="dlQueueNote" hidden', $html);
        $this->assertStringContainsString('offlineQueueSize', $html);
        $this->assertStringContainsString('novarr:offline-queue', $html);
    }
}
