<?php

namespace App\Books;

use App\Novel;
use App\NovelRequest;
use App\Services\Requests\RequestRefused;
use App\Services\Requests\Requests;
use App\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Books readers upload, through the owner's approval:
 *
 *   1. receive()  a reader sends an ePub: it is read at once (a file that is
 *                 not a readable ePub is refused there and then), parked in
 *                 {books}/pending/, and becomes a pending request of medium
 *                 'book'. The owner is emailed (RequestNotifier::created).
 *   2. search()   the owner's review page asks Google Books and Open Library
 *                 for matches (the file's own title / author / ISBN first, or
 *                 whatever the owner types: a "dump" ePub with a junk title
 *                 is matched by searching for the right one).
 *   3. approve()  the owner's chosen details (a match, the file's own, or
 *                 typed in) are imported with the file (BookLibrary), the
 *                 request is approved and, the book being complete, marked
 *                 available, which tells the reader.
 *      decline()  the request is declined and the parked file removed.
 *
 * The owner's own uploads take the same path, without the email.
 */
class BookUploads
{
    public const PENDING_DIR = 'pending';

    /** Public-disk folder of a parked upload's cover (shown on the request and review page). */
    public const COVER_DIR = 'book-uploads';

    /** Chapter labels kept in upload_meta for the review page. */
    private const KEEP_LABELS = 40;

    public function __construct(
        private Requests $requests,
        private BookLibrary $library,
        private BookLookup $lookup,
    ) {
    }

    /**
     * @throws EpubException   not a readable ePub, or nothing to read in it
     * @throws RequestRefused  already in the library / already waiting / pending limit
     */
    public function receive(User $user, string $file, string $originalName, ?string $note): NovelRequest
    {
        $parked = $this->park($file, $originalName);
        $metadata = $parked['metadata'];

        try {
            return $this->requests->createUpload($user, [
                'title' => $metadata['title'] ?: BookLibrary::titleFromFilename($originalName),
                'author' => $metadata['authors'] !== [] ? implode(', ', $metadata['authors']) : null,
                'cover_url' => $parked['meta']['cover_path'] !== null ? Storage::disk('public')->url($parked['meta']['cover_path']) : null,
                'description' => $metadata['description'],
                'upload_path' => $parked['relative'],
                'upload_meta' => $parked['meta'],
            ], $note);
        } catch (\Throwable $e) {
            // No request, no parked file.
            self::unpark($parked);
            throw $e;
        }
    }

    /**
     * The owner fulfils a book asked for by title (NovelRequest::isWanted())
     * with the ePub they found: the file is read and parked like an upload,
     * the reader's title, author and cover stay on the request, and the
     * catalogues are searched for the requested book, so the review page
     * opens with it as the first match.
     *
     * @throws EpubException   not a readable ePub, or nothing to read in it
     * @throws RequestRefused  not a pending wanted book / the file is already in the library or waiting
     */
    public function attach(NovelRequest $request, string $file, string $originalName): NovelRequest
    {
        if (!$request->isPending() || !$request->isWanted()) {
            throw new RequestRefused(409, 'This request already has its ePub, or is no longer waiting.', 'not_wanted');
        }
        $parked = $this->park($file, $originalName);
        // What the reader asked for, with the series / ISBN a pasted book link gave (its first candidate).
        $asked = (array) ($request->candidateList()[0] ?? []);
        $meta = $parked['meta'] + ['requested' => ['title' => $request->title, 'author' => $request->author]
            + array_filter(['series' => $asked['series'] ?? null, 'series_index' => $asked['series_index'] ?? null, 'isbn' => $asked['isbn'] ?? null], fn($v) => $v !== null && $v !== '')];

        try {
            $request->forceFill([
                'upload_path' => $parked['relative'],
                'upload_meta' => $meta,
                // The reader's cover until the owner chooses (the file's when they asked for none).
                'cover_url' => $request->cover_url ?: ($meta['cover_path'] !== null ? Storage::disk('public')->url($meta['cover_path']) : null),
                'description' => $request->description ?: $parked['metadata']['description'],
                'candidates' => null,
                'candidates_ranked_at' => null,
            ])->save();
        } catch (\Throwable $e) {
            self::unpark($parked);
            throw $e;
        }

        $this->search($request, (string) $request->title, $request->author ? trim(explode(',', (string) $request->author)[0]) : null);
        Log::info('Book request fulfilled with an upload', ['request' => $request->id, 'file' => $originalName]);

        return $request->refresh();
    }

