<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateePub;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * November 2026 audit, NovelController: edit form with an empty chapter
 * total, SQL-counted missing/duplicate chapters, the slim Library query and
 * conditional GETs.
 */
class NovelControllerAuditTest extends TestCase
{
    use RefreshDatabase;

    private ?string $written = null;

    protected function tearDown(): void
    {
        if ($this->written && is_file($this->written)) {
            File::delete($this->written);
        }
        parent::tearDown();
    }

    private function novel(array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => 'Audit Novel',
            'author' => 'Someone',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 10,
            'description' => 'LONG-SYNOPSIS-MARKER',
        ], $attrs));
    }

    private function chapter(Novel $novel, float $n, array $attrs = []): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => "Chapter {$n}",
            'url' => "https://example.test/c-{$n}",
        ]);
        if ($attrs) {
            $chapter->forceFill($attrs)->save();
        }

        return $chapter;
    }

    private function stats(int $id): array
    {
        $method = new \ReflectionMethod(\App\Http\Controllers\NovelController::class, 'novelStats');

        return $method->invoke(app(\App\Http\Controllers\NovelController::class), $id);
    }

    // --- 1. empty "Total chapters" on edit ---------------------------------

    public function test_clearing_total_chapters_on_edit_saves_zero_instead_of_500(): void
    {
        $novel = $this->novel(['no_of_chapters' => 42]);

        $this->put(route('novels.update', $novel->id), ['name' => 'Audit Novel', 'no_of_chapters' => ''])
            ->assertRedirect(route('novels.show', $novel->id));

        $this->assertSame(0, (int) $novel->fresh()->no_of_chapters);
    }

    // --- 2. missing / duplicate counts -------------------------------------

    public function test_missing_and_duplicate_counts_follow_the_old_rules(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1);
        $this->chapter($novel, 2);
        $this->chapter($novel, 2);            // duplicate group (2, book 0)
        $this->chapter($novel, 3, ['blacklist' => 1]); // blacklisted: does not cover 3
        $this->chapter($novel, 4.5);          // intval → covers 4
        $this->chapter($novel, 5, ['double_chapter' => 1]); // covers 5 and 6
        $this->chapter($novel, 9);
        $this->chapter($novel, 9, ['double_chapter' => 1]); // second dup group; would cover 10 (> latest, ignored)
        $this->chapter($novel, 0);            // prologue: never counts

        $stats = $this->stats($novel->id);

        // 1..9 minus {1, 2, 4, 5, 6, 9} = {3, 7, 8}
        $this->assertSame(3, $stats['missing_count']);
        $this->assertSame(2, $stats['duplicate_count']);

        $this->get(route('novels.show', $novel->id))
            ->assertOk()
            ->assertSee('Remove 2 duplicate(s)');
    }

    public function test_no_chapters_means_nothing_missing(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 0);

        $stats = $this->stats($novel->id);
        $this->assertSame(0, $stats['missing_count']);
        $this->assertSame(0, $stats['duplicate_count']);
    }

    public function test_a_misparsed_huge_chapter_number_renders_quickly(): void
    {
        $novel = $this->novel();
        foreach ([1, 2, 3] as $n) {
            $this->chapter($novel, $n);
        }
        $this->chapter($novel, 20231105);

        $before = memory_get_usage();
        memory_reset_peak_usage();
        $start = microtime(true);

        $this->get(route('novels.show', $novel->id))
            ->assertOk()
            // 1..20231105 minus the four covered numbers.
            ->assertSee(number_format(20231105 - 4));

        $this->assertLessThan(2.0, microtime(true) - $start);
        // range(1, 20231105) alone would need hundreds of MB.
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before);

        // Only integers are cached.
        $cached = \Illuminate\Support\Facades\Cache::get(\App\Http\Helpers\CacheHelper::novelStatsKey($novel->id));
        $this->assertSame(20231105 - 4, $cached['missing_count']);
        $this->assertArrayNotHasKey('missing_chapters', $cached);
    }

    // --- 3. slim Library query ---------------------------------------------

    public function test_library_query_does_not_load_description_or_unused_relations(): void
    {
        $novel = $this->novel(['name' => 'Slim Library Novel']);
        $this->chapter($novel, 1);

        DB::enableQueryLog();
        $this->get(route('novels.index'))->assertOk()->assertSee('Slim Library Novel');
        // MySQL/MariaDB quote identifiers with backticks, sqlite with double
        // quotes: compare on one form.
        $queries = array_map(fn($q) => str_replace('`', '"', $q), array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        // The page's novels are loaded by id once the order is known; the
        // candidate query that orders them is slimmer still.
        $library = array_values(array_filter($queries, fn($q) => str_contains($q, 'from "novels"')));
        $this->assertNotEmpty(array_filter($library, fn($q) => str_contains($q, '"author"') && str_contains($q, '"id" in (')), 'Library page query not found');
        foreach ($library as $sql) {
            $this->assertStringNotContainsString('description', $sql);
            $this->assertStringNotContainsString('"novels".*', $sql);
        }

        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/from "(groups|languages)"/', $sql);
        }
    }

    // --- 6. conditional GETs + 404 -----------------------------------------

    public function test_chapters_json_answers_304_on_a_repeat_request(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1);

        $first = $this->getJson(route('novels.chapters_json', $novel->id))->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('private', $first->headers->get('Cache-Control'));

        $this->getJson(route('novels.chapters_json', $novel->id), ['If-None-Match' => $etag])
            ->assertStatus(304);

        // A change produces a new body, so the old tag no longer matches.
        $this->chapter($novel, 2);
        $this->getJson(route('novels.chapters_json', $novel->id), ['If-None-Match' => $etag])
            ->assertOk();
    }

    public function test_offline_manifest_answers_304_on_a_repeat_request(): void
    {
        $novel = $this->novel();
        $this->chapter($novel, 1, ['status' => 1]);

        $etag = $this->getJson(route('novels.offline_manifest', $novel->id))->assertOk()->headers->get('ETag');
        $this->getJson(route('novels.offline_manifest', $novel->id), ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_conditional_get_is_on_the_polled_routes_but_not_the_reader(): void
    {
        foreach (['novels.chapters_json', 'novels.offline_manifest', 'opds.index', 'palette'] as $name) {
            $this->assertContains('cache.headers:private;max_age=0;etag', Route::getRoutes()->getByName($name)->middleware(), $name);
        }
        $this->assertNotContains('cache.headers:private;max_age=0;etag', Route::getRoutes()->getByName('chapters.show')->middleware());
    }

    public function test_chapters_json_404s_for_a_missing_novel(): void
    {
        $this->getJson(route('novels.chapters_json', 999999))->assertNotFound();
    }

    public function test_epub_download_is_conditional(): void
    {
        $novel = $this->novel(['name' => 'Conditional Epub', 'status' => 1]);
        File::ensureDirectoryExists(storage_path('app/ePub'));
        $this->written = GenerateePub::epubPath($novel);
        File::put($this->written, 'epub-bytes');

        $first = $this->get(route('novels.download_epub', $novel->id))->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->get(route('novels.download_epub', $novel->id), ['If-None-Match' => $etag])
            ->assertStatus(304);

        // A regenerated file (new size / mtime) is downloaded again.
        File::put($this->written, 'epub-bytes, regenerated');
        touch($this->written, time() + 5);
        $this->get(route('novels.download_epub', $novel->id), ['If-None-Match' => $etag])
            ->assertOk()
            ->assertDownload('Conditional Epub - Someone.epub');
    }

    // --- 7. dead code -------------------------------------------------------

    public function test_removed_dead_code_stays_gone(): void
    {
        $this->assertFalse(method_exists(\App\Http\Controllers\NovelController::class, 'get_novel'));
        $this->assertFalse(method_exists(\App\Http\Helpers\CacheHelper::class, 'clearNovelDataTablesCache'));
        $this->assertNull(config('datatables'));
        $this->assertNull(config('bugsnag'));
        $this->assertNull(config('audit'));
    }
}
