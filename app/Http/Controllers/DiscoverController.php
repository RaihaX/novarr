<?php

namespace App\Http\Controllers;

use App\Novel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Sonarr-style "Add Novel" discovery: search and browse novelbin.me, then
 * add a result via the existing novel:create background command.
 */
class DiscoverController extends Controller
{
    protected const BASE = 'https://novelbin.me';

    public function index()
    {
        return view('novels.discover');
    }

    /**
     * Fetch a result list from novelbin.me.
     * type: search (requires q) | popular | completed
     */
    public function browse(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:search,popular,completed',
            'q' => 'required_if:type,search|nullable|string|max:100',
        ]);

        $url = match ($data['type']) {
            'search' => self::BASE . '/search?keyword=' . urlencode($data['q']),
            'popular' => self::BASE . '/sort/top-hot-novel',
            'completed' => self::BASE . '/sort/completed',
        };

        // Browse lists barely change — cache them. Searches are cached
        // briefly to absorb repeated keystroke submissions. A broken cache
        // store (e.g. unwritable storage/framework/cache) must not take the
        // feature down, so fall back to an uncached fetch.
        $ttl = $data['type'] === 'search' ? 600 : 3600;
        $cacheKey = 'discover_' . md5($url);

        try {
            $items = Cache::remember($cacheKey, $ttl, fn() => $this->fetchList($url));
        } catch (\Throwable $e) {
            Log::warning('Discover: cache store unavailable (' . $e->getMessage() . ') — fetching uncached');
            $items = $this->fetchList($url);
        }

        if ($items === null) {
            return response()->json([
                'success' => false,
                'message' => 'Could not reach novelbin.me — try again shortly.',
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
     * Fetch and parse a novelbin.me list page into result items.
     * Returns null when the page cannot be fetched.
     */
    protected function fetchList(string $url): ?array
    {
        $html = null;

        try {
            $response = createHttpClient()->request('GET', $url);
            if ($response->getStatusCode() === 200) {
                $html = $response->getContent(false);
            }
        } catch (\Throwable $e) {
            Log::warning("Discover: direct fetch failed for {$url}: " . $e->getMessage());
        }

        // Cloudflare challenge or failure → retry through FlareSolverr.
        if (empty($html) || stripos($html, '<title>Just a moment...</title>') !== false) {
            $html = fetchWithBrowser($url);
        }

        if (empty($html)) {
            Log::error("Discover: could not fetch {$url}");
            return null;
        }

        try {
            $crawler = new Crawler($html);
            $items = [];

            $crawler->filter('h3.novel-title')->each(function (Crawler $node) use (&$items) {
                $link = $node->filter('a');
                if ($link->count() === 0) {
                    return;
                }

                $name = trim($link->first()->attr('title') ?: $link->first()->text());
                $href = $link->first()->attr('href');

                if (empty($name) || empty($href)) {
                    return;
                }

                // Walk up to the result row for cover + author.
                $row = $node->closest('.row');
                $cover = '';
                $author = '';

                if ($row) {
                    $img = $row->filter('img.cover, img');
                    if ($img->count() > 0) {
                        // Sort pages lazy-load covers via data-src; search
                        // pages use a plain src.
                        foreach (['data-src', 'src'] as $attr) {
                            $value = $img->first()->attr($attr);
                            if (!empty($value) && stripos($value, 'data:image') !== 0 && stripos($value, 'logo') === false) {
                                $cover = $value;
                                break;
                            }
                        }
                    }
                    $authorNode = $row->filter('.author');
                    if ($authorNode->count() > 0) {
                        $author = trim($authorNode->first()->text());
                    }
                }

                $items[] = [
                    'name' => $name,
                    'url' => str_starts_with($href, 'http') ? $href : self::BASE . $href,
                    'cover' => $cover,
                    'author' => $author,
                ];
            });

            if (empty($items)) {
                Log::warning("Discover: no results parsed from {$url} (html length: " . strlen($html) . ") — markup may have changed");
            }

            return $items;
        } catch (\Throwable $e) {
            Log::error("Discover: parse error for {$url}: " . $e->getMessage());
            return null;
        }
    }
}
