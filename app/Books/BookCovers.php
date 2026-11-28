<?php

namespace App\Books;

use App\Http\Helpers\CacheHelper;
use App\Novel;
use App\Services\CoverThumbnails;
use App\Services\DuplicateSeries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The best cover for each book of the Books library (`books:covers`,
 * nightly; "Find a better cover" on the book page).
 *
 * The current cover is a PLACEHOLDER (as good as none) when there is none,
 * it is under MIN_HEIGHT pixels tall, or it is a generated text card
 * (Calibre's default cover, FanFicFare / FicHub title pages: a flat
 * background with text; textCard()), or a blank page with a little text
 * (an ePub whose "cover" is its title page: blankPage()).
 *
 * Candidates come from every catalogue switched on (BookLookup): by the
 * book's ISBN (its ePub's) first, then by title and author; a catalogue's
 * book counts only when it is this book: the same ISBN, or the same title
 * (normalised, equal or one inside the other) AND an author word in common
 * (BookLibrary::authorsAgree()). Each candidate cover is downloaded, must
 * decode and be portrait (width / height MIN_ASPECT–MAX_ASPECT); the
 * largest (pixel area) wins. Google's thumbnail is also asked at zoom=0,
 * Apple's at 1200 px.
 *
 * Replaced automatically when the current cover is a placeholder, or the
 * best is at least UPGRADE_FACTOR × its area (STRICT_UPGRADE_FACTOR × for a
 * cover of unknown origin, unless the candidate is the same edition by
 * ISBN). Never a cover the owner chose (novels.cover_source 'manual'). An
 * AI-painted cover (cover_source 'generated', App\Covers) counts as a
 * placeholder here: a real catalogue cover always replaces it (precedence
 * generated < catalogue < manual), and a book with one is checked again every
 * GENERATED_RECHECK_DAYS. The
 * new cover is attached like any other (Novel::attachCover(): new File row,
 * thumbnails); a book in Calibre is pushed to it (PushBookToCalibre).
 */
class BookCovers
{
    /** A cover shorter than this (pixels) is a placeholder. */
    public const MIN_HEIGHT = 300;

    /** Portrait only: width / height between these. */
    public const MIN_ASPECT = 0.55;
    public const MAX_ASPECT = 0.8;

    /** A better cover replaces a catalogue one at this many times its area… */
    public const UPGRADE_FACTOR = 1.5;

    /** …and a cover of unknown origin at this many (or the same edition by ISBN at UPGRADE_FACTOR). */
    public const STRICT_UPGRADE_FACTOR = 2.0;

    /** Candidate covers downloaded per book. */
    public const MAX_DOWNLOADS = 10;

    /**
     * The text-card test (textCard()): the image is shrunk to SAMPLE ×
     * SAMPLE, each pixel's colour quantised to QUANT_BITS bits a channel.
     * A card is few colours (≤ FLAT_MAX_COLOURS), or one background colour
     * covering ≥ FLAT_BACKGROUND_SHARE of it with ≤ CARD_MAX_COLOURS
     * colours in all. A photo or an illustration has hundreds.
     */
    public const SAMPLE = 32;
    public const QUANT_BITS = 3;
    public const FLAT_MAX_COLOURS = 12;
    public const FLAT_BACKGROUND_SHARE = 0.55;
    public const CARD_MAX_COLOURS = 48;
    /**
     * A generated card is flat fills and a few lines of type: few sharp
     * neighbouring-pixel changes. Measured 2026-11-28: FanFicFare / Calibre
     * cards 0.05-0.07, real covers with flat, type-led designs (Yellowface,
     * The Atlas Six, Ana Huang's King of... books) 0.12-0.52. Without it the
     * CARD_MAX_COLOURS rule called those real covers placeholders.
     */
    public const CARD_MAX_EDGES = 0.09;
    /** A neighbouring-pixel difference (sum of R, G, B) that counts as an edge. */
    public const EDGE_STEP = 24;

    /**
     * The blank-page test (blankPage()): at least BLANK_LIGHT_SHARE of the
     * SAMPLE × SAMPLE pixels near white (every channel ≥ BLANK_LIGHT_LEVEL)
     * and at most BLANK_COLOURED_SHARE coloured (channels BLANK_CHROMA
     * apart): black or grey text on white, nothing painted.
     */
    public const BLANK_LIGHT_LEVEL = 215;
    public const BLANK_LIGHT_SHARE = 0.8;
    public const BLANK_CHROMA = 60;
    public const BLANK_COLOURED_SHARE = 0.03;

    /** A book with an AI cover is asked of the catalogues again after this many days. */
    public const GENERATED_RECHECK_DAYS = 30;

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CATALOGUE = 'catalogue';
    /** Painted by the AI cover pipeline (App\Covers\CoverGenerator): any real cover beats it. */
    public const SOURCE_GENERATED = 'generated';

