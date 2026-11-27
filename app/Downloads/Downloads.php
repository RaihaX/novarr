<?php

namespace App\Downloads;

use App\Audiobooks\AudiobookLibrary;
use App\DownloadGrab;

/**
 * Searching Usenet & torrents for books and audiobooks: what is set up.
 *
 * The flow (owner only, the home network like the other admin pages):
 *
 *   1. App\Downloads\Prowlarr::search() asks Prowlarr (ALL its indexers,
 *      usenet and torrent) in the book or audiobook categories, and
 *      App\Downloads\ReleaseRanking orders the results for the wanted book.
 *   2. App\Downloads\Grabber::grab() sends the chosen result to NZBGet
 *      (usenet, in the kind's category) or Transmission (torrent, in the
 *      kind's download-dir) and records an App\DownloadGrab.
 *   3. `downloads:poll` (every 2 minutes, App\Downloads\DownloadPoller)
 *      follows each grab in its client; a finished ebook is taken from the
 *      books inbox into the review queue (App\Downloads\BookDownloads), a
 *      finished audiobook is imported by `audiobooks:scan` from the
 *      audiobooks inbox, linked to the grab's book
 *      (App\Downloads\AudiobookGrabs).
 *
 * Off unless Prowlarr's url and API key are set; each download client is
 * optional (a result of a protocol without its client cannot be grabbed).
 */
final class Downloads
{
    public static function enabled(): bool
    {
        return (string) config('novarr.prowlarr.url') !== '' && (string) config('novarr.prowlarr.api_key') !== '';
    }

    public static function nzbgetConfigured(): bool
    {
        return (string) config('novarr.nzbget.url') !== '';
    }

    public static function transmissionConfigured(): bool
    {
        return (string) config('novarr.transmission.url') !== '';
    }

    /** The client a protocol's results go to, or null when it is not set up. */
    public static function clientFor(string $protocol): ?string
    {
        return match ($protocol) {
            DownloadGrab::USENET => self::nzbgetConfigured() ? DownloadGrab::NZBGET : null,
            DownloadGrab::TORRENT => self::transmissionConfigured() ? DownloadGrab::TRANSMISSION : null,
            default => null,
        };
    }

    /** Why a protocol's results cannot be grabbed, or null when they can. */
    public static function clientMissing(string $protocol): ?string
    {
        if (self::clientFor($protocol) !== null) {
            return null;
        }

        return $protocol === DownloadGrab::TORRENT
            ? 'Transmission is not set up (NOVARR_TRANSMISSION_URL), so torrents cannot be grabbed.'
            : 'NZBGet is not set up (NOVARR_NZBGET_URL), so usenet results cannot be grabbed.';
    }

    /** The books download inbox, as Novarr sees it. */
    public static function booksInbox(): string
    {
        return rtrim((string) (config('novarr.books.download_inbox') ?: storage_path('app/books-inbox')), '/');
    }

    /** The inbox a kind's downloads finish in, as Novarr sees it. */
    public static function inboxFor(string $kind): string
    {
        return match ($kind) {
            DownloadGrab::KIND_AUDIOBOOK => AudiobookLibrary::inbox(),
            DownloadGrab::KIND_COMIC => \App\Comics\ComicLibrary::inbox(),
            default => self::booksInbox(),
        };
    }

    /** NZBGet's category for a kind's downloads (each completes into its own inbox). */
    public static function nzbCategory(string $kind): string
    {
        return (string) config(match ($kind) {
            DownloadGrab::KIND_AUDIOBOOK => 'novarr.nzbget.category_audiobooks',
            DownloadGrab::KIND_COMIC => 'novarr.nzbget.category_comics',
            default => 'novarr.nzbget.category_books',
        });
    }

    /** Transmission's download-dir for a kind's downloads. */
    public static function torrentDir(string $kind): string
    {
        return (string) config(match ($kind) {
            DownloadGrab::KIND_AUDIOBOOK => 'novarr.transmission.dir_audiobooks',
            DownloadGrab::KIND_COMIC => 'novarr.transmission.dir_comics',
            default => 'novarr.transmission.dir_books',
        });
    }

    /** "ebook" / "audiobook" / "comic" from anything a form sends (ebook when unknown). */
    public static function kind(?string $kind): string
    {
        return in_array($kind, DownloadGrab::KINDS, true) ? $kind : DownloadGrab::KIND_EBOOK;
    }

    /** "Downloading 43%", "Finished — ready to review", "Failed: …": one line for a grab. */
    public static function statusLine(DownloadGrab $grab): string
    {
        return match ($grab->status) {
            DownloadGrab::STATUS_QUEUED => 'Queued in ' . self::clientName($grab->client),
            DownloadGrab::STATUS_DOWNLOADING => 'Downloading ' . (int) $grab->progress . '%',
            DownloadGrab::STATUS_COMPLETED => match ($grab->kind) {
                DownloadGrab::KIND_AUDIOBOOK => 'Finished — waiting for the audiobooks scan',
                DownloadGrab::KIND_COMIC => 'Finished — waiting for the comics scan',
                default => 'Finished — taking the ePub',
            },
            DownloadGrab::STATUS_IMPORTED => match (true) {
                $grab->kind === DownloadGrab::KIND_AUDIOBOOK => 'Imported — in the audiobooks',
                $grab->kind === DownloadGrab::KIND_COMIC => 'Imported — in the comics',
                $grab->imported_type === DownloadGrab::IMPORTED_BOOK => 'Finished — added to the library',
                default => 'Finished — ready to review',
            },
            DownloadGrab::STATUS_FAILED => 'Failed: ' . ($grab->error ?: 'no reason given'),
            default => ucfirst((string) $grab->status),
        };
    }

    /** "Ebook" / "Audiobook" / "Comic": a kind for the owner's pages. */
    public static function kindLabel(?string $kind): string
    {
        return match ($kind) {
            DownloadGrab::KIND_AUDIOBOOK => 'Audiobook',
            DownloadGrab::KIND_COMIC => 'Comic',
            default => 'Ebook',
        };
    }

    public static function clientName(?string $client): string
    {
        return $client === DownloadGrab::TRANSMISSION ? 'Transmission' : 'NZBGet';
    }
}
