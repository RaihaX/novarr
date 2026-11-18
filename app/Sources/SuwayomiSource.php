<?php

namespace App\Sources;

use App\Novel;
use App\Scraping\Fetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Manga through the user's own Suwayomi-Server (config novarr.suwayomi):
 * Suwayomi runs the Tachiyomi/Mihon extensions and fetches from the sites
 * itself; Novarr only talks to its local GraphQL API (no account):
 *
 *   TOC        mutation fetchChapters(mangaId)
 *   metadata   mutation fetchManga(id); cover GET /api/v1/manga/{id}/thumbnail
 *   pages      query chapters(mangaId, sourceOrder) → mutation fetchChapterPages(chapterId)
 *              → GET {base}/api/v1/manga/{id}/chapter/{order}/page/{n}
 *   Discover   query sources; mutation fetchSourceManga(source, SEARCH|POPULAR)
 *
 * (fetchManga / fetchChapters are marked deprecated in favour of
 * fetchMangaAndChapters in Suwayomi v2.x but still answer; verified against
 * v2.4.2366.) The manga does not have to be in Suwayomi's library.
 *
 * Identity: a series is {base}/manga/{mangaId} and a chapter
 * {base}/manga/{mangaId}/chapter/{sourceOrder} — Suwayomi's own web UI URLs.
 * Only URLs on the configured origin (scheme, host, port and base path) are
 * claimed; with no URL configured the source claims nothing, lists nothing
 * and never makes a request.
 *
 * On Discover this one class is one entry per Suwayomi source (ExpandsDiscover):
 * a scoped instance carries the Suwayomi source id; the registered, unscoped
 * instance resolves URLs and lists nothing (no network on the resolution
 * path, which runs for every novel).
 */
class SuwayomiSource extends AbstractSource implements ExpandsDiscover, PagedDiscover
{
    public const SERIES_PATH = '#^/manga/(\d{1,18})/?$#';
    public const CHAPTER_PATH = '#^/manga/(\d{1,18})/chapter/(\d{1,9})/?$#';
    public const THUMBNAIL_PATH = '#^/api/v1/manga/\d{1,18}/thumbnail$#';

    /** The source list is cached an hour; a failed listing is not cached. */
    public const SOURCES_CACHE_SECONDS = 3600;

    /**
     * The source listing runs while the Discover page renders: it gets a
     * shorter timeout than the configured one, and its outcome (even a
     * failure) is reused for this long in the same process, so a dead
     * server is asked once per page load instead of once per lookup.
     */
    public const SOURCES_TIMEOUT = 15;
    public const SOURCES_MEMO_SECONDS = 60;

    /** Genres that mean a vertical long-strip read. */
    public const VERTICAL_GENRES = '/manhwa|manhua|webtoon|long\s*strip/i';

    /** Suwayomi's MangaStatus → Novarr's status_text (UNKNOWN: none). */
    public const STATUS_TEXT = [
        'ONGOING' => 'Ongoing',
        'COMPLETED' => 'Completed',
        // The original run has ended (MangaDex's "completed" means the same).
        'PUBLISHING_FINISHED' => 'Completed',
        'ON_HIATUS' => 'Hiatus',
        'CANCELLED' => 'Cancelled',
        'LICENSED' => 'Licensed',
    ];

    public const QUERY_SOURCES = 'query { sources { nodes { id name lang isNsfw supportsLatest } } }';
    public const QUERY_CHAPTER_ID = 'query ($mangaId: Int!, $sourceOrder: Int!) { chapters(condition: {mangaId: $mangaId, sourceOrder: $sourceOrder}) { nodes { id } } }';
    public const MUTATION_FETCH_CHAPTERS = 'mutation ($input: FetchChaptersInput!) { fetchChapters(input: $input) { chapters { id name chapterNumber sourceOrder scanlator uploadDate } } }';
    public const MUTATION_FETCH_MANGA = 'mutation ($input: FetchMangaInput!) { fetchManga(input: $input) { manga { id title author artist status genre description thumbnailUrl sourceId source { name } } } }';
    public const MUTATION_FETCH_PAGES = 'mutation ($input: FetchChapterPagesInput!) { fetchChapterPages(input: $input) { pages } }';
    public const MUTATION_FETCH_SOURCE_MANGA = 'mutation ($input: FetchSourceMangaInput!) { fetchSourceManga(input: $input) { hasNextPage mangas { id title url thumbnailUrl inLibrary status author genre description } } }';

