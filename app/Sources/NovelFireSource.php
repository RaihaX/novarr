<?php

namespace App\Sources;

use App\Novel;
use App\Scraping\ContentExtractor;
use App\Scraping\FailureSnapshot;
use App\Scraping\Fetcher;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\DomCrawler\Crawler;

/**
 * novelfire.net. Fronted by Cloudflare but, as of 2026-11, not challenged:
 * every page answers a plain GET, so pages are fetched plain first and only
 * go through the shared clearance / FlareSolverr path (Fetcher::page()) when
 * the plain answer is refused or is a challenge.
 *
 * URLs: a book is /book/{slug}, its chapters /book/{slug}/chapter-{n}, and
 * the chapter list /book/{slug}/chapters?page=N (100 rows a page; there is
 * no all-chapters AJAX endpoint any more — /listChapterDataAjax answers 404).
 *
 * Chapter pages are fetched plain through chapterContent(): a fraction of
 * chapter requests (seemingly at random) is answered with a 302 to a
 * partner site (readnovel.site, whose "Loading..." page script-redirects on
 * to novelphoenix.com). Such an answer is retried; see chapterContent().
 */
class NovelFireSource extends AbstractSource
{
    public const BASE = 'https://novelfire.net';

    /** Hard cap on chapter-list pages walked (100 rows each). */
    public const TOC_PAGE_CAP = 500;

    /** Plain attempts for one chapter page while the site diverts us. */
    public const CHAPTER_ATTEMPTS = 3;

    /**
     * Merge precedence (see MetadataMerger): the book page first (it has
     * description, author, genres, chapter count, status and a cover that a
     * plain client can download); NovelUpdates fills what is missing, its
     * status wins when it has one, and its fully_translated flag is taken.
     */
    public const METADATA_PRECEDENCE = [
        'fetch' => ['site', 'novelupdates'],
        'lazy' => false,
        'base' => 'site',
        'steps' => ['fields', 'status', 'copy', 'covers'],
        'fields' => [
            'description' => 'site',
            'author' => 'site',
            'genres' => 'site',
            'no_of_chapters' => 'site',
        ],
        'status' => ['mode' => 'resolve', 'prefer' => 'novelupdates'],
        'copy' => ['fully_translated' => 'novelupdates'],
        'covers' => ['site', 'novelupdates'],
    ];

    public function name(): string
    {
        return 'novelfire';
    }

    public function hosts(): array
    {
        return ['novelfire.net'];
    }

    // ---- URLs --------------------------------------------------------------

    /**
     * The book slug of a novelfire book or chapter URL
     * ("https://www.novelfire.net/book/shadow-slave/chapter-3" →
     * "shadow-slave"), or null when the URL is not one.
     */
    public static function bookSlug(string $url): ?string
    {
        if (!self::hostMatches(self::urlHost($url), ['novelfire.net'])) {
            return null;
        }
        if (!preg_match('#^/book/([a-z0-9][a-z0-9_-]*)(?:/|$)#i', self::urlPath($url), $m)) {
            return null;
        }

        return strtolower($m[1]);
    }

    /** Whether the URL is a novelfire chapter page (/book/{slug}/chapter-{n}). */
    public static function isChapterUrl(string $url): bool
    {
        if (self::bookSlug($url) === null) {
            return false;
        }

        return (bool) preg_match('#^/book/[^/]+/chapter-\d+/?$#i', self::urlPath($url));
    }

