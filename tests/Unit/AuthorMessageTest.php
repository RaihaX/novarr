<?php

namespace Tests\Unit;

use App\Console\Commands\ChapterScraper;
use Tests\TestCase;

/**
 * Numbered chapters whose whole body is a note from the author/translator
 * (apology, hiatus notice, thanks, series-complete message) are recognised
 * from the content, while locked/empty-page stubs stay rejected.
 */
class AuthorMessageTest extends TestCase
{
    private const APOLOGY = "<p>Translator: 549690339</p>\n"
        . "<p>I got into a fight with my daughter-in-law, and I was so upset that I couldn't write a book. I'm sorry.</p>";

    private const LOCKED = 'This chapter is locked. Please login or purchase coins to unlock.';

    private const NECROMANCER = "Catastrophic Necromancer has finally come to an end. It's been a great two-year journey. "
        . "Thank you to everyone who stuck with it and read all the way to the end it was a fun ride. "
        . "I'm open to suggestions for my next translation project, so don't be shy. If something catches my "
        . "interest, I'll take it on. To my paid subscribers: thank you so much for your support. I know that for "
        . "some of you it wasn't a small amount, and your help meant a lot to me during a difficult period in my "
        . "life. I truly appreciate it. I've also set up a new Ko-fi page, and hopefully it stays up: "
        . "https://ko-fi.com/tokisworld If anyone would like to donate, you're more than welcome. Overall, thank "
        . "you for accompanying me on this journey. Discord: https://discord.gg/rkMm6fvr";

    /** A realistic paraphrase of White Dragon Lord's ~200-word "Afterwords". */
    private static function afterwords(): string
    {
        return "<p>Hello everyone, this is the author. White Dragon Lord has finally reached its last page. "
            . "When I started writing this story I never imagined it would carry on for so long. Over the past "
            . "four years I wrote nearly every single day, sometimes late at night after work, sometimes early in "
            . "the morning before anyone else in my house was awake. All told the series came to a little over "
            . "two million words, which still feels unreal when I say it out loud.</p>"
            . "<p>I want to thank my editors, who caught countless mistakes and patiently pushed me whenever a "
            . "volume stalled, and the translators who carried the story to readers I could never have reached "
            . "on my own. Most of all I want to thank every reader who followed Rhys and his dragons from the "
            . "very first chapter to the final battle. Your messages kept me going through the hard months, and "
            . "there were quite a few of those.</p>"
            . "<p>I will rest for a little while, spend some time with my family, and then begin planning a new "
            . "world. There are already a few ideas I am excited about. Thank you again for reading this far. "
            . "Let's meet again in my next book.</p>";
    }

    private static function longChapter(int $words = 1200): string
    {
        $sentence = 'The sword came down and he rolled left while the blade bit stone where his head had been. ';
        $perSentence = str_word_count($sentence);
        $text = str_repeat($sentence, (int) ceil($words / $perSentence));

        return '<p>I am sorry, my friend, said the knight.</p><p>' . $text . '</p>';
    }

    // ---- looksLikeAuthorMessage: positives --------------------------------

    public function testRealTranslatorApologyIsAuthorMessage()
    {
        $this->assertTrue(ChapterScraper::looksLikeAuthorMessage(self::APOLOGY));
        // Same text as plain lines, no HTML.
        $this->assertTrue(ChapterScraper::looksLikeAuthorMessage(
            "Translator: 549690339\n\nI got into a fight with my daughter-in-law, and I was so upset that I couldn't write a book. I'm sorry."
        ));
    }

    public function testCommonAuthorNotesAreRecognised()
    {
        $notes = [
            'Sorry guys, no chapter today, I\'m sick. Will be back tomorrow.',
            "Author's note: thank you all for reading, see you in the next book.",
            "I'll be taking a short break for exams. Updates resume next week.",
            // Curly apostrophes as scraped from the web.
            "Sorry guys, no chapter today, I\u{2019}m sick. Will be back tomorrow.",
        ];

        foreach ($notes as $note) {
            $this->assertTrue(ChapterScraper::looksLikeAuthorMessage($note), "Should recognise: {$note}");
        }
    }

    public function testAfterwordsThankYouIsAuthorMessage()
    {
        $words = str_word_count(strip_tags(self::afterwords()));
        $this->assertGreaterThan(150, $words);
        $this->assertLessThanOrEqual(400, $words);
        $this->assertTrue(ChapterScraper::looksLikeAuthorMessage(self::afterwords()));
    }

