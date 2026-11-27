<?php

namespace App\Downloads;

use App\DownloadGrab;
use App\Novel;

/**
 * What `comics:scan` (App\Comics\ComicInbox) needs to know about an inbox
 * entry that is a grabbed comic download (App\DownloadGrab of kind comic
 * whose completed_path is the entry's name, the newest one) — as
 * App\Downloads\AudiobookGrabs does for audiobooks:
 *
 *   hold()      leave it alone: still downloading, or a torrent already
 *               imported / failed (it stays in the inbox to seed).
 *   options()   a finished grab: the importer's options — the series it was
 *               grabbed for (novel_id: "Find more issues"), the reader
 *               request (request_id: a wanted comic), copy for a torrent.
 *   imported()  the grab is done: imported, with the series.
 *   failed()    the import failed: the grab says why; true when the entry
 *               stays where it is (a torrent) instead of going to _failed.
 */
final class ComicGrabs
{
    public static function forEntry(string $name): ?DownloadGrab
    {
        if ($name === '') {
            return null;
        }

        return DownloadGrab::where('kind', DownloadGrab::KIND_COMIC)
            ->where('completed_path', $name)
            ->orderByDesc('id')
            ->first();
    }

    /** @return ?array{0: string, 1: string} */
    public static function hold(string $name): ?array
    {
        $grab = self::forEntry($name);
        if ($grab === null) {
            return null;
        }
        if ($grab->isActive()) {
            return [AudiobookGrabs::DOWNLOADING, 'still downloading in ' . Downloads::clientName($grab->client) . ' (' . (int) $grab->progress . '%)'];
        }
        if ($grab->isTorrent() && $grab->status === DownloadGrab::STATUS_IMPORTED) {
            return [AudiobookGrabs::SEEDING, 'imported already; its torrent is still seeding'];
        }
        if ($grab->isTorrent() && $grab->status === DownloadGrab::STATUS_FAILED) {
            return [AudiobookGrabs::SEEDING, 'not imported (' . ($grab->error ?: 'failed') . '); its torrent is still seeding'];
        }

        return null;
    }

    /** @return array{copy?: bool, novel_id?: ?int, request_id?: ?int} */
    public static function options(string $name): array
    {
        $grab = self::completed($name);
        if ($grab === null) {
            return [];
        }

        return ['copy' => $grab->isTorrent(), 'novel_id' => $grab->novel_id ?: null, 'request_id' => $grab->novel_request_id ?: null];
    }

    public static function imported(string $name, ?Novel $novel): void
    {
        $grab = self::completed($name);
        if ($grab === null) {
            return;
        }
        $grab->forceFill([
            'status' => DownloadGrab::STATUS_IMPORTED,
            'imported_type' => DownloadGrab::IMPORTED_COMIC,
            'imported_id' => $novel?->id,
            'imported_at' => now(),
            'error' => null,
        ])->save();
        \App\DownloadEvent::log(\App\DownloadEvent::IMPORTED, $grab->title, $novel !== null ? 'Added to the comics: ' . $novel->name . '.' : 'Added to the comics.', $grab);
    }

    /** @return bool  true: leave the entry in the inbox (a torrent, still seeding) */
    public static function failed(string $name, string $reason): bool
    {
        $grab = self::completed($name);
        if ($grab === null) {
            return false;
        }
        $grab->fail('Not imported: ' . $reason);

        return $grab->isTorrent();
    }

    private static function completed(string $name): ?DownloadGrab
    {
        $grab = self::forEntry($name);

        return $grab !== null && $grab->status === DownloadGrab::STATUS_COMPLETED ? $grab : null;
    }
}
