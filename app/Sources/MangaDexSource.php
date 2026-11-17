<?php

namespace App\Sources;

use App\Novel;
use App\Scraping\Fetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * MangaDex (mangadex.org) — the first manga source. Everything comes from
 * the public JSON API at api.mangadex.org (no account):
 *
 *   TOC        GET /manga/{id}/feed (paginated, 500 per page)
 *   metadata   GET /manga/{id}?includes[]=cover_art&includes[]=author&includes[]=artist
 *   pages      GET /at-home/server/{chapterId} → {baseUrl}/data/{hash}/{file}
 *   Discover   GET /manga (title search / followedCount order)
 *
 * MangaDex rules this adapter follows (api.mangadex.org/docs/2-limitations):
 * an honest User-Agent on every request (config novarr.mangadex.user_agent;
 * never the scraper's browser one), ~5 requests/second globally and 40/min
 * for /at-home/server, no hotlinking of uploads.mangadex.org images (Discover
 * covers go through Novarr, see App\Http\Controllers\DiscoverCoverController),
 * and a report for every image fetched from a MangaDex@Home node.
 *
 * The series URL is https://mangadex.org/title/{uuid}[/slug] and a chapter's
 * https://mangadex.org/chapter/{uuid}.
 */
class MangaDexSource extends AbstractSource implements ReportsPageFetches
{
    public const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** The feed's largest page; offset + limit may not pass 10,000. */
    public const FEED_LIMIT = 500;
    public const MAX_WINDOW = 10000;

    /** Results per Discover list (the /manga endpoint allows 100). */
    public const DISCOVER_LIMIT = 40;

    /** Every content rating: the feed hides "pornographic" unless asked, silently dropping chapters. */
    public const CONTENT_RATINGS = ['safe', 'suggestive', 'erotica', 'pornographic'];

    /** Discover lists stay on the tamer ratings. */
    public const DISCOVER_RATINGS = ['safe', 'suggestive'];

    /** The "Long Strip" format tag (vertical webtoons). */
    public const LONG_STRIP_TAG = '3e2b8dae-350e-4ab8-a8ce-016e844b9f0d';

    public function name(): string
    {
        return 'mangadex';
    }

    public function medium(): string
    {
        return Novel::MEDIUM_MANGA;
    }

    public function hosts(): array
    {
        return ['mangadex.org'];
    }

    /**
     * Only mangadex.org itself (and www.) — never api./uploads. subdomains,
     * which are API/image hosts, not series pages.
     */
    public function matchesUrl(string $url): bool
    {
        $host = self::urlHost($url);

        return $host === 'mangadex.org' || $host === 'www.mangadex.org';
    }

    // ---- URLs ----------------------------------------------------------------

    /** The series uuid of a /title/{uuid}[/slug] URL, else null. */
    public static function seriesId(?string $url): ?string
    {
        return self::pathId((string) $url, 'title');
    }

    /** The chapter uuid of a /chapter/{uuid} URL, else null. */
    public static function chapterId(?string $url): ?string
    {
        return self::pathId((string) $url, 'chapter');
    }

    private static function pathId(string $url, string $kind): ?string
    {
        $host = self::urlHost($url);
        if ($host !== 'mangadex.org' && $host !== 'www.mangadex.org') {
            return null;
        }
        $path = (string) parse_url(preg_match('#^[a-z][a-z0-9+.\-]*://#i', trim($url)) ? trim($url) : '//' . ltrim(trim($url), '/'), PHP_URL_PATH);

        return preg_match('#^/' . $kind . '/(' . self::UUID . ')(?:/|$)#i', $path, $m) ? strtolower($m[1]) : null;
    }

    public static function seriesUrl(string $id): string
    {
        return "https://mangadex.org/title/{$id}";
    }

    public static function chapterUrl(string $id): string
    {
        return "https://mangadex.org/chapter/{$id}";
    }

    /** Library match by series uuid (any slug, with or without www.). */
    public function librarySlug(string $url, bool $stored = false): ?string
    {
        return self::seriesId($url);
    }

    /** The headers every MangaDex request carries: an honest User-Agent. */
    public static function requestHeaders(): array
    {
        return ['User-Agent' => (string) config('novarr.mangadex.user_agent', 'Novarr/1.0')];
    }

    /** Is this URL on one of MangaDex's own domains (site, API, uploads, @Home)? */
    public static function isMangaDexHost(?string $url): bool
    {
        return self::hostMatches(self::urlHost((string) $url), ['mangadex.org', 'mangadex.network']);
    }

    // ---- API -----------------------------------------------------------------

    private static function apiBase(): string
    {
        return rtrim((string) config('novarr.mangadex.api', 'https://api.mangadex.org'), '/');
    }

    private static function uploadsBase(): string
    {
        return rtrim((string) config('novarr.mangadex.uploads', 'https://uploads.mangadex.org'), '/');
    }

    /**
     * A query string the way MangaDex documents it: lists as `key[]=v`
     * (repeated), maps as `key[sub]=v`, brackets left literal. Keys keep
     * their order, so a URL is stable (tests route exact URLs).
     */
    public static function query(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        $pairs[] = $key . '[]=' . rawurlencode((string) $item);
                    }
                } else {
                    foreach ($value as $sub => $item) {
                        $pairs[] = $key . '[' . $sub . ']=' . rawurlencode((string) $item);
                    }
                }
                continue;
            }
            $pairs[] = $key . '=' . rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return implode('&', $pairs);
    }

    /** GET an API path; null on failure ($status = HTTP status or 'exception'). */
    protected function api(string $path, array $params = [], &$status = null): ?array
    {
        $url = self::apiBase() . '/' . ltrim($path, '/') . ($params ? '?' . self::query($params) : '');
        $json = app(Fetcher::class)->json($url, self::requestHeaders(), $status);
        if (is_array($json) && ($json['result'] ?? 'ok') === 'error') {
            return null;
        }

        return $json;
    }

    /** Pause between two requests of one operation (overridable for tests). */
    protected function pauseBetweenRequests(): void
    {
        $ms = (int) config('novarr.mangadex.request_delay_ms', 250);
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /** Configured translated languages (config novarr.mangadex.languages). */
    public static function languages(): array
    {
        $languages = array_values(array_filter(array_map(
            fn($l) => strtolower(trim((string) $l)),
            (array) config('novarr.mangadex.languages', ['en'])
        ), fn($l) => preg_match('/^[a-z]{2}(-[a-z]{2,3})?$/', $l) === 1));

        return $languages ?: ['en'];
    }

    // ---- Table of contents ---------------------------------------------------

    /**
     * The manga feed in the configured languages as TOC rows: one row per
     * chapter number (MangaDex lists a number once per scanlation group),
     * chapters without images (official-publisher links, zero pages) left
     * out, `book` always 0 so the order is the chapter number's.
     */
    public function tableOfContents(Novel $novel): array
    {
        $id = self::seriesId($novel->translator_url);
        if ($id === null) {
            Log::warning("MangaDexSource: not a MangaDex series URL: {$novel->translator_url}");
            return [];
        }

        $chapters = [];
        $offset = 0;
        while (true) {
            $status = null;
            $json = $this->api("manga/{$id}/feed", self::feedParams($offset), $status);
            if (!is_array($json) || !is_array($json['data'] ?? null)) {
                Log::warning("MangaDexSource: feed of {$id} failed at offset {$offset} (HTTP " . ($status ?? '?') . ")");
                if ($offset > 0) {
                    // The rows read so far are kept, but the run is partial
                    // (TOC health, no parking of unlisted rows).
                    markTocRunPartial();
                    break;
                }

                return [];
            }

            array_push($chapters, ...$json['data']);
            $offset += self::FEED_LIMIT;
            $total = (int) ($json['total'] ?? 0);
            if ($json['data'] === [] || $offset >= $total) {
                break;
            }
            if ($offset + self::FEED_LIMIT > self::MAX_WINDOW) {
                Log::warning("MangaDexSource: feed of {$id} has {$total} entries; MangaDex serves only the first " . self::MAX_WINDOW);
                markTocRunPartial();
                break;
            }
            $this->pauseBetweenRequests();
        }

        return self::tocRows($chapters);
    }

    /** Query of one feed page. */
    public static function feedParams(int $offset): array
    {
        return [
            'limit' => self::FEED_LIMIT,
            'offset' => $offset,
            'translatedLanguage' => self::languages(),
            'contentRating' => self::CONTENT_RATINGS,
            // Server-side filters for what the rows below skip anyway.
            'includeExternalUrl' => 0,
            'includeEmptyPages' => 0,
            'includeFuturePublishAt' => 0,
            'order' => ['chapter' => 'asc', 'publishAt' => 'asc'],
        ];
    }

    /**
     * Feed chapters → TOC rows. Pure.
     *
     * Skipped: an externalUrl, zero pages, or no id. One row per chapter
     * number: the copy whose scanlation group has the most chapters in this
     * feed wins, ties to the earliest publishAt (then the chapter id), so
     * the choice is stable between runs. Chapters without a number become
     * one row, number 0, labelled "Oneshot" (same rule among several).
     *
     * @param array<int, array> $chapters feed `data` items
     * @return array<int, array{label: string, url: string, chapter: string, book: int}>
     */
    public static function tocRows(array $chapters): array
    {
        $entries = [];
        $groupCounts = [];
        foreach ($chapters as $chapter) {
            $attributes = $chapter['attributes'] ?? [];
            $id = strtolower((string) ($chapter['id'] ?? ''));
            if (!preg_match('/^' . self::UUID . '$/', $id)
                || !empty($attributes['externalUrl'])
                || (int) ($attributes['pages'] ?? 0) <= 0
                || !empty($attributes['isUnavailable'])) {
                continue;
            }

            $groups = [];
            foreach ($chapter['relationships'] ?? [] as $relationship) {
                if (($relationship['type'] ?? '') === 'scanlation_group' && !empty($relationship['id'])) {
                    $groups[] = (string) $relationship['id'];
                }
            }
            sort($groups);
            $group = $groups === [] ? 'none' : implode('+', $groups);

            $raw = trim((string) ($attributes['chapter'] ?? ''));
            $number = is_numeric($raw) && (float) $raw >= 0 ? self::numberString($raw) : null;

            $entries[] = [
                'id' => $id,
                'key' => $number ?? 'oneshot',
                'number' => $number,
                'title' => trim((string) ($attributes['title'] ?? '')),
                'group' => $group,
                'published' => (string) ($attributes['publishAt'] ?? ''),
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

            return $x <=> $y ?: strcmp($a['published'], $b['published']);
        });

        $rows = [];
        foreach ($best as $entry) {
            $rows[] = [
                'label' => self::label($entry['number'], $entry['title']),
                'url' => self::chapterUrl($entry['id']),
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
            ?: (strcmp($a['published'], $b['published']) ?: strcmp($a['id'], $b['id']));
    }

    /** "12", "12.5", "0" — a feed chapter number without padding or trailing zeros. */
    public static function numberString(string $raw): string
    {
        $text = rtrim(rtrim(number_format((float) $raw, 4, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /**
     * "Chapter 12.5: Title" (ChapterLabelParser reads the number), "Chapter 12"
     * without a title, "Oneshot" for an unnumbered chapter. A title that only
     * repeats the number ("Chapter 12", "Ch. 12") is dropped.
     */
    public static function label(?string $number, string $title): string
    {
        if ($number === null) {
            return 'Oneshot';
        }

        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title !== '' && preg_match('/^(?:chapter|ch\.?)\s*' . preg_quote($number, '/') . '\s*$/iu', $title)) {
            $title = '';
        }

        return mb_substr("Chapter {$number}" . ($title !== '' ? ": {$title}" : ''), 0, 250);
    }

    // ---- Metadata ------------------------------------------------------------

    /**
     * The series' own metadata — MangaDex only, never NovelUpdates (a manga
     * is not on NovelUpdates and a name match there would be another work).
     * Keys as the other adapters' plus `reading_mode` ('vertical' for a Long
     * Strip, else 'rtl' for a Japanese original, else 'ltr') and `title`.
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

        $id = self::seriesId($novel->translator_url);
        if ($id === null) {
            return $metadata;
        }
        $metadata['tried_urls'] = [self::seriesUrl($id)];

        $json = $this->api("manga/{$id}", ['includes' => ['cover_art', 'author', 'artist']]);
        $manga = $json['data'] ?? null;
        if (!is_array($manga)) {
            Log::warning("MangaDexSource: no metadata for {$id}");
            return $metadata;
        }

        return array_merge($metadata, self::seriesMetadata($manga));
    }

    /** One /manga entity → metadata fields. Pure. */
    public static function seriesMetadata(array $manga): array
    {
        $attributes = $manga['attributes'] ?? [];
        $id = (string) ($manga['id'] ?? '');

        $names = [];
        $coverFile = null;
        foreach ($manga['relationships'] ?? [] as $relationship) {
            $type = $relationship['type'] ?? '';
            if (in_array($type, ['author', 'artist'], true)) {
                $name = trim((string) ($relationship['attributes']['name'] ?? ''));
                if ($name !== '' && !in_array(mb_strtolower($name), array_map('mb_strtolower', $names), true)) {
                    $names[] = $name;
                }
            } elseif ($type === 'cover_art' && $coverFile === null) {
                $coverFile = $relationship['attributes']['fileName'] ?? null;
            }
        }

        $covers = [];
        if (is_string($coverFile) && $coverFile !== '' && $id !== '') {
            $base = self::uploadsBase() . "/covers/{$id}/{$coverFile}";
            $covers = [$base, "{$base}.512.jpg"];
        }

        $genres = [];
        $longStrip = false;
        foreach ($attributes['tags'] ?? [] as $tag) {
            $name = (string) ($tag['attributes']['name']['en'] ?? '');
            if (($tag['id'] ?? '') === self::LONG_STRIP_TAG || strcasecmp($name, 'Long Strip') === 0) {
                $longStrip = true;
            }
            if ($name !== '' && in_array($tag['attributes']['group'] ?? '', ['genre', 'theme'], true)) {
                $genres[] = $name;
            }
        }

        $status = strtolower((string) ($attributes['status'] ?? ''));
        $statusText = self::statusLabel($status);
        $last = $attributes['lastChapter'] ?? null;

        $metadata = [
            'title' => self::title($attributes),
            'description' => self::descriptionHtml(self::localized($attributes['description'] ?? [])),
            'author' => implode(', ', $names),
            'no_of_chapters' => is_numeric($last) ? (int) floor((float) $last) : 0,
            'image' => $covers[0] ?? '',
            'genres' => normalizeGenres($genres),
            'cover_candidates' => $covers,
            'reading_mode' => $longStrip ? 'vertical' : (strtolower((string) ($attributes['originalLanguage'] ?? '')) === 'ja' ? 'rtl' : 'ltr'),
        ];
        if ($statusText !== null) {
            $metadata['status_text'] = $statusText;
            $metadata['completed'] = $status === 'completed';
        }

        return $metadata;
    }

    /** English if present, else the first non-empty value of a localized map. */
    public static function localized($map): string
    {
        if (!is_array($map)) {
            return is_string($map) ? trim($map) : '';
        }
        if (!empty($map['en']) && is_string($map['en'])) {
            return trim($map['en']);
        }
        foreach ($map as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /** The English title (main, else an English alt title), else the main one in any language. */
    public static function title(array $attributes): string
    {
        $titles = (array) ($attributes['title'] ?? []);
        if (!empty($titles['en'])) {
            return trim((string) $titles['en']);
        }
        foreach ((array) ($attributes['altTitles'] ?? []) as $alt) {
            if (is_array($alt) && !empty($alt['en'])) {
                return trim((string) $alt['en']);
            }
        }

        return self::localized($titles);
    }

    /** MangaDex descriptions are Markdown: links become their text, emphasis marks go, paragraphs become <p>. */
    public static function descriptionHtml(string $markdown): string
    {
        $text = self::plainMarkdown($markdown);
        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split("/\n\s*\n/", $text) ?: [];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph !== '') {
                $html .= '<p>' . nl2br(e($paragraph), false) . '</p>';
            }
        }

        return $html;
    }

    /** Markdown → plain text (links to their text, emphasis/heading/rule marks removed). */
    public static function plainMarkdown(string $markdown): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text) ?? $text;          // images
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;       // links
        $text = preg_replace('/^\s{0,3}(?:#{1,6}\s*|>\s?|-{3,}\s*$|\*{3,}\s*$)/m', '', $text) ?? $text;
        $text = preg_replace('/(\*\*|__|~~)(.+?)\1/s', '$2', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])[*_](?!\s)(.+?)(?<!\s)[*_](?![\w*])/s', '$1', $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    // ---- Pages ---------------------------------------------------------------

    /**
     * The chapter's full-quality page images from the MangaDex@Home server
     * endpoint (rate limit 40/min — one call per chapter; the sweep's host
     * pace keeps well under it). Null for a URL that is not a MangaDex
     * chapter.
     */
    public function chapterPages(string $url, ?string &$failure = null): ?array
    {
        $failure = null;
        $id = self::chapterId($url);
        if ($id === null) {
            return null;
        }

        $status = null;
        $json = $this->api("at-home/server/{$id}", [], $status);
        if ($json === null) {
            $failure = match (true) {
                $status === 429 => 'rate_limited',
                $status === 404 => 'not_found',
                default => 'fetch_failed',
            };
            if ($failure === 'rate_limited') {
                // Recorded for the API host; the scraper cools down the
                // series' host.
                Fetcher::moveRetryAfter((string) parse_url(self::apiBase(), PHP_URL_HOST), 'mangadex.org');
            }
            return [];
        }

        $base = rtrim((string) ($json['baseUrl'] ?? ''), '/');
        $hash = (string) ($json['chapter']['hash'] ?? '');
        $files = $json['chapter']['data'] ?? null;
        if ($base === '' || $hash === '' || !preg_match('#^https://#i', $base)) {
            $failure = 'fetch_failed';
            return [];
        }
        if (!is_array($files) || $files === []) {
            $failure = 'no_content';
            return [];
        }

        $pages = [];
        foreach (array_values($files) as $file) {
            if (!is_string($file) || $file === '' || str_contains($file, '/')) {
                continue;
            }
            $pages[] = [
                'url' => "{$base}/data/{$hash}/" . rawurlencode($file),
                'headers' => self::requestHeaders(),
            ];
        }
        if ($pages === []) {
            $failure = 'no_content';
        }

        return $pages;
    }

    /**
     * MangaDex@Home asks for a report of every image fetched from a node
     * that is not on mangadex.org (success or failure, bytes, duration,
     * whether its cache served it). Best effort, never throws.
     */
    public function reportPageFetch(array $page, bool $success, int $bytes, int $milliseconds, bool $cached): void
    {
        $url = (string) ($page['url'] ?? '');
        $host = self::urlHost($url);
        if ($host === null || self::hostMatches($host, ['mangadex.org'])) {
            return;
        }

        try {
            app(Fetcher::class)->postJson((string) config('novarr.mangadex.report_url', 'https://api.mangadex.network/report'), [
                'url' => $url,
                'success' => $success,
                'cached' => $cached,
                'bytes' => $success ? $bytes : 0,
                'duration' => $milliseconds,
            ], self::requestHeaders());
        } catch (\Throwable $e) {
            Log::debug("MangaDexSource: image report failed: " . $e->getMessage());
        }
    }

    // ---- Discover ------------------------------------------------------------

    public function discoverLabel(): string
    {
        return 'MangaDex (manga)';
    }

    public function discoverHost(): string
    {
        return 'mangadex.org';
    }

    public function discoverLists(): array
    {
        return ['search', 'popular'];
    }

    /**
     * Search by title (relevance) or the most-followed series, in the
     * configured languages; only series with a downloadable chapter are
     * listed. Cached: popular an hour, searches ten minutes.
     * Null when the API could not be reached.
     */
    public function discover(string $type, ?string $query = null): ?array
    {
        $params = ['limit' => self::DISCOVER_LIMIT];
        if ($type === 'search') {
            $query = trim((string) $query);
            if ($query === '') {
                return [];
            }
            $params += ['title' => $query, 'order' => ['relevance' => 'desc']];
        } elseif ($type === 'popular') {
            $params += ['order' => ['followedCount' => 'desc']];
        } else {
            return [];
        }
        $params += [
            'includes' => ['cover_art', 'author'],
            'contentRating' => self::DISCOVER_RATINGS,
            'availableTranslatedLanguage' => self::languages(),
            'hasAvailableChapters' => 'true',
        ];

        $ttl = $type === 'search' ? 600 : 3600;
        $key = 'discover_mangadex_v2_' . md5(self::query($params));
        $fetch = function () use ($params) {
            $json = $this->api('manga', $params);

            return is_array($json['data'] ?? null) ? $this->readableOnly(self::discoverItems($json['data'])) : null;
        };

        try {
            $items = Cache::get($key);
            if (is_array($items)) {
                return $items;
            }
            $items = $fetch();
            if ($items !== null) {
                Cache::put($key, $items, $ttl);
            }

            return $items;
        } catch (\Throwable $e) {
            Log::warning('Discover: cache store unavailable (' . $e->getMessage() . ') — fetching uncached');
            return $fetch();
        }
    }

    /** Start time of the last hasReadableChapters() request. */
    private float $lastReadableCheck = 0.0;

    /**
     * Drop the Discover items with nothing to download. MangaDex's own
     * `availableTranslatedLanguage` filter is not enough: a series whose
     * chapters were taken down (licensed titles) still lists the language.
     * An item whose check could not be made is kept.
     */
    private function readableOnly(array $items): array
    {
        return array_values(array_filter($items, function (array $item) {
            $id = self::seriesId($item['url']);

            return $id === null || $this->hasReadableChapters($id) !== false;
        }));
    }

    /**
     * Does the series have a chapter Novarr can download in the configured
     * languages (hosted on MangaDex, with pages)? Null when the API could
     * not say. Cached for a day per series.
     */
    public function hasReadableChapters(string $id): ?bool
    {
        $key = 'mangadex_readable_v1_' . $id . '_' . md5(implode(',', self::languages()));
        try {
            $cached = Cache::get($key);
        } catch (\Throwable $e) {
            $cached = null;
        }
        if (is_bool($cached)) {
            return $cached;
        }

        // Up to 40 checks in one page load: space the request starts (under
        // the documented 5 a second) instead of sleeping after each answer.
        $gap = min(210, (int) config('novarr.mangadex.request_delay_ms', 250)) / 1000;
        $wait = $this->lastReadableCheck + $gap - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1e6));
        }
        $this->lastReadableCheck = microtime(true);
        $json = $this->api('chapter', self::readableParams($id));
        if (!is_array($json) || !is_numeric($json['total'] ?? null)) {
            return null;
        }
        $readable = (int) $json['total'] > 0;
        try {
            Cache::put($key, $readable, 86400);
        } catch (\Throwable $e) {
            // Uncached is fine: the list is still filtered.
        }

        return $readable;
    }

    /** Query counting a series' downloadable chapters (the feed's filters). */
    public static function readableParams(string $id): array
    {
        return [
            'limit' => 1,
            'manga' => $id,
            'translatedLanguage' => self::languages(),
            'contentRating' => self::CONTENT_RATINGS,
            'includeExternalUrl' => 0,
            'includeEmptyPages' => 0,
            'includeFuturePublishAt' => 0,
        ];
    }

    /**
     * /manga entities → Discover items: name, url, cover (the 256 px
     * thumbnail — Discover::results() routes it through Novarr), author,
     * description, status, chapters. Pure.
     */
    public static function discoverItems(array $data): array
    {
        $items = [];
        foreach ($data as $manga) {
            $id = strtolower((string) ($manga['id'] ?? ''));
            $attributes = $manga['attributes'] ?? [];
            $name = self::title($attributes);
            if (!preg_match('/^' . self::UUID . '$/', $id) || $name === '') {
                continue;
            }

            $authors = [];
            $cover = '';
            foreach ($manga['relationships'] ?? [] as $relationship) {
                $type = $relationship['type'] ?? '';
                if ($type === 'author' && !empty($relationship['attributes']['name'])) {
                    $authors[] = trim((string) $relationship['attributes']['name']);
                } elseif ($type === 'cover_art' && $cover === '' && !empty($relationship['attributes']['fileName'])) {
                    $cover = self::uploadsBase() . "/covers/{$id}/{$relationship['attributes']['fileName']}.256.jpg";
                }
            }

            $item = [
                'name' => $name,
                'url' => self::seriesUrl($id),
                'cover' => $cover,
                'cover_thumb' => '',
                'author' => implode(', ', array_values(array_unique($authors))),
                'description' => self::plainSynopsis(e(self::plainMarkdown(self::localized($attributes['description'] ?? [])))),
            ];
            if (($status = self::statusLabel($attributes['status'] ?? null)) !== null) {
                $item['status'] = $status;
            }
            $last = $attributes['lastChapter'] ?? null;
            if (is_numeric($last) && (float) $last > 0) {
                $item['chapters'] = (int) floor((float) $last);
            }
            $items[] = $item;
        }

        return $items;
    }
}