    /** Ko-fi / Discord links and thanks to subscribers are neutral. */
    public function testSeriesCompleteMessageWithDonationLinksIsAuthorMessage()
    {
        $this->assertTrue(ChapterScraper::looksLikeAuthorMessage(self::NECROMANCER));
    }

    // ---- looksLikeAuthorMessage: negatives --------------------------------

    public function testSiteBoilerplateStubsAreNotAuthorMessages()
    {
        $stubs = [
            self::LOCKED,
            'Loading… please wait. If the chapter does not load, refresh the page.',
            'You are reading Chapter 407 at novelfull.com. Report issue. Next chapter.',
            'Chapter content not available. Please try again later.',
            // Boilerplate wins even with first-person apology vocabulary.
            "Sorry, I can't show this chapter: it is locked. Please login to unlock it.",
        ];

        foreach ($stubs as $stub) {
            $this->assertFalse(ChapterScraper::looksLikeAuthorMessage($stub), "Should reject: {$stub}");
        }
    }

    public function testOrdinaryShortProseIsNotAuthorMessage()
    {
        $prose = 'The sword came down. He rolled left, the blade biting stone where his head had been. '
            . 'Dust filled the hall. The guard shouted for help as the torches guttered and the doors slammed shut behind them.';
        $this->assertLessThanOrEqual(40, str_word_count($prose));
        $this->assertFalse(ChapterScraper::looksLikeAuthorMessage($prose));
    }

    public function testTooShortOrEmptyTextIsNotAuthorMessage()
    {
        $this->assertFalse(ChapterScraper::looksLikeAuthorMessage(''));
        $this->assertFalse(ChapterScraper::looksLikeAuthorMessage('<p></p>'));
        $this->assertFalse(ChapterScraper::looksLikeAuthorMessage("I'm sorry guys"));
    }

    public function testFullLengthChapterIsNotAuthorMessage()
    {
        $chapter = self::longChapter(1200);
        $this->assertGreaterThan(1200, str_word_count(strip_tags($chapter)));
        $this->assertFalse(ChapterScraper::looksLikeAuthorMessage($chapter));
    }

    // ---- gate + kind ------------------------------------------------------

    public function testGateAcceptsNumberedApologyChapter()
    {
        $this->assertTrue(ChapterScraper::acceptableWordCount('Chapter 1828 - 1828', 22, 250, self::APOLOGY));
        // Without the text the old behaviour stands.
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 1828 - 1828', 22, 250));
    }

    public function testGateStillRejectsLockedStub()
    {
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 1828 - 1828', 11, 250, self::LOCKED));
    }

    public function testGateAcceptsSeriesCompleteMessageWithItsRealLabel()
    {
        $words = str_word_count(self::NECROMANCER);
        $this->assertTrue(ChapterScraper::acceptableWordCount('Chapter 4851: COMPLETE', $words, 250, self::NECROMANCER));
        $this->assertSame('note', ChapterScraper::chapterKind('Chapter 4851: COMPLETE', $words, 250, self::NECROMANCER));
    }

    public function testGateAcceptsAfterwordsUnderANumberedLabel()
    {
        $words = str_word_count(self::afterwords());
        $this->assertTrue(ChapterScraper::acceptableWordCount('Chapter 1203', $words, 250, self::afterwords()));
        $this->assertSame('note', ChapterScraper::chapterKind('Chapter 1203', $words, 250, self::afterwords()));
    }

    public function testChapterKind()
    {
        $this->assertSame('note', ChapterScraper::chapterKind('Chapter 1828 - 1828', 22, 250, self::APOLOGY));
        // Short special label is also a note.
        $this->assertSame('note', ChapterScraper::chapterKind('Afterwords', 180, 250, null));
        // A proper chapter is never a note, whatever its text.
        $this->assertNull(ChapterScraper::chapterKind('Chapter 12', 2000, 250, self::longChapter(2000)));
        $this->assertNull(ChapterScraper::chapterKind('Chapter 12', 2000, 250, self::APOLOGY));
        // Rejected stub: no kind.
        $this->assertNull(ChapterScraper::chapterKind('Chapter 1828 - 1828', 11, 250, self::LOCKED));
    }

    // ---- completion-marker labels ----------------------------------------

    public function testCompletionMarkerLabelsAreSpecial()
    {
        foreach (['Chapter 4851: COMPLETE', 'Chapter 900 - The End', 'Finale', 'END', 'Chapter 77 – Completed',
                     'Chapter 3000: Fin.', 'Chapter 10 — Finished'] as $label) {
            $this->assertTrue(ChapterScraper::acceptableWordCount($label, 120), "Should accept: {$label}");
        }
    }

