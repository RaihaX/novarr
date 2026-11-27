<?php

namespace App\Sources;

use App\Comics\ComicLibrary;
use App\Novel;

/**
 * Comics & graphic novels (medium 'comic', App\Comics). Nothing is fetched
 * from a site: the issues and their pages came from the comics inbox, and a
 * comic series is complete on arrival like a book, so the TOC and chapter
 * sweeps never ask this source for anything. `novel:metadata` gets nothing
 * from it either: a comic's details are filled by App\Comics\ComicMetadata
 * (Comic Vine, ComicInfo.xml, the book catalogues).
 *
 * It claims only comic:// URLs (and rows of medium 'comic'), so it never
 * takes a web address from another source, and it is never on Discover.
 */
class ComicSource extends AbstractSource
{
    public function name(): string
    {
        return 'comic';
    }

    public function medium(): string
    {
        return Novel::MEDIUM_COMIC;
    }

    public function matches(Novel $novel): bool
    {
        return $novel->medium === Novel::MEDIUM_COMIC || $this->matchesUrl((string) ($novel->translator_url ?? ''));
    }

    public function matchesUrl(string $url): bool
    {
        return ComicLibrary::isComicUrl($url);
    }

    public function discoverHost(): string
    {
        return 'Comics inbox';
    }

    /** A comic's issues come in through the inbox, never from a table of contents. */
    public function tableOfContents(Novel $novel): array
    {
        return [];
    }

    public function metadata(Novel $novel): array
    {
        return [
            'description' => $novel->description,
            'author' => $novel->author,
            'no_of_chapters' => $novel->chapters()->count(),
            'genres' => [],
            'cover_candidates' => [],
        ];
    }

    public function downloadLane(Novel $novel): ?string
    {
        return 'comics';
    }
}
