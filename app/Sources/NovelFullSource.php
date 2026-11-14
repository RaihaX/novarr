<?php

namespace App\Sources;

use App\Novel;
use App\Scraping\FailureSnapshot;
use App\Scraping\Fetcher;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\DomCrawler\Crawler;

/**
 * novelfull.com. Cloudflare-protected; full chapter list comes from its AJAX
 * chapter-option endpoint (keyed by the numeric data-novel-id). Covers are
 * fetchable with a plain client, and the novel page carries description,
 * author and genres, so it's mostly self-sufficient with NovelUpdates as a
 * fallback for anything missing.
 *
 * The global novelFullToc() / novelFullFetchAjax() /
 * parseNovelFullChapterOptions() / getMetadataFromNovelFull() helpers
 * delegate here.
 */
class NovelFullSource extends AbstractSource
{
    /**
     * Merge precedence (see MetadataMerger): novelfull's own page first
     * (result starts as its metadata, cover first); NovelUpdates fills a
     * missing description / author / genres / chapter count, its status wins
     * when it has one, and its fully_translated flag is always taken.
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
        // NovelUpdates' status wins when it has one; otherwise keep the
        // "Status:" the novel page itself reports (e.g. "Ongoing").
        'status' => ['mode' => 'resolve', 'prefer' => 'novelupdates'],
        'copy' => ['fully_translated' => 'novelupdates'],
        // novelfull cover first (fetchable), NovelUpdates as fallback.
        'covers' => ['site', 'novelupdates'],
    ];

    public function name(): string
    {
        return 'novelfull';
    }

    public function hosts(): array
    {
        return ['novelfull.com'];
    }

    public function contentSelectors(): array
    {
        return ['#chapter-content', ...self::GENERIC_CONTENT_SELECTORS];
    }

    public function removeSelectors(): array
    {
        return [
            ...self::GENERIC_REMOVE_SELECTORS,
            '#chapter-content > div[align="center"]', // ad slot above the text
            '#chapter-content > div[align="left"]',   // "report chapter" notice
            '#chapter-content > div[style]',          // inline ad boxes
            '.chapter-nav', '#chapter-nav-top', '#chapter-nav-bottom',
            '#chapter_error', '.chapter-end',
        ];
    }

    /**
     * Search results page (/search?keyword=…) → discover cards with cover
     * and author. Pure. Rows are .list-truyen .row blocks with an
     * h3.truyen-title link, an img.cover and a span.author.
     *
     * @return array<int, array{name: string, url: string, cover: string, author: string}>
     */
    public static function parseSearchResults(string $html, string $origin = 'https://novelfull.com'): array
    {
        $items = [];
        $abs = fn(string $u) => $u === '' ? '' : (preg_match('#^https?://#i', $u) ? $u : (str_starts_with($u, '//') ? 'https:' . $u : $origin . '/' . ltrim($u, '/')));

        (new Crawler($html))->filter('.list-truyen .row')->each(function (Crawler $row) use (&$items, $abs) {
            $link = $row->filter('h3.truyen-title a');
            if ($link->count() === 0) {
                return;
            }
            $href = trim($link->attr('href') ?? '');
            $name = trim($link->attr('title') ?: $link->text());
            if ($href === '' || $name === '' || !str_ends_with($href, '.html')) {
                return;
            }

            $img = $row->filter('img.cover');
            $cover = $img->count() ? trim($img->attr('src') ?: ($img->attr('data-src') ?? '')) : '';
            $author = $row->filter('span.author');

            $items[] = [
                'name' => $name,
                'url' => $abs($href),
                'cover' => $abs($cover),
                'author' => $author->count() ? trim(preg_replace('/\s+/u', ' ', $author->text())) : '',
            ];
        });

        return $items;
    }

    public function tableOfContents(Novel $novel): array
    {
        return $this->toc($novel->translator_url);
    }

