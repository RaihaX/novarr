<?php

namespace Tests\Unit;

use App\Console\Commands\ChapterScraper;
use Tests\TestCase;

/**
 * The one-sentence explanation attached to a novel after an all-failed scrape
 * run — this is what the dashboard and the daily email show instead of the old
 * generic "the source site may have changed".
 */
class ScrapeIssueSummaryTest extends TestCase
{
    /** No failures at all: nothing to report. */
    public function testReturnsNullWhenThereWereNoFailures()
    {
        $this->assertNull(ChapterScraper::summarizeScrapeIssue([]));
        $this->assertNull(ChapterScraper::summarizeScrapeIssue([
            'bad_url' => 0,
            'fetch_failed' => 0,
            'cloudflare' => 0,
            'no_content' => 0,
            'short_content' => 0,
        ]));
    }

    /** Garbage pending rows from a bad TOC parse name the offending URL. */
    public function testBadUrlSummaryIncludesCountAndExample()
    {
        $summary = ChapterScraper::summarizeScrapeIssue([
            'bad_url' => 2,
            'example_bad_url' => 'https://novelfull.comArial, sans-serif',
        ]);

        $this->assertSame(
            '2 pending chapter(s) have an invalid source URL (e.g. "https://novelfull.comArial, sans-serif") '
                . '— the TOC scrape may have saved garbage entries',
            $summary
        );
    }

    /** A Cloudflare challenge is the site blocking us, not a layout change. */
    public function testCloudflareSummary()
    {
        $this->assertSame(
            'the source site is blocking fetches with a Cloudflare challenge',
            ChapterScraper::summarizeScrapeIssue(['cloudflare' => 1, 'no_content' => 2])
        );
    }

    /** Every chapter failed to fetch — network/FlareSolverr side. */
    public function testAllFetchFailedSummary()
    {
        $this->assertSame(
            'chapter pages could not be fetched',
            ChapterScraper::summarizeScrapeIssue(['fetch_failed' => 3])
        );
    }

    /** Mixed fetch failures fall through to the more diagnostic cause. */
    public function testPartialFetchFailuresFallThroughToNoContent()
    {
        $this->assertSame(
            'chapter pages load but contain no chapter text '
                . '— the chapters may be empty on the source or its layout changed',
            ChapterScraper::summarizeScrapeIssue(['fetch_failed' => 1, 'no_content' => 2])
        );
    }

    /** Pages that parse to nothing. */
    public function testNoContentSummary()
    {
        $this->assertSame(
            'chapter pages load but contain no chapter text '
                . '— the chapters may be empty on the source or its layout changed',
            ChapterScraper::summarizeScrapeIssue(['no_content' => 4])
        );
    }

    /** Stub chapters report the word range actually seen. */
    public function testShortContentSummaryReportsWordRange()
    {
        $this->assertSame(
            'chapter pages only contain 23–111 words (need >250) '
                . '— the source may only have stub chapters',
            ChapterScraper::summarizeScrapeIssue([
                'short_content' => 5,
                'short_min' => 23,
                'short_max' => 111,
            ])
        );
    }

    /** A single stub chapter reads as one number, not "23–23". */
    public function testShortContentSummaryCollapsesEqualBounds()
    {
        $this->assertSame(
            'chapter pages only contain 23 words (need >250) '
                . '— the source may only have stub chapters',
            ChapterScraper::summarizeScrapeIssue([
                'short_content' => 1,
                'short_min' => 23,
                'short_max' => 23,
            ])
        );
    }

    /** Priority: bad URLs beat every other cause, Cloudflare beats the rest. */
    public function testPriorityOrdering()
    {
        $all = [
            'bad_url' => 1,
            'example_bad_url' => 'https://novelfull.comArial, sans-serif',
            'fetch_failed' => 1,
            'cloudflare' => 1,
            'no_content' => 1,
            'short_content' => 1,
            'short_min' => 40,
            'short_max' => 90,
        ];

        $this->assertStringContainsString('invalid source URL', ChapterScraper::summarizeScrapeIssue($all));

        unset($all['bad_url'], $all['example_bad_url']);
        $this->assertStringContainsString('Cloudflare challenge', ChapterScraper::summarizeScrapeIssue($all));

        unset($all['cloudflare']);
        $this->assertStringContainsString('contain no chapter text', ChapterScraper::summarizeScrapeIssue($all));

        unset($all['no_content'], $all['fetch_failed']);
        $this->assertStringContainsString('only contain 40–90 words', ChapterScraper::summarizeScrapeIssue($all));
    }
}
