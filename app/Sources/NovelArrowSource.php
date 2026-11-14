<?php

namespace App\Sources;

use App\Novel;
use App\Scraping\FailureSnapshot;
use App\Scraping\Fetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * NovelPing (novelping.com; formerly Novel Arrow, before that Novel Bin) —
 * the class keeps its NovelArrow name so stored settings/labels stay valid.
 * Both brand hosts are recognised; every outgoing URL is built on the
 * canonical host (config novarr.novelarrow_host). Also the default source
 * for anything not
 * matched elsewhere. TOC comes from the site's JSON API (the page only embeds
 * ~30 chapters), with a generic page-parse fallback for unrecognised sites.
 * Metadata is NovelUpdates first, Novel Arrow as fallback.
 *
 * The site's URL and API helpers live here as static methods; the global
 * novelArrow*() / isNovelArrowUrl() / getMetadataFromNovelArrow() helpers
 * delegate to them.
 */
class NovelArrowSource extends AbstractSource
{
    /**
     * Merge precedence (see MetadataMerger): NovelUpdates first; the site
     * fills description/author/chapter count/cover/genres only when
     * NovelUpdates lacks one of cover/description/author/chapter count, and
     * the status when NovelUpdates has none. The site is fetched only when
     * one of those is needed.
     */
    public const METADATA_PRECEDENCE = [
        'fetch' => ['novelupdates', 'site'],
        'lazy' => true,
        'base' => 'novelupdates',
        'steps' => ['covers', 'fields', 'status'],
        'gate' => ['image', 'description', 'author', 'no_of_chapters'],
        'fields' => [
            'description' => 'novelupdates',
            'author' => 'novelupdates',
            'no_of_chapters' => 'novelupdates',
            'image' => 'novelupdates',
            'genres' => 'novelupdates',
        ],
        'status' => ['mode' => 'fill', 'prefer' => 'novelupdates'],
        'covers' => ['novelupdates', 'site'],
    ];

    public function name(): string
    {
        return 'novelarrow';
    }

    public function hosts(): array
    {
        return self::brandHosts();
    }

    public function matches(Novel $novel): bool
    {
        // Default source — handles novelarrow and anything unrecognised.
        return true;
    }

    public function matchesUrl(string $url): bool
    {
        // The catch-all, like matches().
        return true;
    }

    public function contentSelectors(): array
    {
        return ['#chr-content', '.chr-c', ...self::GENERIC_CONTENT_SELECTORS];
    }

    public function removeSelectors(): array
    {
        return [
            ...self::GENERIC_REMOVE_SELECTORS,
            '.chr-nav', '#chr-nav-top', '#chr-nav-bottom', '.chr-nav-top', '.chr-nav-bottom',
            '.chapter-nav', '.report-chapter', '.ads', '.ad-container', '.share-buttons',
        ];
    }

    public function tableOfContents(Novel $novel): array
    {
        // The complete list lives behind a JSON API keyed by the slug —
        // used for the groups in config novarr.novelarrow_api_group_ids.
        if (InstallIds::contains(InstallIds::novelArrowApiGroupIds(), $novel->group_id)
            && self::isSiteUrl($novel->translator_url ?? '', legacy: true)) {
            $result = self::chapterArchive($novel->translator_url);
            if (!empty($result)) {
                return $result;
            }
            $slug = self::slugFrom($novel->translator_url);
            $previous = (int) $novel->last_toc_count;
            \Log::warning(
                "NovelArrowSource: API chapter list empty for slug '{$slug}' ({$novel->translator_url})"
                . ($previous > 0 ? ", previously {$previous} chapters" : "")
                . "; falling back to page parse"
            );
            // The page only embeds the newest ~30 chapters: whatever the
            // fallback finds is a partial list for TOC health purposes.
            markTocRunPartial();
        }

        // Generic page parse (the novel page's embedded chapter list).
        $result = [];
        $reason = null;
        $html = app(Fetcher::class)->html($novel->translator_url, $reason);
        if ($html !== null) {
            (new Crawler($html))->filter('.list-chapter > li > a')->each(function ($node) use (&$result) {
                $result[] = generateTocChapterInfo($node->text(), trim($node->attr('href')));
            });
            if (array_filter($result) === []) {
                FailureSnapshot::noteTocHtml($html);
            }
        }

        return $result;
    }

