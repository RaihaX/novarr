<?php

namespace App\Comics;

use App\Books\BookLookup;
use App\Novel;
use App\NovelRequest;
use App\Services\DuplicateSeries;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * A comic series' details, filled where they are missing — never over what
 * is there (the owner's edits, an earlier fill): author, publisher, start
 * year, description, cover (a cover_source 'manual' cover is never
 * replaced; a series with a cover keeps it).
 *
 * In order, each filling only the gaps the one before left:
 *
 *   1. the file's ComicInfo.xml (writers, publisher, summary, year);
 *   2. Comic Vine (with a key, App\Comics\ComicVine): the series of the
 *      same name (and start year when known) — description, publisher,
 *      start year, cover; its id is kept (comicvine_id). Asked once per
 *      series (comicvine_checked_at) unless forced;
 *   3. without a Comic Vine match: the book catalogues (App\Books\BookLookup)
 *      for a graphic novel — results that are comics / graphic novels
 *      ("Comics & Graphic Novels") of the same title;
 *   4. the reader request's cover / description (what they asked for);
 *   5. the first page of its first issue as the cover.
 */
class ComicMetadata
{
    public function __construct(private ComicVine $comicVine)
    {
    }

    public function fill(Novel $novel, ?array $info = null, ?string $firstPage = null, bool $isNew = false, ?NovelRequest $request = null, bool $force = false): array
    {
        $done = [];
        $set = function (string $field, mixed $value) use ($novel, &$done) {
            if ($value === null || $value === '' || $value === []) {
                return;
            }
            if ($novel->{$field} === null || $novel->{$field} === '') {
                $novel->{$field} = is_string($value) ? mb_substr($value, 0, $field === 'description' ? 65000 : 250) : $value;
                $done[$field] = true;
            }
        };

        // 1. ComicInfo.
        if ($info !== null) {
            $set('author', implode(', ', array_slice((array) ($info['writers'] ?? []), 0, 4)) ?: null);
            $set('publisher', $info['publisher'] ?? null);
            $set('description', $info['summary'] ?? null);
            $set('start_year', $info['start_year'] ?? null);
        }

        // 2. Comic Vine.
        $coverUrl = null;
        $matched = false;
        if (ComicVine::enabled() && ($force || $novel->comicvine_checked_at === null)) {
            $volume = $novel->comicvine_id ? $this->comicVine->volume((int) $novel->comicvine_id) : $this->match($novel);
            if ($volume !== null) {
                $matched = true;
                $novel->comicvine_id = (string) $volume['id'];
                $set('description', $volume['description']);
                $set('publisher', $volume['publisher']);
                $set('start_year', $volume['start_year']);
                $set('author', $volume['people'] !== [] ? implode(', ', array_slice($volume['people'], 0, 3)) : null);
                $coverUrl = $volume['cover_url'];
                $done['comicvine'] = true;
            }
            $novel->comicvine_checked_at = now();
        }

        // 3. The book catalogues, for a graphic novel.
        if (!$matched && ($novel->description === null || $novel->description === '' || !$this->hasCover($novel)) && BookLookup::sources() !== []) {
            $book = $this->fromCatalogues($novel);
            if ($book !== null) {
                $set('description', $book['description'] ?? null);
                $set('author', implode(', ', array_slice((array) ($book['authors'] ?? []), 0, 3)) ?: null);
                $set('publisher', $book['publisher'] ?? null);
                $coverUrl ??= $book['cover_url'] ?? null;
                $done['catalogue'] = true;
            }
        }

        // 4. What the reader asked for.
        if ($request !== null) {
            $set('description', $request->description);
            $coverUrl ??= $request->cover_url;
        }
        $novel->save();

        // Cover: the found one, else the first page.
        if (!$this->hasCover($novel) && $novel->cover_source !== 'manual') {
            if ($coverUrl !== null && $this->storeCoverFromUrl($novel, $coverUrl)) {
                $done['cover'] = 'found';
            } elseif ($firstPage !== null && is_file($firstPage) && $this->storeCoverFromFile($novel, $firstPage)) {
                $done['cover'] = 'first_page';
            }
        }

        return $done;
    }

    /** Comic Vine's series for this one: the same normalised name, the same start year when both are known. */
    public function match(Novel $novel): ?array
    {
        $key = DuplicateSeries::normalize((string) $novel->name);
        $year = $novel->start_year ? (int) $novel->start_year : null;
        $volumes = $this->comicVine->searchVolumes((string) $novel->name, $year);
        $same = array_values(array_filter($volumes, fn($v) => DuplicateSeries::normalize($v['name']) === $key));
        if ($same === []) {
            return null;
        }
        if ($year !== null) {
            foreach ($same as $v) {
                if ($v['start_year'] === $year) {
                    return $v;
                }
            }

            return null;
        }
        // No year: the one with the most issues (the main series, not a one-shot).
        usort($same, fn($a, $b) => (int) $b['issues'] <=> (int) $a['issues']);

        return $same[0];
    }

    /** A catalogue result that is a comic / graphic novel of this title, or null. */
    public function fromCatalogues(Novel $novel): ?array
    {
        try {
            $found = app(BookLookup::class)->candidates(['title' => (string) $novel->name, 'author' => explode(',', (string) $novel->author)[0] ?? null], 8);
        } catch (\Throwable $e) {
            Log::info("Comics: catalogue lookup for {$novel->id} failed: " . $e->getMessage());

            return null;
        }
        $key = DuplicateSeries::normalize((string) $novel->name);
        foreach ($found as $book) {
            if (self::isComicBook($book) && DuplicateSeries::normalize((string) BookLookup::cleanTitle((string) $book['title'])) === $key) {
                return $book;
            }
        }

        return null;
    }

    /** Is a catalogue result a comic / graphic novel (its categories or genres say so)? */
    public static function isComicBook(array $book): bool
    {
        $words = implode(' | ', array_map('strval', array_merge((array) ($book['genres'] ?? []), (array) ($book['categories'] ?? []))));

        return (bool) preg_match('/comic|graphic novel|manga|superhero/i', $words);
    }

    private function hasCover(Novel $novel): bool
    {
        return $novel->file()->exists();
    }

    private function storeCoverFromUrl(Novel $novel, string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        try {
            $response = Http::withUserAgent(ComicVine::USER_AGENT)->timeout(20)->get($url);
        } catch (\Throwable $e) {
            Log::info("Comics: cover {$url} not fetched: " . $e->getMessage());

            return false;
        }
        $bytes = $response->successful() ? $response->body() : '';

        return $bytes !== '' && $this->storeCover($novel, $bytes, 'cover');
    }

    private function storeCoverFromFile(Novel $novel, string $path): bool
    {
        $bytes = (string) @file_get_contents($path);

        return $bytes !== '' && $this->storeCover($novel, $bytes, 'first page');
    }

    private function storeCover(Novel $novel, string $bytes, string $name): bool
    {
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info)) {
            return false;
        }
        $ext = match ($info['mime'] ?? '') {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $file = md5($novel->id . microtime(true) . random_bytes(4)) . '.' . $ext;
        try {
            Storage::disk('public')->put($file, $bytes);
            $novel->attachCover($file, $name);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Comics: cover of {$novel->id} not stored: " . $e->getMessage());

            return false;
        }
    }
}