    /** The Suwayomi source of a manga already on the server (cheap: no fetch from the site). */
    public const QUERY_MANGA_SOURCE = 'query ($id: Int!) { manga(id: $id) { sourceId source { name } } }';

    /** Download lanes of Suwayomi novels: "suwayomi:{sourceId}" (App\Services\DownloadLanes). */
    public const LANE_PREFIX = 'suwayomi:';

    /** The source lookup runs while lanes are worked out: short. */
    public const SOURCE_REF_TIMEOUT = 10;

    /**
     * @param ?string $sourceId   the Suwayomi source a Discover entry lists (null: the registered instance)
     * @param ?string $sourceName its name, for the picker label
     * @param ?string $sourceLang its language, named in the label when several are configured
     */
    public function __construct(
        private ?string $sourceId = null,
        private ?string $sourceName = null,
        private ?string $sourceLang = null,
    ) {
    }

    public function name(): string
    {
        return 'suwayomi';
    }

    public function medium(): string
    {
        return Novel::MEDIUM_MANGA;
    }

    /** The Suwayomi source this instance lists on Discover, or null. */
    public function sourceId(): ?string
    {
        return $this->sourceId;
    }

    // ---- Configuration -------------------------------------------------------

    /** The configured base URL without a trailing slash; '' when the source is off. */
    public static function baseUrl(): string
    {
        $url = rtrim(trim((string) config('novarr.suwayomi.url', '')), '/');

        return self::origin($url) !== null ? $url : '';
    }

    public static function configured(): bool
    {
        return self::baseUrl() !== '';
    }

    /** Seconds a GraphQL call may take (config novarr.suwayomi.timeout). */
    public static function timeout(): int
    {
        return max(1, (int) config('novarr.suwayomi.timeout', 90));
    }

    /** Configured source languages, lower-cased (config novarr.suwayomi.languages). */
    public static function languages(): array
    {
        $languages = array_values(array_unique(array_filter(array_map(
            fn($l) => strtolower(trim((string) $l)),
            (array) config('novarr.suwayomi.languages', ['en'])
        ), fn($l) => $l !== '')));

        return $languages ?: ['en'];
    }

    /** Source names left off Discover, lower-cased (config novarr.suwayomi.exclude_sources). */
    public static function excludedSources(): array
    {
        return array_values(array_filter(array_map(
            fn($n) => mb_strtolower(trim((string) $n)),
            (array) config('novarr.suwayomi.exclude_sources', [])
        ), fn($n) => $n !== ''));
    }

    // ---- URLs ----------------------------------------------------------------

    /**
     * "scheme://host:port" of an http(s) URL, lower-cased, with the default
     * port filled in (so http://h and http://h:80 are one origin); null for
     * anything else. Pure.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return "{$scheme}://{$host}:{$port}";
    }

    /**
     * The path of a URL relative to the configured base (starting with "/"),
     * or null when the URL is not on the base's origin and path. Pure but
     * for the config read.
     */
    private static function relativePath(string $url): ?string
    {
        $base = self::baseUrl();
        if ($base === '' || self::origin($url) !== self::origin($base)) {
            return null;
        }

        $basePath = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
        $path = (string) parse_url(trim($url), PHP_URL_PATH);
        if ($basePath !== '') {
            if ($path !== $basePath && !str_starts_with($path, $basePath . '/')) {
                return null;
            }
            $path = substr($path, strlen($basePath));
        }

        return $path === '' ? '/' : $path;
    }

    /** A series ({base}/manga/{id}) or chapter URL on the configured server. */
    public function matchesUrl(string $url): bool
    {
        $path = self::relativePath($url);

        return $path !== null && (preg_match(self::SERIES_PATH, $path) === 1 || preg_match(self::CHAPTER_PATH, $path) === 1);
    }

