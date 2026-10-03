<?php

namespace Tests\Unit;

use App\Scraping\ChapterLabel;
use App\Scraping\ChapterLabelParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ChapterLabelParser (audit A4): structured volume / number / part / kind /
 * title / sort key, kept in agreement with the legacy
 * generateTocChapterInfo() parser.
 */
class ChapterLabelParserTest extends TestCase
{
    private const U = 'https://example.com/novel/x/';

    /**
     * Every chapter-number case of TocLabelParsingTest, run through both
     * parsers: (int) number and volume match, and the parser's number + part
     * reproduce the legacy `chapter` value exactly (legacyChapter()).
     */
    #[DataProvider('legacyCases')]
    public function testAgreesWithGenerateTocChapterInfo(string $label, string $url): void
    {
        $legacy = generateTocChapterInfo($label, $url);
        $parsed = ChapterLabelParser::parse($label, $url);

        $this->assertSame((int) $legacy['chapter'], (int) $parsed->number, "number: {$label}");
        $this->assertSame($legacy['book'], $parsed->volume, "volume: {$label}");
        $this->assertEqualsWithDelta((float) $legacy['chapter'], $parsed->legacyChapter(), 1e-9, "legacy chapter: {$label}");
    }

    public static function legacyCases(): array
    {
        $u = self::U;
        $v = 'https://example.com/novel/';
        return [
            'plain'                  => ['Chapter 12: The Fall', $u . 'chapter-12'],
            'decimal kept'           => ['Chapter 12.5', $u . 'chapter-12-5'],
            'part N'                 => ['Chapter 12 Part 2', $u . 'chapter-12-part-2'],
            '(Part N)'               => ['Chapter 12 (Part 3)', $u . 'chapter-12'],
            'dash part with url'     => ['Chapter 12-2', $u . 'chapter-12-2'],
            'dash without url agree' => ['Chapter 12 - 3 Strangers', $u . 'chapter-12-3-strangers'],
            'double numbering'       => ['Chapter 164 - 106 Title', $u . 'chapter-164-106-title'],
            'paren split'            => ['Chapter 164(2)', $u . 'chapter-164-2'],
            'paren 1'                => ['Chapter 12 (1)', $u . 'chapter-12'],
            'paren 10 no collision'  => ['Chapter 12 (10)', $u . 'chapter-12'],
            'paren 9'                => ['Chapter 12 (9)', $u . 'chapter-12'],
            'roman I is a title'     => ['Chapter 5 I', $u . 'chapter-5-i'],
            'letter with url agree'  => ['Chapter 164A', $u . 'chapter-164a'],
            'letter B dash url'      => ['Chapter 164 B - Return', $u . 'chapter-164-b-return'],
            'letter url disagrees'   => ['Chapter 164 B - Return', $u . 'chapter-164-return'],
            'letter id url A'        => ['Chapter 164A', 'https://novelarrow.com/chapter/some-novel/98765'],
            'letter id url B'        => ['Chapter 164 B - Return', 'https://novelarrow.com/chapter/some-novel/98766'],
            'roman I id url'         => ['Chapter 5 I', 'https://novelarrow.com/chapter/some-novel/111'],
            'paren mid-title'        => ['Chapter 30: Heroes of (2) Worlds', $u . 'chapter-30'],
            'paren final token'      => ['Chapter 30: Heroes (2)', $u . 'chapter-30'],
            'paren A-dash form'      => ['Chapter 5: Title (1) – A – Start', $u . 'chapter-5'],
            'underscore suffix'      => ['Chapter 16 - 16 Trading_1', $u . 'chapter-16'],
            'no number start'        => ['Vol. 2 Chapter 3', $u . 'vol-2-chapter-3'],
            'number first'           => ['45 The Gate', $u . '45-the-gate'],
            'typo token'             => ['Novel Name Chaper 1161 Title', $u . 'c-1161'],
            'url fallback'           => ['The Gate', $u . 'the-gate-chapter-86'],
            'negative is not url'    => ['Chapter -1 - Glossary', $u . 'chapter-1-glossary'],
            'department is not part' => ['Chapter 7: Department 2', $u . 'chapter-7'],
            'live decimal'           => ['Chapter 374.5', $u . 'chapter-374-5'],
            'live first number wins' => ['Chapter 523 - 517 Title', $u . 'chapter-523-517-title'],
            'typo Chpater'           => ['Chpater 12', $u . 'chpater-12'],
            'typo Chaper'            => ['Chaper 12', $u . 'chaper-12'],
            'typo Chhapter'          => ['Chhapter 12: Run', $u . 'chhapter-12'],
            'Chosen is not chapter'  => ['Chosen 1', $u . 'chosen'],
            'epl1 stays 0'           => ['epl1', $u . 'chapter-900-epl1'],
            'side chapter stays 0'   => ['Side chapter 3', $u . 'side-chapter-3'],
            'side story stays 0'     => ['Side Story 2: Picnic', $u . 'side-story-2'],
            'cjk'                    => ['第12章 Chapter 12 – 天下无敌', $u . 'chapter-12'],
            // Volume cases.
            'vol prefix'             => ['Vol. 2 Chapter 3', $v . 'x/c3'],
            'volume prefix'          => ['Volume 2 Chapter 3', $v . 'x/c3'],
            'book prefix'            => ['Book 2 Chapter 3', $v . 'x/c3'],
            'vol.4'                  => ['Vol.4 Chapter 1', $v . 'x/c1'],
            'url volume-6'           => ['Chapter 43', $v . 'x/volume-6-chapter-43'],
            'slug book ignored'      => ['Chapter 43', $v . 'insect-taming-book-1-chapter-x/chapter-43'],
            'facebook'               => ['Chapter 9: Facebook 2', $v . 'x/chapter-9'],
            'book in title'          => ['Chapter 300: Book 2 Begins', $v . 'x/chapter-300'],
            'volume in title'        => ['Chapter 12 - The Volume 3 Archive', $v . 'x/chapter-12'],
            'url v2-'                => ['Chapter 3', $v . 'x/v2-chapter-3'],
            'url /vol2/'             => ['Chapter 3', $v . 'x/vol2/chapter-3'],
            'url vol-4-ch-'          => ['Chapter 3', $v . 'x/vol-4-ch-3'],
        ];
    }

