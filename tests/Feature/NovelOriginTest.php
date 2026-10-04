<?php

namespace Tests\Feature;

use App\Mail\NewChapters;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use App\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * Novel origin (translated web novel vs original English): schema, model
 * helpers, NovelUpdates parsing, precedence (manual > novelupdates >
 * inferred), the novel:origin backfill, the edit-page override and every
 * place the label surfaces (cover chip, novel page, Library chips, palette,
 * daily email).
 */
class NovelOriginTest extends TestCase
{
    use RefreshDatabase;

    private const NU_URL = 'https://www.novelupdates.com/series/lord-of-the-mysteries/';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('dashboard_attention', [], 900);
    }

    private function novel(string $name, array $attrs = []): Novel
    {
        return Novel::create(array_merge([
            'name' => $name,
            'author' => 'Some Author',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 10,
        ], $attrs));
    }

    private function fakeNovelUpdates(): FakeFetcher
    {
        $fake = new FakeFetcher([self::NU_URL => 'novelupdates/series-page.html']);
        app()->instance(Fetcher::class, $fake);

        return $fake;
    }

    // ---- schema + model -----------------------------------------------------

    public function test_migration_adds_the_origin_columns_and_index(): void
    {
        foreach (['origin', 'origin_language', 'origin_source', 'origin_type'] as $column) {
            $this->assertTrue(Schema::hasColumn('novels', $column), $column);
        }
        $this->assertContains('idx_novels_origin', array_column(Schema::getIndexes('novels'), 'name'));
    }

    public function test_model_labels(): void
    {
        $ko = $this->novel('A', ['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'manual']);
        $this->assertTrue($ko->isTranslated());
        $this->assertSame('Translated · Korean', $ko->originLabel());
        $this->assertSame('TRANSLATED · KO', $ko->originChip());
        $this->assertSame('Translated (KO)', $ko->originShortLabel());

        $unknownLang = new Novel(['origin' => 'translated']);
        $this->assertSame('Translated', $unknownLang->originLabel());
        $this->assertSame('TRANSLATED', $unknownLang->originChip());
        $this->assertSame('Translated', $unknownLang->originShortLabel());

        $en = new Novel(['origin' => 'original', 'origin_language' => 'en']);
        $this->assertFalse($en->isTranslated());
        $this->assertSame('Original · English', $en->originLabel());
        $this->assertSame('ORIGINAL · EN', $en->originChip());
        $this->assertSame('Original', $en->originShortLabel());

        foreach ([new Novel(), new Novel(['origin' => 'unknown'])] as $unknown) {
            $this->assertNull($unknown->isTranslated());
            $this->assertNull($unknown->originLabel());
            $this->assertNull($unknown->originChip());
            $this->assertNull($unknown->originShortLabel());
        }
    }

    public function test_apply_origin_respects_precedence(): void
    {
        $novel = $this->novel('A');

        $this->assertTrue($novel->applyOrigin(['origin' => 'original', 'origin_language' => 'en'], 'inferred'));
        $this->assertTrue($novel->applyOrigin(['origin' => 'translated', 'origin_language' => 'zh', 'origin_type' => 'Web Novel (CN)'], 'novelupdates'));
        // Inference never overrides NovelUpdates.
        $this->assertFalse($novel->applyOrigin(['origin' => 'original', 'origin_language' => 'en'], 'inferred'));
        $this->assertSame('novelupdates', $novel->fresh()->origin_source);

        $novel->applyOrigin(['origin' => 'original', 'origin_language' => 'en'], 'manual');
        // Nothing overrides manual.
        $this->assertFalse($novel->applyOrigin(['origin' => 'translated', 'origin_language' => 'ko'], 'novelupdates'));
        $fresh = $novel->fresh();
        $this->assertSame(['original', 'en', 'manual'], [$fresh->origin, $fresh->origin_language, $fresh->origin_source]);
    }

    // ---- NovelUpdates parsing + metadata path --------------------------------

    public function test_fetch_novel_updates_metadata_reads_type_and_language(): void
    {
        $this->fakeNovelUpdates();

        $metadata = fetchNovelUpdatesMetadata(self::NU_URL);

        $this->assertSame('Web Novel (CN)', $metadata['type']);
        $this->assertSame('Chinese', $metadata['original_language']);
        // The existing fields still parse from the same fixture.
        $this->assertSame('Lord of the Mysteries', $metadata['title']);
        $this->assertSame('1432', (string) $metadata['no_of_chapters']);
        $this->assertTrue($metadata['completed']);
        $this->assertTrue($metadata['fully_translated']);
        $this->assertContains('诡秘之主 (new)', $metadata['associated']);
    }

    public function test_fetch_novel_updates_metadata_defaults_when_blocks_are_missing(): void
    {
        app()->instance(Fetcher::class, new FakeFetcher([self::NU_URL => '<html><body><div class="seriestitlenu">X</div></body></html>']));

        $metadata = fetchNovelUpdatesMetadata(self::NU_URL);
        $this->assertSame('', $metadata['type']);
        $this->assertSame('', $metadata['original_language']);
    }

    public function test_get_metadata_sets_origin_from_a_confident_match(): void
    {
        $this->fakeNovelUpdates();
        $novel = $this->novel('Lord of the Mysteries', ['novelupdates_url' => self::NU_URL]);
        $novel->forceFill(['novelupdates_match_score' => 1.0])->saveQuietly();

        getMetadata($novel);

        $fresh = $novel->fresh();
        $this->assertSame('translated', $fresh->origin);
        $this->assertSame('zh', $fresh->origin_language);
        $this->assertSame('novelupdates', $fresh->origin_source);
        $this->assertSame('Web Novel (CN)', $fresh->origin_type);
    }

    public function test_get_metadata_ignores_a_low_scored_saved_url(): void
    {
        $this->fakeNovelUpdates();
        $novel = $this->novel('Something Else Entirely', ['novelupdates_url' => self::NU_URL]);
        $novel->forceFill(['novelupdates_match_score' => 0.4])->saveQuietly();

        getMetadata($novel);

        $this->assertNull($novel->fresh()->origin);
    }

    public function test_manual_override_sticks_across_a_metadata_refresh(): void
    {
        $this->fakeNovelUpdates();
        $novel = $this->novel('Lord of the Mysteries', ['novelupdates_url' => self::NU_URL]);
        $novel->forceFill(['novelupdates_match_score' => 1.0])->saveQuietly();

        $this->put(route('novels.update', $novel->id), ['origin' => 'original', 'origin_language' => 'en'])
            ->assertRedirect();
        $this->assertSame('manual', $novel->fresh()->origin_source);

        getMetadata($novel->fresh());
        $this->artisan('novel:origin', ['novel' => $novel->id, '--force' => true, '--delay' => 0])->assertSuccessful();

        $fresh = $novel->fresh();
        $this->assertSame(['original', 'en', 'manual'], [$fresh->origin, $fresh->origin_language, $fresh->origin_source]);
    }

    // ---- novel:origin backfill -----------------------------------------------

    public function test_backfill_uses_novel_updates_when_matched_and_infers_otherwise(): void
    {
        $fake = $this->fakeNovelUpdates();

        $matched = $this->novel('Lord of the Mysteries', ['author' => 'Cuttlefish That Loves Diving', 'novelupdates_url' => self::NU_URL]);
        $matched->forceFill(['novelupdates_match_score' => 0.97])->saveQuietly();
        $english = $this->novel('Shadow Slave', ['author' => 'Guiltythree', 'translator_url' => 'https://novelping.com/novel/shadow-slave']);
        $korean = $this->novel('Omniscient Reader', ['author' => '싱숑']);
        $tl = $this->novel('Some Cultivation', ['author' => 'Mad Snail']);
        $c = NovelChapter::create(['novel_id' => $tl->id, 'chapter' => 1, 'book' => 0, 'label' => 'Chapter 1', 'url' => 'https://x.test/1']);
        $c->description = '<p>Translator: Sorry for the late chapter!</p><p>The sect master laughed.</p>';
        $c->status = 1;
        $c->save();
        $manual = $this->novel('Mine', ['origin' => 'translated', 'origin_language' => 'ja', 'origin_source' => 'manual', 'author' => 'Someone']);

        $this->artisan('novel:origin', ['--delay' => 0])
            ->expectsOutputToContain('Translated · Chinese')
            ->expectsTable(['Origin', 'Novels', 'NovelUpdates', 'Inferred', 'Manual'], [
                ['translated', 4, 1, 2, 1],
                ['original', 1, 0, 1, 0],
                ['unknown', 0, 0, 0, 0],
            ])
            ->assertSuccessful();

        $this->assertSame(['translated', 'zh', 'novelupdates', 'Web Novel (CN)'], array_values($matched->fresh()->only(['origin', 'origin_language', 'origin_source', 'origin_type'])));
        $this->assertSame(['original', 'en', 'inferred'], array_values($english->fresh()->only(['origin', 'origin_language', 'origin_source'])));
        $this->assertSame(['translated', 'ko', 'inferred'], array_values($korean->fresh()->only(['origin', 'origin_language', 'origin_source'])));
        $this->assertSame(['translated', null, 'inferred'], array_values($tl->fresh()->only(['origin', 'origin_language', 'origin_source'])));
        $this->assertSame(['translated', 'ja', 'manual'], array_values($manual->fresh()->only(['origin', 'origin_language', 'origin_source'])));
        $this->assertCount(1, $fake->calls);

        // A second run keeps everything and fetches nothing.
        $this->artisan('novel:origin', ['--delay' => 0])->expectsOutputToContain('0 novel(s) changed, 0 NovelUpdates')->assertSuccessful();
        $this->assertCount(1, $fake->calls);
    }

    public function test_backfill_no_fetch_skips_the_network(): void
    {
        $fake = $this->fakeNovelUpdates();
        $matched = $this->novel('Lord of the Mysteries', ['author' => 'Cuttlefish That Loves Diving', 'novelupdates_url' => self::NU_URL]);
        $matched->forceFill(['novelupdates_match_score' => 0.97])->saveQuietly();

        $this->artisan('novel:origin', ['novel' => $matched->id, '--no-fetch' => true])->assertSuccessful();

        $this->assertSame([], $fake->calls);
        // Matched on NovelUpdates, so a Latin author is not called "original".
        $this->assertSame('unknown', $matched->fresh()->origin);
        $this->assertSame('inferred', $matched->fresh()->origin_source);
    }

    public function test_backfill_force_re_evaluates_inferred_rows(): void
    {
        $novel = $this->novel('Shadow Slave', ['author' => 'Guiltythree']);
        $this->artisan('novel:origin', ['novel' => $novel->id, '--no-fetch' => true])->assertSuccessful();
        $this->assertSame('original', $novel->fresh()->origin);

        $novel->forceFill(['author' => '爱潜水的乌贼'])->saveQuietly();
        $this->artisan('novel:origin', ['novel' => $novel->id, '--no-fetch' => true])->assertSuccessful();
        $this->assertSame('original', $novel->fresh()->origin, 'kept without --force');

        $this->artisan('novel:origin', ['novel' => $novel->id, '--no-fetch' => true, '--force' => true])->assertSuccessful();
        $this->assertSame(['translated', 'zh'], [$novel->fresh()->origin, $novel->fresh()->origin_language]);
    }

    public function test_backfill_unknown_novel_id_fails(): void
    {
        $this->artisan('novel:origin', ['novel' => 999])->assertFailed();
    }

    public function test_creating_a_novel_from_the_form_infers_origin(): void
    {
        $this->post(route('novels.store'), ['name' => 'Shadow Slave', 'author' => 'Guiltythree'])
            ->assertRedirect();

        $novel = Novel::where('name', 'Shadow Slave')->first();
        $this->assertSame(['original', 'en', 'inferred'], [$novel->origin, $novel->origin_language, $novel->origin_source]);
    }

    // ---- edit page override --------------------------------------------------

    public function test_edit_page_shows_the_origin_fields_and_who_set_them(): void
    {
        $novel = $this->novel('A', ['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'novelupdates', 'origin_type' => 'Web Novel (KR)']);

        $html = $this->get(route('novels.edit', $novel->id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<option value="translated"\s+selected#', $html);
        $this->assertMatchesRegularExpression('#<option value="ko"\s+selected#', $html);
        $this->assertStringContainsString('Set by NovelUpdates (Web Novel (KR))', $html);

        $novel->forceFill(['origin_source' => 'inferred'])->saveQuietly();
        $this->get(route('novels.edit', $novel->id))->assertSee('Inferred from the author and chapters');
        $novel->forceFill(['origin_source' => 'manual'])->saveQuietly();
        $this->get(route('novels.edit', $novel->id))->assertSee('Set by you');
    }

    public function test_saving_unchanged_origin_does_not_make_it_manual(): void
    {
        $novel = $this->novel('A', ['origin' => 'translated', 'origin_language' => 'ko', 'origin_source' => 'novelupdates']);

        $this->put(route('novels.update', $novel->id), ['name' => 'A', 'origin' => 'translated', 'origin_language' => 'ko'])->assertRedirect();
        $this->assertSame('novelupdates', $novel->fresh()->origin_source);

        $this->put(route('novels.update', $novel->id), ['name' => 'A', 'origin' => 'translated', 'origin_language' => 'ja'])->assertRedirect();
        $this->assertSame(['translated', 'ja', 'manual'], [$novel->fresh()->origin, $novel->fresh()->origin_language, $novel->fresh()->origin_source]);

        // "Other" language stores null; "Unknown" hands it back to detection.
        $this->put(route('novels.update', $novel->id), ['origin' => 'translated', 'origin_language' => 'other'])->assertRedirect();
        $this->assertNull($novel->fresh()->origin_language);
        $this->put(route('novels.update', $novel->id), ['origin' => 'unknown', 'origin_language' => 'en'])->assertRedirect();
        $this->assertSame([null, null, null], [$novel->fresh()->origin, $novel->fresh()->origin_language, $novel->fresh()->origin_source]);

        $this->put(route('novels.update', $novel->id), ['origin' => 'bogus'])->assertSessionHasErrors('origin');
    }

    // ---- surfaces --------------------------------------------------------------

    public function test_cover_chip_renders_and_omits(): void
    {
        $html = Blade::render('<x-cover :novel="[\'name\' => \'X\']" chip="TRANSLATED · KO" :progress="30" />');
        $this->assertStringContainsString('<span class="cover-chip">TRANSLATED · KO</span>', $html);
        $this->assertStringContainsString('class="cover cover-md has-chip"', $html);

        $this->assertStringNotContainsString('chip', Blade::render('<x-cover :novel="[\'name\' => \'X\']" />'));
        $this->assertStringNotContainsString('cover-chip', Blade::render('<x-cover :novel="[\'name\' => \'X\']" :chip="null" />'));
    }

    public function test_library_tiles_chips_filter_and_counts(): void
    {
        $this->novel('Korean One', ['origin' => 'translated', 'origin_language' => 'ko']);
        $this->novel('Chinese One', ['origin' => 'translated', 'origin_language' => 'zh']);
        $this->novel('English One', ['origin' => 'original', 'origin_language' => 'en']);
        $this->novel('Mystery One');

        $html = $this->get(route('novels.index', ['view' => 'grid']))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="cover-chip">TRANSLATED · KO</span>', $html);
        $this->assertStringContainsString('<span class="cover-chip">ORIGINAL · EN</span>', $html);
        $this->assertSame(3, substr_count($html, 'class="cover-chip"'));
        $this->assertMatchesRegularExpression('#data-chip="translated"[^>]*>\s*Translated\s*<span class="lib-chip-count ">2</span>#', $html);
        $this->assertMatchesRegularExpression('#data-chip="original"[^>]*>\s*Original\s*<span class="lib-chip-count ">1</span>#', $html);

        $translated = $this->get(route('novels.index', ['view' => 'grid', 'filter' => 'translated']))->getContent();
        $this->assertStringContainsString('Korean One', $translated);
        $this->assertStringContainsString('Chinese One', $translated);
        $this->assertStringNotContainsString('English One', $translated);
        $this->assertStringNotContainsString('Mystery One', $translated);

        $original = $this->get(route('novels.index', ['view' => 'grid', 'filter' => 'original']))->getContent();
        $this->assertStringContainsString('English One', $original);
        $this->assertStringNotContainsString('Korean One', $original);
    }

    public function test_novel_page_shows_the_label_and_cover_chip(): void
    {
        $novel = $this->novel('Korean One', ['origin' => 'translated', 'origin_language' => 'ko']);

        $this->get(route('novels.show', $novel->id))
            ->assertOk()
            ->assertSee('Translated · Korean')
            ->assertSee('<span class="cover-chip" aria-hidden="true">TRANSLATED · KO</span>', false);

        $plain = $this->novel('Plain');
        $this->get(route('novels.show', $plain->id))->assertDontSee('cover-chip', false)->assertDontSee('Origin</span>', false);
    }

    public function test_palette_payload_carries_the_origin_label(): void
    {
        $this->novel('Ascending Korean', ['origin' => 'translated', 'origin_language' => 'ko']);
        $this->novel('Ascending Plain');

        $this->getJson('/palette?q=ascending')
            ->assertOk()
            ->assertJsonPath('novels.0.name', 'Ascending Korean')
            ->assertJsonPath('novels.0.origin_label', 'Translated · Korean')
            ->assertJsonPath('novels.1.origin_label', null);
    }

    public function test_daily_email_rows_carry_the_origin(): void
    {
        Mail::fake();
        Setting::put('summary_email', 'reader@example.test');

        foreach ([
            ['Korean Saga', ['origin' => 'translated', 'origin_language' => 'ko']],
            ['English Saga', ['origin' => 'original', 'origin_language' => 'en']],
            ['Plain Saga', []],
        ] as $i => [$name, $attrs]) {
            $novel = $this->novel($name, $attrs + ['translator_url' => 'https://www.novelfull.test/n' . $i]);
            $c = NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'book' => 0, 'label' => 'Chapter 1', 'url' => "https://www.novelfull.test/n{$i}/1"]);
            $c->forceFill(['description' => '<p>' . str_repeat('word ', 50) . '</p>', 'status' => 1, 'download_date' => now()->subHour()])->save();
        }

        $this->artisan('novel:email-summary')->assertSuccessful();

        Mail::assertSent(NewChapters::class, function (NewChapters $mail) {
            $html = $mail->render();
            $this->assertMatchesRegularExpression('#· novelfull\.test</span>\s+<span style="white-space: nowrap;">· Translated \(KO\)</span>#u', $html);
            $this->assertStringContainsString('<span style="white-space: nowrap;">· Original</span>', $html);
            $this->assertSame(2, substr_count($html, '· Translated (KO)') + substr_count($html, '· Original<'));

            $mail->assertSeeInText('1 new · novelfull.test · Translated (KO)');
            $mail->assertSeeInText('1 new · novelfull.test · Original');

            return true;
        });
    }
}