    /** Suwayomi's manga id of a series or chapter URL on the configured server, else null. */
    public static function mangaId(?string $url): ?int
    {
        $path = self::relativePath((string) $url);
        if ($path !== null && (preg_match(self::SERIES_PATH, $path, $m) || preg_match(self::CHAPTER_PATH, $path, $m))) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * The manga id and source order of a chapter URL on the configured
     * server, else null.
     *
     * @return ?array{manga: int, order: int}
     */
    public static function chapterRef(?string $url): ?array
    {
        $path = self::relativePath((string) $url);

        return $path !== null && preg_match(self::CHAPTER_PATH, $path, $m)
            ? ['manga' => (int) $m[1], 'order' => (int) $m[2]]
            : null;
    }

    public static function seriesUrl(int|string $mangaId): string
    {
        return self::baseUrl() . '/manga/' . (int) $mangaId;
    }

    public static function chapterUrl(int|string $mangaId, int|string $sourceOrder): string
    {
        return self::seriesUrl($mangaId) . '/chapter/' . (int) $sourceOrder;
    }

    public static function thumbnailUrl(int|string $mangaId): string
    {
        return self::baseUrl() . '/api/v1/manga/' . (int) $mangaId . '/thumbnail';
    }

    /**
     * Is this a manga thumbnail on the configured server (and nothing
     * else: no query, no other path)? The Discover cover proxy fetches only
     * these from Suwayomi.
     */
    public static function isThumbnailUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $path = self::relativePath($url);

        return $path !== null && preg_match(self::THUMBNAIL_PATH, $path) === 1;
    }

