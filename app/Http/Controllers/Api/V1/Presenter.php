<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NovelState;
use App\Http\Controllers\NovelController;
use App\Novel;
use App\NovelChapter;
use App\Services\NovelHealth;
use App\Services\ReadState;
use App\Services\ResumePoint;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The one place the native app's JSON shapes are built (see the API v1
 * contract): the novel summary, chapter refs, timestamps and URLs.
 */
class Presenter
{
    /** Novel columns a summary reads — never `description`. */
    public const SUMMARY_COLUMNS = [
        'id', 'name', 'author', 'status', 'paused_at', 'no_of_chapters',
        'scrape_failures', 'toc_failures', 'origin', 'origin_language', 'medium',
        'origin_source', 'origin_confidence', 'series', 'series_index',
    ];

    /**
     * Novels (with cover and tags) ready for summary(), keyed by id. Three
     * statements whatever the count: novels, files, tags.
     *
     * @param  int[]|null  $ids  null = the whole library
     * @return Collection<int, Novel>
     */
    public static function loadNovels(?array $ids = null, array $extraColumns = []): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Novel::with(['file', 'tags' => fn($q) => $q->select('tags.id', 'tags.name')->orderBy('tags.name')])
            ->select(array_merge(self::SUMMARY_COLUMNS, $extraColumns))
            ->when($ids !== null, fn($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * Library-wide figures every summary reads, for the current reader, from
     * the caches the Library page uses (plus the resume map): a fixed number
     * of statements when cold, none when warm.
     *
     * @return array{reading: array, totals: array, aggregates: array, attention: array<int, true>, resume: array<int, int>, want_to_read: array<int, true>, favourites: array<int, true>, audiobooks: array<int, array{id: int, duration_ms: int}>}
     */
    public static function context(): array
    {
        $userId = app(ReadState::class)->userId();
        $reading = NovelController::libraryReading($userId);
        // The reader's built-in lists' ids, "Want to read" and "Favourites"
        // (one statement for both, no list made).
        $builtIn = \App\Services\ReadingLists::builtInIds($userId);

        return [
            'reading' => $reading['reading'],
            'totals' => $reading['totals'],
            'aggregates' => NovelController::libraryAggregates($userId),
            'attention' => NovelHealth::attentionIds(),
            'resume' => static::resumeChapterIds($userId),
            'want_to_read' => $builtIn[\App\ReadingList::KIND_WANT_TO_READ],
            'favourites' => $builtIn[\App\ReadingList::KIND_FAVOURITES],
            // Books' audiobooks: novel id => {id, duration_ms} (cached until an audiobook changes).
            'audiobooks' => \App\Audiobooks\AudiobookLibrary::novelMap(),
        ];
    }

    public static function summary(Novel $novel, array $context): array
    {
        $id = (int) $novel->id;
        $r = $context['reading'][$id] ?? ['read' => 0, 'new' => 0, 'total' => 0, 'percent' => 0, 'finished' => false];
        $a = $context['aggregates'][$id] ?? ['downloaded' => 0, 'source' => 0, 'last_read' => null, 'last_download' => null];
        $download = NovelHealth::downloadProgress((int) $a['downloaded'], (int) $a['source'], (int) ($novel->no_of_chapters ?? 0));

        return [
            'id' => $id,
            'name' => $novel->name,
            // 'novel' or 'manga' (manga are listed only on request: ?medium=).
            'medium' => (string) ($novel->medium ?: Novel::MEDIUM_NOVEL),
            'author' => $novel->author ?: null,
            'cover_url' => static::url($novel->coverUrl('md')),
            // No size above md exists (CoverThumbnails::SIZES): the original is the largest.
            'cover_large_url' => static::url($novel->originalCoverUrl() ?? $novel->coverUrl('md')),
            'state' => static::state($novel, isset($context['attention'][$id])),
            'completed' => (bool) $novel->status,
            'paused' => $novel->paused_at !== null,
            'origin' => static::origin($novel),
            // 'JP' | 'KR' | 'CN' | 'EN' | null — Novel::originCountry().
            'origin_country' => $novel->originCountry(),
            'tags' => $novel->tags->map(fn($t) => ['id' => (int) $t->id, 'name' => $t->name])->values()->all(),
            'chapters_total' => (int) $r['total'],
            'chapters_downloaded' => (int) $a['downloaded'],
            'chapters_read' => (int) $r['read'],
            'chapters_new' => (int) $r['new'],
            'read_percent' => (int) $r['percent'],
            'download_percent' => (int) $download['percent'],
            'finished' => (bool) $r['finished'],
            'last_read_at' => static::timestamp($a['last_read']),
            'updated_at' => static::timestamp($a['last_download']),
            'resume_chapter_id' => $context['resume'][$id] ?? null,
            // A book's series and its number in it (App\Books\BookSeries);
            // null when none ('' = the owner's "no series" is none too).
            'series' => (string) $novel->series !== '' ? (string) $novel->series : null,
            'series_index' => (string) $novel->series !== '' && $novel->series_index !== null ? (float) $novel->series_index : null,
            // On the reader's "Want to read" (the built-in reading list).
            'want_to_read' => isset($context['want_to_read'][$id]),
            // Its audiobook (App\Audiobook), or null: {id, duration_ms}.
            'audiobook' => ($context['audiobooks'] ?? \App\Audiobooks\AudiobookLibrary::novelMap())[$id] ?? null,
            // In the reader's "Favourites" (the built-in reading list, the heart).
            'favourite' => isset($context['favourites'][$id]),
        ];
    }

    /**
     * The reader's "Want to read" of $medium (MEDIA), newest first, the
     * first $limit (null: all): [{novel: summary, added_at, started}].
     * started: the reader has read a chapter of it or has progress in one
     * (it stays on the list either way). A fixed number of statements.
     *
     * @return list<array{novel: array, added_at: ?string, started: bool}>
     */
    public static function wantToReadItems(int $userId, string $medium, ?int $limit = null, ?array $context = null): array
    {
        return static::wantToReadShelf($userId, $medium, $limit, $context)['items'];
    }

    /** wantToReadItems() and how many there are of $medium in all. @return array{count:int, items:list<array>} */
    public static function wantToReadShelf(int $userId, string $medium, ?int $limit = null, ?array $context = null): array
    {
        return static::builtInShelf($userId, \App\ReadingList::KIND_WANT_TO_READ, $medium, $limit, $context);
    }

    /** The reader's "Favourites" of $medium, newest first: as wantToReadItems(). */
    public static function favouriteItems(int $userId, string $medium, ?int $limit = null, ?array $context = null): array
    {
        return static::builtInShelf($userId, \App\ReadingList::KIND_FAVOURITES, $medium, $limit, $context)['items'];
    }

    /**
     * A built-in reading list of $kind (App\ReadingList::BUILT_IN) of
     * $medium, newest first, as summaries, and how many there are in all.
     *
     * @return array{count:int, items:list<array{novel: array, added_at: ?string, started: bool}>}
     */
    public static function builtInShelf(int $userId, string $kind, string $medium, ?int $limit = null, ?array $context = null): array
    {
        $shelf = \App\Services\ReadingLists::itemsOfKind($userId, $kind, $medium === 'all' ? null : static::mediaFor($medium), $limit);
        if ($shelf['items'] === []) {
            return $shelf;
        }
        $context ??= static::context();
        $novels = static::loadNovels(array_column($shelf['items'], 'novel_id'));

        $items = [];
        foreach ($shelf['items'] as $item) {
            if ($novel = $novels->get($item['novel_id'])) {
                $items[] = [
                    'novel' => static::summary($novel, $context),
                    'added_at' => static::timestamp($item['added_at']),
                    'started' => $item['started'],
                ];
            }
        }

        return ['count' => $shelf['count'], 'items' => $items];
    }

    /**
     * Keep the novels of the medium a client asked for (LibraryController's
     * ?medium=): 'novel' (the default — the iPhone app and the KOReader
     * plugin can't show pages), 'manga' or 'all'.
     */
    /**
     * The listing a client asks for (?medium=): novel (the default, as old
     * clients expect), manga, book, text (novels and books: everything an
     * e-reader shows as text) or all.
     */
    public const MEDIA = ['novel', 'manga', 'book', 'text', 'all', 'comic'];

    public static function medium(\Illuminate\Http\Request $request): string
    {
        $request->validate(['medium' => 'nullable|in:' . implode(',', self::MEDIA)]);
        $medium = $request->query('medium');

        return in_array($medium, self::MEDIA, true) ? $medium : 'novel';
    }

    /** Does a novel of $novelMedium belong in a listing for $medium? */
    public static function inMedium(?string $novelMedium, string $medium): bool
    {
        $novelMedium = $novelMedium ?: Novel::MEDIUM_NOVEL;

        return match ($medium) {
            'all' => true,
            'text' => !in_array($novelMedium, Novel::PAGED_MEDIA, true),
            default => $novelMedium === $medium,
        };
    }

    /** The novels table's media a listing for $medium covers. */
    public static function mediaFor(string $medium): array
    {
        return match ($medium) {
            'all' => [Novel::MEDIUM_NOVEL, Novel::MEDIUM_MANGA, Novel::MEDIUM_BOOK, Novel::MEDIUM_COMIC],
            'text' => [Novel::MEDIUM_NOVEL, Novel::MEDIUM_BOOK],
            default => [$medium],
        };
    }

    /**
     * The Library's status for a novel: its lifecycle state, with the grid's
     * red "failed" flag for a needs-attention novel whose scrapes or TOC
     * runs keep failing.
     */
    public static function state(Novel $novel, bool $attention): string
    {
        $state = NovelState::forNovel($novel, $attention);
        if ($state === NovelState::Attention && ((int) $novel->scrape_failures >= 3 || (int) $novel->toc_failures >= 2)) {
            $state = NovelState::Failed;
        }

        return $state->value;
    }

    /** The grid caption: "TRANSLATED · ZH" reads "Translated · ZH". */
    public static function origin(Novel $novel): ?string
    {
        $chip = $novel->originChip();
        if ($chip === null) {
            return null;
        }
        $parts = explode(' · ', $chip);
        $parts[0] = ucfirst(strtolower($parts[0]));

        return implode(' · ', $parts);
    }

    /**
     * Each novel's chapter to open for Continue/Start for a reader (default:
     * the current one), keyed by novel id — App\Services\ResumePoint, the
     * rule every surface shares: the chapter with the reader's most recent
     * activity when it is unfinished, else the first downloaded unread
     * chapter after it, the first downloaded chapter when nothing is read or
     * started, and the last downloaded chapter when caught up. Novels with
     * nothing downloaded are absent. One statement, cached per reader.
     *
     * @return array<int, int>
     */
    public static function resumeChapterIds(?int $userId = null): array
    {
        return app(ResumePoint::class)->libraryChapterIds($userId);
    }

    /** `{"id","label","chapter","book"}`, plus `downloaded` for reader prev/next. */
    public static function chapterRef(NovelChapter $chapter, bool $withDownloaded = false): array
    {
        $ref = [
            'id' => (int) $chapter->id,
            'label' => static::label($chapter),
            'chapter' => static::number($chapter->chapter),
            'book' => (int) $chapter->book,
        ];
        if ($withDownloaded) {
            $ref['downloaded'] = (bool) $chapter->status;
        }

        return $ref;
    }

    /** Never null: the reader's own fallback. */
    public static function label(NovelChapter $chapter): string
    {
        return $chapter->label ?: 'Chapter ' . static::number($chapter->chapter);
    }

    /** A chapter number as JSON: 12 for a whole number, 12.5 otherwise. */
    public static function number($value): int|float
    {
        $value = (float) $value;

        return floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
    }

    /** ISO-8601 UTC with a Z and no fractional seconds, or null. DB strings are in the app timezone. */
    public static function timestamp($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $at = $value instanceof CarbonInterface
            ? $value->copy()
            : Carbon::parse((string) $value, config('app.timezone'));

        return $at->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * An absolute URL on the origin the request came in on (Storage::url()
     * builds on APP_URL, which a device on the tailnet may not reach).
     */
    public static function url(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return url($path) . ($query ? '?' . $query : '');
    }
}
