<?php

namespace Tests\Unit;

use App\Console\Commands\ChapterScraper;
use PHPUnit\Framework\TestCase;

/**
 * Review finding B1: a failure reason the stats table didn't know ("exception")
 * raised an undefined-key warning that Laravel turned into an exception and
 * aborted the whole sweep. The summary must also explain the new reasons.
 */
class ScrapeFailureAccountingTest extends TestCase
{
    public function testNotFoundAndExceptionAreSummarised(): void
    {
        $this->assertStringContainsString(
            '404',
            ChapterScraper::summarizeScrapeIssue(['not_found' => 3])
        );
        $this->assertStringContainsString(
            'error while parsing',
            ChapterScraper::summarizeScrapeIssue(['exception' => 2, 'fetch_failed' => 1])
        );
        // Mixed with stubs, the stub explanation still wins as before.
        $this->assertStringContainsString(
            'words',
            ChapterScraper::summarizeScrapeIssue(['not_found' => 1, 'short_content' => 2, 'short_min' => 40, 'short_max' => 90])
        );
    }

    public function testNotFoundIsNotABlockingFailure(): void
    {
        $this->assertFalse(ChapterScraper::isBlockingFailure('not_found'));
        $this->assertFalse(ChapterScraper::isBlockingFailure('exception'));
        $this->assertTrue(ChapterScraper::isBlockingFailure('cloudflare'));
        $this->assertTrue(ChapterScraper::isBlockingFailure('fetch_failed'));
    }

    /** Review finding H2: paywall / advance-release stubs are not author notes. */
    public function testPaywallStubsAreNotAuthorMessages(): void
    {
        $stubs = [
            'This chapter is available early on my Patreon. Thank you for your support! See you next week.',
            'Advance chapters are on Ko-fi. Sorry for the wait, the rest of this chapter will be posted tomorrow.',
            'I am sorry, the full chapter unlocks in 24 hours. Thanks for reading.',
            'Early access for supporters only. The rest of the chapter will be uploaded soon, sorry!',
        ];
        foreach ($stubs as $stub) {
            $this->assertFalse(ChapterScraper::looksLikeAuthorMessage($stub), $stub);
            $this->assertFalse(ChapterScraper::acceptableWordCount('Chapter 512 - The Duel', 22, 250, $stub), $stub);
        }

        // A completion note that merely mentions Ko-fi/Discord is still a note.
        $note = "Catastrophic Necromancer has finally come to an end. Thank you to everyone who stuck with it. "
            . "I've set up a new Ko-fi page: https://ko-fi.com/x If anyone would like to donate, you're welcome. Discord: https://discord.gg/y";
        $this->assertTrue(ChapterScraper::looksLikeAuthorMessage($note));
    }
}