    /** An absolute URL on the server for a path Suwayomi returned ("/api/v1/…"), else null. */
    public static function absolute(?string $path): ?string
    {
        $path = trim((string) $path);
        $base = self::baseUrl();
        if ($base === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $base . $path;
    }

    /** Library match by Suwayomi's manga id. */
    public function librarySlug(string $url, bool $stored = false): ?string
    {
        $id = self::mangaId($url);

        return $id !== null ? (string) $id : null;
    }

    // ---- API -----------------------------------------------------------------

    /**
     * One GraphQL request. Returns the `data` object, or null when the
     * server could not be reached, answered other than 200, or reported
     * `errors` (Suwayomi answers 200 either way; the first message is logged,
     * cut short — they carry whole Java stack traces). User input only ever
     * travels in $variables, never in the query text. $status receives the
     * HTTP status (or 'exception').
     */
    protected function graphql(string $query, array $variables = [], ?int $timeout = null, &$status = null): ?array
    {
        $status = null;
        $base = self::baseUrl();
        if ($base === '') {
            return null;
        }

        $body = ['query' => $query];
        if ($variables !== []) {
            $body['variables'] = $variables;
        }
        $json = app(Fetcher::class)->postJsonForJson("{$base}/api/graphql", $body, [], $timeout ?? self::timeout(), $status);
        if (!is_array($json)) {
            Log::warning('SuwayomiSource: GraphQL request failed (HTTP ' . ($status ?? '?') . ')');
            return null;
        }
        if (!empty($json['errors'])) {
            Log::warning('SuwayomiSource: GraphQL error: ' . self::errorMessage($json['errors']));
            return null;
        }

        return is_array($json['data'] ?? null) ? $json['data'] : null;
    }

    /** The first GraphQL error's first line, at most ~200 characters. Pure. */
    public static function errorMessage($errors): string
    {
        $message = is_array($errors) ? (string) ($errors[0]['message'] ?? 'unknown error') : 'unknown error';
        $line = trim(strtok($message, "\r\n") ?: $message);

        return mb_strimwidth($line, 0, 200, '…');
    }

    /** ChapterScraper's category for a failed GraphQL call. */
    private static function failureFor($status): string
    {
        return Fetcher::isRateLimitStatus($status) ? 'rate_limited' : 'fetch_failed';
    }

    // ---- Table of contents ---------------------------------------------------

    /**
     * The chapter list Suwayomi fetches from the site now (fetchChapters can
     * take up to a minute) as TOC rows; [] when it could not be had (as
     * MangaDexSource: an empty TOC, logged).
     */
    public function tableOfContents(Novel $novel): array
    {
        $id = self::mangaId($novel->translator_url);
        if ($id === null) {
            Log::warning("SuwayomiSource: not a Suwayomi series URL (or no server configured): {$novel->translator_url}");
            return [];
        }

        $data = $this->graphql(self::MUTATION_FETCH_CHAPTERS, ['input' => ['mangaId' => $id]]);
        $chapters = $data['fetchChapters']['chapters'] ?? null;
        if (!is_array($chapters)) {
            Log::warning("SuwayomiSource: chapter list of manga {$id} failed");
            return [];
        }

        return self::tocRows($chapters, $id);
    }

    /**
     * fetchChapters rows → TOC rows. Pure but for the base URL.
     *
     * One row per chapter number: the copy whose scanlator has the most
     * chapters in this list wins, ties to the earliest uploadDate, then the
     * lowest chapter id — stable between runs. Chapters without a number
     * (null or < 0) become one row, number 0, labelled with the chapter's
     * name. `book` is always 0; rows are ordered by number, then source order.
     *
     * @param array<int, array> $chapters
     * @return array<int, array{label: string, url: string, chapter: string, book: int}>
     */
    public static function tocRows(array $chapters, int $mangaId): array
    {
        $entries = [];
        $groupCounts = [];
        foreach ($chapters as $chapter) {
            $id = $chapter['id'] ?? null;
            $order = $chapter['sourceOrder'] ?? null;
            if (!is_numeric($id) || !is_numeric($order)) {
                continue;
            }

            $raw = $chapter['chapterNumber'] ?? null;
            $number = is_numeric($raw) && (float) $raw >= 0 ? MangaDexSource::numberString((string) $raw) : null;
            $scanlator = trim((string) ($chapter['scanlator'] ?? ''));
            $group = $scanlator === '' ? '' : mb_strtolower($scanlator);
            $uploaded = $chapter['uploadDate'] ?? null;

            $entries[] = [
                'id' => (int) $id,
                'order' => (int) $order,
                'key' => $number ?? 'unnumbered',
                'number' => $number,
                'name' => trim(preg_replace('/\s+/u', ' ', (string) ($chapter['name'] ?? '')) ?? ''),
                'group' => $group,
                // Missing / 0 (unknown) never counts as the earliest.
                'uploaded' => is_numeric($uploaded) && (int) $uploaded > 0 ? (int) $uploaded : PHP_INT_MAX,
            ];
            $groupCounts[$group] = ($groupCounts[$group] ?? 0) + 1;
        }

        $best = [];
        foreach ($entries as $entry) {
            $current = $best[$entry['key']] ?? null;
            if ($current === null || self::prefer($entry, $current, $groupCounts) < 0) {
                $best[$entry['key']] = $entry;
            }
        }

        uasort($best, function (array $a, array $b) {
            $x = $a['number'] === null ? -1.0 : (float) $a['number'];
            $y = $b['number'] === null ? -1.0 : (float) $b['number'];

            return $x <=> $y ?: $a['order'] <=> $b['order'];
        });

        $rows = [];
        foreach ($best as $entry) {
            $rows[] = [
                'label' => $entry['number'] === null
                    ? mb_substr($entry['name'] !== '' ? $entry['name'] : 'Oneshot', 0, 250)
                    : MangaDexSource::label($entry['number'], self::chapterTitle($entry['name'], $entry['number'])),
                'url' => self::chapterUrl($mangaId, $entry['order']),
                'chapter' => $entry['number'] ?? '0',
                'book' => 0,
            ];
        }

        return $rows;
    }

    /** <0 when $a should be kept over $b. */
    private static function prefer(array $a, array $b, array $groupCounts): int
    {
        return ($groupCounts[$b['group']] <=> $groupCounts[$a['group']])
            ?: (($a['uploaded'] <=> $b['uploaded']) ?: ($a['id'] <=> $b['id']));
    }

    /**
     * The title part of a Suwayomi chapter name: a leading "Vol.2 Chapter
     * 12:" / "Ch. 12 -" for this chapter's own number is cut, so the label
     * is not "Chapter 12: Chapter 12: …". A name that does not start with the
     * number is kept whole. Pure.
     */
    public static function chapterTitle(string $name, string $number): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (preg_match('/^(?:vol(?:ume)?\.?\s*\d+(?:\.\d+)?\s*[,:\-–—]?\s*)?(?:chapter|chap\.?|ch\.?|episode|ep\.?)\s*(\d+(?:\.\d+)?)(?!\d)(.*)$/iu', $name, $m)
            && MangaDexSource::numberString($m[1]) === $number) {
            return trim(preg_replace('/^[\s:\-–—.|]+/u', '', $m[2]) ?? '');
        }

        return $name;
    }

