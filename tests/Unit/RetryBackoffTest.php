<?php

namespace Tests\Unit;

use App\Console\Commands\ChapterScraper;
use PHPUnit\Framework\TestCase;

/**
 * Per-chapter retry backoff (audit A7/F27): exponential from 10 minutes,
 * capped at 3 days; transient site-wide failures wait a flat 30 minutes.
 */
class RetryBackoffTest extends TestCase
{
    public function testExponentialBackoffTable(): void
    {
        $expected = [
            1 => 600,      // 10m
            2 => 1200,     // 20m
            3 => 2400,     // 40m
            4 => 4800,     // 80m
            5 => 9600,
            6 => 19200,
            7 => 38400,
            8 => 76800,
            9 => 153600,
            10 => 259200,  // capped at 72h
            11 => 259200,
            50 => 259200,
            1000 => 259200,
        ];

        foreach ($expected as $attempts => $seconds) {
            $this->assertSame($seconds, ChapterScraper::backoffSeconds($attempts, 'short_content'), "attempts={$attempts}");
            $this->assertSame($seconds, ChapterScraper::backoffSeconds($attempts, 'not_found'), "attempts={$attempts}");
        }
    }

    public function testZeroOrNegativeAttemptsUseTheFirstStep(): void
    {
        $this->assertSame(600, ChapterScraper::backoffSeconds(0, 'no_content'));
        $this->assertSame(600, ChapterScraper::backoffSeconds(-3, 'no_content'));
    }

    public function testTransientFailuresUseAFlatThirtyMinutes(): void
    {
        foreach (['cloudflare', 'fetch_failed'] as $category) {
            foreach ([1, 2, 8, 100] as $attempts) {
                $this->assertSame(1800, ChapterScraper::backoffSeconds($attempts, $category));
            }
        }
    }

    public function testNeedsReviewFromEightAttempts(): void
    {
        $this->assertFalse(ChapterScraper::isNeedsReview(0));
        $this->assertFalse(ChapterScraper::isNeedsReview(7));
        $this->assertTrue(ChapterScraper::isNeedsReview(8));
        $this->assertTrue(ChapterScraper::isNeedsReview(20));
    }
}
