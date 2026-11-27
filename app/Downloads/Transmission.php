<?php

namespace App\Downloads;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transmission's RPC (config novarr.transmission): POST
 * {url}/transmission/rpc with HTTP basic auth and the
 * X-Transmission-Session-Id header. The first call (and any after
 * Transmission restarts) is answered 409 with the session id in that
 * header: it is remembered and the call made again, once.
 *
 *   torrent-add  {filename: magnet link or .torrent URL, or metainfo:
 *                base64 .torrent; download-dir}. Answers torrent-added (or
 *                torrent-duplicate: the same torrent is already there,
 *                which is fine) with its hashString and name.
 *   torrent-get  {ids: [hashString…], fields: […]}: percentDone (0–1),
 *                leftUntilDone, status (0 stopped … 4 downloading, 5–6
 *                seeding), error (0 none, 1–2 tracker, 3 local) and
 *                errorString, name, downloadDir.
 *
 * Novarr never removes a torrent: it keeps seeding; Novarr only stops
 * following it once imported.
 */
class Transmission
{
    private const SESSION_KEY = 'downloads.transmission.session';

    public const FIELDS = ['hashString', 'name', 'percentDone', 'leftUntilDone', 'status', 'error', 'errorString', 'downloadDir', 'sizeWhenDone', 'isFinished', 'eta'];

    /** Local errors (error 3): the download itself cannot go on (disk full, no permission). */
    public const ERROR_LOCAL = 3;

    /**
     * Add a torrent: a magnet link, a .torrent URL Transmission fetches, or
     * the .torrent file's bytes.
     *
     * @param  array{magnet?: string, url?: string, metainfo?: string}  $source
     * @return array{hash: string, name: string}
     *
     * @throws DownloadFailed
     */
    public function add(array $source, string $downloadDir): array
    {
        $arguments = ['download-dir' => $downloadDir, 'paused' => false];
        if (!empty($source['metainfo'])) {
            $arguments['metainfo'] = base64_encode($source['metainfo']);
        } else {
            $arguments['filename'] = (string) ($source['magnet'] ?? $source['url'] ?? '');
        }
        if (($arguments['filename'] ?? null) === '') {
            throw new DownloadFailed('This torrent has no magnet link or download link.');
        }

        $answer = $this->call('torrent-add', $arguments);
        $torrent = $answer['torrent-added'] ?? $answer['torrent-duplicate'] ?? null;
        if (!is_array($torrent) || empty($torrent['hashString'])) {
            throw new DownloadFailed('Transmission did not say which torrent it added.');
        }

        return ['hash' => strtolower((string) $torrent['hashString']), 'name' => (string) ($torrent['name'] ?? '')];
    }

    /**
     * The torrents with these hashes, by hash (lower case).
     *
     * @param  list<string>  $hashes
     * @return array<string, array>
     *
     * @throws DownloadFailed
     */
    public function torrents(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }
        $answer = $this->call('torrent-get', ['ids' => array_values($hashes), 'fields' => self::FIELDS]);
        $out = [];
        foreach ((array) ($answer['torrents'] ?? []) as $torrent) {
            if (is_array($torrent) && !empty($torrent['hashString'])) {
                $out[strtolower((string) $torrent['hashString'])] = $torrent;
            }
        }

        return $out;
    }

    /** Finished downloading: everything wanted is there (it may go on seeding). */
    public static function complete(array $torrent): bool
    {
        $status = (int) ($torrent['status'] ?? 0);

        // 1–2: verifying local data, not done whatever percentDone says.
        return (float) ($torrent['percentDone'] ?? 0) >= 1.0
            && (int) ($torrent['leftUntilDone'] ?? 0) === 0
            && !in_array($status, [1, 2], true);
    }

    public static function progress(array $torrent): int
    {
        return (int) max(0, min(100, floor((float) ($torrent['percentDone'] ?? 0) * 100)));
    }

    /** @throws DownloadFailed */
    private function call(string $method, array $arguments): array
    {
        $endpoint = (string) config('novarr.transmission.url');
        if ($endpoint === '') {
            throw new DownloadFailed('Transmission is not set up (NOVARR_TRANSMISSION_URL).');
        }
        if (!str_ends_with($endpoint, '/rpc')) {
            $endpoint .= '/transmission/rpc';
        }

        $response = $this->post($endpoint, $method, $arguments, (string) Cache::get(self::SESSION_KEY, ''));
        if ($response->status() === 409) {
            // The session id handshake: remember the id and ask again, once.
            $session = (string) $response->header('X-Transmission-Session-Id');
            if ($session === '') {
                throw new DownloadFailed('Transmission asked for a session id but did not give one.');
            }
            Cache::put(self::SESSION_KEY, $session, 86400);
            $response = $this->post($endpoint, $method, $arguments, $session);
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new DownloadFailed('Transmission refused the username or password (NOVARR_TRANSMISSION_USERNAME / NOVARR_TRANSMISSION_PASSWORD), or Novarr\'s address is not in its RPC whitelist.');
        }
        if (!$response->successful()) {
            throw new DownloadFailed("Transmission answered {$response->status()} to {$method}.");
        }
        $body = $response->json();
        if (!is_array($body)) {
            throw new DownloadFailed("Transmission's answer to {$method} could not be read.");
        }
        if (($body['result'] ?? null) !== 'success') {
            throw new DownloadFailed("Transmission refused {$method}: " . (string) ($body['result'] ?? 'no reason given'));
        }

        return (array) ($body['arguments'] ?? []);
    }

    /** @throws DownloadFailed */
    private function post(string $endpoint, string $method, array $arguments, string $session): \Illuminate\Http\Client\Response
    {
        $http = Http::acceptJson()->timeout(max(3, (int) config('novarr.transmission.timeout', 20)));
        if ((string) config('novarr.transmission.username') !== '') {
            $http = $http->withBasicAuth((string) config('novarr.transmission.username'), (string) config('novarr.transmission.password'));
        }
        if ($session !== '') {
            $http = $http->withHeaders(['X-Transmission-Session-Id' => $session]);
        }

        try {
            return $http->post($endpoint, ['method' => $method, 'arguments' => (object) $arguments]);
        } catch (\Throwable $e) {
            Log::warning("Transmission {$method} failed: " . $e->getMessage());
            throw new DownloadFailed('Transmission could not be reached. Is it running?');
        }
    }
}
