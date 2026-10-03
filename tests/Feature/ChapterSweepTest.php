<?php

namespace Tests\Feature;

use App\Console\Commands\ChapterScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * novel:chapter sweep (audit F18/F19). Pending rows use invalid URLs (or a
 * cooled-down host) so no network fetch ever happens.
 */
class ChapterSweepTest extends TestCase
{
    use RefreshDatabase;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->group = new Group();
        $this->group->label = 'Example';
        $this->group->url = 'https://novelfull.com';
        $this->group->save();
    }

    private function novel(string $name, int $badPending, ?string $lastDownload = null): Novel
    {
        $novel = Novel::create([
            'name' => $name,
            'status' => 0,
            'group_id' => $this->group->id,
            'no_of_chapters' => 100,
        ]);

        if ($lastDownload) {
            $done = NovelChapter::create([
                'novel_id' => $novel->id,
                'chapter' => 1,
                'label' => 'Chapter 1',
                'url' => 'https://novelfull.com/x/chapter-1',
            ]);
            $done->status = 1;
            $done->download_date = $lastDownload;
            $done->save();
        }

        for ($i = 0; $i < $badPending; $i++) {
            NovelChapter::create([
                'novel_id' => $novel->id,
                'chapter' => $i + 2,
                'label' => 'Arial',
                'url' => 'Arial, sans-serif',
            ]);
        }

        return $novel;
    }

    public function testEveryNovelIsProcessedOldestDownloadFirst()
    {
        $recent = $this->novel('Recent', 1, now()->subHour()->toDateTimeString());
        $old = $this->novel('Old', 1, now()->subMonth()->toDateTimeString());
        $never = $this->novel('Never', 1);

        Artisan::call('novel:chapter');
        $output = Artisan::output();

        $positions = array_map(fn($name) => strpos($output, "Processing: {$name} - "), ['Never', 'Old', 'Recent']);
        $this->assertNotContains(false, $positions, $output);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'never-downloaded first, then oldest download');

        foreach ([$recent, $old, $never] as $novel) {
            $this->assertSame(1, $novel->fresh()->scrape_failures);
        }
    }

    public function testSweepCapsChaptersPerNovel()
    {
        $novel = $this->novel('Backlog', 30);

        Artisan::call('novel:chapter');

        $this->assertStringStartsWith('25 pending chapter(s)', $novel->fresh()->last_scrape_issue);
    }

    public function testExplicitNovelRunIsNotCapped()
    {
        $novel = $this->novel('Backlog', 30);

        Artisan::call('novel:chapter', ['novel' => $novel->id]);

        $this->assertStringStartsWith('30 pending chapter(s)', $novel->fresh()->last_scrape_issue);
    }

    public function testNovelOnCooledDownHostIsSkipped()
    {
        $novel = Novel::create([
            'name' => 'Blocked',
            'status' => 0,
            'group_id' => $this->group->id,
            'no_of_chapters' => 10,
        ]);
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id,
            'chapter' => 1,
            'label' => 'Chapter 1',
            'url' => 'https://cooldown.invalid/novel/chapter-1',
        ]);
        Cache::put(ChapterScraper::hostCooldownKey('cooldown.invalid'), true, now()->addMinutes(15));

        Artisan::call('novel:chapter');

        $this->assertStringContainsString('cooling down', Artisan::output());
        $this->assertSame(0, $novel->fresh()->scrape_failures);
        $this->assertFalse($chapter->fresh()->status);
    }

    /** Junk pending rows back off too (audit A7): the next sweep skips them. */
    public function testBadUrlChaptersBackOffUntilDue()
    {
        $novel = $this->novel('Junk', 2);

        Artisan::call('novel:chapter');
        $this->assertSame(1, $novel->fresh()->scrape_failures);

        $chapter = NovelChapter::where('novel_id', $novel->id)->first();
        $this->assertSame(1, (int) $chapter->attempts);
        $this->assertSame('bad_url', $chapter->last_failure_reason);
        $this->assertTrue($chapter->next_attempt_at->isFuture());

        // Nothing is due: the novel isn't picked up, so its count stands.
        Artisan::call('novel:chapter');
        $this->assertStringNotContainsString('Processing: Junk', Artisan::output());
        $this->assertSame(1, $novel->fresh()->scrape_failures);
    }
}