    /** The path of a URL, also for one written without a scheme ("novelfire.net/book/x"). */
    private static function urlPath(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) && !str_starts_with($url, '//')) {
            $url = '//' . $url;
        }

        return (string) parse_url($url, PHP_URL_PATH);
    }

    /** The canonical book URL for a slug. */
    public static function bookUrl(string $slug): string
    {
        return self::BASE . '/book/' . $slug;
    }

    /** One page of a book's chapter list, newest first. */
    public static function chapterListUrl(string $slug, int $page): string
    {
        return self::BASE . '/book/' . $slug . '/chapters?page=' . $page . '&sort_by=desc';
    }

    public function librarySlug(string $url, bool $stored = false): ?string
    {
        return self::bookSlug($url);
    }

    // ---- fetching ----------------------------------------------------------

    /**
     * A page: plain GET first (the site is not challenged today), else the
     * shared clearance / FlareSolverr path. A 404 or 429 is final (FlareSolverr
     * would get the same answer, or ignore the rate limit). Null on failure,
     * $reason saying why ('http_404', 'rate_limited' or Fetcher's reasons).
     */
    protected function fetchHtml(string $url, ?string &$reason = null): ?string
    {
        $reason = null;
        $fetcher = app(Fetcher::class);

        $status = null;
        $body = $fetcher->plain($url, [], [], $status);
        if ($body !== null && !Fetcher::isCloudflareChallenge($body)) {
            return $body;
        }
        if ($status === 404) {
            $reason = 'http_404';
            return null;
        }
        if ($status === 429) {
            $reason = 'rate_limited';
            return null;
        }

        \Log::info("novelfire: plain GET of {$url} answered " . ($body !== null ? 'a challenge' : ($status ?? 'nothing')) . '; trying the clearance/FlareSolverr path');

        return $fetcher->page($url, $reason);
    }

    /** Waits a rate-limited chapter-list page gets before the walk gives up (partial TOC). */
    public const RATE_LIMIT_WAITS = 3;

    private static function pause(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    // ---- table of contents -------------------------------------------------

    public function tableOfContents(Novel $novel): array
    {
        return $this->toc((string) $novel->translator_url);
    }

    /**
     * Full chapter list, oldest first. The list is walked newest first
     * (sort_by=desc) so that, given the stored chapter URLs ($knownUrls, or
     * the resolver tableOfContentGenerator() was given), the walk stops after
     * the first page whose chapters are all stored already
     * (markTocRunIncremental()). A full walk costs ceil(chapters / 100)
     * requests; the page count comes from page 1's pagination. A page that
     * fails twice ends the walk with what was read (markTocRunPartial()).
     *
     * Rows are ordered by the site's own running number (span.chapter-no),
     * so the result does not depend on the sort the site actually applied.
     *
     * @param callable|null $knownUrls fn(): array<string, true> of stored chapter URLs
     */
    public function toc(string $novelUrl, ?callable $knownUrls = null): array
    {
        $knownUrls ??= tocKnownUrlsResolver();
        $slug = self::bookSlug($novelUrl);
        if ($slug === null) {
            \Log::warning("novelFireToc: not a novelfire book URL: {$novelUrl}");
            return [];
        }

        $firstUrl = self::chapterListUrl($slug, 1);
        $firstHtml = $this->fetchHtml($firstUrl);
        if (empty($firstHtml)) {
            \Log::error("novelFireToc: could not fetch {$firstUrl}");
            return [];
        }

        $lastPage = min(self::lastListPage($firstHtml, $slug), self::TOC_PAGE_CAP);

        /** @var array<int, array{seq: int, row: array}> $collected */
        $collected = [];
        $collect = function (string $html) use (&$collected, $slug): array {
            $entries = self::parseChapterList($html, $slug);
            array_push($collected, ...$entries);

            return $entries;
        };

        $firstEntries = $collect($firstHtml);

        // Only a page that really runs newest first may end the walk early:
        // were sort_by ignored, page 1 would hold the oldest chapters, all
        // stored, and an early stop would miss every new one.
        $descending = count($firstEntries) < 2 || $firstEntries[0]['seq'] > end($firstEntries)['seq'];
        if (!$descending) {
            \Log::warning("novelFireToc: {$firstUrl} is not newest first; walking every page");
        }

        $known = null;
        $allStored = function (array $entries) use (&$known, $knownUrls, $descending): bool {
            if (!$descending || $knownUrls === null || $entries === []) {
                return false;
            }
            $known ??= $knownUrls();
            foreach ($entries as $entry) {
                if (!isset($known[$entry['row']['url']])) {
                    return false;
                }
            }

            return true;
        };

        $stoppedAt = $allStored($firstEntries) ? 1 : null;
        $pagesRead = 1;
        for ($page = 2; $stoppedAt === null && $page <= $lastPage; $page++) {
            $url = self::chapterListUrl($slug, $page);
            // The site answers 429 after a burst of about ten requests:
            // pace the walk, and wait out a rate limit instead of giving up
            // with a third of a long book.
            self::pause((int) config('novarr.novelfire.request_delay_ms', 1500));
            $reason = null;
            $html = $this->fetchHtml($url, $reason);
            for ($wait = 1; empty($html) && $reason === 'rate_limited' && $wait <= self::RATE_LIMIT_WAITS; $wait++) {
                \Log::info("novelFireToc: page {$page} of {$lastPage} rate limited for {$novelUrl}; waiting ({$wait}/" . self::RATE_LIMIT_WAITS . ')');
                self::pause((int) config('novarr.novelfire.rate_limit_wait_ms', 20000));
                $html = $this->fetchHtml($url, $reason);
            }
            if (empty($html) && $reason !== 'rate_limited') {
                \Log::info("novelFireToc: page {$page} failed for {$novelUrl} (" . ($reason ?? 'unknown') . '); retrying once');
                $html = $this->fetchHtml($url, $reason);
            }
            if (empty($html)) {
                \Log::warning("novelFireToc: page {$page} of {$lastPage} failed for {$novelUrl} (" . ($reason ?? 'unknown') . '); stopping (partial TOC)');
                markTocRunPartial();
                break;
            }
            $pagesRead = $page;
            if ($allStored($collect($html))) {
                $stoppedAt = $page;
            }
        }

        if ($stoppedAt !== null && $stoppedAt < $lastPage) {
            markTocRunIncremental();
            \Log::info("novelFireToc: page {$stoppedAt} of {$lastPage} holds only stored chapters for {$novelUrl}; incremental walk stops there");
        }

        // Oldest first by the site's running number; a row seen twice (the
        // list shifted while it was walked) is kept once.
        usort($collected, fn($a, $b) => $a['seq'] <=> $b['seq']);
        $seen = [];
        $result = [];
        foreach ($collected as $entry) {
            $url = $entry['row']['url'];
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $result[] = $entry['row'];
            }
        }

        if ($result === []) {
            FailureSnapshot::noteTocHtml($firstHtml);
        }

        \Log::info('novelFireToc: parsed ' . count($result) . " chapters across {$pagesRead} of {$lastPage} page(s) for {$novelUrl}");

        return $result;
    }

    /** The highest ?page=N the chapter list's pagination links to (1 when none). */
    public static function lastListPage(string $html, string $slug): int
    {
        $pages = [1];
        if (preg_match_all('#/book/' . preg_quote($slug, '#') . '/chapters\?page=(\d+)#i', $html, $m)) {
            $pages = array_merge($pages, array_map('intval', $m[1]));
        }

        return max($pages);
    }

    /**
     * One chapter-list page → entries in page order, each the TOC row
     * (generateTocChapterInfo()) plus the site's running number. Pure. Only
     * links to this book's /chapter-N pages count.
     *
     * @return array<int, array{seq: int, row: array}>
     */
    public static function parseChapterList(string $html, string $slug): array
    {
        $entries = [];
        $pattern = '#^(?:https?://(?:www\.)?novelfire\.net)?/book/' . preg_quote($slug, '#') . '/chapter-(\d+)/?$#i';

        (new Crawler($html))->filter('ul.chapter-list li a[href]')->each(function (Crawler $a) use (&$entries, $pattern, $slug) {
            $href = trim((string) $a->attr('href'));
            if (!preg_match($pattern, $href, $m)) {
                return;
            }
            $title = $a->filter('.chapter-title');
            $label = $title->count() ? $title->text() : ($a->attr('title') ?? $a->text());
            $label = self::normalizeChapterLabel($label);
            if ($label === '') {
                return;
            }
            $no = $a->filter('.chapter-no');
            $seq = $no->count() && ctype_digit(trim($no->text())) ? (int) trim($no->text()) : (int) $m[1];

            $row = generateTocChapterInfo($label, self::BASE . '/book/' . $slug . '/chapter-' . $m[1]);
            // The label parser reads a trailing "(1)" as a part ("Chapter 69:
            // Siege Of The Crimson Spire (1)" → "69.1"). Here every row is its
            // own page: when the label's whole number is the page's own
            // number, the "(1)" is an arc part in the title, not a split
            // chapter, so the chapter keeps its whole number.
            if ($row && str_contains((string) $row['chapter'], '.')
                && preg_match('/^chapter\s+(\d+)(?![.\d])/i', $label, $lm)
                && (int) $lm[1] === (int) $m[1]
                && (int) floor((float) $row['chapter']) === (int) $m[1]) {
                $row['chapter'] = (string) (int) $m[1];
            }
            if ($row) {
                $entries[] = ['seq' => $seq, 'row' => $row];
            }
        });

        return $entries;
    }

    /**
     * novelfire labels repeat the number after a dash: "Chapter 1 - 1:
     * Nightmare Begins", and an untitled chapter is "Chapter 108 -". The
     * repeat reads as a part ("1.1") to the label parser, so it is dropped:
     * "Chapter 1: Nightmare Begins", "Chapter 108". A dash followed by a real
     * title ("Chapter 500 - Return") or another number ("Chapter 12 - 12.5:
     * …") is left alone.
     */
    public static function normalizeChapterLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $label)) ?? '');
        if (!preg_match('/^(chapter\s+(\d+(?:\.\d+)?))\s*[-–—]\s*(.*)$/iu', $label, $m)) {
            return $label;
        }
        [, $head, $number, $rest] = $m;
        if ($rest === '') {
            return $head;
        }
        if (preg_match('/^' . preg_quote($number, '/') . '(?!\.?\d)\s*(?:[:.\-–—]\s*|$)(.*)$/u', $rest, $r)) {
            $title = trim($r[1]);

            return $title === '' ? $head : "{$head}: {$title}";
        }

        return $label;
    }

    // ---- metadata ----------------------------------------------------------

    protected function siteMetadata(Novel $novel): array
    {
        return $this->pageMetadata((string) $novel->translator_url);
    }

    public function metadata(Novel $novel): array
    {
        return MetadataMerger::merge(
            fn() => $this->siteMetadata($novel),
            fn() => $this->novelUpdatesMetadata($novel),
            static::METADATA_PRECEDENCE
        );
    }

    /** The book page's metadata (fetched), empty fields when it can't be had. */
    public function pageMetadata(string $novelUrl): array
    {
        $slug = self::bookSlug($novelUrl);
        $html = $slug !== null ? $this->fetchHtml(self::bookUrl($slug)) : null;
        if (empty($html)) {
            return ['description' => '', 'author' => '', 'no_of_chapters' => 0, 'image' => '', 'genres' => []];
        }

        return self::parseBookPage($html);
    }

    /**
     * Book page → metadata (title, description, author, no_of_chapters,
     * image, genres, status_text/completed). Pure.
     */
    public static function parseBookPage(string $html): array
    {
        $metadata = ['description' => '', 'author' => '', 'no_of_chapters' => 0, 'image' => '', 'genres' => []];

        try {
            $crawler = new Crawler($html);

            $title = $crawler->filter('h1.novel-title');
            if ($title->count() > 0) {
                $metadata['title'] = trim($title->first()->text());
            }

            $summary = $crawler->filter('.summary .content');
            if ($summary->count() > 0) {
                // Drop the "Show More" toggle before taking the synopsis.
                $node = $summary->getNode(0);
                foreach ((new Crawler($node))->filter('.expand') as $toggle) {
                    $toggle->parentNode?->removeChild($toggle);
                }
                $metadata['description'] = sanitizeSynopsisHtml($summary->first()->html());
            }
            if ($metadata['description'] === '') {
                $meta = $crawler->filterXPath('//meta[@itemprop="description"]');
                if ($meta->count() > 0) {
                    $metadata['description'] = trim((string) $meta->attr('content'));
                }
            }

            $author = $crawler->filter('.novel-info .author a, .novel-info [itemprop="author"]');
            if ($author->count() > 0) {
                $metadata['author'] = trim(preg_replace('/\s+/u', ' ', $author->first()->text()));
            }

            $stat = fn(string $label) => $crawler->filterXPath('//div[contains(concat(" ", normalize-space(@class), " "), " header-stats ")]/span[normalize-space(small)="' . $label . '"]/strong');

            $chapters = $stat('Chapters');
            if ($chapters->count() > 0 && preg_match('/(\d[\d,]*)/', $chapters->text(), $m)) {
                $metadata['no_of_chapters'] = (int) str_replace(',', '', $m[1]);
            }

            $status = $stat('Status');
            if ($status->count() > 0) {
                $text = trim(preg_replace('/\s+/u', ' ', $status->text()));
                if ($text !== '') {
                    $metadata['status_text'] = $text;
                    $metadata['completed'] = stripos($text, 'complete') !== false;
                }
            }

            $img = $crawler->filter('.novel-header figure.cover img');
            if ($img->count() > 0) {
                $src = trim((string) ($img->attr('data-src') ?: $img->attr('src')));
                if ($src !== '' && !str_starts_with($src, 'data:')) {
                    $metadata['image'] = self::absolute($src);
                }
            }

            $genres = $crawler->filter('.novel-info .categories a');
            if ($genres->count() > 0) {
                $metadata['genres'] = normalizeGenres($genres->each(fn(Crawler $n) => $n->text()));
            }
        } catch (\Throwable $e) {
            \Log::error('novelfire metadata parse error: ' . $e->getMessage());
        }

        return $metadata;
    }

    // ---- chapter content ---------------------------------------------------

    public function contentSelectors(): array
    {
        return ['#chapter-container #content', ...self::GENERIC_CONTENT_SELECTORS];
    }

    public function removeSelectors(): array
    {
        return [
            ...self::GENERIC_REMOVE_SELECTORS,
            '.nf-ads',              // a-ads slots inside #content
            '#content > div',       // the sticky ad box (and any other widget)
            '#content > h4',        // the chapter heading repeated above the text
            '.chapternav', '.report-container', '#comments',
        ];
    }

    /**
     * The chapter, fetched plain (the site is not challenged, so no
     * FlareSolverr round trip). Null for any URL that isn't a novelfire
     * chapter. When the site diverts the request to its partner site (a 302
     * to readnovel.site, seen on roughly a third of requests, at random —
     * the same chapter answers normally a moment later) the page is asked
     * for again, up to CHAPTER_ATTEMPTS times. Still diverted: [] with
     * failure 'rate_limited', so the chapter is retried later instead of
     * being fetched through FlareSolverr, which would follow the partner
     * site's script redirect to another site's page.
     */
    public function chapterContent(string $url, ?string &$failure = null): ?array
    {
        $failure = null;
        if (!self::isChapterUrl($url)) {
            return null;
        }

        $fetcher = app(Fetcher::class);
        for ($attempt = 1; $attempt <= self::CHAPTER_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                [$min, $max] = array_map('intval', array_pad((array) config('novarr.chapter_fetch_delay_ms', [500, 1500]), 2, 0));
                if ($max > 0) {
                    usleep(1000 * rand(max(0, $min), max($min, $max)));
                }
            }

            $status = null;
            $html = $fetcher->plain($url, [], [], $status);
            if ($html === null) {
                // Not a diversion: the page fetch decides (FlareSolverr on a
                // refusal), except for a rate limit or a missing chapter.
                $failure = match (true) {
                    $status === 429 => 'rate_limited',
                    $status === 404 => 'not_found',
                    default => 'fetch_failed',
                };
                return [];
            }
            if (Fetcher::isCloudflareChallenge($html)) {
                $failure = 'fetch_failed';
                return [];
            }
            if (!self::isChapterPage($html)) {
                \Log::info("novelfire: {$url} was diverted to another site (attempt {$attempt}/" . self::CHAPTER_ATTEMPTS . ')');
                continue;
            }

            $paragraphs = ContentExtractor::make()->extract($html, $this, $url);
            if ($paragraphs === []) {
                $failure = 'empty';
            }

            return $paragraphs;
        }

        \Log::warning("novelfire: {$url} was diverted " . self::CHAPTER_ATTEMPTS . ' times; leaving the chapter for a later run');
        $failure = 'rate_limited';

        return [];
    }

    /**
     * Whether a fetched page is a novelfire chapter page (it has the
     * #content body), rather than the partner site's redirect page.
     */
    public static function isChapterPage(string $html): bool
    {
        return str_contains($html, 'id="chapter-container"') || preg_match('/<div[^>]+id="content"/i', $html) === 1;
    }

    // ---- Discover ----------------------------------------------------------

    /**
     * Search only. The site has browse lists (/genre-all/sort-popular/…),
     * but a source with browse lists is tiered ahead of search-only ones on
     * the Discover page, which would make novelfire the page's default
     * source in place of NovelPing.
     */
    public function discoverLists(): array
    {
        return ['search'];
    }

    public function discover(string $type, ?string $query = null): ?array
    {
        if ($type !== 'search') {
            return [];
        }
        $q = trim((string) $query);
        if ($q === '') {
            return [];
        }

        $fetch = function () use ($q) {
            $html = $this->fetchHtml(self::BASE . '/search?keyword=' . urlencode($q));
            if (empty($html)) {
                return null;
            }

            return self::parseSearchResults($html);
        };

        try {
            return Cache::remember('discover_nfire_' . md5($q), 600, $fetch);
        } catch (\Throwable $e) {
            return $fetch();
        }
    }

    /**
     * Search results page → discover items (name, url, cover, author — the
     * page names no author, so '' — and chapters when shown). Pure. The
     * "Some Popular Novels" block under the results is not part of them.
     *
     * @return array<int, array{name: string, url: string, cover: string, author: string, chapters?: int}>
     */
    public static function parseSearchResults(string $html): array
    {
        $items = [];
        $seen = [];
        $rows = (new Crawler($html))->filterXPath(
            '//ul[contains(concat(" ", normalize-space(@class), " "), " novel-list ")]'
            . '[not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " popular-novels ")])]'
            . '/li[contains(concat(" ", normalize-space(@class), " "), " novel-item ")]'
        );

        $rows->each(function (Crawler $row) use (&$items, &$seen) {
            $link = $row->filter('a[href]');
            if ($link->count() === 0) {
                return;
            }
            $href = trim((string) $link->first()->attr('href'));
            $slug = self::bookSlug(self::absolute($href));
            if ($slug === null) {
                return;
            }
            $titleNode = $row->filter('.novel-title');
            $name = trim((string) ($link->first()->attr('title') ?: ($titleNode->count() ? $titleNode->text() : '')));
            $url = self::bookUrl($slug);
            if ($name === '' || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;

            $img = $row->filter('img');
            $cover = $img->count() ? trim((string) ($img->attr('data-src') ?: $img->attr('src'))) : '';
            if (str_starts_with($cover, 'data:')) {
                $cover = '';
            }

            $item = [
                'name' => $name,
                'url' => $url,
                'cover' => $cover === '' ? '' : self::absolute($cover),
                'author' => '',
            ];
            if (preg_match('/(\d[\d,]*)\s+Chapters?\b/i', $row->text(), $m)) {
                $item['chapters'] = (int) str_replace(',', '', $m[1]);
            }
            $items[] = $item;
        });

        return $items;
    }

    private static function absolute(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return self::BASE . '/' . ltrim($url, '/');
    }
}
