<?php

namespace App\Http\Controllers;

use App\Scraping\Fetcher;
use App\Scraping\PageDownloader;
use App\Sources\AbstractSource;
use App\Sources\MangaDexSource;
use App\Sources\SuwayomiSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /discover/cover?u=… — a Discover result's cover, fetched by Novarr for
 * hosts that refuse hotlinked images (MangaDex serves a wrong image to any
 * browser that embeds uploads.mangadex.org) or that the browser cannot reach
 * (a Suwayomi-Server on the LAN). Only https URLs on the hosts in config
 * novarr.discover_cover_proxy_hosts and Suwayomi thumbnails on the
 * configured server are fetched, so this is not an open proxy. A fetched
 * cover is cached for a day.
 */
class DiscoverCoverController extends Controller
{
    /** Largest cover accepted (thumbnails are ~10–60 KB). */
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const CACHE_SECONDS = 86400;

    /**
     * Is this cover URL one Discover serves through Novarr? https on an
     * allow-listed host, or — over plain http too — a manga thumbnail on the
     * configured Suwayomi-Server (a LAN address the browser often cannot
     * reach; exactly /api/v1/manga/{id}/thumbnail on that origin, nothing
     * else on it).
     */
    public static function proxied(string $url): bool
    {
        if (SuwayomiSource::isThumbnailUrl($url)) {
            return true;
        }
        if (!preg_match('#^https://#i', $url)) {
            return false;
        }
        $host = AbstractSource::urlHost($url);

        return $host !== null && in_array($host, array_map('strtolower', (array) config('novarr.discover_cover_proxy_hosts', [])), true);
    }

    public function show(Request $request)
    {
        return $this->respond($request, (string) $request->query('u', ''));
    }

    /**
     * The cover at $url (one proxied() allows), fetched or from the cache;
     * 404 otherwise. Shared with RequestCoverController.
     */
    protected function respond(Request $request, string $url)
    {
        abort_unless($url !== '' && strlen($url) <= 2048 && self::proxied($url), 404);

        $key = 'discover_cover_v1_' . md5($url);
        $cached = null;
        try {
            $cached = Cache::get($key);
        } catch (\Throwable $e) {
            // A broken cache store only costs a refetch.
        }

        if (!is_array($cached)) {
            $cached = $this->fetch($url);
            if ($cached === null) {
                abort(404);
            }
            try {
                Cache::put($key, $cached, self::CACHE_SECONDS);
            } catch (\Throwable $e) {
                // Best effort.
            }
        }

        $body = base64_decode($cached['data']);
        $etag = '"' . md5($body) . '"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=' . self::CACHE_SECONDS]);
        }

        return response($body, 200, [
            'Content-Type' => $cached['mime'],
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'public, max-age=' . self::CACHE_SECONDS,
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return ?array{mime: string, data: string} base64 data, or null when it isn't a usable image */
    private function fetch(string $url): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'novarr_cover_');
        if ($tmp === false) {
            return null;
        }

        try {
            $headers = MangaDexSource::isMangaDexHost($url) ? MangaDexSource::requestHeaders() : [];
            if (!app(Fetcher::class)->download($url, $tmp, $headers, self::MAX_BYTES)) {
                return null;
            }
            $check = PageDownloader::inspect($tmp, self::MAX_BYTES);
            if (!$check['ok']) {
                return null;
            }

            return ['mime' => $check['mime'], 'data' => base64_encode((string) file_get_contents($tmp))];
        } finally {
            @unlink($tmp);
        }
    }
}