    /**
     * Chapter text straight from the JSON API, when the URL is a NovelPing
     * chapter page (decided on URL shape alone, whatever the novel's
     * source). Null when it is not; otherwise the escaped <p> paragraphs,
     * [] with $failure saying why the API gave nothing: 'empty' (no chapter
     * text), 'rate_limited' (HTTP 429 only — a 503 is as often a Cloudflare
     * challenge as a rate limit), 'not_found' (404) or 'fetch_failed'.
     */
    public function chapterContent(string $url, ?string &$failure = null): ?array
    {
        $failure = null;

        if (!self::isSiteUrl($url)) {
            return null;
        }

        $path = trim(parse_url($url, PHP_URL_PATH) ?: "", "/");
        $parts = explode("/", $path);
        if (count($parts) !== 3 || $parts[0] !== "chapter") {
            return null;
        }
        [, $slug, $chapterId] = $parts;
        $slug = self::cleanSlug($slug);

        // Not api(): the HTTP status is needed to tell a rate limit from an
        // empty answer.
        $status = null;
        $json = app(Fetcher::class)->json(
            self::canonicalBase() . "/api-web/novels/" . rawurlencode($slug) . "/chapters/" . rawurlencode($chapterId),
            [],
            $status
        );
        if ($json === null) {
            $failure = match (true) {
                $status === 429 => "rate_limited",
                $status === 404 => "not_found",
                default => "fetch_failed",
            };
            return [];
        }

        $content = $json["item"]["chapterInfo"]["chapter_content"] ?? "";
        if (!is_string($content) || trim($content) === "") {
            $failure = "empty";
            return [];
        }

        $content = str_replace("\u{FEFF}", "", stripChapterNoise($content));

        // Content arrives as <p> blocks (occasionally br-separated inside, with
        // an <h4> title on top). Walk it as a DOM so the heading becomes its own
        // paragraph instead of being glued to the first line, and inline
        // emphasis survives.
        $root = (new Crawler('<html><body><div id="__novarr_root">' . $content . '</div></body></html>'))->filter('#__novarr_root');
        if ($root->count() === 0) {
            return [];
        }

        $result = [];
        foreach (chapterNodeParagraphs($root->getNode(0)) as $inner) {
            $paragraph = finalizeChapterParagraph($inner);
            if ($paragraph !== null) {
                $result[] = $paragraph;
            }
        }

        return $result;
    }

    protected function siteMetadata(Novel $novel): array
    {
        return self::apiMetadata($novel);
    }

    public function metadata(Novel $novel): array
    {
        $metadata = MetadataMerger::merge(
            fn() => $this->siteMetadata($novel),
            fn() => $this->novelUpdatesMetadata($novel),
            static::METADATA_PRECEDENCE
        );

        // Always keep the source site's own cover as a last-resort candidate —
        // it's deterministic by slug, and NovelUpdates' CDN can 403 hotlinked
        // downloads even when its metadata is otherwise complete (so the
        // fallback above never runs).
        if ($slug = $this->coverSlug($novel)) {
            $metadata['cover_candidates'][] = self::coverUrlFor($slug);
        }
        $metadata['cover_candidates'] = array_values(array_unique($metadata['cover_candidates']));

        return $metadata;
    }

    // ---- Discover ----------------------------------------------------------

    public function discoverLabel(): string
    {
        return 'NovelPing (novelarrow)';
    }

    public function discoverHost(): string
    {
        return self::canonicalHost();
    }

    public function discoverLists(): array
    {
        return ['search', 'popular', 'completed'];
    }

