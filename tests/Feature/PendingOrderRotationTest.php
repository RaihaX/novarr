<?php

namespace Tests\Feature;

use App\Console\Commands\ChapterScraper;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review finding H3: with a per-run cap, dead or stub chapters at the front
 * of a novel used to fill the cap on every run. recordAttempt() stamps each
 * try and the sweep orders never-tried chapters first, then oldest attempt.
 */
class PendingOrderRotationTest extends TestCase
{
    use RefreshDatabase;

    public function testRecordAttemptStampsWithoutTouchingContent(): void
    {
        $novel = Novel::create(['name' => 'Rotation', 'translator_url' => 'https://novelfull.com/rotation.html']);
        $chapter = NovelChapter::create(['novel_id' => $novel->id, 'label' => 'Chapter 1', 'url' => 'https://novelfull.com/rotation/chapter-1.html', 'chapter' => 1, 'status' => 0]);

        $this->assertNull($chapter->fresh()->last_attempt_at);
        ChapterScraper::recordAttempt($chapter);

        $fresh = $chapter->fresh();
        $this->assertNotNull($fresh->last_attempt_at);
        $this->assertSame(0, (int) $fresh->status);
        $this->assertNull($fresh->text);
    }

    public function testNeverTriedChaptersSortBeforeTriedOnes(): void
    {
        $novel = Novel::create(['name' => 'Rotation', 'translator_url' => 'https://novelfull.com/rotation.html']);
        foreach ([1, 2, 3, 4] as $n) {
            NovelChapter::create(['novel_id' => $novel->id, 'label' => "Chapter {$n}", 'url' => "https://novelfull.com/rotation/chapter-{$n}.html", 'chapter' => $n, 'status' => 0]);
        }
        // Chapters 1 and 2 were tried (1 most recently); 3 and 4 never.
        NovelChapter::where('chapter', 1)->update(['last_attempt_at' => now()]);
        NovelChapter::where('chapter', 2)->update(['last_attempt_at' => now()->subHour()]);

        $order = $novel->chapters()
            ->where('status', 0)
            ->orderByRaw('last_attempt_at IS NOT NULL')
            ->orderBy('last_attempt_at')
            ->orderBy('book')
            ->orderBy('chapter')
            ->pluck('chapter')
            ->map(fn($c) => (int) $c)
            ->all();

        $this->assertSame([3, 4, 2, 1], $order);
    }
}
