<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NovelState;
use App\Http\Controllers\NovelController;
use App\Http\Helpers\CacheHelper;
use App\Novel;
use App\NovelChapter;
use App\Services\NovelHealth;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The one place the native app's JSON shapes are built (see the API v1
 * contract): the novel summary, chapter refs, timestamps and URLs.
 */
class Presenter
{
    /** Novel columns a summary reads — never `description`. */
    public const SUMMARY_COLUMNS = [
        'id', 'name', 'author', 'status', 'paused_at', 'no_of_chapters',
        'scrape_failures', 'toc_failures', 'origin', 'origin_language',
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
     * Library-wide figures every summary reads, from the caches the Library
     * page uses (plus the resume map): a fixed number of statements when cold,
     * none when warm.
     *
     * @return array{reading: array, totals: array, aggregates: array, attention: array<int, true>, resume: array<int, int>}
     */
    public static function context(): array
    {
        $reading = NovelController::libraryReading();

        return [
            'reading' => $reading['reading'],
            'totals' => $reading['totals'],
            'aggregates' => NovelController::libraryAggregates(),
            'attention' => NovelHealth::attentionIds(),
            'resume' => static::resumeChapterIds(),
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
            'author' => $novel->author ?: null,
            'cover_url' => static::url($novel->coverUrl('md')),
            // No size above md exists (CoverThumbnails::SIZES): the original is the largest.
            'cover_large_url' => static::url($novel->originalCoverUrl() ?? $novel->coverUrl('md')),
            'state' => static::state($novel, isset($context['attention'][$id])),
            'completed' => (bool) $novel->status,
            'paused' => $novel->paused_at !== null,
            'origin' => static::origin($novel),
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
        ];
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
     * Each novel's chapter to open for Continue/Start, keyed by novel id:
     * the first downloaded unread chapter after the most recently read one
     * (latest read_at; ties go to the furthest in reading order), or the
     * first downloaded chapter when nothing is read. When nothing unread is
     * downloaded after it (caught up), the last downloaded chapter. Novels
     * with nothing downloaded are absent.
     *
     * One statement for the whole library (window functions; reading order
     * is exactly NovelChapter::scopeOrdered(), NULL sort_keys first on both
     * SQLite and MySQL/MariaDB), cached like the Library aggregates.
     *
     * @return array<int, int>
     */
    public static function resumeChapterIds(): array
    {
        return Cache::remember(CacheHelper::API_RESUME_KEY, 60, function () {
            // Only rows that can be the anchor (read) or the target (downloaded).
            $ranked = DB::table('novel_chapters')
                ->where('blacklist', 0)
                ->whereNull('deleted_at')
                ->where(fn($q) => $q->where('status', 1)->orWhereNotNull('read_at'))
                ->selectRaw('id, novel_id, status, read_at, read_progress,
                    ROW_NUMBER() OVER (PARTITION BY novel_id ORDER BY book, sort_key, chapter, id) as pos');

            $anchored = DB::query()->fromSub($ranked, 'r')
                ->selectRaw('id, novel_id, status, read_at, read_progress, pos,
                    FIRST_VALUE(CASE WHEN read_at IS NOT NULL THEN pos END) OVER (
                        PARTITION BY novel_id
                        ORDER BY CASE WHEN read_at IS NULL THEN 1 ELSE 0 END, read_at DESC, pos DESC
                    ) as anchor');

            // 0 = a chapter abandoned part-way (the web Continue list resumes
            // it too: HomeController::continueReading()), most recently read
            // first; 1 = downloaded, unread and after the anchor; 2 = any
            // other downloaded row (the fallback takes the last of them).
            $prio = 'CASE WHEN status = 1 AND read_at IS NOT NULL AND read_progress IS NOT NULL AND read_progress < 90 THEN 0
                WHEN status = 1 AND read_at IS NULL AND (anchor IS NULL OR pos > anchor) THEN 1
                WHEN status = 1 THEN 2 ELSE 3 END';

            $picked = DB::query()->fromSub($anchored, 'a')
                ->selectRaw("id, novel_id, {$prio} as prio,
                    ROW_NUMBER() OVER (
                        PARTITION BY novel_id
                        ORDER BY {$prio}, CASE WHEN {$prio} = 0 THEN read_at END DESC,
                            CASE WHEN {$prio} = 1 THEN pos ELSE 0 END, pos DESC
                    ) as rn");

            return DB::query()->fromSub($picked, 'p')
                ->where('rn', 1)
                ->where('prio', '<', 3)
                ->pluck('id', 'novel_id')
                ->mapWithKeys(fn($id, $novelId) => [(int) $novelId => (int) $id])
                ->all();
        });
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
