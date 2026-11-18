<?php

namespace Tests\Feature;

use App\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFetcher;

/**
 * Characterisation of the Discover page per source, pinned before the
 * source registry refactor: the rendered source picker (keys, labels, order,
 * hosts), and the JSON the browse endpoint returns for every source × list
 * type against faked upstream answers, including the library marking (slug
 * on either NovelPing host, URL, name), upstream failures, validation and
 * the cache keys (kept so a deploy reuses warm caches).
 */
class DiscoverSourcesCharacterisationTest extends GoldenTestCase
{
    use RefreshDatabase;

    private const NA_LIST = 'https://novelping.com/api-web/novels?';
    private const EN_SEARCH = 'https://www.empirenovel.com/search-live?q=';
    private const NF_SEARCH = 'https://novelfull.com/search?keyword=';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function upstream(bool $failing = false): FakeFetcher
    {
        $list = json_encode(['items' => [
            ['novel_id' => 'shadow-slave', 'novel_name' => 'Shadow Slave', 'novel_author' => 'Guiltythree', 'novel_desc' => '<p>Growing up in poverty.</p><p>Sunny.</p>', 'novel_status' => 0, 'totalChapter' => 3207],
            ['novel_id' => 'lord-of-the-mysteries', 'novel_name' => 'Lord of the Mysteries', 'novel_author' => 'Cuttlefish', 'novel_status' => 1, 'totalChapter' => '0'],
            ['novel_id' => 'free-novel', 'novel_name' => '  A Free Novel ', 'novel_author' => ' Someone '],
            ['novel_id' => '', 'novel_name' => 'No slug'],
        ]]);

        if ($failing) {
            return $this->fake([], [
                self::NA_LIST => FakeFetcher::fail('fetch_failed', 500),
                self::EN_SEARCH => FakeFetcher::fail('flaresolverr_error', null),
                self::NF_SEARCH => FakeFetcher::fail('flaresolverr_error', null),
            ]);
        }

        return $this->fake([], [
            self::NA_LIST => $list,
            self::EN_SEARCH => 'empirenovel/search-live-shadow.html',
            self::NF_SEARCH => 'novelfull/search-shadow-slave.html',
        ]);
    }

    private function library(): void
    {
        // Old host + trailing underscore (slug match), a legacy novelbin URL
        // (slug match, legacy), an Empire Novel URL and a novelfull URL
        // (normalised URL match), and a name-only match.
        Novel::create(['name' => 'Shadow Slave (WN)', 'group_id' => 1, 'translator_url' => 'https://novelarrow.com/novel/shadow-slave_']);
        Novel::create(['name' => 'Legacy Bin', 'group_id' => 1, 'translator_url' => 'https://novelbin.me/novel-book/free-novel']);
        Novel::create(['name' => 'Empire Copy', 'group_id' => 1, 'translator_url' => 'http://empirenovel.com/novel/shadow-hunter/']);
        Novel::create(['name' => 'NF Copy', 'group_id' => 1, 'translator_url' => 'https://www.novelfull.com/shadow-slave.html']);
        Novel::create(['name' => 'Lord Of The Mysteries!', 'group_id' => 1, 'translator_url' => 'https://example.test/lotm']);
    }

    public function testPageRendersTheSourcePickerInTheOriginalOrder(): void
    {
        // Separate libraries: "Add novel" (the default) offers the text
        // sources only, "Add manga" the manga ones (MangaDex).
        $html = $this->get('/novels/discover')->assertOk()->getContent();

        $this->assertStringContainsString(
            "<select id=\"discoverSource\" class=\"form-select\" aria-label=\"Source\">\n"
            . "        <option value=\"novelarrow\">NovelPing (novelarrow)</option>\n"
            . "        <option value=\"empirenovel\">empirenovel.com</option>\n"
            . "        <option value=\"novelfull\">novelfull.com</option>\n"
            . "    </select>",
            $html
        );
        $this->assertStringContainsString(
            'const SOURCE_HOST = {"novelarrow":"novelping.com","empirenovel":"empirenovel.com","novelfull":"novelfull.com"};',
            $html
        );
        $manga = $this->get('/novels/discover?medium=manga')->assertOk()->getContent();
        $this->assertStringContainsString(
            "<select id=\"discoverSource\" class=\"form-select\" aria-label=\"Source\">\n"
            . "        <option value=\"mangadex\">MangaDex (manga)</option>\n"
            . "    </select>",
            $manga
        );
        // Popular / Completed tabs, Popular active, loaded on open.
        $this->assertStringContainsString(
            '<button type="button" class="btn btn-secondary discover-tab active" data-type="popular">Popular</button>' . "\n"
            . '        <button type="button" class="btn btn-secondary discover-tab" data-type="new">New</button>' . "\n"
            . '        <button type="button" class="btn btn-secondary discover-tab" data-type="random" title="A random page of the catalogue; click again for another">Random</button>' . "\n"
            . '        <button type="button" class="btn btn-secondary discover-tab" data-type="completed">Completed</button>',
            $html
        );
        $this->assertStringContainsString("loadList('popular');\n\n})();", $html);
    }

