<?php

namespace App\Downloads;

use App\DownloadGrab;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Prowlarr's search across ALL its indexers (usenet and torrent):
 * GET {url}/api/v1/search?query=…&type=search&categories=…&limit=100
 * with the X-Api-Key header (config novarr.prowlarr). No indexerIds: every
 * enabled indexer is asked.
 *
 * Categories (Newznab): ebook 7000 Books + 7020 Books/EBook; audiobook 3000
 * Audio + 3030 Audio/Audiobook. Indexers that ignore categories (Nyaa)
 * still answer; the ranking sorts out what is not a book.
 *
 * Each result is normalised (normalise()) and the list is ranked for the
 * wanted book (App\Downloads\ReleaseRanking).
 */
class Prowlarr
{
    public const CATEGORIES = [
        DownloadGrab::KIND_EBOOK => [7000, 7020],
        DownloadGrab::KIND_AUDIOBOOK => [3000, 3030],
        // Books/Comics, and Books (indexers that file comics there).
        DownloadGrab::KIND_COMIC => [7030, 7000],
    ];

    /** Books/Comics: added to an ebook search for a graphic novel, so none is missed. */
    public const COMICS_CATEGORY = 7030;

    /** Results asked for per search. */
    public const LIMIT = 100;

    /**
     * Search and rank.
     *
     * @param  array{title?: ?string, author?: ?string, language?: ?string}  $wanted  what the results are ranked against (the query when empty)
     * @return list<array>  ranked results (ReleaseRanking::score())
     *
     * @throws DownloadFailed  not set up, unreachable, refused
     */
    public function search(string $query, string $kind, array $wanted = []): array
    {
        if (!Downloads::enabled()) {
            throw new DownloadFailed('Prowlarr is not set up (NOVARR_PROWLARR_URL and NOVARR_PROWLARR_API_KEY).');
        }
        $query = trim($query);
        if ($query === '') {
            throw new DownloadFailed('Type what to search for.');
        }
        $kind = Downloads::kind($kind);

        // Repeated keys (categories=7000&categories=7020), as Prowlarr reads them.
        $params = ['query=' . rawurlencode($query), 'type=search', 'limit=' . self::LIMIT, 'offset=0'];
        $categories = self::CATEGORIES[$kind];
        if ($kind === DownloadGrab::KIND_EBOOK && !empty($wanted['graphic_novel'])) {
            $categories[] = self::COMICS_CATEGORY;
        }
        foreach ($categories as $category) {
            $params[] = 'categories=' . $category;
        }
        $url = config('novarr.prowlarr.url') . '/api/v1/search?' . implode('&', $params);

        try {
            $response = Http::withHeaders(['X-Api-Key' => (string) config('novarr.prowlarr.api_key')])
                ->acceptJson()
                ->timeout(max(5, (int) config('novarr.prowlarr.timeout', 60)))
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('Prowlarr search failed: ' . $e->getMessage());
            throw new DownloadFailed('Prowlarr could not be reached (' . config('novarr.prowlarr.url') . '). Is it running?');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new DownloadFailed('Prowlarr refused the API key (NOVARR_PROWLARR_API_KEY).');
        }
        if (!$response->successful()) {
            throw new DownloadFailed('Prowlarr answered ' . $response->status() . ' to the search.');
        }
        $rows = $response->json();
        if (!is_array($rows)) {
            throw new DownloadFailed('Prowlarr\'s answer could not be read.');
        }

        $results = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($normal = self::normalise($row)) !== null) {
                $results[] = $normal;
            }
        }

        return ReleaseRanking::rank($results, [
            'kind' => $kind,
            'title' => trim((string) ($wanted['title'] ?? '')) ?: $query,
            'author' => $wanted['author'] ?? null,
            'language' => $wanted['language'] ?? null,
            'graphic_novel' => !empty($wanted['graphic_novel']),
        ] + array_intersect_key($wanted, array_flip(['series_index', 'number', 'number_kind', 'year'])));
    }

    /**
     * One Prowlarr release in Novarr's words, or null when it cannot be
     * grabbed (no download URL or magnet, no title).
     *
     * @return ?array{guid: string, indexer: ?string, indexer_id: ?int, protocol: string, title: string, size: int, age_days: ?int, publish_date: ?string, seeders: ?int, leechers: ?int, grabs: ?int, download_url: ?string, magnet_url: ?string, info_url: ?string, categories: list<int>}
     */
    public static function normalise(array $row): ?array
    {
        $title = trim((string) ($row['title'] ?? ''));
        $download = self::url($row['downloadUrl'] ?? null);
        // A magnet link itself, or Prowlarr's link that redirects to one.
        $magnet = is_string($row['magnetUrl'] ?? null) && str_starts_with((string) $row['magnetUrl'], 'magnet:')
            ? (string) $row['magnetUrl']
            : self::url($row['magnetUrl'] ?? null);
        if ($title === '' || ($download === null && $magnet === null)) {
            return null;
        }
        $protocol = strtolower((string) ($row['protocol'] ?? '')) === 'torrent' ? DownloadGrab::TORRENT : DownloadGrab::USENET;

        $published = null;
        try {
            $published = !empty($row['publishDate']) ? Carbon::parse((string) $row['publishDate']) : null;
        } catch (\Throwable) {
            $published = null;
        }
        $age = isset($row['age']) && is_numeric($row['age']) ? (int) $row['age'] : ($published !== null ? (int) floor(abs(now()->diffInDays($published))) : null);

        $categories = [];
        foreach ((array) ($row['categories'] ?? []) as $category) {
            $id = is_array($category) ? ($category['id'] ?? null) : $category;
            if (is_numeric($id)) {
                $categories[] = (int) $id;
            }
        }

        $int = fn($v) => is_numeric($v) ? (int) $v : null;

        return [
            'guid' => (string) ($row['guid'] ?? ($download ?? $magnet)),
            'indexer' => isset($row['indexer']) ? (string) $row['indexer'] : null,
            'indexer_id' => $int($row['indexerId'] ?? null),
            'protocol' => $protocol,
            'title' => $title,
            'size' => max(0, (int) ($row['size'] ?? 0)),
            'age_days' => $age,
            'publish_date' => $published?->toIso8601String(),
            'seeders' => $protocol === DownloadGrab::TORRENT ? $int($row['seeders'] ?? null) : null,
            'leechers' => $protocol === DownloadGrab::TORRENT ? $int($row['leechers'] ?? null) : null,
            'grabs' => $int($row['grabs'] ?? null),
            'download_url' => $download,
            'magnet_url' => $magnet,
            'info_url' => self::url($row['infoUrl'] ?? ($row['commentUrl'] ?? null)),
            'categories' => array_values(array_unique($categories)),
        ];
    }

    private static function url(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('#^https?://#i', $value) ? $value : null;
    }
}