    /** Where the legacy encoder packs parts into .1–.99, the new keys keep that order. */
    public function testPartsReproduceLegacyOrdering(): void
    {
        $labels = ['Chapter 12', 'Chapter 12 (1)', 'Chapter 12 Part 2', 'Chapter 12 (9)', 'Chapter 12 (10)', 'Chapter 12 (18)', 'Chapter 13'];
        $legacy = array_map(fn($l) => (float) generateTocChapterInfo($l, self::U . 'c')['chapter'], $labels);
        $keys = array_map(fn($l) => ChapterLabelParser::parse($l, self::U . 'c')->sortKey, $labels);

        $sortedLegacy = $legacy;
        sort($sortedLegacy);
        $sortedKeys = $keys;
        sort($sortedKeys);
        $this->assertSame($sortedLegacy, $legacy);
        $this->assertSame($sortedKeys, $keys);
        $this->assertCount(count($keys), array_unique(array_map('strval', $keys)));
    }

    #[DataProvider('structuredCases')]
    public function testStructuredFields(string $label, string $url, int $volume, ?float $number, int $part, string $kind, ?string $title, ?float $sortKey): void
    {
        $p = ChapterLabelParser::parse($label, $url);

        $this->assertSame($volume, $p->volume, 'volume');
        $this->assertSame($number, $p->number, 'number');
        $this->assertSame($part, $p->part, 'part');
        $this->assertSame($kind, $p->kind, 'kind');
        $this->assertSame($title, $p->title, 'title');
        $this->assertSame($sortKey, $p->sortKey, 'sortKey');
    }

    public static function structuredCases(): array
    {
        $u = self::U;
        return [
            'plain'           => ['Chapter 12: The Fall', $u . 'chapter-12', 0, 12.0, 0, 'chapter', 'The Fall', 12.0],
            'decimal'         => ['Chapter 374.5', $u . 'c', 0, 374.5, 0, 'chapter', null, 374.5],
            'first number'    => ['Chapter 523 - 517', $u . 'c', 0, 523.0, 0, 'chapter', '517', 523.0],
            'paren part'      => ['Chapter 12 (2)', $u . 'c', 0, 12.0, 2, 'chapter', null, 12.002],
            'part N title'    => ['Chapter 12 Part 3: Return', $u . 'c', 0, 12.0, 3, 'chapter', 'Return', 12.003],
            'trailing paren'  => ['Chapter 30: Heroes (2)', $u . 'c', 0, 30.0, 2, 'chapter', 'Heroes', 30.002],
            'underscore'      => ['Chapter 16 - Trading_1', $u . 'c', 0, 16.0, 1, 'chapter', 'Trading', 16.001],
            'letter split'    => ['Chapter 164 B - Return', $u . 'chapter-164-b-return', 0, 164.0, 2, 'chapter', 'Return', 164.002],
            'volume prefix'   => ['Vol. 2 Chapter 3: Dawn', $u . 'c', 2, 3.0, 0, 'chapter', 'Dawn', 3.0],
            'url volume'      => ['Chapter 3', $u . 'vol-4-ch-3', 4, 3.0, 0, 'chapter', null, 3.0],
            'typo'            => ['Chpater 12 – Run!', $u . 'c', 0, 12.0, 0, 'chapter', 'Run!', 12.0],
            'cjk title kept'  => ['第12章 Chapter 12 – 天下无敌', $u . 'c', 0, 12.0, 0, 'chapter', '天下无敌', 12.0],
            'prologue'        => ['Prologue', $u . 'prologue', 0, null, 0, 'prologue', 'Prologue', null],
            'epilogue'        => ['Chapter 100 - Epilogue', $u . 'c', 0, 100.0, 0, 'epilogue', 'Epilogue', 100.0],
            'interlude'       => ['Interlude: The Bridge', $u . 'c', 0, null, 0, 'interlude', 'Interlude: The Bridge', null],
            'side story'      => ['Side Story 2: Picnic', $u . 'side-story-2', 0, null, 0, 'side', 'Side Story 2: Picnic', null],
            'side chapter'    => ['Side chapter 3', $u . 'side-chapter-3', 0, null, 0, 'side', 'Side chapter 3', null],
            'extra'           => ['Bonus Chapter: Beach', $u . 'c', 0, null, 0, 'extra', 'Bonus Chapter: Beach', null],
            'afterword'       => ['Afterword', $u . 'afterword', 0, null, 0, 'afterword', 'Afterword', null],
            'notice'          => ["Author's Note", $u . 'note', 0, null, 0, 'notice', "Author's Note", null],
            'completion'      => ['Chapter 4851: COMPLETE', $u . 'c', 0, 4851.0, 0, 'notice', 'COMPLETE', 4851.0],
            'mention only'    => ['Chapter 3: The Extra Mile', $u . 'c', 0, 3.0, 0, 'chapter', 'The Extra Mile', 3.0],
            'unknown'         => ['Chosen 1', $u . 'chosen', 0, null, 0, 'unknown', 'Chosen 1', null],
        ];
    }

