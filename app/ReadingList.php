<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A reader's reading list ("Favourites", "To read", …): a named group of
 * series, private to its reader (user_id; with sign-in off the owner, see
 * App\Support\CurrentReader). A series can be in several lists. Members are
 * rows of reading_list_novel (no model: read and written through
 * App\Services\ReadingLists with the query builder).
 *
 * The name is unique per reader on name_key (key()): trimmed, inner
 * whitespace collapsed, lowercased.
 */
class ReadingList extends Model
{
    public const PIVOT = 'reading_list_novel';

    /** Longest name, in characters. */
    public const MAX_NAME = 40;

    /** Lists per reader. */
    public const MAX_LISTS = 30;

    /** Series per list. */
    public const MAX_NOVELS = 2000;

    /** The one-tap starters offered before a reader has any list. */
    public const STARTERS = ['Favourites', 'To read', 'Finished'];

    protected $fillable = ['user_id', 'name', 'name_key'];

    protected $casts = [
        'user_id' => 'integer',
    ];

    protected static function booted(): void
    {
        // Members go with their list (no foreign keys, as chapter_reads).
        static::deleted(function (self $list) {
            DB::table(self::PIVOT)->where('reading_list_id', $list->id)->delete();
        });
    }

    /** "  Summer   reads " → "Summer reads": the name as stored. */
    public static function clean(?string $name): string
    {
        // Control characters become spaces, then runs of whitespace one space.
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', (string) $name);

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** The uniqueness key of a name: clean() and lowercased. */
    public static function key(?string $name): string
    {
        return mb_strtolower(static::clean($name), 'UTF-8');
    }

    /** Every list of a reader and its members, rows only (cleanup). */
    public static function forgetUser(int $userId): void
    {
        $ids = static::where('user_id', $userId)->pluck('id');
        DB::table(self::PIVOT)->whereIn('reading_list_id', $ids)->delete();
        static::whereIn('id', $ids)->delete();
    }

    /** A hard-deleted series leaves every list. */
    public static function forgetNovel(int $novelId): void
    {
        DB::table(self::PIVOT)->where('novel_id', $novelId)->delete();
    }
}
