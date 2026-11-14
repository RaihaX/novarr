<?php

namespace Tests\Feature;

use App\Novel;
use App\Sources\EmpireNovelSource;
use App\Sources\NovelArrowSource;
use App\Sources\NovelFullSource;
use App\Sources\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterisation of each adapter's metadata() merge of its own site
 * metadata with the NovelUpdates metadata, pinned before the merge moved
 * into App\Sources\MetadataMerger. Every adapter runs over the full matrix
 * of input shapes (both present, one side empty, conflicting values, empty
 * strings vs nulls vs absent keys, falsy "0" strings) with both fetches
 * stubbed; the golden file records the merged array (values, types and key
 * order) and which fetches ran in which order.
 *
 * Regenerate only on purpose: GOLDEN_UPDATE=1 php artisan test --filter MetadataMergeCharacterisation
 */
class MetadataMergeCharacterisationTest extends GoldenTestCase
{
    use RefreshDatabase;

    public static function variants(): array
    {
        $empty = [
            'description' => '', 'author' => '', 'no_of_chapters' => 0, 'image' => '',
            'status_text' => '', 'completed' => false, 'fully_translated' => null, 'genres' => [],
            'title' => '', 'associated' => [], 'type' => '', 'original_language' => '',
        ];
        $fullA = [
            'description' => 'Desc A', 'author' => 'Author A', 'no_of_chapters' => 100, 'image' => 'https://a.test/a.jpg',
            'status_text' => 'Completed', 'completed' => true, 'fully_translated' => true, 'genres' => ['Action'],
            'title' => 'Title A', 'associated' => ['Alias A'], 'type' => 'Web Novel (CN)', 'original_language' => 'Chinese',
            'extra_a' => 'only on A',
        ];
        $fullB = [
            'description' => 'Desc B', 'author' => 'Author B', 'no_of_chapters' => '250', 'image' => 'https://b.test/b.jpg',
            'status_text' => '12 Chapters (Ongoing)', 'completed' => false, 'fully_translated' => false, 'genres' => ['Drama', 'Romance'],
            'title' => 'Title B', 'associated' => [], 'type' => 'Web Novel (KR)', 'original_language' => 'Korean',
            'tried_urls' => ['https://novelping.com/novel/b'],
        ];

        return [
            'full_a' => $fullA,
            'full_b' => $fullB,
            'empty_shape' => $empty,
            'nulls' => array_fill_keys(array_keys($empty), null),
            'absent' => [],
            'zero_strings' => ['description' => '0', 'author' => '0', 'no_of_chapters' => '0', 'image' => '0',
                'status_text' => '0', 'completed' => true, 'fully_translated' => '0', 'genres' => ['0'], 'title' => '0'],
            'desc_only' => ['description' => 'Desc P', 'completed' => true] + $empty,
            'no_status' => ['status_text' => '', 'completed' => false] + $fullA,
            'media_only' => ['description' => '', 'author' => null, 'no_of_chapters' => 7, 'image' => 'https://m.test/m.png',
                'genres' => ['Fantasy'], 'status_text' => 'Ongoing', 'completed' => 'yes'],
        ];
    }

    public static function adapters(): array
    {
        return [
            'empirenovel' => [EmpireNovelSource::class, 'https://www.empirenovel.com/novel/some-novel'],
            'novelfull' => [NovelFullSource::class, 'https://novelfull.com/some-novel.html'],
            'novelarrow' => [NovelArrowSource::class, 'https://example.test/novel/some-novel'],
            'novelarrow-own-url' => [NovelArrowSource::class, 'https://novelping.com/novel/own-slug_'],
        ];
    }

    /** The adapter with both fetches stubbed; its $log records the fetch order. */
    public static function stubbed(string $class, array $site, array $nu): Source
    {
        $source = match ($class) {
            EmpireNovelSource::class => new class extends EmpireNovelSource { use StubbedMetadataFetches; },
            NovelFullSource::class => new class extends NovelFullSource { use StubbedMetadataFetches; },
            NovelArrowSource::class => new class extends NovelArrowSource { use StubbedMetadataFetches; },
        };
        $source->siteData = $site;
        $source->nuData = $nu;

        return $source;
    }

    #[DataProvider('adapters')]
    public function testMergeMatrix(string $class, string $url): void
    {
        $novel = new Novel();
        $novel->forceFill(['name' => 'Some Novel', 'translator_url' => $url, 'group_id' => 1]);

        $actual = [];
        foreach (self::variants() as $siteName => $site) {
            foreach (self::variants() as $nuName => $nu) {
                $source = self::stubbed($class, $site, $nu);
                $merged = $source->metadata($novel);
                $actual["site={$siteName} nu={$nuName}"] = [
                    'fetches' => $source->log,
                    'result' => $merged,
                ];
            }
        }

        // JSON round trip so the comparison sees exactly what the file holds.
        $actual = json_decode(json_encode($actual), true);
        $key = basename(str_replace('\\', '/', $class)) . (str_contains($url, 'novelping') ? '-own-url' : '');
        $expected = $this->golden("metadata-merge/{$key}.expected.json", $actual);

        foreach ($expected as $case => $row) {
            $this->assertArrayHasKey($case, $actual);
            $this->assertSame($row, $actual[$case], "{$key}: {$case}");
        }
        $this->assertSame(array_keys($expected), array_keys($actual));
    }
}

trait StubbedMetadataFetches
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
