<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use App\Scraping\FailureSnapshot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Failure snapshots (A2): storage, retention and the per-novel snapshot
 * page / download route.
 */
class GoldenSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/novarr-snapshots-' . getmypid() . '-' . bin2hex(random_bytes(4));
        config([
            'novarr.snapshots.path' => $this->dir,
            'novarr.snapshots.enabled' => true,
            'novarr.snapshots.keep_per_novel' => 5,
            'novarr.snapshots.days' => 14,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function novelWithChapter(): array
    {
        $novel = Novel::create(['name' => 'Snapshot Test', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 1]);
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id, 'chapter' => 3, 'book' => 0,
            'label' => 'Chapter 3 The Broken Page', 'url' => 'https://example.test/novel/chapter-3',
        ]);

        return [$novel, $chapter];
    }

    public function testStoreWritesAGzippedFileWithTheDocumentedName(): void
    {
        [$novel, $chapter] = $this->novelWithChapter();
        Carbon::setTestNow('2026-10-03 08:15:30');

        $path = FailureSnapshot::store($novel, $chapter, '<html><body>broken</body></html>', 'short_content');

        $this->assertSame("{$this->dir}/{$novel->id}/{$chapter->id}-short_content-20261003_081530.html.gz", $path);
        $this->assertSame('<html><body>broken</body></html>', gzdecode(file_get_contents($path)));
        $this->assertTrue(FailureSnapshot::validName(basename($path)));
    }

    public function testNothingIsStoredWhenDisabledOrEmpty(): void
    {
        [$novel, $chapter] = $this->novelWithChapter();

        $this->assertNull(FailureSnapshot::store($novel, $chapter, "  \n", 'no_content'));
        config(['novarr.snapshots.enabled' => false]);
        $this->assertNull(FailureSnapshot::store($novel, $chapter, '<p>x</p>', 'no_content'));
        $this->assertSame([], FailureSnapshot::files($novel->id));
    }

    public function testRetentionKeepsTheNewestFivePerNovelAndDropsOldFiles(): void
    {
        [$novel, $chapter] = $this->novelWithChapter();

        for ($i = 0; $i < 7; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-10-03 08:00:00')->addMinutes($i));
            FailureSnapshot::store($novel, $chapter, "<p>page {$i}</p>", 'no_content');
        }
        FailureSnapshot::prune($novel->id);

        $files = FailureSnapshot::files($novel->id);
        $this->assertCount(5, $files);
        $this->assertStringContainsString('080600', $files[0]['file'], 'newest first');
        $this->assertStringContainsString('080200', end($files)['file']);

        // Age out: everything older than 14 days goes.
        Carbon::setTestNow('2026-10-20 08:00:00');
        FailureSnapshot::prune($novel->id);
        $this->assertSame([], FailureSnapshot::files($novel->id));
    }

    public function testChapterGeneratorSnapshotsAShortPage(): void
    {
        [$novel, $chapter] = $this->novelWithChapter();
        config(['novarr.chapter_fetch_delay_ms' => [0, 0]]);
        $fake = new \Tests\Support\FakeFetcher([
            'https://example.test/novel/chapter-3' => '<html><body><div id="chapter-content"><p>Only a teaser of the chapter is here.</p></div></body></html>',
        ]);
        app()->instance(\App\Scraping\Fetcher::class, $fake);

        $reason = null;
        $paragraphs = chapterGenerator($chapter->fresh(), $reason);

        $this->assertCount(1, $paragraphs);
        $this->assertNull($reason, 'short content is left to the caller\'s word gate');
        $files = FailureSnapshot::files($novel->id);
        $this->assertCount(1, $files);
        $this->assertSame('short_content', $files[0]['reason']);
        $this->assertSame($chapter->id, $files[0]['chapter_id']);
        $this->assertSame([], $fake->unexpected);
    }

    public function testIndexListsSnapshotsWithChapterLabels(): void
    {
        [$novel, $chapter] = $this->novelWithChapter();
        Carbon::setTestNow('2026-10-03 08:15:30');
        FailureSnapshot::store($novel, $chapter, '<p>a</p>', 'no_content');
        FailureSnapshot::store($novel, null, '<p>toc</p>', 'empty_toc');

        $this->get(route('novels.snapshots', $novel->id))
            ->assertOk()
            ->assertSee('Failure snapshots')
            ->assertSee('Chapter 3 The Broken Page')
            ->assertSee('Table of contents')
            ->assertSee('no content')
            ->assertSee('empty toc')
            ->assertSee("{$chapter->id}-no_content-20261003_081530.html.gz", false);
    }

    public function testIndexWithNoSnapshots(): void
    {
        [$novel] = $this->novelWithChapter();
        $this->get(route('novels.snapshots', $novel->id))->assertOk()->assertSee('No failure snapshots');
        $this->get('/novels/999999/snapshots')->assertNotFound();
    }

    public function testDownloadServesTheGunzippedHtmlAsPlainText(): void
    {
        [$novel, $chapter] = $this->novelWithChapter();
        $path = FailureSnapshot::store($novel, $chapter, '<html><script>alert(1)</script>body</html>', 'no_content');

        $response = $this->get(route('novels.snapshots', [$novel->id, basename($path)]));
        $response->assertOk();
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame('<html><script>alert(1)</script>body</html>', $response->getContent());
    }

    public function testDownloadRejectsBadNamesAndOtherNovelsFiles(): void
    {
        [$novel, $chapter] = $this->novelWithChapter();
        $other = Novel::create(['name' => 'Other', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 0]);
        $path = FailureSnapshot::store($other, null, '<p>secret</p>', 'empty_toc');
        $name = basename($path);

        // Another novel's snapshot is not reachable through this novel.
        $this->get("/novels/{$novel->id}/snapshots/{$name}")->assertNotFound();
        $this->get("/novels/{$other->id}/snapshots/{$name}")->assertOk();

        foreach ([
            '..%2F..%2F.env',
            '..%2F' . $other->id . '%2F' . $name,
            '1-no_content-20261003_081530.html',
            'x-no_content-20261003_081530.html.gz',
            '1-No-Content-20261003_081530.html.gz',
        ] as $bad) {
            $this->get("/novels/{$novel->id}/snapshots/{$bad}")->assertNotFound();
        }

        $this->assertFalse(FailureSnapshot::validName('../1-no_content-1_1.html.gz'));
        $this->assertNull(FailureSnapshot::read($novel->id, '../' . $other->id . '/' . $name));
    }
}
