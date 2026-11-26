<?php

namespace App\Books;

use App\Http\Helpers\CacheHelper;
use App\Jobs\RunNovelCommand;
use App\Novel;
use App\NovelChapter;
use App\Services\DuplicateSeries;
use App\Tag;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Books uploaded as ePubs: one novels row (medium 'book') per file, its
 * chapters cut from the ePub's contents and stored like downloaded chapter
 * text, so the reader, progress, search, the apps and KOReader read it as
 * they read a web novel.
 *
 * The original file is kept at storage/app/books/{id}.epub: it is what
 * "Download ePub", Send to Kindle and OPDS hand out (GenerateePub::epubPath)
 * and it is never rebuilt. A book arrives complete (status 1), so the TOC
 * and chapter sweeps never look at it.
 *
 * Identity is the file: book://library/{sha1}. The same file twice is the
 * same book; another file with the same title is a duplicate unless forced.
 */
class BookLibrary
{
    public const URL_PREFIX = 'book://library/';

    public const CREATED = 'created';
    public const EXISTING = 'existing';
    public const DUPLICATE = 'duplicate';

    private const LOCK = 'book_import_lock';

    /** At most this many tags from the ePub's own subjects. */
    private const MAX_SUBJECT_TAGS = 8;

    public function __construct(private DuplicateSeries $duplicates)
    {
    }

    /** Where a book's original ePub is kept. */
    public static function path(Novel|int $novel): string
    {
        $id = $novel instanceof Novel ? (int) $novel->id : $novel;

        $root = rtrim((string) (config('novarr.books.path') ?: storage_path('app/books')), '/');

        return "{$root}/{$id}.epub";
    }

    public static function urlFor(string $sha1): string
    {
        return self::URL_PREFIX . $sha1;
    }

    /**
     * Add an ePub to the library.
     *
     * @param  string  $file          the uploaded (temporary) file; it is copied, not moved
     * @param  string  $originalName  the name it was uploaded under (a title when the ePub has none)
     * @param  bool    $lookup        fill gaps from the catalogues afterwards (off when the owner already matched it)
     * @param  array   $details       the owner's choice, over the file's own metadata, each key optional:
     *                                title, author, description (null clears), genres (string[], replaces
     *                                the file's subjects), cover_url (a catalogue cover, over the file's),
     *                                file_cover (false: never the file's own cover)
     * @return array{result: string, novel: Novel}  result: created | existing | duplicate
     *
     * @throws EpubException  the file cannot be read as an ePub or has no text
     */
    public function import(string $file, string $originalName, bool $force = false, bool $lookup = true, array $details = []): array
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

        if (trim((string) ($details['title'] ?? '')) !== '') {
            $metadata['title'] = trim((string) $details['title']);
        }
        if (array_key_exists('author', $details)) {
            $author = trim((string) $details['author']);
            $metadata['authors'] = $author !== '' ? [$author] : [];
        }
        if (array_key_exists('description', $details)) {
            $description = trim((string) $details['description']);
            $metadata['description'] = $description !== '' ? $description : null;
        }

        $title = $metadata['title'] ?: self::titleFromFilename($originalName);
        $url = self::urlFor(sha1_file($file));

        $outcome = Cache::lock(self::LOCK, 120)->block(30, function () use ($file, $url, $title, $metadata, $chapters, $force) {
            $existing = $this->duplicates->find($title, Novel::MEDIUM_BOOK, [], $url);
            if ($existing !== null && $existing['by'] === DuplicateSeries::BY_SOURCE) {
                return ['result' => self::EXISTING, 'novel' => $existing['novel']];
            }
            if ($existing !== null && !$force) {
                return ['result' => self::DUPLICATE, 'novel' => $existing['novel']];
            }

            return ['result' => self::CREATED, 'novel' => $this->create($file, $url, $title, $metadata, $chapters)];
        });

        if ($outcome['result'] !== self::CREATED) {
            return $outcome;
        }

        /** @var Novel $novel */
        $novel = $outcome['novel'];
        if (array_key_exists('genres', $details)) {
            $this->applyTags($novel, normalizeGenres(array_map('strval', (array) $details['genres'])));
        } else {
            $this->applyTags($novel, self::subjectTags($metadata['subjects']));
        }

        $covered = false;
        if (!empty($details['cover_url']) && ($downloaded = downloadCoverImage((string) $details['cover_url'], $novel->id))) {
            $novel->attachCover($downloaded['filename'], $downloaded['basename']);
            $covered = true;
        }
        if (!$covered && $cover !== null && ($details['file_cover'] ?? true)) {
            $this->attachCover($novel, $cover);
        }
        CacheHelper::clearNovelCache((int) $novel->id);

        Log::info('Book added', ['novel' => $novel->id, 'title' => $novel->name, 'chapters' => count($chapters)]);

        if ($lookup) {
            $this->queueLookup((int) $novel->id);
        }

