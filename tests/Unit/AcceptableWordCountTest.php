<?php

namespace Tests\Unit;

use App\Console\Commands\ChapterScraper;
use Tests\TestCase;

/**
 * Whether a fetched chapter is long enough to save. Numbered chapters must
 * clear the configured threshold; named special chapters (prologues, side
 * stories, extras) are legitimately short and only need to clear 50 words.
 */
class AcceptableWordCountTest extends TestCase
{
    /** A normal chapter comfortably over the default threshold. */
    public function testNormalChapterAboveThresholdIsAccepted()
    {
        $this->assertTrue(ChapterScraper::acceptableWordCount('Chapter 12', 2500));
        $this->assertTrue(ChapterScraper::acceptableWordCount('Chapter 12', 251));
    }

    /** Exactly at the threshold is not "greater than", so it is rejected. */
    public function testNormalChapterAtOrBelowThresholdIsRejected()
    {
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 12', 250));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 12', 0));
    }

    /** The real-world stubs: 23–111 words of junk on a numbered chapter. */
    public function testNumberedChapterWithStubContentIsRejected()
    {
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 407', 100));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 407: The Duel', 23));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 407: The Duel', 111));
    }

    /** A caller-supplied threshold replaces the 250 default in both directions. */
    public function testCustomThresholdIsUsed()
    {
        $this->assertTrue(ChapterScraper::acceptableWordCount('Chapter 12', 120, 100));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 12', 300, 500));
    }

    /** Named special chapters are short by nature but real. */
    public function testSpecialChaptersAreAcceptedAtFiftyWords()
    {
        $labels = [
            'Chapter 0: Prologue',
            'Prologue',
            'Epilogue',
            'Prelude',
            'Interlude 2',
            'Intermission',
            'Afterword',
            'Side Story 3',
            'Side-story: The Butler',
            'Extra 1',
            'Special Chapter — Author Q&A',
            'Announcement',
        ];

        foreach ($labels as $label) {
            $this->assertTrue(
                ChapterScraper::acceptableWordCount($label, 150),
                "Expected \"{$label}\" to be accepted at 150 words"
            );
            $this->assertTrue(
                ChapterScraper::acceptableWordCount($label, 50),
                "Expected \"{$label}\" to be accepted at the 50-word floor"
            );
        }
    }

    /** "Chapter 0" counts as special even without a name after it. */
    public function testChapterZeroIsSpecial()
    {
        $this->assertTrue(ChapterScraper::acceptableWordCount('Chapter 0', 150));
        $this->assertTrue(ChapterScraper::acceptableWordCount('chapter0', 150));

        // Not chapter zero — 10, 100, 0.5 are ordinary numbered chapters.
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 10', 150));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 100', 150));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 05', 150));
    }

    /** Below the 50-word floor even a prologue is an empty page, not content. */
    public function testSpecialChapterBelowFloorIsRejected()
    {
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 0: Prologue', 49));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Prologue', 0));
    }

    /** Word boundaries: "extraction"/"specialist" must not read as special. */
    public function testSubstringMatchesDoNotCountAsSpecial()
    {
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 88: Extraction', 150));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 89: The Specialist', 150));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 90: Prologues Aside', 150));
    }

    /** No label to judge by: the normal rule is all we have. */
    public function testNullOrEmptyLabelFallsBackToNormalRule()
    {
        $this->assertFalse(ChapterScraper::acceptableWordCount(null, 150));
        $this->assertFalse(ChapterScraper::acceptableWordCount('', 150));
        $this->assertFalse(ChapterScraper::acceptableWordCount('   ', 150));

        $this->assertTrue(ChapterScraper::acceptableWordCount(null, 500));
        $this->assertTrue(ChapterScraper::acceptableWordCount('', 500));
    }
}
