<?php

namespace Tests\Feature;

use App\Bookmark;
use App\Http\Controllers\Api\V1\Presenter;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NovelController;
use App\Http\Helpers\CacheHelper;
use App\Novel;
use App\NovelChapter;
use App\ReadingPosition;
use App\Services\ReadingPositions;
use App\Services\ReadState;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Multi-user stage 1: read state, positions, highlights and everything
 * derived from them (continue reading, unread counts, stats, caches) belong
 * to one reader. There is no sign-in yet, so the tests switch the current
 * reader (App\Support\CurrentReader) themselves.
 */
class MultiUserReadStateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $reader;

    private Novel $novel;

    /** @var NovelChapter[] chapters 1..5 of $novel, all downloaded */
    private array $chapters = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->owner();
        $this->reader = User::factory()->create(['name' => 'Second Reader']);

        $this->novel = Novel::create(['name' => 'Shared Novel', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 5]);
        foreach (range(1, 5) as $n) {
            $chapter = NovelChapter::create([
                'novel_id' => $this->novel->id, 'chapter' => $n, 'book' => 0,
                'label' => "Chapter {$n}", 'url' => "https://example.test/shared/{$n}",
            ]);
            $chapter->description = "<p>Words of chapter {$n}.</p>";
            $chapter->forceFill(['status' => 1, 'download_date' => now()->subDays(10)])->save();
            $this->chapters[$n] = $chapter;
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asOwner(): void
    {
        $this->actAsReader($this->owner);
    }

    private function asReader(): void
    {
        $this->actAsReader($this->reader);
    }

    /** The novel's chapter list from the API, as [chapter number => [read, read_progress]]. */
    private function apiChapters(): array
    {
        return collect($this->getJson("/api/v1/novels/{$this->novel->id}/chapters")->assertOk()->json('chapters'))
            ->mapWithKeys(fn($c) => [$c['chapter'] => [$c['read'], $c['read_progress']]])
            ->all();
    }

    // --- (b) independent state ---------------------------------------------

    public function test_two_readers_have_independent_read_state_progress_and_positions(): void
    {
        // The owner reads chapters 1-2 and is 40 % into chapter 3 on a Kindle.
        $this->asOwner();
        $this->post(route('chapters.toggle_read', $this->chapters[1]->id))->assertOk()->assertJson(['read' => true]);
        $this->get(route('chapters.show', $this->chapters[2]->id))->assertOk();
        $this->postJson("/api/v1/chapters/{$this->chapters[3]->id}/progress", ['progress' => 40], ['X-Novarr-Device' => 'kindle_0001'])->assertOk();

        // The second reader opens chapter 4 only and is 70 % into chapter 1.
        $this->asReader();
        $this->get(route('chapters.show', $this->chapters[4]->id))->assertOk()
            ->assertViewHas('readerState', fn($s) => $s['read'] === true && $s['latest_position'] === null);
        $this->postJson("/api/v1/chapters/{$this->chapters[1]->id}/progress", ['progress' => 70], ['X-Novarr-Device' => 'iphone_0001'])
            ->assertOk()->assertJson(['read' => false]);

        $this->assertSame([1 => [false, 70], 2 => [false, null], 3 => [false, null], 4 => [true, null], 5 => [false, null]], $this->apiChapters());
        $this->assertNull($this->readAtOf($this->chapters[2], $this->reader));

        $this->asOwner();
        $this->assertSame([1 => [true, 100], 2 => [true, null], 3 => [false, 40], 4 => [false, null], 5 => [false, null]], $this->apiChapters());
        $this->getJson("/api/v1/chapters/{$this->chapters[1]->id}")->assertOk()->assertJsonPath('chapter.read', true)->assertJsonPath('chapter.read_progress', 100);

        // Latest position: each reader's own device.
        $positions = app(ReadingPositions::class);
        $this->assertSame('kindle_0001', $positions->latest($this->novel->id, $this->owner->id)['device_id']);
        $this->assertSame($this->chapters[3]->id, $positions->latest($this->novel->id, $this->owner->id)['chapter_id']);
        $this->assertSame('iphone_0001', $positions->latest($this->novel->id, $this->reader->id)['device_id']);
        $this->getJson("/api/v1/novels/{$this->novel->id}/position")->assertOk()->assertJsonPath('position.device_id', 'kindle_0001');
        $this->asReader();
        $this->getJson("/api/v1/novels/{$this->novel->id}/position")->assertOk()->assertJsonPath('position.device_id', 'iphone_0001');
        $this->assertSame(2, ReadingPosition::where('novel_id', $this->novel->id)->count());
    }

    public function test_a_stale_progress_check_compares_only_the_readers_own_position(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00');
        $this->asOwner();
        $this->postJson("/api/v1/chapters/{$this->chapters[2]->id}/progress", ['progress' => 80])->assertOk();

        // The second reader's offline write from an hour ago is not "older than"
        // the owner's: it is their first position in the chapter.
        $this->asReader();
        $this->postJson("/api/v1/chapters/{$this->chapters[2]->id}/progress", ['progress' => 20, 'age' => 3_600_000])->assertOk();

        $this->assertSame(80, $this->progressOf($this->chapters[2], $this->owner));
        $this->assertSame(20, $this->progressOf($this->chapters[2], $this->reader));
    }

    public function test_bulk_marks_touch_only_the_current_reader(): void
    {
        // Frozen: the test compares two now() readings a few statements apart.
        Carbon::setTestNow('2026-11-19 12:00:00');
        $this->markRead($this->chapters[1], $this->owner, now()->subDays(5));

        $this->asReader();
        $this->postJson('/api/v1/chapters/bulk-read', ['read' => true, 'scope' => 'all', 'novel_id' => $this->novel->id])
            ->assertOk()->assertJson(['count' => 5]);
        $this->postJson(route('novels.bulk'), ['action' => 'read', 'ids' => [$this->novel->id]])->assertOk()->assertJson(['chapters' => 0]);
        $this->postJson(route('chapters.read_through', $this->chapters[5]->id))->assertOk()->assertJson(['marked' => 0]);
        $this->assertSame(5, $this->readCount($this->novel->id, $this->reader));
        $this->assertSame(1, $this->readCount($this->novel->id, $this->owner));
        $this->assertTrue(now()->subDays(5)->startOfSecond()->equalTo($this->readAtOf($this->chapters[1], $this->owner)));

        $this->asOwner();
        $this->postJson('/api/v1/chapters/bulk-read', ['read' => false, 'scope' => 'all', 'novel_id' => $this->novel->id])->assertOk();
        $this->assertSame(0, $this->readCount($this->novel->id, $this->owner));
        $this->assertSame(5, $this->readCount($this->novel->id, $this->reader));
    }

    public function test_highlights_are_per_reader(): void
    {
        $this->asOwner();
        $this->postJson('/api/v1/highlights', ['chapter_id' => $this->chapters[1]->id, 'excerpt' => 'Same words'])->assertCreated();
        $this->post(route('bookmarks.store'), ['chapter_id' => $this->chapters[2]->id, 'excerpt' => 'Owner only'])->assertOk();

        $this->asReader();
        // The same excerpt is a new highlight for another reader.
        $theirs = $this->postJson('/api/v1/highlights', ['chapter_id' => $this->chapters[1]->id, 'excerpt' => 'Same words'])
            ->assertCreated()->json('highlight.id');
        $this->assertSame(['Same words'], array_column($this->getJson('/api/v1/highlights')->assertOk()->json('highlights'), 'excerpt'));
        $this->get(route('bookmarks.index'))->assertOk()->assertDontSee('Owner only');

        // Another reader's highlight can't be deleted from here.
        $ownerOnly = Bookmark::where('excerpt', 'Owner only')->value('id');
        $this->deleteJson("/api/v1/highlights/{$ownerOnly}")->assertNoContent();
        $this->delete(route('bookmarks.destroy', $ownerOnly))->assertNotFound();
        $this->assertNotNull(Bookmark::find($ownerOnly));

        $this->asOwner();
        $this->assertSame(['Owner only', 'Same words'], collect($this->getJson('/api/v1/highlights')->json('highlights'))->pluck('excerpt')->sort()->values()->all());
        $this->deleteJson("/api/v1/highlights/{$theirs}")->assertNoContent();
        $this->assertNotNull(Bookmark::find($theirs));
    }

    public function test_continue_reading_unread_counts_resume_and_stats_are_per_reader(): void
    {
        // Owner: read 1-3, 50 % into 2 earlier → resumes 4. Reader: read 1 only.
        $this->markRead($this->chapters[1], $this->owner, now()->subHours(3), 100);
        $this->markRead($this->chapters[2], $this->owner, now()->subHours(2), 100);
        $this->markRead($this->chapters[3], $this->owner, now()->subHour(), 100);
        $this->markRead($this->chapters[1], $this->reader, now()->subDays(2), 30);

        $home = app(HomeController::class);
        $this->assertSame($this->chapters[4]->id, $home->continueReading(8, $this->owner->id)[0]['next']->id);
        $resume = $home->continueReading(8, $this->reader->id)[0];
        $this->assertSame([$this->chapters[1]->id, true, 30], [$resume['next']->id, $resume['resume'], $resume['next']->readProgress()]);

        $this->assertSame(3, NovelController::libraryReading($this->owner->id)['reading'][$this->novel->id]['read']);
        $this->assertSame(1, NovelController::libraryReading($this->reader->id)['reading'][$this->novel->id]['read']);
        $this->assertNotSame(
            NovelController::libraryAggregates($this->owner->id)[$this->novel->id]['last_read'],
            NovelController::libraryAggregates($this->reader->id)[$this->novel->id]['last_read']
        );
        $this->assertSame($this->chapters[4]->id, Presenter::resumeChapterIds($this->owner->id)[$this->novel->id]);
        $this->assertSame($this->chapters[1]->id, Presenter::resumeChapterIds($this->reader->id)[$this->novel->id]);

        // The same pages, as each reader.
        $this->asOwner();
        $this->get('/continue')->assertRedirect(route('chapters.show', $this->chapters[4]->id));
        $this->getJson('/api/v1/novels')->assertOk()->assertJsonPath('novels.0.chapters_read', 3);
        $this->get(route('stats.index'))->assertOk()->assertViewHas('s', fn($s) => $s['headline']['chapters_read'] === 3);
        $this->get(route('novels.show', $this->novel->id))->assertOk()->assertViewHas('read_count', 3);
        $this->get(route('home'))->assertOk()->assertViewHas('hero', fn($h) => $h['chapter']->id === $this->chapters[4]->id);

        $this->asReader();
        $this->get('/continue')->assertRedirect(route('chapters.show', $this->chapters[1]->id));
        $this->getJson('/api/v1/novels')->assertOk()->assertJsonPath('novels.0.chapters_read', 1);
        $this->get(route('stats.index'))->assertOk()->assertViewHas('s', fn($s) => $s['headline']['chapters_read'] === 1 && $s['headline']['chapters_today'] === 0);
        $this->get(route('novels.show', $this->novel->id))->assertOk()->assertViewHas('read_count', 1);
        $this->get(route('home'))->assertOk()->assertViewHas('hero', fn($h) => $h['chapter']->id === $this->chapters[1]->id && $h['chapter_percent'] === 30);
    }

    public function test_deleting_a_reader_removes_only_their_state(): void
    {
        $this->markRead($this->chapters[1], $this->owner);
        $this->markRead($this->chapters[1], $this->reader);
        Bookmark::create(['user_id' => $this->reader->id, 'novel_id' => $this->novel->id, 'novel_chapter_id' => $this->chapters[1]->id, 'excerpt' => 'x']);
        app(ReadingPositions::class)->record($this->chapters[1], 10, now(), ['id' => null, 'name' => null], $this->reader->id);

        $this->reader->delete();

        $this->assertSame(0, DB::table('chapter_reads')->where('user_id', $this->reader->id)->count());
        $this->assertSame(0, Bookmark::where('user_id', $this->reader->id)->count());
        $this->assertSame(0, ReadingPosition::where('user_id', $this->reader->id)->count());
        $this->assertSame(1, $this->readCount($this->novel->id, $this->owner));
    }

    public function test_hard_deleting_a_chapter_or_novel_removes_every_readers_rows(): void
    {
        $this->markRead($this->chapters[1], $this->owner);
        $this->markRead($this->chapters[1], $this->reader);
        $this->markRead($this->chapters[2], $this->reader);

        $this->chapters[1]->delete(); // soft: rows stay
        $this->assertSame(3, DB::table('chapter_reads')->count());
        $this->chapters[1]->forceDelete();
        $this->assertSame(1, DB::table('chapter_reads')->count());

        $this->novel->forceDelete();
        $this->assertSame(0, DB::table('chapter_reads')->count());
    }

    // --- (c) cache invalidation ---------------------------------------------

    /** Every per-reader cache key a reader's pages fill. */
    private function readerKeys(User $user): array
    {
        return [
            CacheHelper::libraryReadingKey($user->id),
            CacheHelper::dashboardContinueKey($user->id),
            CacheHelper::apiResumeKey($user->id),
            CacheHelper::novelStatsKey($this->novel->id, $user->id),
        ];
    }

    private function warm(User $user): void
    {
        $this->actAsReader($user);
        $this->get(route('novels.index'))->assertOk();
        $this->get(route('home'))->assertOk();
        $this->getJson('/api/v1/novels')->assertOk();
        $this->get(route('novels.show', $this->novel->id))->assertOk();
    }

    /** Is $key cached and still valid (computed under the current epoch)? */
    private function fresh(string $key, ?string $epoch = null): bool
    {
        $hit = Cache::get($key);

        return is_array($hit) && ($hit['epoch'] ?? null) === ($epoch ?? CacheHelper::epoch());
    }

    public function test_a_read_invalidates_only_the_readers_caches(): void
    {
        $this->markRead($this->chapters[1], $this->owner);
        $this->warm($this->owner);
        $this->warm($this->reader);
        foreach ([...$this->readerKeys($this->owner), ...$this->readerKeys($this->reader)] as $key) {
            $this->assertTrue(Cache::has($key), $key);
        }
        $ownerLibrary = Cache::get(CacheHelper::libraryReadingKey($this->owner->id));

        // The second reader opens chapter 2.
        $this->asReader();
        $this->get(route('chapters.show', $this->chapters[2]->id))->assertOk();

        foreach ($this->readerKeys($this->reader) as $key) {
            $this->assertFalse(Cache::has($key), "{$key} should be forgotten");
        }
        $this->assertTrue($this->fresh(CacheHelper::libraryReadingKey($this->owner->id)));
        $this->assertTrue($this->fresh(CacheHelper::dashboardContinueKey($this->owner->id)));
        $this->assertTrue($this->fresh(CacheHelper::apiResumeKey($this->owner->id)));
        $this->assertTrue($this->fresh(CacheHelper::novelStatsKey($this->novel->id, $this->owner->id), CacheHelper::novelEpoch($this->novel->id)));
        $this->assertSame($ownerLibrary, Cache::get(CacheHelper::libraryReadingKey($this->owner->id)));
        $this->assertTrue(Cache::has(CacheHelper::LIBRARY_AGGREGATES_KEY), 'the shared chapter counts stay');

        // Each sees their own count straight away.
        $this->assertSame(1, NovelController::libraryReading($this->reader->id)['reading'][$this->novel->id]['read']);
        $this->assertSame(1, NovelController::libraryReading($this->owner->id)['reading'][$this->novel->id]['read']);
        $this->assertTrue(NovelChapter::find($this->chapters[2]->id) !== null && $this->readAtOf($this->chapters[2], $this->owner) === null);
    }

    public function test_a_download_invalidates_every_readers_caches(): void
    {
        $queued = NovelChapter::create([
            'novel_id' => $this->novel->id, 'chapter' => 6, 'book' => 0,
            'label' => 'Chapter 6', 'url' => 'https://example.test/shared/6',
        ]);
        $this->markRead($this->chapters[5], $this->owner, now()->subDay());
        $this->markRead($this->chapters[5], $this->reader, now()->subDay());
        $this->warm($this->owner);
        $this->warm($this->reader);
        $before = CacheHelper::epoch();
        $novelBefore = CacheHelper::novelEpoch($this->novel->id);

        // A download lands (as ChapterScraper does: save, then clearNovelCache()).
        $queued->forceFill(['status' => 1, 'download_date' => now()])->save();
        foreach ([$this->owner, $this->reader] as $user) {
            $this->assertSame(0, NovelController::libraryReading($user->id)['reading'][$this->novel->id]['new'], 'still cached');
        }
        CacheHelper::clearNovelCache($this->novel->id);

        $this->assertNotSame($before, CacheHelper::epoch());
        $this->assertNotSame($novelBefore, CacheHelper::novelEpoch($this->novel->id));
        $this->assertFalse(Cache::has(CacheHelper::LIBRARY_AGGREGATES_KEY));
        foreach ([$this->owner, $this->reader] as $user) {
            foreach ($this->readerKeys($user) as $key) {
                $epoch = str_starts_with($key, 'novel_stats_') ? CacheHelper::novelEpoch($this->novel->id) : CacheHelper::epoch();
                $this->assertFalse($this->fresh($key, $epoch), "{$key} should be stale");
            }
            // Recomputed on the next read: chapter 6 is new for both.
            $this->assertSame(1, NovelController::libraryReading($user->id)['reading'][$this->novel->id]['new']);
            $this->assertSame($queued->id, Presenter::resumeChapterIds($user->id)[$this->novel->id]);
        }
    }

    public function test_read_state_service_resolves_the_reader_per_call(): void
    {
        // A ReadState held across "requests" (as Octane keeps controllers)
        // follows the current reader rather than the one it was built with.
        $service = app(ReadState::class);
        $this->asOwner();
        $this->assertSame($this->owner->id, $service->userId());
        $this->asReader();
        $this->assertSame($this->reader->id, $service->userId());
        $service->markOpened($this->chapters[3]);
        $this->assertNotNull($this->readAtOf($this->chapters[3], $this->reader));
        $this->assertNull($this->readAtOf($this->chapters[3], $this->owner));
    }
}
