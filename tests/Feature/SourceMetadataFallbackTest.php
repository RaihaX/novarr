<?php

namespace Tests\Feature;

use App\Novel;
use App\Sources\EmpireNovelSource;
use App\Sources\MetadataMerger;
use App\Sources\NovelArrowSource;
use App\Sources\NovelFullSource;
use App\Sources\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * cover_candidates carry no duplicates (first seen wins, compared after
 * trim), and NovelArrow falls back to the site's genres when NovelUpdates
 * has none, like EmpireNovel and NovelFull do.
 */
class SourceMetadataFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const NU_FULL = [
        'description' => 'NU desc', 'author' => 'NU author', 'no_of_chapters' => 120,
        'image' => 'https://nu.test/cover.jpg', 'genres' => ['Action', 'Drama'],
        'status_text' => 'Ongoing', 'completed' => false,
    ];

    private const SITE_FULL = [
        'description' => 'Site desc', 'author' => 'Site author', 'no_of_chapters' => 130,
        'image' => 'https://site.test/cover.jpg', 'genres' => ['Fantasy'],
    ];

    private static function stubbed(string $class, array $site, array $nu): Source
    {
        $source = match ($class) {
            EmpireNovelSource::class => new class extends EmpireNovelSource { use FallbackStubbedFetches; },
            NovelFullSource::class => new class extends NovelFullSource { use FallbackStubbedFetches; },
            NovelArrowSource::class => new class extends NovelArrowSource { use FallbackStubbedFetches; },
        };
        $source->siteData = $site;
        $source->nuData = $nu;

        return $source;
    }

    private static function novel(string $url): Novel
    {
        $novel = new Novel();
        $novel->forceFill(['name' => 'Some Novel', 'translator_url' => $url]);

        return $novel;
    }

    // ---- cover de-duplication ------------------------------------------------

    public function testUniqueCoversKeepsFirstSeenOrderAndComparesTrimmed(): void
    {
        $this->assertSame(
            ['https://b.test/1.jpg', ' https://a.test/2.jpg', 'https://A.test/2.jpg', 'http://a.test/2.jpg'],
            MetadataMerger::uniqueCovers([
                'https://b.test/1.jpg',
                ' https://a.test/2.jpg',   // first seen: kept untrimmed
                'https://a.test/2.jpg ',   // same after trim: dropped
                'https://A.test/2.jpg',    // case differs: kept (no normalising)
                'https://b.test/1.jpg',
                'http://a.test/2.jpg',     // scheme differs: kept
            ])
        );
        $this->assertSame([], MetadataMerger::uniqueCovers([]));
    }

    public function testEmpireNovelCoverCandidatesHaveNoDuplicates(): void
    {
        $source = self::stubbed(EmpireNovelSource::class,
            ['image' => 'https://same.test/c.jpg '] + self::SITE_FULL,
            ['image' => 'https://same.test/c.jpg'] + self::NU_FULL);

        $merged = $source->metadata(self::novel('https://www.empirenovel.com/novel/some-novel'));

        $this->assertSame(['https://same.test/c.jpg'], $merged['cover_candidates']);
    }

    public function testEmpireNovelKeepsDistinctCoversInPrecedenceOrder(): void
    {
        $source = self::stubbed(EmpireNovelSource::class, self::SITE_FULL, self::NU_FULL);

        $merged = $source->metadata(self::novel('https://www.empirenovel.com/novel/some-novel'));

        $this->assertSame(['https://nu.test/cover.jpg', 'https://site.test/cover.jpg'], $merged['cover_candidates']);
    }

    public function testNovelFullCoverCandidatesHaveNoDuplicates(): void
    {
        $source = self::stubbed(NovelFullSource::class,
            ['image' => 'https://same.test/c.jpg'] + self::SITE_FULL,
            ['image' => '  https://same.test/c.jpg'] + self::NU_FULL);

        $merged = $source->metadata(self::novel('https://novelfull.com/some-novel.html'));

        $this->assertSame(['https://same.test/c.jpg'], $merged['cover_candidates']);
    }

    public function testNovelFullKeepsDistinctCoversInPrecedenceOrder(): void
    {
        $source = self::stubbed(NovelFullSource::class, self::SITE_FULL, self::NU_FULL);

        $merged = $source->metadata(self::novel('https://novelfull.com/some-novel.html'));

        $this->assertSame(['https://site.test/cover.jpg', 'https://nu.test/cover.jpg'], $merged['cover_candidates']);
    }

    // ---- NovelArrow genres ---------------------------------------------------

    public function testNovelArrowUsesNovelUpdatesGenresWhenPresent(): void
    {
        $source = self::stubbed(NovelArrowSource::class, self::SITE_FULL, self::NU_FULL);

        $merged = $source->metadata(self::novel('https://novelping.com/novel/own-slug'));

        $this->assertSame(['Action', 'Drama'], $merged['genres']);
        // NovelUpdates was complete: the site is still never fetched.
        $this->assertSame(['novelupdates'], $source->log);
    }

    public function testNovelArrowFallsBackToSiteGenresWhenNovelUpdatesHasNone(): void
    {
        $source = self::stubbed(NovelArrowSource::class, self::SITE_FULL, ['genres' => []] + self::NU_FULL);

        $merged = $source->metadata(self::novel('https://novelping.com/novel/own-slug'));

        $this->assertSame(['Fantasy'], $merged['genres']);
        $this->assertSame(['novelupdates', 'site'], $source->log);
        // Only genres come from the site; the rest stays NovelUpdates'.
        $this->assertSame('NU desc', $merged['description']);
        $this->assertSame('NU author', $merged['author']);
        $this->assertSame(120, $merged['no_of_chapters']);
        $this->assertSame('https://nu.test/cover.jpg', $merged['image']);
        $this->assertSame(
            ['https://nu.test/cover.jpg', NovelArrowSource::coverUrlFor('own-slug')],
            $merged['cover_candidates']
        );
    }

    public function testNovelArrowFallsBackWhenNovelUpdatesGenresAreAbsent(): void
    {
        $nu = self::NU_FULL;
        unset($nu['genres']);
        $source = self::stubbed(NovelArrowSource::class, self::SITE_FULL, $nu);

        $merged = $source->metadata(self::novel('https://novelping.com/novel/own-slug'));

        $this->assertSame(['Fantasy'], $merged['genres']);
    }

    public function testNovelArrowGenresStayEmptyWhenNeitherSideHasAny(): void
    {
        $source = self::stubbed(NovelArrowSource::class, ['genres' => []] + self::SITE_FULL, ['genres' => []] + self::NU_FULL);

        $merged = $source->metadata(self::novel('https://novelping.com/novel/own-slug'));

        $this->assertSame([], $merged['genres']);
    }

    public function testNovelArrowGenreFallbackMatchesTheOtherAdapters(): void
    {
        // Same inputs through every adapter: NovelUpdates genres win when
        // present, the site's fill in when NovelUpdates has none.
        $cases = [
            EmpireNovelSource::class => 'https://www.empirenovel.com/novel/some-novel',
            NovelFullSource::class => 'https://novelfull.com/some-novel.html',
            NovelArrowSource::class => 'https://novelping.com/novel/own-slug',
        ];
        foreach ($cases as $class => $url) {
            $withNu = self::stubbed($class, ['genres' => []] + self::SITE_FULL, self::NU_FULL)->metadata(self::novel($url));
            $withoutNu = self::stubbed($class, self::SITE_FULL, ['genres' => []] + self::NU_FULL)->metadata(self::novel($url));

            $this->assertSame(['Action', 'Drama'], $withNu['genres'], $class);
            $this->assertSame(['Fantasy'], $withoutNu['genres'], $class);
        }
    }

    // ---- MetadataMerger 'ungated' -------------------------------------------

    public function testUngatedFieldFillsThroughAShutGateWithoutOpeningIt(): void
    {
        $fetched = [];
        $merged = MetadataMerger::merge(
            function () use (&$fetched) { $fetched[] = 'site'; return ['genres' => ['S'], 'author' => 'Site', 'image' => 'https://s.test/s.jpg']; },
            function () use (&$fetched) { $fetched[] = 'nu'; return ['genres' => [], 'author' => '', 'image' => 'https://n.test/n.jpg', 'description' => 'D']; },
            [
                'lazy' => true, 'base' => 'novelupdates', 'steps' => ['covers', 'fields'],
                'gate' => ['description'],
                'fields' => ['author' => 'novelupdates', 'genres' => 'novelupdates'],
                'ungated' => ['genres'],
                'covers' => ['novelupdates', 'site'],
            ]
        );

        $this->assertSame(['S'], $merged['genres']);
        $this->assertSame('', $merged['author']); // gated: not filled
        $this->assertSame(['https://n.test/n.jpg'], $merged['cover_candidates']);
        $this->assertSame(['nu', 'site'], $fetched);
    }
}

trait FallbackStubbedFetches
{
    public array $siteData = [];
    public array $nuData = [];
    public array $log = [];

    protected function siteMetadata(Novel $novel): array
    {
        $this->log[] = 'site';

        return $this->siteData;
    }

    protected function novelUpdatesMetadata(Novel $novel): array
    {
        $this->log[] = 'novelupdates';

        return $this->nuData;
    }
}
