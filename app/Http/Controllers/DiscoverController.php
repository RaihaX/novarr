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
            $cacheKey = 'discover_v3_' . md5($url);

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
        $cacheKey = 'discover_en_' . md5($q);

        $fetch = function () use ($q) {
            $url = 'https://www.empirenovel.com/search-live?q=' . urlencode($q);
            $html = fetchWithBrowser($url);
            if (empty($html)) {
                return null;
            }

            // FlareSolverr wraps the JSON body in HTML; pull the JSON array out.
            if (!preg_match('/(\[.*\])/s', $html, $m)) {
                return [];
            }
            $rows = json_decode($m[1], true);
            if (!is_array($rows)) {
                return [];
            }

            return collect($rows)->map(function ($r) {
                $slug = $r['slug'] ?? null;
                if (!$slug) {
                    return null;
                }
                return [
                    'name' => $r['name'] ?? $slug,
                    'url' => 'https://www.empirenovel.com/novel/' . $slug,
                    'cover' => "https://www.empirenovel.com/uploads/novel/{$slug}/cover/cover_250x350.jpg",
                    'author' => '',
                ];
            })->filter()->values()->all();
        };

        try {
            return Cache::remember($cacheKey, 600, $fetch);
        } catch (\Throwable $e) {
            return $fetch();
        }
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

            $items = [];
            (new Crawler($html))->filter('h3.truyen-title a')->each(function ($node) use (&$items) {
                $href = $node->attr('href');
                $name = trim($node->attr('title') ?: $node->text());
                if (!$href || !$name || !str_ends_with($href, '.html')) {
                    return;
                }
                $items[] = [
                    'name' => $name,
                    'url' => 'https://novelfull.com' . $href,
                    'cover' => '',
                    'author' => '',
                ];
            });

            return $items;
        };

        try {
            return Cache::remember($cacheKey, 600, $fetch);
        } catch (\Throwable $e) {
            return $fetch();
        }
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
            ];
        }

        if (empty($items)) {
            Log::warning("Discover: no results from {$url} — API shape may have changed");
        }

        return $items;
    }
}