    /**
     * Read an ePub and park a copy under pending/ with its cover: what a
     * request needs to know about the file (upload_meta).
     *
     * @return array{relative: string, target: string, metadata: array, meta: array}
     *
     * @throws EpubException
     * @throws RequestRefused  already in the library / the same file already waiting
     */
    private function park(string $file, string $originalName): array
    {
        $epub = Epub::open($file);
        try {
            $metadata = $epub->metadata();
            $chapters = $epub->chapters();
            $cover = $epub->cover();
        } finally {
            $epub->close();
        }
        if ($chapters === []) {
            throw new EpubException('This ePub has no text to read (only images, or nothing in its spine).');
        }

        $sha1 = sha1_file($file);
        $inLibrary = Novel::where('translator_url', BookLibrary::urlFor($sha1))->first();
        if ($inLibrary !== null) {
            throw new RequestRefused(409, "This book is already in the library: {$inLibrary->name}.", 'in_library', ['novel_id' => (int) $inLibrary->id]);
        }
        $waiting = NovelRequest::where('medium', Novel::MEDIUM_BOOK)
            ->where('status', NovelRequest::STATUS_PENDING)
            ->where('upload_meta', 'like', '%"sha1":"' . $sha1 . '"%')
            ->first();
        if ($waiting !== null) {
            throw new RequestRefused(409, "This file is already waiting for the owner ({$waiting->title}).", 'already_requested', ['request_id' => (int) $waiting->id]);
        }
        // Another file of a book the library has (same title and author): say so now.
        $existing = BookLibrary::existing($metadata['title'] ?: BookLibrary::titleFromFilename($originalName), implode(', ', (array) $metadata['authors']));
        if ($existing !== null) {
            throw new RequestRefused(409, self::inLibraryMessage($existing), 'in_library', ['novel_id' => (int) $existing->id]);
        }

        $token = (string) Str::uuid();
        $relative = self::PENDING_DIR . "/{$token}.epub";
        $target = self::root() . '/' . $relative;
        File::ensureDirectoryExists(dirname($target), 0755);
        if (!File::copy($file, $target)) {
            throw new \RuntimeException('The upload could not be stored.');
        }
        $coverPath = $cover !== null ? $this->storeCover($token, $cover) : null;

        return [
            'relative' => $relative,
            'target' => $target,
            'metadata' => $metadata,
            'meta' => [
                'sha1' => $sha1,
                'original_name' => mb_substr($originalName, 0, 200),
                'size' => (int) filesize($file),
                'title' => $metadata['title'],
                'authors' => $metadata['authors'],
                'isbn' => $metadata['isbn'],
                'language' => $metadata['language'],
                'publisher' => $metadata['publisher'],
                'year' => $metadata['year'],
                'series' => $metadata['series'],
                'series_index' => $metadata['series_index'],
                'subjects' => array_slice($metadata['subjects'], 0, 20),
                'chapters' => count($chapters),
                'words' => array_sum(array_column($chapters, 'words')),
                'labels' => array_slice(array_column($chapters, 'label'), 0, self::KEEP_LABELS),
                'excerpt' => self::excerpt($chapters),
                'cover_path' => $coverPath,
            ],
        ];
    }

    /** "“Star Wars: Thrawn” by Timothy Zahn is already in the library." */
    public static function inLibraryMessage(Novel $book): string
    {
        $by = trim((string) $book->author) !== '' ? ' by ' . trim((string) $book->author) : '';

        return "\u{201C}{$book->name}\u{201D}{$by} is already in the library.";
    }

