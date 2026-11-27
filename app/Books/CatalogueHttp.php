<?php

namespace App\Books;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The HTTP calls of the extra metadata catalogues (Hardcover, Apple Books,
 * Audible, Audnexus): short timeouts, every failure an answer of null
 * (logged, never thrown), and successful answers cached for
 * novarr.books.catalogue_cache_hours (a day by default), so asking the same
 * thing twice in a day costs nothing.
 *
 * A failed answer is not cached: the next lookup asks again.
 */
class CatalogueHttp
{
    public const USER_AGENT = 'Novarr (self-hosted reading server)';

    public static function timeout(): int
    {
        return max(1, (int) config('novarr.books.catalogue_timeout', 8));
    }

    public static function cacheSeconds(): int
    {
        return max(0, (int) round(((float) config('novarr.books.catalogue_cache_hours', 24)) * 3600));
    }

    /** GET $url with $params as JSON, cached; null on any failure. */
    public static function getJson(string $label, string $url, array $params = []): ?array
    {
        $key = 'catalogue_v1_' . sha1('GET|' . $url . '|' . json_encode($params));

        return static::cached($key, function () use ($label, $url, $params) {
            try {
                $response = Http::timeout(static::timeout())
                    ->connectTimeout(min(5, static::timeout()))
                    ->acceptJson()
                    ->withUserAgent(self::USER_AGENT)
                    ->get($url, $params);
            } catch (\Throwable $e) {
                Log::info("{$label} lookup failed: " . $e->getMessage(), ['url' => $url]);

                return null;
            }
            if (!$response->successful()) {
                Log::info("{$label} lookup answered " . $response->status(), ['url' => $url]);

                return null;
            }
            $json = $response->json();

            return is_array($json) ? $json : null;
        });
    }

    /**
     * POST a JSON body (GraphQL), cached by url + body (never by the
     * headers: the token is not part of the key); null on any failure.
     *
     * @param  array<string, string>  $headers
     * @param  ?callable  $before  called right before the request is sent (a throttle), not on a cache hit
     */
    public static function postJson(string $label, string $url, array $body, array $headers = [], ?callable $before = null): ?array
    {
        $key = 'catalogue_v1_' . sha1('POST|' . $url . '|' . json_encode($body));

        return static::cached($key, function () use ($label, $url, $body, $headers, $before) {
            if ($before !== null) {
                $before();
            }
            try {
                $response = Http::timeout(static::timeout())
                    ->connectTimeout(min(5, static::timeout()))
                    ->acceptJson()
                    ->withUserAgent(self::USER_AGENT)
                    ->withHeaders($headers)
                    ->post($url, $body);
            } catch (\Throwable $e) {
                Log::info("{$label} request failed: " . $e->getMessage(), ['url' => $url]);

                return null;
            }
            if (!$response->successful()) {
                Log::info("{$label} answered " . $response->status(), ['url' => $url, 'body' => mb_substr((string) $response->body(), 0, 300)]);

                return null;
            }
            $json = $response->json();
            // GraphQL reports its errors with a 200.
            if (!is_array($json) || (!empty($json['errors']) && empty($json['data']))) {
                Log::info("{$label} answered with errors", ['errors' => is_array($json) ? json_encode($json['errors'] ?? null) : null]);

                return null;
            }

            return $json;
        });
    }

    /** Largest image downloaded (bytes). */
    public const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

    /**
     * Download an image (a cover): its bytes, size and type, or null when it
     * could not be fetched or is not an image (JPEG, PNG, WebP, GIF). Never
     * cached.
     *
     * @return ?array{bytes: string, width: int, height: int, mime: string, ext: string}
     */
    public static function image(string $url): ?array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        try {
            $response = Http::timeout(max(5, static::timeout() * 2))
                ->connectTimeout(min(5, static::timeout()))
                ->withUserAgent(self::USER_AGENT)
                ->get($url);
        } catch (\Throwable $e) {
            Log::info('Cover download failed: ' . $e->getMessage(), ['url' => $url]);

            return null;
        }
        if (!$response->successful()) {
            return null;
        }
        $bytes = (string) $response->body();

        return static::describeImage($bytes);
    }

    /**
     * What an image's bytes are: size and type, or null for anything that
     * is not a decodable JPEG / PNG / WebP / GIF.
     *
     * @return ?array{bytes: string, width: int, height: int, mime: string, ext: string}
     */
    public static function describeImage(string $bytes): ?array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            return null;
        }
        $ext = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
        if ($ext === null) {
            return null;
        }

        return ['bytes' => $bytes, 'width' => (int) $info[0], 'height' => (int) $info[1], 'mime' => (string) $info['mime'], 'ext' => $ext];
    }

    /** $fetch() through the cache: a null answer is not stored. A broken cache store still fetches. */
    private static function cached(string $key, callable $fetch): ?array
    {
        $seconds = static::cacheSeconds();
        try {
            $hit = $seconds > 0 ? Cache::get($key) : null;
        } catch (\Throwable) {
            $hit = null;
        }
        if (is_array($hit)) {
            return $hit;
        }
        $answer = $fetch();
        if ($answer !== null && $seconds > 0) {
            try {
                Cache::put($key, $answer, $seconds);
            } catch (\Throwable) {
                // Uncached: asked again next time.
            }
        }

        return $answer;
    }
}