    public function testCompletionWordsMidTitleAreNotSpecial()
    {
        foreach (['Chapter 12: Complete Victory', 'Chapter 7: Final Battle', 'Chapter 8: The Endless Road',
                     'Chapter 9: Finishing Move'] as $label) {
            $this->assertFalse(ChapterScraper::acceptableWordCount($label, 120), "Should reject: {$label}");
        }
    }

    /** Lead review fixes: structural specials stay story, not notes. */
    public function testShortPrologueIsAcceptedButNotANote(): void
    {
        $prologue = str_repeat('<p>The city burned long before dawn, and nobody came to put it out.</p> ', 12); // ~150 words
        $this->assertTrue(ChapterScraper::acceptableWordCount('Chapter 0: Prologue', 150, 250, $prologue));
        $this->assertNull(ChapterScraper::chapterKind('Chapter 0: Prologue', 150, 250, $prologue));
        $this->assertNull(ChapterScraper::chapterKind('Side Story 2', 120, 250, $prologue));

        // Note-type labels are notes.
        $this->assertSame('note', ChapterScraper::chapterKind('Afterwords', 150, 250, $prologue));
        $this->assertSame('note', ChapterScraper::chapterKind('Chapter 4851: COMPLETE', 150, 250, $prologue));
    }

    /** Completion markers must be the whole subtitle, not a word in a title. */
    public function testCompletionMarkerMustBeWholeSubtitle(): void
    {
        foreach (['Chapter 4851: COMPLETE', 'Chapter 900 - The End', 'END', 'Finale', 'Ch. 12 Fin'] as $label) {
            $this->assertTrue(ChapterScraper::isNoteLabel($label), $label);
        }
        foreach (['Chapter 50: Dead End', 'Chapter 12: Complete Victory', 'Chapter 7: Final Battle', 'Chapter 3: The End of Days'] as $label) {
            $this->assertFalse(ChapterScraper::isNoteLabel($label), $label);
            $this->assertFalse(ChapterScraper::acceptableWordCount($label, 120, 250, null), $label);
        }
    }

    /** A truncated first-person chapter full of dialogue is prose, not a note. */
    public function testFirstPersonProseWithDialogueIsNotAnAuthorMessage(): void
    {
        $prose = '<p>"Sorry," I said, stepping back. "I didn\'t mean to."</p>'
            . '<p>"You never do," she snapped. "Thank you for nothing."</p>'
            . '<p>I looked at my hands. The blade was still there, and so was the blood.</p>'
            . '<p>"Then see you at the end," I told her, and walked out into the rain.</p>';

        $this->assertFalse(ChapterScraper::looksLikeAuthorMessage($prose));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 88', 60, 250, $prose));
    }

    /** A real translator's note that mentions "registered" (White Dragon Lord ch. 2281). */
    public function testTranslatorNoteMentioningRegisteredIsAccepted(): void
    {
        $text = "<p>Translator: 54969033</p><p>Recently, I was a little annoyed because the employees at wumiandian registered "
            . "their members under my name when they registered for membership. I also opened wechat to make a secret payment, "
            . "so they directly swiped my money when they bought things. Therefore, the author decided to fight to the death. "
            . "Today, he only went to work on the lawsuit and did not have the time to update it. I hope everyone can understand, "
            . "i'll make up for it in two days.</p>";
        $this->assertTrue(ChapterScraper::looksLikeAuthorMessage($text));
        $this->assertTrue(ChapterScraper::acceptableWordCount('Chapter 2281 - 2281', 107, 250, $text));
        $this->assertSame('note', ChapterScraper::chapterKind('Chapter 2281 - 2281', 107, 250, $text));
    }

    /** A speaker line does not rescue a genuinely locked page. */
    public function testSpeakerLineDoesNotRescueALockedPage(): void
    {
        $locked = "<p>Translator: Night</p><p>This chapter is locked. Please log in to unlock it with coins. Sorry for the inconvenience.</p>";
        $this->assertFalse(ChapterScraper::looksLikeAuthorMessage($locked));
        $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 300', 18, 250, $locked));

        // Only the generic words moved to phrases; "register to read" still rejects without a speaker.
        $stub = "<p>Please register to read the rest of this chapter. I am sorry for the wait.</p>";
        $this->assertFalse(ChapterScraper::looksLikeAuthorMessage($stub));
    }
}