    /**
     * The api-web novel list for a Discover list (cached: browse lists an
     * hour, searches ten minutes). Null when the endpoint can't be fetched.
     */
    public function discover(string $type, ?string $query = null): ?array
    {
        $params = match ($type) {
            'search' => ['status' => 'all', 'sort' => 'SEARCH_KEYWORD', 'keyword' => $query],
            'popular' => ['status' => 'all', 'sort' => 'POPULAR'],
            'completed' => ['status' => 'completed', 'sort' => 'COMPLETE'],
        };
        $url = self::canonicalBase() . '/api-web/novels?'
            . http_build_query($params + ['limit' => 40, 'page' => 1, 'genre' => 'ALL']);

        // Browse lists barely change — cache them. Searches are cached
        // briefly. A broken cache store must not take the feature down.
        $ttl = $type === 'search' ? 600 : 3600;
        $cacheKey = 'discover_v5_' . md5($url);

        try {
            return Cache::remember($cacheKey, $ttl, fn() => $this->fetchDiscoverList($url));
        } catch (\Throwable $e) {
            Log::warning('Discover: cache store unavailable (' . $e->getMessage() . ') — fetching uncached');
            return $this->fetchDiscoverList($url);
        }
    }

    /**
     * Library matching by slug: on either brand host, and for a stored
     * novel the legacy novelbin hosts too.
     */
    public function librarySlug(string $url, bool $stored = false): ?string
    {
        if (!self::isSiteUrl($url, legacy: $stored)) {
            return null;
        }
        $slug = self::slugFrom($url);

        return $slug !== '' ? $slug : null;
    }

    /**
     * Fetch a novelping.com api-web novel list into result items.
     * Returns null when the endpoint cannot be fetched.
     */
    protected function fetchDiscoverList(string $url): ?array
    {
        $json = null;

        try {
            // Through the Fetcher (plain HTTP) so tests can intercept it.
            $json = app(Fetcher::class)->json($url);
        } catch (\Exception $e) {
            Log::warning("Discover: fetch failed for {$url}: " . $e->getMessage());
        }

        if (!is_array($json)) {
            Log::error("Discover: could not fetch {$url}");
            return null;
        }

        $items = [];
        foreach ($json['items'] ?? [] as $row) {
            $slug = $row['novel_id'] ?? '';
            $name = trim($row['novel_name'] ?? '');
            if ($slug === '' || $name === '') {
                continue;
            }

            $item = [
                'name' => $name,
                'url' => self::novelPageUrl($slug),
                // Covers live on the image host, keyed by slug (see the
                // site's og:image tags).
                'cover' => self::coverUrlFor($slug),
                'cover_thumb' => '',
                'author' => trim($row['novel_author'] ?? ''),
                'description' => self::plainSynopsis($row['novel_desc'] ?? ''),
            ];
            // Status pill + chapter count for the cover card, when reported.
            $status = self::statusText($row['novel_status'] ?? null);
            if ($status !== null) {
                $item['status'] = $status;
            }
            if (is_numeric($row['totalChapter'] ?? null) && (int) $row['totalChapter'] > 0) {
                $item['chapters'] = (int) $row['totalChapter'];
            }
            $items[] = $item;
        }

        if (empty($items)) {
            Log::warning("Discover: no results from {$url} — API shape may have changed");
        }

        return $items;
    }

    /**
     * Best Novel Arrow slug for this novel: the novel URL when it's already a
     * Novel Arrow one, else the slug embedded in its own chapters' URLs (the
     * definitive source identity), else a guess from the name.
     */
    private function coverSlug(Novel $novel): ?string
    {
        if (!empty($novel->translator_url) && self::isSiteUrl($novel->translator_url, legacy: true)) {
            $slug = self::slugFrom($novel->translator_url);
            if ($slug !== '') {
                return $slug;
            }
        }

        $chapterUrl = $novel->chapters()
            ->where(fn($q) => $q->where('url', 'like', '%novelarrow%')->orWhere('url', 'like', '%novelping%'))
            ->value('url');
        if ($chapterUrl) {
            $slug = self::slugFrom($chapterUrl);
            if ($slug !== '') {
                return $slug;
            }
        }

        return !empty($novel->name) ? novelSlug($novel->name) : null;
    }

    // ---- URL and API helpers (were global functions) -------------------------

    /**
     * Novel Arrow's canonical host. The site rebranded to novelping.com
     * (novelarrow.com 302-redirects there), so every outgoing URL is built on
     * the configured host (config novarr.novelarrow_host,
     * NOVARR_NOVELARROW_HOST) while both brand hosts are still recognised.
     */
    public static function canonicalHost(): string
    {
        $host = strtolower(trim((string) config("novarr.novelarrow_host", "novelping.com")));
        // Tolerate a pasted origin ("https://www.novelping.com/").
        $host = preg_replace('#^[a-z]+://#', "", $host);
        $host = preg_replace('#^www\.#', "", rtrim($host, "/"));

        return $host !== "" ? $host : "novelping.com";
    }

