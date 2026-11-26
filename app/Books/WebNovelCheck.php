<?php

namespace App\Books;

use App\File;
use App\Http\Helpers\CacheHelper;
use App\Novel;
use App\Scraping\NovelUpdatesMatcher;
use App\Scraping\OriginInference;
use App\Services\CoverThumbnails;
use App\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Books that are really web novels: a translated Chinese / Korean /
 * Japanese web novel or an English web serial, imported from Calibre or
 * uploaded as an ePub, is moved from Books to Novels when NovelUpdates
 * lists it as a web novel (`books:webnovel-check`, nightly).
 *
 * Deciding (evaluate(), network only, no writes). The book's title goes
 * through NovelUpdates' search, scored by NovelUpdatesMatcher exactly as a
 * web novel's lookup is (resolveNovelUpdatesUrl); the series page of a
 * confident hit is then fetched and the book must pass every one of:
 *
 *   1. title   its title alone (no author bonus) scores at or above the
 *              matcher threshold against the page's title and associated
 *              names (a sequel marker on one side only fails it);
 *   2. type    NovelUpdates calls it a "Web Novel": a Light Novel or a
 *              Published Novel listed there is a real book and stays one;
 *   3. author  when both sides name an author, one of the book's authors
 *              is one of NovelUpdates' (authorsMatch(): order, "Last,
 *              First", spacing, accents and small romanisation differences
 *              are forgiven, another name is not); when either side has
 *              none, the normalised title must equal NovelUpdates' title or
 *              one of its associated names exactly;
 *   4. new     no other series in the library is that NovelUpdates series
 *              (same URL) or already in Novels under its title: converting
 *              would make a duplicate, so the book stays a book.
 *
 * Anything else is "not a web novel". False negatives are the price: a
 * real novel must never leave Books. A lookup that did not get an answer
 * (network, Cloudflare, rate limit) is "failed", and the book is tried
 * again on the next run.
 *
 * Converting (convert()). medium becomes 'novel' and converted_from
 * 'book'; the NovelUpdates series is stored as for any web novel
 * (novelupdates_url, novelupdates_match_score, novelupdates_checked_at),
 * and its metadata applied: title, associated names (alternative_titles),
 * author, description, genres (the tags), origin (Novel::applyOrigin, never
 * over a manual one) and cover. The chapters (the ePub's text),
 * translator_url (book://library/{sha1}) and source_ref (calibre:{uuid})
 * are never touched: BookLibrary::path() still finds the file and Calibre
 * sync still knows the book. epub_generated is cleared so the ePub sweep
 * builds one from the chapters under the new title and cover; the
 * original ePub stays where it is. Every replaced value is kept in
 * converted_snapshot.
 *
 * The cover is added as a new File row, not through Novel::attachCover()
 * (which deletes the previous cover): the book's own cover stays on disk
 * so undo() can bring it back.
 *
 * Undo (undo()) puts the snapshot back, removes the NovelUpdates cover and
 * the generated ePub, and makes the row a book again. webnovel_checked_at
 * stays set, so the nightly run never converts it a second time.
 */
class WebNovelCheck
{
    public const CONVERTED = 'converted';
    public const NOT_WEB_NOVEL = 'not_web_novel';
    public const DUPLICATE = 'duplicate';
    public const FAILED = 'failed';

    /** NovelUpdates types (#showtype) that make a book a web novel. */
    public const WEB_TYPES = ['web novel'];

    /** Fuzzy author names: at least this similar (1 − Levenshtein ratio). */
    public const AUTHOR_SIMILARITY = 0.9;

    /** Fields restored by undo(), as they were before the conversion. */
    private const SNAPSHOT_FIELDS = [
        'name', 'author', 'description', 'medium',
        'novelupdates_url', 'novelupdates_match_score', 'novelupdates_checked_at',
        'origin', 'origin_language', 'origin_source', 'origin_type', 'origin_confidence',
        'alternative_titles', 'epub_generated',
    ];

    public function __construct(private CoverThumbnails $thumbnails)
    {
    }

    /**
     * Books not checked yet, oldest first (soft-deleted ones are left alone).
     */
    public static function pending()
    {
        return Novel::books()->whereNull('webnovel_checked_at')->orderBy('id');
    }

    /**
     * Look the book up on NovelUpdates and decide; writes nothing.
     *
     * @return array{result: string, reason: string, url: ?string, title: ?string, score: ?float, metadata: ?array}
     */
    public function evaluate(Novel $book): array
    {
        $name = trim((string) $book->name);
        $author = trim((string) $book->author) ?: null;
        $answer = fn(string $result, string $reason, array $extra = []) => $extra + [
            'result' => $result, 'reason' => $reason, 'url' => null, 'title' => null, 'score' => null, 'metadata' => null,
        ];

        if ($name === '') {
            return $answer(self::NOT_WEB_NOVEL, 'no title');
        }

        $report = null;
        $url = resolveNovelUpdatesUrl($name, $author, $report);
        if (($report['failed'] ?? null) !== null) {
            return $answer(self::FAILED, "NovelUpdates search failed ({$report['failed']})");
        }
        if ($url === null) {
            $best = $report['score'] ?? null;

            return $answer(self::NOT_WEB_NOVEL, $best === null ? 'not on NovelUpdates' : sprintf('no confident NovelUpdates match (best %.3f)', $best), ['score' => $best]);
        }

        $page = $report['metadata'] ?? null;
        if ($page === null) {
            $failure = null;
            $page = fetchNovelUpdatesMetadata($url, $failure);
            if ($failure !== null) {
                return $answer(self::FAILED, "NovelUpdates series page failed ({$failure})", ['url' => $url]);
            }
        }
        $title = trim((string) ($page['title'] ?? ''));
        $associated = array_values(array_filter(array_map('trim', (array) ($page['associated'] ?? [])), 'strlen'));
        if ($title === '' && $associated === []) {
            return $answer(self::NOT_WEB_NOVEL, 'the NovelUpdates series page is empty', ['url' => $url]);
        }

        $found = ['url' => $url, 'title' => $title !== '' ? $title : null, 'metadata' => $page];

        // 1. The title alone, without the matcher's author bonus.
        $score = NovelUpdatesMatcher::score($name, null, ['title' => $title, 'associated' => $associated]);
        $found['score'] = $score;
        if ($score < NovelUpdatesMatcher::threshold()) {
            return $answer(self::NOT_WEB_NOVEL, sprintf('title too different from "%s" (%.3f)', $title, $score), $found);
        }

        // 2. A web novel, not a light / published novel.
        $type = strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($page['type'] ?? '')) ?? ''));
        if (!self::isWebType($type)) {
            return $answer(self::NOT_WEB_NOVEL, 'NovelUpdates lists it as ' . ($type !== '' ? "\"{$page['type']}\"" : 'no type') . ', not a web novel', $found);
        }

        // 3. The author, or failing one, the exact title.
        $theirs = array_values(array_filter(array_map('trim', array_merge(
            (array) ($page['authors'] ?? []),
            [(string) ($page['author'] ?? '')]
        )), 'strlen'));
        if ($author !== null && $theirs !== []) {
            if (!self::authorsMatch($author, $theirs)) {
                return $answer(self::NOT_WEB_NOVEL, sprintf('another author ("%s" on NovelUpdates, "%s" here)', $theirs[0], $author), $found);
            }
        } elseif (!self::exactTitle($name, array_merge([$title], $associated))) {
            return $answer(self::NOT_WEB_NOVEL, 'no author to compare and the title is not exactly NovelUpdates\'', $found);
        }

        // 4. Not already in the library as a web novel.
        if (($other = $this->existingSeries($book, $url, $title)) !== null) {
            return $answer(self::DUPLICATE, "already in Novels as \"{$other->name}\" (#{$other->id})", $found);
        }

        return $answer(self::CONVERTED, 'web novel on NovelUpdates', $found);
    }

    /**
     * Move a book to Novels with the NovelUpdates metadata of evaluate()'s
     * match (its 'url', 'score' and 'metadata').
     */
    public function convert(Novel $book, array $match): Novel
    {
        $page = (array) $match['metadata'];
        $snapshot = ['fields' => [], 'tags' => $book->tags()->pluck('tags.id')->map(fn($id) => (int) $id)->all()];
        foreach (self::SNAPSHOT_FIELDS as $field) {
            $value = $book->getAttributes()[$field] ?? null;
            $snapshot['fields'][$field] = $field === 'alternative_titles' ? $book->alternative_titles : $value;
        }
        $snapshot['converted_at'] = now()->toDateTimeString();

        DB::transaction(function () use ($book, $page, $match, &$snapshot) {
            $title = trim((string) ($page['title'] ?? ''));
            $associated = array_values(array_unique(array_filter(array_map('trim', (array) ($page['associated'] ?? [])), 'strlen')));

            $book->forceFill([
                'medium' => Novel::MEDIUM_NOVEL,
                'converted_from' => Novel::MEDIUM_BOOK,
                'novelupdates_url' => $match['url'],
                'novelupdates_match_score' => $match['score'],
                'novelupdates_checked_at' => now(),
                'webnovel_checked_at' => now(),
                'alternative_titles' => $associated ?: null,
                // Built again from the chapters, under the new title and cover.
                'epub_generated' => null,
            ]);
            if ($title !== '') {
                $book->name = mb_substr($title, 0, 250);
            }
            if (trim((string) ($page['author'] ?? '')) !== '') {
                $book->author = mb_substr(trim((string) $page['author']), 0, 250);
            }
            if (trim(strip_tags((string) ($page['description'] ?? ''))) !== '') {
                $book->description = (string) $page['description'];
            }
            $book->converted_snapshot = $snapshot;
            $book->save();

            if (($origin = OriginInference::fromNovelUpdates($page)) !== null) {
                $book->applyOrigin($origin, 'novelupdates');
            }

            $genres = array_values(array_filter((array) ($page['genres'] ?? [])));
            if ($genres !== []) {
                $book->tags()->sync(collect($genres)->map(fn($g) => Tag::firstOrCreate(['name' => $g])->id)->all());
            }
        });

        // The NovelUpdates cover, beside the book's own (kept for undo).
        $image = trim((string) ($page['image'] ?? ''));
        if ($image !== '' && ($downloaded = downloadCoverImage($image, $book->id))) {
            $file = $book->file()->save(new File([
                'file_name' => $downloaded['basename'],
                'file_path' => 'public/' . CoverThumbnails::relative($downloaded['filename']),
            ]));
            $this->thumbnails->generate($file->file_path);
            $book->setRelation('file', $file);
            $snapshot['added_cover'] = (int) $file->id;
            $book->forceFill(['converted_snapshot' => $snapshot])->saveQuietly();
        }

        CacheHelper::clearNovelCache((int) $book->id);
        Log::info('Book moved to Novels: it is a web novel on NovelUpdates', [
            'novel' => $book->id,
            'was' => $snapshot['fields']['name'],
            'now' => $book->name,
            'novelupdates_url' => $match['url'],
            'score' => $match['score'],
        ]);

        return $book;
    }

    /**
     * Put a converted book back in Books exactly as it was.
     *
     * @throws \RuntimeException  the row was not converted by this check
     */
    public function undo(Novel $novel): Novel
    {
        if (!$novel->wasConvertedFromBook() || !is_array($novel->converted_snapshot)) {
            throw new \RuntimeException("#{$novel->id} \"{$novel->name}\" was not moved from Books by the web-novel check.");
        }
        $snapshot = $novel->converted_snapshot;

        // The ePub built from the chapters under the NovelUpdates title (the
        // original stays at BookLibrary::path()).
        $generated = \App\Console\Commands\GenerateePub::epubPath($novel);
        if (!$novel->isBook() && is_file($generated) && $generated !== BookLibrary::path($novel)) {
            @unlink($generated);
        }

        DB::transaction(function () use ($novel, $snapshot) {
            $fields = (array) ($snapshot['fields'] ?? []);
            $alternative = $fields['alternative_titles'] ?? null;
            unset($fields['alternative_titles']);

            $novel->forceFill($fields);
            $novel->alternative_titles = $alternative;
            $novel->medium = $fields['medium'] ?? Novel::MEDIUM_BOOK;
            $novel->converted_from = null;
            $novel->converted_snapshot = null;
            // Checked: the owner says it is a book, so it is never moved again.
            $novel->webnovel_checked_at ??= now();
            $novel->save();

            $novel->tags()->sync((array) ($snapshot['tags'] ?? []));
        });

        if (!empty($snapshot['added_cover']) && ($file = File::find($snapshot['added_cover'])) !== null) {
            $this->removeCover($file);
        }
        $novel->unsetRelation('file');

        CacheHelper::clearNovelCache((int) $novel->id);
        Log::info('Web-novel conversion undone: back in Books', ['novel' => $novel->id, 'title' => $novel->name]);

        return $novel;
    }

    /**
     * Do two author fields name the same person? $ours is the book's (one
     * string, several authors comma-joined, or "Last, First"); $theirs are
     * NovelUpdates' names. Each name is compared on its letters in order and
     * with its words sorted, so "Er Gen", "Ergen", "Gen Er" and "Gen, Er"
     * agree, and loosely (AUTHOR_SIMILARITY, so "Joan Smith" is not "John
     * Smith") for romanisation variants; anything shorter than three
     * letters never matches loosely, and a name in its own script
     * ("爱潜水的乌贼") only matches exactly.
     *
     * @param string[] $theirs
     */
    public static function authorsMatch(?string $ours, array $theirs): bool
    {
        $mine = self::nameForms((string) $ours);
        if ($mine === []) {
            return false;
        }
        foreach ($theirs as $name) {
            foreach (self::nameForms((string) $name) as $other) {
                foreach ($mine as $form) {
                    if ($form === $other) {
                        return true;
                    }
                    if (str_starts_with($form, 'u:') || str_starts_with($other, 'u:')) {
                        continue; // own-script names: exact only
                    }
                    $length = max(strlen($form), strlen($other));
                    if (min(strlen($form), strlen($other)) >= 3
                        && 1 - levenshtein($form, $other) / $length >= self::AUTHOR_SIMILARITY) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Comparable forms of an author field: for the whole field, each author
     * in it (split on ; & "and", and on commas), "Last, First" turned round,
     * and a name without its bracketed original ("Er Gen (耳根)"): the
     * letters in order and the words sorted, ASCII lowercase, no spaces.
     *
     * @return string[]
     */
    public static function nameForms(string $field): array
    {
        $field = trim(html_entity_decode($field, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($field === '') {
            return [];
        }

        $names = [$field];
        $unbracketed = trim(preg_replace('/[(\[（【][^)\]）】]*[)\]）】]/u', ' ', $field) ?? '');
        if ($unbracketed !== '' && $unbracketed !== $field) {
            $names[] = $unbracketed;
        }
        foreach ($names as $name) {
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s*(?:[;&,\/]|\band\b)\s*/iu', $name) ?: []), 'strlen'));
            $names = array_merge($names, $parts);
            // "Smith, John" is one person turned round.
            $commas = array_values(array_filter(array_map('trim', explode(',', $name)), 'strlen'));
            if (count($commas) === 2) {
                $names[] = $commas[1] . ' ' . $commas[0];
            }
        }

        $forms = [];
        foreach ($names as $name) {
            // A name in its own script ("爱潜水的乌贼") has no ASCII form:
            // compared as written (letters only, lowercase), exactly.
            if (preg_match('/[^\x00-\x7F]/', $name) && Str::ascii($name) !== $name) {
                $raw = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name)) ?? '';
                if ($raw !== '' && !preg_match('/^[a-z0-9]+$/', $raw)) {
                    $forms[] = 'u:' . $raw;
                }
            }
            $words = array_values(array_filter(explode(' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($name))) ?? ''), 'strlen'));
            if ($words === []) {
                continue;
            }
            $forms[] = implode('', $words);
            sort($words);
            $forms[] = implode('', $words);
        }

        return array_values(array_unique(array_filter($forms, 'strlen')));
    }

    /** Is $type (NovelUpdates' #showtype, lowercase) one of WEB_TYPES? */
    public static function isWebType(string $type): bool
    {
        foreach (self::WEB_TYPES as $web) {
            if (str_contains($type, $web)) {
                return true;
            }
        }

        return false;
    }

    /** Is the normalised $title exactly one of $names (normalised)? */
    public static function exactTitle(string $title, array $names): bool
    {
        $ours = NovelUpdatesMatcher::normalizeTitle($title);
        if ($ours === '') {
            return false;
        }
        foreach ($names as $name) {
            if (trim((string) $name) !== '' && NovelUpdatesMatcher::normalizeTitle((string) $name) === $ours) {
                return true;
            }
        }

        return false;
    }

    /** Another series that already is this NovelUpdates series, or a web novel of that title. */
    private function existingSeries(Novel $book, string $url, string $title): ?Novel
    {
        $byUrl = Novel::whereKeyNot($book->id)->whereIn('novelupdates_url', array_unique([$url, rtrim($url, '/'), rtrim($url, '/') . '/']))->first();
        if ($byUrl !== null) {
            return $byUrl;
        }
        $key = \App\Services\DuplicateSeries::normalize($title);
        if ($key === '') {
            return null;
        }
        foreach (Novel::text()->whereKeyNot($book->id)->get(['id', 'name']) as $novel) {
            if (\App\Services\DuplicateSeries::normalize((string) $novel->name) === $key) {
                return $novel;
            }
        }

        return null;
    }

    /** Remove a cover File row with its image and thumbnails, unless another row uses the image. */
    private function removeCover(File $file): void
    {
        $relative = CoverThumbnails::relative((string) $file->file_path);
        $shared = File::whereKeyNot($file->getKey())
            ->whereIn('file_path', [$relative, 'public/' . $relative])
            ->exists();
        if ($relative !== '' && !$shared) {
            $this->thumbnails->delete($file->file_path);
            Storage::disk('public')->delete($relative);
        }
        $file->delete();
    }
}
