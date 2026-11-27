<?php

namespace App\Downloads;

use App\Audiobooks\AudiobookInbox;
use App\Audiobooks\AudiobookLibrary;
use App\DownloadEvent;
use App\DownloadGrab;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * The nightly tidy-up of the download inboxes (downloads:tidy): the
 * audiobook inbox (novarr.audiobooks.inbox, also the owner's drop-in folder),
 * the books download inbox (novarr.books.download_inbox) and the comics
 * inbox (novarr.comics.inbox, also a drop-in folder; a usenet / dropped-in
 * comic is deleted by its import, so what is left here is torrents'), with
 * the same rules for all three.
 *
 * What it deletes, and only that:
 *   - a finished download Novarr already IMPORTED (or that failed) whose
 *     files were left behind: a torrent's (imports copy them, so it can seed)
 *     once Transmission no longer has the torrent, a usenet one at once;
 *   - {audiobook inbox}/_failed entries (unreadable files, with their
 *     .reason.txt) older than FAILED_DAYS.
 *   - readers' audiobook uploads (App\Audiobooks\AudiobookUploads::tidy(),
 *     {audiobooks root}/_uploads): one abandoned while uploading (no chunk
 *     for novarr.audiobooks.uploads.abandoned_hours, 48 h), row and files;
 *     the leftover files of declined / failed ones. Done whether or not
 *     Transmission answers (no torrent ever uses _uploads); a PENDING (or
 *     processing) upload is never touched.
 *
 * What it never deletes:
 *   - anything a torrent in Transmission uses: ANY torrent (Novarr's or one
 *     the owner added by hand), in ANY state (seeding, paused, stopped,
 *     errored), whose name is the entry's — checked against Transmission's
 *     full list every run;
 *   - anything at all when Transmission is set up but cannot be asked (the
 *     list is unknown: the whole run deletes nothing);
 *   - an entry no grab accounts for (a file the owner dropped in and the
 *     scanner has not imported yet, or anything else);
 *   - a grab that is still queued, downloading or completed-not-imported.
 */
class InboxTidy
{
    /** Unreadable audiobook files are kept this long in _failed, to be looked at. */
    public const FAILED_DAYS = 30;

    public function __construct(private Transmission $transmission)
    {
    }

    /**
     * @return array{deleted: list<string>, kept: list<string>, problem: ?string}
     */
    public function run(bool $dryRun = false): array
    {
        $deleted = [];
        $kept = [];

        // Readers' uploads first: nothing to do with torrents.
        try {
            $deleted = app(\App\Audiobooks\AudiobookUploads::class)->tidy($dryRun);
        } catch (\Throwable $e) {
            Log::warning('Downloads tidy-up: audiobook uploads not tidied: ' . $e->getMessage());
        }

        // Every torrent Transmission has, by name: never touched.
        $seeding = [];
        if (Downloads::transmissionConfigured()) {
            try {
                foreach ($this->transmission->all() as $torrent) {
                    $seeding[mb_strtolower(trim((string) ($torrent['name'] ?? '')))] = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Downloads tidy-up: Transmission could not be asked, nothing deleted: ' . $e->getMessage());

                return ['deleted' => $deleted, 'kept' => [], 'problem' => 'Transmission could not be asked, so nothing was deleted from the inboxes: ' . $e->getMessage()];
            }
        }

        foreach ([DownloadGrab::KIND_AUDIOBOOK => AudiobookLibrary::inbox(), DownloadGrab::KIND_EBOOK => Downloads::booksInbox(), DownloadGrab::KIND_COMIC => \App\Comics\ComicLibrary::inbox()] as $kind => $inbox) {
            if (!is_dir($inbox)) {
                continue;
            }
            foreach (scandir($inbox) ?: [] as $name) {
                if ($name === '.' || $name === '..' || $name === AudiobookInbox::FAILED_DIR || str_starts_with($name, '.')) {
                    continue;
                }
                $verdict = $this->verdict($kind, $name, $seeding);
                if ($verdict === null) {
                    $kept[] = "{$name}";
                    continue;
                }
                if (!$dryRun && !self::remove("{$inbox}/{$name}")) {
                    $kept[] = "{$name} (could not be deleted)";
                    continue;
                }
                $deleted[] = "{$name} — {$verdict}";
                if (!$dryRun) {
                    DownloadEvent::log(DownloadEvent::TIDIED, $name, $verdict);
                }
            }
        }

        // Unreadable audiobook / comic files, after FAILED_DAYS (their .reason.txt goes with them).
        foreach ([AudiobookLibrary::inbox(), \App\Comics\ComicLibrary::inbox()] as $failedInbox) {
            $failedDir = $failedInbox . '/' . AudiobookInbox::FAILED_DIR;
            if (is_dir($failedDir)) {
                $cutoff = now()->subDays(self::FAILED_DAYS)->getTimestamp();
                foreach (scandir($failedDir) ?: [] as $name) {
                    if ($name === '.' || $name === '..' || str_ends_with($name, '.reason.txt')) {
                        continue;
                    }
                    $path = "{$failedDir}/{$name}";
                    // A torrent's files never land in _failed, but check anyway.
                    if (isset($seeding[mb_strtolower($name)]) || (int) @filemtime($path) > $cutoff) {
                        continue;
                    }
                    if (!$dryRun) {
                        self::remove($path);
                        @unlink("{$failedDir}/{$name}.reason.txt");
                        DownloadEvent::log(DownloadEvent::TIDIED, $name, 'Unreadable, kept ' . self::FAILED_DAYS . ' days in _failed.');
                    }
                    $deleted[] = "_failed/{$name} — unreadable, older than " . self::FAILED_DAYS . ' days';
                }
            }
        }

        return ['deleted' => $deleted, 'kept' => $kept, 'problem' => null];
    }

    /**
     * Why this inbox entry may go, or null to keep it.
     *
     * @param  array<string, true>  $seeding  every torrent's name (lower case)
     */
    private function verdict(string $kind, string $name, array $seeding): ?string
    {
        if (isset($seeding[mb_strtolower($name)])) {
            return null; // a torrent uses it
        }
        $grab = DownloadGrab::where('kind', $kind)->where('completed_path', $name)->orderByDesc('id')->first();
        if ($grab === null) {
            return null; // not a download Novarr knows: the owner's, or not imported yet
        }
        if (!in_array($grab->status, [DownloadGrab::STATUS_IMPORTED, DownloadGrab::STATUS_FAILED], true)) {
            return null; // still being downloaded or imported
        }
        if ($grab->protocol === DownloadGrab::TORRENT) {
            return $grab->status === DownloadGrab::STATUS_IMPORTED
                ? 'imported, and its torrent is no longer in Transmission'
                : 'failed, and its torrent is no longer in Transmission';
        }

        return $grab->status === DownloadGrab::STATUS_IMPORTED ? 'imported (usenet)' : 'failed (usenet)';
    }

    private static function remove(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }

        return is_dir($path) && File::deleteDirectory($path);
    }
}