    // ---- Metadata ------------------------------------------------------------

    /**
     * The series' own metadata from Suwayomi (never NovelUpdates: a manga
     * is not on it). Keys as MangaDexSource::metadata().
     */
    public function metadata(Novel $novel): array
    {
        $metadata = [
            'description' => '',
            'author' => '',
            'no_of_chapters' => 0,
            'image' => '',
            'genres' => [],
            'cover_candidates' => [],
            'tried_urls' => [],
        ];

        $id = self::mangaId($novel->translator_url);
        if ($id === null) {
            return $metadata;
        }
        $metadata['tried_urls'] = [self::seriesUrl($id)];

        $data = $this->graphql(self::MUTATION_FETCH_MANGA, ['input' => ['id' => $id]]);
        $manga = $data['fetchManga']['manga'] ?? null;
        if (!is_array($manga)) {
            Log::warning("SuwayomiSource: no metadata for manga {$id}");
            return $metadata;
        }

        return array_merge($metadata, self::seriesMetadata($manga + ['id' => $id]));
    }

    /**
     * One fetchManga `manga` → metadata fields. Pure but for the base URL.
     * Suwayomi does not know the chapter count up front, so no_of_chapters
     * stays the caller's 0 (unknown).
     */
    public static function seriesMetadata(array $manga): array
    {
        $id = (int) ($manga['id'] ?? 0);

        $names = [];
        foreach ([$manga['author'] ?? '', $manga['artist'] ?? ''] as $name) {
            $name = trim((string) $name);
            if ($name !== '' && !in_array(mb_strtolower($name), array_map('mb_strtolower', $names), true)) {
                $names[] = $name;
            }
        }

        $genres = array_values(array_filter(array_map(fn($g) => is_string($g) ? trim($g) : '', (array) ($manga['genre'] ?? []))));
        $vertical = false;
        foreach ($genres as $genre) {
            if (preg_match(self::VERTICAL_GENRES, $genre)) {
                $vertical = true;
                break;
            }
        }

        $cover = $id > 0 ? self::thumbnailUrl($id) : '';
        $sourceRef = trim((string) ($manga['sourceId'] ?? ''));
        $metadata = [
            'title' => trim((string) ($manga['title'] ?? '')),
            'description' => self::descriptionHtml((string) ($manga['description'] ?? '')),
            'author' => implode(', ', $names),
            'image' => $cover,
            'genres' => normalizeGenres($genres),
            'cover_candidates' => $cover !== '' ? [$cover] : [],
            // Japanese right-to-left unless the genres say long strip.
            'reading_mode' => $vertical ? 'vertical' : 'rtl',
        ];

        // The site behind the server: the novel's download lane.
        if (self::validSourceId($sourceRef)) {
            $metadata['source_ref'] = $sourceRef;
        }

        $status = strtoupper(trim((string) ($manga['status'] ?? '')));
        if (isset(self::STATUS_TEXT[$status])) {
            $metadata['status_text'] = self::STATUS_TEXT[$status];
            $metadata['completed'] = self::STATUS_TEXT[$status] === 'Completed';
        }

        return $metadata;
    }

    // ---- Download lane ---------------------------------------------------------

    /** Is this a Suwayomi source id (digits only)? Pure. */
    public static function validSourceId(?string $id): bool
    {
        return is_string($id) && preg_match('/^\d{1,20}$/', $id) === 1;
    }

    /**
     * Every Suwayomi novel shares the server's host, but each Suwayomi
     * source is its own site: "suwayomi:{sourceId}" from novels.source_ref.
     * Null while the source is unknown (DownloadLanes then backfills it with
     * fetchSourceRef(), or uses the host). No request.
     */
    public function downloadLane(Novel $novel): ?string
    {
        $ref = $novel->source_ref;

        return self::validSourceId($ref) && $this->matches($novel) ? self::LANE_PREFIX . $ref : null;
    }

