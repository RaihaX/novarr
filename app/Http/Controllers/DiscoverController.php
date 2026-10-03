<?php

namespace App\Http\Controllers;

use App\Novel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Sonarr-style "Add Novel" discovery: search and browse novelping.com
 * (formerly novelarrow.com — source key "novelarrow"), empirenovel and
 * novelfull, then add a result via the existing novel:create background
 * command.
 */
class DiscoverController extends Controller
{

    public function index()
    {
        return view('novels.discover');
    }

    /**
     * Fetch a result list from a source.
     * type: search (requires q) | popular | completed
     *
     * Browse lists (popular/completed) leave out novels already in the
     * library and report how many were hidden; typed searches keep every
     * result and only mark library ones (`in_library`). The filter runs
     * after the cache read — the cache is keyed by the source query alone,
     * since the library changes far more often than the lists.
     */
    public function browse(Request $request)
    {
        $data = $request->validate([
            'source' => 'nullable|in:novelarrow,empirenovel,novelfull',
            'type' => 'required|in:search,popular,completed',
            'q' => 'required_if:type,search|nullable|string|max:100',
        ]);

        $source = $data['source'] ?? 'novelarrow';

        // Empire Novel only exposes a live search endpoint (no browse lists).
        if ($source === 'empirenovel') {
            if ($data['type'] !== 'search') {
                return response()->json(['success' => true, 'items' => []]);
            }
            $items = $this->searchEmpireNovel($data['q']);
            $sourceLabel = 'empirenovel.com';
        } elseif ($source === 'novelfull') {
            if ($data['type'] !== 'search') {
                return response()->json(['success' => true, 'items' => []]);
            }
            $items = $this->searchNovelFull($data['q']);
            $sourceLabel = 'novelfull.com';
        } else {
            $query = match ($data['type']) {
                'search' => ['status' => 'all', 'sort' => 'SEARCH_KEYWORD', 'keyword' => $data['q']],
                'popular' => ['status' => 'all', 'sort' => 'POPULAR'],
                'completed' => ['status' => 'completed', 'sort' => 'COMPLETE'],
            };
            $url = novelArrowBase() . '/api-web/novels?'
                . http_build_query($query + ['limit' => 40, 'page' => 1, 'genre' => 'ALL']);

            // Browse lists barely change — cache them. Searches are cached
            // briefly. A broken cache store must not take the feature down.
            $ttl = $data['type'] === 'search' ? 600 : 3600;
            $cacheKey = 'discover_v5_' . md5($url);

            try {
                $items = Cache::remember($cacheKey, $ttl, fn() => $this->fetchList($url));
            } catch (\Throwable $e) {
                Log::warning('Discover: cache store unavailable (' . $e->getMessage() . ') — fetching uncached');
                $items = $this->fetchList($url);
            }
            $sourceLabel = novelArrowHost();
        }

        if ($items === null) {
            return response()->json([
                'success' => false,
                'message' => "Could not reach {$sourceLabel} — try again shortly.",
            ], 502);
        }

        // Mark results that are already in the library (by slug/URL or name).
        $library = $this->libraryIndex();
        foreach ($items as &$item) {
            $item['in_library'] = $this->inLibrary($item, $library);
        }
        unset($item);

        $hidden = 0;
        if ($data['type'] !== 'search') {
            $before = count($items);
            $items = array_values(array_filter($items, fn($item) => !$item['in_library']));
            $hidden = $before - count($items);
        }

        return response()->json(['success' => true, 'items' => $items, 'hidden' => $hidden]);
    }

    /**
     * Library lookup sets: Novel Arrow/NovelPing slugs (either host, legacy
     * novelbin too), normalised URLs for other sources, normalised names.
     *
     * @return array{slugs: array<string, true>, urls: array<string, true>, names: array<string, true>}
     */
    protected function libraryIndex(): array
    {
        $index = ['slugs' => [], 'urls' => [], 'names' => []];

        foreach (Novel::query()->get(['name', 'translator_url']) as $novel) {
            $url = (string) $novel->translator_url;
            if ($url !== '') {
                $index['urls'][self::normalizeUrl($url)] = true;
                if (isNovelArrowUrl($url, legacy: true) && ($slug = novelArrowSlug($url)) !== '') {
                    $index['slugs'][$slug] = true;
                }
            }
            $name = self::normalizeName((string) $novel->name);
            if ($name !== '') {
                $index['names'][$name] = true;
            }
        }

        return $index;
    }