    /** "https://{canonical host}" — the base for every outgoing Novel Arrow URL. */
    public static function canonicalBase(): string
    {
        return "https://" . self::canonicalHost();
    }

    /** Hosts that are the same site: both brands plus the configured one. */
    public static function brandHosts(): array
    {
        return array_values(array_unique(["novelarrow.com", "novelping.com", self::canonicalHost()]));
    }

    /**
     * Whether a URL is on Novel Arrow / NovelPing (either brand, with or
     * without "www."). With $legacy, the pre-2026-07 novelbin hosts count too
     * (their slugs carried over, so slugFrom() still resolves them).
     */
    public static function isSiteUrl(?string $url, bool $legacy = false): bool
    {
        $host = strtolower((string) parse_url(trim((string) $url), PHP_URL_HOST));
        if ($host === "") {
            return false;
        }
        $host = preg_replace('#^www\.#', "", $host);

        if (in_array($host, self::brandHosts(), true)) {
            return true;
        }

        return $legacy && str_contains($host, "novelbin");
    }

    /** Cover image URL for a slug (the image host follows the brand: images.{host}). */
    public static function coverUrlFor(string $slug): string
    {
        return "https://images." . self::canonicalHost() . "/novel/{$slug}.jpg";
    }

    /**
     * A slug as the API wants it. A trailing "_" or "-" (or whitespace) makes
     * the API answer with an EMPTY list instead of a 404
     * ("i-can-see-your-combat-power_"), so trim them.
     */
    public static function cleanSlug(string $slug): string
    {
        return rtrim(trim($slug), "_- \t\n\r\0\x0B");
    }

    /**
     * Extract the novel slug from any Novel Arrow URL shape — a novel page
     * (…/novel/slug), a chapter page (…/chapter/slug/chapter-…) — or a legacy
     * Novel Bin shape (…/novel-book/slug, …/b/slug,
     * …/ajax/chapter-archive?novelId=slug). Slugs are identical across the
     * rebrands (novelbin → novelarrow → novelping), so old URLs still resolve.
     * Trailing "_"/"-"/whitespace is trimmed (see cleanSlug()).
     */
    public static function slugFrom(string $url): string
    {
        $url = trim($url);
        parse_str(parse_url($url, PHP_URL_QUERY) ?: "", $query);

        if (!empty($query["novelId"]) && is_string($query["novelId"])) {
            return self::cleanSlug($query["novelId"]);
        }

        $path = trim(parse_url($url, PHP_URL_PATH) ?: "", "/");
        $parts = $path === "" ? [] : explode("/", $path);

        // Chapter pages carry the slug one segment before the chapter id.
        if (count($parts) >= 2 && $parts[0] === "chapter") {
            return self::cleanSlug($parts[1]);
        }

        return $parts === [] ? "" : self::cleanSlug(end($parts));
    }

    /** Canonical chapter page URL for a slug + chapter id. */
    public static function chapterPageUrl(string $slug, string $chapterId): string
    {
        return self::canonicalBase() . "/chapter/{$slug}/{$chapterId}";
    }

    /** Canonical novel page URL for a slug. */
    public static function novelPageUrl(string $slug): string
    {
        return self::canonicalBase() . "/novel/{$slug}";
    }

    /**
     * GET a Novel Arrow (novelping.com) api-web endpoint on the canonical host
     * and return the decoded JSON, or null on any failure (logged).
     */
    public static function api(string $path): ?array
    {
        $url = self::canonicalBase() . "/api-web/" . ltrim($path, "/");

        return app(Fetcher::class)->json($url);
    }

