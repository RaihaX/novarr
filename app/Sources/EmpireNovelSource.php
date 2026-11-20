<?php

namespace App\Sources;

use App\Novel;
use App\Scraping\FailureSnapshot;
use App\Scraping\Fetcher;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\DomCrawler\Crawler;

/**
 * empirenovel.com. Cloudflare-protected; the chapter list is paginated and
 * the site's own cover images can't be fetched server-side, so covers and
 * the richer description/genres come from NovelUpdates while the chapter
 * count comes from Empire Novel itself (the source we actually scrape).
 *
 * The global empireNovelToc() / getMetadataFromEmpireNovel() helpers
 * delegate here.
 */
class EmpireNovelSource extends AbstractSource
{
    /**
     * Merge precedence (see MetadataMerger): NovelUpdates first (result
     * starts as its metadata, cover first), Empire Novel's chapter count
     * wins, and Empire Novel fills a missing description / author / genres.
     */
    public const METADATA_PRECEDENCE = [
        'fetch' => ['site', 'novelupdates'],
        'lazy' => false,
        'base' => 'novelupdates',
        'steps' => ['fields', 'covers'],
        'fields' => [
            'no_of_chapters' => 'site',
            'description' => 'novelupdates',
            'author' => 'novelupdates',
            'genres' => 'novelupdates',
        ],
        'status' => null,
        // NovelUpdates cover first (fetchable), Empire Novel's own as last resort.
        'covers' => ['novelupdates', 'site'],
    ];

    private const BASE = 'https://www.empirenovel.com';

    public function name(): string
    {
        return 'empirenovel';
    }

    public function hosts(): array
    {
        return ['empirenovel.com'];
    }

    public function contentSelectors(): array
    {
        return ['#read-novel', '.reader-page', ...self::GENERIC_CONTENT_SELECTORS];
    }

    public function removeSelectors(): array
    {
        return [
            ...self::GENERIC_REMOVE_SELECTORS,
            '#read-novel ul',          // "Quality checked by: …" credit
            '.pub',                    // ad block
            '#reader-bottom', '#chapter-right', '.cookie-consent', 'nav', 'footer',
        ];
    }

    public function tableOfContents(Novel $novel): array
    {
        return $this->toc($novel->translator_url);
    }

