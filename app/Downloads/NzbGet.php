<?php

namespace App\Downloads;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NZBGet's JSON-RPC API (config novarr.nzbget): POST {url}/jsonrpc with
 * HTTP basic auth, {"method": …, "params": [...], "id": n}.
 *
 *   append(NZBFilename, Content, Category, Priority, AddToTop, AddPaused,
 *          DupeKey, DupeScore, DupeMode, PPParameters)
 *          Content is the NZB's URL here (NZBGet fetches it itself: a
 *          Prowlarr proxy link, or the indexer's own link when Prowlarr's
 *          "redirect" is on). Answers the new NZBID (> 0), or 0 / -1 when
 *          refused.
 *   listgroups(0)  the queue: NZBID, NZBName, Status (QUEUED, FETCHING,
 *          DOWNLOADING, PP_QUEUED, UNPACKING …), FileSizeMB,
 *          RemainingSizeMB, DestDir.
 *   history(false) done and failed: NZBID, Name, Status ("SUCCESS/ALL",
 *          "FAILURE/PAR", "WARNING/SCRIPT", "DELETED/MANUAL" …), DestDir,
 *          FinalDir.
 */
class NzbGet
{
    private int $id = 0;

    /** @throws DownloadFailed */
    public function append(string $title, string $url, string $category): int
    {
        $name = self::nzbName($title);
        $nzbId = $this->call('append', [$name, $url, $category, 0, false, false, '', 0, 'SCORE', []]);
        if (!is_numeric($nzbId) || (int) $nzbId <= 0) {
            throw new DownloadFailed('NZBGet refused the download (append answered ' . json_encode($nzbId) . ').');
        }

        return (int) $nzbId;
    }

    /**
     * The queue, by NZBID.
     *
     * @return array<int, array>
     *
     * @throws DownloadFailed
     */
    public function queue(): array
    {
        return $this->byId((array) $this->call('listgroups', [0]));
    }

    /**
     * The history (finished, failed, deleted), by NZBID.
     *
     * @return array<int, array>
     *
     * @throws DownloadFailed
     */
    public function history(): array
    {
        return $this->byId((array) $this->call('history', [false]));
    }

    /** The server's download speed now, bytes per second (status's DownloadRate); 0 when it cannot say. */
    public function downloadRate(): int
    {
        try {
            $status = (array) $this->call('status', []);
        } catch (DownloadFailed) {
            return 0;
        }

        return max(0, (int) ($status['DownloadRate'] ?? 0));
    }

    /** The queue entry's progress, 0–100. */
    public static function progress(array $group): int
    {
        $total = (float) ($group['FileSizeMB'] ?? 0);
        $left = (float) ($group['RemainingSizeMB'] ?? 0);
        if ($total <= 0) {
            return 0;
        }

        return (int) max(0, min(100, floor((1 - $left / $total) * 100 + 1e-9)));
    }

    /** A history status that left the files in place ("SUCCESS/…", "WARNING/SCRIPT"…: only a script complained). */
    public static function succeeded(string $status): bool
    {
        $status = strtoupper($status);

        return str_starts_with($status, 'SUCCESS') || in_array($status, ['WARNING/SCRIPT', 'WARNING/PP'], true);
    }

    /** The NZB's name for NZBGet: the release title, filesystem-safe, ending in .nzb. */
    public static function nzbName(string $title): string
    {
        $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $title) ?? '');
        $name = mb_substr($name !== '' ? $name : 'download', 0, 200);

        return str_ends_with(strtolower($name), '.nzb') ? $name : $name . '.nzb';
    }

    /** @throws DownloadFailed */
    private function call(string $method, array $params): mixed
    {
        $endpoint = (string) config('novarr.nzbget.url');
        if ($endpoint === '') {
            throw new DownloadFailed('NZBGet is not set up (NOVARR_NZBGET_URL).');
        }
        if (!str_ends_with($endpoint, '/jsonrpc')) {
            $endpoint .= '/jsonrpc';
        }

        $http = Http::acceptJson()->timeout(max(3, (int) config('novarr.nzbget.timeout', 20)));
        if ((string) config('novarr.nzbget.username') !== '') {
            $http = $http->withBasicAuth((string) config('novarr.nzbget.username'), (string) config('novarr.nzbget.password'));
        }

        try {
            $response = $http->post($endpoint, ['method' => $method, 'params' => $params, 'id' => ++$this->id]);
        } catch (\Throwable $e) {
            Log::warning("NZBGet {$method} failed: " . $e->getMessage());
            throw new DownloadFailed('NZBGet could not be reached. Is it running?');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new DownloadFailed('NZBGet refused the username or password (NOVARR_NZBGET_USERNAME / NOVARR_NZBGET_PASSWORD).');
        }
        if (!$response->successful()) {
            throw new DownloadFailed("NZBGet answered {$response->status()} to {$method}.");
        }
        $body = $response->json();
        if (!is_array($body)) {
            throw new DownloadFailed("NZBGet's answer to {$method} could not be read.");
        }
        if (!empty($body['error'])) {
            $message = is_array($body['error']) ? (string) ($body['error']['message'] ?? json_encode($body['error'])) : (string) $body['error'];
            throw new DownloadFailed("NZBGet refused {$method}: {$message}");
        }

        return $body['result'] ?? null;
    }

    private function byId(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['NZBID'])) {
                $out[(int) $row['NZBID']] = $row;
            }
        }

        return $out;
    }
}
