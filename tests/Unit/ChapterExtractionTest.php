<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * extractChapterParagraphs() against saved source pages. Regressions here
 * are the "chapter pages load but contain no chapter text" dashboard alert.
 */
class ChapterExtractionTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . "/../fixtures/{$name}");
    }

    /**
     * Divine Emperor of Death ch. 209 on novelfull: ~1,000 words served as
     * only 8 <p> blocks. The old ">10 paragraphs" rule rejected it on 5,735
     * consecutive runs.
     */
    public function testNovelfullChapterWithFewLongParagraphsIsExtracted(): void
    {
        $paragraphs = extractChapterParagraphs($this->fixture('novelfull-chapter-209-eight-paragraphs.html'));

        // The page has 8 <p> blocks; two are an ad slot and the site's
        // "if you find any errors… report chapter" notice, which the spam
        // filter drops. Six paragraphs of story remain.
        $this->assertCount(6, $paragraphs);
        $this->assertGreaterThan(1000, chapterParagraphWords($paragraphs));
        $this->assertStringContainsString('Davis asked in a nonchalant tone', $paragraphs[0]);
        $this->assertStringContainsString('the safety of his sister', end($paragraphs));

        foreach ($paragraphs as $p) {
            $this->assertStringStartsWith('<p>', $p);
            $this->assertStringNotContainsString('Prev Chapter', $p);
            $this->assertStringNotContainsString('report chapter', strtolower($p));
        }
    }

    public function testCompletenessIsJudgedOnWordsOrParagraphs(): void
    {
        $long = array_fill(0, 3, '<p>' . str_repeat('word ', 60) . '</p>'); // 180 words, 3 paras
        $this->assertTrue(chapterParagraphsLookComplete($long));

        $many = array_fill(0, 10, '<p>Short line.</p>');
        $this->assertTrue(chapterParagraphsLookComplete($many));

        $thin = array_fill(0, 4, '<p>Only a few words here.</p>');
        $this->assertFalse(chapterParagraphsLookComplete($thin));
        $this->assertFalse(chapterParagraphsLookComplete([]));
    }

    /** A page with no recognisable container yields nothing, not a crash. */
    public function testUnknownMarkupYieldsEmpty(): void
    {
        $html = '<html><body><nav><a href="/">Home</a></nav><footer>© site</footer></body></html>';
        $this->assertSame([], extractChapterParagraphs($html));
    }

    /** A short-but-real page is returned rather than discarded as nothing. */
    public function testShortRealContentIsKeptForTheWordGate(): void
    {
        $html = '<html><body><div id="chapter-content">'
            . '<p>Translator: 549690339</p>'
            . '<p>I got into a fight with my daughter-in-law, and I was so upset that I couldn\'t write a book. I\'m sorry.</p>'
            . '</div></body></html>';

        $paragraphs = extractChapterParagraphs($html);
        $this->assertCount(2, $paragraphs);
        $this->assertStringContainsString('daughter-in-law', $paragraphs[1]);
    }
}
