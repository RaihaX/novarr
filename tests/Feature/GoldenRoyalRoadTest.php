<?php

namespace Tests\Feature;

use App\Console\Commands\NovelScraper;
use App\Group;
use App\Novel;
use App\NovelChapter;
use App\Scraping\ContentExtractor;
use App\Services\Discover;
use App\Sources\AbstractSource;
use App\Sources\EmpireNovelSource;
use App\Sources\MangaDexSource;
use App\Sources\NovelArrowSource;
use App\Sources\NovelFullSource;
use App\Sources\RoyalRoadSource;
use App\Sources\SourceResolver;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FakeFetcher;

/**
 * www.royalroad.com through the real adapter, against pages saved from the
 * live site on 2026-11-20. See tests/fixtures/royalroad/README.md.
 */
class GoldenRoyalRoadTest extends GoldenTestCase
{
    use RefreshDatabase;

    private const BASE = 'https://www.royalroad.com';
    private const MOL = self::BASE . '/fiction/21220/mother-of-learning';
    private const RUNESMITH = self::BASE . '/fiction/33844/the-runesmith';
    private const HWFWM = self::BASE . '/fiction/26294/he-who-fights-with-monsters';
    private const MOL_CH1 = self::MOL . '/chapter/301778/1-good-morning-brother';
    private const RUNESMITH_CH729 = self::RUNESMITH . '/chapter/4376982/chapter-729-taking-damage';
    private const SEARCH = self::BASE . '/fictions/search?title=';

    /** The anti-piracy sentences hidden (display: none) in the two chapter fixtures. */
    private const HIDDEN_SENTENCES = [
        'chapter-301778-good-morning-brother' => 'the author publishes this chapter on RoyalRoad, while this copy was made without consent',
        'chapter-4376982-taking-damage' => 'this copy came from the author\'s official page without permission',
    ];

    private function source(): RoyalRoadSource
    {
        return new RoyalRoadSource();
    }

    // ---- URLs and identity ----------------------------------------------------

    public function testFictionIdAndLibrarySlugAreTheNumericId(): void
    {
        $source = $this->source();

        foreach ([
            self::MOL,
            'https://royalroad.com/fiction/21220/mother-of-learning',
            'http://www.royalroad.com/fiction/21220',
            'www.royalroad.com/fiction/21220/mother-of-learning/',
            'https://WWW.RoyalRoad.com/fiction/21220/an-old-slug-the-author-renamed',
            self::MOL_CH1,
            self::MOL . '?tab=chapters#toc',
        ] as $url) {
            $this->assertSame(21220, RoyalRoadSource::fictionId($url), $url);
            $this->assertSame('21220', $source->librarySlug($url), $url);
            $this->assertSame('21220', $source->librarySlug($url, stored: true), $url);
            $this->assertTrue($source->matchesUrl($url), $url);
        }

        // Not a fiction: no id, no slug (the host is still Royal Road's).
        foreach ([self::BASE . '/profile/100374', self::BASE . '/fictions/weekly-popular', self::BASE . '/fiction/chapter/301778', self::BASE . '/fiction/abc/x'] as $url) {
            $this->assertNull(RoyalRoadSource::fictionId($url), $url);
            $this->assertNull($source->librarySlug($url), $url);
        }
        // Other sites, look-alike hosts.
        foreach (['https://novelfull.com/fiction/21220/x.html', 'https://notroyalroad.com/fiction/21220/x', 'https://royalroad.com.evil.test/fiction/21220/x'] as $url) {
            $this->assertNull(RoyalRoadSource::fictionId($url), $url);
            $this->assertNull($source->librarySlug($url), $url);
            $this->assertFalse($source->matchesUrl($url), $url);
        }
    }

