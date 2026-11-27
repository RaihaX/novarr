<?php

namespace App\Downloads;

use App\Audiobook;
use App\DownloadGrab;
use App\DownloadWanted;
use App\Jobs\SearchWanted;
use App\Novel;
use App\NovelRequest;
use App\User;
use Illuminate\Support\Collection;

/**
 * The wanted list: what Novarr looks for by itself (App\DownloadWanted).
 *
 *   ebooks      every pending wanted book request (NovelRequest::isWanted())
 *               is monitored automatically: added when the request is made
 *               (NovelRequest's created hook → addRequest()) and by sync();
 *               dropped once the request has its ePub or is decided.
 *   comics      every pending comic request (kind comic): added and
 *               dropped like a wanted book; found → `comics:scan` imports
 *               it and approves the request.
 *   audiobooks  a book is monitored when the owner turns on "Want the
 *               audiobook" on its page, or a reader presses "Ask for the
 *               audiobook" (requested_by; the owner is told). Dropped once
 *               the book has an audiobook (Audiobook's saved hook →
 *               audiobookArrived()) or the owner unmonitors it.
 *
 * A new item is searched at once (the App\Jobs\SearchWanted job), then on
 * the backoff (nextSearch()); `downloads:search-wanted` does the due ones.
 * The owner can unmonitor an item (an ebook stays a pending request, just
 * not searched by itself; an audiobook is dropped) or monitor it again.
 */
class WantedList
{
    /** When the item searched $searches times is searched next: backoff_hours[n-1], the last one repeating. */
    public static function nextSearch(int $searches): \Illuminate\Support\Carbon
    {
        $hours = (array) config('novarr.downloads.backoff_hours', [1, 6, 24, 72]) ?: [72];
        $step = $hours[max(0, min($searches, count($hours)) - 1)] ?? end($hours);

        return now()->addMinutes((int) round(((float) $step) * 60));
    }

    /** Bring the list in line with the requests and books: add the new wanted books, drop what is no longer wanted. */
    public function sync(): void
    {
        foreach ([Novel::MEDIUM_BOOK => DownloadGrab::KIND_EBOOK, Novel::MEDIUM_COMIC => DownloadGrab::KIND_COMIC] as $medium => $kind) {
            $wanted = NovelRequest::where('medium', $medium)
                ->where('status', NovelRequest::STATUS_PENDING)
                ->get()
                ->filter(fn(NovelRequest $r) => $r->isWanted());
            $have = DownloadWanted::where('kind', $kind)->pluck('novel_request_id')->all();
            foreach ($wanted as $request) {
                if (!in_array((int) $request->id, $have, true)) {
                    $this->row($kind, request: $request);
                }
            }
            DownloadWanted::where('kind', $kind)
                ->whereNotIn('novel_request_id', $wanted->pluck('id')->all() ?: [0])
                ->delete();
        }

        foreach (DownloadWanted::where('kind', DownloadGrab::KIND_AUDIOBOOK)->get() as $row) {
            if (!Novel::whereKey($row->novel_id)->exists() || Audiobook::where('novel_id', $row->novel_id)->exists()) {
                $row->delete();
            }
        }
    }

    /** A new wanted book request: on the list, searched at once. */
    public function addRequest(NovelRequest $request): ?DownloadWanted
    {
        if (!$request->isPending() || !$request->isWanted()) {
            return null;
        }
        $kind = Grabber::kindForRequest($request);
        $row = DownloadWanted::where('kind', $kind)->where('novel_request_id', $request->id)->first()
            ?? $this->row($kind, request: $request);
        $this->searchSoon($row);

        return $row;
    }

    /**
     * Want this book's audiobook (the owner's switch, or a reader asking:
     * $requestedBy). Searched at once when newly wanted.
     *
     * @throws DownloadFailed  not a book, or it has an audiobook already
     */
    public function monitorAudiobook(Novel $novel, ?User $requestedBy = null): DownloadWanted
    {
        if (!$novel->isBook()) {
            throw new DownloadFailed('Only a book can have an audiobook.');
        }
        if (Audiobook::where('novel_id', $novel->id)->exists()) {
            throw new DownloadFailed('This book has its audiobook already.');
        }
        $row = self::audiobookFor((int) $novel->id);
        $new = $row === null;
        $row ??= $this->row(DownloadGrab::KIND_AUDIOBOOK, novel: $novel, requestedBy: $requestedBy);
        if (!$row->monitored) {
            $row->forceFill(['monitored' => true, 'next_search_at' => now()])->save();
            $new = true;
        }
        if ($requestedBy !== null && $row->requested_by === null) {
            $row->forceFill(['requested_by' => $requestedBy->id])->save();
        }
        if ($new) {
            $this->searchSoon($row);
        }

        return $row;
    }

    public function unmonitor(DownloadWanted $row): void
    {
        if ($row->kind === DownloadGrab::KIND_AUDIOBOOK) {
            $row->delete();

            return;
        }
        $row->forceFill(['monitored' => false])->save();
    }

    public function monitor(DownloadWanted $row): void
    {
        $row->forceFill(['monitored' => true, 'next_search_at' => now()])->save();
        $this->searchSoon($row);
    }

    /** The book has its audiobook now: no longer wanted. */
    public static function audiobookArrived(int $novelId): void
    {
        DownloadWanted::where('kind', DownloadGrab::KIND_AUDIOBOOK)->where('novel_id', $novelId)->delete();
    }

    public static function audiobookFor(int $novelId): ?DownloadWanted
    {
        return DownloadWanted::where('kind', DownloadGrab::KIND_AUDIOBOOK)->where('novel_id', $novelId)->first();
    }

    /** The wanted row a grab belongs to (its request's, its book's audiobook), or null. */
    public static function forGrab(DownloadGrab $grab): ?DownloadWanted
    {
        if ($grab->wanted_id && ($row = DownloadWanted::find($grab->wanted_id)) !== null) {
            return $row;
        }
        if (in_array($grab->kind, [DownloadGrab::KIND_EBOOK, DownloadGrab::KIND_COMIC], true) && $grab->novel_request_id) {
            return DownloadWanted::where('kind', $grab->kind)->where('novel_request_id', $grab->novel_request_id)->first();
        }
        if ($grab->kind === DownloadGrab::KIND_AUDIOBOOK && $grab->novel_id) {
            return self::audiobookFor((int) $grab->novel_id);
        }

        return null;
    }

    /**
     * The monitored items whose search is due, soonest first.
     *
     * @return Collection<int, DownloadWanted>
     */
    public function due(int $limit): Collection
    {
        return DownloadWanted::where('monitored', true)
            ->where(fn($q) => $q->whereNull('next_search_at')->orWhere('next_search_at', '<=', now()))
            ->orderByRaw('next_search_at IS NOT NULL')
            ->orderBy('next_search_at')
            ->orderBy('id')
            ->limit(max(0, $limit))
            ->get();
    }

    /** Search it now (a queued job) when automatic searching is on; else it waits for "Search now". */
    public function searchSoon(DownloadWanted $row): void
    {
        $row->forceFill(['next_search_at' => now()])->save();
        if (DownloadProfile::autoSearch()) {
            SearchWanted::dispatch((int) $row->id);
        }
    }

    private function row(string $kind, ?NovelRequest $request = null, ?Novel $novel = null, ?User $requestedBy = null): DownloadWanted
    {
        return DownloadWanted::create([
            'kind' => $kind,
            'novel_request_id' => $request?->id,
            'novel_id' => $novel?->id,
            'monitored' => true,
            'requested_by' => $requestedBy?->id,
            'next_search_at' => now(),
        ]);
    }
}
