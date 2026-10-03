<?php

namespace Tests\Unit;

use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

/**
 * Pure chapter-text helpers: spam-line filter, watermark cleaner, entity
 * handling, paragraph splitting and inline-emphasis preservation.
 */
class ChapterTextCleaningTest extends TestCase
{
    private function story(int $words = 200): string
    {
        return trim(str_repeat('The wind howled across the silent valley. ', (int) ceil($words / 7)));
    }

    // ---- F5: spam lines -------------------------------------------------

    public function testStoryLinesMentioningSponsorsAreKept(): void
    {
        $this->assertFalse(isChapterSpamLine('Lin Feng sponsored the young disciple.'));
        $this->assertFalse(isChapterSpamLine('The Outbrainer clan arrived.'));
        $this->assertFalse(isChapterSpamLine(str_repeat('He shouted "!important" at the crowd again and again. ', 4)));
    }

    public function testWidgetLinesAreDropped(): void
    {
        $this->assertTrue(isChapterSpamLine('Sponsored'));
        $this->assertTrue(isChapterSpamLine('Promoted Content'));
        $this->assertTrue(isChapterSpamLine('Ten tricks doctors hateRead MoreUndo'));
        $this->assertTrue(isChapterSpamLine('Free gamePlay NowUndo'));
        $this->assertTrue(isChapterSpamLine('Recommended by Outbrain'));
        $this->assertTrue(isChapterSpamLine('powered by taboola'));
        $this->assertTrue(isChapterSpamLine('.ad { display: none !important; }'));
    }

    // ---- F6: watermarks -------------------------------------------------

    public function testWatermarkParagraphsAreDropped(): void
    {
        foreach ([
            'Read the latest chapters at novelbin.com',
            'R e a d  latest at n0velb1n.c0m!',
            'This chapter is updated by novelfull.com',
            'The source of this content is lightnovelpub.com',
            'Find the original at freewebnovel.com.',
            'Visit novelarrow.com for the fastest updates',
            'Please support the author!',
            "N\u{200B}ovel\u{200C}Bin.com",
            'N 0 v e l B 1 n . c 0 m',
            'ｎｏｖｅｌｂｉｎ．ｃｏｍ', // full-width, NFKC-folded
        ] as $line) {
            $this->assertNull(cleanChapterParagraphText($line), $line);
        }
    }

    public function testStoryTextIsNotTreatedAsWatermark(): void
    {
        foreach ([
            'He read the latest report at dawn.',
            'Come find me at dawn, they told me.',
            'Visit the elder for advice, she said.',
            'The records were carefully kept by the clerk.',
            "Ren's choice was final.",
        ] as $line) {
            $this->assertSame($line, cleanChapterParagraphText($line), $line);
        }
    }

    public function testTrailingWatermarkSentenceIsCut(): void
    {
        $story = $this->story(60);
        $this->assertSame($story, cleanChapterParagraphText($story . ' Read the latest chapters at n0velb1n.c0m.'));
        $this->assertSame('He nodded.', cleanChapterParagraphText('He nodded. Visit novelbin.com for more.'));
    }

    public function testLongParagraphMentioningSiteMidTextIsKept(): void
    {
        $text = 'The novelbin merchant guild sent word. ' . $this->story(60);
        $this->assertSame($text, cleanChapterParagraphText($text));
    }

    public function testZeroWidthCharactersAreStripped(): void
    {
        $this->assertSame('Hello there friend', cleanChapterParagraphText("Hel\u{200B}lo there\u{FEFF} friend"));
        $this->assertNull(cleanChapterParagraphText("\u{200B}\u{2060}"));
    }

    public function testWatermarkListIsConfigurable(): void
    {
        config(['novarr.watermarks' => ['sites' => ['mysecretsite'], 'phrases' => [], 'lines' => ['^subscribenow$']]]);
        $this->assertNull(cleanChapterParagraphText('My Secret Site . com'));
        $this->assertNull(cleanChapterParagraphText('Subscribe now!'));
        // Default sites/phrases are replaced, so this is no longer a watermark.
        $this->assertSame('Read latest at novelbin.com', cleanChapterParagraphText('Read latest at novelbin.com'));
    }

