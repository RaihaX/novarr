<?php

namespace App\Downloads;

use App\DownloadGrab;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * `downloads:poll` (every 2 minutes; "Check now" on the Downloads page):
 * asks NZBGet and Transmission how the grabs still in them are doing
 * (one listgroups + history, one torrent-get, whatever the number of
 * grabs) and moves each App\DownloadGrab along:
 *
 *   queued / downloading  progress 0–100 (and the download's current name)
 *   completed             finished in the client; completed_path is its
 *                         name in the inbox (NZBGet: the basename of the
 *                         history's FinalDir, else DestDir; Transmission:
 *                         the torrent's name, its top file or folder)
 *   failed                NZBGet's history says FAILURE / DELETED…,
 *                         Transmission reports a local error (3: disk full,
 *                         no permission), the client no longer has it, or
 *                         its progress has not moved for stall_hours (6);
 *                         a failed grab is blocklisted and its wanted item
 *                         searched again (App\Downloads\FailedDownloads)
 *
 * Then every completed ebook is taken into the review queue
 * (App\Downloads\BookDownloads). Completed audiobooks wait for
 * `audiobooks:scan` (App\Downloads\AudiobookGrabs tells it whose entry it
 * is). A client that cannot be reached leaves its grabs as they are (the
 * problem is reported) and is asked again next time.
 */
class DownloadPoller
{
    /** A grab its client has never heard of after this long is failed (seconds). */
    public const MISSING_AFTER = 600;

    private const LOCK = 'downloads_poll';

    public const LAST_POLL_KEY = 'downloads.last_poll';

    public function __construct(private NzbGet $nzbget, private Transmission $transmission, private BookDownloads $books)
    {
    }

    /**
     * @return array{checked: int, completed: int, imported: int, failed: int, problems: list<string>, busy: bool}
     */
    public function poll(): array
    {
        $counts = ['checked' => 0, 'completed' => 0, 'imported' => 0, 'failed' => 0, 'problems' => [], 'busy' => false];
        $lock = Cache::lock(self::LOCK, 300);
        if (!$lock->get()) {
            $counts['busy'] = true;

            return $counts;
        }

        try {
            $active = DownloadGrab::whereIn('status', DownloadGrab::ACTIVE)->orderBy('id')->get();
            $counts['checked'] = $active->count();

            $usenet = $active->where('client', DownloadGrab::NZBGET);
            if ($usenet->isNotEmpty()) {
                try {
                    $this->pollNzbGet($usenet->all(), $counts);
                } catch (DownloadFailed $e) {
                    $counts['problems'][] = $e->getMessage();
                }
            }

            $torrents = $active->where('client', DownloadGrab::TRANSMISSION);
            if ($torrents->isNotEmpty()) {
                try {
                    $this->pollTransmission($torrents->all(), $counts);
                } catch (DownloadFailed $e) {
                    $counts['problems'][] = $e->getMessage();
                }
            }

            // Finished ebooks into the review queue.
            foreach (DownloadGrab::where('kind', DownloadGrab::KIND_EBOOK)->where('status', DownloadGrab::STATUS_COMPLETED)->orderBy('id')->get() as $grab) {
                $result = $this->books->import($grab);
                if ($result === DownloadGrab::STATUS_IMPORTED) {
                    $counts['imported']++;
                } elseif ($result === DownloadGrab::STATUS_FAILED) {
                    $counts['failed']++;
                }
            }
        } finally {
            $lock->release();
        }

        Cache::forever(self::LAST_POLL_KEY, ['at' => now()->toIso8601String()] + $counts);

        return $counts;
    }

    /** @param list<DownloadGrab> $grabs */
    private function pollNzbGet(array $grabs, array &$counts): void
    {
        $queue = $this->nzbget->queue();
        $history = $this->nzbget->history();
        $rate = $this->nzbget->downloadRate();

        foreach ($grabs as $grab) {
            $id = (int) $grab->client_ref;
            if (isset($queue[$id])) {
                $group = $queue[$id];
                $waiting = in_array(strtoupper((string) ($group['Status'] ?? '')), ['QUEUED', 'PAUSED', 'FETCHING'], true);
                $name = trim((string) ($group['NZBName'] ?? ''));
                $left = (float) ($group['RemainingSizeMB'] ?? 0) * 1048576;
                $this->progress($grab, [
                    'status' => $waiting && NzbGet::progress($group) === 0 ? DownloadGrab::STATUS_QUEUED : DownloadGrab::STATUS_DOWNLOADING,
                    'progress' => min(99, NzbGet::progress($group)),
                    'completed_path' => $name !== '' ? mb_substr(basename($name), 0, 500) : $grab->completed_path,
                    // Rough: what is left at the server's current speed.
                    'eta_seconds' => !$waiting && $rate > 0 ? (int) min(4294967295, round($left / $rate)) : null,
                ], $counts);

                continue;
            }
            if (isset($history[$id])) {
                $item = $history[$id];
                $status = (string) ($item['Status'] ?? '');
                if (NzbGet::succeeded($status)) {
                    $dir = (string) (($item['FinalDir'] ?? '') ?: ($item['DestDir'] ?? ''));
                    $this->complete($grab, $dir !== '' ? basename(rtrim($dir, '/')) : (string) ($item['Name'] ?? $grab->completed_path));
                    $counts['completed']++;
                } else {
                    $grab->forceFill(['polled_at' => now()])->fail('NZBGet could not finish the download (' . ($status ?: 'no status') . ').');
                    $counts['failed']++;
                }

                continue;
            }
            $this->missing($grab, 'NZBGet', $counts);
        }
    }

    /** @param list<DownloadGrab> $grabs */
    private function pollTransmission(array $grabs, array &$counts): void
    {
        $torrents = $this->transmission->torrents(array_values(array_filter(array_map(fn($g) => (string) $g->client_ref, $grabs))));

        foreach ($grabs as $grab) {
            $torrent = $torrents[strtolower((string) $grab->client_ref)] ?? null;
            if ($torrent === null) {
                $this->missing($grab, 'Transmission', $counts);

                continue;
            }
            $name = trim((string) ($torrent['name'] ?? ''));
            if ((int) ($torrent['error'] ?? 0) === Transmission::ERROR_LOCAL) {
                $grab->forceFill(['polled_at' => now()])->fail('Transmission: ' . (trim((string) ($torrent['errorString'] ?? '')) ?: 'the download stopped with an error.'));
                $counts['failed']++;

                continue;
            }
            if (Transmission::complete($torrent)) {
                $this->complete($grab, $name !== '' ? $name : (string) $grab->completed_path);
                $counts['completed']++;

                continue;
            }
            $progress = Transmission::progress($torrent);
            $eta = (int) ($torrent['eta'] ?? -1);
            $this->progress($grab, [
                // 3: waiting to download; 0 with nothing yet: stopped before it started.
                'status' => in_array((int) ($torrent['status'] ?? 0), [0, 3], true) && $progress === 0 ? DownloadGrab::STATUS_QUEUED : DownloadGrab::STATUS_DOWNLOADING,
                'progress' => min(99, $progress),
                'completed_path' => $name !== '' ? mb_substr(basename($name), 0, 500) : $grab->completed_path,
                // A tracker's complaint (error 1–2) is shown, the download goes on.
                'error' => (int) ($torrent['error'] ?? 0) > 0 ? mb_substr((string) ($torrent['errorString'] ?? ''), 0, 1000) : null,
                'eta_seconds' => $eta >= 0 ? $eta : null,
            ], $counts);
        }
    }

    /**
     * Save a grab's progress; one whose progress has not moved for
     * novarr.downloads.stall_hours is failed (blocklisted, replaced).
     */
    private function progress(DownloadGrab $grab, array $fields, array &$counts): void
    {
        if ((int) $fields['progress'] > (int) $grab->progress || $grab->progress_at === null) {
            $fields['progress_at'] = now();
        }
        $grab->forceFill($fields + ['polled_at' => now()])->save();

        $stall = (float) config('novarr.downloads.stall_hours', 6);
        $since = $grab->progress_at ?? $grab->created_at;
        if ($stall > 0 && $since !== null && $since->diffInMinutes(now()) >= $stall * 60) {
            $hours = rtrim(rtrim(number_format($stall, 1), '0'), '.');
            $grab->fail("No progress for {$hours} " . ($stall == 1 ? 'hour' : 'hours') . ' (stuck at ' . (int) $grab->progress . '%).');
            $counts['failed']++;
        }
    }

    private function complete(DownloadGrab $grab, string $name): void
    {
        $grab->forceFill([
            'status' => DownloadGrab::STATUS_COMPLETED,
            'progress' => 100,
            'completed_path' => mb_substr(basename($name), 0, 500),
            'completed_at' => now(),
            'eta_seconds' => null,
            'error' => null,
            'polled_at' => now(),
        ])->save();
        \App\DownloadEvent::log(\App\DownloadEvent::COMPLETED, $grab->title, 'In the inbox as “' . $grab->completed_path . '”.', $grab);
        Log::info('Download finished', ['grab' => $grab->id, 'name' => $grab->completed_path]);
    }

    /** The client does not have it (any more): failed once it has had time to show up. */
    private function missing(DownloadGrab $grab, string $client, array &$counts): void
    {
        if ($grab->created_at !== null && $grab->created_at->diffInSeconds(now()) < self::MISSING_AFTER) {
            return;
        }
        $grab->forceFill(['polled_at' => now()])->fail("{$client} no longer has this download (removed from it?).");
        $counts['failed']++;
    }
}
