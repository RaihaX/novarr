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
            'source' => 'nullable|in:novelbin,empirenovel,novelfull',
            'type' => 'required|in:search,popular,completed',
            'q' => 'required_if:type,search|nullable|string|max:100',
        ]);

        $source = $data['source'] ?? 'novelbin';

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
            $url = match ($data['type']) {
                'search' => self::BASE . '/search?keyword=' . urlencode($data['q']),
                'popular' => self::BASE . '/sort/top-hot-novel',
                'completed' => self::BASE . '/sort/completed',
            };

            // Browse lists barely change — cache them. Searches are cached
            // briefly. A broken cache store must not take the feature down.
            $ttl = $data['type'] === 'search' ? 600 : 3600;
            $cacheKey = 'discover_v2_' . md5($url);

            try {
                $items = Cache::remember($cacheKey, $ttl, fn() => $this->fetchList($url));
            } catch (\Throwable $e) {
                Log::warning('Discover: cache store unavailable (' . $e->getMessage() . ') — fetching uncached');
                $items = $this->fetchList($url);
            }
            $sourceLabel = 'novelbin.me';
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

                // List pages serve tiny resized thumbnails
                // (…/novel_200_89/slug.jpg) that look terrible upscaled; the
                // full-size original lives at …/novel/slug.jpg. Keep the
                // thumbnail as a client-side fallback.
                $fullCover = preg_replace('#/novel_\d+_\d+/#', '/novel/', $cover);

                $items[] = [
                    'name' => $name,
                    'url' => str_starts_with($href, 'http') ? $href : self::BASE . $href,
                    'cover' => $fullCover,
                    'cover_thumb' => $cover !== $fullCover ? $cover : '',
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