    public function __construct(private BookLookup $lookup)
    {
    }

    /**
     * Whether image bytes are a generated text card (see the constants).
     * Not decodable = not a card (the caller handles a broken image).
     */
    public static function textCard(string $bytes): bool
    {
        $stats = static::colourStats($bytes);
        if ($stats === null) {
            return false;
        }

        return ($stats['colours'] <= self::FLAT_MAX_COLOURS
                || ($stats['background_share'] >= self::FLAT_BACKGROUND_SHARE && $stats['colours'] <= self::CARD_MAX_COLOURS))
            && $stats['edges'] <= self::CARD_MAX_EDGES;
    }

    /**
     * Whether image bytes are a mostly blank page with a little dark text
     * (see the BLANK_ constants): a title or copyright page used as a cover.
     */
    public static function blankPage(string $bytes): bool
    {
        $stats = static::colourStats($bytes);

        return $stats !== null
            && $stats['light_share'] >= self::BLANK_LIGHT_SHARE
            && $stats['coloured_share'] <= self::BLANK_COLOURED_SHARE;
    }

    /** @return ?array{colours: int, background_share: float, light_share: float, coloured_share: float, edges: float} */
    public static function colourStats(string $bytes): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $n = self::SAMPLE;
        $small = imagecreatetruecolor($n, $n);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $n, $n, imagesx($image), imagesy($image));
        $shift = 8 - self::QUANT_BITS;
        $counts = [];
        $light = 0;
        $coloured = 0;
        for ($x = 0; $x < $n; $x++) {
            for ($y = 0; $y < $n; $y++) {
                $rgb = imagecolorat($small, $x, $y);
                [$r, $g, $b] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                $key = ($r >> $shift) << (2 * self::QUANT_BITS) | ($g >> $shift) << self::QUANT_BITS | ($b >> $shift);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $light += min($r, $g, $b) >= self::BLANK_LIGHT_LEVEL ? 1 : 0;
                $coloured += max($r, $g, $b) - min($r, $g, $b) >= self::BLANK_CHROMA ? 1 : 0;
            }
        }
        $total = $n * $n;
        imagedestroy($small);

        // Edge density on a larger sample: how much fine detail (illustration,
        // texture, photo grain) there is, as opposed to flat fills and type.
        [$ew, $eh] = [96, 144];
        $edgeSample = imagecreatetruecolor($ew, $eh);
        imagecopyresampled($edgeSample, $image, 0, 0, 0, 0, $ew, $eh, imagesx($image), imagesy($image));
        $edges = 0;
        for ($y = 0; $y < $eh; $y++) {
            $prev = imagecolorat($edgeSample, 0, $y);
            for ($x = 1; $x < $ew; $x++) {
                $cur = imagecolorat($edgeSample, $x, $y);
                $step = abs((($cur >> 16) & 0xFF) - (($prev >> 16) & 0xFF))
                    + abs((($cur >> 8) & 0xFF) - (($prev >> 8) & 0xFF))
                    + abs(($cur & 0xFF) - ($prev & 0xFF));
                $edges += $step > self::EDGE_STEP ? 1 : 0;
                $prev = $cur;
            }
        }
        imagedestroy($edgeSample);

        return [
            'colours' => count($counts), 'background_share' => max($counts) / $total, 'light_share' => $light / $total,
            'coloured_share' => $coloured / $total, 'edges' => $edges / (($ew - 1) * $eh),
        ];
    }

    /**
     * The current cover: its size and whether it is a placeholder.
     *
     * @return array{exists: bool, width: int, height: int, placeholder: bool, reason: ?string}
     */
    public function current(Novel $book): array
    {
        $file = $book->relationLoaded('file') ? $book->file : $book->file()->first();
        $relative = $file ? CoverThumbnails::relative((string) $file->file_path) : '';
        $bytes = null;
        try {
            $bytes = $relative !== '' && Storage::disk('public')->exists($relative) ? Storage::disk('public')->get($relative) : null;
        } catch (\Throwable) {
        }
        $image = $bytes !== null ? CatalogueHttp::describeImage((string) $bytes) : null;
        if ($image === null) {
            return ['exists' => false, 'width' => 0, 'height' => 0, 'placeholder' => true, 'reason' => 'no cover'];
        }
        $reason = match (true) {
            $image['height'] < self::MIN_HEIGHT => 'too small (' . $image['width'] . '×' . $image['height'] . ')',
            static::blankPage($image['bytes']) => 'a blank page with a little text',
            static::textCard($image['bytes']) => 'a generated text cover',
            default => null,
        };

        return ['exists' => true, 'width' => $image['width'], 'height' => $image['height'], 'placeholder' => $reason !== null, 'reason' => $reason];
    }

    /**
     * The catalogue covers of this book, downloaded and measured, largest
     * first (portrait, decodable only).
     *
     * @return list<array{url: string, source: string, source_name: string, width: int, height: int, isbn_match: bool, bytes: string, ext: string}>
     */
    public function candidates(Novel $book): array
    {
        $isbn = static::isbnOf($book);
        $urls = [];
        $add = function (string $url, string $source, bool $isbnMatch) use (&$urls) {
            $urls[$url] ??= ['url' => $url, 'source' => $source, 'source_name' => BookLookup::SOURCE_NAMES[$source] ?? $source, 'isbn_match' => $isbnMatch];
            $urls[$url]['isbn_match'] = $urls[$url]['isbn_match'] || $isbnMatch;
        };

        if ($isbn !== null && BookLookup::sourceEnabled('openlibrary')) {
            $add(BookLookup::OPEN_LIBRARY_COVERS . "/isbn/{$isbn}-L.jpg?default=false", 'openlibrary', true);
        }
        $queries = [];
        if ($isbn !== null) {
            $queries[] = ['isbn' => $isbn, 'title' => (string) $book->name, 'author' => (string) $book->author];
        }
        $queries[] = ['title' => (string) $book->name, 'author' => (string) $book->author];

        foreach ($queries as $query) {
            foreach ($this->lookup->candidates($query, 15) as $c) {
                $isbnMatch = $isbn !== null && ($c['isbn'] === $isbn || in_array($isbn, (array) ($c['isbns'] ?? []), true));
                if (!$isbnMatch && !static::sameBook($book, (string) $c['title'], implode(', ', (array) $c['authors']))) {
                    continue;
                }
                foreach ((array) ($c['covers'] ?? []) as $cover) {
                    $url = (string) ($cover['url'] ?? '');
                    if ($url === '') {
                        continue;
                    }
                    // Google's thumbnail, asked at its largest too.
                    if (($cover['source'] ?? '') === 'google' && str_contains($url, 'zoom=')) {
                        $add(preg_replace('/zoom=\d/', 'zoom=0', $url) ?? $url, 'google', $isbnMatch && $c['source'] === 'google');
                    }
                    $add($url, (string) ($cover['source'] ?? $c['source']), $isbnMatch && ($cover['source'] ?? '') === $c['source']);
                }
            }
        }

        $measured = [];
        foreach (array_slice(array_values($urls), 0, self::MAX_DOWNLOADS) as $candidate) {
            $image = CatalogueHttp::image($candidate['url']);
            if ($image === null) {
                continue;
            }
            $aspect = $image['width'] / max(1, $image['height']);
            if ($aspect < self::MIN_ASPECT || $aspect > self::MAX_ASPECT || $image['height'] < self::MIN_HEIGHT || static::textCard($image['bytes'])) {
                continue;
            }
            $measured[] = $candidate + ['width' => $image['width'], 'height' => $image['height'], 'bytes' => $image['bytes'], 'ext' => $image['ext']];
        }
        usort($measured, fn($a, $b) => [$b['width'] * $b['height'], $b['isbn_match']] <=> [$a['width'] * $a['height'], $a['isbn_match']]);

        // The same picture at two addresses (zoom=0 / zoom=1 answering the same size): once.
        $seen = [];

        return array_values(array_filter($measured, function ($c) use (&$seen) {
            $hash = sha1($c['bytes']);
            if (isset($seen[$hash])) {
                return false;
            }

            return $seen[$hash] = true;
        }));
    }

    /**
     * Check one book: replace its cover when that is allowed (see the class
     * comment). $mode: 'all', 'missing' (placeholders only) or 'upgrade'
     * (real covers only).
     *
     * @return array{action: string, reason: string, current: array, best: ?array}
     *         action: replaced | kept | skipped
     */
    public function check(Novel $book, string $mode = 'all', bool $dryRun = false): array
    {
        $current = $this->current($book);
        // An AI cover is as good as none here: any real one of this book replaces it.
        if ($book->cover_source === self::SOURCE_GENERATED && !$current['placeholder']) {
            $current['placeholder'] = true;
            $current['reason'] = 'an AI-painted cover';
        }

        $out = fn(string $action, string $reason, ?array $best = null) => ['action' => $action, 'reason' => $reason, 'current' => $current, 'best' => $best !== null ? array_diff_key($best, ['bytes' => 1]) : null];

        if ($book->cover_source === self::SOURCE_MANUAL) {
            return $out('skipped', 'the owner chose this cover');
        }
        if ($mode === 'missing' && !$current['placeholder']) {
            return $out('skipped', 'it has a real cover');
        }
        if ($mode === 'upgrade' && $current['placeholder']) {
            return $out('skipped', 'it has no real cover');
        }

        $candidates = $this->candidates($book);
        $best = $candidates[0] ?? null;
        if ($best === null) {
            return $out('kept', 'no catalogue cover of this book');
        }
        $area = $best['width'] * $best['height'];
        $currentArea = $current['width'] * $current['height'];
        if (!$current['placeholder']) {
            $factor = $book->cover_source === self::SOURCE_CATALOGUE || $best['isbn_match'] ? self::UPGRADE_FACTOR : self::STRICT_UPGRADE_FACTOR;
            if ($area < $currentArea * $factor) {
                return $out('kept', "the current cover is good enough ({$current['width']}×{$current['height']}, best {$best['width']}×{$best['height']})", $best);
            }
        }
        if (!$dryRun && !$this->apply($book, $best, self::SOURCE_CATALOGUE)) {
            return $out('kept', 'the new cover could not be stored', $best);
        }

        return $out('replaced', $current['placeholder'] ? ($current['reason'] ?? 'no cover') : 'a larger cover', $best);
    }

    /**
     * Make a downloaded cover the book's (attachCover(): File row,
     * thumbnails, the previous one removed), mark who chose it, and push
     * it to Calibre when the book is there.
     *
     * @param  array{bytes: string, ext: string, url?: string}  $image
     */
    public function apply(Novel $book, array $image, string $source): bool
    {
        $name = 'cover-' . $book->id . '-' . substr(sha1($image['bytes']), 0, 16) . '.' . $image['ext'];
        try {
            Storage::disk('public')->put($name, $image['bytes']);
            $book->attachCover($name, basename(parse_url((string) ($image['url'] ?? $name), PHP_URL_PATH) ?: $name));
        } catch (\Throwable $e) {
            Log::warning("Book {$book->id}: the new cover could not be stored: " . $e->getMessage());

            return false;
        }
        // No updated_at: a cover is no edit of the book's text.
        DB::table('novels')->where('id', $book->id)->update(['cover_source' => $source, 'cover_checked_at' => now(), 'cover_generated_at' => null]);
        $book->forceFill(['cover_source' => $source]);
        try {
            CacheHelper::clearNovelCache($book->id);
        } catch (\Throwable) {
        }
        \App\Jobs\PushBookToCalibre::for($book->fresh());

        return true;
    }

    /**
     * The books `books:covers` checks: never checked (or every one with
     * $recheck), or the given ids; oldest first.
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, Novel>
     */
    public static function due(array $ids, bool $recheck, int $limit)
    {
        $query = Novel::books()->with('file')->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } elseif (!$recheck) {
            // Never checked, or an AI cover not checked for a while.
            $query->where(fn($q) => $q->whereNull('cover_checked_at')
                ->orWhere(fn($g) => $g->where('cover_source', self::SOURCE_GENERATED)
                    ->where('cover_checked_at', '<', now()->subDays(self::GENERATED_RECHECK_DAYS))));
        }

        return $query->limit(max(1, $limit))->get();
    }

    public static function markChecked(Novel $book): void
    {
        DB::table('novels')->where('id', $book->id)->update(['cover_checked_at' => now()]);
    }

    /** The same book: title (normalised) equal or one inside the other, and an author word in common. */
    public static function sameBook(Novel $book, string $title, string $authors): bool
    {
        $ours = DuplicateSeries::normalize(BookLookup::cleanTitle((string) $book->name));
        $theirs = DuplicateSeries::normalize(BookLookup::cleanTitle($title));
        if ($ours === '' || $theirs === '' || mb_strlen($ours) < 3 || mb_strlen($theirs) < 3) {
            return false;
        }
        if ($ours !== $theirs && !str_contains($ours, $theirs) && !str_contains($theirs, $ours)) {
            return false;
        }

        return BookLibrary::authorsAgree((string) $book->author, $authors);
    }

    /** The ISBN in the book's ePub, or null. */
    public static function isbnOf(Novel $book): ?string
    {
        try {
            $path = BookLibrary::path($book);
            if (!is_file($path)) {
                return null;
            }
            $epub = Epub::open($path);
            $isbn = $epub->metadata()['isbn'] ?? null;
            $epub->close();
        } catch (\Throwable) {
            return null;
        }
        $isbn = preg_replace('/[^0-9X]/', '', strtoupper((string) $isbn)) ?: null;

        return $isbn !== null && Epub::validIsbn($isbn) ? $isbn : null;
    }

    /**
     * The candidates for the book page's chooser, kept for an hour so the
     * owner's choice names one by its number (never a URL from the form).
     *
     * @return list<array>  candidates() without the bytes
     */
    public function chooserCandidates(Novel $book, bool $fresh = false): array
    {
        $key = 'book_cover_choices_v1_' . $book->id;
        if (!$fresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }
        $list = array_map(fn($c) => array_diff_key($c, ['bytes' => 1]), $this->candidates($book));
        Cache::put($key, $list, now()->addHour());

        return $list;
    }
}