    /**
     * The Suwayomi source of a series on the server: ['id' => …, 'name' => …],
     * or null when the server could not say (short timeout, logged). One
     * cheap query — Suwayomi answers from its own database.
     *
     * @return ?array{id: string, name: string}
     */
    public function fetchSourceRef(Novel $novel): ?array
    {
        $id = self::mangaId($novel->translator_url);
        if ($id === null) {
            return null;
        }

        $data = $this->graphql(self::QUERY_MANGA_SOURCE, ['id' => $id], min(self::SOURCE_REF_TIMEOUT, self::timeout()));
        $sourceId = trim((string) ($data['manga']['sourceId'] ?? ''));
        if (!self::validSourceId($sourceId)) {
            return null;
        }

        return ['id' => $sourceId, 'name' => trim((string) ($data['manga']['source']['name'] ?? ''))];
    }

    /**
     * A source's name from the cached source list only (never a request —
     * the Activity page and logs use it), else null.
     */
    public static function cachedSourceName(string $sourceId): ?string
    {
        try {
            $list = Cache::get(self::sourcesCacheKey());
        } catch (\Throwable $e) {
            return null;
        }
        foreach (is_array($list) ? $list : [] as $row) {
            if (($row['id'] ?? null) === $sourceId && ($row['name'] ?? '') !== '') {
                return (string) $row['name'];
            }
        }

        // The hourly list has expired (or the server was down when it did):
        // the names kept from the last successful listing still apply.
        try {
            $names = Cache::get(self::sourceNamesCacheKey());
        } catch (\Throwable $e) {
            return null;
        }
        $name = is_array($names) ? ($names[$sourceId] ?? '') : '';

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Cache key of the id → name map kept without expiry (cachedSourceName()). */
    public static function sourceNamesCacheKey(): string
    {
        return 'suwayomi_source_names_v1_' . md5(self::baseUrl());
    }

    /**
     * Make sure a source's name is known for labels, asking the server for
     * its source list when it is not (one request, then cached). Called by a
     * download run, which talks to the server anyway — never by a page view.
     */
    public function ensureSourceName(string $sourceId): ?string
    {
        if (self::cachedSourceName($sourceId) === null) {
            $this->sourceList();
        }

        return self::cachedSourceName($sourceId);
    }

    /** Plain-text description → <p> paragraphs (blank lines split, single newlines kept). Pure. */
    public static function descriptionHtml(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }

        $html = '';
        foreach (preg_split("/\n\s*\n/", $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph !== '') {
                $html .= '<p>' . nl2br(e($paragraph), false) . '</p>';
            }
        }

        return $html;
    }

    // ---- Pages ---------------------------------------------------------------

    /**
     * The chapter's page images: the chapter id is looked up by (manga id,
     * source order), then Suwayomi lists the pages (fetching the list from
     * the site). Each page is a GET on the server, which fetches the image
     * from the site at its own pace. Null for a URL that is not a chapter
     * on the configured server; [] with $failure as MangaDexSource's
     * ('not_found' when Suwayomi has no such chapter, 'no_content' for an
     * empty list, 'rate_limited' / 'fetch_failed' when a call failed).
     */
    public function chapterPages(string $url, ?string &$failure = null): ?array
    {
        $failure = null;
        $ref = self::chapterRef($url);
        if ($ref === null) {
            return null;
        }

        $status = null;
        $data = $this->graphql(self::QUERY_CHAPTER_ID, ['mangaId' => $ref['manga'], 'sourceOrder' => $ref['order']], null, $status);
        if ($data === null) {
            $failure = self::failureFor($status);
            return [];
        }
        $chapterId = $data['chapters']['nodes'][0]['id'] ?? null;
        if (!is_numeric($chapterId)) {
            $failure = 'not_found';
            return [];
        }

        $data = $this->graphql(self::MUTATION_FETCH_PAGES, ['input' => ['chapterId' => (int) $chapterId]], null, $status);
        if ($data === null) {
            $failure = self::failureFor($status);
            return [];
        }
        $paths = $data['fetchChapterPages']['pages'] ?? null;
        if (!is_array($paths) || $paths === []) {
            $failure = 'no_content';
            return [];
        }

        $pages = [];
        foreach (array_values($paths) as $path) {
            $absolute = is_string($path) ? self::absolute($path) : null;
            if ($absolute !== null) {
                $pages[] = ['url' => $absolute, 'headers' => []];
            }
        }
        if ($pages === []) {
            $failure = 'no_content';
        }

        return $pages;
    }

