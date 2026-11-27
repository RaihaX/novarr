<?php

namespace App\Http\Helpers;

use App\Novel;
use Illuminate\Http\Request;

/**
 * The web app's two libraries — Novels and Manga — share one route
 * (novels.index) and are told apart by ?medium=novel|manga. The medium last
 * chosen is remembered in the session (like novels_sort / library_view), so
 * a plain /novels or /novels/discover opens the same library again.
 *
 * Pages that belong to one medium (Library, Discover, manual add, a series
 * page) mark it on the current request (share()); the layout reads it back
 * with current() to pick the active desktop nav item, without a query of its
 * own. A request attribute rather than View::share, so nothing outlives the
 * request (tests and long-running workers reuse the view factory).
 */
class LibraryMedium
{
    public const SESSION_KEY = 'library_medium';

    public const ALL = [Novel::MEDIUM_NOVEL, Novel::MEDIUM_MANGA, Novel::MEDIUM_BOOK];

    public static function isValid(mixed $medium): bool
    {
        return in_array($medium, self::ALL, true);
    }

    /**
     * The medium a request asks for: an explicit valid ?medium= wins and is
     * remembered; anything else (none, the old "all", junk) falls back to the
     * remembered one. Marked on the request for the layout (share()).
     */
    public static function resolve(Request $request): string
    {
        $medium = $request->query('medium');
        if (self::isValid($medium)) {
            session([self::SESSION_KEY => $medium]);
        } else {
            $medium = self::remembered();
        }

        self::share($medium);

        return $medium;
    }

    /** The last library shown in this session; novels by default. */
    public static function remembered(): string
    {
        $medium = session(self::SESSION_KEY);

        return self::isValid($medium) ? $medium : Novel::MEDIUM_NOVEL;
    }

    /** Tell the layout which library this page belongs to. */
    public static function share(?string $medium): void
    {
        request()->attributes->set('navMedium', self::isValid($medium) ? $medium : Novel::MEDIUM_NOVEL);
    }

    /** The medium of the page being rendered: what share() set, else the remembered one. */
    public static function current(): string
    {
        $medium = request()->attributes->get('navMedium');

        return self::isValid($medium) ? $medium : self::remembered();
    }

    /** "novel" / "manga" / "book" — the singular word for one series of the medium. */
    public static function noun(string $medium): string
    {
        return match ($medium) {
            Novel::MEDIUM_MANGA => 'manga',
            Novel::MEDIUM_BOOK => 'book',
            default => 'web novel',
        };
    }

    /** "12 novels", "1 novel", "9 manga" (manga is not pluralised). */
    public static function countNoun(string $medium, int $count): string
    {
        $noun = self::noun($medium);

        return $medium === Novel::MEDIUM_MANGA ? $noun : \Illuminate\Support\Str::plural($noun, $count);
    }

    /** The plural, capitalised library title: "Novels" / "Manga" / "Books". */
    public static function title(string $medium): string
    {
        // Novels are web novels (the Books library holds published ones).
        if ($medium !== Novel::MEDIUM_MANGA && $medium !== Novel::MEDIUM_BOOK) {
            return 'Web Novels';
        }

        return ucfirst(self::plural($medium));
    }

    /** Lower-case plural for running text: "novels" / "manga" / "books". */
    public static function plural(string $medium): string
    {
        return match ($medium) {
            Novel::MEDIUM_MANGA => 'manga',
            Novel::MEDIUM_BOOK => 'books',
            default => 'novels',
        };
    }
}
