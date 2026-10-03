<?php

namespace Tests\Unit;

use App\Services\ChapterNumberResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * generateTocChapterInfo(): chapter / part / volume parsing from TOC labels
 * and URLs, teaser filtering and display-label preservation.
 */
class TocLabelParsingTest extends TestCase
{
    private function parse(string $label, string $url = 'https://example.com/novel/x/chapter-1'): ?array
    {
        return generateTocChapterInfo($label, $url);
    }

    #[DataProvider('chapterNumbers')]
    public function testChapterNumbers(string $label, string $url, string $expected): void
    {
        $this->assertSame($expected, (string) $this->parse($label, $url)['chapter'], $label);
    }

    public static function chapterNumbers(): array
    {
        $u = 'https://example.com/novel/x/';
        return [
            'plain'                    => ['Chapter 12: The Fall', $u . 'chapter-12', '12'],
            'decimal kept'             => ['Chapter 12.5', $u . 'chapter-12-5', '12.5'],
            'part N'                   => ['Chapter 12 Part 2', $u . 'chapter-12-part-2', '12.2'],
            '(Part N)'                 => ['Chapter 12 (Part 3)', $u . 'chapter-12', '12.3'],
            'dash part with url'       => ['Chapter 12-2', $u . 'chapter-12-2', '12.2'],
            'dash without url agree'   => ['Chapter 12 - 3 Strangers', $u . 'chapter-12-3-strangers', '12'],
            'double numbering'         => ['Chapter 164 - 106 Title', $u . 'chapter-164-106-title', '164'],
            'paren split'              => ['Chapter 164(2)', $u . 'chapter-164-2', '164.2'],
            'paren 1'                  => ['Chapter 12 (1)', $u . 'chapter-12', '12.1'],
            'paren 10 no collision'    => ['Chapter 12 (10)', $u . 'chapter-12', '12.91'],
            'paren 9'                  => ['Chapter 12 (9)', $u . 'chapter-12', '12.9'],
            'roman I is a title'       => ['Chapter 5 I', $u . 'chapter-5-i', '5'],
            'letter with url agree'    => ['Chapter 164A', $u . 'chapter-164a', '164.1'],
            'letter B dash url agree'  => ['Chapter 164 B - Return', $u . 'chapter-164-b-return', '164.2'],
            'letter url disagrees'     => ['Chapter 164 B - Return', $u . 'chapter-164-return', '164'],
            // H5a: id-based URL (no chapter number) keeps the label's split.
            'letter id url A'          => ['Chapter 164A', 'https://novelarrow.com/chapter/some-novel/98765', '164.1'],
            'letter id url B'          => ['Chapter 164 B - Return', 'https://novelarrow.com/chapter/some-novel/98766', '164.2'],
            'roman I id url'           => ['Chapter 5 I', 'https://novelarrow.com/chapter/some-novel/111', '5'],
            // H5c: "(N)" only right after the number or as the final token.
            'paren mid-title'          => ['Chapter 30: Heroes of (2) Worlds', $u . 'chapter-30', '30'],
            'paren final token'        => ['Chapter 30: Heroes (2)', $u . 'chapter-30', '30.2'],
            'paren A-dash form'        => ['Chapter 5: Title (1) – A – Start', $u . 'chapter-5', '5.1'],
            'underscore suffix'        => ['Chapter 16 - 16 Trading_1', $u . 'chapter-16', '16.1'],
            'no number start'          => ['Vol. 2 Chapter 3', $u . 'vol-2-chapter-3', '3'],
            'number first'             => ['45 The Gate', $u . '45-the-gate', '45'],
            'typo token'               => ['Novel Name Chaper 1161 Title', $u . 'c-1161', '1161'],
            'url fallback'             => ['The Gate', $u . 'the-gate-chapter-86', '86'],
            'negative is not url'      => ['Chapter -1 - Glossary', $u . 'chapter-1-glossary', '0'],
            'department is not part'   => ['Chapter 7: Department 2', $u . 'chapter-7', '7'],
            // Cases observed live.
            'live decimal'             => ['Chapter 374.5', $u . 'chapter-374-5', '374.5'],
            'live first number wins'   => ['Chapter 523 - 517 Title', $u . 'chapter-523-517-title', '523'],
            'typo Chpater'             => ['Chpater 12', $u . 'chpater-12', '12'],
            'typo Chaper'              => ['Chaper 12', $u . 'chaper-12', '12'],
            'typo Chhapter'            => ['Chhapter 12: Run', $u . 'chhapter-12', '12'],
            'Chosen is not chapter'    => ['Chosen 1', $u . 'chosen', '0'],
            'epl1 stays 0'             => ['epl1', $u . 'chapter-900-epl1', '0'],
            'side chapter stays 0'     => ['Side chapter 3', $u . 'side-chapter-3', '0'],
            'side story stays 0'       => ['Side Story 2: Picnic', $u . 'side-story-2', '0'],
        ];
    }