    /**
     * The pending upload is a book the library already has: approve the
     * request as that book (the reader is told it is ready to read), and
     * drop the uploaded file.
     *
     * @throws RequestRefused  not pending
     */
    public function useExisting(NovelRequest $request, User $admin, Novel $existing): NovelRequest
    {
        if (!$request->isPending()) {
            throw new RequestRefused(409, 'Only a pending request can be approved.', 'not_pending');
        }
        $this->requests->approve($request, $admin, ['novel_id' => (int) $existing->id]);
        $this->requests->markAvailableNow($request);
        self::discard($request);
        $request->forceFill(['upload_path' => null])->saveQuietly();
        Log::info('Book request matched to a book already in the library', ['request' => $request->id, 'novel' => $existing->id]);

        return $request->refresh();
    }

    private static function unpark(array $parked): void
    {
        @unlink($parked['target']);
        if (($parked['meta']['cover_path'] ?? null) !== null) {
            Storage::disk('public')->delete($parked['meta']['cover_path']);
        }
    }

    /**
     * Several files at once (the upload page, the app, the email inbox): each
     * is received on its own, one refused file never stops the others, except
     * the pending limit (every later file would be refused the same way).
     *
     * @param  array<int, array{path: string, name: string}>  $files
     * @return array{sent: NovelRequest[], refused: array<int, array{file: string, message: string, code: string, novel_id?: int}>}
     */
    public function receiveMany(User $user, array $files, ?string $note): array
    {
        $sent = [];
        $refused = [];
        foreach ($files as $file) {
            $name = $file['name'];
            try {
                $sent[] = $this->receive($user, $file['path'], $name, $note);
            } catch (EpubException $e) {
                $refused[] = ['file' => $name, 'message' => $e->getMessage(), 'code' => 'unreadable'];
            } catch (RequestRefused $e) {
                $refused[] = array_filter([
                    'file' => $name,
                    'message' => $e->getMessage(),
                    'code' => (string) $e->errorCode,
                    'novel_id' => isset($e->extra['novel_id']) ? (int) $e->extra['novel_id'] : null,
                ], fn($v) => $v !== null);
                if ($e->errorCode === 'pending_limit') {
                    break;
                }
            } catch (\Throwable $e) {
                Log::error("Book upload of {$name} failed: " . $e->getMessage(), ['exception' => $e]);
                $refused[] = ['file' => $name, 'message' => 'It could not be added. Try again in a moment.', 'code' => 'failed'];
            }
        }

        return ['sent' => $sent, 'refused' => $refused];
    }

    /**
     * Ask the catalogues and keep the result on the request (its
     * candidates; candidates_ranked_at marks that a search ran).
     *
     * @return array<int, array> the candidates
     */
    public function search(NovelRequest $request, ?string $title = null, ?string $author = null, ?string $isbn = null): array
    {
        $meta = (array) ($request->upload_meta ?? []);
        $query = [
            'title' => $title ?? ($meta['title'] ?? $request->title),
            'author' => $author ?? ($meta['authors'][0] ?? $request->author),
            'isbn' => $isbn ?? ($meta['isbn'] ?? null),
            'language' => $meta['language'] ?? null,
        ];
        // A typed title replaces the file's ISBN (which may be the wrong one), unless one was typed too.
        if ($title !== null && $isbn === null) {
            $query['isbn'] = null;
        }

        $candidates = $this->lookup->candidates($query);
        $request->forceFill(['candidates' => $candidates, 'candidates_ranked_at' => now()])->save();

        return $candidates;
    }