    protected function inLibrary(array $item, array $library): bool
    {
        $url = (string) ($item['url'] ?? '');
        if ($url !== '') {
            if (isNovelArrowUrl($url) && isset($library['slugs'][novelArrowSlug($url)])) {
                return true;
            }
            if (isset($library['urls'][self::normalizeUrl($url)])) {
                return true;
            }
        }

        $name = self::normalizeName((string) ($item['name'] ?? ''));

        return $name !== '' && isset($library['names'][$name]);
    }

    /** Scheme, "www.", case and a trailing "/" or "_" don't make a different novel. */
    public static function normalizeUrl(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('#^https?://(www\.)?#', '', $url);

        return rtrim($url, '/_');
    }

    /** Lowercase, punctuation stripped, whitespace collapsed: "Re:Zero!" ≈ "re zero". */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(html_entity_decode(trim($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = str_replace(["'", "\u{2019}"], '', $name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? '';

        return trim($name);
    }

    /**
     * Search empirenovel.com via its live-search JSON endpoint (behind
     * Cloudflare, so via FlareSolverr). Returns null on failure.
     */
    protected function searchEmpireNovel(string $q): ?array
    {
        // v2: results carry the synopsis / status now.
        $cacheKey = 'discover_en_v2_' . md5($q);

        $fetch = function () use ($q) {
            $url = 'https://www.empirenovel.com/search-live?q=' . urlencode($q);
            $html = fetchWithBrowser($url);
            if (empty($html)) {
                return null;
            }

            return $this->parseEmpireNovelSearch($html);
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
    public function parseEmpireNovelSearch(string $body): array
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
                'url' => 'https://www.empirenovel.com/novel/' . $slug,
                'cover' => "https://www.empirenovel.com/uploads/novel/{$slug}/cover/cover_250x350.jpg",
                'author' => '',
                'description' => $this->plainSynopsis(is_string($summary) ? $summary : ''),
            ];

            $status = $this->statusLabel($r['status'] ?? null);
            if ($status !== null) {
                $item['status'] = $status;
            }

            $items[] = $item;
        }

        return $items;
    }

    /** "Ongoing" / "Completed" from a source status string, else null. */
    protected function statusLabel($status): ?string
    {
        if (!is_string($status) || trim($status) === '') {
            return null;
        }
        $status = strtolower(trim($status));

        return match (true) {
            str_contains($status, 'complete') => 'Completed',
            str_contains($status, 'ongoing') => 'Ongoing',
            default => ucfirst($status),
        };
    }

    /**
     * Search novelfull.com (Cloudflare-protected, via FlareSolverr). Results
     * are <h3 class="truyen-title"><a href="/slug.html" title="…">.
     */
    protected function searchNovelFull(string $q): ?array
    {
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
            foreach (\App\Sources\NovelFullSource::parseSearchResults($html) as $row) {
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

    /**
     * Source synopses arrive as HTML fragments; discover cards show plain
     * text. Block-level tags become spaces so paragraphs don't run together,
     * and the result is capped — a card never needs the whole thing.
     */
    protected function plainSynopsis(string $html, int $limit = 900): string
    {
        // Opening tags count too: sources emit unclosed <p>s, and stripping
        // those without a separator welds the last word to the next sentence.
        $text = preg_replace('/<\/?(br|p|div|li|ul|ol|h[1-6]|blockquote)\b[^>]*>/i', ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // Cut on a word boundary rather than mid-word.
        $cut = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace > $limit / 2 ? mb_substr($cut, 0, $lastSpace) : $cut, " \t\n.,;:") . '…';
    }

    /**
     * Fetch a novelping.com api-web novel list into result items.
     * Returns null when the endpoint cannot be fetched.
     */
    protected function fetchList(string $url): ?array
    {
        $json = null;

        try {
            // Through the Fetcher (plain HTTP) so tests can intercept it.
            $json = app(\App\Scraping\Fetcher::class)->json($url);
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
                'url' => novelArrowNovelUrl($slug),
                // Covers live on the image host, keyed by slug (see the
                // site's og:image tags).
                'cover' => novelArrowCoverUrl($slug),
                'cover_thumb' => '',
                'author' => trim($row['novel_author'] ?? ''),
                'description' => $this->plainSynopsis($row['novel_desc'] ?? ''),
            ];
            // Status pill + chapter count for the cover card, when reported.
            $status = novelArrowStatus($row['novel_status'] ?? null);
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
}
