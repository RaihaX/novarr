<?php

namespace App\Http\Controllers;

use App\Audiobook;
use App\Audiobooks\AudiobookInbox;
use App\Audiobooks\AudiobookLibrary;
use App\Audiobooks\AudiobookMatcher;
use App\Audiobooks\ListeningProgress;
use App\Http\Controllers\Api\V1\AudiobookController as ApiAudiobooks;
use App\Http\Controllers\Api\V1\AudiobookPresenter;
use App\Jobs\ScanAudiobooks;
use App\Support\CurrentReader;
use Illuminate\Http\Request;

/**
 * The audiobooks' web pages:
 *
 *   /audiobooks           every reader: the shelf (Continue listening, then
 *                         every audiobook: books' and standalone ones)
 *   /audiobooks/{id}      every reader: the player (resources/js/audiobook-
 *                         player.js); it saves through /api/v1/audiobooks…
 *   /audiobooks/manage    the owner: the inbox (waiting, failed + Retry),
 *                         the unmatched ones with a book search to match
 *                         them (or leave them standalone), "Scan now", totals
 */
class AudiobookController extends Controller
{
    public const SHELF_PAGE = 60;

    public function __construct(private ListeningProgress $progress)
    {
    }

    public function index(Request $request)
    {
        $userId = app(CurrentReader::class)->id();
        $q = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));

        $rows = Audiobook::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
                $query->where(fn($w) => $w->where('title', 'like', $like)->orWhere('author', 'like', $like)
                    ->orWhere('narrator', 'like', $like)
                    ->orWhereHas('novel', fn($n) => $n->where('name', 'like', $like)->orWhere('author', 'like', $like)));
            })
            ->orderBy('title')->orderBy('id')
            ->offset(($page - 1) * self::SHELF_PAGE)
            ->limit(self::SHELF_PAGE + 1)
            ->get();
        $items = AudiobookPresenter::load($rows->take(self::SHELF_PAGE));
        $progress = $this->progress->forMany($userId, $items->pluck('id')->map(fn($id) => (int) $id)->all());

        return view('audiobooks.index', [
            'audiobooks' => $items,
            'progress' => $progress,
            'continue' => $q === '' && $page === 1 ? ApiAudiobooks::continueShelf($this->progress, $userId) : [],
            'q' => $q,
            'page' => $page,
            'has_more' => $rows->count() > self::SHELF_PAGE,
            'total' => Audiobook::count(),
        ]);
    }

    public function show(int $id)
    {
        $audiobook = ApiAudiobooks::find($id);
        $userId = app(CurrentReader::class)->id();
        $detail = AudiobookPresenter::detail($audiobook, $this->progress->get($userId, $id), ApiAudiobooks::bookmarks($userId, $id));

        return view('audiobooks.show', [
            'audiobook' => $audiobook,
            'detail' => $detail,
        ]);
    }

    public function manage(Request $request, AudiobookInbox $inbox)
    {
        $status = $inbox->status();
        $q = trim((string) $request->query('q', ''));
        $for = (int) $request->query('for', 0);

        $unmatched = AudiobookPresenter::load(Audiobook::where('status', Audiobook::STATUS_UNMATCHED)->orderByDesc('added_at')->orderByDesc('id')->get());
        $recent = AudiobookPresenter::load(Audiobook::where('status', Audiobook::STATUS_READY)->orderByDesc('added_at')->orderByDesc('id')->limit(30)->get());

        // The book search for one audiobook (?for=&q=), prefilled with its cleaned title.
        $searchFor = $for > 0 ? Audiobook::find($for) : null;
        if ($searchFor !== null && $q === '') {
            $q = AudiobookMatcher::cleanTitle((string) $searchFor->title);
        }
        $results = $searchFor !== null ? AudiobookMatcher::searchBooks($q) : collect();
        $linked = $results->isEmpty() ? [] : Audiobook::whereIn('novel_id', $results->pluck('id'))->pluck('id', 'novel_id')->all();

        return view('audiobooks.manage', [
            'status' => $status,
            'unmatched' => $unmatched,
            'recent' => $recent,
            'searchFor' => $searchFor,
            'q' => $q,
            'results' => $results,
            'linked' => $linked,
            'totals' => [
                'count' => Audiobook::count(),
                'matched' => Audiobook::whereNotNull('novel_id')->count(),
                'duration_ms' => (int) Audiobook::sum('duration_ms'),
                'size_bytes' => (int) Audiobook::sum('size_bytes'),
            ],
            'root' => AudiobookLibrary::root(),
        ]);
    }

    public function scan()
    {
        ScanAudiobooks::dispatch();

        return redirect()->route('audiobooks.manage')
            ->with('success', 'Scan queued: settled entries are imported within a minute or two. Refresh this page to see them.');
    }

    public function retry(Request $request, AudiobookInbox $inbox)
    {
        $name = (string) $request->validate(['name' => 'required|string|max:255'])['name'];
        if (($error = $inbox->retry($name)) !== null) {
            return redirect()->route('audiobooks.manage')->with('error', $error);
        }

        return redirect()->route('audiobooks.manage')->with('success', "“{$name}” is back in the inbox: the next scan tries it again.");
    }

    public function match(Request $request, AudiobookMatcher $matcher, int $id)
    {
        $audiobook = Audiobook::findOrFail($id);
        $data = $request->validate([
            'novel_id' => 'nullable|integer|min:1',
            'standalone' => 'sometimes|boolean',
        ]);
        $novelId = !empty($data['standalone']) ? null : (isset($data['novel_id']) ? (int) $data['novel_id'] : null);
        if ($novelId === null && empty($data['standalone'])) {
            return redirect()->route('audiobooks.manage', ['for' => $id])->with('error', 'Choose a book, or leave it standalone.');
        }

        if (($error = $matcher->match($audiobook, $novelId)) !== null) {
            return redirect()->route('audiobooks.manage', ['for' => $id])->with('error', $error);
        }
        $audiobook->refresh()->load('novel');

        return redirect()->route('audiobooks.manage')->with('success', $novelId === null
            ? "“{$audiobook->title}” is a standalone audiobook."
            : "“{$audiobook->title}” is now the audiobook of “{$audiobook->novel?->name}”.");
    }
}
