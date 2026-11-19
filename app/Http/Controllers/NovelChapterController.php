<?php

namespace App\Http\Controllers;

use App\Bookmark;
use App\NovelChapter;
use App\Services\ReadingPositions;
use App\Services\ReadState;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NovelChapterController extends Controller
{
    /** Longest `age` honoured for a replayed write (the offline queue's TTL). */
    private const MAX_WRITE_AGE_MS = 30 * 24 * 60 * 60 * 1000;

    protected $novelchapters;

    protected ReadingPositions $positions;

    // Stateless (the reader is resolved per call), so safe to hold here even
    // though Octane keeps controller instances across requests.
    protected ReadState $readState;

    public function __construct(NovelChapter $novelchapters, ReadingPositions $positions, ReadState $readState)
    {
        $this->novelchapters = $novelchapters;
        $this->positions = $positions;
        $this->readState = $readState;
    }

    /**
     * Display a chapter with previous/next navigation.
     */
    public function show(Request $request, $id)
    {
        $chapter = $this->novelchapters->with(['novel:id,name,medium'])->findOrFail($id);

        // A manga chapter: the page shell for the manga reader. It is never
        // marked read on open — the reader marks it through
        // chapters.progress once the pages have actually been read.
        if ($chapter->novel?->isManga()) {
            return view('chapters.manga', [
                'chapter' => $chapter,
                'pages' => $chapter->status ? $chapter->pages()->get(['page', 'width', 'height']) : collect(),
            ]);
        }
        $chapter->load('text');

        [$prev, $next] = static::neighbours($chapter);

        // Opening a downloaded chapter marks it read — but only when a person
        // actually opened it. Background fetches are skipped (see
        // isBackgroundFetch); the page tells the reader JS via `deferRead` so it
        // can mark the chapter once it is genuinely shown (a rendered Turbo
        // prefetch, or an inline continuous-reading section scrolled into view).
        $background = $this->isBackgroundFetch($request);
        $this->readState->get($chapter);
        $unread = $chapter->status && !$chapter->isRead();

        // Where the series was read last BEFORE this open, so the page can
        // offer to jump there; then this open becomes the latest position
        // (opening a chapter in the web reader is reading it here).
        $latestPosition = $this->positions->latestForWeb((int) $chapter->novel_id);
        $readerId = $this->readState->userId();

        if (!$background && $unread) {
            $this->readState->markOpened($chapter, lookup: $request->boolean('lookup'));
        }
        // A look-up (`?lookup=1`: a Highlights entry or a full-text search
        // hit) opens an old chapter to check a passage, not to read on from
        // it: it must not become the series' latest position, or the
        // reader's other devices get offered a jump backwards. The page tells
        // the reader JS (`lookup`) so its scroll saves skip this chapter too.
        $lookup = $request->boolean('lookup');
        if (!$background && !$lookup && $chapter->status) {
            $this->positions->record($chapter, $chapter->readProgress() ?? 0, now(), $this->positions->device($request));
        }

        return view('chapters.show', [
            'chapter' => $chapter,
            'prev' => $prev,
            'next' => $next,
            // Serialised into the page for the reader JS (continuous loading,
            // TOC, position sync) — and parsed back out of fetched pages when
            // appending the next chapter inline.
            'readerState' => [
                'id' => $chapter->id,
                'novelId' => $chapter->novel_id,
                'label' => $chapter->label ?: 'Chapter ' . $chapter->chapter,
                'chapter' => $chapter->chapter,
                'url' => route('chapters.show', $chapter->id),
                'progress' => $chapter->readProgress(),
                'read' => $chapter->isRead(),
                // Served to a background fetch without being marked read: the
                // client marks it when the chapter is actually displayed.
                'deferRead' => $background && $unread,
                // Opened as a look-up (see above): the reader JS posts no
                // scroll position for this chapter (chapters it moves on to
                // are tracked as usual).
                'lookup' => $lookup,
                'hasContent' => (bool) $chapter->rawText(),
                // `downloaded`: whether the neighbour has a body yet. A next
                // chapter that is still pending is not a place to go: the
                // reader offers "Mark read" (stay) and "Check for new
                // chapters" instead, and continuous reading stops there.
                'prev' => static::neighbourState($prev),
                'next' => static::neighbourState($next),
                // This reader's own highlights in this chapter, drawn into the
                // text by the reader JS (resources/js/reader/highlights.js).
                // Embedded rather than fetched, so continuous reading lifts
                // them with the section and an offline copy keeps them.
                'highlights' => static::highlightsFor($chapter, $readerId),
                // Opened from the Highlights page (`?highlight=<id>`): the
                // reader scrolls that highlight into view instead of
                // restoring the saved position, when it is in this chapter.
                'focusHighlight' => $request->integer('highlight') ?: null,
                // The series' latest position (any device) as it was before
                // this open; see ReadingPositions::latest().
                'latest_position' => $latestPosition,
            ],
        ]);
    }

    /**
     * The chapters before and after $chapter in reading order (blacklisted
     * excluded): (book, sort_key, chapter) tuple comparison — same book and a
     * later key, else the next book. Rows without a sort_key fall back to the
     * legacy chapter number.
     *
     * @return array{0: ?NovelChapter, 1: ?NovelChapter}
     */
    public static function neighbours(NovelChapter $chapter, array $columns = ['id', 'chapter', 'label', 'status']): array
    {
        $prev = NovelChapter::where('novel_id', $chapter->novel_id)
            ->where('blacklist', 0)
            ->relativeTo($chapter, '<')
            ->orderedDesc()
            ->first($columns);

        $next = NovelChapter::where('novel_id', $chapter->novel_id)
            ->where('blacklist', 0)
            ->relativeTo($chapter, '>')
            ->ordered()
            ->first($columns);

        return [$prev, $next];
    }

    /**
     * A neighbour as the reader state carries it (null when there is none).
     *
     * @return array{id:int, chapter:mixed, label:?string, url:string, downloaded:bool}|null
     */
    public static function neighbourState(?NovelChapter $neighbour): ?array
    {
        if ($neighbour === null) {
            return null;
        }

        return [
            'id' => $neighbour->id,
            'chapter' => $neighbour->chapter,
            'label' => $neighbour->label,
            'url' => route('chapters.show', $neighbour->id),
            'downloaded' => (bool) $neighbour->status,
        ];
    }

    /**
     * One reader's highlights in $chapter, oldest first (the order they are
     * drawn in): only what the reader page needs.
     *
     * @return array<int, array{id:int, excerpt:string, note:?string}>
     */
    public static function highlightsFor(NovelChapter $chapter, int $userId): array
    {
        return Bookmark::where('user_id', $userId)
            ->where('novel_chapter_id', $chapter->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'excerpt', 'note'])
            ->map(fn(Bookmark $b) => ['id' => (int) $b->id, 'excerpt' => (string) $b->excerpt, 'note' => $b->note])
            ->values()
            ->all();
    }

    /**
     * Whether this request is a fetch the reader never asked to see:
     *  - browser / Turbo prefetches (`Sec-Purpose`, `Purpose`, and Turbo 8's
     *    `X-Sec-Purpose: prefetch` for hover/instant prefetch);
     *  - our own background fetches, tagged `X-Novarr-Fetch: offline`
     *    (service-worker "Download for offline") or `continuous` (the next
     *    chapter appended inline before the reader reaches it);
     *  - an explicit `?prefetch=1`.
     */
    protected function isBackgroundFetch(Request $request): bool
    {
        foreach (['Sec-Purpose', 'Purpose', 'X-Sec-Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (str_contains(strtolower((string) $request->header($header, '')), 'prefetch')) {
                return true;
            }
        }

        if (in_array(strtolower(trim((string) $request->header('X-Novarr-Fetch', ''))), ['offline', 'continuous', 'prefetch'], true)) {
            return true;
        }

        return $request->boolean('prefetch');
    }

    /**
     * Manually toggle a chapter's read state (override the auto-mark).
     */
    public function toggleRead(Request $request, $id)
    {
        $recordedAt = $this->recordedAt($request);
        $chapter = $this->novelchapters->findOrFail($id);
        $this->readState->get($chapter);
        $this->applyRead($chapter, !$chapter->isRead(), $recordedAt);
        $this->recordReadMark($request, $chapter, $recordedAt);

        return response()->json([
            'success' => true,
            'read' => $chapter->isRead(),
        ]);
    }

    /**
     * Set (not toggle) a chapter's read state — the native app's explicit
     * mark. Same side effects as toggleRead().
     */
    public function setRead(Request $request, $id)
    {
        $read = (bool) $request->validate(['read' => 'required|boolean'])['read'];
        $recordedAt = $this->recordedAt($request);
        $chapter = $this->novelchapters->findOrFail($id);
        $this->applyRead($chapter, $read, $recordedAt);
        $this->recordReadMark($request, $chapter, $recordedAt);

        return response()->json(['success' => true, 'read' => $chapter->isRead()]);
    }

    /** A mark READ is "finished it here"; marking unread says nothing about where the reader is. */
    private function recordReadMark(Request $request, NovelChapter $chapter, Carbon $recordedAt): void
    {
        if ($chapter->isRead()) {
            $this->positions->record($chapter, 100, $recordedAt, $this->positions->device($request));
        }
    }

    private function applyRead(NovelChapter $chapter, bool $read, Carbon $recordedAt): void
    {
        // An explicit mark also settles the in-chapter position: done when
        // read, forgotten when unread — so Continue Reading doesn't resume
        // into a chapter the user has already dealt with. A chapter already
        // read keeps its original date. The mark's time supersedes scroll
        // positions recorded before it (a replayed mark carries its own,
        // earlier time).
        $this->readState->setRead($chapter, $read, $recordedAt);
    }

    /**
     * Persist how far through a chapter the reader has scrolled (0–100), so
     * the position survives across devices. Accepts JSON or form data — the
     * pagehide save arrives via navigator.sendBeacon, which can only POST
     * form-encoded bodies.
     *
     * A position recorded before the stored one (see recordedAt(): replayed
     * offline writes carry their `age`) is ignored, still answered with
     * success so the client drops it; a live write counts as "now" and
     * always applies.
     */
    public function progress(Request $request, $id)
    {
        // `read` lets the reader mark a chapter read at the moment it is
        // actually shown (continuous reading, a rendered prefetch) rather than
        // when its HTML was fetched. Progress is then optional.
        $data = $request->validate([
            'progress' => 'required_without:read|nullable|integer|min:0|max:100',
            'read' => 'sometimes|boolean',
            // A look-up chapter's deferred mark-read-on-view (see show()):
            // mark it, but leave the series' latest position alone.
            'lookup' => 'sometimes|boolean',
        ]);
        $at = $this->recordedAt($request);

        $chapter = $this->novelchapters->findOrFail($id);

        // A look-up is not reading there: its position is not stored, so
        // the chapter's position time does not make it the chapter read last
        // and move Continue (App\Services\ResumePoint). Its read mark still
        // applies, as a manual one (not reading activity).
        $stale = $this->readState->recordProgress(
            $chapter,
            isset($data['progress']) && empty($data['lookup']) ? (int) $data['progress'] : null,
            !empty($data['read']),
            $at,
            lookup: !empty($data['lookup'])
        );

        // The series' latest position follows this write unless the write
        // was itself too old for the chapter (record() also checks its age
        // against the series' stored position).
        if (!$stale && empty($data['lookup']) && (isset($data['progress']) || !empty($data['read']))) {
            $this->positions->record($chapter, $chapter->readProgress() ?? 0, $at, $this->positions->device($request));
        }

        return response()->json(['success' => true, 'read' => $chapter->isRead()]);
    }

    /**
     * When a read-state write was recorded. A write replayed from the offline
     * queue carries `age`: how many milliseconds ago the client queued it,
     * measured on the client's own clock, so a phone clock that is fast or
     * slow doesn't matter. Absent means a live write (now). Capped at 30
     * days, the queue's lifetime.
     */
    private function recordedAt(Request $request): Carbon
    {
        $age = $request->validate([
            'age' => 'sometimes|nullable|integer|min:0',
        ])['age'] ?? 0;

        return now()->subMilliseconds(min((int) $age, self::MAX_WRITE_AGE_MS));
    }

    /**
     * Mark this chapter and every earlier downloaded chapter as read — for
     * catching up read state after reading elsewhere. Already-read chapters
     * keep their original timestamp.
     */
    public function readThrough(Request $request, $id)
    {
        $recordedAt = $this->recordedAt($request);
        $chapter = $this->novelchapters->findOrFail($id);
        $chapters = fn() => NovelChapter::where('novel_id', $chapter->novel_id)
            ->where('blacklist', 0)
            ->where('status', 1)
            ->relativeTo($chapter, '<=');

        // `dry_run`: only say how many chapters a mark would change (the
        // reader confirms first when that is a lot), marking nothing.
        if ($request->boolean('dry_run')) {
            return response()->json([
                'success' => true,
                'dry_run' => true,
                'marked' => $this->readState->whereUnread($chapters())->count(),
            ]);
        }

        $count = $this->readState->markMany($chapters(), true, $recordedAt, onlyUnread: true);

        return response()->json(['success' => true, 'marked' => $count]);
    }

    /**
     * Bulk mark chapters read or unread (novel-page table).
     *
     * Scopes: 'ids' (explicit selection, default), 'all' (whole novel),
     * 'up_to' / 'from' (everything before-and-including / after-and-including
     * an anchor chapter, in the list's reading order — NovelChapter::ordered()).
     */
    public function bulkRead(Request $request)
    {
        $data = $request->validate([
            'read' => 'required|boolean',
            'scope' => 'nullable|in:ids,all,up_to,from',
            'ids' => 'required_if:scope,ids|required_without:scope|array',
            'ids.*' => 'integer',
            'novel_id' => 'nullable|required_if:scope,all|integer',
            // nullable: the page sends anchor_id: null for scope=all.
            'anchor_id' => 'nullable|required_if:scope,up_to,from|integer',
        ]);
        $recordedAt = $this->recordedAt($request);

        $scope = $data['scope'] ?? 'ids';
        // Every selected chapter is set, already-read ones included (their
        // date becomes now), as this endpoint always has.
        $mark = fn($query) => $this->readState->markMany($query, (bool) $data['read'], $recordedAt);

        if ($scope === 'ids') {
            return response()->json(['success' => true, 'count' => $mark(NovelChapter::whereIn('id', $data['ids']))]);
        }

        if ($scope === 'all') {
            $novelId = (int) $data['novel_id'];
            $query = NovelChapter::where('novel_id', $novelId)->where('blacklist', 0);
        } else {
            $anchor = NovelChapter::findOrFail($data['anchor_id']);
            $novelId = $anchor->novel_id;
            $before = $scope === 'up_to';

            $query = NovelChapter::where('novel_id', $novelId)
                ->where('blacklist', 0)
                ->relativeTo($anchor, $before ? '<=' : '>=');
        }

        return response()->json(['success' => true, 'count' => $mark($query)]);
    }
}