    /**
     * Add the book with the owner's details.
     *
     * @param  array  $details  BookLibrary::import()'s details (title, author, description, genres, cover_url, file_cover)
     *
     * @throws RequestRefused  not pending, the file is gone, or a duplicate title (unless forced)
     */
    public function approve(NovelRequest $request, User $admin, array $details, bool $force = false, bool $lookup = false): Novel
    {
        if (!$request->isPending()) {
            throw new RequestRefused(409, 'Only a pending request can be approved.', 'not_pending', ['status' => $request->displayStatus()]);
        }
        $path = self::pendingPath($request);
        if ($path === null || !is_file($path)) {
            throw new RequestRefused(410, 'The uploaded file is no longer here. Decline this request and ask for the book to be uploaded again.', 'upload_missing');
        }

        try {
            $outcome = $this->library->import($path, (string) ($request->upload_meta['original_name'] ?? 'book.epub'), $force, $lookup, $details);
        } catch (EpubException $e) {
            throw new RequestRefused(422, $e->getMessage(), 'unreadable');
        }

        if ($outcome['result'] === BookLibrary::DUPLICATE) {
            throw new RequestRefused(409, self::inLibraryMessage($outcome['novel']), 'duplicate', [
                'duplicate' => \App\Services\DuplicateSeries::describe($outcome['novel']),
            ]);
        }

        $novel = $outcome['novel'];
        $this->requests->approve($request, $admin, ['novel_id' => (int) $novel->id]);
        $this->requests->markAvailableNow($request);
        self::discard($request);
        $request->forceFill(['upload_path' => null])->saveQuietly();

        Log::info('Book request approved', ['request' => $request->id, 'novel' => $novel->id, 'result' => $outcome['result']]);
        // Two-way Calibre sync: the new book goes to Calibre with the matched details.
        if ($outcome['result'] === BookLibrary::CREATED) {
            \App\Jobs\PushBookToCalibre::for($novel);
        }

        return $novel;
    }

    /** @throws RequestRefused not pending */
    public function decline(NovelRequest $request, User $admin, ?string $reason): NovelRequest
    {
        $request = $this->requests->decline($request, $admin, $reason);
        self::discard($request);
        $request->forceFill(['upload_path' => null])->saveQuietly();

        return $request;
    }

    /** The parked ePub of an upload request, or null. */
    public static function pendingPath(NovelRequest $request): ?string
    {
        $relative = (string) ($request->upload_path ?? '');
        // Only ever a file under pending/, whatever the column holds.
        if (!preg_match('#^' . self::PENDING_DIR . '/[0-9a-f-]{36}\.epub$#', $relative)) {
            return null;
        }

        return self::root() . '/' . $relative;
    }

    /** Remove an upload's parked file and cover (decided, cancelled or deleted). */
    public static function discard(NovelRequest $request): void
    {
        if (!$request->isUpload()) {
            return;
        }
        if ($path = self::pendingPath($request)) {
            @unlink($path);
        }
        $cover = (string) ($request->upload_meta['cover_path'] ?? '');
        if ($cover !== '' && str_starts_with($cover, self::COVER_DIR . '/') && !str_contains($cover, '..')) {
            // An approved book has its own copy of the cover; this one was only for the request.
            Storage::disk('public')->delete($cover);
        }
    }

    private static function root(): string
    {
        return rtrim((string) (config('novarr.books.path') ?: storage_path('app/books')), '/');
    }

    /** @param array{bytes: string, type: string, name: string} $cover */
    private function storeCover(string $token, array $cover): ?string
    {
        $extension = match ($cover['type']) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $path = self::COVER_DIR . "/{$token}.{$extension}";

        try {
            Storage::disk('public')->put($path, $cover['bytes']);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('An uploaded book\'s cover could not be stored: ' . $e->getMessage());

            return null;
        }
    }

    /** About 900 characters from the first real chapter, as plain text: what the file actually holds. */
    private static function excerpt(array $chapters): string
    {
        foreach ($chapters as $chapter) {
            if ($chapter['words'] >= 100) {
                $text = html_entity_decode(strip_tags(str_replace('</p>', "</p>\n", $chapter['html'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return \App\Sources\AbstractSource::plainSynopsis($text, 900);
            }
        }

        return '';
    }
}
