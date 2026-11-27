<?php

namespace App\Books;

use App\File;
use App\Http\Helpers\CacheHelper;
use App\Novel;
use App\Scraping\NovelUpdatesMatcher;
use App\Scraping\OriginInference;
use App\Services\CoverThumbnails;
use App\Sources\RoyalRoadSource;
use App\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Books that are really web novels: a translated Chinese / Korean /
 * Japanese web novel, an English web serial (Royal Road, Scribble Hub…) or
 * fan fiction (Archive of Our Own, FanFiction.net, SpaceBattles…), imported
 * from Calibre or uploaded as an ePub, is moved from Books to Novels
 * (`books:webnovel-check`, nightly). Novels is the web-novel library.
 *
 * Deciding (evaluate(), no writes). Three rules, cheapest first; the first
 * that answers wins:
 *
 *   A. evidence     (no network) the book's own ePub says it is web fiction
 *                   (App\Books\WebFictionEvidence: a dc:source on a
 *                   web-fiction host, a FanFicFare uid, World Literature
 *                   Company's storiesonline ids, a web-fiction publisher, a
 *                   FanFicFare / FicHub / WebToEpub download). Converted
 *                   with its own metadata. For a translation host
 *                   (webnovel.com, wuxiaworld…) a NovelUpdates match, when
 *                   the network pass runs and finds one, supplies the
 *                   metadata instead.
 *   B. NovelUpdates the book's title goes through NovelUpdates' search,
 *                   scored by NovelUpdatesMatcher exactly as a web novel's
 *                   lookup is (resolveNovelUpdatesUrl); when the title finds
 *                   nothing, its variants are tried (titleVariants(): no
 *                   "(Light Novel)", no "Vol. 2" / "Book 3", no
 *                   ": subtitle"). The series page of a confident hit is
 *                   then fetched and the book must pass every one of:
 *       1. title   the title searched (the book's or the variant) alone, no
 *                  author bonus, scores at or above the matcher threshold
 *                  against the page's title and associated names (a sequel
 *                  marker on one side only fails it);
 *       2. type    NovelUpdates calls it a "Web Novel" (with any "(KR)",
 *                  "(CN)", "(JP)" suffix): a Light Novel or a Published
 *                  Novel listed there is a real book and stays one;
 *       3. author  when both sides name an author, one of the book's
 *                  authors is one of NovelUpdates' (authorsMatch(): order,
 *                  "Last, First", spacing, accents and small romanisation
 *                  differences are forgiven, another name is not); when
 *                  either side has none, the normalised title searched must
 *                  equal NovelUpdates' title or one of its associated names
 *                  exactly;
 *   C. Royal Road   (network, after NovelUpdates found nothing; off with
 *                   novarr.books.webnovel_check_royalroad) a Royal Road
 *                   search by title: a result whose normalised title is
 *                   exactly the book's and whose fiction page names the
 *                   book's author (authorsOverlap()). A book without an
 *                   author is never matched here.
 *
 * Every rule then needs the book not to be in Novels already: no other
 * series is that NovelUpdates series / that source page, or a web novel of
 * that title (for rules A and C: by the same author when both name one).
 * Converting would make a duplicate, so the book stays a book ("already in
 * Novels").
 *
 * Anything else is "not a web novel". False negatives are the price: a
 * real novel must never leave Books. A lookup that did not get an answer
 * (network, Cloudflare, rate limit, a missing ePub) is "failed", and the
 * book is tried again on the next run. Two markers: webfiction_checked_at
 * (rule A was run) and webnovel_checked_at (rules B and C were run), so a
 * book looked up before rule A existed still gets it.
 *
 * Converting (convert()). medium becomes 'novel' and converted_from
 * 'book'; converted_reason says what moved it ("web fiction from
 * storiesonline.net", "listed on NovelUpdates", "on Royal Road"), shown on
 * the series page; web_source_url keeps where the story was published (the
 * ePub's dc:source, the Royal Road fiction page) because translator_url
 * stays the book:// identity. A NovelUpdates match stores the series as for
 * any web novel (novelupdates_url, novelupdates_match_score,
 * novelupdates_checked_at) and applies its metadata: title, associated
 * names (alternative_titles), author, description, genres (the tags),
 * origin (Novel::applyOrigin, never over a manual one) and cover. Rules A
 * and C keep the book's own title, author, description, tags and cover,
 * and set the origin from the host (English original web fiction, or a
 * translation for a translation host), never over NovelUpdates' or a
 * manual one. The chapters (the ePub's text), translator_url
 * (book://library/{sha1}) and source_ref (calibre:{uuid}) are never
 * touched: BookLibrary::path() still finds the file and Calibre sync still
 * knows the book. epub_generated is cleared so the ePub sweep builds one
 * from the chapters; the original ePub stays where it is. Every replaced
 * value is kept in converted_snapshot.
 *
 * The cover is added as a new File row, not through Novel::attachCover()
 * (which deletes the previous cover): the book's own cover stays on disk
 * so undo() can bring it back.
 *
 * Undo (undo(): `--undo=ID`, or the owner's Undo button on the series page)
 * puts the snapshot back, removes the NovelUpdates cover and the generated
 * ePub, and makes the row a book again. Both markers stay set, so the
 * nightly run never converts it a second time.
 */
class WebNovelCheck
{
    public const CONVERTED = 'converted';
    public const NOT_WEB_NOVEL = 'not_web_novel';
    public const DUPLICATE = 'duplicate';
    public const FAILED = 'failed';

    /** The rule that converted a book (converted_reason says it in words). */
    public const RULE_EVIDENCE = 'evidence';
    public const RULE_NOVELUPDATES = 'novelupdates';
    public const RULE_ROYALROAD = 'royalroad';

    public const REASON_NOVELUPDATES = 'listed on NovelUpdates';
    public const REASON_ROYALROAD = 'on Royal Road';

    /** The passes evaluate() reports as answered (their markers). */
    public const PASS_EVIDENCE = 'evidence';
    public const PASS_LOOKUP = 'lookup';

    /** NovelUpdates types (#showtype) that make a book a web novel. */
    public const WEB_TYPES = ['web novel'];

    /** Fuzzy author names: at least this similar (1 − Levenshtein ratio). */
    public const AUTHOR_SIMILARITY = 0.9;

    /** Title variants searched on NovelUpdates when the title itself finds nothing. */
    public const MAX_VARIANTS = 3;

    /** Fields restored by undo(), as they were before the conversion. */
    private const SNAPSHOT_FIELDS = [
        'name', 'author', 'description', 'medium',
        'novelupdates_url', 'novelupdates_match_score', 'novelupdates_checked_at',
        'origin', 'origin_language', 'origin_source', 'origin_type', 'origin_confidence',
        'alternative_titles', 'epub_generated', 'web_source_url',
    ];

    public function __construct(private CoverThumbnails $thumbnails)
    {
    }

    /**
     * Books not checked yet, oldest first (soft-deleted ones are left alone):
     * by either pass, or with $evidenceOnly those never read for ePub
     * evidence.
     */
    public static function pending(bool $evidenceOnly = false)
    {
        $query = Novel::books()->orderBy('id');

        return $evidenceOnly
            ? $query->whereNull('webfiction_checked_at')
            : $query->where(fn($q) => $q->whereNull('webnovel_checked_at')->orWhereNull('webfiction_checked_at'));
    }

    /**
     * Decide with the passes asked for: $evidence (rule A, the ePub, no
     * network) and $lookup (rules B and C). Writes nothing.
     *
     * 'answered' lists the passes that got an answer (PASS_EVIDENCE,
     * PASS_LOOKUP): the caller stamps their markers (webfiction_checked_at,
     * webnovel_checked_at); a pass that failed is tried again next run.
     *
     * @return array{result: string, reason: string, url: ?string, title: ?string, score: ?float, metadata: ?array,
     *               rule: ?string, converted_reason: ?string, source_url: ?string, answered: string[]}
     */
    public function evaluate(Novel $book, bool $evidence = true, bool $lookup = true): array
    {
        $answered = [];
        // Whether NovelUpdates or Royal Road was asked (the command paces those).
        $networked = false;
        $with = function (array $outcome) use (&$answered, &$networked) {
            return ['answered' => $answered, 'networked' => $networked] + $outcome + ['rule' => null, 'converted_reason' => null, 'source_url' => null];
        };

        $found = null;
        if ($evidence) {
            $found = $this->evaluateEvidence($book);
            if ($found['result'] !== self::FAILED) {
                $answered[] = self::PASS_EVIDENCE;
            }
            if ($found['result'] === self::DUPLICATE) {
                // Web fiction that is in Novels already: no lookup changes that.
                $answered[] = self::PASS_LOOKUP;

                return $with($found);
            }
            if ($found['result'] === self::CONVERTED) {
                // A translation: NovelUpdates' metadata, when it has the series.
                if ($lookup && $found['translated']) {
                    $networked = true;
                    try {
                        $nu = $this->evaluateNovelUpdates($book);
                    } catch (\Throwable $e) {
                        $nu = ['result' => self::FAILED];
                    }
                    if ($nu['result'] === self::CONVERTED) {
                        $answered[] = self::PASS_LOOKUP;

                        return $with([
                            'converted_reason' => mb_substr($found['converted_reason'] . ', ' . self::REASON_NOVELUPDATES, 0, 191),
                            'source_url' => $found['source_url'],
                            'reason' => $found['reason'] . '; ' . $nu['reason'],
                        ] + $nu);
                    }
                }
                $answered[] = self::PASS_LOOKUP;

                return $with($found);
            }
        }
        if (!$lookup) {
            return $with($found ?? self::blank(self::NOT_WEB_NOVEL, 'not checked'));
        }

        $networked = true;
        $nu = $this->evaluateNovelUpdates($book);
        if ($nu['result'] !== self::NOT_WEB_NOVEL ||!config('novarr.books.webnovel_check_royalroad', true)) {
            if ($nu['result'] !== self::FAILED) {
                $answered[] = self::PASS_LOOKUP;
            }

            return $with($nu);
        }

        $rr = $this->evaluateRoyalRoad($book);
        if ($rr['result'] === self::FAILED) {
            return $with($rr);
        }
        $answered[] = self::PASS_LOOKUP;
        if ($rr['result'] === self::NOT_WEB_NOVEL) {
            return $with(['reason' => $nu['reason'] . '; ' . $rr['reason']] + $nu);
        }

        return $with($rr);
    }

    /**
     * Rule A: the book's own ePub (WebFictionEvidence). No network.
     *
     * @return array{result: string, reason: string, url: ?string, title: ?string, score: ?float, metadata: ?array,
     *               rule: ?string, converted_reason: ?string, source_url: ?string, translated: bool}
     */
    public function evaluateEvidence(Novel $book): array
    {
        $evidence = WebFictionEvidence::forBook($book);
        if ($evidence === null) {
            return self::blank(self::NOT_WEB_NOVEL, 'no web-fiction source in its ePub') + ['translated' => false];
        }
        if (!empty($evidence['missing'])) {
            return self::blank(self::FAILED, 'its ePub file is missing') + ['translated' => false];
        }

        $found = [
            'rule' => self::RULE_EVIDENCE,
            'converted_reason' => mb_substr($evidence['reason'], 0, 191),
            'source_url' => $evidence['url'] !== null ? mb_substr($evidence['url'], 0, 500) : null,
            'url' => $evidence['url'],
            'translated' => (bool) $evidence['translated'],
            'title' => (string) $book->name,
            'evidence' => $evidence,
        ];
        if (($other = $this->existingWebNovel($book, (string) $book->name, $book->author, $evidence['url'])) !== null) {
            return self::blank(self::DUPLICATE, "{$evidence['reason']}, but already in Novels as \"{$other->name}\" (#{$other->id})", $found);
        }

        return self::blank(self::CONVERTED, $evidence['reason'], $found);
    }

    /**
     * Rule B: look the book up on NovelUpdates (its title, then its
     * variants) and decide; writes nothing.
     *
     * @return array{result: string, reason: string, url: ?string, title: ?string, score: ?float, metadata: ?array,
     *               rule: ?string, converted_reason: ?string, source_url: ?string}
     */
    public function evaluateNovelUpdates(Novel $book): array
    {
        $title = trim((string) $book->name);
        $author = trim((string) $book->author) ?: null;
        if ($title === '') {
            return self::blank(self::NOT_WEB_NOVEL, 'no title');
        }

        $delay = max(0.0, (float) config('novarr.books.webnovel_check_delay', 3));
        $first = null;
        foreach (array_merge([$title], self::titleVariants($title)) as $i => $name) {
            if ($i > 0 && $delay > 0) {
                usleep((int) ($delay * 1_000_000));
            }
            $report = null;
            $url = resolveNovelUpdatesUrl($name, $author, $report);
            if (($report['failed'] ?? null) !== null) {
                return self::blank(self::FAILED, "NovelUpdates search failed ({$report['failed']})");
            }
            if ($url === null) {
                $best = $report['score'] ?? null;
                $first ??= self::blank(self::NOT_WEB_NOVEL, $best === null ? 'not on NovelUpdates' : sprintf('no confident NovelUpdates match (best %.3f)', $best), ['score' => $best]);
                continue;
            }

            $judged = $this->judgeNovelUpdates($book, $name, $author, $url, $report);
            if ($i > 0 && $judged['result'] !== self::FAILED) {
                $judged['reason'] .= " (searched as \"{$name}\")";
            }
            // The title itself decides; a variant that found another series
            // leaves the next variant to try.
            if ($i === 0 || $judged['result'] !== self::NOT_WEB_NOVEL) {
                return $judged;
            }
            $first ??= $judged;
        }

        return $first;
    }

    /**
     * Rule B for one confident search hit ($name is the title searched):
     * the series page's title, type and author, and no duplicate.
     */
    private function judgeNovelUpdates(Novel $book, string $name, ?string $author, string $url, ?array $report): array
    {
        $page = $report['metadata'] ?? null;
        if ($page === null) {
            $failure = null;
            $page = fetchNovelUpdatesMetadata($url, $failure);
            if ($failure !== null) {
                return self::blank(self::FAILED, "NovelUpdates series page failed ({$failure})", ['url' => $url]);
            }
        }
        $title = trim((string) ($page['title'] ?? ''));
        $associated = array_values(array_filter(array_map('trim', (array) ($page['associated'] ?? [])), 'strlen'));
        if ($title === '' && $associated === []) {
            return self::blank(self::NOT_WEB_NOVEL, 'the NovelUpdates series page is empty', ['url' => $url]);
        }

        $found = ['url' => $url, 'title' => $title !== '' ? $title : null, 'metadata' => $page];

        // 1. The title alone, without the matcher's author bonus.
        $score = NovelUpdatesMatcher::score($name, null, ['title' => $title, 'associated' => $associated]);
        $found['score'] = $score;
        if ($score < NovelUpdatesMatcher::threshold()) {
            return self::blank(self::NOT_WEB_NOVEL, sprintf('title too different from "%s" (%.3f)', $title, $score), $found);
        }

        // 2. A web novel, not a light / published novel.
        $type = strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($page['type'] ?? '')) ?? ''));
        if (!self::isWebType($type)) {
            return self::blank(self::NOT_WEB_NOVEL, 'NovelUpdates lists it as ' . ($type !== '' ? "\"{$page['type']}\"" : 'no type') . ', not a web novel', $found);
        }

        // 3. The author, or failing one, the exact title.
        $theirs = array_values(array_filter(array_map('trim', array_merge(
            (array) ($page['authors'] ?? []),
            [(string) ($page['author'] ?? '')]
        )), 'strlen'));
        if ($author !== null && $theirs !== []) {
            if (!self::authorsMatch($author, $theirs)) {
                return self::blank(self::NOT_WEB_NOVEL, sprintf('another author ("%s" on NovelUpdates, "%s" here)', $theirs[0], $author), $found);
            }
        } elseif (!self::exactTitle($name, array_merge([$title], $associated))) {
            return self::blank(self::NOT_WEB_NOVEL, 'no author to compare and the title is not exactly NovelUpdates\'', $found);
        }

        // 4. Not already in the library as a web novel.
        if (($other = $this->existingSeries($book, $url, $title)) !== null) {
            return self::blank(self::DUPLICATE, "already in Novels as \"{$other->name}\" (#{$other->id})", $found);
        }

        return self::blank(self::CONVERTED, 'web novel on NovelUpdates', $found + [
            'rule' => self::RULE_NOVELUPDATES,
            'converted_reason' => self::REASON_NOVELUPDATES,
        ]);
    }

    /**
     * Rule C: a Royal Road search by title; the exact title by the same
     * author. Uses RoyalRoadSource's own search (plain request first,
     * cached ten minutes) and fiction page; at most two fiction pages per
     * book.
     *
     * @return array{result: string, reason: string, url: ?string, title: ?string, score: ?float, metadata: ?array,
     *               rule: ?string, converted_reason: ?string, source_url: ?string}
     */
    public function evaluateRoyalRoad(Novel $book): array
    {
        $name = trim((string) $book->name);
        $author = trim((string) $book->author);
        if ($name === '' || $author === '') {
            return self::blank(self::NOT_WEB_NOVEL, 'not looked up on Royal Road (no ' . ($name === '' ? 'title' : 'author') . ')');
        }

        $source = app(RoyalRoadSource::class);
        $items = $source->discover('search', $name);
        if ($items === null) {
            return self::blank(self::FAILED, 'Royal Road search failed');
        }
        $exact = array_values(array_filter($items, fn($item) => self::exactTitle($name, [(string) ($item['name'] ?? '')])));
        if ($exact === []) {
            return self::blank(self::NOT_WEB_NOVEL, 'not on Royal Road');
        }

        foreach (array_slice($exact, 0, 2) as $item) {
            $page = $source->pageMetadata((string) $item['url']);
            if (!isset($page['title'])) {
                return self::blank(self::FAILED, 'Royal Road fiction page failed', ['url' => $item['url']]);
            }
            $theirs = trim((string) ($page['author'] ?? ''));
            if ($theirs === '' || !self::authorsOverlap($author, $theirs)) {
                continue;
            }
            $found = [
                'url' => $item['url'],
                'title' => trim((string) ($page['title'] ?: $item['name'])),
                'metadata' => $page,
                'rule' => self::RULE_ROYALROAD,
                'converted_reason' => self::REASON_ROYALROAD,
                'source_url' => mb_substr((string) $item['url'], 0, 500),
            ];
            if (($other = $this->existingWebNovel($book, $name, $author, $item['url'])) !== null) {
                return self::blank(self::DUPLICATE, "on Royal Road, but already in Novels as \"{$other->name}\" (#{$other->id})", $found);
            }

            return self::blank(self::CONVERTED, "on Royal Road by {$theirs}", $found);
        }

        return self::blank(self::NOT_WEB_NOVEL, 'on Royal Road under that title by another author', ['url' => $exact[0]['url']]);
    }

    /**
     * Move a book to Novels by evaluate()'s outcome: with the NovelUpdates
     * metadata for rule B (its 'url', 'score' and 'metadata'), keeping the
     * book's own for rules A and C.
     */
    public function convert(Novel $book, array $match): Novel
    {
        $rule = $match['rule'] ?? self::RULE_NOVELUPDATES;
        $nu = $rule === self::RULE_NOVELUPDATES;
        $page = $nu ? (array) $match['metadata'] : [];
        $snapshot = ['fields' => [], 'tags' => $book->tags()->pluck('tags.id')->map(fn($id) => (int) $id)->all()];
        foreach (self::SNAPSHOT_FIELDS as $field) {
            $value = $book->getAttributes()[$field] ?? null;
            $snapshot['fields'][$field] = $field === 'alternative_titles' ? $book->alternative_titles : $value;
        }
        $snapshot['converted_at'] = now()->toDateTimeString();
        $snapshot['rule'] = $rule;
        $reason = (string) ($match['converted_reason'] ?? ($nu ? self::REASON_NOVELUPDATES : 'web fiction'));

        DB::transaction(function () use ($book, $page, $match, $nu, $rule, $reason, &$snapshot) {
            $book->forceFill([
                'medium' => Novel::MEDIUM_NOVEL,
                'converted_from' => Novel::MEDIUM_BOOK,
                'converted_reason' => mb_substr($reason, 0, 191),
                'webnovel_checked_at' => now(),
                'webfiction_checked_at' => now(),
                // Built again from the chapters (under the new title and cover).
                'epub_generated' => null,
            ]);
            if (($match['source_url'] ?? null) !== null) {
                $book->web_source_url = mb_substr((string) $match['source_url'], 0, 500);
            }

            if ($nu) {
                $title = trim((string) ($page['title'] ?? ''));
                $associated = array_values(array_unique(array_filter(array_map('trim', (array) ($page['associated'] ?? [])), 'strlen')));
                $book->forceFill([
                    'novelupdates_url' => $match['url'],
                    'novelupdates_match_score' => $match['score'],
                    'novelupdates_checked_at' => now(),
                    'alternative_titles' => $associated ?: null,
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
            }
            $book->converted_snapshot = $snapshot;
            $book->save();

            if ($nu) {
                if (($origin = OriginInference::fromNovelUpdates($page)) !== null) {
                    $book->applyOrigin($origin, 'novelupdates');
                }
                $genres = array_values(array_filter((array) ($page['genres'] ?? [])));
                if ($genres !== []) {
                    $book->tags()->sync(collect($genres)->map(fn($g) => Tag::firstOrCreate(['name' => $g])->id)->all());
                }
            } else {
                // From the host: English original web fiction, or a translation.
                $translated = (bool) ($match['translated'] ?? false);
                $book->applyOrigin($translated
                    ? ['origin' => OriginInference::TRANSLATED, 'origin_language' => null, 'origin_type' => 'Web Novel', 'confidence' => OriginInference::MEDIUM]
                    : ['origin' => OriginInference::ORIGINAL, 'origin_language' => 'en', 'origin_type' => 'Web Novel', 'confidence' => OriginInference::HIGH],
                    'source');
            }
        });

        // The NovelUpdates cover, beside the book's own (kept for undo).
        $image = trim((string) ($page['image'] ?? ''));
        if ($nu && $image !== '' && ($downloaded = downloadCoverImage($image, $book->id))) {
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
        Log::info('Book moved to Novels: ' . $reason, [
            'novel' => $book->id,
            'rule' => $rule,
            'was' => $snapshot['fields']['name'],
            'now' => $book->name,
            'url' => $match['url'] ?? null,
            'score' => $match['score'] ?? null,
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

        // The ePub built from the chapters under the new title (the
        // original stays at BookLibrary::path()).
        $generated = \App\Console\Commands\GenerateePub::epubPath($novel);
        if (!$novel->isBook() && is_file($generated) && $generated !== BookLibrary::path($novel)) {
            @unlink($generated);
        }

        DB::transaction(function () use ($novel, $snapshot) {
            $fields = array_intersect_key((array) ($snapshot['fields'] ?? []), array_flip(self::SNAPSHOT_FIELDS));
            $alternative = $fields['alternative_titles'] ?? null;
            unset($fields['alternative_titles']);

            $novel->forceFill($fields);
            $novel->alternative_titles = $alternative;
            $novel->medium = $fields['medium'] ?? Novel::MEDIUM_BOOK;
            $novel->converted_from = null;
            $novel->converted_reason = null;
            $novel->converted_snapshot = null;
            // Checked: the owner says it is a book, so it is never moved
            // again (not by --all either: webnovel_kept_at).
            $novel->webnovel_checked_at ??= now();
            $novel->webfiction_checked_at ??= now();
            $novel->webnovel_kept_at = now();
            $novel->save();

            $novel->tags()->sync((array) ($snapshot['tags'] ?? []));
        });

        // The NovelUpdates cover and an AI cover painted since (App\Covers\CoverGenerator).
        foreach (['added_cover', 'generated_cover'] as $added) {
            if (!empty($snapshot[$added]) && ($file = File::find($snapshot[$added])) !== null) {
                $this->removeCover($file);
            }
        }
        if (!empty($snapshot['generated_cover'])) {
            DB::table('novels')->where('id', $novel->id)->where('cover_source', BookCovers::SOURCE_GENERATED)
                ->update(['cover_source' => null, 'cover_generated_at' => null]);
            $novel->cover_source = $novel->cover_source === BookCovers::SOURCE_GENERATED ? null : $novel->cover_source;
        }
        $novel->unsetRelation('file');

        CacheHelper::clearNovelCache((int) $novel->id);
        Log::info('Web-novel conversion undone: back in Books', ['novel' => $novel->id, 'title' => $novel->name]);

        return $novel;
    }

    /**
     * Searchable variants of a book title, most specific first, without the
     * title itself (at most MAX_VARIANTS): no bracketed suffix ("(Light
     * Novel)", "[Complete]"), no volume marker ("Vol. 2", "Volume II",
     * "Book 3", "Part One"), no subtitle (after ":" or a spaced dash).
     *
     * @return string[]
     */
    public static function titleVariants(string $title): array
    {
        $clean = fn(string $t) => trim(preg_replace('/\s+/u', ' ', trim($t, " \t\n\r\0\x0B,;:.-–—")) ?? '');
        $numbers = '(?:\d+(?:\.\d+)?|[ivxlc]+|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)';

        $variants = [];
        $base = $clean(preg_replace('/\s*[(\[][^)\]]*[)\]]\s*$/u', '', $title) ?? $title);
        $variants[] = $base;
        $noVolume = $clean(preg_replace('/[\s,:;–—-]*\b(?:vol(?:ume)?\.?|book|part)\s*' . $numbers . '\b|\s#\d+\b/iu', ' ', $base) ?? $base);
        $variants[] = $noVolume;
        foreach ([$noVolume, $base] as $t) {
            $parts = preg_split('/\s*(?::|\s[-–—]\s)\s*/u', $t, 2);
            if (is_array($parts) && count($parts) === 2) {
                $variants[] = $clean($parts[0]);
            }
        }

        $own = NovelUpdatesMatcher::normalizeTitle($title);
        $seen = [];
        $out = [];
        foreach ($variants as $variant) {
            $key = NovelUpdatesMatcher::normalizeTitle($variant);
            if (mb_strlen($variant) < 3 || $key === '' || $key === $own || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $variant;
        }

        return array_slice($out, 0, self::MAX_VARIANTS);
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
     * Rule C's author test: authorsMatch(), or every word (three letters or
     * more) of the shorter name is a word of the other ("nobody103" and
     * "nobody103 (Domagoj Kurmaić)").
     */
    public static function authorsOverlap(string $ours, string $theirs): bool
    {
        if (self::authorsMatch($ours, [$theirs])) {
            return true;
        }
        $words = fn(string $name) => array_values(array_unique(array_filter(
            explode(' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($name))) ?? ''),
            fn($w) => strlen($w) >= 3
        )));
        $a = $words($ours);
        $b = $words($theirs);
        if ($a === [] || $b === []) {
            return false;
        }
        [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];

        return array_diff($short, $long) === [];
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

    /** An outcome of evaluate() with every key. */
    private static function blank(string $result, string $reason, array $extra = []): array
    {
        return $extra + [
            'result' => $result, 'reason' => $reason, 'url' => null, 'title' => null, 'score' => null, 'metadata' => null,
            'rule' => null, 'converted_reason' => null, 'source_url' => null,
        ];
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

    /**
     * Rules A and C: a web novel already in Novels that is this story: the
     * same source page ($url as its translator_url or web_source_url), or
     * the same title by the same author (either without an author: the
     * title alone).
     */
    private function existingWebNovel(Novel $book, string $title, ?string $author, ?string $url): ?Novel
    {
        if ($url !== null && trim($url) !== '') {
            $urls = array_values(array_unique([trim($url), rtrim(trim($url), '/'), rtrim(trim($url), '/') . '/']));
            $byUrl = Novel::whereKeyNot($book->id)
                ->where(fn($q) => $q->whereIn('translator_url', $urls)->orWhereIn('web_source_url', $urls))
                ->first();
            if ($byUrl !== null) {
                return $byUrl;
            }
        }
        $key = \App\Services\DuplicateSeries::normalize($title);
        if ($key === '') {
            return null;
        }
        $author = trim((string) $author);
        foreach (Novel::text()->whereKeyNot($book->id)->get(['id', 'name', 'author']) as $novel) {
            if (\App\Services\DuplicateSeries::normalize((string) $novel->name) !== $key) {
                continue;
            }
            $theirs = trim((string) $novel->author);
            if ($author === '' || $theirs === '' || self::authorsOverlap($author, $theirs)) {
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
