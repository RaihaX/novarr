<?php

namespace App\Http\Controllers;

use App\Novel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Sonarr-style "Add Novel" discovery: search and browse novelarrow.com, then
 * add a result via the existing novel:create background command.
 */
class DiscoverController extends Controller
{
    protected const BASE = 'https://novelarrow.com';

    public function index()
    {
        return view('novels.discover');
    }

    /**
     * Fetch a result list from novelarrow.com.
     * type: search (requires q) | popular | completed
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
            $url = self::BASE . '/api-web/novels?'
                . http_build_query($query + ['limit' => 40, 'page' => 1, 'genre' => 'ALL']);

            // Browse lists barely change — cache them. Searches are cached
            // briefly. A broken cache store must not take the feature down.
            $ttl = $data['type'] === 'search' ? 600 : 3600;
            $cacheKey = 'discover_v4_' . md5($url);

            try {
                $items = Cache::remember($cacheKey, $ttl, fn() => $this->fetchList($url));
            } catch (\Throwable $e) {
                Log::warning('Discover: cache store unavailable (' . $e->getMessage() . ') — fetching uncached');
                $items = $this->fetchList($url);
            }
            $sourceLabel = 'novelarrow.com';
        }

        if ($items === null) {
            return response()->json([
                'success' => false,
                'message' => "Could not reach {$sourceLabel} — try again shortly.",
            ], 502);
        }

        // Mark results that are already in the library (by URL or name).
        $existingUrls = Novel::pluck('translator_url')->filter()
            ->map(fn($u) => rtrim(strtolower($u), '/'))->flip();
        $existingNames = Novel::pluck('name')
            ->map(fn($n) => mb_strtolower(trim($n)))->flip();

        foreach ($items as &$item) {
            $item['in_library'] = isset($existingUrls[rtrim(strtolower($item['url']), '/')])
                || isset($existingNames[mb_strtolower(trim($item['name']))]);
        }

        return response()->json(['success' => true, 'items' => $items]);
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
     * Fetch a novelarrow.com api-web novel list into result items.
     * Returns null when the endpoint cannot be fetched.
     */
    protected function fetchList(string $url): ?array
    {
        $json = null;

        try {
            $response = createHttpClient()->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() === 200) {
                $json = json_decode($response->getContent(false), true);
            }
        } catch (\Throwable $e) {
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

            $items[] = [
                'name' => $name,
                'url' => self::BASE . '/novel/' . $slug,
                // Covers live on the image host, keyed by slug (see the
                // site's og:image tags).
                'cover' => "https://images.novelarrow.com/novel/{$slug}.jpg",
                'cover_thumb' => '',
                'author' => trim($row['novel_author'] ?? ''),
                'description' => $this->plainSynopsis($row['novel_desc'] ?? ''),
            ];
        }

        if (empty($items)) {
            Log::warning("Discover: no results from {$url} — API shape may have changed");
        }

        return $items;
    }
}
