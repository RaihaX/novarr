<?php

namespace App\Services;

use App\ChapterRead;
use App\Http\Helpers\CacheHelper;
use App\NovelChapter;
use App\Support\CurrentReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-reader read state: whether a chapter is read (and when), how far in it
 * the reader is (0–100) and when that position was written. The only place
 * that reads or writes chapter_reads; everything else asks here or uses the
 * query helpers below.
 *
 * Every method takes an optional user id and otherwise uses the current
 * reader (App\Support\CurrentReader), resolved per call — never kept on the
 * instance, which controllers hold across requests under Octane.
 *
 * Writes forget the reader's own derived caches (CacheHelper::
 * clearReaderCache()) under the same conditions the single-user code cleared
 * them, so a read never invalidates another reader's caches.
 */
class ReadState
{
    public const TABLE = 'chapter_reads';

    /** Prefix of the columns join() selects, turned back into state by attachJoined(). */
    private const JOINED_PREFIX = 'cr_';

    private const JOINED_COLUMNS = ['novel_chapter_id', 'read_at', 'progress', 'progress_at'];

    /** Chapter ids per whereIn() when loading state (well under SQLite's bound-variable limit). */
    private const LOAD_CHUNK = 1000;

    public function userId(?int $userId = null): int
    {
        return $userId ?? app(CurrentReader::class)->id();
    }

    // ---------------------------------------------------------------------
    // Reading one chapter's / a list's state
    // ---------------------------------------------------------------------

    /** The reader's state of $chapter (null = never touched), also attached to it. */
    public function get(NovelChapter $chapter, ?int $userId = null): ?ChapterRead
    {
        $state = ChapterRead::where('user_id', $this->userId($userId))
            ->where('novel_chapter_id', $chapter->id)
            ->first();
        $chapter->setReadState($state);

        return $state;
    }

    /**
     * Attach the reader's state to every chapter in $chapters (models with
     * an id): one primary-key lookup per LOAD_CHUNK chapters, or — when the
     * caller knows they all belong to $novelId — one (user_id, novel_id)
     * range read.
     *
     * @param  iterable<NovelChapter>  $chapters
     */
    public function load(iterable $chapters, ?int $userId = null, ?int $novelId = null): void
    {
        $list = [];
        foreach ($chapters as $chapter) {
            $list[] = $chapter;
        }
        if ($list === []) {
            return;
        }
        $userId = $this->userId($userId);

        if ($novelId !== null) {
            $rows = ChapterRead::where('user_id', $userId)->where('novel_id', $novelId)->get()->keyBy('novel_chapter_id');
        } else {
            $rows = collect();
            foreach (array_chunk(array_map(fn($c) => (int) $c->id, $list), self::LOAD_CHUNK) as $ids) {
                $rows = $rows->union(ChapterRead::where('user_id', $userId)->whereIn('novel_chapter_id', $ids)->get()->keyBy('novel_chapter_id'));
            }
        }

        foreach ($list as $chapter) {
            $chapter->setReadState($rows->get((int) $chapter->id));
        }
    }

    /**
     * Join the reader's chapter_reads row to a NovelChapter query as `cr`
     * (left: chapters without one stay; inner: only touched chapters) and
     * select $columns (novel_chapters columns, qualified here) plus the
     * row's columns as cr_*, for filters / ordering on read state and for
     * attachJoined(). The join is on the primary key (user_id,
     * novel_chapter_id). Qualify novel_chapters columns in other clauses of
     * such a query that chapter_reads shares (novel_id).
     *
     * @param  string[]  $columns
     */
    public function join(Builder $query, array $columns, ?int $userId = null, string $type = 'left'): Builder
    {
        $userId = $this->userId($userId);
        $query->join(self::TABLE . ' as cr', function ($j) use ($userId) {
            $j->on('cr.novel_chapter_id', '=', 'novel_chapters.id')->where('cr.user_id', '=', $userId);
        }, null, null, $type);

        $query->select(array_map(fn($c) => str_contains($c, '.') ? $c : "novel_chapters.{$c}", $columns));
        foreach (self::JOINED_COLUMNS as $column) {
            $query->addSelect("cr.{$column} as " . self::JOINED_PREFIX . $column);
        }

        return $query;
    }

