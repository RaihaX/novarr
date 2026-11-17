<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One downloaded page image of a manga chapter. The file lives on the
 * `manga` disk (config filesystems.disks.manga) at
 * {novel_id}/{chapter_id}/{page, 3 digits}.{ext}; `path` is relative to that
 * disk and only ever written by App\Scraping\PageDownloader, so serving a
 * page never builds a path from request input.
 */
class ChapterPage extends Model
{
    public const DISK = 'manga';

    protected $table = 'chapter_pages';

    protected $fillable = ['novel_chapter_id', 'page', 'path', 'mime', 'width', 'height', 'bytes'];

    protected $casts = [
        'novel_chapter_id' => 'integer',
        'page' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'bytes' => 'integer',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(NovelChapter::class, 'novel_chapter_id');
    }

    public static function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /** The directory of one chapter's pages on the manga disk. */
    public static function chapterDirectory(int $novelId, int $chapterId): string
    {
        return "{$novelId}/{$chapterId}";
    }

    /** A page's file name: "007.webp". */
    public static function fileName(int $page, string $extension): string
    {
        return str_pad((string) $page, 3, '0', STR_PAD_LEFT) . '.' . $extension;
    }

    /**
     * Remove a chapter's page rows and files (its whole directory). Used when
     * a manga chapter is queued for re-download and when a novel is hard
     * deleted. Returns the number of rows removed. Never throws on a missing
     * directory.
     */
    public static function purgeChapter(NovelChapter $chapter): int
    {
        $removed = self::where('novel_chapter_id', $chapter->id)->delete();

        try {
            self::disk()->deleteDirectory(self::chapterDirectory((int) $chapter->novel_id, (int) $chapter->id));
        } catch (\Throwable $e) {
            Log::warning("ChapterPage: could not delete the pages of chapter {$chapter->id}: " . $e->getMessage());
        }

        return $removed;
    }

    /** Remove every page row and file of a novel. */
    public static function purgeNovel(int $novelId): int
    {
        $chapterIds = NovelChapter::withTrashed()->where('novel_id', $novelId)->pluck('id')->all();
        $removed = 0;
        foreach (array_chunk($chapterIds, 500) as $chunk) {
            $removed += self::whereIn('novel_chapter_id', $chunk)->delete();
        }

        try {
            self::disk()->deleteDirectory((string) $novelId);
        } catch (\Throwable $e) {
            Log::warning("ChapterPage: could not delete the pages of novel {$novelId}: " . $e->getMessage());
        }

        return $removed;
    }
}
