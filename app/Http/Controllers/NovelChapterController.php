<?php

namespace App\Http\Controllers;

use App\NovelChapter;
use App\Http\Helpers\CacheHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NovelChapterController extends Controller
{
    /** Longest `age` honoured for a replayed write (the offline queue's TTL). */
    private const MAX_WRITE_AGE_MS = 30 * 24 * 60 * 60 * 1000;

    protected $novelchapters;

    public function __construct(NovelChapter $novelchapters)
    {
        $this->novelchapters = $novelchapters;
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
        $unread = $chapter->status && $chapter->read_at === null;

        if (!$background && $unread) {
            $chapter->forceFill(['read_at' => now()])->saveQuietly();
            CacheHelper::clearNovelCache($chapter->novel_id);
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
                'progress' => $chapter->read_progress,
                'read' => $chapter->read_at !== null,
                // Served to a background fetch without being marked read: the
                // client marks it when the chapter is actually displayed.
                'deferRead' => $background && $unread,
                'hasContent' => (bool) $chapter->rawText(),
                'prev' => $prev ? ['id' => $prev->id, 'chapter' => $prev->chapter, 'label' => $prev->label, 'url' => route('chapters.show', $prev->id)] : null,
                'next' => $next ? ['id' => $next->id, 'chapter' => $next->chapter, 'label' => $next->label, 'url' => route('chapters.show', $next->id)] : null,
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
    public static function neighbours(NovelChapter $chapter, array $columns = ['id', 'chapter', 'label']): array
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
        $this->applyRead($chapter, $chapter->read_at === null, $recordedAt);

        return response()->json([
            'success' => true,
            'read' => $chapter->read_at !== null,
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

        return response()->json(['success' => true, 'read' => $chapter->read_at !== null]);
    }

    private function applyRead(NovelChapter $chapter, bool $read, Carbon $recordedAt): void
    {
        // An explicit mark also settles the in-chapter position: done when
        // read, forgotten when unread — so Continue Reading doesn't resume
        // into a chapter the user has already dealt with. A chapter already
        // read keeps its original date.
        $chapter->forceFill([
            'read_at' => $read ? ($chapter->read_at ?? now()) : null,
            'read_progress' => $read ? 100 : null,
            // An explicit mark supersedes scroll positions recorded before it
            // (a replayed mark carries its own, earlier time).
            'read_progress_at' => $recordedAt,
        ])->saveQuietly();
        CacheHelper::clearNovelCache($chapter->novel_id);
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
        ]);
        $at = $this->recordedAt($request);

        $chapter = $this->novelchapters->findOrFail($id);

        $fill = [];
        if (isset($data['progress'])) {
            $storedAt = $chapter->read_progress_at ? Carbon::parse($chapter->read_progress_at) : null;
            if (!$storedAt || !$at->lt($storedAt)) {
                $fill['read_progress'] = $data['progress'];
                $fill['read_progress_at'] = $at;
            }
        }
        $markRead = !empty($data['read']) && $chapter->status && $chapter->read_at === null;
        if ($markRead) {
            $fill['read_at'] = now();
        }

        if ($fill) {
            $chapter->forceFill($fill)->saveQuietly();
        }
        if ($markRead) {
            CacheHelper::clearNovelCache($chapter->novel_id);
        }

        return response()->json(['success' => true, 'read' => $chapter->read_at !== null]);
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

        $count = NovelChapter::where('novel_id', $chapter->novel_id)
            ->where('blacklist', 0)
            ->where('status', 1)
            ->whereNull('read_at')
            ->relativeTo($chapter, '<=')
            ->update(['read_at' => now(), 'read_progress' => 100, 'read_progress_at' => $recordedAt]);

        CacheHelper::clearNovelCache($chapter->novel_id);

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
        $values = [
            'read_at' => $data['read'] ? now() : null,
            'read_progress' => $data['read'] ? 100 : null,
            'read_progress_at' => $recordedAt,
        ];

        if ($scope === 'ids') {
            $count = NovelChapter::whereIn('id', $data['ids'])->update($values);

            foreach (NovelChapter::whereIn('id', $data['ids'])->distinct()->pluck('novel_id') as $novelId) {
                CacheHelper::clearNovelCache($novelId);
            }

            return response()->json(['success' => true, 'count' => $count]);
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

        $count = $query->update($values);
        CacheHelper::clearNovelCache($novelId);

        return response()->json(['success' => true, 'count' => $count]);
    }
}
