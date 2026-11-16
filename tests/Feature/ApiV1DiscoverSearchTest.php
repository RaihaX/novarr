<?php

namespace Tests\Feature;

use App\Jobs\RunDiscoverSearch;
use App\Novel;
use App\Scraping\Fetcher;
use App\Services\Discover;
use App\Services\DiscoverSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeFetcher;
use Tests\TestCase;

/**
 * POST /api/v1/discover/searches and GET /api/v1/discover/searches/{id}:
 * the background browse/search, against faked upstream answers (no network).
 * The phpunit queue is sync, so the job runs inside the POST unless faked.
 */
class ApiV1DiscoverSearchTest extends TestCase
{
    use RefreshDatabase;

    private const NA_LIST = 'https://novelping.com/api-web/novels?';
    private const NF_SEARCH = 'https://novelfull.com/search?keyword=';

    private const SEARCH_KEYS = ['id', 'status', 'message', 'result'];

    private FakeFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function upstream(?array $rows = null, bool $failing = false): void
    {
        $rows ??= [
            ['novel_id' => 'shadow-slave', 'novel_name' => 'Shadow Slave', 'novel_author' => 'Guiltythree', 'novel_status' => 0, 'totalChapter' => 3207],
            ['novel_id' => 'lord-of-the-mysteries', 'novel_name' => 'Lord of the Mysteries', 'novel_author' => 'Cuttlefish', 'novel_status' => 1],
            ['novel_id' => 'free-novel', 'novel_name' => 'A Free Novel', 'novel_author' => ''],
        ];

        $this->fetcher = $failing
            ? new FakeFetcher([], [
                self::NA_LIST => FakeFetcher::fail('fetch_failed', 500),
                self::NF_SEARCH => FakeFetcher::fail('flaresolverr_error', null),
            ])
            : new FakeFetcher([], [
                self::NA_LIST => json_encode(['items' => $rows]),
                self::NF_SEARCH => 'novelfull/search-shadow-slave.html',
            ]);
        app()->instance(Fetcher::class, $this->fetcher);
    }

    private function search(string $id): array
    {
        $search = $this->getJson("/api/v1/discover/searches/{$id}")->assertOk()->json('search');
        $this->assertSame(self::SEARCH_KEYS, array_keys($search));

        return $search;
    }

    public function testTheJobRunsOnTheSyncQueueAndTheResultIsRetrievable(): void
    {
        $this->upstream();
        $inLibrary = Novel::create(['name' => 'Shadow Slave (WN)', 'group_id' => 1, 'translator_url' => 'https://novelarrow.com/novel/shadow-slave_']);

        $posted = $this->postJson('/api/v1/discover/searches', ['type' => 'popular', 'source' => 'novelarrow'])
            ->assertStatus(202)
            ->json('search');
        $this->assertSame(self::SEARCH_KEYS, array_keys($posted));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $posted['id']);

        $search = $this->search($posted['id']);
        $this->assertSame('done', $search['status']);
        $this->assertNull($search['message']);

