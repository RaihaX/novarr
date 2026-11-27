<?php

namespace App\Audiobooks;

use App\Audiobook;
use App\Books\BookLibrary;
use App\Novel;
use App\Services\DuplicateSeries;
use Illuminate\Support\Str;

/**
 * Which book an audiobook is the audiobook of.
 *
 * Automatic (autoMatch(), right after an import): the title (album tag,
 * else title tag, else the file / folder name) cleaned of what audiobook
 * releases add to it ("(Unabridged)", "[…]", "- Audiobook", "read by …"),
 * and the author (album_artist / artist tags), looked up with the Books
 * library's own rule (App\Books\BookLibrary::existing(): the same
 * normalised title among the books, incl. books moved to Novels, sharing
 * an author's surname). A folder named "Author - Title" is tried both ways
 * round when the tags say nothing.
 *
 * Linked automatically only when it is confident: the title matches AND
 * both sides name an author that agrees. Otherwise the audiobook is
 * `unmatched` with a note saying why, for the owner to decide on the
 * Audiobooks page (match()): a book, or standalone (no book).
 *
 * A book has at most one audiobook (v1): a second one for a book already
 * linked stays unmatched ("this book already has an audiobook").
 */
class AudiobookMatcher
{
    public const NOTE_ALREADY_LINKED = 'this book already has an audiobook';

    public const NOTE_NO_BOOK = 'no book in the library has this title and author';

    public const NOTE_NO_AUTHOR = 'a book has this title, but the author could not be compared';

    /**
     * Link $audiobook to its book when confident, else mark it unmatched
     * with the reason. Saves it.
     *
     * @param  array{title?: ?string, author?: ?string, source_name?: ?string}  $hints  the import's raw title / author
     */
    public function autoMatch(Audiobook $audiobook, array $hints = []): Audiobook
    {
        [$book, $note] = $this->find($audiobook, $hints);

        if ($book !== null) {
            $audiobook->forceFill(['novel_id' => (int) $book->id, 'status' => Audiobook::STATUS_READY, 'match_note' => null]);
        } else {
            $audiobook->forceFill(['novel_id' => null, 'status' => Audiobook::STATUS_UNMATCHED, 'match_note' => $note]);
        }
        $audiobook->save();

        return $audiobook;
    }

    /**
     * The confident book for $audiobook, or [null, why not].
     *
     * @return array{0: ?Novel, 1: ?string}
     */
    public function find(Audiobook $audiobook, array $hints = []): array
    {
        $note = self::NOTE_NO_BOOK;
        foreach ($this->candidates($audiobook, $hints) as [$title, $author]) {
            $book = BookLibrary::existing($title, $author);
            if ($book === null) {
                continue;
            }
            if (trim((string) $author) === '' || trim((string) $book->author) === '') {
                $note = self::NOTE_NO_AUTHOR;

                continue;
            }
            if (Audiobook::where('novel_id', $book->id)->whereKeyNot($audiobook->id ?? 0)->exists()) {
                $note = self::NOTE_ALREADY_LINKED;

                continue;
            }

            return [$book, null];
        }

        return [null, $note];
    }