    /**
     * Full chapter list for an Empire Novel novel. The novel page paginates the
     * chapter list (?page=N, newest first); walk every page and return chapters
     * in ascending order with absolute URLs. All fetches go through FlareSolverr
     * (the site is behind Cloudflare).
     *
     * Incremental mode ($knownUrls, or the resolver tableOfContentGenerator()
     * was given): the walk stops after the first page whose chapter URLs are all
     * stored already, returning the entries read so far and flagging the run
     * with markTocRunIncremental(). A walk that reaches the last page anyway is
     * an ordinary full walk. A page that fails twice ends the walk with what
     * was parsed, flagged with markTocRunPartial().
     *
     * @param callable|null $knownUrls fn(): array<string, true> of stored chapter URLs
     */
    public function toc(string $novelUrl, ?callable $knownUrls = null): array
    {
        $knownUrls ??= tocKnownUrlsResolver();
        $base = self::BASE;
        $novelPath = parse_url($novelUrl, PHP_URL_PATH) ?: "";
        $novelUrl = $base . $novelPath; // normalise to canonical host/path
        $result = [];
        $fetcher = app(Fetcher::class);

        // Page 1 with the host's shared clearance (plain, when a cached entry
        // still gets us in) or via FlareSolverr to clear Cloudflare and grab the
        // clearance cookie, which lets the remaining pages be fetched with a
        // plain (fast) client — ~0.4s each vs ~7s through the headless browser.
        // A solve writes the clearance back to the shared store.
        $session = $fetcher->sessionPage($novelUrl . "?page=1");
        if (empty($session['html'])) {
            \Log::error("empireNovelToc: could not fetch {$novelUrl}");
            return [];
        }
        $firstHtml = $session['html'];

        // Fetch a page: plain client with the clearance cookie, falling back to
        // FlareSolverr if that fails (cookie expired / not available).
        $fetchPage = function (int $page) use ($novelUrl, &$session, $fetcher) {
            $url = $novelUrl . "?page=" . $page;
            if (!empty($session['cf_clearance']) && !empty($session['user_agent'])) {
                $status = null;
                $html = $fetcher->plain($url, ['User-Agent' => $session['user_agent']], ['cf_clearance' => $session['cf_clearance']], $status);
                if ($html !== null) {
                    return $html;
                }
                if ($status === 403 || $status === 503) {
                    $fetcher->forgetClearance($url); // refused: no longer a valid clearance
                }
            }
            $html = $fetcher->html($url);
            // html() refreshes the shared clearance when its solve carried a
            // cf_clearance cookie: pick it up so later pages go plain again.
            if ($html !== null && ($fresh = $fetcher->clearance($url)) !== null) {
                $session['cf_clearance'] = $fresh['cookies']['cf_clearance'];
                $session['user_agent'] = $fresh['user_agent'];
            }
            return $html;
        };

        // Highest ?page=N in the pagination is the last page.
        preg_match_all('/[?&]page=(\d+)/', $firstHtml, $m);
        $lastPage = $m[1] ? max(array_map('intval', $m[1])) : 1;
        $lastPage = min($lastPage, 2000); // hard safety cap

        // Returns the chapter URLs found on the page.
        $parsePage = function (string $html) use (&$result, $novelPath): array {
            $pageUrls = [];
            $crawler = new Crawler($html);
            $crawler->filter('a[href*="' . $novelPath . '/"]')->each(function ($node) use (&$result, &$pageUrls, $novelPath) {
                $href = $node->attr('href');
                // Only chapter links: /novel/{slug}/{numericId}
                if (!preg_match('#' . preg_quote($novelPath, '#') . '/(\d+)$#', $href)) {
                    return;
                }
                // Normalise non-breaking spaces (the list renders "Chapter&nbsp;
                // 7174") before collapsing whitespace.
                $text = str_replace("\u{a0}", ' ', $node->text());
                $text = trim(preg_replace('/\s+/u', ' ', $text));
                // Label is like "First Chapter Chapter 1" / "Chapter 4173" — pull
                // the chapter number from anywhere in it.
                if (!preg_match('/chapter\s*([\d.]+)/i', $text, $m)) {
                    return;
                }
                $url = str_starts_with($href, 'http') ? $href : 'https://www.empirenovel.com' . $href;
                $pageUrls[] = $url;
                $result[] = [
                    'label' => 'Chapter ' . $m[1],
                    'book' => 0,
                    'url' => $url,
                    'chapter' => $m[1],
                ];
            });

            return $pageUrls;
        };

        // Incremental stop test. A page without chapter links proves nothing
        // (a glitch page), so it never ends the walk. Stored URLs load lazily.
        $known = null;
        $allStored = function (array $pageUrls) use (&$known, $knownUrls): bool {
            if ($knownUrls === null || $pageUrls === []) {
                return false;
            }
            $known ??= $knownUrls();
            foreach ($pageUrls as $url) {
                if (!isset($known[$url])) {
                    return false;
                }
            }

            return true;
        };

        $stoppedAt = $allStored($parsePage($firstHtml)) ? 1 : null;
        $pagesRead = 1;
        for ($page = 2; $stoppedAt === null && $page <= $lastPage; $page++) {
            $html = $fetchPage($page);
            if (empty($html)) {
                // One retry (same Fetcher path) before giving up on the walk.
                \Log::info("empireNovelToc: page {$page} failed for {$novelUrl}; retrying once");
                $html = $fetchPage($page);
            }
            if (empty($html)) {
                // Pages run newest → oldest, so stopping here loses the older
                // chapters: return what was parsed but flag the run as partial
                // so the TOC health doesn't take it for a complete list.
                \Log::warning("empireNovelToc: page {$page} of {$lastPage} failed twice for {$novelUrl}; stopping (partial TOC)");
                markTocRunPartial();
                break;
            }
            $pagesRead = $page;
            if ($allStored($parsePage($html))) {
                $stoppedAt = $page;
            }
        }

        if ($stoppedAt !== null && $stoppedAt < $lastPage) {
            markTocRunIncremental();
            \Log::info("empireNovelToc: page {$stoppedAt} of {$lastPage} holds only stored chapters for {$novelUrl}; incremental walk stops there");
        }

        // Dedupe by URL and sort ascending by chapter number.
        $seen = [];
        $unique = [];
        foreach ($result as $row) {
            if ($row && !isset($seen[$row['url']])) {
                $seen[$row['url']] = true;
                $unique[] = $row;
            }
        }
        usort($unique, fn($a, $b) => ($a['chapter'] <=> $b['chapter']));

        if ($unique === []) {
            FailureSnapshot::noteTocHtml($firstHtml);
        }

        \Log::info("empireNovelToc: parsed " . count($unique) . " chapters across {$pagesRead} of {$lastPage} page(s) for {$novelUrl}");

        return $unique;
    }

    protected function siteMetadata(Novel $novel): array
    {
        return $this->pageMetadata($novel->translator_url);
    }