    /**
     * Turn the cr_* columns of chapters fetched through join() into their
     * attached state, and drop them from the attributes (so they are never
     * saved, serialised or cached as columns).
     *
     * @param  iterable<NovelChapter>  $chapters
     */
    public function attachJoined(iterable $chapters): void
    {
        foreach ($chapters as $chapter) {
            $attributes = $chapter->getAttributes();
            $row = [];
            foreach (self::JOINED_COLUMNS as $column) {
                $row[$column] = $attributes[self::JOINED_PREFIX . $column] ?? null;
                unset($attributes[self::JOINED_PREFIX . $column]);
            }
            $chapter->setRawAttributes($attributes, true);

            $state = null;
            if ($row['novel_chapter_id'] !== null) {
                $state = (new ChapterRead())->setRawAttributes($row + [
                    'novel_id' => $attributes['novel_id'] ?? null,
                ], true);
            }
            $chapter->setReadState($state);
        }
    }

    /**
     * Keep chapters the reader has read: an EXISTS probe on chapter_reads'
     * primary key per chapter. Works on any query over novel_chapters.
     */
    public function whereRead(Builder|QueryBuilder $query, ?int $userId = null): Builder|QueryBuilder
    {
        return $query->whereExists($this->readProbe($this->userId($userId)));
    }

    /**
     * Keep chapters the reader has not read (no row, or a row with no
     * read_at): a NOT EXISTS probe on chapter_reads' primary key. Over a
     * novel's downloaded chapters in reading order it is served by
     * novel_chapters.idx_reading_order, stopping at the first unread.
     */
    public function whereUnread(Builder|QueryBuilder $query, ?int $userId = null): Builder|QueryBuilder
    {
        return $query->whereNotExists($this->readProbe($this->userId($userId)));
    }

    private function readProbe(int $userId): \Closure
    {
        return fn($q) => $q->from(self::TABLE . ' as rp')
            ->selectRaw('1')
            ->where('rp.user_id', $userId)
            ->whereColumn('rp.novel_chapter_id', 'novel_chapters.id')
            ->whereNotNull('rp.read_at');
    }

    /**
     * Correlated EXISTS for a query over novels: has the reader read any
     * (not soft-deleted) chapter of `novels.id`? Index (user_id, novel_id,
     * read_at), stopping at the first row.
     */
    public function startedNovelSubquery(?int $userId = null): QueryBuilder
    {
        return DB::table(self::TABLE . ' as sr')
            ->join('novel_chapters as sc', 'sc.id', '=', 'sr.novel_chapter_id')
            ->selectRaw('1')
            ->where('sr.user_id', $this->userId($userId))
            ->whereColumn('sr.novel_id', 'novels.id')
            ->whereNotNull('sr.read_at')
            ->whereNull('sc.deleted_at')
            ->limit(1);
    }

    // ---------------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------------

    /**
     * Opening a downloaded chapter: mark it read now unless it already is
     * (its in-chapter position is left alone). Returns whether it was marked.
     */
    public function markOpened(NovelChapter $chapter, ?int $userId = null): bool
    {
        $userId = $this->userId($userId);
        $now = now();

        $marked = DB::table(self::TABLE)->insertOrIgnore($this->row($chapter, $userId, ['read_at' => $now])) > 0
            || DB::table(self::TABLE)->where('user_id', $userId)->where('novel_chapter_id', $chapter->id)
                ->whereNull('read_at')->update(['read_at' => $now]) > 0;

        $this->get($chapter, $userId);
        if ($marked) {
            CacheHelper::clearReaderCache($userId, [(int) $chapter->novel_id]);
        }

        return $marked;
    }

    /**
     * An explicit mark read / unread. It also settles the in-chapter
     * position — done (100) when read, forgotten when unread — and its time
     * supersedes positions recorded before $recordedAt. A chapter already
     * read keeps its original date.
     */
    public function setRead(NovelChapter $chapter, bool $read, CarbonInterface $recordedAt, ?int $userId = null): ?ChapterRead
    {
        $userId = $this->userId($userId);
        $current = $this->get($chapter, $userId);

        $this->put($chapter, $userId, [
            'read_at' => $read ? ($current?->read_at ?? now()) : null,
            'progress' => $read ? 100 : null,
            'progress_at' => $recordedAt,
        ]);
        CacheHelper::clearReaderCache($userId, [(int) $chapter->novel_id]);

        return $this->get($chapter, $userId);
    }

    /**
     * A reader position write: $progress (0–100, null = none sent) recorded
     * at $at, and optionally "mark it read now" ($markRead; only a
     * downloaded, unread chapter is marked). A position older than the
     * stored one is ignored (offline replays carry their own time); the
     * read mark still applies. Returns whether the position was stale.
     */
    public function recordProgress(NovelChapter $chapter, ?int $progress, bool $markRead, CarbonInterface $at, ?int $userId = null): bool
    {
        $userId = $this->userId($userId);
        $current = $this->get($chapter, $userId);

        $values = [];
        $stale = false;
        if ($progress !== null) {
            $storedAt = $current?->progress_at;
            if (!$storedAt || !$at->lt($storedAt)) {
                $values['progress'] = $progress;
                $values['progress_at'] = $at;
            } else {
                $stale = true;
            }
        }
        $markRead = $markRead && $chapter->status && $current?->read_at === null;
        if ($markRead) {
            $values['read_at'] = now();
        }

        if ($values !== []) {
            $this->put($chapter, $userId, $values);
            $this->get($chapter, $userId);
        }
        if ($markRead) {
            CacheHelper::clearReaderCache($userId, [(int) $chapter->novel_id]);
        }

        return $stale;
    }