    /**
     * Full chapter list for a novelfull.com novel. Like Novel Bin it exposes an
     * AJAX chapter-option endpoint that returns every chapter in one request,
     * but keyed by the numeric data-novel-id from the novel page rather than the
     * slug. Cloudflare-protected, so fetched via FlareSolverr (clearance reused
     * for the AJAX call).
     */
    public function toc(string $novelUrl): array
    {
        $host = parse_url($novelUrl, PHP_URL_HOST) ?: 'novelfull.com';
        $scheme = parse_url($novelUrl, PHP_URL_SCHEME) ?: 'https';
        $origin = "{$scheme}://{$host}";

        // Shared clearance first (plain), else a FlareSolverr solve that
        // refreshes it; the session's cookie is reused for the AJAX call.
        $session = app(Fetcher::class)->sessionPage($novelUrl);
        if (empty($session['html'])) {
            \Log::error("novelFullToc: could not fetch {$novelUrl}");
            return [];
        }

        if (!preg_match('/data-novel-id="(\d+)"/', $session['html'], $m)) {
            \Log::warning("novelFullToc: no data-novel-id on {$novelUrl}");
            FailureSnapshot::noteTocHtml($session['html']);
            return [];
        }

        // novelfull moved the endpoint from /ajax/chapter-option to
        // /ajax-chapter-option (same params and markup). Try the new path; the
        // old one only when the new one answers non-200.
        $html = null;
        $ajaxUrl = null;
        foreach (['/ajax-chapter-option', '/ajax/chapter-option'] as $endpoint) {
            $ajaxUrl = "{$origin}{$endpoint}?novelId=" . $m[1];
            $status = null;
            $html = $this->fetchAjax($ajaxUrl, $session, $status);
            if ($html !== null) {
                break;
            }
            \Log::warning("novelFullToc: {$ajaxUrl} returned " . ($status ?? 'no response') . ($endpoint === '/ajax-chapter-option' ? '; trying legacy endpoint' : ''));
        }
        if (empty($html)) {
            return [];
        }

        $result = self::parseChapterOptions($html, $origin);
        if ($result === null) {
            // A 200 error/blank page would otherwise yield junk rows like
            // "https://novelfull.com16px" from unrelated <option>s.
            \Log::warning("novelFullToc: no chapter <option> entries in {$ajaxUrl} response for {$novelUrl}");
            FailureSnapshot::noteTocHtml($html);
            return [];
        }

        \Log::info("novelFullToc: parsed " . count($result) . " chapters for {$novelUrl}");

        return $result;
    }

    /**
     * GET a novelfull AJAX URL: plain HTTP with the reused cf_clearance cookie
     * first, then FlareSolverr. Returns the body on a 200, else null with the
     * last seen status (or FlareSolverr reason) in $status.
     */
    public function fetchAjax(string $ajaxUrl, array $session, &$status = null): ?string
    {
        $fetcher = app(Fetcher::class);

        if (!empty($session['cf_clearance']) && !empty($session['user_agent'])) {
            $body = $fetcher->plain($ajaxUrl, ['User-Agent' => $session['user_agent']], ['cf_clearance' => $session['cf_clearance']], $status);
            if ($body !== null) {
                return $body;
            }
            if ($status === 404) {
                return null; // FlareSolverr would get the same 404
            }
        }

        $reason = null;
        $html = $fetcher->html($ajaxUrl, $reason);
        if ($html === null) {
            $status = $reason ?? $status;
        }

        return $html ?: null;
    }

