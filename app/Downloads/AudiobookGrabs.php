<?php

namespace App\Downloads;

use App\Audiobook;
use App\DownloadGrab;

/**
 * What `audiobooks:scan` (App\Audiobooks\AudiobookInbox) needs to know about
 * an inbox entry that is a grabbed download (App\DownloadGrab of kind
 * audiobook whose completed_path is the entry's name, the newest one):
 *
 *   hold()      leave it alone: still downloading (the client is writing
 *               into the inbox: the scan must not take half of it, whatever
 *               its sizes say), or a torrent already imported / failed
 *               (it stays in the inbox to seed; taking it again would only
 *               fail as "already in the library" and move it, breaking
 *               the seeding).
 *   options()   for a finished grab: the importer's options, the book the
 *               owner grabbed it for ("expected book for this inbox entry",
 *               App\Audiobooks\AudiobookMatcher::expected()) and copy (a
 *               torrent's files are copied, never moved: it keeps seeding).
 *   imported()  the grab is done: imported, with the audiobook.
 *   failed()    the import failed: the grab says why; answers true when the
 *               entry must stay where it is (a torrent: still seeding)
 *               instead of going to _failed.
 *
 * An entry no grab names is the scan's as before.
 */
final class AudiobookGrabs
{
    /** Entry states the inbox shows for a grab's entry. */
    public const DOWNLOADING = 'downloading';
    public const SEEDING = 'seeding';

    public static function forEntry(string $name): ?DownloadGrab
    {
        if ($name === '') {
            return null;
        }

        return DownloadGrab::where('kind', DownloadGrab::KIND_AUDIOBOOK)
            ->where('completed_path', $name)
            ->orderByDesc('id')
            ->first();
    }

    /** @return ?array{0: string, 1: string}  [state (downloading | seeding), why], or null to import as usual */
    public static function hold(string $name): ?array
    {
        $grab = self::forEntry($name);
        if ($grab === null) {
            return null;
        }
        if ($grab->isActive()) {
            return [self::DOWNLOADING, 'still downloading in ' . Downloads::clientName($grab->client) . ' (' . (int) $grab->progress . '%)'];
        }
        if ($grab->isTorrent() && $grab->status === DownloadGrab::STATUS_IMPORTED) {
            return [self::SEEDING, 'imported already; its torrent is still seeding'];
        }
        if ($grab->isTorrent() && $grab->status === DownloadGrab::STATUS_FAILED) {
            return [self::SEEDING, 'not imported (' . ($grab->error ?: 'failed') . '); its torrent is still seeding'];
        }

        return null;
    }

    /** @return array{copy?: bool, novel_id?: ?int}  App\Audiobooks\AudiobookImporter::import()'s options */
    public static function options(string $name): array
    {
        $grab = self::completed($name);
        if ($grab === null) {
            return [];
        }

        return ['copy' => $grab->isTorrent(), 'novel_id' => $grab->novel_id ?: null];
    }

    public static function imported(string $name, Audiobook $audiobook): void
    {
        $grab = self::completed($name);
        if ($grab === null) {
            return;
        }
        $grab->forceFill([
            'status' => DownloadGrab::STATUS_IMPORTED,
            'imported_type' => DownloadGrab::IMPORTED_AUDIOBOOK,
            'imported_id' => (int) $audiobook->id,
            'imported_at' => now(),
            'error' => null,
        ])->save();
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