    public function testInvalidWatermarkPatternIsSkippedWithWarning(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        config(['novarr.watermarks' => ['sites' => ['novel(full', 'novelfull'], 'phrases' => ['[broken'], 'lines' => []]]);

        $this->assertNull(cleanChapterParagraphText('novelfull.com'));
        $this->assertSame('He read the latest scroll.', cleanChapterParagraphText('He read the latest scroll.'));

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn($msg) => str_contains($msg, 'Invalid novarr.watermarks pattern'))
            ->twice();
    }

    /** Audit H1: story text that folds onto a site name or a TLD. */
    public function testStoryTextResemblingWatermarksIsKept(): void
    {
        foreach ([
            'It was a novel full of wonders and stories told.',
            'Find them on the planet.',
            'They searched the internet for answers at night.',
            'Find it at the bottom of the cabinet.',
            'Visit the telecom tower to see.',
            'He read the latest report at dawn.',
            'Come find me at dawn.',
            'The novelfull of the wind.',
        ] as $line) {
            $this->assertSame($line, cleanChapterParagraphText($line), $line);
        }

        $long = $this->story(40) . ' Find him on the planet.';
        $this->assertSame($long, cleanChapterParagraphText($long));
    }

    // ---- F8a/F8b: #chr-content br/p split --------------------------------

    private function chrPage(string $inner): string
    {
        return '<html><body><div id="chr-content">' . $inner . '</div></body></html>';
    }

    public function testEntitiesAreEncodedExactlyOnceInBrSplit(): void
    {
        $paragraphs = extractChapterParagraphs($this->chrPage('Tom &amp; Jerry<br>' . $this->story()));
        $this->assertSame('<p>Tom &amp; Jerry</p>', $paragraphs[0]);
    }

    public function testChrContentWithParagraphTagsIsSplit(): void
    {
        $inner = '';
        for ($i = 1; $i <= 12; $i++) {
            $inner .= "<p>Paragraph number {$i} of the chapter body.</p>";
        }
        $paragraphs = extractChapterParagraphs($this->chrPage($inner));
        $this->assertCount(12, $paragraphs);
        $this->assertSame('<p>Paragraph number 1 of the chapter body.</p>', $paragraphs[0]);
    }

    public function testBrSplitDropsWatermarks(): void
    {
        $inner = $this->story() . '<br>Read the latest chapters at n0velb1n.c0m<br>' . $this->story();
        $paragraphs = extractChapterParagraphs($this->chrPage($inner));
        $this->assertCount(2, $paragraphs);
        $this->assertStringNotContainsString('latest', implode('', $paragraphs));
    }

    // ---- F9: recursive walker --------------------------------------------

    private function walk(string $html): array
    {
        $result = [];
        (new Crawler('<html><body>' . $html . '</body></html>'))->filter('p')->each(function ($node) use (&$result) {
            foreach (chapterNodeParagraphs($node->getNode(0)) as $inner) {
                $paragraph = finalizeChapterParagraph($inner);
                if ($paragraph !== null) {
                    $result[] = $paragraph;
                }
            }
        });
        return $result;
    }

    public function testBrInsideParagraphSplitsIt(): void
    {
        $this->assertSame(['<p>Line one.</p>', '<p>Line two.</p>'], $this->walk('<p>Line one.<br>Line two.</p>'));
    }

    public function testInlineEmphasisIsKeptWithoutAttributes(): void
    {
        $this->assertSame(['<p>He <em>really</em> meant it</p>'], $this->walk('<p>He <em>really</em> meant it</p>'));
        $this->assertSame(
            ['<p><strong>Boom!</strong> The <i>wall</i> fell</p>'],
            $this->walk('<p><strong class="x" onclick="evil()">Boom!</strong> The <i style="a">wall</i> fell</p>')
        );
        // Other elements contribute text only.
        $this->assertSame(['<p>Go home now</p>'], $this->walk('<p>Go <a href="/x">home</a> <span>now</span></p>'));
    }

    public function testBreakInsideEmphasisKeepsBothHalvesBalanced(): void
    {
        $this->assertSame(
            ['<p>A <em>first</em></p>', '<p><em>second</em> B</p>'],
            $this->walk('<p>A <em>first<br>second</em> B</p>')
        );
    }

    public function testRecursiveTextIsEscapedOnce(): void
    {
        $this->assertSame(['<p>Tom &amp; Jerry &lt;3</p>'], $this->walk('<p>Tom &amp; Jerry &lt;3</p>'));
    }

    public function testRecursiveDropsWatermarkAndSpam(): void
    {
        $this->assertSame(
            ['<p>Real story line here.</p>'],
            $this->walk('<p>Real story line here.</p><p>Sponsored</p><p>Read latest at n0velb1n.c0m</p><p><script>var a=1;</script></p>')
        );
    }

    // ---- F31: leading chapter title --------------------------------------

    public function testInStoryHeadingWithOtherNumberIsKept(): void
    {
        $paras = ['<p>Chapter 3 - The Heavenly Art</p>', '<p>He opened the old manual and read.</p>'];
        $this->assertSame($paras, stripLeadingChapterTitle($paras, 120, 'Chapter 120: Return of the King'));
    }

    public function testOwnHeadingIsStripped(): void
    {
        $paras = ['<p>Chapter 120: Return of the King</p>', '<p>Story.</p>'];
        $this->assertSame(['<p>Story.</p>'], stripLeadingChapterTitle($paras, 120, 'Chapter 120: Return of the King'));

        // Second (translation) numbering carried by the label.
        $paras = ['<p>Chapter 1205: Trial</p>', '<p>Chapter 1150 - Trial</p>', '<p>Story.</p>'];
        $this->assertSame(['<p>Story.</p>'], stripLeadingChapterTitle($paras, 1205, 'Chapter 1205 - 1150 Trial'));
    }

    public function testHeadingFuzzyMatchingLabelIsStripped(): void
    {
        // Site numbering differs, but the heading is clearly the label.
        $paras = ['<p>Chapter 12: Return of the King</p>', '<p>Story.</p>'];
        $this->assertSame(['<p>Story.</p>'], stripLeadingChapterTitle($paras, 13, 'Chapter 13: Return of the King'));
    }

    public function testLabelTailParagraphIsStripped(): void
    {
        $paras = ["<p>Ren's Choice!</p>", '<p>Story.</p>'];
        $this->assertSame(['<p>Story.</p>'], stripLeadingChapterTitle($paras, 7, "Chapter 7: Ren's Choice!"));

        $paras = ['<p>Chapter 7</p>', "<p>Ren's Choice</p>", '<p>Story.</p>'];
        $this->assertSame(['<p>Story.</p>'], stripLeadingChapterTitle($paras, 7, "Chapter 7: Ren's Choice!"));
    }

    public function testNoNumberOrLabelKeepsHistoricBehaviour(): void
    {
        $paras = ['<p>Chapter 3 - Anything</p>', '<p>Story.</p>'];
        $this->assertSame(['<p>Story.</p>'], stripLeadingChapterTitle($paras));
    }

    // ---- Live-observed additions ------------------------------------------

    public function testLiveNovelFullWatermarks(): void
    {
        foreach ([
            'Read latest chapters at novelfull.me',
            'Read latest chapters at somesite.me',
            'novelfull.me',
            'www.some-site.com',
            'https://n0velfire.net/',
        ] as $line) {
            $this->assertNull(cleanChapterParagraphText($line), $line);
        }
        $this->assertSame('He nodded.', cleanChapterParagraphText('He nodded. Read latest chapters at foo.me'));
        $this->assertSame('He visited the capital.', cleanChapterParagraphText('He visited the capital.'));
    }

    public function testGluedNumberedTitlePrefixIsStripped(): void
    {
        $paras = ['<p>1600 Beast FarmThe morning sun rose over the pens.</p>', '<p>More.</p>'];
        $this->assertSame(
            ['<p>The morning sun rose over the pens.</p>', '<p>More.</p>'],
            stripLeadingChapterTitle($paras, 1600, 'Chapter 1600 Beast Farm')
        );
    }

    public function testBareNumberedTitleParagraphIsStripped(): void
    {
        $paras = ['<p>1600 Beast Farm</p>', '<p>The morning sun rose.</p>'];
        $this->assertSame(['<p>The morning sun rose.</p>'], stripLeadingChapterTitle($paras, 1600, 'Chapter 1600 - Beast Farm'));
        // Label without the word "Chapter".
        $this->assertSame(['<p>The morning sun rose.</p>'], stripLeadingChapterTitle($paras, 1600, '1600 Beast Farm'));
    }

    public function testGluedUnnumberedTitleIsStrippedCaseAndPunctuationInsensitive(): void
    {
        $paras = ['<p>beast farm!The morning sun rose.</p>'];
        $this->assertSame(['<p>The morning sun rose.</p>'], stripLeadingChapterTitle($paras, 1600, 'Chapter 1600: Beast Farm'));
    }

    public function testStorySentenceOpeningWithTitleWordsIsKept(): void
    {
        $label = 'Chapter 1600: Beast Farm';
        $paras = ['<p>Beast Farm was quiet that morning.</p>'];
        $this->assertSame($paras, stripLeadingChapterTitle($paras, 1600, $label));
        $paras = ['<p>Beast Farmers gathered at dawn.</p>'];
        $this->assertSame($paras, stripLeadingChapterTitle($paras, 1600, $label));
        // Someone else's number in front: not our heading.
        $paras = ['<p>1599 Beast FarmThe morning.</p>'];
        $this->assertSame($paras, stripLeadingChapterTitle($paras, 1600, $label));
    }
}
