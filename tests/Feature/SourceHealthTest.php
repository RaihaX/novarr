<?php

namespace Tests\Feature;

use App\Console\Commands\ChapterScraper;
use App\Console\Commands\SourceHealthCheck;
use App\Group;
use App\Mail\AdminAlertMail;
use App\Novel;
use App\NovelChapter;
use App\Scraping\Fetcher;
use App\Services\AdminAlert;
use App\Services\Discover;
use App\Services\Requests\InlineSearchRunner;
use App\Services\SourceHealth;
use App\Setting;
use App\Support\PublicAddress;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeFetcher;
use Tests\Support\FakeMangaA;
use Tests\Support\FakeMangaB;
use Tests\Support\FakeRequestSource;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Source health (App\Services\SourceHealth): the per-hour counters, the
 * hook points that feed them, the status thresholds, Health → Sources and
 * the hourly "a source started failing" alert.
 */
class SourceHealthTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        // On a server database (CI's MariaDB) the request-search runner tests'
        // child processes record searches outside the test transaction, and
        // those rows stay: start every test from an empty table.
        DB::table('source_stats')->delete();
        Carbon::setTestNow('2026-11-20 12:30:00');
        config([
            'novarr.source_health.min_attempts' => 20,
            'novarr.source_health.failing_below' => 0.5,
            'novarr.source_health.degraded_below' => 0.85,
            'novarr.source_health.retention_days' => 90,
            'novarr.source_health.alert_repeat_hours' => 24,
        ]);
        Cache::forget(SourceHealthCheck::STATE_KEY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sums(string $source, string $kind): array
    {
        $row = DB::table('source_stats')->where('source', $source)->where('kind', $kind)
            ->selectRaw('COALESCE(SUM(ok), 0) AS ok, COALESCE(SUM(failed), 0) AS failed')->first();

        return ['ok' => (int) $row->ok, 'failed' => (int) $row->failed];
    }

    private function reasons(string $source, string $kind): array
    {
        return DB::table('source_stats')->where('source', $source)->where('kind', $kind)->where('reason', '!=', '')
            ->orderBy('reason')->pluck('failed', 'reason')->map(fn($n) => (int) $n)->all();
    }

    /** $ok successes and $failed failures ($reason) for $source in the current hour. */
    private function counts(string $source, int $ok, int $failed, string $reason = 'not_found', string $kind = SourceHealth::KIND_CHAPTER): void
    {
        for ($i = 0; $i < $ok; $i++) {
            SourceHealth::record($source, $kind, true);
        }
        for ($i = 0; $i < $failed; $i++) {
            SourceHealth::record($source, $kind, false, $reason, "Chapter {$i}: gone");
        }
    }

    // ---- record() -----------------------------------------------------------------

    public function test_record_upserts_one_row_per_bucket_and_reason_and_sums_add_up(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        for ($i = 0; $i < 37; $i++) {
            SourceHealth::record('novelfull', 'chapter', true);
        }
        for ($i = 0; $i < 11; $i++) {
            SourceHealth::record('novelfull', 'chapter', false, 'rate_limited', 'Chapter 9: slow down');
        }
        SourceHealth::record('novelfull', 'chapter', false, 'not_found', 'Chapter 10: The source answers 404');

        // One statement per event: an atomic upsert, never read-modify-write.
        $this->assertSame(49, $queries);
        $this->assertSame(['ok' => 37, 'failed' => 12], $this->sums('novelfull', 'chapter'));
        $this->assertSame(['not_found' => 1, 'rate_limited' => 11], $this->reasons('novelfull', 'chapter'));
        $this->assertSame(3, DB::table('source_stats')->count(), 'ok row + one row per reason');

        $row = DB::table('source_stats')->where('reason', 'not_found')->first();
        $this->assertSame('2026-11-20', substr((string) $row->day, 0, 10));
        $this->assertSame(12, (int) $row->hour);
        $this->assertSame('Chapter 10: The source answers 404', $row->last_error);
        $this->assertNotNull($row->last_failed_at);
        $this->assertNotNull(DB::table('source_stats')->where('reason', '')->value('last_ok_at'));

        // A new hour is a new bucket.
        Carbon::setTestNow('2026-11-20 13:05:00');
        SourceHealth::record('novelfull', 'chapter', true);
        $this->assertSame(['ok' => 38, 'failed' => 12], $this->sums('novelfull', 'chapter'));
        $this->assertSame(4, DB::table('source_stats')->count());
    }

    public function test_record_ignores_unknown_kinds_and_cleans_reasons(): void
    {
        SourceHealth::record('x', 'bogus', true);
        SourceHealth::record('', 'search', false, 'Weird Reason!!', str_repeat('e', 400));
        SourceHealth::record('y', 'toc', false, null);

        $this->assertSame(0, DB::table('source_stats')->where('source', 'x')->count());
        $row = DB::table('source_stats')->where('source', SourceHealth::UNKNOWN)->first();
        $this->assertSame('weirdreason', $row->reason);
        $this->assertSame(255, mb_strlen($row->last_error));
        $this->assertSame('failed', DB::table('source_stats')->where('source', 'y')->value('reason'));
    }

    public function test_a_failing_database_never_reaches_the_caller(): void
    {
        Schema::drop('source_stats');

        SourceHealth::record('novelfull', 'chapter', true);
        SourceHealth::record('novelfull', 'chapter', false, 'not_found', 'gone');

        $this->assertTrue(true, 'no exception');
    }

    // ---- identity -------------------------------------------------------------------

    public function test_source_identity(): void
    {
        $group = Group::find(9001) ?? tap(new Group(), fn($g) => $g->forceFill(['id' => 9001, 'label' => 'G', 'url' => 'https://group.test'])->save());

        $novelfull = Novel::create(['name' => 'NF', 'status' => 0, 'group_id' => $group->id, 'no_of_chapters' => 1, 'translator_url' => 'https://novelfull.com/a.html']);
        $other = Novel::create(['name' => 'Other', 'status' => 0, 'group_id' => $group->id, 'no_of_chapters' => 1, 'translator_url' => 'https://www.Some-Site.test/b']);
        $groupOnly = Novel::create(['name' => 'Group only', 'status' => 0, 'group_id' => $group->id, 'no_of_chapters' => 1]);

        $this->assertSame('novelfull', SourceHealth::keyForNovel($novelfull));
        $this->assertSame('www.some-site.test', SourceHealth::keyForNovel($other), 'only the catch-all claims it: its host');
        $this->assertSame('group.test', SourceHealth::keyForNovel($groupOnly));
        $this->assertSame('novelfull', SourceHealth::keyForSource(new \App\Sources\NovelFullSource()));
        $this->assertSame('suwayomi:42', SourceHealth::keyForSource(new \App\Sources\SuwayomiSource('42')));
    }

    // ---- hook points ----------------------------------------------------------------

    /** A ChapterScraper whose fetch answers from $responder (no network, no sleeping). */
    private function fakeScraper(\Closure $responder): void
    {
        $scraper = new class extends ChapterScraper {
            public ?\Closure $responder = null;

            protected function generateChapterDescription($chapter, ?string &$failureReason = null)
            {
                return ($this->responder)($chapter, $failureReason);
            }

            protected function pause(int $seconds): void
            {
            }
        };
        $scraper->responder = $responder;
        $this->app[Kernel::class]->registerCommand($scraper);
        Setting::put('scrape_adaptive', '0');
        Setting::put('scrape_min_delay', 0);
        Setting::put('scrape_max_delay', 0);
    }

    private function chapterNovel(int $count, string $host = 'novelfull.com'): Novel
    {
        $group = Group::find(9001) ?? tap(new Group(), fn($g) => $g->forceFill(['id' => 9001, 'label' => 'G', 'url' => 'https://group.test'])->save());
        $novel = Novel::create(['name' => 'Hooked', 'status' => 0, 'group_id' => $group->id, 'no_of_chapters' => $count, 'translator_url' => "https://{$host}/hooked.html"]);
        for ($n = 1; $n <= $count; $n++) {
            NovelChapter::create(['novel_id' => $novel->id, 'chapter' => $n, 'label' => "Chapter {$n}", 'url' => "https://{$host}/hooked/chapter-{$n}"]);
        }

        return $novel;
    }

    public function test_chapter_downloads_record_ok_not_found_and_rate_limited(): void
    {
        $novel = $this->chapterNovel(4);
        $this->fakeScraper(function ($chapter, ?string &$reason) {
            return match ((int) $chapter->chapter) {
                1, 2 => '<p>' . str_repeat('word ', 400) . '</p>',
                3 => tap('', function () use (&$reason) { $reason = 'not_found'; }),
                default => tap('', function () use (&$reason) { $reason = 'rate_limited'; }),
            };
        });

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertSame(['ok' => 2, 'failed' => 2], $this->sums('novelfull', 'chapter'), Artisan::output());
        $this->assertSame(['not_found' => 1, 'rate_limited' => 1], $this->reasons('novelfull', 'chapter'));
        $this->assertStringContainsString('Chapter 3', (string) DB::table('source_stats')->where('reason', 'not_found')->value('last_error'));
    }

    public function test_a_skipped_chapter_with_a_junk_url_is_not_recorded(): void
    {
        $novel = $this->chapterNovel(0);
        NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'label' => 'Arial', 'url' => 'Arial, sans-serif']);
        $this->fakeScraper(fn() => $this->fail('nothing is fetched'));

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertSame(0, DB::table('source_stats')->count());
    }

    public function test_the_single_chapter_download_records_its_outcome(): void
    {
        $novel = $this->chapterNovel(2);
        $this->fakeScraper(function ($chapter, ?string &$reason) {
            if ((int) $chapter->chapter === 1) {
                return '<p>' . str_repeat('word ', 400) . '</p>';
            }
            $reason = 'no_content';

            return '';
        });
        $ids = NovelChapter::where('novel_id', $novel->id)->orderBy('chapter')->pluck('id')->all();

        Artisan::call('novel:chapter', ['--chapter' => $ids[0]]);
        Artisan::call('novel:chapter', ['--chapter' => $ids[1]]);

        $this->assertSame(['ok' => 1, 'failed' => 1], $this->sums('novelfull', 'chapter'));
        $this->assertSame(['no_content' => 1], $this->reasons('novelfull', 'chapter'));
    }

    private function tocNovel(): Novel
    {
        config(['novarr.novelarrow_host' => 'novelping.com']);
        $group = Group::find(1) ?? tap(new Group(), fn($g) => $g->forceFill(['id' => 1, 'label' => 'Novel Arrow', 'url' => 'https://novelping.com'])->save());

        return Novel::create(['name' => 'Toc Novel', 'status' => 0, 'group_id' => $group->id, 'no_of_chapters' => 0, 'translator_url' => 'https://novelping.com/novel/test-novel']);
    }

    public function test_chapter_list_runs_record_ok_and_empty(): void
    {
        $api = 'https://novelping.com/api-web/novels/test-novel/chapters?sort=asc';
        $items = array_map(fn($n) => ['chapter_id' => "chapter-{$n}", 'chapter_name' => "Chapter {$n}"], range(1, 3));
        $fake = new FakeFetcher([$api => json_encode(['items' => $items])]);
        app()->instance(Fetcher::class, $fake);
        $novel = $this->tocNovel();

        $this->artisan('novel:toc', ['novel' => $novel->id])->assertExitCode(0);
        $this->assertSame(['ok' => 1, 'failed' => 0], $this->sums('novelarrow', 'toc'));

        $fake->route($api, json_encode(['items' => []]));
        $this->artisan('novel:toc', ['novel' => $novel->id])->assertExitCode(0);
        $this->assertSame(['ok' => 1, 'failed' => 1], $this->sums('novelarrow', 'toc'));
        // NovelArrow marks a walk that found no chapters as cut short.
        $this->assertSame(['partial' => 1], $this->reasons('novelarrow', 'toc'));

        // The rule itself: empty → empty_toc; an incremental walk with nothing new is ok.
        SourceHealth::recordToc($novel, [], false);
        SourceHealth::recordToc($novel, [], false, true);
        $this->assertSame(['ok' => 2, 'failed' => 2], $this->sums('novelarrow', 'toc'));
        $this->assertSame(['empty_toc' => 1, 'partial' => 1], $this->reasons('novelarrow', 'toc'));
    }

    public function test_searches_record_their_outcome_on_discover_and_request_search(): void
    {
        FakeRequestSource::register();
        FakeRequestSource::$search[FakeMangaA::class] = [['name' => 'Solo', 'url' => 'https://manga-a.test/solo']];
        FakeRequestSource::$search[FakeMangaB::class] = null;

        $discover = app(Discover::class);
        $this->assertNotNull($discover->results(new FakeMangaA(), 'search', 'solo'));
        $this->assertNull($discover->results(new FakeMangaB(), 'search', 'solo'));
        // A list the source doesn't offer is no call.
        $this->assertSame([], $discover->results(new FakeMangaA(), 'popular'));

        (new InlineSearchRunner())->run('manga', 'solo', [['fakemanga_a', 'fakemanga_b']], [
            'fakemanga_a' => new FakeMangaA(), 'fakemanga_b' => new FakeMangaB(),
        ], (int) now()->addMinute()->getPreciseTimestamp(3));

        $this->assertSame(['ok' => 2, 'failed' => 0], $this->sums('fakemanga_a', 'search'));
        $this->assertSame(['ok' => 0, 'failed' => 2], $this->sums('fakemanga_b', 'search'));
        $this->assertSame(['fetch_failed' => 2], $this->reasons('fakemanga_b', 'search'));
        FakeRequestSource::reset();
    }

    // ---- thresholds -----------------------------------------------------------------

    public function test_status_thresholds(): void
    {
        $this->assertSame(SourceHealth::IDLE, SourceHealth::judge(0, 0));
        // Low volume is never judged, however bad.
        $this->assertSame(SourceHealth::HEALTHY, SourceHealth::judge(0, 19));
        $this->assertSame(SourceHealth::HEALTHY, SourceHealth::judge(17, 3), '85% is healthy');
        $this->assertSame(SourceHealth::DEGRADED, SourceHealth::judge(16, 4), '80%');
        $this->assertSame(SourceHealth::DEGRADED, SourceHealth::judge(10, 10), '50% is degraded, not failing');
        $this->assertSame(SourceHealth::FAILING, SourceHealth::judge(9, 11), '45%');
        $this->assertSame(SourceHealth::FAILING, SourceHealth::judge(0, 20));

        // Rate limits alone: Degraded at worst.
        $this->assertSame(SourceHealth::DEGRADED, SourceHealth::judge(0, 50, 50));
        $this->assertSame(SourceHealth::DEGRADED, SourceHealth::judge(10, 40, 30), 'without the rate limits: 10 of 20 ok');
        $this->assertSame(SourceHealth::FAILING, SourceHealth::judge(5, 45, 10), 'without the rate limits: 5 of 40 ok');
        $this->assertSame(SourceHealth::DEGRADED, SourceHealth::judge(2, 30, 20), 'without the rate limits only 12 attempts');

        config(['novarr.source_health.min_attempts' => 5]);
        $this->assertSame(SourceHealth::FAILING, SourceHealth::judge(0, 5));
    }

    public function test_the_overview_judges_the_last_24_hours_worst_kind_and_sorts_worst_first(): void
    {
        // Yesterday 11:00 is outside the 24 h window (it starts at 13:00).
        Carbon::setTestNow('2026-11-19 11:10:00');
        $this->counts('oldfail.test', 0, 30);
        Carbon::setTestNow('2026-11-20 12:30:00');
        $this->counts('oldfail.test', 1, 0);

        $this->counts('failing.test', 2, 28);
        $this->counts('degraded.test', 30, 0);
        $this->counts('degraded.test', 15, 10, 'fetch_failed', SourceHealth::KIND_TOC);
        $this->counts('limited.test', 0, 40, 'rate_limited');
        $this->counts('healthy.test', 50, 1);
        $this->counts('quiet.test', 0, 5);

        $rows = collect(app(SourceHealth::class)->overview())->keyBy('key');

        $this->assertSame(SourceHealth::FAILING, $rows['failing.test']['status']);
        $this->assertSame(SourceHealth::DEGRADED, $rows['degraded.test']['status'], 'its chapter lists drag it down');
        $this->assertSame(SourceHealth::DEGRADED, $rows['limited.test']['status']);
        $this->assertSame(SourceHealth::HEALTHY, $rows['healthy.test']['status']);
        $this->assertSame(SourceHealth::HEALTHY, $rows['quiet.test']['status']);
        $this->assertTrue($rows['quiet.test']['low_volume']);
        $this->assertSame(SourceHealth::HEALTHY, $rows['oldfail.test']['status'], 'yesterday morning is outside the 24 hours');
        $this->assertSame(31, $rows['oldfail.test']['kinds']['chapter']['week']['attempts']);
        $this->assertSame(1, $rows['oldfail.test']['kinds']['chapter']['day']['attempts']);
        $this->assertSame(['day' => '2026-11-19', 'ok' => 0, 'failed' => 30], $rows['oldfail.test']['daily'][5]);
        $this->assertCount(7, $rows['oldfail.test']['daily']);
        $this->assertSame(SourceHealth::IDLE, $rows['novelfull']['status'], 'registered, never called');

        $this->assertSame(['reason' => 'rate_limited', 'label' => 'rate limited (HTTP 429/503)', 'count' => 40], $rows['limited.test']['top_reason']);
        $this->assertSame('Chapter 27: gone', $rows['failing.test']['last_error']['message']);
        $this->assertNotNull($rows['failing.test']['last_ok_at']);

        $order = array_column(app(SourceHealth::class)->overview(), 'key');
        $this->assertSame('failing.test', $order[0]);
        $this->assertLessThan(array_search('healthy.test', $order), array_search('degraded.test', $order));
        $this->assertLessThan(array_search('novelfull', $order), array_search('healthy.test', $order));
    }

    // ---- the page ---------------------------------------------------------------------

    public function test_the_page_renders_for_the_admin_worst_first(): void
    {
        $this->counts('failing.test', 2, 28);
        $this->counts('healthy.test', 40, 0);

        $response = $this->get(route('health.sources'));

        $response->assertOk()
            ->assertSee('Sources')
            ->assertSee('failing.test')
            ->assertSee('Failing')
            ->assertSee('not found (404)')
            ->assertSee('Chapter 27: gone')
            ->assertSeeInOrder(['data-source="failing.test"', 'data-source="healthy.test"'], false);

        $this->get(route('health.index'))->assertOk()->assertSee(route('health.sources'), false);
    }

    public function test_the_page_is_refused_to_a_reader_and_on_the_public_address(): void
    {
        $this->enableSignIn();
        $token = $this->tokenFor($this->makeReader());
        $this->asToken($token)->get(route('health.sources'))->assertStatus(403);

        // The admin, on the public address (as cloudflared forwards it).
        config(['novarr.public_hosts' => ['read.example.com'], 'novarr.public_url' => null]);
        $this->asToken($this->tokenFor($this->owner()))
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
            ->getJson('http://read.example.com/health/sources')
            ->assertStatus(403)
            ->assertExactJson(['message' => PublicAddress::ADMIN_MESSAGE, 'code' => 'forbidden']);
    }

    // ---- the alert ---------------------------------------------------------------------

    /** @return object{sent: array} */
    private function captureAlerts(): object
    {
        $alert = new class extends AdminAlert {
            public array $sent = [];

            public function send(string $subject, string $message, ?string $url = null): array
            {
                $this->sent[] = compact('subject', 'message', 'url');

                return ['webhook' => true, 'email' => true];
            }
        };
        $this->app->instance(AdminAlert::class, $alert);

        return $alert;
    }

    public function test_the_alert_fires_once_on_the_transition_and_not_again_within_24_hours(): void
    {
        $alert = $this->captureAlerts();
        $this->counts('healthy.test', 30, 0);

        // Not failing yet: nothing.
        $this->artisan('sources:health-check')->assertExitCode(0);
        $this->assertSame([], $alert->sent);

        $this->counts('broken.test', 3, 27, 'not_found');
        $this->artisan('sources:health-check')->assertExitCode(0);
        $this->assertCount(1, $alert->sent);
        $this->assertStringContainsString('broken.test', $alert->sent[0]['message']);
        $this->assertStringContainsString('chapters: 3 of 30 ok (10%)', $alert->sent[0]['message']);
        $this->assertStringContainsString('not found (404)', $alert->sent[0]['message']);
        $this->assertStringEndsWith('/health/sources', $alert->sent[0]['url']);

        // Still failing an hour later: no repeat.
        Carbon::setTestNow('2026-11-20 13:30:00');
        $this->artisan('sources:health-check')->assertExitCode(0);
        $this->assertCount(1, $alert->sent);

        // Recovers, then fails again within 24 hours: still no repeat.
        $this->counts('broken.test', 200, 0);
        $this->artisan('sources:health-check')->assertExitCode(0);
        $this->counts('broken.test', 0, 400, 'not_found');
        $this->artisan('sources:health-check')->assertExitCode(0);
        $this->assertCount(1, $alert->sent);

        // A day after the alert, a new transition is told again.
        Carbon::setTestNow('2026-11-21 13:00:00');
        $this->counts('broken.test', 200, 0);
        $this->artisan('sources:health-check')->assertExitCode(0);
        $this->counts('broken.test', 0, 900, 'not_found');
        $this->artisan('sources:health-check')->assertExitCode(0);
        $this->assertCount(2, $alert->sent);
    }

    public function test_no_alert_for_a_source_that_was_already_failing(): void
    {
        $alert = $this->captureAlerts();
        $this->counts('broken.test', 0, 30);
        Cache::forever(SourceHealthCheck::STATE_KEY, ['failing' => ['broken.test'], 'alerted' => []]);

        $this->artisan('sources:health-check')->assertExitCode(0);

        $this->assertSame([], $alert->sent);
    }

    public function test_rate_limits_alone_never_alert(): void
    {
        $alert = $this->captureAlerts();
        $this->counts('slow.test', 0, 60, 'rate_limited');

        $this->artisan('sources:health-check')->assertExitCode(0);

        $this->assertSame([], $alert->sent);
    }

    public function test_the_alert_goes_to_the_owners_email(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $owner = $this->owner();
        $owner->forceFill(['email' => 'owner@example.com'])->save();
        $this->counts('broken.test', 0, 30);

        $this->artisan('sources:health-check')->assertExitCode(0);

        Mail::assertSent(AdminAlertMail::class, fn($mail) => $mail->hasTo('owner@example.com')
            && str_contains($mail->subjectLine, 'broken.test')
            && str_contains($mail->body, '0 of 30 ok'));
    }

    // ---- retention ----------------------------------------------------------------------

    public function test_prune_keeps_90_days(): void
    {
        Carbon::setTestNow('2026-08-21 10:00:00'); // 91 days ago
        SourceHealth::record('old.test', 'chapter', true);
        Carbon::setTestNow('2026-08-23 10:00:00'); // 89 days ago
        SourceHealth::record('kept.test', 'chapter', true);
        Carbon::setTestNow('2026-11-20 12:30:00');

        $this->artisan('sources:health-check', ['--prune' => true])->assertExitCode(0);

        $this->assertSame(['kept.test'], DB::table('source_stats')->pluck('source')->all());
    }
}
