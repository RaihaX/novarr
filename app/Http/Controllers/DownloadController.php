<?php

namespace App\Http\Controllers;

use App\DownloadBlock;
use App\DownloadEvent;
use App\DownloadGrab;
use App\DownloadWanted;
use App\Downloads\BookDownloads;
use App\Downloads\DownloadFailed;
use App\Downloads\DownloadPoller;
use App\Downloads\DownloadProfile;
use App\Downloads\Downloads;
use App\Downloads\DownloadSearch;
use App\Downloads\Grabber;
use App\Downloads\SearchBudget;
use App\Downloads\WantedList;
use App\Downloads\WantedSearcher;
use App\Novel;
use App\NovelRequest;
use App\Services\AdminAlert;
use App\Services\Requests\RequestNotifier;
use App\Setting;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Searching Usenet & torrents and downloading (App\Downloads), the owner's
 * side (admin only, so the home network only: App\Support\RouteAccess),
 * except "Ask for the audiobook" (any reader):
 *
 *   /downloads                  Activity, Sonarr style: Queue · History ·
 *                               Wanted · Blocklist · Settings (?tab=)
 *   POST /downloads/search      JSON: a Prowlarr search, ranked (the widget,
 *                               resources/js/download-finder.js)
 *   POST /downloads/grab        JSON: grab a result of that search (by its
 *                               guid; a manual grab overrides the profile)
 *   GET  /downloads/grabs/{id}  JSON: a grab's status (the widget polls it)
 *   POST /downloads/grabs/{id}/fail    Mark failed & search again
 *   POST /downloads/grabs/{id}/import  take a finished download into a
 *                               chosen request (ebook) / book (audiobook)
 *   POST /downloads/poll        check the clients now
 *   POST /downloads/wanted/{id}/search|unmonitor|monitor
 *   DELETE /downloads/blocklist/{id}   remove (and search its item again)
 *   POST /downloads/settings    the automation switches
 *   POST /novels/{id}/audiobook/monitor  the owner's "Want the audiobook"
 *   POST /novels/{id}/audiobook/ask      a reader's "Ask for the audiobook"
 */
class DownloadController extends Controller
{
    public const TABS = ['queue', 'history', 'wanted', 'blocklist', 'settings'];

    public function index(Request $request, WantedList $list)
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'queue';
        if (Downloads::enabled()) {
            $list->sync();
        }

