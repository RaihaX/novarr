<?php

namespace App;

use App\Audiobooks\AudiobookLibrary;
use App\Services\CoverThumbnails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * An audiobook: its files (parts) under config('novarr.audiobooks.path')/
 * {dir}, its chapters (whole-book positions) and, when matched, the book it
 * is the audiobook of (novel_id; App\Audiobooks\AudiobookMatcher). A
 * standalone one (no book) still lists and plays, with the title, author
 * and cover from its own tags.
 *
 * Imported by App\Audiobooks\AudiobookImporter from the drop-in folder.
 * The listening state (ListeningProgress, bookmarks) is per reader.
 *
 * A soft delete hides it everywhere and keeps its files and rows (a
 * restore brings it back); a hard delete removes its rows and the readers'
 * progress and bookmarks, not its files (they are the owner's: the folder
 * is left for them to remove).
 */
class Audiobook extends Model
{
    use SoftDeletes;

    public const STATUS_READY = 'ready';

    public const STATUS_UNMATCHED = 'unmatched';

    protected $fillable = [
        'novel_id', 'title', 'author', 'narrator', 'description', 'year', 'duration_ms', 'size_bytes',
        'cover_path', 'dir', 'status', 'match_note', 'source_name', 'fingerprint', 'added_at',
    ];

    protected $casts = [
        'novel_id' => 'integer',
        'year' => 'integer',
        'duration_ms' => 'integer',
        'size_bytes' => 'integer',
        'added_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::forceDeleted(function (self $audiobook) {
            AudiobookPart::where('audiobook_id', $audiobook->id)->delete();
            AudiobookChapter::where('audiobook_id', $audiobook->id)->delete();
            AudiobookBookmark::where('audiobook_id', $audiobook->id)->delete();
            DB::table('audiobook_progress')->where('audiobook_id', $audiobook->id)->delete();
        });
        // The books' "has an audiobook" map (novel payloads) follows every change.
        static::saved(function (self $audiobook) {
            AudiobookLibrary::forgetNovelMap();
            // The book has its audiobook: no longer wanted (App\Downloads\WantedList).
            if ($audiobook->novel_id) {
                \App\Downloads\WantedList::audiobookArrived((int) $audiobook->novel_id);
            }
        });
        static::deleted(fn() => AudiobookLibrary::forgetNovelMap());
        static::restored(fn() => AudiobookLibrary::forgetNovelMap());
    }

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(AudiobookPart::class)->orderBy('idx');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(AudiobookChapter::class)->orderBy('idx');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /** The audiobook's folder on disk (absolute). */
    public function directory(): string
    {
        return rtrim(AudiobookLibrary::root(), '/') . '/' . trim((string) $this->dir, '/');
    }

    /**
     * Its cover for display: its own (thumbnail when there is one), else the
     * matched book's (when `novel.file` is loaded), else null. Never
     * lazy-loads (lists render many at once).
     */
    public function coverUrl(string $size = 'md'): ?string
    {
        if ($this->cover_path) {
            $thumbnails = app(CoverThumbnails::class);
            $path = $thumbnails->exists($this->cover_path, $size) ? $thumbnails->path($this->cover_path, $size) : $this->cover_path;

            // Built like a book cover's URL ("/storage/…", on whatever host the page is on).
            return Storage::url('public/' . $path);
        }
        if ($this->relationLoaded('novel') && $this->novel !== null) {
            return $this->novel->coverUrl($size);
        }

        return null;
    }

    /** The title shown: the matched book's name, else its own. */
    public function displayTitle(): string
    {
        return $this->relationLoaded('novel') && $this->novel !== null ? (string) $this->novel->name : (string) $this->title;
    }

    /** The author shown: the matched book's, else its own (tags). */
    public function displayAuthor(): ?string
    {
        $author = $this->relationLoaded('novel') && $this->novel !== null && $this->novel->author ? $this->novel->author : $this->author;

        return $author !== null && trim((string) $author) !== '' ? (string) $author : null;
    }
}