    /**
     * Fetch the complete chapter list for a Novel Arrow novel via its JSON API
     * (the novel page itself only embeds ~30 chapters).
     */
    public static function chapterArchive(string $novelUrl): array
    {
        $slug = self::slugFrom($novelUrl);

        if ($slug === "") {
            return [];
        }

        $json = self::api("novels/" . rawurlencode($slug) . "/chapters?sort=asc");

        $result = [];
        foreach (($json["items"] ?? []) as $item) {
            $chapterId = trim($item["chapter_id"] ?? "");
            $label = trim(preg_replace('/\s+/', " ", $item["chapter_name"] ?? ""));

            if ($chapterId !== "" && $label !== "") {
                $result[] = generateTocChapterInfo($label, self::chapterPageUrl($slug, $chapterId));
            }
        }

        $result = array_values(array_filter($result));
        if ($result === []) {
            // The API answers an unknown/mangled slug with an empty list, not a 404.
            \Log::warning("novelArrowChapterArchive: API returned 0 chapters for slug '{$slug}'" . ($json === null ? " (request failed)" : ""));
        } else {
            \Log::info("novelArrowChapterArchive: parsed " . count($result) . " chapters for {$slug}");
        }

        return $result;
    }

    /**
     * Novel Arrow's `novel_status` (0 = ongoing, 1 = completed — the API's
     * "completed" browse list only carries 1s) as a status_text, or null when
     * absent / unrecognised. Strings ("Completed", "ONGOING") are accepted too.
     */
    public static function statusText($status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }
        if (is_numeric($status)) {
            return match ((int) $status) {
                0 => "Ongoing",
                1 => "Completed",
                default => null,
            };
        }
        $status = strtolower(trim((string) $status));
        if (str_contains($status, "complete")) {
            return "Completed";
        }
        if (str_contains($status, "ongoing")) {
            return "Ongoing";
        }

        return null;
    }

    /**
     * Fetch novel metadata from novelping.com (formerly novelarrow / novelbin) as a fallback
     * source, via its JSON API. Tries the slug from translator_url first when
     * it's already a Novel Arrow URL, then a slug built from the novel name.
     */
    public static function apiMetadata($data): array
    {
        $metadata = [
            "description" => "",
            "author" => "",
            "no_of_chapters" => 0,
            "image" => "",
            "genres" => [],
        ];

        $slugs = [];

        if (!empty($data->translator_url) && self::isSiteUrl($data->translator_url, legacy: true)) {
            $slug = self::slugFrom($data->translator_url);
            if ($slug !== "") {
                $slugs[] = $slug;
            }
        }

        if (!empty($data->name)) {
            $slugs[] = novelSlug($data->name);
        }

        $slugs = array_values(array_unique($slugs));
        $metadata["tried_urls"] = array_map(fn($s) => self::novelPageUrl($s), $slugs);

        foreach ($slugs as $slug) {
            $json = self::api("novels/" . rawurlencode($slug));
            $info = $json["item"]["novelInfo"] ?? null;

            if (empty($info)) {
                \Log::warning("getMetadataFromNovelArrow: no data for slug '{$slug}'");
                continue;
            }

            $metadata["description"] = trim($info["novel_desc"] ?? "");
            $metadata["author"] = trim($info["novel_author"] ?? "");
            $metadata["no_of_chapters"] = (int) ($info["totalChapter"] ?? 0);

            $status = self::statusText($info["novel_status"] ?? null);
            if ($status !== null) {
                $metadata["status_text"] = $status;
                $metadata["completed"] = $status === "Completed";
            }

            // Covers live on the image host, keyed by slug (see the site's
            // og:image tags) — downloadCoverImage() validates it's a real image.
            $metadata["image"] = self::coverUrlFor($slug);

            $genres = $info["novel_genres"] ?? [];
            if (empty($genres)) {
                $genres = $info["novel_tags"] ?? [];
            }
            if (!empty($genres)) {
                $metadata["genres"] = normalizeGenres(is_array($genres) ? $genres : explode(",", $genres));
            }

            \Log::debug("getMetadataFromNovelArrow fetched slug '{$slug}'", [
                'has_description' => !empty($metadata['description']),
                'has_author' => !empty($metadata['author']),
                'no_of_chapters' => $metadata['no_of_chapters'],
            ]);

            // Stop on the first slug that yields any useful field.
            if (!empty($metadata['description']) || !empty($metadata['author'])) {
                break;
            }
        }

        return $metadata;
    }
}
