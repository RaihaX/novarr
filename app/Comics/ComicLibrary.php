<?php

namespace App\Comics;

use App\Novel;

/**
 * Where comics live and what counts as one (medium 'comic').
 *
 * A comic series is a Novel of medium 'comic' (translator_url
 * comic://library/{slug}, never scraped: complete on arrival like a book);
 * each issue or volume is a NovelChapter whose pages are ChapterPage files
 * on the manga disk ({novel id}/{chapter id}/001.jpg …), exactly as a
 * downloaded manga chapter's: the manga reader, the pages API and the CBZ
 * endpoint read both.
 *
 * Files in: the comics inbox (config novarr.comics.inbox, App\Comics\ComicInbox).
 */
final class ComicLibrary
{
    /** A comic series' identity: comic://library/{slug}-{random}. */
    public const URL_PREFIX = 'comic://library/';

    /** Files that are one issue / volume each. */
    public const EXTENSIONS = ['cbz', 'cbr', 'cb7', 'cbt', 'pdf'];

    /** Page images kept from an archive or folder. */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    /** Names inside archives / folders that are never pages. */
    public const IGNORED_NAMES = ['thumbs.db', 'desktop.ini', '.ds_store'];

    public static function inbox(): string
    {
        return rtrim((string) (config('novarr.comics.inbox') ?: storage_path('app/comics-inbox')), '/');
    }

    public static function failedDir(): string
    {
        return self::inbox() . '/' . ComicInbox::FAILED_DIR;
    }

    public static function isComicFile(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    public static function isImage(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Is this path (relative, inside an archive or folder) one to skip:
     * macOS resource forks (__MACOSX/, "._x.jpg"), hidden files, thumbnails?
     */
    public static function ignored(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);
        foreach (explode('/', $relative) as $part) {
            if ($part === '__MACOSX' || ($part !== '' && $part[0] === '.')) {
                return true;
            }
        }

        return in_array(strtolower(basename($relative)), self::IGNORED_NAMES, true);
    }

    public static function isComicUrl(?string $url): bool
    {
        return str_starts_with(trim((string) $url), self::URL_PREFIX);
    }

    /** A new series' identity URL. */
    public static function newUrl(string $title): string
    {
        $slug = \Illuminate\Support\Str::slug($title) ?: 'comic';

        return self::URL_PREFIX . mb_substr($slug, 0, 80) . '-' . bin2hex(random_bytes(4));
    }

    /** Comic series of the library, soft-deleted ones left out. */
    public static function series()
    {
        return Novel::query()->where('medium', Novel::MEDIUM_COMIC);
    }

    /** "Saga (2012)", "Batman" — a series' name with its year, for messages. */
    public static function label(Novel $novel): string
    {
        return $novel->name . ($novel->start_year ? ' (' . $novel->start_year . ')' : '');
    }
}
