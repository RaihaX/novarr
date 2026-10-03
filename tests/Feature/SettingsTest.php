<?php

namespace Tests\Feature;

use App\Console\Commands\VerifyCompletion;
use App\Novel;
use App\Scraping\FailureSnapshot;
use App\Scraping\NovelUpdatesMatcher;
use App\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings page: scraper/snapshot/matcher fields, validation (audit L1)
 * and the code paths that read the saved values.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/novarr-settings-snapshots-' . getmypid() . '-' . bin2hex(random_bytes(4));
        config([
            'novarr.snapshots.path' => $this->dir,
            'novarr.snapshots.enabled' => true,
            'novarr.snapshots.keep_per_novel' => 5,
            'novarr.snapshots.days' => 14,
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'scrape_min_delay' => '20',
            'scrape_max_delay' => '60',
            'min_chapter_words' => '300',
            'max_chapters_per_novel_per_run' => '0',
            'max_run_minutes' => '120',
            'novelupdates_match_threshold' => '0.9',
            'snapshots_enabled' => '1',
            'snapshots_keep_per_novel' => '3',
            'snapshots_days' => '30',
        ], $overrides);
    }

    public function test_settings_page_renders_new_fields_with_effective_and_default_values(): void
    {
        $response = $this->get('/settings')->assertOk();

        foreach ([
            'min_chapter_words', 'max_chapters_per_novel_per_run', 'max_run_minutes',
            'snapshots_enabled', 'snapshots_keep_per_novel', 'snapshots_days',
            'novelupdates_match_threshold',
        ] as $key) {
            $response->assertSee('name="' . $key . '"', false);
        }

        $response->assertSee('150-minute scheduler lock');
        $response->assertSee('In effect:', false);
        $response->assertSee('Default: 0.85', false);
    }

    public function test_saving_valid_values_persists_them(): void
    {
        $this->post('/settings', $this->validPayload())
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('300', setting('min_chapter_words'));
        $this->assertSame('0', setting('max_chapters_per_novel_per_run'));
        $this->assertSame('120', setting('max_run_minutes'));
        $this->assertSame('0.9', setting('novelupdates_match_threshold'));
        $this->assertSame('3', setting('snapshots_keep_per_novel'));
        $this->assertSame('30', setting('snapshots_days'));
        $this->assertSame('1', setting('snapshots_enabled'));
        $this->assertDatabaseHas('app_settings', ['key' => 'scrape_max_delay', 'value' => '60']);

        $this->assertSame(0.9, NovelUpdatesMatcher::threshold());
        $this->assertSame(3, FailureSnapshot::keepPerNovel());
        $this->assertSame(30, FailureSnapshot::days());

        $this->get('/settings')->assertOk()->assertSee('(saved)', false);
    }

    public function test_unchecked_snapshots_switch_disables_snapshots(): void
    {
        $payload = $this->validPayload();
        unset($payload['snapshots_enabled']);

        $this->post('/settings', $payload)->assertSessionHasNoErrors();

        $this->assertSame('0', setting('snapshots_enabled'));
        $this->assertFalse(FailureSnapshot::enabled());
    }

    public function test_max_delay_below_min_delay_is_rejected(): void
    {
        $this->from('/settings')
            ->post('/settings', $this->validPayload(['scrape_min_delay' => '100', 'scrape_max_delay' => '50']))
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['scrape_max_delay' => 'The max delay must be greater than or equal to the min delay.']);

        $this->assertNull(setting('scrape_max_delay'));
    }

    public function test_blank_max_delay_is_checked_against_its_default(): void
    {
        // min 120 with max blank → effective max is the 90s default.
        $this->post('/settings', $this->validPayload(['scrape_min_delay' => '120', 'scrape_max_delay' => '']))
            ->assertSessionHasErrors('scrape_max_delay');

        // Equal values are allowed.
        $this->post('/settings', $this->validPayload(['scrape_min_delay' => '60', 'scrape_max_delay' => '60']))
            ->assertSessionHasNoErrors();
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $this->post('/settings', $this->validPayload([
            'novelupdates_match_threshold' => '1.5',
            'max_run_minutes' => '150',
            'min_chapter_words' => '10',
            'snapshots_days' => '0',
        ]))->assertSessionHasErrors([
            'novelupdates_match_threshold', 'max_run_minutes', 'min_chapter_words', 'snapshots_days',
        ]);

        $this->assertNull(setting('novelupdates_match_threshold'));
    }

    public function test_threshold_falls_back_to_constant_when_unset_or_invalid(): void
    {
        $this->assertSame(0.85, NovelUpdatesMatcher::threshold());
        $this->assertSame(NovelUpdatesMatcher::THRESHOLD, NovelUpdatesMatcher::threshold());

        Setting::put('novelupdates_match_threshold', 'nonsense');
        $this->assertSame(0.85, NovelUpdatesMatcher::threshold());

        Setting::put('novelupdates_match_threshold', '3');
        $this->assertSame(0.85, NovelUpdatesMatcher::threshold());
    }

    public function test_threshold_setting_drives_pick_and_verify_completion(): void
    {
        $candidates = [['title' => 'Shadow Slave', 'url' => 'https://www.novelupdates.com/series/shadow-slave/']];
        $this->assertNotNull(NovelUpdatesMatcher::pick($candidates, 'Shadow Slave', null));

        // Strictest threshold: an exact title still scores 1.0, a near miss fails.
        Setting::put('novelupdates_match_threshold', '1.0');
        $this->assertNotNull(NovelUpdatesMatcher::pick($candidates, 'Shadow Slave', null));
        $this->assertNotNull(VerifyCompletion::matchRefusalReason(0.9));

        Setting::put('novelupdates_match_threshold', '0.5');
        $this->assertNull(VerifyCompletion::matchRefusalReason(0.6));
        $this->assertNotNull(VerifyCompletion::matchRefusalReason(0.4));
    }

    public function test_failure_snapshot_honours_retention_and_enabled_settings(): void
    {
        $novel = Novel::create(['name' => 'Settings Snapshot', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 1]);

        Setting::put('snapshots_keep_per_novel', '2');
        foreach (range(1, 4) as $i) {
            \Carbon\Carbon::setTestNow(now()->addSecond());
            FailureSnapshot::store($novel, null, "<html>{$i}</html>", 'empty_toc');
        }
        \Carbon\Carbon::setTestNow();

        $this->assertCount(2, FailureSnapshot::files($novel->id));

        // Setting overrides the (enabled) config.
        Setting::put('snapshots_enabled', '0');
        $this->assertFalse(FailureSnapshot::enabled());
        $this->assertNull(FailureSnapshot::store($novel, null, '<html>x</html>', 'empty_toc'));

        // Unset → config fallback.
        Setting::put('snapshots_enabled', null);
        $this->assertTrue(FailureSnapshot::enabled());
        config(['novarr.snapshots.enabled' => false]);
        $this->assertFalse(FailureSnapshot::enabled());
    }

    public function test_failure_snapshot_falls_back_to_config(): void
    {
        $this->assertSame(5, FailureSnapshot::keepPerNovel());
        $this->assertSame(14, FailureSnapshot::days());

        Setting::put('snapshots_days', '7');
        $this->assertSame(7, FailureSnapshot::days());
    }
}