    #[DataProvider('volumes')]
    public function testVolumes(string $label, string $url, int $book): void
    {
        $this->assertSame($book, $this->parse($label, $url)['book'], $label);
    }

    public static function volumes(): array
    {
        $u = 'https://example.com/novel/';
        return [
            ['Vol. 2 Chapter 3', $u . 'x/c3', 2],
            ['Volume 2 Chapter 3', $u . 'x/c3', 2],
            ['Book 2 Chapter 3', $u . 'x/c3', 2],
            ['Vol.4 Chapter 1', $u . 'x/c1', 4],
            ['Chapter 43', $u . 'x/volume-6-chapter-43', 6],
            // Volume marker in an earlier path segment (the novel slug) is ignored.
            ['Chapter 43', $u . 'insect-taming-book-1-chapter-x/chapter-43', 0],
            ['Chapter 9: Facebook 2', $u . 'x/chapter-9', 0],
            // H5b: Book/Volume inside a title is not a volume.
            ['Chapter 300: Book 2 Begins', $u . 'x/chapter-300', 0],
            ['Chapter 12 - The Volume 3 Archive', $u . 'x/chapter-12', 0],
            // URL vN / volN segments.
            ['Chapter 3', $u . 'x/v2-chapter-3', 2],
            ['Chapter 3', $u . 'x/vol2/chapter-3', 2],
            ['Chapter 3', $u . 'x/vol-4-ch-3', 4],
        ];
    }

    public function testTeaserOnlyDroppedAsMarker(): void
    {
        $this->assertNull($this->parse('[Teaser] Chapter 51'));
        $this->assertNull($this->parse('Teaser: Chapter 51'));
        $this->assertNull($this->parse('Chapter 51 Teaser'));
        $this->assertNull($this->parse('Chapter 51 [Teaser]'));

        $kept = $this->parse('Chapter 50: The Teaser Trap', 'https://example.com/novel/x/chapter-50');
        $this->assertNotNull($kept);
        $this->assertSame('50', (string) $kept['chapter']);
    }

    public function testDisplayLabelKeepsOriginalText(): void
    {
        $this->assertSame("Chapter 7: Ren's Choice!", $this->parse("  Chapter 7:   Ren's Choice!  ")['label']);
        $this->assertSame('7', (string) $this->parse("Chapter 7: Ren's Choice!")['chapter']);

        $cjk = $this->parse('第12章 Chapter 12 – 天下无敌', 'https://example.com/novel/x/chapter-12');
        $this->assertSame('第12章 Chapter 12 – 天下无敌', $cjk['label']);
        $this->assertSame('12', (string) $cjk['chapter']);

        $long = $this->parse('Chapter 1 ' . str_repeat('長', 400));
        $this->assertSame(250, mb_strlen($long['label']));
        $this->assertTrue(mb_check_encoding($long['label'], 'UTF-8'));
    }

