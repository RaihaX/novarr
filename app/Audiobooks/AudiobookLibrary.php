<?php

namespace App\Audiobooks;

use App\Audiobook;
use Illuminate\Support\Facades\Cache;

/**
 * Where audiobooks live and what counts as one: the library root and the
 * drop-in folder (config novarr.audiobooks), the formats streamed as they
 * are, and the books' "has an audiobook" map that every novel payload reads
 * (`audiobook` on the API's novel summaries).
 */
final class AudiobookLibrary
{
    /** Extension => the Content-Type it is streamed with (no transcoding in v1). */
    public const FORMATS = [
        'm4b' => 'audio/mp4',
        'm4a' => 'audio/mp4',
        'mp3' => 'audio/mpeg',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'flac' => 'audio/flac',
    ];

    /** Pictures in an audiobook's folder taken as its cover (in this order), when the files carry none. */
    public const COVER_NAMES = ['cover', 'folder', 'front', 'albumart', 'albumartlarge'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** The cache key of novelMap(); dropped by every App\Audiobook change. */
    private const NOVEL_MAP_KEY = 'audiobooks.novel_map.v1';

    public static function root(): string
    {
        return rtrim((string) config('novarr.audiobooks.path', storage_path('app/audiobooks')), '/');
    }

    public static function inbox(): string
    {
        return rtrim((string) config('novarr.audiobooks.inbox', storage_path('app/audiobooks-inbox')), '/');
    }

    /** The inbox's folder for entries that could not be imported. */
    public static function failedDir(): string
    {
        return self::inbox() . '/' . AudiobookInbox::FAILED_DIR;
    }

    public static function isAudio(string $name): bool
    {
        return isset(self::FORMATS[strtolower(pathinfo($name, PATHINFO_EXTENSION))]);
    }

    public static function mimeFor(string $name): string
    {
        return self::FORMATS[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /**
     * Every book's audiobook: novel_id => {id, duration_ms} (soft-deleted
     * audiobooks left out). Cached until an audiobook changes, so a novel
     * listing pays nothing for it once warm (one statement cold).
     *
     * @return array<int, array{id: int, duration_ms: int}>
     */
    public static function novelMap(): array
    {
        return Cache::rememberForever(self::NOVEL_MAP_KEY, function () {
            $map = [];
            foreach (Audiobook::query()->whereNotNull('novel_id')->get(['id', 'novel_id', 'duration_ms']) as $row) {
                $map[(int) $row->novel_id] = ['id' => (int) $row->id, 'duration_ms' => (int) $row->duration_ms];
            }

            return $map;
        });
    }

    public static function forgetNovelMap(): void
    {
        Cache::forget(self::NOVEL_MAP_KEY);
    }

    /** The audiobook of a book (not soft-deleted), or null. */
    public static function forNovel(int $novelId): ?Audiobook
    {
        return Audiobook::where('novel_id', $novelId)->first();
    }

    /** "41234000" ms → "11 h 27 min" (under an hour: "27 min"; under a minute: "45 s"). */
    public static function humanDuration(int $ms): string
    {
        $seconds = intdiv(max(0, $ms), 1000);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours > 0) {
            return $minutes > 0 ? "{$hours} h {$minutes} min" : "{$hours} h";
        }
        if ($minutes > 0) {
            return "{$minutes} min";
        }

        return ($seconds % 60) . ' s';
    }
}