        return $outcome;
    }

    /**
     * The row, its chapters and the stored file, all or nothing: a failure
     * part way leaves no half-imported book behind.
     */
    private function create(string $file, string $url, string $title, array $metadata, array $chapters): Novel
    {
        $novel = DB::transaction(function () use ($url, $title, $metadata, $chapters) {
            $novel = new Novel();
            $novel->name = mb_substr($title, 0, 250);
            $novel->translator_url = $url;
            $novel->medium = Novel::MEDIUM_BOOK;
            $novel->group_id = 0;
            $novel->author = $metadata['authors'] !== [] ? mb_substr(implode(', ', $metadata['authors']), 0, 250) : null;
            $novel->description = $metadata['description'];
            $novel->no_of_chapters = count($chapters);
            $novel->origin_language = $metadata['language'];
            // Complete on arrival: nothing will ever be added to it.
            $novel->status = 1;
            $novel->completed_at = now();
            $novel->save();

            foreach (array_values($chapters) as $index => $chapter) {
                $number = $index + 1;
                $row = new NovelChapter([
                    'novel_id' => $novel->id,
                    'chapter' => $number,
                    'number' => $number,
                    'sort_key' => $number,
                    // No volume: a book is one work (the chapter list and reader show no "Book 1").
                    'book' => 0,
                    'label' => mb_substr($chapter['label'], 0, 250),
                    'title' => mb_substr($chapter['label'], 0, 250),
                    'source_label' => mb_substr($chapter['label'], 0, 250),
                    'url' => $url . '/' . $number,
                ]);
                // Readable (status 1) but never "downloaded": no download_date,
                // so a whole book never shows as new chapters (home strip,
                // Activity, the daily email, the apps' updates, "new since you
                // last read"). The reader hears of it through its request instead.
                $row->status = 1;
                $row->description = $chapter['html'];
                $row->save();
            }

            return $novel;
        });

        $path = self::path($novel);
        try {
            File::ensureDirectoryExists(dirname($path), 0755);
            if (!File::copy($file, $path)) {
                throw new \RuntimeException("Could not store the ePub at {$path}");
            }
            // Its ePub is ready (and never stale: the chapters came from it).
            $novel->forceFill(['epub_generated' => now()])->saveQuietly();
        } catch (\Throwable $e) {
            // No file, no book: take the row and its chapters out again.
            $this->forget($novel);
            throw $e;
        }

        return $novel;
    }

    /** Remove a book entirely (row, chapters and their text, file). */
    public function forget(Novel $novel): void
    {
        DB::transaction(function () use ($novel) {
            $ids = NovelChapter::withTrashed()->where('novel_id', $novel->id)->pluck('id');
            foreach ($ids->chunk(500) as $chunk) {
                \App\ChapterText::whereIn('novel_chapter_id', $chunk)->delete();
                NovelChapter::withTrashed()->whereIn('id', $chunk)->forceDelete();
            }
            $novel->tags()->detach();
            $novel->forceDelete();
        });
        @unlink(self::path($novel));
    }

    /** Fill description, genres and cover from the catalogues, in the background. */
    public function queueLookup(int $novelId): void
    {
        try {
            Bus::dispatch((new RunNovelCommand('novel:metadata', ['novel' => $novelId], uniqid('cmd_', true)))->onQueue('commands'));
        } catch (\Throwable $e) {
            // The sync queue runs it here and rethrows; the book is there either way.
            Log::warning("Book lookup for {$novelId} failed: " . $e->getMessage());
        }
    }

    /** @param string[] $tags */
    private function applyTags(Novel $novel, array $tags): void
    {
        if ($tags === []) {
            return;
        }
        $ids = collect($tags)->map(fn($name) => Tag::firstOrCreate(['name' => $name])->id);
        $novel->tags()->syncWithoutDetaching($ids);
    }

    /**
     * The ePub's subjects as tags, when they read like genres ("Fantasy",
     * "Science fiction") rather than library headings ("England--Fiction").
     *
     * @return string[]
     */
    public static function subjectTags(array $subjects): array
    {
        $tags = [];
        foreach ($subjects as $subject) {
            $subject = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', (string) $subject) ?? '');
            if ($subject === '' || str_contains($subject, '--') || str_contains($subject, ',') || mb_strlen($subject) > 40) {
                continue;
            }
            $tags[] = $subject;
        }

        return array_slice(normalizeGenres($tags), 0, self::MAX_SUBJECT_TAGS);
    }

    /** @param array{bytes: string, type: string, name: string} $cover */
    private function attachCover(Novel $novel, array $cover): void
    {
        $extension = match ($cover['type']) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $name = md5($novel->id . microtime(true)) . '.' . $extension;

        try {
            Storage::disk('public')->put($name, $cover['bytes']);
            $novel->attachCover($name, $cover['name']);
        } catch (\Throwable $e) {
            Log::warning("Book {$novel->id}: the ePub's cover could not be stored: " . $e->getMessage());
        }
    }

    /** "the_left_hand-of darkness (v2).epub" → "The Left Hand of Darkness (v2)". */
    public static function titleFromFilename(string $name): string
    {
        $title = pathinfo($name, PATHINFO_FILENAME);
        $title = trim(preg_replace('/[_\s]+/', ' ', str_replace('-', ' ', $title)) ?? '');

        return $title !== '' ? \Illuminate\Support\Str::title($title) : 'Untitled book';
    }
}
