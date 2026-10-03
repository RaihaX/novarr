<?php

namespace Tests\Unit;

use App\Console\Commands\ChapterCleaner;
use App\Console\Commands\CleanChapterContent as C;
use Tests\TestCase;

/**
 * Audit F4/F15: content cleaners must not destroy story text.
 */
class CleanChapterContentTest extends TestCase
{
    /** N story paragraphs of ~12 words each. */
    private function story(int $n, string $prefix = 'Line'): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = "<p>{$prefix} {$i}: the wind howled across the mountain pass as the disciples climbed.</p>";
        }
        return $out;
    }

    public function testSponsoredInProseIsKept()
    {
        $paras = $this->story(10);
        array_splice($paras, 1, 0, ['<p>The Azure Sect sponsored the tournament, and every elder attended.</p>']);
        $html = implode('', $paras);

        [$cleaned, $stats] = C::cleanDescription($html);

        $this->assertSame($html, $cleaned);
        $this->assertFalse($stats['tail_truncated']);
        $this->assertFalse($stats['refused']);
    }

    public function testSponsoredProseInTailIsKept()
    {
        $paras = $this->story(10);
        $paras[] = '<p>In the end it was the merchant guild that sponsored his journey home.</p>';
        $html = implode('', $paras);

        [$cleaned] = C::cleanDescription($html);
        $this->assertSame($html, $cleaned);
    }

    public function testWidgetBlockInTailIsTruncated()
    {
        $story = $this->story(10);
        $html = implode('', $story) . '<p>Sponsored</p><p>You Won\'t Believe This Read MoreUndo</p><p>Promoted Content</p>';

        [$cleaned, $stats] = C::cleanDescription($html);

        $this->assertSame(implode('', $story), $cleaned);
        $this->assertTrue($stats['tail_truncated']);
    }

    public function testWidgetParagraphEarlyIsDroppedButStoryAfterItKept()
    {
        $story = $this->story(10);
        $withWidget = $story;
        array_splice($withWidget, 2, 0, ['<p>Sponsored Content</p>']);

        [$cleaned, $stats] = C::cleanDescription(implode('', $withWidget));

        $this->assertSame(implode('', $story), $cleaned);
        $this->assertFalse($stats['tail_truncated']);
        $this->assertTrue($stats['widget_removed']);
    }

    public function testStraySponsoredBeforeShortDialogueDoesNotTruncate()
    {
        // Dialogue lines are short (card-shaped) too; one marker isn't a block.
        $dialogue = [];
        for ($i = 1; $i <= 12; $i++) {
            $dialogue[] = "<p>\"Line {$i}, master?\" he asked quietly.</p>";
        }
        $html = implode('', array_slice($dialogue, 0, 3)) . '<p>Sponsored</p>' . implode('', array_slice($dialogue, 3));

        [$cleaned, $stats] = C::cleanDescription($html);

        $this->assertSame(implode('', $dialogue), $cleaned);
        $this->assertFalse($stats['tail_truncated']);
        $this->assertTrue($stats['widget_removed']);
    }

    public function testTaboolaAndOutbrainAreWholeTokens()
    {
        $this->assertTrue(C::isWidgetText('Taboola Feed'));
        $this->assertTrue(C::isWidgetText('Recommended by Outbrain'));
        $this->assertTrue(C::isWidgetText('Sponsored'));
        $this->assertTrue(C::isWidgetText('promoted content'));
        $this->assertTrue(C::isWidgetText('Play NowUndo'));
        $this->assertFalse(C::isWidgetText('Sponsored by the Azure Sect'));
        $this->assertFalse(C::isWidgetText('The taboolarian sect'));
        $this->assertFalse(C::isWidgetText(str_repeat('word ', 20) . 'taboola'));
    }

    public function testCssParagraphsAreStillRemoved()
    {
        $story = $this->story(6);
        $html = '<p>.pf-config-x { color: red !important; }</p>' . implode('', $story);

        [$cleaned, $stats] = C::cleanDescription($html);

        $this->assertSame(implode('', $story), $cleaned);
        $this->assertTrue($stats['css_removed']);
        $this->assertFalse($stats['refused']);
    }

    public function testGluedWidgetInTailParagraphIsCut()
    {
        $story = $this->story(9);
        $last = "<p>He closed the door and slept until the morning bell rang out.\n\n        Sponsored\n        Ten Tricks Doctors Hate Read MoreUndo</p>";
        [$cleaned, $stats] = C::cleanDescription(implode('', $story) . $last);

        $this->assertSame(implode('', $story) . '<p>He closed the door and slept until the morning bell rang out.</p>', $cleaned);
        $this->assertTrue($stats['tail_truncated']);
    }

    public function testShortChapterWithTrailingMarkerIsCleanedNotRefused()
    {
        // Trailing lone "Sponsored": dropped; the 3-word opener is all the story.
        [$cleaned, $stats] = C::cleanDescription('<p>Short opener here.</p><p>Sponsored</p>');
        $this->assertFalse($stats['refused']);
        $this->assertSame('<p>Short opener here.</p>', $cleaned);
    }

    public function testLossGuardThresholds()
    {
        $this->assertTrue(C::losesTooMuch(100, 49));
        $this->assertFalse(C::losesTooMuch(100, 50));
        $this->assertTrue(C::losesTooMuch(10, 0));
        $this->assertFalse(C::losesTooMuch(0, 0));
    }

    public function testRefusesWhenWidgetBlockWouldSwallowLongStoryParagraphs()
    {
        $long = '<p>' . str_repeat('The disciples climbed the endless stairs. ', 15) . '</p>';
        $html = '<p>A short opening line of the chapter.</p>'
            . '<p>Sponsored</p><p>Ten Tricks Doctors Hate Read MoreUndo</p><p>Promoted</p>'
            . $long . $long;
        // Block = 6 paragraphs, 4 card-shaped (67%) with 3 markers -> would
        // truncate, but that drops ~180 story words and keeps 7.
        [$cleaned, $stats] = C::cleanDescription($html);

        $this->assertTrue($stats['refused']);
        $this->assertSame('too_much_loss', $stats['reason']);
        $this->assertSame($html, $cleaned);
    }

    public function testFifteenCardTaboolaBlockAfterLongStoryIsTruncated()
    {
        $story = $this->story(40);
        $cards = ['<p>Sponsored</p>'];
        for ($i = 1; $i <= 14; $i++) {
            $cards[] = $i % 2
                ? "<p>Doctors stunned: this one weird trick number {$i} melts belly fat overnight Read MoreUndo</p>"
                : "<p>Brand {$i}</p>";
        }
        [$cleaned, $stats] = C::cleanDescription(implode('', $story) . implode('', $cards));

        $this->assertSame(implode('', $story), $cleaned);
        $this->assertTrue($stats['tail_truncated']);
        $this->assertFalse($stats['refused']);
    }

    public function testLongReadMoreUndoLineIsDetectedAtAnyLength()
    {
        $line = 'Doctors stunned: a retired nurse from Ohio shares the one thing she does every morning before breakfast Read MoreUndo';
        $this->assertGreaterThan(60, strlen($line));
        $this->assertTrue(C::isWidgetText($line));
        $this->assertTrue(C::isWidgetText(str_repeat('x', 200) . ' Play NowUndo'));
    }

    public function testAllWidgetBodyIsRefusedAsAllWidget()
    {
        $html = '<p>Sponsored</p><p>Ten Tricks Doctors Hate Read MoreUndo</p><p>Brand X</p>';
        [$cleaned, $stats] = C::cleanDescription($html);

        $this->assertTrue($stats['refused']);
        $this->assertSame('all_widget', $stats['reason']);
        $this->assertSame($html, $cleaned);
    }

    public function testRefusesGluedCutThatWouldDropMostOfTheStory()
    {
        // Single paragraph: a stray "Sponsored" line after a gap near the top,
        // then the actual story. Cutting there would keep ~0 words.
        $html = "<p>Hi.\n\n    Sponsored\n    " . str_repeat('The disciples climbed on. ', 40) . '</p>';
        [$cleaned, $stats] = C::cleanDescription($html);

        $this->assertTrue($stats['refused']);
        $this->assertSame('too_much_loss', $stats['reason']);
        $this->assertSame($html, $cleaned);
        $this->assertGreaterThan(100, $stats['words_before']);
    }

    public function testRefusalReturnsOriginalUnchanged()
    {
        // Single paragraph that is entirely a widget: cleaning would empty it.
        $html = '<p>Sponsored</p>';
        [$cleaned, $stats] = C::cleanDescription($html);

        $this->assertTrue($stats['refused']);
        $this->assertSame('all_widget', $stats['reason']);
        $this->assertSame($html, $cleaned);
        $this->assertSame(1, $stats['words_before']);
        $this->assertSame(0, $stats['words_after']);
    }

    public function testCleaningIsIdempotent()
    {
        $html = '<p>.pf-config-x{a:b !important}</p>' . implode('', $this->story(10))
            . '<p>Sponsored</p><p>Read MoreUndo</p>';

        [$once] = C::cleanDescription($html);
        [$twice, $stats] = C::cleanDescription($once);

        $this->assertSame($once, $twice);
        $this->assertFalse($stats['tail_truncated'] || $stats['css_removed'] || $stats['widget_removed']);
    }

    public function testContentWithoutParagraphsIsUntouched()
    {
        [$cleaned, $stats] = C::cleanDescription('plain text Sponsored');
        $this->assertSame('plain text Sponsored', $cleaned);
        $this->assertFalse($stats['refused']);
    }

    // ---- F15: ChapterCleaner judges by words, not "<p>" count -------------

    public function testChapterCleanerUsesWordCountNotParagraphCount()
    {
        // 5 long paragraphs with attributes: old rule (<= 10 literal "<p>") reset it.
        $long = str_repeat('<p class="x">' . str_repeat('word ', 80) . '</p>', 5);
        $this->assertFalse(ChapterCleaner::isThin('Chapter 3', $long, 250));

        // 30 tiny paragraphs of junk: plenty of <p> but too few words.
        $junk = str_repeat('<p>Loading</p>', 30);
        $this->assertTrue(ChapterCleaner::isThin('Chapter 3', $junk, 250));
    }

    public function testChapterCleanerKeepsShortSpecials()
    {
        $short = '<p>' . str_repeat('word ', 120) . '</p>';
        $this->assertFalse(ChapterCleaner::isThin('Side Story 2', $short, 250));
        $this->assertTrue(ChapterCleaner::isThin('Chapter 2', $short, 250));
    }

    public function testChapterCleanerWordCountDecodesEntities()
    {
        // "&amp;" must not count as the word "amp".
        $this->assertSame(2, ChapterCleaner::wordCount('<p>one &amp; two</p>'));
        $this->assertSame(0, ChapterCleaner::wordCount(''));
    }
}