    // ---- Discover ------------------------------------------------------------

    /**
     * One scoped instance per Suwayomi source in the configured languages,
     * minus the excluded names, sorted by name. [] for a scoped instance,
     * when not configured, or when the listing failed.
     */
    public function discoverSources(): array
    {
        if ($this->sourceId !== null || !self::configured()) {
            return [];
        }

        $languages = self::languages();
        $excluded = self::excludedSources();
        $entries = [];
        foreach ($this->sourceList() ?? [] as $row) {
            if (!in_array($row['lang'], $languages, true) || in_array(mb_strtolower($row['name']), $excluded, true)) {
                continue;
            }
            $entries[$row['id']] = new static($row['id'], $row['name'], $row['lang']);
        }
        uasort($entries, fn(self $a, self $b) => strcasecmp((string) $a->sourceName, (string) $b->sourceName)
            ?: strcmp((string) $a->sourceLang, (string) $b->sourceLang));

        return $entries;
    }

    /**
     * Every source on the server, [['id', 'name', 'lang']] (cached an hour;
     * filtering happens after the cache, so a config change applies at
     * once). Null when the server could not be asked. See SOURCES_MEMO_SECONDS.
     */
    protected function sourceList(): ?array
    {
        $base = self::baseUrl();
        $memoKey = 'novarr.suwayomi.sources.' . md5($base);
        $memo = app()->bound($memoKey) ? app($memoKey) : null;
        if (is_array($memo) && $memo['at'] > microtime(true) - self::SOURCES_MEMO_SECONDS) {
            return $memo['list'];
        }

        $cacheKey = self::sourcesCacheKey();
        $list = null;
        try {
            $cached = Cache::get($cacheKey);
            $list = is_array($cached) ? $cached : null;
        } catch (\Throwable $e) {
            // A broken cache store only costs a request.
        }

        if ($list === null) {
            $data = $this->graphql(self::QUERY_SOURCES, [], min(self::SOURCES_TIMEOUT, self::timeout()));
            $nodes = $data['sources']['nodes'] ?? null;
            if (is_array($nodes)) {
                $list = self::sourceRows($nodes);
                try {
                    Cache::put($cacheKey, $list, self::SOURCES_CACHE_SECONDS);
                    // Names outlive the list, so labels survive an outage.
                    $names = array_filter(array_column($list, 'name', 'id'), fn($n) => is_string($n) && $n !== '');
                    $known = Cache::get(self::sourceNamesCacheKey());
                    Cache::forever(self::sourceNamesCacheKey(), $names + (is_array($known) ? $known : []));
                } catch (\Throwable $e) {
                    // Uncached is fine.
                }
            } else {
                Log::warning("SuwayomiSource: could not list the sources of {$base}; no Suwayomi entries on Discover for now");
            }
        }

        app()->instance($memoKey, ['at' => microtime(true), 'list' => $list]);

        return $list;
    }

    /** Cache key of the server's source list (sourceList()). */
    public static function sourcesCacheKey(): string
    {
        return 'suwayomi_sources_v1_' . md5(self::baseUrl());
    }

    /** `sources` nodes → [['id', 'name', 'lang']] (ids digits only, lang lower-cased). Pure. */
    public static function sourceRows(array $nodes): array
    {
        $rows = [];
        foreach ($nodes as $node) {
            $id = trim((string) ($node['id'] ?? ''));
            $name = trim((string) ($node['name'] ?? ''));
            if (!preg_match('/^\d{1,20}$/', $id) || $name === '') {
                continue;
            }
            $rows[] = ['id' => $id, 'name' => $name, 'lang' => strtolower(trim((string) ($node['lang'] ?? '')))];
        }

        return $rows;
    }

    public function discoverLabel(): string
    {
        if ($this->sourceId === null) {
            return 'Suwayomi';
        }
        $label = 'Suwayomi · ' . ($this->sourceName ?: $this->sourceId);

        return count(self::languages()) > 1 && $this->sourceLang ? $label . ' (' . strtoupper($this->sourceLang) . ')' : $label;
    }