    /**
     * Parse novelfull's chapter <option> list into TOC rows. Pure. Only options
     * whose value is a site-relative (or same-origin) chapter path count;
     * returns null when there are none, so the caller can tell an error page
     * from a real list. Deduped by URL (the dropdown is rendered twice).
     */
    public static function parseChapterOptions(string $html, string $origin): ?array
    {
        $seen = [];
        $result = [];
        $found = false;

        (new Crawler($html))->filter('option')->each(function ($node) use (&$result, &$seen, &$found, $origin) {
            $href = trim($node->attr('value') ?? '');
            $label = trim($node->text());
            if ($href === '' || $label === '') {
                return;
            }
            if (str_starts_with($href, $origin . '/')) {
                $href = substr($href, strlen($origin));
            }
            // A chapter page: /{novel-slug}/{chapter-slug}.html — matched on
            // shape, not on the word "chapter", so typo'd slugs like
            // "/shadow-slave/chpater-2812-….html" are kept — or any same-site
            // path that does mention "chapter".
            if (!preg_match('#^/[^\s"<>/]+/[^\s"<>/]+\.html?$#i', $href)
                && !preg_match('#^/[^\s"<>]*chapter[^\s"<>]*$#i', $href)) {
                return;
            }
            $found = true;
            $url = $origin . $href;
            if (isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;
            $row = generateTocChapterInfo($label, $url);
            if ($row) {
                $result[] = $row;
            }
        });

        return $found ? $result : null;
    }

    protected function siteMetadata(Novel $novel): array
    {
        return $this->pageMetadata($novel->translator_url);
    }

    /**
     * Metadata for a novelfull.com novel page (cover, description, author,
     * genres). novelfull covers are fetchable with a plain client.
     */
    public function pageMetadata(string $novelUrl): array
    {
        $metadata = ["description" => "", "author" => "", "no_of_chapters" => 0, "image" => "", "genres" => []];
        $host = parse_url($novelUrl, PHP_URL_HOST) ?: 'novelfull.com';
        $scheme = parse_url($novelUrl, PHP_URL_SCHEME) ?: 'https';
        $origin = "{$scheme}://{$host}";

        $html = app(Fetcher::class)->html($novelUrl);
        if (empty($html)) {
            return $metadata;
        }

        try {
            $crawler = new Crawler($html);

            $title = $crawler->filter('h3.title');
            if ($title->count() > 0) {
                $metadata["title"] = trim($title->first()->text());
            }

            $desc = $crawler->filter('.desc-text');
            if ($desc->count() > 0) {
                $metadata["description"] = sanitizeSynopsisHtml($desc->first()->html());
            }

            // Chapter count: the "latest chapters" list leads with the newest;
            // take the highest "Chapter N" there.
            $latest = $crawler->filter('.l-chapters a');
            if ($latest->count() > 0) {
                $numbers = [];
                $latest->each(function ($node) use (&$numbers) {
                    if (preg_match('/chapter\s*(\d+)/i', $node->attr('title') ?: $node->text(), $mm)) {
                        $numbers[] = (int) $mm[1];
                    }
                });
                if ($numbers) {
                    $metadata["no_of_chapters"] = max($numbers);
                }
            }

            // Cover + author + genres live in the .info / .book block.
            $img = $crawler->filter('.book img, .info img, [itemprop="image"]');
            if ($img->count() > 0) {
                $src = $img->first()->attr('src') ?? '';
                if ($src !== '') {
                    $metadata["image"] = str_starts_with($src, 'http') ? $src : $origin . $src;
                }
            }

            $info = $crawler->filter('.info');
            if ($info->count() > 0) {
                $author = $info->filter('a[href*="/author/"]');
                if ($author->count() > 0) {
                    $metadata["author"] = trim($author->first()->text());
                }
                $genres = $info->filter('a[href*="/genre/"]');
                if ($genres->count() > 0) {
                    $metadata["genres"] = normalizeGenres($genres->each(fn($n) => $n->text()));
                }

                // "Status: Ongoing" / "Status: Completed" — used when
                // NovelUpdates has no status for the novel.
                $status = $info->filterXPath('.//div[h3[normalize-space(.)="Status:"]]');
                if ($status->count() > 0) {
                    $text = trim(preg_replace('/\s+/u', ' ', str_ireplace('Status:', '', $status->first()->text())));
                    if ($text !== '') {
                        $metadata["status_text"] = $text;
                        $metadata["completed"] = stripos($text, "complete") !== false;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::error("getMetadataFromNovelFull error for {$novelUrl}: " . $e->getMessage());
        }

        return $metadata;
    }

    public function metadata(Novel $novel): array
    {
        return MetadataMerger::merge(
            fn() => $this->siteMetadata($novel),
            fn() => $this->novelUpdatesMetadata($novel), // NovelUpdates — fills gaps / richer genres
            static::METADATA_PRECEDENCE
        );
    }

    // ---- Discover ----------------------------------------------------------

    /** Search only (no browse lists). */
    public function discoverLists(): array
    {
        return ['search'];
    }

    /**
     * Search novelfull.com (Cloudflare-protected, via FlareSolverr). Results
     * are <h3 class="truyen-title"><a href="/slug.html" title="…">.
     */
    public function discover(string $type, ?string $query = null): ?array
    {
        if ($type !== 'search') {
            return [];
        }
        $q = (string) $query;
        $cacheKey = 'discover_nf_' . md5($q);

        $fetch = function () use ($q) {
            $html = fetchWithBrowser('https://novelfull.com/search?keyword=' . urlencode($q));
            if (empty($html)) {
                return null;
            }

            // The adapter's parser (tested against a saved search page)
            // returns cover and author too; the old inline parse left both
            // blank although the page carries them.
            $items = [];
            foreach (self::parseSearchResults($html) as $row) {
                $url = (string) ($row['url'] ?? '');
                $name = trim((string) ($row['name'] ?? $row['title'] ?? ''));
                if ($url === '' || $name === '' || !str_ends_with($url, '.html')) {
                    continue;
                }
                $items[] = [
                    'name' => $name,
                    'url' => $url,
                    'cover' => (string) ($row['cover'] ?? ''),
                    'author' => (string) ($row['author'] ?? ''),
                ];
            }

            return $items;
        };

        try {
            return Cache::remember($cacheKey, 600, $fetch);
        } catch (\Throwable $e) {
            return $fetch();
        }
    }
}
