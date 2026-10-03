<?php

namespace Tests\Feature;

use App\Console\Commands\NovelScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use App\Scraping\ChapterLabelParser;
use App\Services\ChapterNumberResolver;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Audit A4: reading order by (book, sort_key, chapter, id), the structured
 * backfill, and reader prev/next across decimals, parts, volumes and end
 * matter.
 */
class ChapterOrderingTest extends TestCase
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
            'name' => 'Ordering Novel',
            'status' => 0,
            'group_id' => $group->id,
            'no_of_chapters' => 10,
        ]);
    }

    private function sync(Novel $novel, array $labels): void
    {
        $command = new NovelScraper();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        $toc = [];
        foreach ($labels as $slug => $label) {
            $url = self::BASE . $slug;
            $toc[] = generateTocChapterInfo($label, $url);
        }
        $command->syncTableOfContents($novel, $toc);
    }

    /** @return string[] labels in reading order */
    private function orderedLabels(Novel $novel): array
    {
        return NovelChapter::where('novel_id', $novel->id)->ordered()->pluck('label')->all();
    }

    public function testDecimalsAndPartsOrder(): void
    {
        $novel = $this->novel();
        // TOC order deliberately scrambled.
        $this->sync($novel, [
            'chapter-13' => 'Chapter 13',
            'chapter-12-5' => 'Chapter 12.5',
            'chapter-12-7' => 'Chapter 12 (7)',
            'chapter-12-2' => 'Chapter 12 (2)',
            'chapter-12' => 'Chapter 12',
            'chapter-12-1' => 'Chapter 12 (1)',
        ]);

        // Parts sit between 12 and 12.5 — the legacy encoding put part 7
        // (12.7) after the decimal 12.5.
        $this->assertSame(
            ['Chapter 12', 'Chapter 12 (1)', 'Chapter 12 (2)', 'Chapter 12 (7)', 'Chapter 12.5', 'Chapter 13'],
            $this->orderedLabels($novel)
        );

        $part = NovelChapter::where('url', self::BASE . 'chapter-12-2')->sole();
        $this->assertSame(12.0, $part->number);
        $this->assertSame(2, $part->part);
        $this->assertSame(12.002, $part->sort_key);
        $this->assertEquals(12.2, $part->chapter, 'legacy chapter stays populated');
        $this->assertSame('12 (part 2)', $part->displayNumber());
        $this->assertSame('12.5', NovelChapter::where('url', self::BASE . 'chapter-12-5')->sole()->displayNumber());
    }

    public function testVolumesOrderBeforeChapterNumbers(): void
    {
        $novel = $this->novel();
        $this->sync($novel, [
            'v2-chapter-1' => 'Vol. 2 Chapter 1',
            'v1-chapter-3' => 'Vol. 1 Chapter 3',
            'v1-chapter-1' => 'Vol. 1 Chapter 1',
        ]);

        $this->assertSame(['Vol. 1 Chapter 1', 'Vol. 1 Chapter 3', 'Vol. 2 Chapter 1'], $this->orderedLabels($novel));
        $this->assertSame('Vol 2 · 1', NovelChapter::where('url', self::BASE . 'v2-chapter-1')->sole()->displayNumber());
    }

    public function testEndMatterSortsAfterTheLastChapterAndStaysThere(): void
    {
        $novel = $this->novel();
        $toc = ['chapter-1' => 'Chapter 1', 'chapter-2' => 'Chapter 2', 'chapter-3' => 'Chapter 3', 'afterword' => 'Afterword'];
        $this->sync($novel, $toc);
        ChapterNumberResolver::fixUnnumbered($novel->id);

        $afterword = NovelChapter::where('url', self::BASE . 'afterword')->sole();
        $this->assertEquals(4, $afterword->chapter, 'legacy max+1 kept');
        $this->assertSame(3.9, $afterword->sort_key, 'sorted at max+0.9');
        $this->assertTrue($afterword->isSpecial());
        $this->assertSame(['Chapter 1', 'Chapter 2', 'Chapter 3', 'Afterword'], $this->orderedLabels($novel));

        // A later chapter 4 lands on the afterword's legacy number: the
        // afterword moves past it.
        $this->sync($novel, $toc + ['chapter-4' => 'Chapter 4']);
        ChapterNumberResolver::fixUnnumbered($novel->id);
        $this->assertSame(['Chapter 1', 'Chapter 2', 'Chapter 3', 'Chapter 4', 'Afterword'], $this->orderedLabels($novel));
        $this->assertSame(4.9, $afterword->fresh()->sort_key);
        $this->assertEquals(5, $afterword->fresh()->chapter);

        // A re-sync (afterword label still unparseable) keeps its placement.
        $this->sync($novel, $toc + ['chapter-4' => 'Chapter 4']);
        $this->assertSame(4.9, $afterword->fresh()->sort_key);
    }

    public function testReaderPrevNextFollowReadingOrder(): void
    {
        $novel = $this->novel();
        $this->sync($novel, [
            'v1-chapter-12-5' => 'Vol. 1 Chapter 12.5',
            'v1-chapter-12' => 'Vol. 1 Chapter 12',
            'v1-chapter-12-2' => 'Vol. 1 Chapter 12 (2)',
            'v1-chapter-12-1' => 'Vol. 1 Chapter 12 (1)',
            'v1-chapter-13' => 'Vol. 1 Chapter 13',
            'v2-chapter-1' => 'Vol. 2 Chapter 1',
            'v2-afterword' => 'Afterword',
        ]);
        // Afterword: book 2 via the URL, resolver places it after 2·1.
        NovelChapter::where('url', self::BASE . 'v2-afterword')->update(['book' => 2]);
        ChapterNumberResolver::fixUnnumbered($novel->id);

        $expected = [
            'Vol. 1 Chapter 12', 'Vol. 1 Chapter 12 (1)', 'Vol. 1 Chapter 12 (2)', 'Vol. 1 Chapter 12.5',
            'Vol. 1 Chapter 13', 'Vol. 2 Chapter 1', 'Afterword',
        ];
        $ordered = NovelChapter::where('novel_id', $novel->id)->ordered()->get();
        $this->assertSame($expected, $ordered->pluck('label')->all());

        foreach ($ordered->values() as $i => $chapter) {
            $view = $this->get(route('chapters.show', $chapter->id))->assertOk();
            $prev = $view->viewData('prev');
            $next = $view->viewData('next');
            $this->assertSame($i > 0 ? $ordered[$i - 1]->id : null, $prev?->id, "prev of {$chapter->label}");
            $this->assertSame($ordered[$i + 1]->id ?? null, $next?->id, "next of {$chapter->label}");
        }
    }

    /** Rows without a sort_key (not yet backfilled / unknown) fall back to `chapter`. */
    public function testPrevNextFallBackToChapterWithoutSortKey(): void
    {
        $novel = $this->novel();
        $ids = [];
        foreach ([1, 2, 3] as $n) {
            $ids[$n] = NovelChapter::create([
                'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
                'label' => "Chapter {$n}", 'url' => self::BASE . "c{$n}",
            ])->id;
        }

        $view = $this->get(route('chapters.show', $ids[2]))->assertOk();
        $this->assertSame($ids[1], $view->viewData('prev')->id);
        $this->assertSame($ids[3], $view->viewData('next')->id);
    }

    /**
     * Backfill over 200 mixed rows: order by (book, sort_key, id) must equal
     * the legacy order by (book, chapter, id).
     */
    public function testBackfillPreservesLegacyOrder(): void
    {
        $novel = $this->novel();
        $other = $this->novel();
        mt_srand(4242);

        $shapes = [
            fn($n) => ["Chapter {$n}", "chapter-{$n}", (float) $n],
            fn($n) => ["Chapter {$n}: Title {$n}", "chapter-{$n}-title", (float) $n],
            fn($n) => ["Chapter {$n}.5", "chapter-{$n}-5", $n + 0.5],
            fn($n) => ["Chapter {$n} Part 2", "chapter-{$n}-part-2", $n + 0.2],
            fn($n) => ["Chapter {$n} (10)", "chapter-{$n}", $n + 0.91],
            fn($n) => ["Chapter {$n}A", "chapter-{$n}a", $n + 0.1],
            fn($n) => ['Afterword', 'afterword-' . $n, (float) $n],         // resolver max+1
            fn($n) => ['Interlude', 'interlude-' . $n, $n + 0.5],           // positional extra
            fn($n) => ['Notice', 'notice-' . $n, 0.0],                       // unnumbered
            fn($n) => ['Chapter 0', 'chapter-0-' . $n, 0.0],                 // a real "0"
            fn($n) => ["Chapter " . ($n + 40), "chapter-{$n}-moved", (float) $n], // label disagrees
            fn($n) => ["Chapter {$n}.25", "chapter-{$n}-25", $n + 0.25],
        ];

        $now = now();
        $rows = [];
        for ($i = 0; $i < 196; $i++) {
            [$label, $slug, $chapter] = $shapes[mt_rand(0, count($shapes) - 1)](mt_rand(1, 400));
            $rows[] = [
                'novel_id' => $i < 150 ? $novel->id : $other->id,
                'label' => $label,
                'url' => self::BASE . $slug . '-' . $i,
                'chapter' => $chapter,
                'book' => mt_rand(0, 2),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        // A deliberate conflict in (other novel, book 3): a real decimal 12.5
        // and an encoded part 12.7 — the new keys would swap them, so that
        // book must fall back to sort_key = chapter.
        foreach ([['Chapter 12.5', 12.5], ['Chapter 12 (7)', 12.7], ['Chapter 12', 12.0], ['Chapter 13', 13.0]] as $j => [$label, $chapter]) {
            $rows[] = [
                'novel_id' => $other->id, 'label' => $label, 'url' => self::BASE . "conflict-{$j}",
                'chapter' => $chapter, 'book' => 3, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        shuffle($rows);
        foreach (array_chunk($rows, 50) as $batch) {
            DB::table('novel_chapters')->insert($batch);
        }

        $result = ChapterLabelParser::backfill(null, 1000);
        $this->assertSame(200, $result['rows']);
        // Only the conflicting book falls back; the rest keep parser keys.
        $this->assertSame(1, $result['fallback_books']);
        $this->assertEquals(
            [12.0, 12.5, 12.7, 13.0],
            DB::table('novel_chapters')->where('novel_id', $other->id)->where('book', 3)->orderBy('sort_key')->pluck('sort_key')->map(fn($v) => (float) $v)->all()
        );
        $this->assertSame(
            0,
            DB::table('novel_chapters')->where('book', '<', 3)->where('label', 'like', '% Part 2')->whereRaw('sort_key = chapter')->count(),
            'part rows outside the conflicting book carry number + part/1000'
        );

        $legacy = DB::table('novel_chapters')->orderBy('novel_id')->orderBy('book')->orderBy('chapter')->orderBy('id')->pluck('id')->all();
        $keyed = DB::table('novel_chapters')->orderBy('novel_id')->orderBy('book')->orderBy('sort_key')->orderBy('id')->pluck('id')->all();
        $this->assertSame($legacy, $keyed);

        // Every numbered row got a number and title/source_label were filled.
        $this->assertSame(0, DB::table('novel_chapters')->where('chapter', '!=', 0)->whereNull('number')->count());
        $this->assertSame(0, DB::table('novel_chapters')->whereNull('source_label')->count());
    }

    /** A clean novel keeps the parser's keys (no fallback to `chapter`). */
    public function testBackfillWritesParserKeysForCleanNovels(): void
    {
        $novel = $this->novel();
        $now = now();
        foreach ([['Chapter 1', 1.0], ['Chapter 1 (2)', 1.2], ['Chapter 1 (10)', 1.91], ['Chapter 2: Rise', 2.0]] as $i => [$label, $chapter]) {
            DB::table('novel_chapters')->insert([
                'novel_id' => $novel->id, 'label' => $label, 'url' => self::BASE . "c{$i}",
                'chapter' => $chapter, 'book' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $result = ChapterLabelParser::backfill($novel->id);
        $this->assertSame(0, $result['fallback_books']);

        $rows = NovelChapter::where('novel_id', $novel->id)->ordered()->get();
        $this->assertSame([1.0, 1.002, 1.01, 2.0], $rows->pluck('sort_key')->all());
        $this->assertSame([0, 2, 10, 0], $rows->pluck('part')->all());
        $this->assertSame('Rise', $rows[3]->title);
        $this->assertSame('Chapter 2: Rise', $rows[3]->source_label);
    }

    public function testDueAndNeedsReviewScopes(): void
    {
        $novel = $this->novel();
        $make = fn(array $attrs) => NovelChapter::create(['novel_id' => $novel->id, 'chapter' => 1, 'label' => 'x', 'url' => self::BASE . uniqid()])
            ->forceFill($attrs + ['status' => 0, 'blacklist' => 0])->save();

        $make(['next_attempt_at' => null]);
        $make(['next_attempt_at' => now()->subMinute()]);
        $make(['next_attempt_at' => now()->addHour()]);
        $make(['next_attempt_at' => null, 'status' => 1]);
        $make(['attempts' => 8]);
        $make(['attempts' => 7]);

        $this->assertSame(4, NovelChapter::where('novel_id', $novel->id)->due()->count());
        $this->assertSame(1, NovelChapter::where('novel_id', $novel->id)->needsReview()->count());
    }
}
