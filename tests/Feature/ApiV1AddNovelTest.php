<?php

namespace Tests\Feature;

use App\Jobs\RunNovelCommand;
use App\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * POST /api/v1/novels: add a Discover result (title + url) to the library.
 */
class ApiV1AddNovelTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://novelping.com/novel/shadow-slave';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Bus::fake();
    }

    public function testCreatesTheNovelAndQueuesItsMetadata(): void
    {
        $response = $this->postJson('/api/v1/novels', ['title' => 'Shadow Slave', 'url' => self::URL])
            ->assertCreated()
            ->assertJsonPath('already_in_library', false)
            ->assertJsonPath('novel.name', 'Shadow Slave');

        $novel = Novel::sole();
        $this->assertSame($novel->id, $response->json('novel.id'));
        // Saved as novel:create saves it.
        $this->assertSame(self::URL, $novel->translator_url);
        $this->assertSame(1, (int) $novel->group_id);
        $this->assertSame(0, (int) $novel->status);

        // The Presenter summary, as GET /api/v1/novels lists it.
        $this->assertSame(
            array_keys($this->getJson('/api/v1/novels')->json('novels.0')),
            array_keys($response->json('novel'))
        );

        Bus::assertDispatched(RunNovelCommand::class, fn(RunNovelCommand $job) => $job->artisanCommand === 'novel:metadata'
            && $job->params === ['novel' => $novel->id]
            && $job->queue === 'commands');
        Bus::assertDispatchedTimes(RunNovelCommand::class, 1);
    }

    public function testAddingTwiceAnswersTheExistingNovel(): void
    {
        $first = $this->postJson('/api/v1/novels', ['title' => 'Shadow Slave', 'url' => self::URL])->assertCreated();

        $this->postJson('/api/v1/novels', ['title' => 'Shadow Slave', 'url' => self::URL])
            ->assertOk()
            ->assertJsonPath('already_in_library', true)
            ->assertJsonPath('novel.id', $first->json('novel.id'));

        $this->assertSame(1, Novel::count());
        Bus::assertDispatchedTimes(RunNovelCommand::class, 1);
    }

    public function testMatchesTheLibraryLikeDiscoverDoes(): void
    {
        // Same novel on the old host with a trailing underscore (slug match).
        $bySlug = Novel::create(['name' => 'Shadow Slave (WN)', 'group_id' => 1, 'translator_url' => 'https://novelarrow.com/novel/shadow-slave_']);
        // Same name, differently punctuated.
        $byName = Novel::create(['name' => 'Lord Of The Mysteries!', 'group_id' => 1, 'translator_url' => 'https://example.test/lotm']);

        $this->postJson('/api/v1/novels', ['title' => 'Shadow Slave', 'url' => self::URL])
            ->assertOk()->assertJsonPath('novel.id', $bySlug->id)->assertJsonPath('already_in_library', true);
        // The same title from another source is a likely duplicate: refused
        // (409) naming the library novel, unless forced (DuplicateSeriesTest).
        $this->postJson('/api/v1/novels', ['title' => 'Lord of the Mysteries', 'url' => 'https://novelping.com/novel/lord-of-the-mysteries'])
            ->assertStatus(409)->assertJsonPath('duplicate.id', $byName->id);

        $this->assertSame(2, Novel::count());
        Bus::assertNotDispatched(RunNovelCommand::class);
    }

    public function testValidation(): void
    {
        $this->postJson('/api/v1/novels', [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['title', 'url']]);
        $this->postJson('/api/v1/novels', ['title' => 'X', 'url' => 'not a url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
        $this->postJson('/api/v1/novels', ['title' => str_repeat('x', 256), 'url' => self::URL])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
        // A plain form post without an Accept header still answers JSON.
        $this->post('/api/v1/novels', ['title' => 'X'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['url']]);

        $this->assertSame(0, Novel::count());
        Bus::assertNotDispatched(RunNovelCommand::class);
    }

    public function testTheGetListStillWorksBesideThePost(): void
    {
        $this->getJson('/api/v1/novels')->assertOk()->assertJsonPath('novels', []);
    }
}
