<?php

namespace Tests\Unit;

use App\Console\Commands\NovelScraper;
use PHPUnit\Framework\TestCase;

class ValidTocUrlTest extends TestCase
{
    public function testAbsoluteUrlIsValid(): void
    {
        $this->assertTrue(NovelScraper::validTocUrl(
            'https://novelfull.com/warlock-apprentice/chapter-1.html',
            'https://novelfull.com'
        ));
    }

    public function testRelativeUrlResolvesAgainstGroup(): void
    {
        $this->assertTrue(NovelScraper::validTocUrl(
            '/warlock-apprentice/chapter-1.html',
            'https://novelfull.com'
        ));
    }

    public function testCssFragmentScrapedAsChapterIsRejected(): void
    {
        // The real-world junk row: a font-family declaration parsed as a link.
        $this->assertFalse(NovelScraper::validTocUrl(
            'Arial, sans-serif',
            'https://novelfull.com'
        ));
    }

    public function testAbsoluteUrlWithWhitespaceIsRejected(): void
    {
        $this->assertFalse(NovelScraper::validTocUrl(
            'https://novelfull.comArial, sans-serif',
            'https://novelfull.com'
        ));
    }

    public function testEmptyUrlFallsBackToGroupPage(): void
    {
        // Matches pre-guard behavior: an item with no URL of its own is kept
        // as long as the group base URL is a real link.
        $this->assertTrue(NovelScraper::validTocUrl(null, 'https://novelfull.com'));
    }

    public function testEmptyUrlWithoutGroupIsRejected(): void
    {
        $this->assertFalse(NovelScraper::validTocUrl(null, ''));
        $this->assertFalse(NovelScraper::validTocUrl('', null));
    }
}
