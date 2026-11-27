<?php

namespace App\Downloads;

use App\Books\BookLibrary;
use App\Books\BookUploads;
use App\Books\EpubException;
use App\DownloadEvent;
use App\DownloadGrab;
use App\Novel;
use App\NovelRequest;
use App\Mail\AdminAlertMail;
use App\Services\AdminAlert;
use App\Services\DuplicateSeries;
use App\Services\Requests\RequestNotifier;
use App\Services\Requests\RequestRefused;
use App\Support\CurrentReader;
use App\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * A finished ebook download (App\DownloadGrab, completed) from the books
 * inbox (novarr.books.download_inbox) into the library or the review queue:
 *
 *   1. the ePub in the download: the entry itself when it is one, else the
 *      largest .epub in its folder (any depth), samples ignored;
 *   2. for a reader's wanted book (still pending and wanted):
 *        - the library has the book by now (BookLibrary::existing() of the
 *          request, or the file is one the library has): the request is
 *          closed as that book (BookUploads::useExisting(), the reader can
 *          read it) and the download dropped;
 *        - else BookUploads::attach() (the request gets the file, its
 *          catalogue matches are searched); then, with auto_approve, when
 *          the ePub's OWN title and author are the request's (DuplicateSeries
 *          normalisation; "Title: Subtitle" counts; authors as BookLibrary
 *          compares them), it is approved at once with the request's
 *          catalogue details (the first match when it is the same title,
 *          else the request's own title / author / description / cover):
 *          the reader is told as usual, the owner gets "Added
 *          automatically: X". Not a clear match (or the approve is refused):
 *          it waits in review: "Downloaded — check it's the right book";
 *      otherwise (a standalone search, or the request was fulfilled
 *      meanwhile) BookUploads::receive() as the owner: a new upload in the
 *      review queue;
 *   3. the owner is told (App\Services\AdminAlert) with the link;
 *   4. a usenet download is removed from the inbox (BookUploads parked its
 *      own copy). A torrent's files stay where they are, seeding: the grab
 *      (imported) is what stops them being taken twice.
 *
 * Nothing usable (no ePub, unreadable…): the grab fails with the reason
 * (blocklisted, the wanted book searched again) and the files stay.
 */
class BookDownloads
{
    /** A finished download not (yet) in the inbox fails after this long (seconds): the inbox paths do not agree. */
    public const MISSING_AFTER = 1800;

    public function __construct(private BookUploads $uploads, private AdminAlert $alert)
    {
    }

    /** @return string  the grab's status afterwards (imported, failed, or completed: still waiting for the files) */
    public function import(DownloadGrab $grab): string
    {
        $name = (string) $grab->completed_path;
        $path = Downloads::booksInbox() . '/' . $name;
        if ($name === '' || $name !== basename($name) || $name === '.' || $name === '..' || !file_exists($path)) {
            if ($grab->completed_at === null || $grab->completed_at->diffInSeconds(now()) >= self::MISSING_AFTER) {
                $grab->fail("The finished download is not in the books inbox ({$path}). Check that NZBGet's {$this->category()} category / Transmission's books folder finish into NOVARR_BOOKS_INBOX.", false);

                return DownloadGrab::STATUS_FAILED;
            }

            return DownloadGrab::STATUS_COMPLETED;
        }

        $epub = self::findEpub($path);
        if ($epub === null) {
            $has = self::listing($path);
            $grab->fail('There is no ePub in this download' . ($has !== '' ? " (it has {$has})." : '.'));

            return DownloadGrab::STATUS_FAILED;
        }

        $request = $grab->novel_request_id ? NovelRequest::find($grab->novel_request_id) : null;
        $wanted = $request !== null && $request->isPending() && $request->isWanted();
        $owner = $this->owner($grab);

        try {
            if ($wanted && ($existing = BookLibrary::existing((string) $request->title, $request->author)) !== null) {
                return $this->alreadyInLibrary($grab, $request, $owner, $existing, $path);
            }
            $result = $wanted
                ? $this->uploads->attach($request, $epub, basename($epub))
                : $this->uploads->receive($owner, $epub, basename($epub), 'Downloaded: ' . mb_substr($grab->title, 0, 400));
        } catch (EpubException $e) {
            $grab->fail('The ePub could not be read: ' . rtrim($e->getMessage(), '.') . '.');

            return DownloadGrab::STATUS_FAILED;
        } catch (RequestRefused $e) {
            // The file is a book the library has: that is the book they asked for.
            if ($wanted && $e->errorCode === 'in_library' && ($existing = Novel::find((int) ($e->extra['novel_id'] ?? 0))) !== null) {
                return $this->alreadyInLibrary($grab, $request, $owner, $existing, $path);
            }
            $grab->fail($e->getMessage());

            return DownloadGrab::STATUS_FAILED;
        } catch (\Throwable $e) {
            Log::error("Downloads: taking the ePub of grab {$grab->id} failed: " . $e->getMessage(), ['exception' => $e]);
            $grab->fail('The ePub could not be added to the review queue (' . $e->getMessage() . ').', false);

            return DownloadGrab::STATUS_FAILED;
        }

        $this->done($grab, DownloadGrab::IMPORTED_REQUEST, (int) $result->id, $path);
        $title = (string) ($result->title ?: $grab->title);
        $reviewUrl = RequestNotifier::adminBase() . route('books.review', $result->id, false);

        // A clear match to the wanted book: added at once.
        if ($wanted && DownloadProfile::autoApprove() && self::clearMatch($result)) {
            try {
                $novel = $this->uploads->approve($result, $owner, self::details($result));
                $this->done($grab, DownloadGrab::IMPORTED_BOOK, (int) $novel->id, null);
                DownloadEvent::log(DownloadEvent::AUTO_APPROVED, $grab->title, "Added to the library as “{$novel->name}”.", $grab);
                $this->alert->send("Added automatically: {$novel->name}",
                    "Added automatically: \u{201C}{$novel->name}\u{201D}" . ($novel->author ? " by {$novel->author}" : '') . ' was downloaded and is in the library; the reader has been told.',
                    RequestNotifier::adminBase() . route('novels.show', $novel->id, false),
                    self::bookMail($novel, [
                        'heading' => 'Added automatically',
                        'tone' => 'success',
                        'intro' => "\u{201C}{$novel->name}\u{201D} was downloaded, matched the request, and is in the library. The reader who asked for it has been told.",
                        'chip' => 'In the library',
                        'button' => 'Open the book',
                    ]));

                return DownloadGrab::STATUS_IMPORTED;
            } catch (RequestRefused $e) {
                Log::info("Downloads: grab {$grab->id} was not approved automatically: " . $e->getMessage());
            }
        }

        DownloadEvent::log(DownloadEvent::IMPORTED, $grab->title, $wanted ? 'Waiting in review: check it is the right book.' : 'In the review queue.', $grab);
        $meta = (array) ($result->upload_meta ?? []);
        $author = implode(', ', (array) ($meta['authors'] ?? [])) ?: (string) $result->author;
        $this->alert->send(
            "Downloaded: {$title}",
            $wanted
                ? "Your download of \u{201C}{$title}\u{201D} finished. Downloaded \u{2014} check it's the right book: review and add it."
                : "Your download of \u{201C}{$title}\u{201D} finished: review and add it.",
            $reviewUrl,
            [
                'eyebrow' => 'Downloads',
                'heading' => 'Download finished',
                'tone' => $wanted ? 'warning' : 'info',
                'intro' => $wanted
                    ? "Your download of \u{201C}{$title}\u{201D} finished, but it is not a clear match to the request. Check it is the right book, then add it to the library."
                    : "Your download of \u{201C}{$title}\u{201D} finished and is waiting in the review queue. Match its details and cover, then add it to the library.",
                'cover' => AdminAlertMail::coverUrl($result->cover_url),
                'title' => $title,
                'meta' => implode(' · ', array_filter(['Book', $author])),
                'chip' => 'Needs review',
                'button' => 'Review and match',
                'secondary' => ['text' => 'Open Downloads', 'url' => RequestNotifier::adminBase() . route('downloads.index', [], false)],
            ],
        );

        return DownloadGrab::STATUS_IMPORTED;
    }

    /**
     * Is the attached ePub clearly the requested book? Its own title is the
     * request's (or the request's with a subtitle), and its own authors
     * agree with the request's.
     */
    public static function clearMatch(NovelRequest $request): bool
    {
        $meta = (array) ($request->upload_meta ?? []);
        $mine = DuplicateSeries::normalize((string) ($meta['title'] ?? ''));
        $wanted = DuplicateSeries::normalize((string) $request->title);
        if ($mine === '' || $wanted === '') {
            return false;
        }
        $titleOk = $mine === $wanted || (mb_strlen($wanted) >= 5 && str_starts_with($mine, $wanted)) || (mb_strlen($mine) >= 5 && str_starts_with($wanted, $mine));

        return $titleOk && BookLibrary::authorsAgree(implode(', ', (array) ($meta['authors'] ?? [])), $request->author);
    }

    /** The request's catalogue details for BookUploads::approve(): its first match when that is the same title, else its own. */
    public static function details(NovelRequest $request): array
    {
        return self::baseDetails($request) + self::seriesDetails($request);
    }

    /**
     * The series the request already knows (a pasted Goodreads / Amazon link,
     * a catalogue hit that carries one), else Hardcover's for its title and
     * author (when a token is set). Empty: the ePub's own series, if any,
     * is used by the import.
     */
    public static function seriesDetails(NovelRequest $request): array
    {
        foreach ($request->candidateList() as $candidate) {
            if (trim((string) ($candidate['series'] ?? '')) !== '') {
                return ['series' => trim((string) $candidate['series']), 'series_index' => $candidate['series_index'] ?? null];
            }
        }
        if (\App\Books\Hardcover::enabled()) {
            try {
                $found = app(\App\Books\Hardcover::class)->seriesFor((string) $request->title, $request->author);
                if (!empty($found['series'])) {
                    return ['series' => (string) $found['series'], 'series_index' => $found['series_index'] ?? null];
                }
            } catch (\Throwable) {
                // No series: the import keeps the ePub's own, the nightly run may find one.
            }
        }

        return [];
    }

    private static function baseDetails(NovelRequest $request): array
    {
        $match = (array) ($request->candidateList()[0] ?? []);
        if ($match !== [] && DuplicateSeries::normalize((string) ($match['title'] ?? '')) === DuplicateSeries::normalize((string) $request->title)) {
            return [
                'title' => (string) $match['title'],
                'author' => implode(', ', (array) ($match['authors'] ?? [])) ?: (string) $request->author,
                'description' => (string) (($match['description'] ?? null) ?: $request->description),
                'genres' => array_values((array) ($match['genres'] ?? [])),
                'cover_url' => ($match['cover_url'] ?? null) ?: null,
                'file_cover' => empty($match['cover_url']),
            ];
        }

        return [
            'title' => (string) $request->title,
            'author' => (string) $request->author,
            'description' => (string) $request->description,
            'genres' => [],
            'cover_url' => null,
            'file_cover' => true,
        ];
    }

    /** The library has the book: the request is closed as it (the reader can read it), the download dropped. */
    private function alreadyInLibrary(DownloadGrab $grab, NovelRequest $request, User $owner, Novel $existing, string $path): string
    {
        $this->uploads->useExisting($request, $owner, $existing);
        $this->done($grab, DownloadGrab::IMPORTED_BOOK, (int) $existing->id, $path);
        DownloadEvent::log(DownloadEvent::IMPORTED, $grab->title, "“{$existing->name}” was already in the library: the request is marked ready.", $grab);
        $this->alert->send("Already in the library: {$existing->name}",
            "\u{201C}{$existing->name}\u{201D} was already in the library, so the download was not needed: the request is marked ready.",
            RequestNotifier::adminBase() . route('novels.show', $existing->id, false),
            self::bookMail($existing, [
                'heading' => 'Already in the library',
                'intro' => "\u{201C}{$existing->name}\u{201D} was already in the library, so the download was not needed. The request is marked ready and the reader can read it now.",
                'chip' => 'Already here',
                'button' => 'Open the book',
            ]));

        return DownloadGrab::STATUS_IMPORTED;
    }

    /** The owner's email about a library book: its cover, title and author on the card, the Downloads link beside the button. */
    private static function bookMail(Novel $novel, array $details): array
    {
        return $details + [
            'eyebrow' => 'Downloads',
            'cover' => AdminAlertMail::coverOf($novel),
            'title' => (string) $novel->name,
            'meta' => implode(' · ', array_filter(['Book', (string) $novel->author])),
            'secondary' => ['text' => 'Open Downloads', 'url' => RequestNotifier::adminBase() . route('downloads.index', [], false)],
        ];
    }

    /** Imported as $type #$id; a usenet download leaves the inbox ($path null: already handled). */
    private function done(DownloadGrab $grab, string $type, int $id, ?string $path): void
    {
        $grab->forceFill([
            'status' => DownloadGrab::STATUS_IMPORTED,
            'imported_type' => $type,
            'imported_id' => $id,
            'imported_at' => now(),
            'error' => null,
        ])->save();
        if ($path !== null && !$grab->isTorrent()) {
            self::remove($path);
        }
    }

    /** The download's ePub: the entry itself, else its largest .epub (not a sample); null when it has none. */
    public static function findEpub(string $path): ?string
    {
        if (is_file($path)) {
            return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'epub' ? $path : null;
        }
        $best = null;
        $bestSize = -1;
        foreach (self::files($path) as $file) {
            $base = $file->getFilename();
            if (strtolower($file->getExtension()) !== 'epub' || str_starts_with($base, '.') || preg_match('/\bsample\b/i', str_replace(['.', '_', '-'], ' ', $base))) {
                continue;
            }
            if ($file->getSize() > $bestSize) {
                $best = $file->getPathname();
                $bestSize = $file->getSize();
            }
        }

        return $best;
    }

    /** "a.pdf, b.mobi" (the first few files), to say what the download holds instead. */
    private static function listing(string $path): string
    {
        if (is_file($path)) {
            return basename($path);
        }
        $names = [];
        foreach (self::files($path) as $file) {
            if (!str_starts_with($file->getFilename(), '.')) {
                $names[] = $file->getFilename();
            }
            if (count($names) >= 5) {
                break;
            }
        }

        return implode(', ', $names);
    }

    /** @return iterable<\SplFileInfo> */
    private static function files(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return [];
        }
        try {
            $out = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    $out[] = $file;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning("Downloads: cannot read {$dir}: " . $e->getMessage());

            return [];
        }
    }

    /** Remove a processed usenet download from the books inbox (only ever inside it). */
    private static function remove(string $path): void
    {
        $inbox = realpath(Downloads::booksInbox());
        $real = realpath($path);
        if ($inbox === false || $real === false || dirname($real) !== $inbox) {
            return;
        }
        is_dir($real) ? File::deleteDirectory($real) : @unlink($real);
    }

    private function owner(DownloadGrab $grab): User
    {
        return User::find($grab->user_id) ?? User::findOrFail(CurrentReader::ownerId());
    }

    private function category(): string
    {
        return (string) config('novarr.nzbget.category_books', 'Novarr-Books');
    }
}
