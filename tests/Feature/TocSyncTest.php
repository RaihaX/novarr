<?php

namespace Tests\Feature;

use App\Console\Commands\NovelScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use App\Services\ChapterNumberResolver;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * novel:toc row matching (audit F12/F13): URL is identity; the number
 * fallback may only adopt a row whose own page vanished from the TOC.
 */
class TocSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://example.test/novel/';

    private function novel(): Novel
    {
        $group = new Group();
        $group->label = 'Example';
        $group->url = 'https://example.test';
        $group->save();

        return Novel::create([
            'name' => 'Test Novel',
            'status' => 0,
            'group_id' => $group->id,
            'no_of_chapters' => 10,
        ]);
    }

    private function scraper(): NovelScraper
    {
        $command = new NovelScraper();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        return $command;
    }

    private function entry(float $chapter, string $slug, ?string $label = null): array
    {
        return [
            'chapter' => $chapter,
            'label' => $label ?? "Chapter {$chapter}",
            'url' => self::BASE . $slug,
        ];
    }

    /** @return array<int, array> chapters 1..$n */
    private function chapters(int $n): array
    {
        return array_map(fn($i) => $this->entry($i, "chapter-{$i}"), range(1, $n));
    }

    public function testFirstSyncCreatesRowsInTocOrder()
    {
        $novel = $this->novel();
        $counts = $this->scraper()->syncTableOfContents($novel, $this->chapters(5));

        $this->assertSame(5, $counts['created']);
        $this->assertSame(
            [1.0, 2.0, 3.0, 4.0, 5.0],
            NovelChapter::where('novel_id', $novel->id)->orderBy('id')->pluck('chapter')->all()
        );
        // A4: structured columns are written alongside the legacy number.
        $this->assertSame(
            [1.0, 2.0, 3.0, 4.0, 5.0],
            NovelChapter::where('novel_id', $novel->id)->orderBy('id')->pluck('sort_key')->all()
        );
        $row = NovelChapter::where('url', self::BASE . 'chapter-3')->sole();
        $this->assertSame(3.0, $row->number);
        $this->assertSame(0, $row->part);
        $this->assertSame('Chapter 3', $row->source_label);
        $this->assertNull($row->title);
    }

    /** A4: parts get number + part; the legacy chapter column is unchanged. */
    public function testPartsWriteStructuredColumns()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, [
            $this->entry(7.1, 'chapter-7-part-1', 'Chapter 7 (1): Dawn'),
            $this->entry(7.2, 'chapter-7-part-2', 'Chapter 7 (2): Dusk'),
        ]);

        $rows = NovelChapter::where('novel_id', $novel->id)->ordered()->get();
        $this->assertSame([7.1, 7.2], $rows->pluck('chapter')->all());
        $this->assertSame([7.0, 7.0], $rows->pluck('number')->all());
        $this->assertSame([1, 2], $rows->pluck('part')->all());
        $this->assertSame([7.001, 7.002], $rows->pluck('sort_key')->all());
        $this->assertSame(['Dawn', 'Dusk'], $rows->pluck('title')->all());
    }

    /** Re-running an unchanged TOC writes nothing. */
    public function testResyncIsIdempotent()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(5));
        $counts = $this->scraper()->syncTableOfContents($novel, $this->chapters(5));

        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 0, 'relocated_mass' => 0], $counts);
        $this->assertSame(5, NovelChapter::where('novel_id', $novel->id)->count());
    }

    /** Two TOC entries parsing to the same number both get a row, stably. */
    public function testEntriesSharingANumberDoNotFightOverOneRow()
    {
        $novel = $this->novel();
        $toc = [
            $this->entry(7, 'chapter-7-part-1', 'Chapter 7 (1)'),
            $this->entry(7, 'chapter-7-part-2', 'Chapter 7 (2)'),
        ];

        $scraper = $this->scraper();
        $scraper->syncTableOfContents($novel, $toc);
        $first = NovelChapter::where('novel_id', $novel->id)->orderBy('id')->pluck('url', 'id')->all();
        $scraper->syncTableOfContents($novel, $toc);
        $second = NovelChapter::where('novel_id', $novel->id)->orderBy('id')->pluck('url', 'id')->all();

        $this->assertCount(2, $first);
        $this->assertSame($first, $second);
    }

    /** A page that moved (old URL gone from TOC) is adopted by number; content refreshed. */
    public function testRelocatedDownloadedRowIsAdoptedAndRequeued()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(10));
        NovelChapter::where('novel_id', $novel->id)->update(['status' => 1]);

        $toc = $this->chapters(10);
        $toc[1] = $this->entry(2, 'chapter-2-new-slug');
        $counts = $this->scraper()->syncTableOfContents($novel, $toc);

        $this->assertSame(0, $counts['created']);
        $this->assertSame(0, $counts['relocated_mass']);
        $row = NovelChapter::where('novel_id', $novel->id)->where('chapter', 2)->sole();
        $this->assertSame(self::BASE . 'chapter-2-new-slug', $row->url);
        $this->assertFalse($row->status, 'stored content belongs to the old page');
        $this->assertSame(9, NovelChapter::where('novel_id', $novel->id)->where('status', 1)->count());
    }

    /** A row whose URL is still listed is never adopted by a different URL. */
    public function testLiveRowIsNotTakenOverByNumber()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(3));
        NovelChapter::where('novel_id', $novel->id)->update(['status' => 1]);

        $toc = $this->chapters(3);
        $toc[] = $this->entry(3, 'chapter-3-redux', 'Chapter 3 Redux');
        $counts = $this->scraper()->syncTableOfContents($novel, $toc);

        $this->assertSame(1, $counts['created']);
        $original = NovelChapter::where('url', self::BASE . 'chapter-3')->sole();
        $this->assertTrue($original->status);
        $this->assertSame('Chapter 3', $original->label);
    }

    /** Unparsed (0) numbers never match by number. */
    public function testZeroNumberNeverMatchesByNumber()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, [$this->entry(0, 'notice', 'Notice')]);
        $this->scraper()->syncTableOfContents($novel, [$this->entry(0, 'notice-2', 'Another notice')]);

        $this->assertSame(2, NovelChapter::where('novel_id', $novel->id)->count());
    }

    /**
     * F13: an unnumbered afterword resolved to max+1 must not be hijacked by
     * the real next chapter when it appears in a later TOC.
     */
    public function testResolvedEndMatterIsNotHijackedByNextChapter()
    {
        $novel = $this->novel();
        $toc = $this->chapters(10);
        $toc[] = $this->entry(0, 'afterword', 'Afterword');

        $this->scraper()->syncTableOfContents($novel, $toc);
        ChapterNumberResolver::fixUnnumbered($novel->id);

        $afterword = NovelChapter::where('url', self::BASE . 'afterword')->sole();
        $this->assertEquals(11, $afterword->chapter);
        // Sorted at max+0.9: after chapter 10, clear of a real chapter 11.
        $this->assertSame(10.9, $afterword->sort_key);
        $afterword->status = 1;
        $afterword->save();

        // The author keeps writing: chapter 11 is published after the afterword.
        $toc[] = $this->entry(11, 'chapter-11');
        $this->scraper()->syncTableOfContents($novel, $toc);
        ChapterNumberResolver::fixUnnumbered($novel->id);

        $afterword->refresh();
        $this->assertSame('Afterword', $afterword->label);
        $this->assertSame(self::BASE . 'afterword', $afterword->url);
        $this->assertTrue($afterword->status);

        $chapter11 = NovelChapter::where('url', self::BASE . 'chapter-11')->sole();
        $this->assertEquals(11, $chapter11->chapter);
        $this->assertNotEquals($afterword->id, $chapter11->id);

        // No number collision: the end matter moved past the new chapter.
        $this->assertEquals(12, $afterword->chapter);
        $this->assertSame(11.9, $afterword->sort_key);
        $this->assertSame(
            'Afterword',
            NovelChapter::where('novel_id', $novel->id)->ordered()->get()->last()->label
        );

        // And it stays there on the next run.
        $this->scraper()->syncTableOfContents($novel, $toc);
        $this->assertEquals(12, $afterword->fresh()->chapter);
        $this->assertSame(11.9, $afterword->fresh()->sort_key);
        $this->assertEquals(11, $chapter11->fresh()->chapter);
    }

    /** A real chapter with digits in its label is never moved for a newcomer. */
    public function testNumberedRowIsNotMovedByCollidingNewEntry()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(3));

        $toc = $this->chapters(3);
        $toc[] = $this->entry(3, 'chapter-3-alt', 'Chapter 3 (alt)');
        $this->scraper()->syncTableOfContents($novel, $toc);

        $this->assertEquals(3, NovelChapter::where('url', self::BASE . 'chapter-3')->sole()->chapter);
    }

    /** M1a: a domain move (same path) keeps downloaded content. */
    public function testHostOnlyChangeDoesNotRequeue()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(10));
        NovelChapter::where('novel_id', $novel->id)->update(['status' => 1]);

        $toc = $this->chapters(10);
        $toc[4]['url'] = 'http://mirror.example.test/novel/chapter-5';
        $counts = $this->scraper()->syncTableOfContents($novel, $toc);

        $row = NovelChapter::where('novel_id', $novel->id)->where('chapter', 5)->sole();
        $this->assertSame('http://mirror.example.test/novel/chapter-5', $row->url);
        $this->assertTrue($row->status);
        $this->assertSame(0, $counts['relocated_mass']);
    }

    /** M1a: whole-novel domain migration: no re-download, no mass flag needed. */
    public function testWholeNovelDomainMigrationKeepsEverything()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(10));
        NovelChapter::where('novel_id', $novel->id)->update(['status' => 1]);

        $toc = array_map(
            fn($e) => ['url' => str_replace('https://example.test', 'https://new-domain.test', $e['url'])] + $e,
            $this->chapters(10)
        );
        $counts = $this->scraper()->syncTableOfContents($novel, $toc);

        $this->assertSame(0, $counts['created']);
        $this->assertSame(10, NovelChapter::where('novel_id', $novel->id)->where('status', 1)->count());
    }

    /** M1b: more than 20% of rows relocating in one run resets nothing. */
    public function testMassRelocationByShareDoesNotRequeue()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(10));
        NovelChapter::where('novel_id', $novel->id)->update(['status' => 1]);

        $toc = $this->chapters(10);
        foreach ([0, 1, 2] as $i) {
            $toc[$i] = $this->entry($i + 1, 'new-format/' . ($i + 1));
        }
        $counts = $this->scraper()->syncTableOfContents($novel, $toc);

        $this->assertSame(3, $counts['relocated_mass']);
        $this->assertSame(0, $counts['created']);
        $this->assertSame(10, NovelChapter::where('novel_id', $novel->id)->where('status', 1)->count());
        $this->assertSame(self::BASE . 'new-format/1', NovelChapter::where('novel_id', $novel->id)->where('chapter', 1)->sole()->url);
    }

    /** M1b: more than 50 relocations is a migration even on a long novel. */
    public function testMassRelocationByCountDoesNotRequeue()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(300));
        NovelChapter::where('novel_id', $novel->id)->update(['status' => 1]);

        $toc = $this->chapters(300);
        for ($i = 0; $i < 51; $i++) {
            $toc[$i] = $this->entry($i + 1, 'new-format/' . ($i + 1));
        }
        $counts = $this->scraper()->syncTableOfContents($novel, $toc);

        $this->assertSame(51, $counts['relocated_mass']);
        $this->assertSame(300, NovelChapter::where('novel_id', $novel->id)->where('status', 1)->count());
    }

    /** Same number in a different book is a different chapter. */
    public function testBookMismatchKeepsSeparateRows()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, [['book' => 1] + $this->entry(5, 'b1-c5')]);
        $this->scraper()->syncTableOfContents($novel, [['book' => 2] + $this->entry(5, 'b2-c5')]);

        $this->assertSame([1, 2], NovelChapter::where('novel_id', $novel->id)->orderBy('id')->pluck('book')->all());
    }

    /** A soft-deleted row isn't revived — its URL gets a fresh row. */
    public function testSoftDeletedRowUrlIsRecreated()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(2));
        NovelChapter::where('url', self::BASE . 'chapter-2')->sole()->delete();

        $counts = $this->scraper()->syncTableOfContents($novel, $this->chapters(2));

        $this->assertSame(1, $counts['created']);
        $this->assertSame(2, NovelChapter::withTrashed()->where('url', self::BASE . 'chapter-2')->count());
        $this->assertSame(1, NovelChapter::where('url', self::BASE . 'chapter-2')->count());
    }

    public function testLabelOnlyChangeCountsAsUpdated()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, $this->chapters(2));

        $toc = $this->chapters(2);
        $toc[1]['label'] = 'Chapter 2: The Return';
        $counts = $this->scraper()->syncTableOfContents($novel, $toc);

        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0, 'relocated_mass' => 0], $counts);
        $row = NovelChapter::where('url', self::BASE . 'chapter-2')->sole();
        $this->assertSame('Chapter 2: The Return', $row->label);
        $this->assertSame('The Return', $row->title);
        $this->assertSame('Chapter 2: The Return', $row->source_label);
    }

    /** A failed parse (0) never overwrites a resolved number. */
    public function testNeverDowngradesToZero()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, [$this->entry(5, 'chapter-5')]);
        $this->scraper()->syncTableOfContents($novel, [$this->entry(0, 'chapter-5', 'Chapter Five?')]);

        $row = NovelChapter::where('url', self::BASE . 'chapter-5')->sole();
        $this->assertEquals(5, $row->chapter);
        $this->assertSame(5.0, $row->sort_key, 'structured number is not downgraded either');
        $this->assertSame('Chapter Five?', $row->label);
    }

    /** Same URL twice in the TOC with an existing row: one row, later entry wins. */
    public function testDuplicateUrlInTocUpdatesTheExistingRow()
    {
        $novel = $this->novel();
        $this->scraper()->syncTableOfContents($novel, [$this->entry(4, 'chapter-4')]);

        $counts = $this->scraper()->syncTableOfContents($novel, [
            $this->entry(4, 'chapter-4', 'Chapter 4 first'),
            $this->entry(4, 'chapter-4', 'Chapter 4 second'),
        ]);

        $this->assertSame(0, $counts['created']);
        $this->assertSame('Chapter 4 second', NovelChapter::where('novel_id', $novel->id)->sole()->label);
    }

    public function testUrlPathChanged()
    {
        $this->assertFalse(NovelScraper::urlPathChanged('https://a.test/x/1', 'https://a.test/x/1'));
        $this->assertFalse(NovelScraper::urlPathChanged('https://a.test/x/1', 'http://b.test/x/1'));
        $this->assertTrue(NovelScraper::urlPathChanged('https://a.test/x/1', 'https://a.test/x/2'));
        $this->assertTrue(NovelScraper::urlPathChanged('https://a.test/x?id=1', 'https://a.test/x?id=2'));
    }

    /** Invalid URLs are still skipped (validTocUrl behaviour kept). */
    public function testInvalidUrlsAreSkipped()
    {
        $novel = $this->novel();
        $counts = $this->scraper()->syncTableOfContents($novel, [
            ['chapter' => 0, 'label' => 'Arial', 'url' => 'Arial, sans-serif'],
            $this->entry(1, 'chapter-1'),
        ]);

        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(1, $counts['created']);
    }
}