    public function testSortKeysOrderDecimalsAndParts(): void
    {
        $keys = array_map(
            fn($l) => ChapterLabelParser::parse($l, self::U . 'c')->sortKey,
            ['Chapter 12', 'Chapter 12 (1)', 'Chapter 12 (2)', 'Chapter 12.5', 'Chapter 13']
        );
        $this->assertSame([12.0, 12.001, 12.002, 12.5, 13.0], $keys);
    }

    public function testSourceLabelIsTrimmedAndCollapsed(): void
    {
        $p = ChapterLabelParser::parse("  Chapter 7:   Ren's\u{200B} Choice!  ", self::U . 'c');
        $this->assertSame("Chapter 7: Ren's Choice!", $p->sourceLabel);
        $this->assertSame("Ren's Choice!", $p->title);
    }

    public function testVolumeHintIsTheLastResort(): void
    {
        $this->assertSame(3, ChapterLabelParser::parse('Chapter 1', self::U . 'c', 3)->volume);
        $this->assertSame(2, ChapterLabelParser::parse('Vol. 2 Chapter 1', self::U . 'c', 3)->volume);
    }

    public function testLegacyChapterUsesEncodeChapterPart(): void
    {
        $this->assertSame(12.91, ChapterLabelParser::parse('Chapter 12 (10)', self::U . 'c')->legacyChapter());
        $this->assertSame(12.2, ChapterLabelParser::parse('Chapter 12 Part 2', self::U . 'c')->legacyChapter());
        $this->assertSame(0.0, ChapterLabelParser::parse('Afterword', self::U . 'c')->legacyChapter());
        $this->assertTrue(ChapterLabelParser::parse('Afterword', self::U . 'c')->isSpecial());
        $this->assertFalse(ChapterLabelParser::parse('Chapter 2', self::U . 'c')->isSpecial());
        $this->assertContains(ChapterLabelParser::parse('Afterword')->kind, ChapterLabel::KINDS);
    }

    public function testDecodeLegacy(): void
    {
        $this->assertSame([12.0, 0], ChapterLabelParser::decodeLegacy(12.0));
        $this->assertSame([12.0, 2], ChapterLabelParser::decodeLegacy(12.2));
        $this->assertSame([12.0, 10], ChapterLabelParser::decodeLegacy(12.91));
        $this->assertSame([12.0, 18], ChapterLabelParser::decodeLegacy(12.99));
        $this->assertSame([12.25, 0], ChapterLabelParser::decodeLegacy(12.25));
        $this->assertSame([-1.0, 0], ChapterLabelParser::decodeLegacy(-1.0));
    }

    public function testReconcile(): void
    {
        $agree = ChapterLabelParser::reconcile(ChapterLabelParser::parse('Chapter 12.5', self::U . 'c'), 12.5);
        $this->assertSame([12.5, 0, 12.5, true], array_values($agree));

        // No parsed number: derived from the legacy value (resolver-assigned).
        $resolved = ChapterLabelParser::reconcile(ChapterLabelParser::parse('Afterword', self::U . 'c'), 11.0);
        $this->assertSame([11.0, 0, 11.0, true], array_values($resolved));

        // ...including the resolver's p+0.5 "extra between p and p+1".
        $extra = ChapterLabelParser::reconcile(ChapterLabelParser::parse('Interlude', self::U . 'c'), 12.5);
        $this->assertSame([12.5, 0, 12.5, true], array_values($extra));

        // Legacy 0 = no position: the key stays NULL, even for "Chapter 0".
        $zero = ChapterLabelParser::reconcile(ChapterLabelParser::parse('Chapter 0', self::U . 'c'), 0.0);
        $this->assertSame([0.0, 0, null, true], array_values($zero));

        // Disagreement: legacy wins, flagged.
        $disagree = ChapterLabelParser::reconcile(ChapterLabelParser::parse('Chapter 40', self::U . 'c'), 12.2);
        $this->assertSame([12.0, 2, 12.002, false], array_values($disagree));
    }
}
