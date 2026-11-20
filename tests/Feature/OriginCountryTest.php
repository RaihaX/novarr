<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use App\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The origin country (JP / KR / CN / EN): applyOrigin precedence and
 * confidence, inferOrigin's sample (five chapters + genres), novel:origin
 * --reinfer / --dry-run, originCountry() against scopeOriginCountry(), the
 * badge, the Library filter, the edit form and the API field. Every title,
 * author and passage is invented.
 */
class OriginCountryTest extends TestCase
{
    use RefreshDatabase;

    /** About 150 words of English with no name, address or place in it. */
    private const QUIET = 'The morning was quiet and the street smelled of rain. She walked to the old market and counted '
        . 'the coins in her pocket. Nothing much happened, and that was fine with her. ';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('dashboard_attention', [], 900);
    }

    private function novel(string $name, array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => $name,
            'author' => 'Plain Writer',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 10,
        ], $attrs));
    }

    /** Downloaded chapters 1…n; $extra[n] is appended to chapter n's body. */
    private function chapters(Novel $novel, int $count, array $extra = []): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $c = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => $i, 'book' => 0, 'label' => "Chapter {$i}", 'url' => "https://example.test/{$novel->id}/{$i}"]);
            $c->description = '<p>' . str_repeat(self::QUIET, 4) . ($extra[$i] ?? '') . '</p>';
            $c->status = 1;
            $c->save();
        }
    }

    // ---- applyOrigin ---------------------------------------------------------------

    public function test_precedence_is_manual_novelupdates_source_inferred_and_confidence_is_stored(): void
    {
        $this->assertSame(['inferred' => 1, 'source' => 2, 'novelupdates' => 3, 'manual' => 4], Novel::ORIGIN_SOURCES);
        $novel = $this->novel('Invented One');

        $this->assertTrue($novel->applyOrigin(['origin' => 'translated', 'origin_language' => 'ko', 'confidence' => 'medium'], 'inferred'));
        $this->assertSame('medium', $novel->fresh()->origin_confidence);
        // An inferred answer replaces an inferred one.
        $this->assertTrue($novel->applyOrigin(['origin' => 'translated', 'origin_language' => 'zh'], 'inferred'));
        $this->assertSame(['zh', 'low'], [$novel->fresh()->origin_language, $novel->fresh()->origin_confidence], 'no confidence = low for inferred');

        $this->assertTrue($novel->applyOrigin(['origin' => 'translated', 'origin_language' => 'ja', 'confidence' => 'medium'], 'source'));
        $this->assertFalse($novel->applyOrigin(['origin' => 'translated', 'origin_language' => 'zh', 'confidence' => 'high'], 'inferred'), 'inference never overrides the source');
        $this->assertSame(['ja', 'source', 'medium'], [$novel->fresh()->origin_language, $novel->fresh()->origin_source, $novel->fresh()->origin_confidence]);

        $this->assertTrue($novel->applyOrigin(['origin' => 'translated', 'origin_language' => 'ko'], 'novelupdates'));
        $this->assertSame('high', $novel->fresh()->origin_confidence, 'NovelUpdates is high');
        $this->assertFalse($novel->applyOrigin(['origin' => 'translated', 'origin_language' => 'ja'], 'source'), 'the source never overrides NovelUpdates');

        $this->assertTrue($novel->applyOrigin(['origin' => 'original', 'origin_language' => 'en'], 'manual'));
        $this->assertFalse($novel->applyOrigin(['origin' => 'translated', 'origin_language' => 'ko'], 'novelupdates'));
        $fresh = $novel->fresh();
        $this->assertSame(['original', 'en', 'manual', 'high'], [$fresh->origin, $fresh->origin_language, $fresh->origin_source, $fresh->origin_confidence]);

        $this->assertFalse($novel->applyOrigin(['origin' => 'original'], 'bogus'));
    }

    // ---- inferOrigin ---------------------------------------------------------------

    public function test_infer_reads_the_first_five_chapters_only(): void
    {
        // Chapter 5 carries a Korean form of address; chapter 6 Japanese
        // honorifics that would outweigh it if it were read.
        $novel = $this->novel('Invented Rain');
        $this->chapters($novel, 6, [
            5 => ' Hyung, wait for me, he called across the road.',
            6 => ' Baker-san bowed. Grocer-san bowed back. Captain-san laughed at them both.',
        ]);

        $result = $novel->inferOrigin();

        $this->assertSame(['translated', 'ko'], [$result['origin'], $result['origin_language']]);
        $fresh = $novel->fresh();
        $this->assertSame(['translated', 'ko', 'inferred', $result['confidence']], [$fresh->origin, $fresh->origin_language, $fresh->origin_source, $fresh->origin_confidence]);
        $this->assertContains($result['confidence'], ['medium', 'high']);
    }

    public function test_infer_passes_the_series_genres(): void
    {
        $novel = $this->novel('Invented Mountain');
        $this->chapters($novel, 5);
        $without = $novel->inferOrigin(false);
        $this->assertNotSame('zh', $without['origin_language']);
        $this->assertNull($novel->fresh()->origin_source, 'apply = false writes nothing');

        $novel->tags()->attach(Tag::create(['name' => 'Wuxia'])->id);
        $result = $novel->fresh()->inferOrigin();

        $this->assertSame(['translated', 'zh', 'medium'], [$result['origin'], $result['origin_language'], $result['confidence']]);
        $this->assertSame('medium', $novel->fresh()->origin_confidence);
        $this->assertSame('CN', $novel->fresh()->originCountry());
    }

    // ---- novel:origin --reinfer / --dry-run --------------------------------------------

    private function staleLibrary(): array
    {
        $stale = $this->novel('Invented Stale', ['origin' => 'original', 'origin_language' => 'en', 'origin_source' => 'inferred', 'origin_confidence' => 'low']);
        $this->chapters($stale, 5, [2 => ' Hyung, look at this. Hyung, over here.']);
        $nu = $this->novel('Invented Updates', ['origin' => 'translated', 'origin_language' => 'zh', 'origin_source' => 'novelupdates', 'origin_confidence' => 'high']);
        $this->chapters($nu, 5, [2 => ' Hyung, look at this.']);
        $manual = $this->novel('Invented Manual', ['origin' => 'translated', 'origin_language' => 'ja', 'origin_source' => 'manual', 'origin_confidence' => 'high']);
        $this->chapters($manual, 5, [2 => ' Hyung, look at this.']);

        return [$stale, $nu, $manual];
    }

    private static function snapshot(): array
    {
        return DB::table('novels')->orderBy('id')->get(['id', 'origin', 'origin_language', 'origin_source', 'origin_confidence'])->map(fn($r) => (array) $r)->all();
    }

    public function test_dry_run_prints_the_table_and_changes_nothing(): void
    {
        [$stale] = $this->staleLibrary();
        $before = self::snapshot();

        $this->artisan('novel:origin', ['--dry-run' => true, '--reinfer' => true, '--no-fetch' => true])
            ->expectsOutputToContain('original · en → translated · ko')
            ->expectsOutputToContain('NovelUpdates — kept')
            ->expectsOutputToContain('manual — kept')
            ->expectsOutputToContain('Dry run: 1 novel(s) would change; nothing was written.')
            ->assertSuccessful();

        $this->assertSame($before, self::snapshot());
        $this->assertSame('en', $stale->fresh()->origin_language);
    }

    public function test_reinfer_replaces_inferred_rows_only(): void
    {
        [$stale, $nu, $manual] = $this->staleLibrary();

        $this->artisan('novel:origin', ['--no-fetch' => true])->assertSuccessful();
        $this->assertSame('en', $stale->fresh()->origin_language, 'a definite inferred row is kept without --reinfer');

        $this->artisan('novel:origin', ['--reinfer' => true, '--no-fetch' => true])->assertSuccessful();

        $fresh = $stale->fresh();
        $this->assertSame(['translated', 'ko', 'inferred'], [$fresh->origin, $fresh->origin_language, $fresh->origin_source]);
        $this->assertContains($fresh->origin_confidence, ['medium', 'high']);
        $this->assertSame(['zh', 'novelupdates'], [$nu->fresh()->origin_language, $nu->fresh()->origin_source]);
        $this->assertSame(['ja', 'manual'], [$manual->fresh()->origin_language, $manual->fresh()->origin_source]);
    }

    // ---- originCountry() == scopeOriginCountry() -------------------------------------

    public function test_the_scope_and_the_method_agree_on_every_combination(): void
    {
        $rows = [];
        foreach ([null, 'unknown', 'translated', 'original'] as $origin) {
            foreach ([null, 'ja', 'ko', 'zh', 'en', 'vi'] as $language) {
                foreach ([null, 'inferred', 'source', 'novelupdates', 'manual'] as $source) {
                    foreach ([null, 'low', 'medium', 'high'] as $confidence) {
                        $rows[] = [
                            'name' => 'Case ' . count($rows), 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 0, 'medium' => 'novel',
                            'origin' => $origin, 'origin_language' => $language, 'origin_source' => $source, 'origin_confidence' => $confidence,
                        ];
                    }
                }
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('novels')->insert($chunk);
        }

        $all = Novel::all();
        $this->assertCount(480, $all);
        $seen = [];
        foreach (['JP', 'KR', 'CN', 'EN', null] as $code) {
            $bySql = Novel::query()->originCountry($code)->orderBy('id')->pluck('id')->all();
            $byPhp = $all->filter(fn(Novel $n) => $n->originCountry() === $code)->pluck('id')->values()->all();
            $this->assertSame($byPhp, $bySql, 'code ' . ($code ?? 'null'));
            $seen = array_merge($seen, $bySql);
        }
        $this->assertCount(480, array_unique($seen), 'every row is in exactly one bucket');
        $this->assertSame([], Novel::query()->originCountry('XX')->pluck('id')->all());

        // Spot checks of the rule.
        $one = fn(array $a) => (new Novel())->forceFill($a)->originCountry();
        $this->assertSame('KR', $one(['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'inferred', 'origin_confidence' => 'medium']));
        $this->assertNull($one(['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'inferred', 'origin_confidence' => 'low']));
        $this->assertNull($one(['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'inferred']), 'an unrated inferred row is hidden');
        $this->assertSame('KR', $one(['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'novelupdates']));
        $this->assertNull($one(['origin' => 'translated', 'origin_language' => 'vi', 'origin_source' => 'manual']));
        $this->assertNull($one(['origin' => 'unknown', 'origin_language' => 'ja', 'origin_source' => 'manual']));
    }

    public function test_labels(): void
    {
        $novel = new Novel(['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'manual']);
        $this->assertSame('Korean', $novel->originCountryLabel());
        $novel->medium = Novel::MEDIUM_MANGA;
        $this->assertSame('Korean manhwa', $novel->originCountryLabel());
        $this->assertNull($novel->originLabel(), 'a manga shows the badge only');
        $this->assertSame('English original', Novel::originCountryName('EN', true));
        $this->assertSame('Japanese manga', Novel::originCountryName('JP', true));
        $this->assertSame('Chinese', Novel::originCountryName('CN'));
        $this->assertNull(Novel::originCountryName(null));
    }

    // ---- Badge -----------------------------------------------------------------------

    private function library(): array
    {
        return [
            'ko' => $this->novel('Invented Korean', ['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'novelupdates', 'origin_confidence' => 'high']),
            'ja' => $this->novel('Invented Japanese', ['origin' => 'translated', 'origin_language' => 'ja', 'origin_source' => 'inferred', 'origin_confidence' => 'high']),
            'zh' => $this->novel('Invented Guess', ['origin' => 'translated', 'origin_language' => 'zh', 'origin_source' => 'inferred', 'origin_confidence' => 'low']),
            'en' => $this->novel('Invented English', ['origin' => 'original', 'origin_language' => 'en', 'origin_source' => 'manual', 'origin_confidence' => 'high']),
            'none' => $this->novel('Invented Blank'),
        ];
    }

    public function test_badge_on_the_library_grid_table_and_series_page(): void
    {
        $n = $this->library();

        $grid = $this->get(route('novels.index', ['view' => 'grid', 'medium' => 'novel']))->assertOk()->getContent();
        $this->assertStringContainsString('data-origin="KR" role="img" aria-label="Origin: Korean" title="Korean">KR</span>', $grid);
        $this->assertStringContainsString('data-origin="JP"', $grid);
        $this->assertStringContainsString('data-origin="EN"', $grid);
        $this->assertStringNotContainsString('data-origin="CN"', $grid, 'a low-confidence guess shows no badge');
        $this->assertSame(3, substr_count($grid, 'data-origin="'));

        $table = $this->get(route('novels.index', ['view' => 'table', 'medium' => 'novel']))->assertOk()->getContent();
        // The narrow list and the full table each carry the badge once.
        $this->assertSame(6, substr_count($table, 'data-origin="'));

        $this->get(route('novels.show', $n['ko']->id))->assertOk()
            ->assertSee('class="badge badge-origin nv-origin" data-origin="KR"', false);
        $this->assertStringNotContainsString('data-origin=', $this->get(route('novels.show', $n['zh']->id))->getContent());

        $manga = $this->novel('Invented Comic', ['medium' => 'manga', 'origin' => 'translated', 'origin_language' => 'zh', 'origin_source' => 'source', 'origin_confidence' => 'medium']);
        $this->get(route('novels.show', $manga->id))->assertOk()->assertSee('title="Chinese manhua"', false);
        $this->get(route('novels.index', ['view' => 'grid', 'medium' => 'manga']))->assertOk()->assertSee('aria-label="Origin: Chinese manhua"', false);
    }

    // ---- Library filter ----------------------------------------------------------------

    private static function names(string $html): array
    {
        preg_match_all('#<span class="lib-tile-title">([^<]+)</span>#', $html, $m);
        sort($m[1]);

        return $m[1];
    }

    public function test_origin_filter_with_counts(): void
    {
        $this->library();
        // Another medium's series never count here.
        $this->novel('Invented Comic', ['medium' => 'manga', 'origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'source', 'origin_confidence' => 'high']);

        $html = $this->get(route('novels.index', ['view' => 'grid', 'medium' => 'novel']))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="">All origins</option>', $html);
        foreach (['Japanese (1)', 'Korean (1)', 'Chinese (0)', 'English original (1)', 'Unknown (2)'] as $option) {
            $this->assertStringContainsString($option, $html);
        }

        $grid = fn(string $origin) => self::names($this->get(route('novels.index', ['view' => 'grid', 'medium' => 'novel', 'origin' => $origin]))->assertOk()->getContent());
        $this->assertSame(['Invented Korean'], $grid('kr'));
        $this->assertSame(['Invented Japanese'], $grid('jp'));
        $this->assertSame([], $grid('cn'));
        $this->assertSame(['Invented English'], $grid('en'));
        $this->assertSame(['Invented Blank', 'Invented Guess'], $grid('unknown'));
        $this->assertCount(5, $grid('bogus'), 'an unknown value is ignored');

        $selected = $this->get(route('novels.index', ['view' => 'grid', 'medium' => 'novel', 'origin' => 'kr']))->getContent();
        $this->assertMatchesRegularExpression('#<option value="kr"\s+selected#', $selected);
    }

    public function test_origin_filter_combines_with_tag_and_list(): void
    {
        $n = $this->library();
        $tag = Tag::create(['name' => 'Invented Tag']);
        $n['ko']->tags()->attach($tag->id);
        $n['ja']->tags()->attach($tag->id);
        $list = \App\ReadingList::create(['user_id' => $this->owner()->id, 'name' => 'Invented List', 'name_key' => \App\ReadingList::key('Invented List')]);
        foreach (['ko', 'en'] as $key) {
            DB::table('reading_list_novel')->insert(['reading_list_id' => $list->id, 'novel_id' => $n[$key]->id, 'added_at' => now()]);
        }

        $grid = fn(array $q) => self::names($this->get(route('novels.index', ['view' => 'grid', 'medium' => 'novel'] + $q))->assertOk()->getContent());
        $this->assertSame(['Invented Japanese'], $grid(['origin' => 'jp', 'tag' => $tag->id]));
        $this->assertSame([], $grid(['origin' => 'en', 'tag' => $tag->id]));
        $this->assertSame(['Invented English'], $grid(['origin' => 'en', 'list' => $list->id]));
        $this->assertSame(['Invented Korean'], $grid(['origin' => 'kr', 'list' => $list->id, 'tag' => $tag->id]));

        // The table view filters the same way, and the chips keep the origin.
        $table = $this->get(route('novels.index', ['view' => 'table', 'medium' => 'novel', 'origin' => 'kr']))->assertOk()->getContent();
        $this->assertStringContainsString('Invented Korean', $table);
        $this->assertStringNotContainsString('Invented Japanese', $table);
        $this->assertMatchesRegularExpression('#data-chip="reading"#', $table);
        $this->assertStringContainsString('origin=kr', $table);
    }

    // ---- Edit form ---------------------------------------------------------------------

    public function test_edit_form_override_and_auto(): void
    {
        $novel = $this->novel('Invented Rain');
        $this->chapters($novel, 5, [3 => ' Hyung, wait for me.']);
        $novel->inferOrigin();
        $this->assertSame('ko', $novel->fresh()->origin_language);

        $html = $this->get(route('novels.edit', $novel->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<option value="auto"\s+selected#', $html);
        foreach (['Auto (detect)', 'Japanese', 'Korean', 'Chinese', 'English original', 'Unknown'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // A choice is yours: manual, high.
        $this->put(route('novels.update', $novel->id), ['origin' => 'ja'])->assertRedirect();
        $fresh = $novel->fresh();
        $this->assertSame(['translated', 'ja', 'manual', 'high'], [$fresh->origin, $fresh->origin_language, $fresh->origin_source, $fresh->origin_confidence]);
        $this->assertMatchesRegularExpression('#<option value="ja"\s+selected#', $this->get(route('novels.edit', $novel->id))->getContent());

        $this->put(route('novels.update', $novel->id), ['origin' => 'en'])->assertRedirect();
        $this->assertSame(['original', 'en', 'manual'], [$novel->fresh()->origin, $novel->fresh()->origin_language, $novel->fresh()->origin_source]);

        // Auto: the manual value goes and detection runs again.
        $this->put(route('novels.update', $novel->id), ['origin' => 'auto'])->assertRedirect();
        $fresh = $novel->fresh();
        $this->assertSame(['translated', 'ko', 'inferred'], [$fresh->origin, $fresh->origin_language, $fresh->origin_source]);

        // Auto on a detected row changes nothing.
        $this->put(route('novels.update', $novel->id), ['origin' => 'auto', 'name' => 'Invented Rain'])->assertRedirect();
        $this->assertSame('inferred', $novel->fresh()->origin_source);
    }

    public function test_manga_edit_form_names_and_auto(): void
    {
        $manga = $this->novel('Invented Comic', ['medium' => 'manga', 'translator_url' => 'https://suwayomi.test/manga/3', 'origin' => 'translated', 'origin_language' => 'ja', 'origin_source' => 'manual', 'origin_confidence' => 'high']);
        $manga->tags()->attach(Tag::create(['name' => 'Manhwa'])->id);

        $html = $this->get(route('novels.edit', $manga->id))->assertOk()->getContent();
        $this->assertStringContainsString('Korean manhwa', $html);
        $this->assertStringNotContainsString('Translated (other language)', $html);

        $this->put(route('novels.update', $manga->id), ['origin' => 'auto'])->assertRedirect();
        $fresh = $manga->fresh();
        $this->assertSame(['ko', 'source', 'medium'], [$fresh->origin_language, $fresh->origin_source, $fresh->origin_confidence], 'Auto re-detects from the stored genres, no network');
    }

    // ---- API -----------------------------------------------------------------------------

    public function test_api_series_objects_carry_origin_country(): void
    {
        $n = $this->library();

        $novels = collect($this->getJson('/api/v1/novels')->assertOk()->json('novels'))->keyBy('id');
        $this->assertSame('KR', $novels[$n['ko']->id]['origin_country']);
        $this->assertSame('JP', $novels[$n['ja']->id]['origin_country']);
        $this->assertNull($novels[$n['zh']->id]['origin_country']);
        $this->assertSame('EN', $novels[$n['en']->id]['origin_country']);
        $this->assertNull($novels[$n['none']->id]['origin_country']);
        $this->assertArrayHasKey('origin_country', $novels[$n['none']->id]);

        $this->assertSame('KR', $this->getJson('/api/v1/novels/' . $n['ko']->id)->assertOk()->json('novel.origin_country'));
    }
}
