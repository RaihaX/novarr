<?php

namespace App\Audiobooks;

use App\Audiobook;
use App\AudiobookChapter;
use App\Books\AppleBooks;
use App\Books\CatalogueHttp;
use App\Services\CoverThumbnails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * What an audiobook's files leave out, from the catalogues
 * (novarr.audiobooks.sources):
 *
 *   audible  the Audible catalogue search (App\Audiobooks\Audible) by title
 *            and author, the files' length within ±3 % of Audible's runtime
 *            when both are known; then Audnexus' record and chapters for
 *            that ASIN. Fills, never overwriting what the files said:
 *              asin, series + number (when it has none), narrator (when
 *              missing), description (when missing), year (when missing);
 *              the cover when it has none (or $options['cover'] = the
 *              owner chose Audible's);
 *              CHAPTERS only when its own are placeholders ("Part 1", one
 *              per file, "Chapter 3" / "Track 03" numbering) AND Audible's
 *              runtime is within ±2 % (CHAPTER_TOLERANCE) of the files':
 *              Audible's names and offsets as they are (nothing scaled),
 *              the last chapter ending at the end of the files. Real
 *              embedded chapters are never replaced.
 *   itunes   Apple Books' audiobook search: a 1200 px cover for one that
 *            still has none.
 *
 * Runs after an import (AudiobookImporter), from "Look up on Audible" on
 * the manage page and from `audiobooks:enrich`. Every failure is logged
 * and skipped; enriched_at is set when the catalogues answered (match or
 * not), left as it was when none could be asked.
 */
class AudiobookEnricher
{
    /** Largest runtime difference (fraction) for Audible's chapters to be used. */
    public const CHAPTER_TOLERANCE = 0.02;

    /** Chapter titles that only number the parts ("Part 1", "Chapter 03", "Track 3", "CD 2", "01"). */
    public const PLACEHOLDER_TITLE = '/^\s*(?:(?:part|chapter|track|cd|disc|disk|file|kapitel|chapitre)\s*[-_#.]?\s*)?\d{1,4}\s*$/iu';

    public function __construct(private Audible $audible, private AppleBooks $apple, private CoverThumbnails $thumbnails)
    {
    }

    /** @return list<string> */
    public static function sources(): array
    {
        $sources = config('novarr.audiobooks.sources', ['audible', 'itunes']);
        if (is_string($sources)) {
            $sources = explode(',', $sources);
        }

        return array_values(array_intersect(['audible', 'itunes'], array_map(fn($s) => strtolower(trim((string) $s)), (array) $sources)));
    }

    public static function enabled(string $source = 'audible'): bool
    {
        return in_array($source, static::sources(), true);
    }

    /**
     * Look $audiobook up and fill what it lacks (see the class comment).
     *
     * @param  array{cover?: bool, title?: ?string, author?: ?string}  $options  cover: use Audible's cover even
     *         when it has one; title / author: what to search for (the import's raw tags)
     * @return array{asked: bool, matched: bool, asin: ?string, changed: list<string>, product: ?array}
     */
    public function enrich(Audiobook $audiobook, array $options = []): array
    {
        $result = ['asked' => false, 'matched' => false, 'asin' => $audiobook->asin, 'changed' => [], 'product' => null];
        if (static::sources() === []) {
            return $result;
        }

        $title = trim((string) ($options['title'] ?? '')) ?: (string) $audiobook->title;
        $author = trim((string) ($options['author'] ?? '')) ?: ($audiobook->author !== null ? (string) $audiobook->author : null);
        $changes = [];

        if (static::enabled('audible')) {
            try {
                [$product, $asked] = $this->lookup($audiobook, $title, $author);
            } catch (\Throwable $e) {
                Log::info("Audiobook {$audiobook->id}: the Audible lookup failed: " . $e->getMessage());
                [$product, $asked] = [null, false];
            }
            $result['asked'] = $asked;
            if ($product !== null) {
                $result['matched'] = true;
                $result['asin'] = $product['asin'];
                $result['product'] = $product;
                $changes = $this->apply($audiobook, $product, (bool) ($options['cover'] ?? false));
            }
        }

        // Still no cover: Apple Books' (1200 px).
        if ($audiobook->cover_path === null && static::enabled('itunes')) {
            try {
                $url = $this->apple->coverFor($title, $author, 'audiobook');
                $result['asked'] = true;
                if ($url !== null && $this->storeCover($audiobook, $url, 'itunes')) {
                    $changes[] = 'cover';
                    $audiobook->metadata_source ??= 'itunes';
                }
            } catch (\Throwable $e) {
                Log::info("Audiobook {$audiobook->id}: the Apple Books lookup failed: " . $e->getMessage());
            }
        }

        if ($result['asked']) {
            $audiobook->enriched_at = now();
        }
        if ($audiobook->isDirty()) {
            $audiobook->save();
        }
        $result['changed'] = array_values(array_unique($changes));

        return $result;
    }

    /**
     * The Audible product: by the stored ASIN, else the search. [product
     * or null, whether Audible answered].
     *
     * @return array{0: ?array, 1: bool}
     */
    private function lookup(Audiobook $audiobook, string $title, ?string $author): array
    {
        if ($audiobook->asin && Audible::validAsin((string) $audiobook->asin)) {
            $details = $this->audible->details((string) $audiobook->asin);

            return [$details, $details !== null];
        }

        $duration = (int) $audiobook->duration_ms > 0 ? (int) $audiobook->duration_ms : null;
        $products = $this->audible->search(AudiobookMatcher::cleanTitle($title), $author);
        if ($products === null) {
            return [null, false];
        }
        $product = Audible::best($products, AudiobookMatcher::cleanTitle($title), $author, $duration);
        if ($product === null && $author !== null && trim($author) !== '') {
            $product = Audible::best($this->audible->search(AudiobookMatcher::cleanTitle($title)) ?? [], AudiobookMatcher::cleanTitle($title), $author, $duration);
        }
        if ($product === null) {
            return [null, true];
        }

        // Audnexus' record is fuller (the full-size image, the primary series).
        $details = $this->audible->details($product['asin']);
        if ($details !== null) {
            foreach ($details as $field => $value) {
                if ($value !== null && $value !== [] && $value !== '') {
                    $product[$field] = $value;
                }
            }
        }

        return [$product, true];
    }

    /** @return list<string> the fields filled */
    private function apply(Audiobook $audiobook, array $product, bool $forceCover): array
    {
        $changed = [];
        if ($audiobook->asin !== $product['asin']) {
            $audiobook->asin = $product['asin'];
            $changed[] = 'asin';
        }
        $audiobook->metadata_source = 'audible';

        if (trim((string) $audiobook->narrator) === '' && $product['narrators'] !== []) {
            $audiobook->narrator = mb_substr(implode(', ', $product['narrators']), 0, 255);
            $changed[] = 'narrator';
        }
        if (trim((string) $audiobook->description) === '' && !empty($product['description'])) {
            $audiobook->description = $product['description'];
            $changed[] = 'description';
        }
        if ($audiobook->year === null && !empty($product['year'])) {
            $audiobook->year = (int) $product['year'];
            $changed[] = 'year';
        }
        if (trim((string) $audiobook->series) === '' && !empty($product['series'])) {
            $audiobook->series = mb_substr((string) $product['series'], 0, 255);
            $audiobook->series_index = $product['series_index'];
            $changed[] = 'series';
        }
        if (($audiobook->cover_path === null || $forceCover) && !empty($product['cover_url'])
            && $this->storeCover($audiobook, (string) $product['cover_url'], 'audible')) {
            $changed[] = 'cover';
        }
        if ($this->applyChapters($audiobook, $product['asin'])) {
            $changed[] = 'chapters';
        }

        return $changed;
    }

    /**
     * Audnexus' chapters in place of placeholder ones, when the runtimes
     * agree within CHAPTER_TOLERANCE. True when replaced.
     */
    public function applyChapters(Audiobook $audiobook, string $asin): bool
    {
        if (!static::placeholderChapters($audiobook)) {
            return false;
        }
        $duration = (int) $audiobook->duration_ms;
        if ($duration <= 0) {
            return false;
        }
        $answer = $this->audible->chapters($asin);
        if ($answer === null || abs($answer['runtime_ms'] - $duration) > $duration * self::CHAPTER_TOLERANCE) {
            return false;
        }

        // Audible's offsets as they are; a chapter past the end of the files is dropped, the last ends with them.
        $chapters = array_values(array_filter($answer['chapters'], fn($c) => $c['start_ms'] < $duration));
        if (count($chapters) < 1) {
            return false;
        }
        for ($i = 0; $i < count($chapters); $i++) {
            $chapters[$i]['end_ms'] = $i + 1 < count($chapters) ? $chapters[$i + 1]['start_ms'] : $duration;
        }
        $chapters[0]['start_ms'] = 0;
        $chapters = array_values(array_filter($chapters, fn($c) => $c['end_ms'] > $c['start_ms']));

        DB::transaction(function () use ($audiobook, $chapters) {
            AudiobookChapter::where('audiobook_id', $audiobook->id)->delete();
            foreach ($chapters as $idx => $c) {
                AudiobookChapter::create(['audiobook_id' => $audiobook->id, 'idx' => $idx] + $c);
            }
        });
        $audiobook->chapters_source = 'audible';
        $audiobook->unsetRelation('chapters');

        return true;
    }

    /**
     * Whether $audiobook's chapters are placeholders Audible's may replace:
     * recorded so at import ('files'), or (imported before that was
     * recorded) none, one "Part 1", one per file, or titles that only
     * number ("Chapter 3", "Track 03").
     */
    public static function placeholderChapters(Audiobook $audiobook): bool
    {
        if ($audiobook->chapters_source === 'embedded' || $audiobook->chapters_source === 'audible') {
            return false;
        }
        if ($audiobook->chapters_source === 'files') {
            return true;
        }
        $chapters = AudiobookChapter::where('audiobook_id', $audiobook->id)->orderBy('idx')->get(['title', 'start_ms']);
        if ($chapters->isEmpty()) {
            return true;
        }
        if ($chapters->every(fn($c) => preg_match(self::PLACEHOLDER_TITLE, (string) $c->title))) {
            return true;
        }
        // One chapter per file, starting where each file starts.
        $starts = DB::table('audiobook_parts')->where('audiobook_id', $audiobook->id)->orderBy('idx')->pluck('start_ms')->map(fn($v) => (int) $v)->all();

        return count($starts) > 1 && $chapters->pluck('start_ms')->map(fn($v) => (int) $v)->all() === $starts;
    }

    /** Download a cover and make it the audiobook's (thumbnails, the old one removed). */
    private function storeCover(Audiobook $audiobook, string $url, string $source): bool
    {
        $image = CatalogueHttp::image($url);
        if ($image === null) {
            return false;
        }
        $name = 'ab' . md5($audiobook->id . '|' . $url . '|' . microtime(true)) . '.' . $image['ext'];
        try {
            Storage::disk('public')->put($name, $image['bytes']);
            $this->thumbnails->generate($name);
        } catch (\Throwable $e) {
            Log::warning("Audiobook {$audiobook->id}: the {$source} cover could not be stored: " . $e->getMessage());

            return false;
        }
        $old = $audiobook->cover_path;
        $audiobook->cover_path = $name;
        $audiobook->cover_source = $source;
        if ($old !== null && $old !== $name) {
            try {
                $this->thumbnails->delete($old);
            } catch (\Throwable) {
            }
            Storage::disk('public')->delete($old);
        }

        return true;
    }
}