        return view('downloads.index', [
            'tab' => $tab,
            'enabled' => Downloads::enabled(),
            'clients' => ['nzbget' => Downloads::nzbgetConfigured(), 'transmission' => Downloads::transmissionConfigured()],
            'queue' => DownloadGrab::with(['request', 'novel'])
                ->whereIn('status', [...DownloadGrab::ACTIVE, DownloadGrab::STATUS_COMPLETED])
                ->orderByDesc('id')->get(),
            'recent' => DownloadGrab::with(['request', 'novel'])
                ->whereIn('status', [DownloadGrab::STATUS_IMPORTED, DownloadGrab::STATUS_FAILED])
                ->orderByDesc('updated_at')->orderByDesc('id')->limit(50)->get(),
            'events' => DownloadEvent::orderByDesc('id')->limit(150)->get(),
            'wanted' => DownloadWanted::with(['request', 'novel', 'requester'])
                ->orderByDesc('monitored')->orderByDesc('needs_choice')->orderBy('next_search_at')->orderBy('id')->get(),
            'blocklist' => DownloadBlock::orderByDesc('id')->limit(200)->get(),
            'lastPoll' => \Illuminate\Support\Facades\Cache::get(DownloadPoller::LAST_POLL_KEY),
            'budget' => ['used' => SearchBudget::used(), 'cap' => DownloadProfile::searchesPerDay()],
            'settings' => [
                'auto_search' => filter_var(DownloadProfile::setting('auto_search'), FILTER_VALIDATE_BOOLEAN),
                'auto_grab' => DownloadProfile::autoGrab(),
                'auto_approve' => DownloadProfile::autoApprove(),
                'min_seeders' => DownloadProfile::minSeeders(),
                'prefer_protocol' => DownloadProfile::preferProtocol(),
                'searches_per_day' => DownloadProfile::searchesPerDay(),
            ],
        ]);
    }

    public function search(Request $request, DownloadSearch $search): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:' . implode(',', DownloadGrab::KINDS)],
            'q' => ['nullable', 'string', 'max:255'],
            'request_id' => ['nullable', 'integer'],
            'novel_id' => ['nullable', 'integer'],
        ]);
        [$bookRequest, $novel] = $this->target($data);
        if (trim((string) ($data['q'] ?? '')) === '' && $bookRequest === null && $novel === null) {
            return response()->json(['message' => 'Type what to search for.'], 422);
        }

        try {
            $found = $search->run($data['kind'], (string) ($data['q'] ?? ''), $bookRequest, $novel);
        } catch (DownloadFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $wanted = DownloadSearch::wanted($bookRequest, $novel);

        return response()->json([
            'search_id' => $found['id'],
            'query' => $found['query'],
            'kind' => $found['kind'],
            'results' => array_map(fn(array $r) => [
                'guid' => $r['guid'],
                'title' => $r['title'],
                'indexer' => $r['indexer'],
                'protocol' => $r['protocol'],
                'size' => $r['size'],
                'age_days' => $r['age_days'],
                'seeders' => $r['seeders'],
                'leechers' => $r['leechers'],
                'format' => $r['format'],
                'score' => $r['score'],
                'why' => $r['why'],
                'info_url' => $r['info_url'],
                'grabbable' => $r['grabbable'],
                'not_grabbable' => $r['not_grabbable'],
                'blocklisted' => $r['blocklisted'],
                // Would the automation grab it alone? (its reason when not)
                'auto' => DownloadProfile::refusal($r, $found['kind'], $wanted) ?? 'ok',
            ], $found['results']),
        ]);
    }

    public function grab(Request $request, Grabber $grabber, CurrentReader $reader): JsonResponse
    {
        $data = $request->validate([
            'search_id' => ['required', 'string', 'max:64'],
            'guid' => ['required', 'string', 'max:2000'],
        ]);
        $search = DownloadSearch::find($data['search_id']);
        if ($search === null) {
            return response()->json(['message' => 'These results are too old. Search again.'], 410);
        }
        $result = collect($search['results'])->first(fn($r) => (string) $r['guid'] === $data['guid']);
        if ($result === null) {
            return response()->json(['message' => 'That result is not in this search. Search again.'], 404);
        }
        $bookRequest = $search['request_id'] ? NovelRequest::find($search['request_id']) : null;
        $novel = $search['novel_id'] ? Novel::find($search['novel_id']) : null;

        try {
            $grab = $grabber->grab($result, $search['kind'], $bookRequest, $novel, $reader->user());
        } catch (DownloadFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['grab' => $grab->present()], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['grab' => DownloadGrab::findOrFail($id)->present()]);
    }

    /** Mark failed & search again: blocklisted, its wanted item searched (the client keeps its copy; a torrent seeds on). */
    public function fail(int $id): RedirectResponse
    {
        $grab = DownloadGrab::findOrFail($id);
        if (!in_array($grab->status, [...DownloadGrab::ACTIVE, DownloadGrab::STATUS_COMPLETED], true)) {
            return back()->with('error', 'Only a download still under way or waiting to be imported can be marked failed.');
        }
        $grab->fail('Marked failed by the owner.');

        return back()->with('success', "“{$grab->title}” is marked failed and blocklisted" . (WantedList::forGrab($grab)?->monitored ? '; searching again.' : '.'));
    }

    /** Take a finished download into a chosen wanted request (ebook) or book (audiobook). */
    public function import(int $id, Request $request, BookDownloads $books): RedirectResponse
    {
        $grab = DownloadGrab::findOrFail($id);
        $data = $request->validate(['request_id' => ['nullable', 'integer'], 'novel_id' => ['nullable', 'integer']]);
        if ((string) $grab->completed_path === '' || !in_array($grab->status, [DownloadGrab::STATUS_COMPLETED, DownloadGrab::STATUS_FAILED], true)) {
            return back()->with('error', 'Only a finished download (or one whose import failed) can be imported by hand.');
        }

        if ($grab->kind === DownloadGrab::KIND_EBOOK) {
            $target = !empty($data['request_id']) ? NovelRequest::find((int) $data['request_id']) : null;
            if (!empty($data['request_id']) && ($target === null || !$target->isPending() || !$target->isWanted())) {
                return back()->with('error', 'That request is not a wanted book waiting for its ePub.');
            }
            $grab->forceFill(['novel_request_id' => $target?->id, 'status' => DownloadGrab::STATUS_COMPLETED, 'completed_at' => now(), 'error' => null])->save();
            $status = $books->import($grab);
            $grab->refresh();

            return back()->with($status === DownloadGrab::STATUS_FAILED ? 'error' : 'success', Downloads::statusLine($grab));
        }

        $novel = !empty($data['novel_id']) ? Novel::find((int) $data['novel_id']) : null;
        if (!empty($data['novel_id']) && ($novel === null || !$novel->isBook())) {
            return back()->with('error', 'That is not a book of the library.');
        }
        $grab->forceFill(['novel_id' => $novel?->id, 'status' => DownloadGrab::STATUS_COMPLETED, 'completed_at' => now(), 'error' => null])->save();
        \App\Jobs\ScanAudiobooks::dispatch();

        return back()->with('success', "“{$grab->title}” is imported by the next audiobooks scan (queued now)" . ($novel ? " as the audiobook of “{$novel->name}”." : '.'));
    }

    public function poll(DownloadPoller $poller): RedirectResponse
    {
        $counts = $poller->poll();
        if ($counts['busy']) {
            return back()->with('error', 'A check is already running. Try again in a moment.');
        }
        $message = "Checked {$counts['checked']}: {$counts['completed']} finished, {$counts['imported']} imported, {$counts['failed']} failed.";

        return back()->with($counts['problems'] === [] ? 'success' : 'error', $message . ($counts['problems'] !== [] ? ' ' . implode(' ', $counts['problems']) : ''));
    }

    public function wantedSearch(int $id, WantedSearcher $searcher): RedirectResponse
    {
        $result = $searcher->search(DownloadWanted::findOrFail($id), false);

        return back()->with(in_array($result['status'], ['grabbed', 'needs_choice', 'nothing'], true) ? 'success' : 'error', $result['message']);
    }

    public function wantedUnmonitor(int $id, WantedList $list): RedirectResponse
    {
        $row = DownloadWanted::findOrFail($id);
        $title = $row->title();
        $list->unmonitor($row);

        return back()->with('success', "“{$title}” is no longer searched for automatically.");
    }

    public function wantedMonitor(int $id, WantedList $list): RedirectResponse
    {
        $row = DownloadWanted::findOrFail($id);
        $list->monitor($row);

        return back()->with('success', "“{$row->title()}” is searched for automatically again.");
    }

    public function blocklistDestroy(int $id, WantedList $list): RedirectResponse
    {
        $block = DownloadBlock::findOrFail($id);
        $block->delete();
        $grab = $block->grab_id ? DownloadGrab::find($block->grab_id) : null;
        $row = $grab !== null ? WantedList::forGrab($grab) : null;
        if ($row !== null && $row->monitored) {
            $list->searchSoon($row);
        }

        return back()->with('success', "“{$block->title}” is off the blocklist" . ($row !== null && $row->monitored ? '; its book is searched again.' : '.'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_search' => ['nullable', 'boolean'],
            'auto_grab' => ['nullable', 'boolean'],
            'auto_approve' => ['nullable', 'boolean'],
            'min_seeders' => ['required', 'integer', 'min:0', 'max:1000'],
            'prefer_protocol' => ['required', 'in:usenet,torrent'],
            'searches_per_day' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);
        foreach (['auto_search', 'auto_grab', 'auto_approve'] as $switch) {
            Setting::put('downloads.' . $switch, !empty($data[$switch]) ? '1' : '0');
        }
        foreach (['min_seeders', 'prefer_protocol', 'searches_per_day'] as $key) {
            Setting::put('downloads.' . $key, (string) $data[$key]);
        }

        return redirect()->route('downloads.index', ['tab' => 'settings'])->with('success', 'Download settings saved.');
    }

    /** The owner's "Want the audiobook" switch on a book's page (want=1 on, 0 off). */
    public function audiobookMonitor(int $id, Request $request, WantedList $list): RedirectResponse
    {
        $novel = Novel::findOrFail($id);
        $want = $request->boolean('want', true);
        if (!$want) {
            if (($row = WantedList::audiobookFor($id)) !== null) {
                $list->unmonitor($row);
            }

            return back()->with('success', 'Novarr no longer looks for this book\'s audiobook.');
        }
        try {
            $list->monitorAudiobook($novel);
        } catch (DownloadFailed $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', Downloads::enabled()
            ? 'Novarr looks for this book\'s audiobook now, and again later until it finds one.'
            : 'Wanted. Novarr will look for it once Prowlarr is set up.');
    }

    /** A reader's "Ask for the audiobook": the book's audiobook is wanted (monitored), and the owner is told once. */
    public function audiobookAsk(int $id, CurrentReader $reader, WantedList $list, AdminAlert $alert): RedirectResponse
    {
        $novel = Novel::findOrFail($id);
        $before = WantedList::audiobookFor($id);
        try {
            $row = $list->monitorAudiobook($novel, $reader->user());
        } catch (DownloadFailed $e) {
            return back()->with('error', $e->getMessage());
        }
        if (Gate::denies('admin') && ($before === null || $before->requested_by === null)) {
            $who = RequestNotifier::nameOf($reader->user());
            $alert->send(
                "Audiobook asked for: {$novel->name}",
                "{$who} asked for the audiobook of \u{201C}{$novel->name}\u{201D}." . (Downloads::enabled() ? ' Novarr is looking for it.' : ''),
                RequestNotifier::adminBase() . route('downloads.index', ['tab' => 'wanted'], false),
            );
        }

        return back()->with('success', 'Asked for. You will find the audiobook on this page once it is here.');
    }

    /** @return array{0: ?NovelRequest, 1: ?Novel} */
    private function target(array $data): array
    {
        $bookRequest = !empty($data['request_id']) ? NovelRequest::find((int) $data['request_id']) : null;
        $novel = !empty($data['novel_id']) ? Novel::find((int) $data['novel_id']) : null;

        return [$bookRequest, $novel];
    }
}