        // The same body as the blocking browse answers.
        $browse = $this->getJson('/api/v1/discover/browse?type=popular&source=novelarrow')->assertOk()->json();
        $this->assertSame($browse, $search['result']);
        $this->assertSame(['items', 'page', 'has_more'], array_keys($search['result']));
        $this->assertCount(3, $search['result']['items']);
        $this->assertTrue($search['result']['items'][0]['in_library']);
        $this->assertSame($inLibrary->id, $search['result']['items'][0]['novel_id']);
        $this->assertFalse($search['result']['has_more']);
    }

    public function testPagingAndSearchParametersReachTheJob(): void
    {
        $rows = [];
        for ($i = 1; $i <= 45; $i++) {
            $rows[] = ['novel_id' => "novel-{$i}", 'novel_name' => "Novel {$i}"];
        }
        $this->upstream($rows);

        $id = $this->postJson('/api/v1/discover/searches', ['type' => 'popular', 'page' => 2, 'per_page' => 10])->assertStatus(202)->json('search.id');
        $result = $this->search($id)['result'];
        $this->assertSame(2, $result['page']);
        $this->assertTrue($result['has_more']);
        $this->assertSame('Novel 11', $result['items'][0]['title']);
        $this->assertCount(10, $result['items']);

        $id = $this->postJson('/api/v1/discover/searches', ['type' => 'search', 'source' => 'novelfull', 'q' => 'shadow slave'])->assertStatus(202)->json('search.id');
        $search = $this->search($id);
        $this->assertSame('done', $search['status']);
        $this->assertNotEmpty($search['result']['items']);
        $this->assertSame('novelfull', $search['result']['items'][0]['source']);
    }

    public function testPostAnswersRunningBeforeTheJobHasRun(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/discover/searches', ['type' => 'search', 'q' => 'shadow'])->assertStatus(202);

        $id = $response->json('search.id');
        $response->assertExactJson(['search' => ['id' => $id, 'status' => 'running', 'message' => null, 'result' => null]]);
        Queue::assertPushed(RunDiscoverSearch::class, fn(RunDiscoverSearch $job) => $job->searchId === $id);
        $this->assertSame('running', $this->search($id)['status']);

        // Each search gets its own id.
        $other = $this->postJson('/api/v1/discover/searches', ['type' => 'search', 'q' => 'shadow'])->json('search.id');
        $this->assertNotSame($id, $other);
    }

    public function testAnUnreachableSourceEndsFailedWithAPlainMessage(): void
    {
        $this->upstream(failing: true);

        foreach ([['type' => 'popular'], ['type' => 'search', 'source' => 'novelfull', 'q' => 'shadow']] as $body) {
            $id = $this->postJson('/api/v1/discover/searches', $body)->assertStatus(202)->json('search.id');

            $this->getJson("/api/v1/discover/searches/{$id}")
                ->assertOk()
                ->assertExactJson(['search' => [
                    'id' => $id,
                    'status' => 'failed',
                    'message' => "Couldn't load this list from the source. Try again later.",
                    'result' => null,
                ]]);
        }
    }

    public function testAJobExceptionEndsFailedNotRunning(): void
    {
        Queue::fake();
        $this->upstream();
        $id = $this->postJson('/api/v1/discover/searches', ['type' => 'popular'])->json('search.id');

        $throwing = new class extends Discover {
            public function sources(): array
            {
                throw new \RuntimeException('boom');
            }
        };
        (new RunDiscoverSearch($id))->handle(app(DiscoverSearch::class), $throwing);

        $search = $this->search($id);
        $this->assertSame('failed', $search['status']);
        $this->assertSame(DiscoverSearch::JOB_FAILED, $search['message']);
        $this->assertNull($search['result']);
    }

    public function testAJobTheQueueGivesUpOnEndsFailed(): void
    {
        Queue::fake();
        $id = $this->postJson('/api/v1/discover/searches', ['type' => 'popular'])->json('search.id');

        (new RunDiscoverSearch($id))->failed(new \RuntimeException('timed out'));

        $this->assertSame('failed', $this->search($id)['status']);
    }

    public function testRunningForFiveMinutesIsReportedAsFailed(): void
    {
        Queue::fake();
        $this->upstream();
        $id = $this->postJson('/api/v1/discover/searches', ['type' => 'popular'])->json('search.id');

        $this->travel(4)->minutes();
        $this->assertSame('running', $this->search($id)['status']);

        $this->travel(1)->minutes();
        $this->getJson("/api/v1/discover/searches/{$id}")
            ->assertOk()
            ->assertExactJson(['search' => ['id' => $id, 'status' => 'failed', 'message' => 'The source took too long.', 'result' => null]]);

        // A job that runs after that does not turn it back into done.
        (new RunDiscoverSearch($id))->handle(app(DiscoverSearch::class), app(Discover::class));
        $this->assertSame('failed', $this->search($id)['status']);
        $this->assertSame([], $this->fetcher->urls());
    }

    public function testUnknownAndExpiredSearchesAre404(): void
    {
        $this->upstream();

        $this->getJson('/api/v1/discover/searches/' . str_repeat('a', 32))->assertNotFound();
        $this->getJson('/api/v1/discover/searches/not-an-id')->assertNotFound();
        // Without an Accept header too (a plain HTTP client): JSON.
        $this->get('/api/v1/discover/searches/' . str_repeat('b', 32))->assertNotFound()->assertJsonStructure(['message']);

        $id = $this->postJson('/api/v1/discover/searches', ['type' => 'popular'])->json('search.id');
        $this->travel(14)->minutes();
        $this->search($id);
        $this->travel(2)->minutes();
        $this->getJson("/api/v1/discover/searches/{$id}")->assertNotFound();
    }

    public function testValidationIsTheBrowseEndpoints(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/discover/searches', [])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->postJson('/api/v1/discover/searches', ['type' => 'bogus'])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->postJson('/api/v1/discover/searches', ['type' => 'search'])->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->postJson('/api/v1/discover/searches', ['type' => 'search', 'q' => str_repeat('x', 101)])->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->postJson('/api/v1/discover/searches', ['type' => 'popular', 'source' => 'nope'])->assertStatus(422)->assertJsonValidationErrors(['source']);
        $this->postJson('/api/v1/discover/searches', ['type' => 'popular', 'page' => 0])->assertStatus(422)->assertJsonValidationErrors(['page']);
        $this->postJson('/api/v1/discover/searches', ['type' => 'popular', 'per_page' => 51])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->post('/api/v1/discover/searches', ['type' => 'bogus'])->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['type']]);

        Queue::assertNothingPushed();
    }
}
