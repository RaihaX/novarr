<?php

namespace Tests\Feature;

use App\Novel;
use App\Sources\AbstractSource;
use App\Sources\NovelArrowSource;
use App\Sources\SourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Adding a source is one adapter + one config entry": a fourth adapter,
 * defined only here and registered only through config('novarr.sources'),
 * is picked up by the resolver (novel and URL), the TOC generator, the
 * chapter generator's API fast path and the Discover page — with no other
 * file touched.
 */
class NewSourceDryRunTest extends GoldenTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DryRunSource::$fastPathCalls = [];

        // Before the novelarrow catch-all, which must stay last.
        config(['novarr.sources' => [
            'empirenovel' => \App\Sources\EmpireNovelSource::class,
            'novelfull' => \App\Sources\NovelFullSource::class,
            'dryrun' => DryRunSource::class,
            'novelarrow' => NovelArrowSource::class,
        ]]);
    }

    private function dryRunNovel(): Novel
    {
        return $this->novel('https://www.dryrun.test/novel/sample', 'Sample Novel');
    }

    public function testResolverPicksTheNewAdapterByNovelAndByUrl(): void
    {
        $this->fake([]);

        $this->assertInstanceOf(DryRunSource::class, SourceResolver::for($this->dryRunNovel()));
        $this->assertInstanceOf(DryRunSource::class, SourceResolver::forUrl('https://dryrun.test/chapter/sample/1'));
        $this->assertSame('dryrun', SourceResolver::get('dryrun')->name());
        // The others are unaffected.
        $this->assertInstanceOf(NovelArrowSource::class, SourceResolver::forUrl('https://unknown.example/x'));
        $this->assertSame(['empirenovel', 'novelfull', 'dryrun', 'novelarrow'], array_keys(SourceResolver::all()));
    }

    public function testTableOfContentGeneratorUsesTheNewAdapter(): void
    {
        $fetcher = $this->fake(['https://www.dryrun.test/novel/sample' => '<ul class="toc">'
            . '<li><a href="https://www.dryrun.test/chapter/sample/1">Chapter 1: Start</a></li>'
            . '<li><a href="https://www.dryrun.test/chapter/sample/2">Chapter 2: Middle</a></li></ul>']);

        $toc = tableOfContentGenerator($this->dryRunNovel());

        $this->assertSame([
            ['label' => 'Chapter 1: Start', 'book' => 0, 'url' => 'https://www.dryrun.test/chapter/sample/1', 'chapter' => '1'],
            ['label' => 'Chapter 2: Middle', 'book' => 0, 'url' => 'https://www.dryrun.test/chapter/sample/2', 'chapter' => '2'],
        ], array_map(fn($row) => ['label' => $row['label'], 'book' => (int) $row['book'], 'url' => $row['url'], 'chapter' => (string) $row['chapter']], $toc));
        $this->assertSame(['https://www.dryrun.test/novel/sample'], $fetcher->urls('html'));
    }

    public function testChapterGeneratorConsultsTheNewAdaptersFastPath(): void
    {
        $fetcher = $this->fake([]); // any page fetch would be an unexpected fetch
        $novel = $this->dryRunNovel();
        $chapter = $this->chapter($novel, 'https://www.dryrun.test/chapter/sample/1', 1, 'Chapter 1: Start');

        $reason = null;
        $paragraphs = chapterGenerator($chapter, $reason);

        $this->assertNull($reason);
        $this->assertSame(['https://www.dryrun.test/chapter/sample/1'], DryRunSource::$fastPathCalls);
        $this->assertCount(12, $paragraphs);
        $this->assertSame('<p>Paragraph 1 of the dry run chapter.</p>', $paragraphs[0]);
        $this->assertSame([], $fetcher->calls);
    }

    public function testNovelPingChapterOnAnotherSourcesNovelStillTakesTheNovelPingApi(): void
    {
        $api = 'https://novelping.com/api-web/novels/shadow-slave/chapters/chapter-1-nightmare-begins';
        $fetcher = $this->fake([$api => 'novelarrow/api-chapter-1-nightmare-begins.json']);
        $chapter = $this->chapter($this->dryRunNovel(), 'https://novelping.com/chapter/shadow-slave/chapter-1-nightmare-begins', 1, 'Chapter 1 Nightmare Begins');

        $paragraphs = chapterGenerator($chapter);

        $this->assertNotEmpty($paragraphs);
        // novelarrow claims the URL (dryrun does not), so its fast path is
        // asked first and answers from the API; dryrun is never asked.
        $this->assertSame([$api], $fetcher->urls('json'));
        $this->assertSame([], DryRunSource::$fastPathCalls);
    }

    public function testFastPathFallsBackToEveryRegisteredSourceInOrder(): void
    {
        $this->fake([]);

        $order = fn(string $url) => array_map(fn($s) => $s->name(), SourceResolver::fastPathCandidates($url));
        $this->assertSame(['dryrun', 'empirenovel', 'novelfull', 'novelarrow'], $order('https://dryrun.test/chapter/x/1'));
        $this->assertSame(['novelfull', 'empirenovel', 'dryrun', 'novelarrow'], $order('https://novelfull.com/x/chapter-1.html'));
        $this->assertSame(['novelarrow', 'empirenovel', 'novelfull', 'dryrun'], $order('https://novelping.com/chapter/x/chapter-1'));
    }

    public function testDiscoverListsTheNewAdapter(): void
    {
        $this->fake([]);

        $html = $this->get('/novels/discover')->assertOk()->getContent();
        $this->assertStringContainsString(
            "        <option value=\"novelarrow\">NovelPing (novelarrow)</option>\n"
            . "        <option value=\"empirenovel\">empirenovel.com</option>\n"
            . "        <option value=\"novelfull\">novelfull.com</option>\n"
            . "        <option value=\"dryrun\">Dry Run (dryrun.test)</option>\n",
            $html
        );
        $this->assertStringContainsString('"dryrun":"dryrun.test"', $html);

        Novel::create(['name' => 'Already Here', 'group_id' => 1, 'translator_url' => 'https://dryrun.test/novel/already-here/']);

        $this->getJson('/novels/discover/browse?source=dryrun&type=search&q=sample')
            ->assertOk()
            ->assertJsonPath('items.0.name', 'Sample Novel')
            ->assertJsonPath('items.0.in_library', false)
            ->assertJsonPath('items.1.in_library', true)
            ->assertJsonPath('hidden', 0);
        $this->getJson('/novels/discover/browse?source=dryrun&type=popular')
            ->assertOk()
            ->assertExactJson(['success' => true, 'items' => []]);
    }
}