    public function testPartEncodingIsOrdered(): void
    {
        $values = array_map(fn($n) => (float) encodeChapterPart('12', $n), range(1, 18));
        $sorted = $values;
        sort($sorted);
        $this->assertSame($sorted, $values);
        $this->assertCount(18, array_unique(array_map(fn($v) => sprintf('%.2f', $v), $values)));
        $this->assertSame('12', encodeChapterPart('12', 0));
    }

    /**
     * The whole-number part we parse must be one ChapterNumberResolver also
     * reads from the label, so self-heal and the parser agree.
     */
    public function testConsistentWithChapterNumberResolver(): void
    {
        $cases = [
            'Chapter 12.5',
            'Chapter 12 Part 2',
            'Chapter 164 - 106 Title',
            'Vol. 2 Chapter 3',
            'Chapter 1501 Section 1502 Transforming Soft',
            "Chapter 7: Ren's Choice!",
        ];
        foreach ($cases as $label) {
            $info = $this->parse($label, 'https://example.com/novel/x/c');
            $candidates = ChapterNumberResolver::candidates($label, null)['label'];
            $this->assertContains((int) floor((float) $info['chapter']), $candidates, $label);
        }
    }

    public function testNovelFullOptionParsing(): void
    {
        $origin = 'https://novelfull.com';
        $html = '<select><option value="/shadow-slave/chapter-1.html">Chapter 1 Nightmare Begins</option>'
            . '<option value="/shadow-slave/chapter-2.html">Chapter 2: Ren\'s Choice</option>'
            . '<option value="/shadow-slave/chapter-1.html">Chapter 1 Nightmare Begins</option></select>';
        $rows = parseNovelFullChapterOptions($html, $origin);
        $this->assertCount(2, $rows);
        $this->assertSame('https://novelfull.com/shadow-slave/chapter-1.html', $rows[0]['url']);
        $this->assertSame('2', (string) $rows[1]['chapter']);
        $this->assertSame("Chapter 2: Ren's Choice", $rows[1]['label']);

        // Absolute same-origin values are accepted too.
        $abs = parseNovelFullChapterOptions('<option value="https://novelfull.com/x/chapter-5.html">Chapter 5</option>', $origin);
        $this->assertSame('https://novelfull.com/x/chapter-5.html', $abs[0]['url']);
    }

    public function testNovelFullErrorPageYieldsNull(): void
    {
        // The 404 page's font-size / sort dropdowns produced "https://novelfull.com16px".
        $html = '<html><body><h1>404</h1><select><option value="16px">16</option>'
            . '<option value="https://evil.example/chapter-1">Chapter 1</option><option value="">Pick</option></select></body></html>';
        $this->assertNull(parseNovelFullChapterOptions($html, 'https://novelfull.com'));
        $this->assertNull(parseNovelFullChapterOptions('', 'https://novelfull.com'));
    }

    /** L6: the parser and ChapterNumberResolver share one volume rule. */
    public function testParseVolumePrefix(): void
    {
        $this->assertSame(2, parseVolumePrefix('Vol. 2 Chapter 3'));
        $this->assertSame(2, parseVolumePrefix('  Volume 2 Chapter 3'));
        $this->assertSame(2, parseVolumePrefix('Book 2 Chapter 3'));
        $this->assertSame(4, parseVolumePrefix('vol4 ch 1'));
        $this->assertNull(parseVolumePrefix('Chapter 300: Book 2 Begins'));
        $this->assertNull(parseVolumePrefix('Chapter 9: Facebook 2'));
        $this->assertNull(parseVolumePrefix('Volcano 3'));

        foreach (['Vol. 2 Chapter 3', 'Book 7 Chapter 1', 'Chapter 300: Book 2 Begins'] as $label) {
            $this->assertSame(parseVolumePrefix($label) ?? 0, $this->parse($label)['book'], $label);
        }
    }

    public function testResolverUsesSharedVolumeHelper(): void
    {
        $src = file_get_contents(app_path('Services/ChapterNumberResolver.php'));
        $this->assertStringContainsString('parseVolumePrefix(', $src);
    }
}