    /**
     * Mark every chapter $chapters selects (a NovelChapter query) read or
     * unread, in two set-based statements: update the reader's existing
     * rows, insert the missing ones. Read = read now, position 100; unread =
     * no date, no position. Either way the position time becomes
     * $recordedAt. With $onlyUnread, chapters already read are left exactly
     * as they are (their date is kept). Returns how many chapters changed.
     */
    public function markMany(Builder $chapters, bool $read, CarbonInterface $recordedAt, bool $onlyUnread = false, ?int $userId = null): int
    {
        $userId = $this->userId($userId);
        $values = [
            'read_at' => $read ? now() : null,
            'progress' => $read ? 100 : null,
            'progress_at' => $recordedAt,
        ];
        // toBase() applies the soft-delete scope.
        $base = $chapters->toBase();

        $count = DB::transaction(function () use ($base, $values, $userId, $onlyUnread) {
            $updated = DB::table(self::TABLE)
                ->where('user_id', $userId)
                ->whereIn('novel_chapter_id', (clone $base)->select('novel_chapters.id'))
                ->when($onlyUnread, fn($q) => $q->whereNull('read_at'))
                ->update($values);

            $inserted = DB::table(self::TABLE)->insertOrIgnoreUsing(
                ['user_id', 'novel_id', 'novel_chapter_id', 'read_at', 'progress', 'progress_at'],
                (clone $base)
                    ->selectRaw('?, novel_chapters.novel_id, novel_chapters.id, ?, ?, ?', [$userId, $values['read_at'], $values['progress'], $values['progress_at']])
                    ->whereNotExists(fn($q) => $q->from(self::TABLE . ' as ex')
                        ->selectRaw('1')
                        ->where('ex.user_id', $userId)
                        ->whereColumn('ex.novel_chapter_id', 'novel_chapters.id'))
            );

            return $updated + $inserted;
        });

        $novelIds = (clone $base)->distinct()->pluck('novel_chapters.novel_id')->map(fn($id) => (int) $id)->all();
        CacheHelper::clearReaderCache($userId, $novelIds);

        return $count;
    }

    /** Insert or update the reader's row for $chapter with $values. */
    private function put(NovelChapter $chapter, int $userId, array $values): void
    {
        DB::table(self::TABLE)->upsert($this->row($chapter, $userId, $values), ['user_id', 'novel_chapter_id'], array_keys($values));
    }

    private function row(NovelChapter $chapter, int $userId, array $values): array
    {
        return [
            'user_id' => $userId,
            'novel_id' => (int) $chapter->novel_id,
            'novel_chapter_id' => (int) $chapter->id,
        ] + $values;
    }

    // ---------------------------------------------------------------------
    // Aggregates
    // ---------------------------------------------------------------------

