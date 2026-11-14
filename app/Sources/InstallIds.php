<?php

namespace App\Sources;

/**
 * The one place that reads the install-specific database ids older code
 * hard-coded (config novarr.*_ids; defaults equal the original literals).
 * Comparisons stay loose (in_array without strict, as the original `==`
 * and in_array checks were), so a string "1" from the database still
 * matches.
 */
final class InstallIds
{
    /** Groups whose novels' alternative_url builds the chapter URL. */
    public static function alternativeUrlGroupIds(): array
    {
        return self::ids('alternative_url_group_ids', [1, 3, 6]);
    }

    /** Novels whose alternative_url chapter number keeps "12-5" for 12.5. */
    public static function dottedChapterUrlNovelIds(): array
    {
        return self::ids('dotted_chapter_url_novel_ids', [72]);
    }

    /** Groups whose NovelPing novels use the chapter-list JSON API. */
    public static function novelArrowApiGroupIds(): array
    {
        return self::ids('novelarrow_api_group_ids', [1]);
    }

    /** Webnovel groups whose TOC entries get a unique_id from the URL. */
    public static function webnovelUniqueIdGroupIds(): array
    {
        return self::ids('webnovel_unique_id_group_ids', [6]);
    }

    /** Loose membership test, as the original `==` / in_array() checks. */
    public static function contains(array $ids, $id): bool
    {
        return in_array($id, $ids);
    }

    private static function ids(string $key, array $default): array
    {
        return array_values((array) config("novarr.{$key}", $default));
    }
}