    public static function browseCases(): array
    {
        $cases = [];
        foreach (['novelarrow', 'empirenovel', 'novelfull', null] as $source) {
            foreach (['popular', 'completed', 'search'] as $type) {
                $cases[($source ?? 'default') . '-' . $type] = [$source, $type];
            }
        }

        return $cases;
    }

    #[DataProvider('browseCases')]
    public function testBrowseJson(?string $source, string $type): void
    {
        $fetcher = $this->upstream();
        $this->library();

        $query = ['type' => $type] + ($source !== null ? ['source' => $source] : []) + ($type === 'search' ? ['q' => 'shadow slave'] : []);
        $response = $this->getJson('/novels/discover/browse?' . http_build_query($query));

        $actual = [
            'status' => $response->status(),
            // Library ids differ between databases (auto-increment is not
            // reset on MariaDB), so the link's id is not part of the golden.
            'json' => json_decode(preg_replace('#/novels\\\\?/\d+"#', '/novels/{id}"', (string) $response->getContent()), true),
            'fetches' => $fetcher->calls,
        ];
        $expected = $this->golden('discover/browse-' . ($source ?? 'default') . '-' . $type . '.expected.json', $actual);
        $this->assertSame($expected, $actual);
    }

    #[DataProvider('browseCases')]
    public function testBrowseUpstreamFailure(?string $source, string $type): void
    {
        $this->upstream(failing: true);

        $query = ['type' => $type] + ($source !== null ? ['source' => $source] : []) + ($type === 'search' ? ['q' => 'shadow'] : []);
        $response = $this->getJson('/novels/discover/browse?' . http_build_query($query));

        $actual = ['status' => $response->status(), 'json' => $response->json()];
        $expected = $this->golden('discover/failure-' . ($source ?? 'default') . '-' . $type . '.expected.json', $actual);
        $this->assertSame($expected, $actual);
    }

    public function testValidation(): void
    {
        $this->upstream();

        $this->getJson('/novels/discover/browse?type=popular&source=webnovel')->assertStatus(422)->assertJsonValidationErrors('source');
        $this->getJson('/novels/discover/browse?type=latest&source=novelarrow')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->getJson('/novels/discover/browse?type=search&source=novelfull')->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function testCacheKeysAreUnchanged(): void
    {
        $this->upstream();

        $this->getJson('/novels/discover/browse?type=popular&source=novelarrow')->assertOk();
        $this->getJson('/novels/discover/browse?type=search&q=shadow&source=novelarrow')->assertOk();
        $this->getJson('/novels/discover/browse?type=search&q=shadow&source=empirenovel')->assertOk();
        $this->getJson('/novels/discover/browse?type=search&q=shadow&source=novelfull')->assertOk();

        $popular = self::NA_LIST . http_build_query(['status' => 'all', 'sort' => 'POPULAR', 'limit' => 40, 'page' => 1, 'genre' => 'ALL']);
        $search = self::NA_LIST . http_build_query(['status' => 'all', 'sort' => 'SEARCH_KEYWORD', 'keyword' => 'shadow', 'limit' => 40, 'page' => 1, 'genre' => 'ALL']);
        $this->assertTrue(Cache::has('discover_v5_' . md5($popular)));
        $this->assertTrue(Cache::has('discover_v5_' . md5($search)));
        $this->assertTrue(Cache::has('discover_en_v2_' . md5('shadow')));
        $this->assertTrue(Cache::has('discover_nf_' . md5('shadow')));
    }
}