    /**
     * The (title, author) pairs tried, best first: the cleaned title with
     * the tags' author, then an "Author - Title" name read both ways.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    public function candidates(Audiobook $audiobook, array $hints = []): array
    {
        $author = $hints['author'] ?? $audiobook->author;
        $titles = array_values(array_unique(array_filter([
            static::cleanTitle((string) ($hints['title'] ?? $audiobook->title)),
            static::cleanTitle((string) $audiobook->title),
        ])));

        $out = [];
        foreach ($titles as $title) {
            $out[] = [$title, $author];
        }

        // "Brandon Sanderson - The Final Empire": no author tag, the name says it.
        foreach (array_filter([$hints['source_name'] ?? null, $audiobook->source_name, $audiobook->title]) as $name) {
            $name = static::cleanTitle(pathinfo((string) $name, PATHINFO_EXTENSION) !== '' && AudiobookLibrary::isAudio((string) $name)
                ? pathinfo((string) $name, PATHINFO_FILENAME)
                : (string) $name);
            $pieces = preg_split('/\s+[-–—]\s+/u', $name) ?: [];
            if (count($pieces) === 2) {
                [$a, $b] = array_map('trim', $pieces);
                $out[] = [$b, $a];
                $out[] = [$a, $b];
            }
        }

        $seen = [];

        return array_values(array_filter($out, function ($pair) use (&$seen) {
            $key = DuplicateSeries::normalize($pair[0]) . '|' . mb_strtolower((string) $pair[1]);
            if ($pair[0] === '' || isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
    }

    /**
     * Link $audiobook to the book $novelId, or leave it standalone (null).
     * Either way it is the owner's decision: status ready.
     *
     * @return ?string  null when done, else why not (plain words)
     */
    public function match(Audiobook $audiobook, ?int $novelId): ?string
    {
        if ($novelId === null) {
            $audiobook->forceFill(['novel_id' => null, 'status' => Audiobook::STATUS_READY, 'match_note' => null])->save();

            return null;
        }

        $book = Novel::find($novelId);
        if ($book === null || !$book->hasLibraryFile()) {
            return 'That is not a book in the library.';
        }
        $other = Audiobook::withTrashed()->where('novel_id', $novelId)->whereKeyNot($audiobook->id)->first();
        if ($other !== null) {
            return $other->trashed()
                ? 'That book is linked to a removed audiobook (#' . $other->id . ').'
                : 'That book already has an audiobook (“' . $other->title . '”).';
        }

        $audiobook->forceFill(['novel_id' => (int) $book->id, 'status' => Audiobook::STATUS_READY, 'match_note' => null])->save();

        return null;
    }

    /**
     * A title as audiobook releases write it, cleaned for matching:
     * "Mistborn [Dramatized] (Unabridged) - Audiobook, read by Michael
     * Kramer" → "Mistborn". Underscores read as spaces.
     */
    public static function cleanTitle(string $title): string
    {
        $t = str_replace('_', ' ', $title);
        // […] and {…}: release groups, formats, years, "[Dramatized]".
        $t = preg_replace('/\[[^\]]*\]|\{[^}]*\}/u', ' ', $t) ?? $t;
        // (Unabridged), (Abridged), (Audiobook), (Audio Book), (MP3), (Retail).
        $t = preg_replace('/\((?:un)?abridged[^)]*\)|\(\s*audio\s*-?\s*book[^)]*\)|\((?:mp3|m4b|aac|retail|audible)[^)]*\)/iu', ' ', $t) ?? $t;
        // "…, read by X", "- narrated by X", "(read by X)".
        $t = preg_replace('/[\s,(\-–—:]*\b(?:read|narrated|performed)\s+by\b.*$/iu', '', $t) ?? $t;
        // "- Audiobook", ": An Audiobook …", "Audiobook" as the last word
        // (not "The Audiobook Murders").
        $t = preg_replace('/\s*[,\-–—:]\s*(?:an?\s+)?audio\s*-?\s*book\b.*$|\s+audio\s*-?\s*book\s*$/iu', '', $t) ?? $t;
        // A bare "Unabridged" left at the end.
        $t = preg_replace('/[\s,\-–—:]*\b(?:un)?abridged\b\s*$/iu', '', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t, " \t\n\r\0\x0B-–—:,.");
    }

    /**
     * Books for the owner's match search: title, author or series like $q
     * (books and books moved to Novels), up to $limit, best first: titles
     * that start with it, then the rest by title.
     *
     * @return \Illuminate\Support\Collection<int, Novel>
     */
    public static function searchBooks(string $q, int $limit = 20)
    {
        $q = trim($q);
        if ($q === '') {
            return collect();
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        return Novel::with('file')
            ->where(fn($w) => $w->where('medium', Novel::MEDIUM_BOOK)->orWhere('translator_url', 'like', BookLibrary::URL_PREFIX . '%'))
            ->where(fn($w) => $w->where('name', 'like', $like)->orWhere('author', 'like', $like)->orWhere('series', 'like', $like))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'author', 'medium', 'translator_url', 'series', 'series_index'])
            ->sortBy(fn(Novel $n) => (Str::startsWith(mb_strtolower($n->name), mb_strtolower($q)) ? '0' : '1') . mb_strtolower($n->name))
            ->take($limit)
            ->values();
    }
}
