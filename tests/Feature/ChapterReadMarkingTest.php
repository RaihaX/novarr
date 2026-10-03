<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Opening a chapter marks it read — background fetches (prefetch, offline
 * download, continuous reading) must not.
 */
class ChapterReadMarkingTest extends TestCase
{
    use RefreshDatabase;

    private function downloadedChapter(): NovelChapter
    {
        $novel = Novel::create([
            'name' => 'Read Marking Test',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 2,
        ]);

        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => 1,
            'book' => 0,
            'label' => 'Chapter 1',
            'url' => 'https://example.test/novel/chapter-1',
        ]);
        $chapter->description = '<p>Once upon a time.</p>';
        $chapter->status = 1;
        $chapter->save();

        return $chapter->fresh();
    }

    public function test_plain_get_marks_the_chapter_read(): void
    {
        $chapter = $this->downloadedChapter();

        $this->get(route('chapters.show', $chapter->id))->assertOk();

        $this->assertNotNull($chapter->fresh()->read_at);
    }

    public function test_turbo_prefetch_does_not_mark_the_chapter_read(): void
    {
        $chapter = $this->downloadedChapter();

        $this->get(route('chapters.show', $chapter->id), ['X-Sec-Purpose' => 'prefetch'])
            ->assertOk()
            ->assertSee('"deferRead":true', false);

        $this->assertNull($chapter->fresh()->read_at);
    }

    #[DataProvider('backgroundRequests')]
    public function test_other_background_fetches_do_not_mark_the_chapter_read(array $headers, string $query): void
    {
        $chapter = $this->downloadedChapter();

        $this->get(route('chapters.show', $chapter->id) . $query, $headers)->assertOk();

        $this->assertNull($chapter->fresh()->read_at);
    }

    public static function backgroundRequests(): array
    {
        return [
            'Sec-Purpose' => [['Sec-Purpose' => 'prefetch;prerender'], ''],
            'Purpose' => [['Purpose' => 'prefetch'], ''],
            'offline download' => [['X-Novarr-Fetch' => 'offline'], ''],
            'continuous reading' => [['X-Novarr-Fetch' => 'continuous'], ''],
            'query flag' => [[], '?prefetch=1'],
        ];
    }

    public function test_progress_endpoint_marks_read_on_view(): void
    {
        $chapter = $this->downloadedChapter();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->postJson(route('chapters.progress', $chapter->id), ['read' => true])
            ->assertOk()
            ->assertJson(['success' => true, 'read' => true]);

        $chapter = $chapter->fresh();
        $this->assertNotNull($chapter->read_at);
        $this->assertNull($chapter->read_progress);
    }

    public function test_progress_endpoint_still_requires_progress_without_read(): void
    {
        $chapter = $this->downloadedChapter();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->postJson(route('chapters.progress', $chapter->id), [])
            ->assertStatus(422);

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->postJson(route('chapters.progress', $chapter->id), ['progress' => 40])
            ->assertOk();

        $chapter = $chapter->fresh();
        $this->assertSame(40, (int) $chapter->read_progress);
        $this->assertNull($chapter->read_at);
    }
}
