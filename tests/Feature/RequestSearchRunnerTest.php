<?php

namespace Tests\Feature;

use App\Services\Discover;
use App\Services\Requests\InlineSearchRunner;
use App\Services\Requests\ProcessSearchRunner;
use App\Services\Requests\RequestSearch;
use App\Services\Requests\SearchRunner;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeMangaA;
use Tests\Support\FakeMangaB;
use Tests\Support\FakeMangaC;
use Tests\Support\FakeRequestSource;
use Tests\TestCase;

/**
 * How a request search asks its sources (App\Services\Requests\SearchRunner):
 * the process runner with real child processes — a stub child script stands
 * in for `artisan requests:search-lane`, so these tests start PHP but never
 * boot sources — its parallelism, deadline and dead children; the lane
 * command itself (run in-process on fake sources); lanes by host; and which
 * runner the container gives.
 */
class RequestSearchRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeRequestSource::register();
    }

    protected function tearDown(): void
    {
        FakeRequestSource::reset();
        putenv('NOVARR_SEARCH_NONCE');
        putenv('NOVARR_SEARCH_QUERY');
        parent::tearDown();
    }

    /**
     * A runner whose children run a stub lane: for each key, "start", then by
     * the key's name — "slowN" sleeps N tenths of a second and answers one
     * item, "die" exits mid-source, "hang…" sleeps 30 s, "noise" prints junk
     * and a forged line before answering, "coldstart" waits 1.2 s BEFORE its
     * "start" line (a child still booting), anything else answers at once.
     */
    private function runner(int $parallelism): ProcessSearchRunner
    {
        return new class($parallelism) extends ProcessSearchRunner {
            protected function command(string $medium, array $keys, int $deadlineMs): array
            {
                $autoload = base_path('vendor/autoload.php');
                $script = <<<'PHP'
                    require $argv[1];
                    use App\Services\Requests\ProcessSearchRunner as R;
                    $nonce = getenv('NOVARR_SEARCH_NONCE');
                    $query = getenv('NOVARR_SEARCH_QUERY');
                    foreach (array_slice($argv, 2) as $key) {
                        if ($key === 'coldstart') { usleep(1200000); }
                        echo R::formatLine($nonce, 'start', $key);
                        if (preg_match('/^slow(\d+)$/', $key, $m)) { usleep((int) $m[1] * 100000); }
                        if ($key === 'die') { exit(3); }
                        if (str_starts_with($key, 'hang')) { sleep(30); }
                        if ($key === 'noise') {
                            echo "Warning: something odd\n", R::formatLine('not-the-nonce', 'done', $key, [['name' => 'Forged']]);
                        }
                        echo R::formatLine($nonce, 'done', $key, [['name' => "{$query} from {$key}", 'url' => "https://{$key}.test/x"]]);
                    }
                    PHP;

                return array_merge([PHP_BINARY, '-r', $script, $autoload], $keys);
            }
        };
    }

    private function deadline(float $seconds): int
    {
        return (int) now()->getPreciseTimestamp(3) + (int) ($seconds * 1000);
    }

    public function test_lanes_run_in_parallel_and_answers_keep_every_key(): void
    {
        $lanes = [['slow6'], ['slow7'], ['slow5'], ['slow8']];

        $started = microtime(true);
        $answers = $this->runner(4)->run('manga', 'solo', $lanes, [], $this->deadline(20));
        $elapsed = microtime(true) - $started;

        // One after another would take 2.6 s; in parallel about the slowest (0.8 s) plus start-up.
        $this->assertLessThan(2.0, $elapsed, "took {$elapsed}s");
        // Keyed by source, in the order they answered (RequestSearch puts hits back in search order).
        $this->assertEqualsCanonicalizing(['slow6', 'slow7', 'slow5', 'slow8'], array_keys($answers));
        foreach ($answers as $key => $answer) {
            $this->assertNull($answer['reason']);
            $this->assertTrue($answer['asked']);
            $this->assertSame([['name' => "solo from {$key}", 'url' => "https://{$key}.test/x"]], $answer['items']);
        }
    }

    public function test_at_most_parallelism_lanes_at_once_and_a_lane_is_asked_in_order(): void
    {
        $started = microtime(true);
        $answers = $this->runner(2)->run('manga', 'q', [['slow5', 'slow4'], ['slow6'], ['slow7'], ['slow3']], [], $this->deadline(20));
        $elapsed = microtime(true) - $started;

        // Two at a time: lane 1 (0.9 s) beside lane 2 (0.6 s), then lane 3
        // (0.7 s) and lane 4 (0.3 s) as places free up: about 1.3 s, where
        // all four at once would take 0.9 s.
        $this->assertGreaterThanOrEqual(1.2, $elapsed, "took {$elapsed}s");
        $this->assertCount(5, $answers);
        $this->assertSame(['q from slow5', 'q from slow4'], [$answers['slow5']['items'][0]['name'], $answers['slow4']['items'][0]['name']]);
    }

    public function test_a_child_that_dies_fails_only_its_unanswered_sources(): void
    {
        $answers = $this->runner(3)->run('manga', 'q', [['ok', 'die', 'never'], ['other']], [], $this->deadline(20));

        $this->assertNull($answers['ok']['reason']);
        $this->assertSame(['items' => null, 'reason' => 'failed', 'asked' => true], $answers['die']);
        $this->assertSame(['items' => null, 'reason' => 'failed', 'asked' => false], $answers['never']);
        $this->assertNull($answers['other']['reason']);
    }

    public function test_children_past_the_deadline_are_stopped_and_reported_as_timeouts(): void
    {
        $started = microtime(true);
        $answers = $this->runner(1)->run('manga', 'q', [['ok', 'hang', 'after'], ['queued']], [], $this->deadline(1.5));
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(5.0, $elapsed, 'the hanging child was stopped, not waited for');
        $this->assertNull($answers['ok']['reason']);
        $this->assertSame(['items' => null, 'reason' => 'timeout', 'asked' => true], $answers['hang']);
        $this->assertSame(['items' => null, 'reason' => 'timeout', 'asked' => false], $answers['after']);
        $this->assertSame(['items' => null, 'reason' => 'timeout', 'asked' => false], $answers['queued']);
    }

    // ---- The per-source limit ----------------------------------------------------

    public function test_a_source_over_its_limit_is_given_up_and_its_lane_goes_on_in_a_new_process(): void
    {
        config(['novarr.requests.source_timeout_seconds' => 0.6]);

        $started = microtime(true);
        $answers = $this->runner(2)->run('manga', 'q', [['ok', 'hang', 'after', 'later']], [], $this->deadline(20));
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(5.0, $elapsed, 'the hanging source was stopped, not waited for');
        // The answer the lane gave before the stop is kept…
        $this->assertSame([['name' => 'q from ok', 'url' => 'https://ok.test/x']], $answers['ok']['items']);
        // …the slow source is a timeout it was asked…
        $this->assertSame(['items' => null, 'reason' => 'timeout', 'asked' => true], $answers['hang']);
        // …and the rest of the lane was asked by a new process.
        $this->assertSame([['name' => 'q from after', 'url' => 'https://after.test/x']], $answers['after']['items']);
        $this->assertNull($answers['later']['reason']);
    }

    public function test_a_lanes_last_source_over_its_limit_starts_no_new_process(): void
    {
        config(['novarr.requests.source_timeout_seconds' => 0.5]);

        $answers = $this->runner(1)->run('manga', 'q', [['ok', 'hang']], [], $this->deadline(20));

        $this->assertSame(['ok', 'hang'], array_keys($answers));
        $this->assertNull($answers['ok']['reason']);
        $this->assertSame(['items' => null, 'reason' => 'timeout', 'asked' => true], $answers['hang']);
    }

    public function test_the_rest_of_a_stopped_lane_goes_next_within_the_parallelism(): void
    {
        config(['novarr.requests.source_timeout_seconds' => 0.5]);

        // One process at a time: the stopped lane's rest goes before the queued lane.
        $answers = $this->runner(1)->run('manga', 'q', [['hang', 'after'], ['other']], [], $this->deadline(20));

        $this->assertSame(['hang', 'after', 'other'], array_keys($answers));
        $this->assertSame('timeout', $answers['hang']['reason']);
        $this->assertNull($answers['after']['reason']);
        $this->assertNull($answers['other']['reason']);
    }

    public function test_the_clock_starts_at_the_start_line_and_the_limit_can_be_off(): void
    {
        // A child that takes longer than the limit to boot is not cut off:
        // its source's clock only starts with its "start" line.
        config(['novarr.requests.source_timeout_seconds' => 0.8]);
        $answers = $this->runner(1)->run('manga', 'q', [['coldstart']], [], $this->deadline(20));
        $this->assertNull($answers['coldstart']['reason']);

        // 0: no per-source limit (slow8 takes 0.8 s, over a 0.5 s limit).
        config(['novarr.requests.source_timeout_seconds' => 0]);
        $this->assertNull($this->runner(1)->run('manga', 'q', [['slow8']], [], $this->deadline(20))['slow8']['reason']);
        config(['novarr.requests.source_timeout_seconds' => 0.5]);
        $this->assertSame('timeout', $this->runner(1)->run('manga', 'q', [['slow8']], [], $this->deadline(20))['slow8']['reason']);
        config(['novarr.requests.source_timeout_seconds' => -3]);
        $this->assertSame(0.0, RequestSearch::sourceTimeoutSeconds());
    }

    public function test_the_overall_deadline_still_stops_lanes_restarted_after_a_timeout(): void
    {
        config(['novarr.requests.source_timeout_seconds' => 0.5]);

        $started = microtime(true);
        $answers = $this->runner(2)->run('manga', 'q', [['hang1', 'hang2', 'hang3', 'hang4', 'hang5', 'hang6']], [], $this->deadline(1.8));
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(4.0, $elapsed, "took {$elapsed}s");
        $this->assertCount(6, $answers);
        foreach ($answers as $key => $answer) {
            $this->assertSame('timeout', $answer['reason'], $key);
            $this->assertNull($answer['items'], $key);
        }
        // The first was asked; the last was never reached before the deadline.
        $this->assertTrue($answers['hang1']['asked']);
        $this->assertFalse($answers['hang6']['asked']);
    }

    public function test_only_lines_with_the_runs_nonce_count(): void
    {
        $answers = $this->runner(1)->run('manga', 'q', [['noise']], [], $this->deadline(20));

        $this->assertSame([['name' => 'q from noise', 'url' => 'https://noise.test/x']], $answers['noise']['items']);
        $this->assertNull(ProcessSearchRunner::parseLine('@@NOVARR-SEARCH@@abc@@not base64 json', 'abc'));
        $this->assertNull(ProcessSearchRunner::parseLine(trim(ProcessSearchRunner::formatLine('abc', 'done', 'k', [])), 'other'));
        $this->assertSame(['event' => 'done', 'key' => 'k', 'items' => [['a' => 1]]],
            ProcessSearchRunner::parseLine(trim(ProcessSearchRunner::formatLine('abc', 'done', 'k', [['a' => 1], 'junk'])), 'abc'));
    }

    public function test_the_lane_command_asks_its_sources_in_order_and_prints_result_lines(): void
    {
        FakeRequestSource::$search[FakeMangaA::class] = [['name' => 'Solo Leveling', 'url' => 'https://manga-a.test/solo']];
        FakeRequestSource::$search[FakeMangaB::class] = fn() => throw new \RuntimeException('boom');
        FakeRequestSource::$search[FakeMangaC::class] = null;
        putenv('NOVARR_SEARCH_NONCE=n1');
        putenv('NOVARR_SEARCH_QUERY=solo');

        $code = Artisan::call('requests:search-lane', [
            'medium' => 'manga',
            'sources' => ['fakemanga_a', 'fakemanga_b', 'fakemanga_c', 'fakenovel_a'],
            '--deadline' => $this->deadline(60),
        ]);

        $this->assertSame(0, $code);
        $events = array_values(array_filter(array_map(fn($l) => ProcessSearchRunner::parseLine($l, 'n1'), explode("\n", Artisan::output()))));
        $this->assertSame([
            ['start', 'fakemanga_a'], ['done', 'fakemanga_a'],
            ['start', 'fakemanga_b'], ['failed', 'fakemanga_b'],
            ['start', 'fakemanga_c'], ['failed', 'fakemanga_c'],
            ['failed', 'fakenovel_a'], // not a manga source
        ], array_map(fn($e) => [$e['event'], $e['key']], $events));
        $this->assertSame('Solo Leveling', $events[1]['items'][0]['name']);
        $this->assertSame(['solo', 'solo', 'solo'], array_column(FakeRequestSource::$calls, 'arg'));

        // Past the deadline nothing is asked.
        FakeRequestSource::$calls = [];
        Artisan::call('requests:search-lane', ['medium' => 'manga', 'sources' => ['fakemanga_a'], '--deadline' => $this->deadline(-1)]);
        $this->assertSame([], FakeRequestSource::$calls);
        $this->assertSame('timeout', ProcessSearchRunner::parseLine(trim(Artisan::output()), 'n1')['event']);
    }

    public function test_sources_of_one_host_share_a_lane_and_bigger_lanes_go_first(): void
    {
        $sources = [
            'mangadex' => new FakeMangaA(),
            'suwayomi:1' => $this->onHost('192.168.1.37:4567'),
            'other' => new FakeMangaC(),
            'suwayomi:2' => $this->onHost('192.168.1.37:4567'),
        ];

        $this->assertSame([['suwayomi:1', 'suwayomi:2'], ['mangadex'], ['other']], RequestSearch::lanes($sources, 1));

        config(['novarr.requests.search_host_parallelism' => 1]);
        $this->assertSame([['suwayomi:1', 'suwayomi:2'], ['mangadex'], ['other']], RequestSearch::lanes($sources));
        config(['novarr.requests.search_host_parallelism' => 0]);
        $this->assertSame(1, RequestSearch::hostParallelism());
    }

    public function test_a_host_with_many_sources_is_spread_over_a_few_lanes(): void
    {
        $sources = ['mangadex' => new FakeMangaA()];
        foreach (range(1, 11) as $n) {
            $sources["suwayomi:{$n}"] = $this->onHost('192.168.1.37:4567');
        }
        config(['novarr.requests.search_host_parallelism' => 3]);

        // Dealt in turn, so each lane keeps search order; bigger lanes first.
        $this->assertSame([
            ['suwayomi:1', 'suwayomi:4', 'suwayomi:7', 'suwayomi:10'],
            ['suwayomi:2', 'suwayomi:5', 'suwayomi:8', 'suwayomi:11'],
            ['suwayomi:3', 'suwayomi:6', 'suwayomi:9'],
            ['mangadex'],
        ], RequestSearch::lanes($sources));

        // Fewer sources than lanes: one each, no empty lane.
        $two = array_slice($sources, 0, 3, true);
        $this->assertSame([['mangadex'], ['suwayomi:1'], ['suwayomi:2']], RequestSearch::lanes($two));

        // Every source is in exactly one lane.
        $flat = array_merge(...RequestSearch::lanes($sources, 5));
        sort($flat);
        $keys = array_keys($sources);
        sort($keys);
        $this->assertSame($keys, $flat);
    }

    private function onHost(string $host): FakeMangaB
    {
        return new class($host) extends FakeMangaB {
            public function __construct(private string $onHost)
            {
            }

            public function discoverHost(): string
            {
                return $this->onHost;
            }
        };
    }

    public function test_the_container_gives_the_process_runner_unless_parallelism_is_one(): void
    {
        $this->app->forgetInstance(SearchRunner::class);

        config(['novarr.requests.search_parallelism' => 4]);
        $this->assertInstanceOf(ProcessSearchRunner::class, app(SearchRunner::class));
        $this->assertNotNull(ProcessSearchRunner::phpBinary());

        config(['novarr.requests.search_parallelism' => 1]);
        $this->assertInstanceOf(InlineSearchRunner::class, app(SearchRunner::class));
        $this->assertSame(1, RequestSearch::parallelism());
        config(['novarr.requests.search_parallelism' => 0]);
        $this->assertSame(1, RequestSearch::parallelism());
    }

    public function test_the_inline_runner_answers_in_the_same_shape(): void
    {
        FakeRequestSource::$search[FakeMangaA::class] = [['name' => 'A', 'url' => 'https://manga-a.test/a']];
        FakeRequestSource::$search[FakeMangaB::class] = null;
        $sources = app(Discover::class)->sourcesFor('manga');

        $answers = (new InlineSearchRunner())->run('manga', 'a', [['fakemanga_a', 'fakemanga_b'], ['fakemanga_c'], ['gone']], $sources, $this->deadline(60));

        $this->assertSame(['items' => [['name' => 'A', 'url' => 'https://manga-a.test/a']], 'reason' => null, 'asked' => true], $answers['fakemanga_a']);
        $this->assertSame(['items' => null, 'reason' => 'failed', 'asked' => true], $answers['fakemanga_b']);
        $this->assertSame(['items' => [], 'reason' => null, 'asked' => true], $answers['fakemanga_c']);
        $this->assertSame(['items' => null, 'reason' => 'failed', 'asked' => false], $answers['gone']);
    }
}
