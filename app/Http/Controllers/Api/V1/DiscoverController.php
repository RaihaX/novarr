<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Discover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Discover for the native clients (the KOReader plugin): the web Discover
 * page's sources and lists, and one list's results as plain JSON. The data
 * comes from App\Services\Discover, as the web page's does.
 *
 * A source answers one page of results per list (NovelPing: 40), so paging
 * here slices that list for small screens; `has_more` says whether a later
 * page of the same list has items.
 */
class DiscoverController extends Controller
{
    public const UPSTREAM_FAILED = "Couldn't load this list from the source. Try again later.";

    /** Items per page unless the client asks for another size. */
    public const PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /** Description length in an item (plain text, characters). */
    public const DESCRIPTION_LIMIT = 500;

    /** Menu names of the lists, in the web page's tab order. */
    public const LIST_NAMES = [
        'popular' => 'Popular',
        'new' => 'New',
        'random' => 'Random',
        'completed' => 'Completed',
        'search' => 'Search',
    ];

    /** GET /api/v1/discover — the sources and their lists, default source first. */
    public function index(Discover $discover): JsonResponse
    {
        $sources = [];
        $first = true;
        foreach ($discover->sourceOptions() as $key => $option) {
            $lists = [];
            foreach (self::LIST_NAMES as $id => $name) {
                if (in_array($id, $option['lists'], true)) {
                    $lists[] = ['id' => $id, 'name' => $name];
                }
            }
            $sources[] = [
                'id' => (string) $key,
                'name' => $option['label'],
                'host' => $option['host'],
                'default' => $first,
                'lists' => $lists,
            ];
            $first = false;
        }

        return response()->json(['sources' => $sources]);
    }

    /**
     * GET /api/v1/discover/browse?source=&type=&q=&page=&per_page=
     * The web browse's parameters (source, type, q) plus paging. Unlike the
     * web page, browse lists keep library novels (flagged in_library).
     */
    public function browse(Request $request, Discover $discover): JsonResponse
    {
        $sources = $discover->sources();
        if ($sources === []) {
            return response()->json(['items' => [], 'page' => 1, 'has_more' => false]);
        }

        $data = $request->validate(static::browseRules($sources));

        $listing = static::listing($discover, $sources, $data);
        if ($listing === null) {
            return response()->json(['message' => self::UPSTREAM_FAILED], 502);
        }

        return response()->json($listing);
    }

    /**
     * Validation rules of a browse (GET /discover/browse and POST
     * /discover/searches take the same parameters).
     *
     * @param  array<string, \App\Sources\Source>  $sources
     */
    public static function browseRules(array $sources): array
    {
        return [
            'source' => 'nullable|in:' . implode(',', array_keys($sources)),
            'type' => 'required|in:' . implode(',', Discover::TYPES),
            'q' => 'required_if:type,search|nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
        ];
    }

    /**
     * One page of a list for validated browse parameters:
     * {items, page, has_more}, or null when the source could not be reached
     * (or threw). $sources must not be empty.
     *
     * @param  array<string, \App\Sources\Source>  $sources
     */
    public static function listing(Discover $discover, array $sources, array $data): ?array
    {
        $key = $data['source'] ?? array_key_first($sources);
        $source = $sources[$key];
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? self::PER_PAGE);

        try {
            $items = $discover->results($source, $data['type'], $data['q'] ?? null);
        } catch (\Throwable $e) {
            Log::warning("API Discover: {$key} {$data['type']} failed: " . $e->getMessage());
            $items = null;
        }

        if ($items === null) {
            return null;
        }

        // Random draws a new list on every request, so a later page would
        // belong to another draw: it comes whole.
        if ($data['type'] === 'random') {
            $slice = $page === 1 ? $items : [];
            $hasMore = false;
        } else {
            $slice = array_slice($items, ($page - 1) * $perPage, $perPage);
            $hasMore = count($items) > $page * $perPage;
        }

        return [
            'items' => array_map(fn(array $item) => self::item($item, (string) $key), array_values($slice)),
            'page' => $page,
            'has_more' => $hasMore,
        ];
    }

    /** One result item; `title` and `url` are what POST /api/v1/novels takes. */
    public static function item(array $item, string $source): array
    {
        $url = (string) ($item['url'] ?? '');
        $description = trim((string) ($item['description'] ?? ''));
        if ($description !== '') {
            $description = html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $description = Str::limit(trim(preg_replace('/\s+/u', ' ', $description) ?? ''), self::DESCRIPTION_LIMIT);
        }
        $novelId = $item['novel_id'] ?? null;

        return [
            'title' => (string) ($item['name'] ?? ''),
            'author' => trim((string) ($item['author'] ?? '')) ?: null,
            'cover_url' => self::absoluteUrl((string) ($item['cover'] ?? ''), $url),
            'description' => $description !== '' ? $description : null,
            'status' => isset($item['status']) ? (string) $item['status'] : null,
            'chapters' => isset($item['chapters']) ? (int) $item['chapters'] : null,
            'url' => $url,
            'source' => $source,
            'in_library' => $novelId !== null,
            'novel_id' => $novelId !== null ? (int) $novelId : null,
        ];
    }

    /** An absolute http(s) URL for a possibly relative one, against the page it came from; null when empty. */
    private static function absoluteUrl(string $url, string $base): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        if (!$scheme || !$host) {
            return null;
        }

        return "{$scheme}://{$host}/" . ltrim($url, '/');
    }
}
