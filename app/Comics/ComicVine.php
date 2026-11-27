<?php

namespace App\Comics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Comic Vine (https://comicvine.gamespot.com/api/): comic series ("volumes")
 * and their issues. Needs a free API key (config novarr.comics.comicvine.api_key,
 * env NOVARR_COMICVINE_API_KEY); without one every method answers "nothing"
 * (enabled() false) and the callers fall back to ComicInfo.xml and the book
 * catalogues.
 *
 * Every call sends format=json and a descriptive User-Agent (Comic Vine
 * refuses the default ones), is throttled under the ~200 an hour per
 * resource Comic Vine allows (novarr.comics.comicvine.per_hour, 150) and
 * cached (cache_hours, 24). A failed call (unreachable, refused, over the
 * limit) is logged and answers null / []: metadata is never worth failing an
 * import for.
 */
class ComicVine
{
    public const USER_AGENT = 'Novarr/1.0 (self-hosted comic library; https://github.com/novarr)';

    public const FIELDS_VOLUME = 'id,name,start_year,publisher,description,deck,image,count_of_issues,site_detail_url,people';

    public static function enabled(): bool
    {
        return (string) config('novarr.comics.comicvine.api_key') !== '';
    }

    /**
     * Series of that name (newest data first as Comic Vine ranks them), each
     * as volume()'s shape; with $year, those that started that year first.
     *
     * @return list<array>
     */
    public function searchVolumes(string $name, ?int $year = null, int $limit = 10): array
    {
        $name = trim($name);
        if (!self::enabled() || $name === '') {
            return [];
        }
        $json = $this->get('search', [
            'query' => $name,
            'resources' => 'volume',
            'limit' => max(1, min(50, $limit)),
            'field_list' => self::FIELDS_VOLUME,
        ]);
        $volumes = [];
        foreach ((array) ($json['results'] ?? []) as $row) {
            if (is_array($row) && ($volume = self::volumeFrom($row)) !== null) {
                $volumes[] = $volume;
            }
        }
        if ($year !== null) {
            usort($volumes, fn($a, $b) => ((int) ($a['start_year'] === $year) <=> (int) ($b['start_year'] === $year)) * -1);
        }

        return $volumes;
    }

    /** One series by its Comic Vine id, or null. */
    public function volume(int $id): ?array
    {
        if (!self::enabled() || $id <= 0) {
            return null;
        }
        $json = $this->get('volume/4050-' . $id, ['field_list' => self::FIELDS_VOLUME]);

        return is_array($json['results'] ?? null) ? self::volumeFrom($json['results']) : null;
    }

    /**
     * One issue of a series by its number: {id, number, name, cover_url, cover_date, description}, or null.
     */
    public function issue(int $volumeId, float $number): ?array
    {
        if (!self::enabled() || $volumeId <= 0) {
            return null;
        }
        $n = rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
        $json = $this->get('issues', [
            'filter' => 'volume:' . $volumeId . ',issue_number:' . $n,
            'field_list' => 'id,issue_number,name,image,cover_date,description',
            'limit' => 1,
        ]);
        $row = $json['results'][0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'number' => (string) ($row['issue_number'] ?? $n),
            'name' => ($row['name'] ?? null) ?: null,
            'cover_url' => self::imageUrl($row['image'] ?? null),
            'cover_date' => ($row['cover_date'] ?? null) ?: null,
            'description' => isset($row['description']) ? (self::plainText((string) $row['description']) ?: null) : null,
        ];
    }

    /**
     * A Comic Vine volume row in Novarr's words: id, name, start_year,
     * publisher, description (plain text), cover_url (large), issues,
     * url, people.
     */
    public static function volumeFrom(array $row): ?array
    {
        $name = trim((string) ($row['name'] ?? ''));
        $id = (int) ($row['id'] ?? 0);
        if ($name === '' || $id <= 0) {
            return null;
        }
        $description = self::plainText((string) ($row['description'] ?? '')) ?: (trim(strip_tags((string) ($row['deck'] ?? ''))) ?: null);
        $people = [];
        foreach ((array) ($row['people'] ?? []) as $person) {
            if (is_array($person) && trim((string) ($person['name'] ?? '')) !== '') {
                $people[] = trim((string) $person['name']);
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'start_year' => is_numeric($row['start_year'] ?? null) ? (int) $row['start_year'] : null,
            'publisher' => is_array($row['publisher'] ?? null) ? (trim((string) ($row['publisher']['name'] ?? '')) ?: null) : null,
            'description' => $description,
            'cover_url' => self::imageUrl($row['image'] ?? null),
            'issues' => is_numeric($row['count_of_issues'] ?? null) ? (int) $row['count_of_issues'] : null,
            'url' => is_string($row['site_detail_url'] ?? null) && preg_match('#^https?://#i', $row['site_detail_url']) ? $row['site_detail_url'] : null,
            'people' => array_slice(array_values(array_unique($people)), 0, 6),
        ];
    }

    /** Comic Vine's image object → its largest useful URL. */
    public static function imageUrl(mixed $image): ?string
    {
        if (!is_array($image)) {
            return null;
        }
        foreach (['original_url', 'super_url', 'medium_url', 'small_url'] as $key) {
            $url = $image[$key] ?? null;
            // Comic Vine's "no image" placeholder is not a cover.
            if (is_string($url) && preg_match('#^https?://#i', $url) && !str_contains($url, 'blank.png') && !str_contains($url, '/6373148-blank')) {
                return $url;
            }
        }

        return null;
    }

    /** HTML (Comic Vine's descriptions, ComicInfo summaries) → plain paragraphs; tables and figures dropped. */
    public static function plainText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $html = preg_replace('#<(table|figure|script|style)\b.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<\s*(br|/p|/h\d|/li|/div)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_values(array_filter(array_map(fn($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l) ?? $l), explode("\n", $text)), fn($l) => $l !== ''));

        return mb_substr(implode("\n\n", $lines), 0, 5000);
    }

    /** One GET, throttled and cached. */
    private function get(string $path, array $params): ?array
    {
        $params = ['api_key' => (string) config('novarr.comics.comicvine.api_key'), 'format' => 'json'] + $params;
        $key = 'comicvine.' . sha1($path . '|' . json_encode(array_diff_key($params, ['api_key' => 1])));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $resource = explode('/', $path)[0];
        $limiter = 'comicvine|' . $resource;
        $perHour = max(1, (int) config('novarr.comics.comicvine.per_hour', 150));
        if (RateLimiter::tooManyAttempts($limiter, $perHour)) {
            Log::info("Comic Vine: hourly limit for {$resource} reached, not asked.");

            return null;
        }
        RateLimiter::hit($limiter, 3600);

        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->acceptJson()
                ->timeout(max(3, (int) config('novarr.comics.comicvine.timeout', 20)))
                ->get(config('novarr.comics.comicvine.url', 'https://comicvine.gamespot.com/api') . '/' . $path . '/', $params);
        } catch (\Throwable $e) {
            Log::info('Comic Vine could not be reached: ' . $e->getMessage());

            return null;
        }
        $json = $response->successful() ? $response->json() : null;
        // status_code 1 is OK; 100 a bad key, 107 rate limited, 101 not found.
        if (!is_array($json) || (int) ($json['status_code'] ?? 0) !== 1) {
            Log::info('Comic Vine answered ' . $response->status() . ' / ' . (is_array($json) ? ($json['error'] ?? '?') : 'no JSON') . " for {$path}");

            return null;
        }
        Cache::put($key, $json, now()->addHours(max(1, (int) config('novarr.comics.comicvine.cache_hours', 24))));

        return $json;
    }
}