    /** host[:port] of the configured server. */
    public function discoverHost(): string
    {
        $base = self::baseUrl();
        if ($base === '') {
            return 'suwayomi';
        }
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $port = parse_url($base, PHP_URL_PORT);

        return $port ? "{$host}:{$port}" : $host;
    }

    /**
     * Search and popular for a scoped instance; nothing for the registered
     * one. (Suwayomi's LATEST has no matching Discover list type.)
     */
    public function discoverLists(): array
    {
        return $this->sourceId !== null && self::configured() ? ['search', 'popular'] : [];
    }

    /**
     * Page 1 of the Suwayomi source's search or popular list (see
     * discoverPage()). Null when the server (or the site behind it) could
     * not be reached.
     */
    public function discover(string $type, ?string $query = null): ?array
    {
        return $this->discoverPage($type, $query, 1)['items'] ?? null;
    }

    /**
     * Page $page (1-based, Suwayomi's own paging) of the source's search
     * or popular list; `has_more` is Suwayomi's hasNextPage (never past
     * PagedDiscover::MAX_PAGE). Each page is cached on its own: popular an
     * hour, searches ten minutes.
     */
    public function discoverPage(string $type, ?string $query, int $page): ?array
    {
        $none = ['items' => [], 'has_more' => false];
        if (!in_array($type, $this->discoverLists(), true) || $page < 1 || $page > PagedDiscover::MAX_PAGE) {
            return $none;
        }

        $input = ['source' => (string) $this->sourceId, 'type' => 'POPULAR', 'page' => $page];
        if ($type === 'search') {
            $query = trim((string) $query);
            if ($query === '') {
                return $none;
            }
            $input = ['source' => (string) $this->sourceId, 'type' => 'SEARCH', 'query' => $query, 'page' => $page];
        }

        $ttl = $type === 'search' ? 600 : 3600;
        $key = 'discover_suwayomi_v2_' . md5(json_encode([self::baseUrl(), $input]));
        $fetch = function () use ($input, $page) {
            $data = $this->graphql(self::MUTATION_FETCH_SOURCE_MANGA, ['input' => $input]);
            $mangas = $data['fetchSourceManga']['mangas'] ?? null;
            if (!is_array($mangas)) {
                return null;
            }

            return [
                'items' => self::discoverItems($mangas),
                'has_more' => ($data['fetchSourceManga']['hasNextPage'] ?? false) === true && $page < PagedDiscover::MAX_PAGE,
            ];
        };

        try {
            $cached = Cache::get($key);
            if (is_array($cached) && is_array($cached['items'] ?? null) && is_bool($cached['has_more'] ?? null)) {
                return $cached;
            }
            $result = $fetch();
            if ($result !== null) {
                Cache::put($key, $result, $ttl);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('Discover: cache store unavailable (' . $e->getMessage() . ') — fetching uncached');
            return $fetch();
        }
    }

    /**
     * fetchSourceManga `mangas` → Discover items: name, url, cover (the
     * server's thumbnail — Discover::results() routes it through Novarr),
     * author, description, status. Pure but for the base URL.
     */
    public static function discoverItems(array $mangas): array
    {
        $items = [];
        foreach ($mangas as $manga) {
            $id = $manga['id'] ?? null;
            $name = trim((string) ($manga['title'] ?? ''));
            if (!is_numeric($id) || (int) $id <= 0 || $name === '') {
                continue;
            }

            $thumbnail = trim((string) ($manga['thumbnailUrl'] ?? ''));
            $item = [
                'name' => $name,
                'url' => self::seriesUrl((int) $id),
                'cover' => preg_match('#^https?://#i', $thumbnail) ? $thumbnail : (self::absolute($thumbnail) ?? ''),
                'cover_thumb' => '',
                'author' => trim((string) ($manga['author'] ?? '')),
                'description' => self::plainSynopsis(e(trim((string) ($manga['description'] ?? '')))),
            ];
            $status = strtoupper(trim((string) ($manga['status'] ?? '')));
            if (isset(self::STATUS_TEXT[$status])) {
                $item['status'] = self::STATUS_TEXT[$status];
            }
            $items[] = $item;
        }

        return $items;
    }
}
