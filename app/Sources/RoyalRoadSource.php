<?php

namespace App\Sources;

use App\Novel;
use App\Scraping\FailureSnapshot;
use App\Scraping\Fetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Royal Road (www.royalroad.com): original English web fiction.
 *
 * Behind Cloudflare: every page goes through Fetcher::page() — a plain
 * request with the host's cached clearance when there is one, else a
 * FlareSolverr solve that stores the clearance for the next pages.
 *
 * Identity: a fiction is its numeric id (/fiction/{id}/{slug}); authors
 * rename slugs, the site redirects any slug (or none) to the current one.
 * librarySlug() is the id, and pages are fetched by id.
 *
 * Table of contents: the fiction page embeds every chapter as JSON
 * (window.chapters, site order) and also renders them in one table; the
 * JSON is read, the table is the fallback. Chapter titles are free text
 * chosen by authors ("1. Foo", "Chapter 12: Bar", "Interlude", the same
 * "Chapter 1035" twice, "Book 2 Chapter 1" after "Chapter 80"), so the
 * TOC `chapter` value is the 1-based position in the site's list — never
 * the number in the title — and `book` is 0. That keeps the reading order
 * the site's own and numbers every row, unnumbered titles included, with
 * no duplicates. The title stays the label.
 *
 * Metadata comes from the fiction page only: Royal Road originals are
 * rarely on NovelUpdates, and a title match there would be another work.
 *
 * Chapter pages: the body is .chapter-inner.chapter-content; author notes
 * sit outside it (and are removed anyway). Royal Road hides anti-piracy
 * sentences in the text with a per-page random class set to display: none
 * in an inline <style>; pageRemoveSelectors() names those classes.
 */
class RoyalRoadSource extends AbstractSource implements PageNoiseSelectors
{
    public const BASE = 'https://www.royalroad.com';

    /** Discover list => site path. */
    public const LISTS = [
        'popular' => '/fictions/weekly-popular',
        'completed' => '/fictions/complete',
    ];

    /** Royal Road's placeholder cover (a fiction without its own). */
    private const NO_COVER = 'nocover';

    public function name(): string
    {
        return 'royalroad';
    }

    public function hosts(): array
    {
        return ['royalroad.com'];
    }

    public function discoverHost(): string
    {
        return 'www.royalroad.com';
    }

    public function discoverLabel(): string
    {
        return 'Royal Road';
    }

    // ---- URLs ----------------------------------------------------------------

    /**
     * The fiction id of a Royal Road fiction or chapter URL
     * (/fiction/{id}[/{slug}[/chapter/{chapterId}/{chapter-slug}]]), or
     * null when the URL is not one (other host, other path).
     */
    public static function fictionId(string $url): ?int
    {
        if (!self::hostMatches(self::urlHost($url), ['royalroad.com'])) {
            return null;
        }
        $path = self::urlPath($url);
        if (!preg_match('#^/fiction/(\d+)(?:/|$)#i', $path, $m)) {
            return null;
        }
        $id = (int) $m[1];

        return $id > 0 ? $id : null;
    }

    /**
     * The fiction page to fetch for a fiction (or chapter) URL: canonical
     * host, id, and the slug the URL carries (a stale slug is redirected by
     * the site; without one the site redirects to the current slug). Null
     * when the URL is not a Royal Road fiction URL.
     */
    public static function fictionUrl(string $url): ?string
    {
        $id = self::fictionId($url);
        if ($id === null) {
            return null;
        }
        $slug = preg_match('#^/fiction/\d+/([a-z0-9][a-z0-9_-]*)#i', self::urlPath($url), $m) && strtolower($m[1]) !== 'chapter'
            ? '/' . $m[1]
            : '';

        return self::BASE . "/fiction/{$id}{$slug}";
    }