/** The fourth source: exists only in this test. */
class DryRunSource extends AbstractSource
{
    public static array $fastPathCalls = [];

    public function name(): string
    {
        return 'dryrun';
    }

    public function hosts(): array
    {
        return ['dryrun.test'];
    }

    public function tableOfContents(Novel $novel): array
    {
        $html = app(\App\Scraping\Fetcher::class)->html($novel->translator_url);
        $rows = [];
        (new Crawler((string) $html))->filter('ul.toc a')->each(function (Crawler $a) use (&$rows) {
            $rows[] = generateTocChapterInfo($a->text(), $a->attr('href'));
        });

        return $rows;
    }

    public function metadata(Novel $novel): array
    {
        return ['description' => '', 'author' => '', 'no_of_chapters' => 0, 'image' => '', 'genres' => [], 'cover_candidates' => []];
    }

    public function chapterContent(string $url, ?string &$failure = null): ?array
    {
        $failure = null;
        self::$fastPathCalls[] = $url;
        if (!$this->matchesUrl($url)) {
            return null;
        }

        return array_map(fn($i) => "<p>Paragraph {$i} of the dry run chapter.</p>", range(1, 12));
    }

    public function discoverLabel(): string
    {
        return 'Dry Run (dryrun.test)';
    }

    public function discoverLists(): array
    {
        return ['search'];
    }

    public function discover(string $type, ?string $query = null): ?array
    {
        return [
            ['name' => 'Sample Novel', 'url' => 'https://www.dryrun.test/novel/sample', 'cover' => '', 'author' => ''],
            ['name' => 'Already Here', 'url' => 'https://dryrun.test/novel/already-here', 'cover' => '', 'author' => ''],
        ];
    }
}
