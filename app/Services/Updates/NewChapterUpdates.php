<?php

namespace App\Services\Updates;

use App\Http\Controllers\Api\V1\Presenter;
use App\NovelChapter;
use App\Services\ReadState;
use App\Services\ResumePoint;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * "New chapters to read" for the phone apps' one combined notification
 * (GET /api/v1/updates?since=<cursor>). Built so a backlog download can
 * never spam: a reader hears about a series only when they had nothing left
 * to read in it at `since` and now do.
 *
 * Over the series' downloaded (status 1), non-blacklisted, not soft-deleted
 * chapters, the reader's AHEAD chapters follow App\Services\ResumePoint:
 *  - NEXT (finished anchor A, resume chapter R = first unread after A) and
 *    START (no activity, R = first unread): R and every unread chapter after
 *    R in reading order (= every unread chapter after A, or every unread
 *    chapter at all for START);
 *  - RESUME (anchor A part-way through): the unread chapters after A — A
 *    itself is not ahead (it was there before), so "part-way through the last
 *    chapter" has nothing ahead until a new one arrives;
 *  - CAUGHT_UP: none.
 * A series is listed when the reader has started it (a chapter_reads row
 * read or with progress ≥ 1 — hand marks count), it is not soft-deleted, it
 * has ahead chapters, and the OLDEST ahead chapter's download_date lies in
 * [since, T) where T is this answer's time (the cursor it returns). So every
 * ahead chapter arrived after `since` (the reader was caught up then), and
 * the windows of successive polls never overlap or leave gaps: each "had
 * nothing, now has something" moment is reported exactly once. download_date
 * is stored to the second, so T is the start of the current second and a
 * chapter stamped in it belongs to the next poll. A NULL download_date counts
 * as old.
 *
 * Statements: the candidate novels (1), ResumePoint::forNovels() (2, or 3–4
 * when a candidate's every chapter is marked read by hand), the ahead
 * chapters with their novel (1) — the same whatever the number of series.
 * No source is ever called.
 */
class NewChapterUpdates
{
    /** Series in one answer, newest first. */
    public const LIMIT = 25;

    /** Candidate series examined per answer: the most recently downloaded-to. */
    public const CANDIDATES = 200;

    private const PREFIX = 'nu1:';

    public function __construct(private ResumePoint $resume, private ReadState $readState)
    {
    }

    /**
     * @return array{cursor: string, series: array<int, array>}
     */
    public function read(?CarbonInterface $since, ?int $userId = null): array
    {
        $userId = $this->readState->userId($userId);
        // The start of this second: chapters stamped in it go to the next poll.
        $until = Carbon::createFromTimestamp(now()->getTimestamp(), config('app.timezone'));
        $cursor = static::encode($until);

        if ($since === null || $since->greaterThanOrEqualTo($until)) {
            return ['cursor' => $cursor, 'series' => []];
        }
        $since = Carbon::createFromTimestamp($since->getTimestamp(), config('app.timezone'));
        $sinceSql = $since->format('Y-m-d H:i:s');
        $untilSql = $until->format('Y-m-d H:i:s');

        // 1. Candidates: started, not soft-deleted series with a chapter
        //    downloaded in the window (range on idx_download_date).
        $candidates = DB::table('novel_chapters as c')
            ->join('novels as n', 'n.id', '=', 'c.novel_id')
            ->whereNull('n.deleted_at')
            ->where('c.download_date', '>=', $sinceSql)
            ->where('c.download_date', '<', $untilSql)
            ->where('c.status', 1)
            ->where('c.blacklist', 0)
            ->whereNull('c.deleted_at')
            ->whereExists(fn($q) => $q->from(ReadState::TABLE . ' as s')
                ->selectRaw('1')
                ->where('s.user_id', $userId)
                ->whereColumn('s.novel_id', 'c.novel_id')
                ->where(fn($w) => $w->whereNotNull('s.read_at')->orWhere('s.progress', '>=', 1)))
            ->groupBy('c.novel_id')
            ->orderByRaw('MAX(c.download_date) DESC')
            ->orderByDesc('c.novel_id')
            ->limit(self::CANDIDATES)
            ->pluck('c.novel_id')
            ->map(fn($id) => (int) $id)
            ->all();
        if ($candidates === []) {
            return ['cursor' => $cursor, 'series' => []];
        }

        // 2. Where each one resumes for this reader.
        $points = $this->resume->forNovels($candidates, ['download_date'], $userId);

        // 3. Ahead chapters of the series that can still qualify. For NEXT /
        //    START the resume chapter is the first ahead chapter: one stamped
        //    before the window rules the series out without a query (the
        //    "500 chapters behind" case).
        $bases = [];
        foreach ($points as $novelId => $point) {
            $chapter = $point['chapter'];
            if ($chapter === null || $point['kind'] === ResumePoint::CAUGHT_UP) {
                continue;
            }
            if ($point['kind'] !== ResumePoint::RESUME
                && ($chapter->download_date === null || $chapter->download_date->lt($since))) {
                continue;
            }
            $bases[$novelId] = $point;
        }
        if ($bases === []) {
            return ['cursor' => $cursor, 'series' => []];
        }

        $ahead = NovelChapter::query()
            ->where('status', 1)
            ->where('blacklist', 0)
            ->where(function ($all) use ($bases) {
                foreach ($bases as $novelId => $point) {
                    $chapter = $point['chapter'];
                    $all->orWhere(function ($one) use ($novelId, $point, $chapter) {
                        $one->where('novel_id', $novelId);
                        if ($point['kind'] === ResumePoint::RESUME) {
                            $one->relativeTo($chapter, '>');
                        } else {
                            $one->where(fn($r) => $r->where('id', (int) $chapter->id)->orWhere(fn($after) => $after->relativeTo($chapter, '>')));
                        }
                    });
                }
            });
        $this->readState->whereUnread($ahead, $userId);
        $ahead->selectRaw("novel_chapters.id as id, novel_chapters.novel_id as novel_id, novel_chapters.label as label,
            novel_chapters.chapter as chapter, novel_chapters.book as book,
            ROW_NUMBER() OVER (PARTITION BY novel_chapters.novel_id ORDER BY novel_chapters.book, novel_chapters.sort_key, novel_chapters.chapter, novel_chapters.id) as pos,
            COUNT(*) OVER (PARTITION BY novel_chapters.novel_id) as new_count,
            MIN(COALESCE(novel_chapters.download_date, '1970-01-01 00:00:00')) OVER (PARTITION BY novel_chapters.novel_id) as oldest,
            MAX(novel_chapters.download_date) OVER (PARTITION BY novel_chapters.novel_id) as newest");

        $rows = DB::query()->fromSub($ahead->toBase(), 'a')
            ->join('novels as n', 'n.id', '=', 'a.novel_id')
            ->whereNull('n.deleted_at')
            ->where('a.pos', 1)
            ->where('a.oldest', '>=', $sinceSql)
            ->where('a.oldest', '<', $untilSql)
            ->orderByDesc('a.newest')
            ->orderByDesc('a.novel_id')
            ->limit(self::LIMIT)
            ->get(['a.id', 'a.novel_id', 'a.label', 'a.chapter', 'a.book', 'a.new_count', 'a.newest', 'n.name', 'n.medium']);

        return [
            'cursor' => $cursor,
            'series' => $rows->map(fn($row) => [
                'novel_id' => (int) $row->novel_id,
                'name' => (string) $row->name,
                'medium' => $row->medium ?: \App\Novel::MEDIUM_NOVEL,
                'new_count' => (int) $row->new_count,
                'chapter_id' => (int) $row->id,
                'chapter_label' => Presenter::label((new NovelChapter())->forceFill(['label' => $row->label, 'chapter' => $row->chapter])),
                'latest_at' => Presenter::timestamp($row->newest),
            ])->values()->all(),
        ];
    }

    /** Opaque to clients: "nu1:" + unix seconds, base64url. */
    public static function encode(CarbonInterface $at): string
    {
        return rtrim(strtr(base64_encode(self::PREFIX . $at->getTimestamp()), '+/', '-_'), '=');
    }

    /** The time a cursor stands for; null when it is not one this server issued. */
    public static function decode(?string $cursor): ?Carbon
    {
        $cursor = trim((string) $cursor);
        if ($cursor === '' || strlen($cursor) > 64) {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($raw) || !preg_match('/^nu1:(\d{1,12})$/', $raw, $m)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $m[1], config('app.timezone'));
    }
}