    public function testFictionUrlIsCanonicalAndKeepsTheUrlsSlug(): void
    {
        $this->assertSame(self::MOL, RoyalRoadSource::fictionUrl('royalroad.com/fiction/21220/mother-of-learning'));
        $this->assertSame(self::MOL, RoyalRoadSource::fictionUrl(self::MOL_CH1));
        $this->assertSame(self::BASE . '/fiction/21220', RoyalRoadSource::fictionUrl('http://royalroad.com/fiction/21220'));
        // A stale slug is fetched as is: the site redirects it to the current one.
        $this->assertSame(self::BASE . '/fiction/21220/old-name', RoyalRoadSource::fictionUrl('https://royalroad.com/fiction/21220/old-name?x=1'));
        $this->assertNull(RoyalRoadSource::fictionUrl('https://novelfull.com/mother-of-learning.html'));
    }

    public function testRoyalRoadUrlsAreClaimedByThisSourceAndOthersKeepTheirs(): void
    {
        $this->fake([]);

        foreach ([self::MOL, 'https://royalroad.com/fiction/21220/x', self::MOL_CH1] as $url) {
            $this->assertInstanceOf(RoyalRoadSource::class, SourceResolver::forUrl($url), $url);
            $this->assertNotInstanceOf(NovelArrowSource::class, SourceResolver::forUrl($url), $url);
            $this->assertInstanceOf(RoyalRoadSource::class, SourceResolver::for($this->novel($url, 'Mother of Learning')), $url);
        }
        $this->assertSame('royalroad', SourceResolver::get('royalroad')?->name());

        // Registered before the catch-all, which stays last.
        $keys = array_keys(SourceResolver::all());
        $this->assertSame('novelarrow', end($keys));
        $this->assertLessThan(array_search('novelarrow', $keys, true), array_search('royalroad', $keys, true));

        // The other sources still claim their own URLs.
        $this->assertInstanceOf(NovelFullSource::class, SourceResolver::forUrl('https://novelfull.com/shadow-slave/chapter-1-nightmare-begins.html'));
        $this->assertInstanceOf(EmpireNovelSource::class, SourceResolver::forUrl('https://www.empirenovel.com/novel/shadow-slave/1'));
        $this->assertInstanceOf(MangaDexSource::class, SourceResolver::forUrl('https://mangadex.org/title/a1c7c817-4e59-43b7-9365-09675a149a6f'));
        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::forUrl('https://novelping.com/novel/shadow-slave'));
        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::forUrl('https://unknown.example/fiction/21220/x'));
    }

    /** A library novel stored under an old slug is the same fiction on Discover. */
    public function testDiscoverMatchesTheLibraryNovelByIdAfterASlugRename(): void
    {
        $this->fake([]);
        $novel = Novel::create(['name' => 'Mother of Learning (old title)', 'group_id' => 1, 'translator_url' => 'https://royalroad.com/fiction/21220/old-slug']);
        $discover = app(Discover::class);
        $library = $discover->libraryIndex();

        $this->assertSame($novel->id, $discover->match(['name' => 'A New Title', 'url' => self::MOL], $library));
        $this->assertNull($discover->match(['name' => 'Other', 'url' => self::RUNESMITH], $library));
    }

    // ---- Discover -------------------------------------------------------------

    public function testSearchItems(): void
    {
        $fetcher = $this->fake([self::SEARCH . 'mother+of+learning' => 'royalroad/search-mother-of-learning.html']);

        $items = $this->source()->discover('search', 'mother of learning');
        $expected = $this->golden('royalroad/search-mother-of-learning.expected.json', $items);

        $this->assertSame($expected, $items);
        $this->assertCount(20, $items);
        // Search answers a plain request with the app's browser headers: no FlareSolverr.
        $this->assertSame([self::SEARCH . 'mother+of+learning'], $fetcher->urls('plain'));
        $this->assertSame([], $fetcher->urls('html'));

        $first = $items[0];
        $this->assertSame(['name', 'url', 'cover', 'author', 'description', 'status', 'chapters'], array_keys($first));
        $this->assertSame('Mother of Learning', $first['name']);
        $this->assertSame(self::MOL, $first['url']);
        $this->assertSame('https://www.royalroadcdn.com/public/covers-full/21220-mother-of-learning.webp?time=1637247458', $first['cover']);
        $this->assertSame('', $first['author'], 'the list does not show authors');
        $this->assertSame('Completed', $first['status']);
        $this->assertSame(109, $first['chapters']);
        $this->assertStringStartsWith('Zorian is a teenage mage of humble birth', $first['description']);

        foreach ($items as $item) {
            $this->assertMatchesRegularExpression('#^https://www\.royalroad\.com/fiction/\d+/[a-z0-9-]+$#', $item['url']);
            $this->assertNotSame('', $item['name']);
        }
    }

    public function testAnEmptyResultPageIsAnEmptyList(): void
    {
        $this->fake([self::SEARCH . 'qzxjvwqkbt' => 'royalroad/search-no-results.html']);

        $this->assertSame([], $this->source()->discover('search', 'qzxjvwqkbt'));
    }

    public function testUnreachableOrChallengedSiteIsNull(): void
    {
        $this->fake([
            self::SEARCH . 'down' => FakeFetcher::fail('flaresolverr_error', null),
            self::SEARCH . 'blocked' => FakeFetcher::fail('http_4xx', 403),
            self::SEARCH . 'challenge' => '<html><head><title>Just a moment...</title></head><body>cf-challenge-running</body></html>',
            self::BASE . '/fictions/weekly-popular' => FakeFetcher::fail('rate_limited', 429),
        ]);
        $source = $this->source();

        $this->assertNull($source->discover('search', 'down'));
        $this->assertNull($source->discover('search', 'blocked'));
        $this->assertNull($source->discover('search', 'challenge'));
        $this->assertNull($source->discover('popular'));
        // A list the source doesn't offer: nothing, no request.
        $this->assertSame([], $source->discover('random'));
    }

    public function testPopularAndCompletedLists(): void
    {
        $fetcher = $this->fake([
            self::BASE . '/fictions/weekly-popular' => 'royalroad/weekly-popular.html',
            self::BASE . '/fictions/complete' => 'royalroad/complete.html',
        ]);
        $source = $this->source();
        // Served, but not offered on Discover (see discoverLists()).
        $this->assertSame(['search'], $source->discoverLists());

        $popular = $source->discover('popular');
        $this->assertCount(20, $popular);
        $this->assertSame(['name' => 'Gifted', 'url' => self::BASE . '/fiction/181303/gifted', 'status' => 'Ongoing', 'chapters' => 128],
            array_intersect_key($popular[0], array_flip(['name', 'url', 'status', 'chapters'])));
        $runesmith = array_values(array_filter($popular, fn($i) => $i['url'] === self::RUNESMITH))[0] ?? null;
        $this->assertSame(731, $runesmith['chapters'] ?? null);

        $completed = $source->discover('completed');
        $this->assertCount(20, $completed);
        $this->assertSame('Mother of Learning', $completed[0]['name']);
        $this->assertStringStartsWith('Zorian is a teenage mage', $completed[0]['description']);
        foreach ($completed as $item) {
            $this->assertSame('Completed', $item['status'], $item['name']);
        }

        // Cached: asking again makes no request.
        $source->discover('popular');
        $this->assertCount(2, $fetcher->calls);
    }

    // ---- Table of contents ------------------------------------------------------

    public function testTableOfContentsFromTheFictionPage(): void
    {
        // Stored without "www.": fetched on the canonical host, one request.
        $fetcher = $this->fake([self::MOL => 'royalroad/fiction-21220-mother-of-learning.html']);
        $novel = $this->novel('https://royalroad.com/fiction/21220/mother-of-learning', 'Mother of Learning');

        $this->assertInstanceOf(RoyalRoadSource::class, SourceResolver::for($novel));
        $toc = tableOfContentGenerator($novel);

        $this->assertTocGolden('royalroad/toc-mother-of-learning.expected.json', $toc, self::BASE);
        $this->assertSame([self::MOL], $fetcher->urls());
        $this->assertCount(109, $toc);

        // Positions in site order, every row numbered, no duplicates.
        $this->assertSame(array_map('strval', range(1, 109)), array_column($toc, 'chapter'));
        $this->assertSame([0], array_values(array_unique(array_column($toc, 'book'))));

        // The unnumbered end matter keeps its place and gets its position.
        $byLabel = array_column($toc, 'chapter', 'label');
        $this->assertSame('1', $byLabel['1. Good Morning Brother']);
        $this->assertSame('107', $byLabel['Epilogue']);
        $this->assertSame('108', $byLabel['Afterword']);
        $this->assertSame('109', $byLabel['New story is out - Zenith of Sorcery']);
    }

    /** 731 chapters; two unnumbered entries lead, so "Chapter 1" is the 3rd row. */
    public function testLongFictionTableOfContents(): void
    {
        $this->fake([self::RUNESMITH => 'royalroad/fiction-33844-the-runesmith.html']);

        $toc = tableOfContentGenerator($this->novel(self::RUNESMITH, 'The Runesmith'));

        $this->assertTocGolden('royalroad/toc-the-runesmith.expected.json', $toc, self::BASE);
        $this->assertCount(731, $toc);
        $this->assertSame(['Glossary of terms', '1'], [$toc[0]['label'], $toc[0]['chapter']]);
        $this->assertSame(['Skills,Titles and other [Spoilers]', '2'], [$toc[1]['label'], $toc[1]['chapter']]);
        $this->assertSame(['Chapter 1 – So it begins… with a truck!', '3'], [$toc[2]['label'], $toc[2]['chapter']]);
        $this->assertSame(['Chapter 729 – Taking Damage.', '731'], [$toc[730]['label'], $toc[730]['chapter']]);
        $this->assertSame(self::RUNESMITH_CH729, $toc[730]['url']);
    }

    /**
     * A stub (chapters removed by the author for publishing): what is left
     * jumps from Chapter 3 to Chapter 1034 and lists "Chapter 1035" twice;
     * both stay, in site order, numbered 27 and 28.
     */
    public function testStubFictionKeepsDuplicateTitlesApart(): void
    {
        $this->fake([self::HWFWM => 'royalroad/fiction-26294-he-who-fights-with-monsters-stub.html']);

        $toc = tableOfContentGenerator($this->novel(self::HWFWM, 'He Who Fights With Monsters'));

        $this->assertCount(28, $toc);
        $this->assertSame(['Chapter 3: Local Cuisine', '3'], [$toc[2]['label'], $toc[2]['chapter']]);
        $this->assertSame(['Chapter 1035: Since You Experienced Normal', '27'], [$toc[26]['label'], $toc[26]['chapter']]);
        $this->assertSame(['Chapter 1035: The Imperfect Who Produce Miracles', '28'], [$toc[27]['label'], $toc[27]['chapter']]);
        $this->assertCount(28, array_unique(array_column($toc, 'url')));
    }

    /** Without the window.chapters script the chapter table gives the same rows. */
    public function testChapterTableFallbackMatchesTheJson(): void
    {
        $html = FakeFetcher::body('royalroad/fiction-21220-mother-of-learning.html');
        $withoutJson = preg_replace('/window\.chapters\s*=/', 'window.notChapters =', $html);
        $this->assertNull(RoyalRoadSource::chaptersFromJson($withoutJson));

        $this->assertSame(RoyalRoadSource::parseChapters($html), RoyalRoadSource::parseChapters($withoutJson));
    }

    public function testJsonListSkipsUnreadableChaptersAndKeepsSiteOrder(): void
    {
        $chapters = [
            ['id' => 3, 'title' => 'Third &amp; last', 'order' => 2, 'visible' => 1, 'isUnlocked' => true, 'url' => '/fiction/9/x/chapter/3/c'],
            ['id' => 1, 'title' => 'Prologue', 'order' => 0, 'visible' => 1, 'isUnlocked' => true, 'url' => '/fiction/9/x/chapter/1/a'],
            ['id' => 2, 'title' => 'A "quoted"]; title', 'order' => 1, 'visible' => 1, 'url' => '/fiction/9/x/chapter/2/b'],
            ['id' => 4, 'title' => 'Advance chapter', 'order' => 3, 'visible' => 1, 'isUnlocked' => false, 'url' => '/fiction/9/x/chapter/4/d'],
            ['id' => 5, 'title' => 'Hidden', 'order' => 4, 'visible' => 0, 'url' => '/fiction/9/x/chapter/5/e'],
            ['id' => 1, 'title' => 'Prologue again', 'order' => 5, 'visible' => 1, 'url' => '/fiction/9/x/chapter/1/a'],
        ];
        $html = '<script>window.chapters = ' . json_encode($chapters) . ";\nwindow.volumes = [];</script>";

        $this->assertSame([
            ['label' => 'Prologue', 'url' => self::BASE . '/fiction/9/x/chapter/1/a', 'chapter' => '1', 'book' => 0, 'positional' => true],
            ['label' => 'A "quoted"]; title', 'url' => self::BASE . '/fiction/9/x/chapter/2/b', 'chapter' => '2', 'book' => 0, 'positional' => true],
            ['label' => 'Third & last', 'url' => self::BASE . '/fiction/9/x/chapter/3/c', 'chapter' => '3', 'book' => 0, 'positional' => true],
        ], RoyalRoadSource::parseChapters($html));
    }

    /**
     * Through the real TOC sync: the reading order is the site's, every
     * row has a number (nothing left at 0 for the resolver), and an
     * unchanged second run changes nothing.
     */
    public function testSyncedChaptersReadInSiteOrder(): void
    {
        $this->fake([self::MOL => 'royalroad/fiction-21220-mother-of-learning.html']);
        $group = new Group();
        $group->label = 'Royal Road';
        $group->url = self::BASE;
        $group->save();
        $novel = Novel::create(['name' => 'Mother of Learning', 'group_id' => $group->id, 'translator_url' => self::MOL]);

        $toc = tableOfContentGenerator($novel);
        // Numbered by position: titles whose own number differs ("Epilogue") must not be logged as disagreeing.
        \Illuminate\Support\Facades\Log::spy();
        $scraper = new NovelScraper();
        $scraper->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $counts = $scraper->syncTableOfContents($novel, $toc);
        $this->assertSame(109, $counts['created']);
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');

        $rows = NovelChapter::where('novel_id', $novel->id)->ordered()->get();
        $this->assertSame(array_column($toc, 'label'), $rows->pluck('label')->all());
        $this->assertSame(0, $rows->where('chapter', 0)->count());
        $this->assertSame(range(1, 109), $rows->pluck('sort_key')->map(fn($k) => (int) $k)->all());

        $again = $scraper->syncTableOfContents($novel, $toc);
        $this->assertSame(0, $again['created']);
        $this->assertSame(0, $again['updated']);
    }

    public function testUnreachableFictionPageGivesNoChapters(): void
    {
        $this->fake([self::MOL => FakeFetcher::fail('flaresolverr_error', null)]);

        $this->assertSame([], tableOfContentGenerator($this->novel(self::MOL, 'Mother of Learning')));
    }

    // ---- Metadata -------------------------------------------------------------

    public function testMetadataFromTheFictionPage(): void
    {
        $this->fake([self::MOL => 'royalroad/fiction-21220-mother-of-learning.html']);

        $meta = $this->source()->metadata($this->novel('https://royalroad.com/fiction/21220/mother-of-learning', 'Mother of Learning'));
        $expected = $this->golden('royalroad/fiction-21220-mother-of-learning.expected.json', $meta);

        $this->assertSame($expected, $meta);
        $this->assertSame('Mother of Learning', $meta['title']);
        $this->assertSame('nobody103', $meta['author']);
        $this->assertSame(109, $meta['no_of_chapters']);
        $this->assertSame('https://www.royalroadcdn.com/public/covers-full/21220-mother-of-learning.jpg?time=1637247458', $meta['image']);
        $this->assertSame([$meta['image'], 'https://www.royalroadcdn.com/public/covers-full/21220-mother-of-learning.webp?time=1637247458'], $meta['cover_candidates']);
        $this->assertSame(['Time Loop', 'Adventure', 'Fantasy', 'Mystery', 'Magic'], $meta['genres']);
        $this->assertStringStartsWith('<p>Zorian is a teenage mage', $meta['description']);
        $this->assertSame('Completed', $meta['status_text']);
        $this->assertTrue($meta['completed']);
    }

    /** A stub shows "STUB" where the status would be. */
    public function testStubFictionMetadata(): void
    {
        $this->fake([self::HWFWM => 'royalroad/fiction-26294-he-who-fights-with-monsters-stub.html']);

        $meta = $this->source()->metadata($this->novel(self::HWFWM, 'He Who Fights With Monsters'));

        $this->assertSame('Stub', $meta['status_text']);
        $this->assertFalse($meta['completed']);
        $this->assertSame(28, $meta['no_of_chapters']);
        $this->assertSame('Shirtaloon (Travis Deverell)', $meta['author']);
    }

    public function testRunesmithMetadataIsOngoing(): void
    {
        $this->fake([self::RUNESMITH => 'royalroad/fiction-33844-the-runesmith.html']);

        $meta = $this->source()->metadata($this->novel(self::RUNESMITH, 'The Runesmith'));

        $this->assertSame(['Ongoing', false, 731, 'Kuropon'], [$meta['status_text'], $meta['completed'], $meta['no_of_chapters'], $meta['author']]);
        $this->assertCount(12, $meta['genres']);
    }

    public function testUnreachableFictionPageGivesEmptyMetadata(): void
    {
        $this->fake([self::MOL => FakeFetcher::fail('http_4xx', 403)]);

        $meta = $this->source()->metadata($this->novel(self::MOL, 'Mother of Learning'));

        $this->assertSame(['description' => '', 'author' => '', 'no_of_chapters' => 0, 'image' => '', 'genres' => [], 'cover_candidates' => []], $meta);
    }

    // ---- Chapter content ---------------------------------------------------------

    #[DataProvider('chapters')]
    public function testChapterContent(string $fixture, string $url, string $novelUrl, int $number, string $label, array $authorNote): void
    {
        $this->fake([$url => "royalroad/{$fixture}.html"]);
        $novel = $this->novel($novelUrl, 'Novel');

        $reason = 'unset';
        $paragraphs = chapterGenerator($this->chapter($novel, $url, $number, $label), $reason);

        $this->assertNull($reason);
        $this->assertContentGolden("royalroad/{$fixture}.expected.json", $paragraphs, $label);
        $this->assertTrue(chapterParagraphsLookComplete($paragraphs));

        $text = implode("\n", array_map(fn($p) => self::plain($p), $paragraphs));
        // The page really has the hidden sentence; the chapter does not.
        $pageText = html_entity_decode(strip_tags(FakeFetcher::body("royalroad/{$fixture}.html")), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString(self::HIDDEN_SENTENCES[$fixture], $pageText);
        $this->assertStringNotContainsString(self::HIDDEN_SENTENCES[$fixture], $text);
        $this->assertDoesNotMatchRegularExpression('/royal\s*road|without (?:permission|consent)/i', $text);
        // Nor the author's note.
        foreach ($authorNote as $needle) {
            $this->assertStringContainsString($needle, FakeFetcher::body("royalroad/{$fixture}.html"));
            $this->assertStringNotContainsString($needle, $text);
        }
    }

    public static function chapters(): array
    {
        return [
            'mother of learning 1 (note after)' => [
                'chapter-301778-good-morning-brother', self::MOL_CH1, self::MOL, 1, '1. Good Morning Brother',
                ['A note from nobody103', 'Mother of Learning Kindle', 'audiobook of the story'],
            ],
            'the runesmith 729 (note before)' => [
                'chapter-4376982-taking-damage', self::RUNESMITH_CH729, self::RUNESMITH, 731, 'Chapter 729 – Taking Damage.',
                ['A note from Kuropon'],
            ],
        ];
    }

    public function testHiddenClassSelectorsComeFromThePagesStyleBlocks(): void
    {
        $this->assertSame(['.cjJiMWNiNDRlNDYxZTQ3OGNiNGEzY2ViOWM0MjNkNmJi'], RoyalRoadSource::hiddenClassSelectors(FakeFetcher::body('royalroad/chapter-301778-good-morning-brother.html')));
        $this->assertSame(['.cjhlYzE4MmE0NzM1NzQ1NTk4MDJiMDVmNTU3ZTIxZDI4'], RoyalRoadSource::hiddenClassSelectors(FakeFetcher::body('royalroad/chapter-4376982-taking-damage.html')));

        $css = '<style>/* c */ .aB1{display:none;speak:never} .shown{display:block} .x, .y { color: red; DISPLAY : none } '
            . 'div .nested{display:none} #id{display:none} .z:after{display:none} .notnone{display:nonexistent}</style>';
        $this->assertSame(['.aB1', '.x', '.y'], RoyalRoadSource::hiddenClassSelectors($css));
        $this->assertSame([], RoyalRoadSource::hiddenClassSelectors('<p>no style</p>'));
    }

    /**
     * SYNTHETIC variant of the real chapter page: in the saved pages the
     * hidden sentence is a <span> between paragraphs, which the extractor's
     * paragraph mode happens to skip anyway. Royal Road also hides it as a
     * whole <p>; this edit of the real page makes it one, to prove the
     * per-page selectors (PageNoiseSelectors) are what removes it.
     */
    public function testHiddenParagraphIsStrippedOnlyThroughThePageSelectors(): void
    {
        $html = FakeFetcher::body('royalroad/chapter-301778-good-morning-brother.html');
        $class = 'cjJiMWNiNDRlNDYxZTQ3OGNiNGEzY2ViOWM0MjNkNmJi';
        $synthetic = preg_replace(
            '#<span class="' . $class . '"><br>(.*?)<br></span>#s',
            '<p class="' . $class . '">$1</p>',
            $html,
            -1,
            $replaced
        );
        $this->assertSame(1, $replaced, 'the fixture still has the hidden span');
        $needle = 'this copy was made without consent';

        // A source with the same selectors but no per-page hook keeps it.
        $withoutHook = new class extends AbstractSource {
            public function name(): string { return 'rr-without-hook'; }
            public function tableOfContents(\App\Novel $novel): array { return []; }
            public function metadata(\App\Novel $novel): array { return []; }
            public function contentSelectors(): array { return (new RoyalRoadSource())->contentSelectors(); }
            public function removeSelectors(): array { return (new RoyalRoadSource())->removeSelectors(); }
        };
        $leaky = implode("\n", array_map(fn($p) => self::plain($p), ContentExtractor::make()->extract($synthetic, $withoutHook)));
        $this->assertStringContainsString($needle, $leaky);

        $paragraphs = ContentExtractor::make()->extract($synthetic, new RoyalRoadSource());
        $clean = implode("\n", array_map(fn($p) => self::plain($p), $paragraphs));
        $this->assertStringNotContainsString($needle, $clean);
        $this->assertCount(substr_count($leaky, "\n"), $paragraphs, 'exactly the one paragraph is dropped');
    }
}
