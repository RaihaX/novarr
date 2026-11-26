<?php

namespace App\Books;

use App\Novel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Novarr → Calibre, the other direction of the hourly Calibre import
 * (App\Books\CalibreImport): two-way sync of the book library.
 *
 *   - A book added in Novarr (an approved upload, a requested book the owner
 *     uploaded, an emailed ePub) is uploaded to Calibre with the details the
 *     owner matched (title, authors, description, genres as tags, series,
 *     cover). Its Calibre id and uuid are kept (calibre_id, calibre_uuid),
 *     so the import knows it and never brings it back as a second copy.
 *   - An edit in Novarr (the edit form) updates the same details in Calibre,
 *     for books that came from Calibre too (found there by their uuid).
 *   - Deleting stays separate on each side.
 *
 * Runs in the background (App\Jobs\PushBookToCalibre) and from
 * books:calibre-push (backfill, retries). A failure is kept on the book
 * (calibre_sync_error) and retried by the hourly run.
 */
class CalibrePush
{
    public function __construct(private CalibreWebWriter $writer, private CalibreWeb $reader)
    {
    }

    public static function enabled(): bool
    {
        return CalibreWeb::enabled() && (bool) config('novarr.calibre_web.push', true)
            && (string) config('novarr.calibre_web.username', '') !== '';
    }

    /** A book Calibre should have: every book, wherever it came from. */
    public static function applies(Novel $novel): bool
    {
        return $novel->medium === Novel::MEDIUM_BOOK || str_starts_with((string) $novel->source_ref, BookLibrary::CALIBRE_REF);
    }

    public static function fromCalibre(Novel $novel): bool
    {
        return str_starts_with((string) $novel->source_ref, BookLibrary::CALIBRE_REF);
    }

    /**
     * Send the book: upload it when Calibre does not have it yet, then its
     * details. Returns what was done: 'uploaded' | 'updated'.
     *
     * @throws \RuntimeException
     */
    public function push(Novel $novel): string
    {
        if (!self::applies($novel)) {
            throw new \RuntimeException('Only books are sent to Calibre.');
        }

        $done = 'updated';
        $calibreId = $novel->calibre_id ? (int) $novel->calibre_id : null;
        if ($calibreId === null && self::fromCalibre($novel)) {
            $calibreId = $this->findByUuid($novel, substr((string) $novel->source_ref, strlen(BookLibrary::CALIBRE_REF)));
        }
        if ($calibreId === null) {
            $file = BookLibrary::path($novel);
            if (!is_file($file)) {
                throw new \RuntimeException('The book’s ePub is missing from Novarr, so it cannot be sent to Calibre.');
            }
            $calibreId = $this->writer->upload($file, self::filename($novel));
            $novel->forceFill(['calibre_id' => $calibreId])->saveQuietly();
            $done = 'uploaded';
        }

        $novel->loadMissing(['file', 'tags']);
        $this->writer->update($calibreId, self::details($novel), self::coverFile($novel));

        $uuid = $novel->calibre_uuid ?: (self::fromCalibre($novel)
            ? substr((string) $novel->source_ref, strlen(BookLibrary::CALIBRE_REF))
            : $this->uuidOf($novel, $calibreId));
        $novel->forceFill([
            'calibre_id' => $calibreId,
            'calibre_uuid' => $uuid,
            'calibre_synced_at' => now(),
            'calibre_sync_error' => null,
        ])->saveQuietly();
        Log::info("Calibre: {$done} “{$novel->name}”", ['novel' => $novel->id, 'calibre_id' => $calibreId]);

        return $done;
    }

    /** Push, keeping a failure on the book instead of throwing. */
    public function pushQuietly(Novel $novel): ?string
    {
        try {
            return $this->push($novel);
        } catch (\Throwable $e) {
            Log::warning("Calibre: sending “{$novel->name}” failed: " . $e->getMessage());
            $novel->forceFill(['calibre_sync_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();

            return null;
        }
    }

    /** The details Calibre gets. */
    public static function details(Novel $novel): array
    {
        $authors = array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|&|;)\s*/u', (string) $novel->author) ?: [])));

        return array_filter([
            'title' => (string) $novel->name,
            'authors' => $authors !== [] ? $authors : null,
            'description' => (string) ($novel->description ?? ''),
            'tags' => $novel->tags->pluck('name')->all(),
            'series' => (string) ($novel->series ?? ''),
            'series_index' => $novel->series_index,
        ], fn($v) => $v !== null);
    }

    private static function coverFile(Novel $novel): ?string
    {
        $path = $novel->file?->file_path;
        if (!$path) {
            return null;
        }
        $full = Storage::disk('public')->path($path);

        return is_file($full) ? $full : null;
    }

    private static function filename(Novel $novel): string
    {
        $name = preg_replace('/[^\pL\pN\s.\'-]+/u', '', (string) $novel->name) ?: 'book';

        return mb_substr(trim($name), 0, 80) . '.epub';
    }

    /** The Calibre id of a book imported from Calibre, found by its uuid (OPDS search by title). */
    private function findByUuid(Novel $novel, string $uuid): ?int
    {
        foreach ([(string) $novel->name, preg_split('/\s*:\s+/u', (string) $novel->name)[0]] as $query) {
            foreach (($this->reader->search($query)['entries'] ?? []) as $entry) {
                if (($entry['uuid'] ?? null) === $uuid && !empty($entry['id'])) {
                    return (int) $entry['id'];
                }
            }
        }

        throw new \RuntimeException('This book is no longer in Calibre (no book with its Calibre id).');
    }

    /** The uuid Calibre gave a book Novarr uploaded (for the import's "already have it"). */
    private function uuidOf(Novel $novel, int $calibreId): ?string
    {
        try {
            foreach (($this->reader->search((string) $novel->name)['entries'] ?? []) as $entry) {
                if ((int) ($entry['id'] ?? 0) === $calibreId) {
                    return $entry['uuid'] ?? null;
                }
            }
        } catch (\Throwable) {
            // The id alone still keeps the import from bringing it back (CalibreImport).
        }

        return null;
    }
}