    /**
     * Metadata for an Empire Novel novel page (cover, summary, chapter count).
     */
    public function pageMetadata(string $novelUrl): array
    {
        $metadata = ["description" => "", "author" => "", "no_of_chapters" => 0, "image" => "", "genres" => []];

        $html = app(Fetcher::class)->html($novelUrl);
        if (empty($html)) {
            return $metadata;
        }

        try {
            $crawler = new Crawler($html);

            $og = $crawler->filterXPath('//meta[@property="og:image"]');
            if ($og->count() > 0) {
                $metadata["image"] = $og->attr("content") ?? "";
            }

            $desc = $crawler->filterXPath('//meta[@name="description"]');
            if ($desc->count() > 0) {
                $metadata["description"] = trim($desc->attr("content") ?? "");
            }

            // Chapter count: highest ?page=N × ~30, refined by parsing later; use
            // the largest "Chapter N" label visible as a floor.
            if (preg_match_all('/Chapter\s+([\d.]+)/i', $html, $m)) {
                $metadata["no_of_chapters"] = (int) max(array_map('floatval', $m[1]));
            }
        } catch (\Throwable $e) {
            \Log::error("getMetadataFromEmpireNovel error for {$novelUrl}: " . $e->getMessage());
        }

        return $metadata;
    }

    public function metadata(Novel $novel): array
    {
        return MetadataMerger::merge(
            fn() => $this->siteMetadata($novel),
            fn() => $this->novelUpdatesMetadata($novel), // NovelUpdates by name — richer desc/genres + a fetchable cover
            static::METADATA_PRECEDENCE
        );
    }

    // ---- Discover ----------------------------------------------------------

    /** Empire Novel only exposes a live search endpoint (no browse lists). */
    public function discoverLists(): array
    {
        return ['search'];
    }

    /**
     * Search empirenovel.com via its live-search JSON endpoint (behind
     * Cloudflare). Like the chapter-list pages it reuses the host's shared
     * clearance (Fetcher::page(): a plain request with the stored
     * cf_clearance + User-Agent, ~0.4 s) and only goes through a FlareSolverr
     * solve (~13 s) when there is none or it is refused; a plain answer that
     * is not the JSON list is not trusted and also goes to FlareSolverr.
     * Returns null on failure.
     */
    public function discover(string $type, ?string $query = null): ?array
    {
        if ($type !== 'search') {
            return [];
        }
        $q = (string) $query;
        // v2: results carry the synopsis / status now.
        $cacheKey = 'discover_en_v2_' . md5($q);

        $fetch = function () use ($q) {
            $url = self::BASE . '/search-live?q=' . urlencode($q);
            $fetcher = app(Fetcher::class);
            $reason = null;
            $via = null;
            $html = $fetcher->page($url, $reason, $via);
            if ($via === 'plain' && !empty($html) && !preg_match('/\[.*\]/s', $html)) {
                $html = $fetcher->html($url);
            }
            if (empty($html)) {
                return null;
            }

            return self::parseSearch($html);
        };

        try {
            return Cache::remember($cacheKey, 600, $fetch);
        } catch (\Throwable $e) {
            return $fetch();
        }
    }

    /**
     * Parse an Empire Novel search-live response into result items. The
     * body is a JSON array (FlareSolverr wraps it in HTML) of rows with
     * `slug`, `name`, a localised `summary` ({"en": "…"}, sometimes itself
     * JSON-encoded) and, on some rows, `status`. Pure — unit tested.
     */
    public static function parseSearch(string $body): array
    {
        if (!preg_match('/(\[.*\])/s', $body, $m)) {
            return [];
        }
        $rows = json_decode($m[1], true);
        if (!is_array($rows)) {
            // FlareSolverr may hand back the JSON HTML-escaped inside <pre>.
            $rows = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        }
        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $r) {
            $slug = is_array($r) ? ($r['slug'] ?? null) : null;
            if (!$slug) {
                continue;
            }

            $summary = $r['summary'] ?? '';
            if (is_string($summary) && str_starts_with(ltrim($summary), '{')) {
                $summary = json_decode($summary, true) ?? $summary;
            }
            if (is_array($summary)) {
                $summary = $summary['en'] ?? (reset($summary) ?: '');
            }

            $item = [
                'name' => $r['name'] ?? $slug,
                'url' => self::BASE . '/novel/' . $slug,
                'cover' => self::BASE . "/uploads/novel/{$slug}/cover/cover_250x350.jpg",
                'author' => '',
                'description' => self::plainSynopsis(is_string($summary) ? $summary : ''),
            ];

            $status = self::statusLabel($r['status'] ?? null);
            if ($status !== null) {
                $item['status'] = $status;
            }

            $items[] = $item;
        }

        return $items;
    }
}