    /**
     * Per novel: chapters the reader has read ('read') and how many of them
     * are downloaded ('read_downloaded'), counting non-blacklisted, not
     * soft-deleted chapters. One grouped statement: (user_id, novel_id,
     * read_at) range, chapter rows by primary key.
     *
     * @param  int[]  $novelIds
     * @return array<int, array{read:int, read_downloaded:int}>
     */
    public function readCounts(array $novelIds, ?int $userId = null): array
    {
        if ($novelIds === []) {
            return [];
        }

        return $this->readRows($this->userId($userId))
            ->whereIn('cr.novel_id', array_values(array_unique(array_map('intval', $novelIds))))
            ->where('c.blacklist', 0)
            ->groupBy('cr.novel_id')
            ->selectRaw('cr.novel_id as novel_id, COUNT(*) as read_count,
                SUM(CASE WHEN c.status = 1 THEN 1 ELSE 0 END) as read_downloaded')
            ->get()
            ->mapWithKeys(fn($r) => [(int) $r->novel_id => [
                'read' => (int) $r->read_count,
                'read_downloaded' => (int) $r->read_downloaded,
            ]])
            ->all();
    }

    /**
     * The Library's per-novel read state for one reader, over not
     * soft-deleted chapters: 'read' (non-blacklisted chapters read),
     * 'last_read' (latest read of any chapter — the "read" sort) and 'new'
     * (downloaded, non-blacklisted, unread chapters that arrived after the
     * latest read of a non-blacklisted chapter). One statement:
     *  - lr: the reader's read rows grouped per novel, a range on
     *    chapter_reads (user_id, novel_id, read_at), chapters by key;
     *  - left-joined per started novel: its chapters downloaded after that
     *    read, a range on idx_novel_download_date (novel_id, download_date),
     *    each with a NOT EXISTS probe on chapter_reads' primary key.
     *
     * @return array<int, array{read:int, last_read:?string, new:int}>
     */
    public function libraryState(?int $userId = null): array
    {
        $userId = $this->userId($userId);

        $perNovel = $this->readRows($userId)
            ->groupBy('cr.novel_id')
            ->selectRaw('cr.novel_id as novel_id,
                SUM(CASE WHEN c.blacklist = 0 THEN 1 ELSE 0 END) as read_count,
                MAX(cr.read_at) as last_read,
                MAX(CASE WHEN c.blacklist = 0 THEN cr.read_at END) as last_visible');

        $rows = DB::query()->fromSub($perNovel, 'lr')
            ->leftJoin('novel_chapters as n', function ($j) use ($userId) {
                $j->on('n.novel_id', '=', 'lr.novel_id')
                    ->on('n.download_date', '>', 'lr.last_visible')
                    ->where('n.status', '=', 1)
                    ->where('n.blacklist', '=', 0)
                    ->whereNull('n.deleted_at')
                    ->whereNotExists(fn($q) => $q->from(self::TABLE . ' as nr')
                        ->selectRaw('1')
                        ->where('nr.user_id', $userId)
                        ->whereColumn('nr.novel_chapter_id', 'n.id')
                        ->whereNotNull('nr.read_at'));
            })
            ->groupBy('lr.novel_id', 'lr.read_count', 'lr.last_read')
            ->selectRaw('lr.novel_id as novel_id, lr.read_count as read_count, lr.last_read as last_read, COUNT(n.id) as new_count')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->novel_id] = [
                'read' => (int) $r->read_count,
                'last_read' => $r->last_read !== null ? (string) $r->last_read : null,
                'new' => (int) $r->new_count,
            ];
        }

        return $out;
    }

    /**
     * NovelChapter query over the chapters the reader has read (not
     * soft-deleted), joined as `cr` — for stats. Served by (user_id, read_at).
     */
    public function readChapters(?int $userId = null): Builder
    {
        return NovelChapter::query()
            ->join(self::TABLE . ' as cr', 'cr.novel_chapter_id', '=', 'novel_chapters.id')
            ->where('cr.user_id', $this->userId($userId))
            ->whereNotNull('cr.read_at');
    }

    /**
     * The reader's read rows (read_at set) joined to their not soft-deleted
     * chapters as `c`.
     */
    private function readRows(int $userId): QueryBuilder
    {
        return DB::table(self::TABLE . ' as cr')
            ->join('novel_chapters as c', 'c.id', '=', 'cr.novel_chapter_id')
            ->where('cr.user_id', $userId)
            ->whereNotNull('cr.read_at')
            ->whereNull('c.deleted_at');
    }

    /**
     * Novels among $novelIds that ANY reader read at or after $since, with
     * the Unix time of the latest such read — the download sweep's priority.
     * Soft-deleted chapters count, as they always did. Range read on
     * (novel_id, read_at).
     *
     * @param  int[]  $novelIds
     * @return array<int, int>
     */
    public function recentlyReadByAnyone(array $novelIds, CarbonInterface $since): array
    {
        if ($novelIds === []) {
            return [];
        }

        return DB::table(self::TABLE)
            ->whereIn('novel_id', $novelIds)
            ->where('read_at', '>=', $since)
            ->groupBy('novel_id')
            ->selectRaw('novel_id, MAX(read_at) as last_read')
            ->pluck('last_read', 'novel_id')
            ->mapWithKeys(fn($at, $id) => [(int) $id => Carbon::parse($at)->getTimestamp()])
            ->all();
    }

    // ---------------------------------------------------------------------
    // Cleanup (hard deletes; see the model hooks)
    // ---------------------------------------------------------------------

    public function forgetChapter(int $chapterId): void
    {
        DB::table(self::TABLE)->where('novel_chapter_id', $chapterId)->delete();
    }

    public function forgetNovel(int $novelId): void
    {
        DB::table(self::TABLE)->where('novel_id', $novelId)->delete();
    }

    public function forgetUser(int $userId): void
    {
        DB::table(self::TABLE)->where('user_id', $userId)->delete();
    }
}