    /** Path of a URL with or without a scheme ("royalroad.com/fiction/1"). */
    private static function urlPath(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) && !str_starts_with($url, '//')) {
            $url = '//' . $url;
        }

        return (string) (parse_url($url, PHP_URL_PATH) ?? '');
    }

    /** A site-relative or absolute URL on the canonical origin. */
    private static function absolute(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return self::BASE . '/' . ltrim($url, '/');
    }

    /**
     * Library matching by fiction id, so a renamed slug, a missing "www."
     * or a chapter URL still find the library novel.
     */
    public function librarySlug(string $url, bool $stored = false): ?string
    {
        $id = self::fictionId($url);

        return $id === null ? null : (string) $id;
    }

    /** A page through the shared clearance / FlareSolverr path; null when it could not be had. */
    private function fetch(string $url, ?string &$reason = null): ?string
    {
        $html = app(Fetcher::class)->page($url, $reason);
        if ($html === null || $html === '') {
            return null;
        }
        if (Fetcher::isCloudflareChallenge($html)) {
            $reason = 'cloudflare';
            return null;
        }

        return $html;
    }

    /**
     * One plain GET with the Fetcher's browser headers and no cookies; the
     * body when it is a real page, null on anything else (refused, a
     * challenge, an empty body, a transport error) so the caller can fall
     * back to fetch().
     */
    private function fetchPlain(string $url): ?string
    {
        $status = null;
        $html = app(Fetcher::class)->plain($url, [], [], $status);
        if ($html === null || $html === '' || Fetcher::isCloudflareChallenge($html)) {
            Log::debug("RoyalRoadSource: plain request for {$url} got " . ($html === null ? ($status ?? 'no response') : 'a challenge or empty page') . '; using the clearance / FlareSolverr path');

            return null;
        }

        return $html;
    }

    // ---- Chapter content -----------------------------------------------------

    public function contentSelectors(): array
    {
        return ['.chapter-inner.chapter-content', ...self::GENERIC_CONTENT_SELECTORS];
    }

    public function removeSelectors(): array
    {
        return [
            ...self::GENERIC_REMOVE_SELECTORS,
            // Author's notes (before / after the chapter): not story text.
            '.author-note-portlet', '.author-note',
            '.nitropay-ad-placeholder',
        ];
    }

    /**
     * Classes the page's own inline <style> blocks hide (display: none) —
     * Royal Road's anti-piracy sentences ("… stolen from Royal Road …")
     * carry such a class, random per page load. Only single-class selectors
     * are taken, so a rule can't widen into something structural.
     */
    public function pageRemoveSelectors(string $html): array
    {
        return self::hiddenClassSelectors($html);
    }

    /** @return string[] ".abc" selectors of display:none rules in inline <style> blocks. Pure. */
    public static function hiddenClassSelectors(string $html): array
    {
        if (!preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $blocks)) {
            return [];
        }

        $selectors = [];
        foreach ($blocks[1] as $css) {
            $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
            if (!preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($rules as $rule) {
                if (!preg_match('/(?:^|[;\s])display\s*:\s*none\b/i', $rule[2])) {
                    continue;
                }
                foreach (explode(',', $rule[1]) as $selector) {
                    $selector = trim($selector);
                    if (preg_match('/^\.[A-Za-z_][A-Za-z0-9_-]*$/', $selector)) {
                        $selectors[$selector] = true;
                    }
                }
            }
        }

        return array_keys($selectors);
    }

    // ---- Table of contents ---------------------------------------------------

    public function tableOfContents(Novel $novel): array
    {
        return $this->toc((string) $novel->translator_url);
    }

    /** Every chapter of a fiction in site order (one request). */
    public function toc(string $novelUrl): array
    {
        $url = self::fictionUrl($novelUrl);
        if ($url === null) {
            Log::warning("RoyalRoadSource: not a Royal Road fiction URL: {$novelUrl}");
            return [];
        }

        $reason = null;
        $html = $this->fetch($url, $reason);
        if ($html === null) {
            Log::error("RoyalRoadSource: could not fetch {$url} (" . ($reason ?? 'unknown') . ')');
            return [];
        }

        $rows = self::parseChapters($html);
        if ($rows === []) {
            Log::warning("RoyalRoadSource: no chapters on {$url}");
            FailureSnapshot::noteTocHtml($html);
            return [];
        }

        Log::info('RoyalRoadSource: parsed ' . count($rows) . " chapters for {$url}");

        return $rows;
    }

    /**
     * TOC rows from a fiction page, in site order: the window.chapters JSON,
     * else the chapter table. Each row is
     * ['label' => title, 'url' => absolute chapter URL, 'chapter' => "N",
     * 'book' => 0] where N is the row's 1-based position. Chapters the
     * page marks as not visible or not unlocked (unreadable for a guest)
     * are left out; a URL listed twice is kept once. Pure.
     *
     * @return array<int, array{label: string, url: string, chapter: string, book: int}>
     */
    public static function parseChapters(string $html): array
    {
        $entries = self::chaptersFromJson($html) ?? self::chaptersFromTable($html);

        $rows = [];
        $seen = [];
        foreach ($entries as $entry) {
            $url = self::absolute($entry['url']);
            if ($url === '' || isset($seen[$url]) || self::fictionId($url) === null) {
                continue;
            }
            $seen[$url] = true;
            $label = trim(preg_replace('/\s+/u', ' ', $entry['title']) ?? '');
            $rows[] = [
                'label' => mb_substr($label !== '' ? $label : 'Chapter', 0, 250),
                'url' => $url,
                'chapter' => (string) (count($rows) + 1),
                'book' => 0,
                // Numbered by position on purpose: no "label disagrees" warning (NovelScraper).
                'positional' => true,
            ];
        }

        return $rows;
    }

    /**
     * window.chapters as [['title', 'url']], or null when the page has no
     * readable list (the table is tried then).
     *
     * @return ?array<int, array{title: string, url: string}>
     */
    public static function chaptersFromJson(string $html): ?array
    {
        $json = self::scriptArray($html, 'window.chapters');
        $list = $json !== null ? json_decode($json, true) : null;
        if (!is_array($list) || $list === []) {
            return null;
        }

        // The page lists them in site order; `order` says the same, and wins
        // if the two ever disagree.
        $indexed = [];
        foreach (array_values($list) as $i => $chapter) {
            if (!is_array($chapter) || !is_string($chapter['url'] ?? null)) {
                continue;
            }
            if (array_key_exists('visible', $chapter) && in_array($chapter['visible'], [0, false, '0'], true)) {
                continue;
            }
            if (($chapter['isUnlocked'] ?? true) === false) {
                continue;
            }
            $indexed[] = [
                'order' => is_numeric($chapter['order'] ?? null) ? (int) $chapter['order'] : $i,
                'i' => $i,
                'title' => html_entity_decode((string) ($chapter['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => $chapter['url'],
            ];
        }
        usort($indexed, fn($a, $b) => [$a['order'], $a['i']] <=> [$b['order'], $b['i']]);

        return $indexed === [] ? null : array_map(fn($c) => ['title' => $c['title'], 'url' => $c['url']], $indexed);
    }

    /** @return array<int, array{title: string, url: string}> tr.chapter-row rows in page order */
    public static function chaptersFromTable(string $html): array
    {
        $entries = [];
        (new Crawler($html))->filter('tr.chapter-row')->each(function (Crawler $row) use (&$entries) {
            $link = $row->filter('td a[href]');
            $url = trim((string) ($row->attr('data-url') ?: ($link->count() ? $link->attr('href') : '')));
            if ($url === '' || $link->count() === 0) {
                return;
            }
            $entries[] = ['title' => $link->first()->text(), 'url' => $url];
        });

        return $entries;
    }

    /**
     * The JSON array literal assigned to $name ("window.chapters = [...]")
     * in an inline script, or null. Brackets are matched outside strings,
     * so a title holding "];" can't cut it short.
     */
    public static function scriptArray(string $html, string $name): ?string
    {
        if (!preg_match('/' . preg_quote($name, '/') . '\s*=\s*\[/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $inString = false;
        $length = strlen($html);
        for ($i = $start; $i < $length; $i++) {
            $c = $html[$i];
            if ($inString) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ']' || $c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($html, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    // ---- Metadata ------------------------------------------------------------

    public function metadata(Novel $novel): array
    {
        return $this->pageMetadata((string) $novel->translator_url);
    }

    /**
     * Metadata from the fiction page: title, description, author, chapter
     * count, cover, tags as genres, cover_candidates, and status_text /
     * completed when the page shows a status. A fiction whose author removed
     * chapters (to publish elsewhere) shows "STUB" instead of its status:
     * status_text "Stub", completed false.
     */
    public function pageMetadata(string $novelUrl): array
    {
        $empty = ['description' => '', 'author' => '', 'no_of_chapters' => 0, 'image' => '', 'genres' => [], 'cover_candidates' => []];

        $url = self::fictionUrl($novelUrl);
        $html = $url !== null ? $this->fetch($url) : null;
        if ($html === null) {
            return $empty;
        }

        try {
            return self::parseMetadata($html) + $empty;
        } catch (\Throwable $e) {
            Log::error("RoyalRoadSource: metadata parse failed for {$url}: " . $e->getMessage());
            return $empty;
        }
    }

    /** Metadata fields from a fiction page. Pure. */
    public static function parseMetadata(string $html): array
    {
        $crawler = new Crawler($html);
        $meta = fn(string $attr, string $name) => ($n = $crawler->filter("meta[{$attr}=\"{$name}\"]"))->count() ? trim((string) $n->attr('content')) : '';

        $title = $crawler->filter('.fic-title h1');
        $author = $crawler->filter('.fic-title h4 a');
        $description = $crawler->filter('.fiction-info .description .hidden-content');

        $covers = [];
        foreach ([$meta('property', 'og:image'), self::scriptString($html, 'window.fictionCover')] as $cover) {
            $cover = self::absolute((string) $cover);
            if ($cover !== '' && !str_contains($cover, self::NO_COVER) && !in_array($cover, $covers, true)) {
                $covers[] = $cover;
            }
        }

        $metadata = [
            'title' => $title->count() ? trim($title->first()->text()) : $meta('name', 'twitter:title'),
            'description' => $description->count() ? sanitizeSynopsisHtml($description->first()->html()) : '',
            'author' => $author->count() ? trim($author->first()->text()) : $meta('property', 'books:author'),
            'no_of_chapters' => count(self::parseChapters($html)),
            'image' => $covers[0] ?? '',
            'genres' => normalizeGenres($crawler->filter('.fiction-info .tags a.fiction-tag')->each(fn(Crawler $n) => $n->text())),
            'cover_candidates' => $covers,
        ];

        $status = self::statusFromLabels($crawler->filter('.fiction-info span.label'));
        if ($status !== null) {
            $metadata['status_text'] = $status;
            $metadata['completed'] = $status === 'Completed';
        }

        return $metadata;
    }

    /** A JS string assigned to $name ("window.fictionCover = "…""), or null. */
    private static function scriptString(string $html, string $name): ?string
    {
        if (!preg_match('/' . preg_quote($name, '/') . '\s*=\s*("(?:[^"\\\\]|\\\\.)*")/', $html, $m)) {
            return null;
        }
        $value = json_decode($m[1]);

        return is_string($value) ? $value : null;
    }

    /** Royal Road's status words (the other labels are the type: Original / Fan Fiction). */
    private const STATUSES = ['COMPLETED', 'ONGOING', 'HIATUS', 'STUB', 'DROPPED', 'INACTIVE'];

    /** "Completed" / "Ongoing" / "Hiatus" / "Stub" / … from the label spans, else null. */
    private static function statusFromLabels(Crawler $labels): ?string
    {
        foreach ($labels as $node) {
            $word = strtoupper(trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? ''));
            if (in_array($word, self::STATUSES, true)) {
                return self::statusLabel($word);
            }
        }

        return null;
    }

    // ---- Discover ------------------------------------------------------------

    /**
     * Search only on the Discover page. discover() also serves 'popular'
     * (weekly popular) and 'completed', but listing either here puts Royal
     * Road among the browse-list sources (SourceResolver::discoverable()),
     * ahead of the novelarrow catch-all registered after it — it would
     * become the Discover page's default source. Add them here once that is
     * wanted.
     */
    public function discoverLists(): array
    {
        return ['search'];
    }

    /**
     * Search (/fictions/search?title=…), weekly popular or completed
     * fictions: the first page of 20, cached (searches ten minutes, lists an
     * hour). Null when the site could not be reached.
     */
    public function discover(string $type, ?string $query = null): ?array
    {
        if ($type === 'search') {
            $url = self::BASE . '/fictions/search?title=' . urlencode(trim((string) $query));
            $ttl = 600;
        } elseif (isset(self::LISTS[$type])) {
            $url = self::BASE . self::LISTS[$type];
            $ttl = 3600;
        } else {
            return [];
        }

        $fetch = function () use ($url, $type) {
            $reason = null;
            // Search pages are answered to a plain request with the app's
            // browser headers, no clearance needed: try that first, and the
            // shared clearance / FlareSolverr path on a refusal or challenge.
            $html = $type === 'search' ? $this->fetchPlain($url) : null;
            $html ??= $this->fetch($url, $reason);
            if ($html === null) {
                Log::warning("RoyalRoadSource: discover fetch failed for {$url} (" . ($reason ?? 'unknown') . ')');
                return null;
            }

            $items = self::parseFictionList($html, $type === 'completed' ? 'Completed' : null);
            if ($items === [] && !str_contains($html, 'fiction-list')) {
                Log::warning("RoyalRoadSource: no fiction list on {$url} — markup may have changed");
            }

            return $items;
        };

        try {
            return Cache::remember('discover_rr_v1_' . md5($url), $ttl, $fetch);
        } catch (\Throwable $e) {
            return $fetch();
        }
    }

    /**
     * A fiction list (search results, weekly popular, complete) → discover
     * items: name, url, cover, author ('' — lists don't show it),
     * description, and status / chapters when shown. $status is the
     * status of a row that shows none (the completed list's rows carry no
     * status label). Pure.
     *
     * @return array<int, array{name: string, url: string, cover: string, author: string, description: string}>
     */
    public static function parseFictionList(string $html, ?string $status = null): array
    {
        $items = [];
        (new Crawler($html))->filter('.fiction-list-item')->each(function (Crawler $row) use (&$items, $status) {
            $link = $row->filter('.fiction-title a[href]');
            if ($link->count() === 0) {
                return;
            }
            $url = self::fictionUrl(self::absolute((string) $link->first()->attr('href')));
            $name = trim(preg_replace('/\s+/u', ' ', $link->first()->text()) ?? '');
            if ($url === null || $name === '') {
                return;
            }

            $img = $row->filter('figure img');
            $cover = $img->count() ? self::absolute((string) $img->first()->attr('src')) : '';
            if (str_contains($cover, self::NO_COVER)) {
                $cover = '';
            }

            $description = $row->filter('[id^="description-"], .description .hidden-content');
            $item = [
                'name' => $name,
                'url' => $url,
                'cover' => $cover,
                'author' => '',
                'description' => $description->count() ? self::plainSynopsis($description->first()->html()) : '',
            ];

            $status = self::statusFromLabels($row->filter('span.label')) ?? $status;
            if ($status !== null) {
                $item['status'] = $status;
            }
            if (preg_match('/([\d,]+)\s+Chapters?\b/i', $row->filter('.stats')->count() ? $row->filter('.stats')->text() : '', $m)) {
                $chapters = (int) str_replace(',', '', $m[1]);
                if ($chapters > 0) {
                    $item['chapters'] = $chapters;
                }
            }

            $items[] = $item;
        });

        return $items;
    }
}
