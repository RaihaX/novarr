<?php

namespace App\Http\Controllers\Api\V1;

use App\Audiobook;
use App\AudiobookBookmark;
use App\Audiobooks\AudiobookInbox;
use App\Audiobooks\AudiobookLibrary;
use App\Audiobooks\AudiobookMatcher;
use App\Audiobooks\ListeningProgress;
use App\Http\Controllers\Controller;
use App\Jobs\ScanAudiobooks;
use App\Support\CurrentReader;
use App\Support\ReadingClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The audiobooks API (see the audiobooks contract, v1): the list, one
 * audiobook with its parts / chapters / the reader's bookmarks, streaming a
 * part (HTTP Range), the reader's progress and bookmarks, and the owner's
 * inbox, match and "scan now".
 *
 * Every reader sees every audiobook that is not removed (status is only
 * informational); progress and bookmarks are each reader's own.
 */
class AudiobookController extends Controller
{
    public const PER_PAGE = 50;

    public const CONTINUE_LIMIT = 12;

    public function __construct(private ListeningProgress $progress)
    {
    }

    private static function readerId(): int
    {
        return app(CurrentReader::class)->id();
    }

    /** GET /api/v1/audiobooks?page=&search=&status= — by title. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:200',
            'status' => 'nullable|in:' . Audiobook::STATUS_READY . ',' . Audiobook::STATUS_UNMATCHED,
        ]);
        $page = (int) ($data['page'] ?? 1);
        $search = trim((string) ($data['search'] ?? ''));

        $rows = Audiobook::query()
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $q->where(fn($w) => $w->where('title', 'like', $like)
                    ->orWhere('author', 'like', $like)
                    ->orWhere('narrator', 'like', $like)
                    ->orWhereHas('novel', fn($n) => $n->where('name', 'like', $like)->orWhere('author', 'like', $like)));
            })
            ->when(isset($data['status']), fn($q) => $q->where('status', $data['status']))
            ->orderBy('title')
            ->orderBy('id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        $items = AudiobookPresenter::load($rows->take(self::PER_PAGE));
        $progress = $this->progress->forMany(static::readerId(), $items->pluck('id')->map(fn($id) => (int) $id)->all());

        return response()->json([
            'audiobooks' => $items->map(fn(Audiobook $a) => AudiobookPresenter::summary($a, $progress[(int) $a->id] ?? null))->values()->all(),
            'page' => $page,
            'has_more' => $rows->count() > self::PER_PAGE,
        ]);
    }

    /** GET /api/v1/audiobooks/continue — started and not finished, newest progress first (12). */
    public function continue(): JsonResponse
    {
        return response()->json(['audiobooks' => static::continueShelf($this->progress, static::readerId())]);
    }

    /** The Continue listening summaries for a reader (the API and Home). */
    public static function continueShelf(ListeningProgress $progress, int $userId, int $limit = self::CONTINUE_LIMIT): array
    {
        $ids = $progress->continueIds($userId, $limit);
        if ($ids === []) {
            return [];
        }
        $audiobooks = AudiobookPresenter::load(Audiobook::whereIn('id', $ids)->get())->keyBy('id');
        $rows = $progress->forMany($userId, $ids);

        $out = [];
        foreach ($ids as $id) {
            if ($audiobook = $audiobooks->get($id)) {
                $out[] = AudiobookPresenter::summary($audiobook, $rows[$id] ?? null);
            }
        }

        return $out;
    }

    /** GET /api/v1/audiobooks/{id} — the detail, with the reader's progress and bookmarks. */
    public function show(int $id): JsonResponse
    {
        $audiobook = static::find($id);
        $userId = static::readerId();

        return response()->json(['audiobook' => AudiobookPresenter::detail(
            $audiobook,
            $this->progress->get($userId, $id),
            static::bookmarks($userId, $id)
        )]);
    }

    /**
     * GET /api/v1/audiobooks/{id}/parts/{index} — the file as it is, with
     * HTTP Range (206), its Content-Type, Accept-Ranges and an ETag.
     * Opened by a bearer token, the web session or the signed URL the
     * detail hands out (RouteAccess::SIGNED_ROUTES).
     */
    public function part(int $id, int $index): BinaryFileResponse
    {
        $audiobook = static::find($id);
        $part = $audiobook->parts->firstWhere('idx', $index);
        abort_if($part === null, 404, 'That part of the audiobook does not exist.');

        $root = realpath(AudiobookLibrary::root());
        $path = realpath($audiobook->directory() . '/' . $part->path);
        // The file must be inside the library (the row's path is never trusted to stay there).
        abort_if($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path), 404,
            'The audio file is missing from the audiobooks library.');

        // The ETag names the file's version (size and time), not its
        // content: hashing a 500 MB part per request would be ruinous.
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $part->mime ?: AudiobookLibrary::mimeFor($path),
        ], false, null, false, true);
        $response->setEtag(substr(sha1($audiobook->id . '|' . $part->idx . '|' . filesize($path) . '|' . filemtime($path)), 0, 32));
        $response->setPrivate();
        $response->setMaxAge(86400);
        $response->headers->set('Accept-Ranges', 'bytes');

        return $response;
    }

    /**
     * PUT /api/v1/audiobooks/{id}/progress — {position_ms, speed, finished?,
     * recorded_at?} → {progress, stale}. An older write than the stored one
     * is ignored (stale: true) and answered with what is stored.
     */
    public function saveProgress(Request $request, int $id): JsonResponse
    {
        $audiobook = static::find($id, false);
        $data = $request->validate([
            'position_ms' => 'required|integer|min:0',
            'speed' => 'required|numeric|min:' . ListeningProgress::MIN_SPEED . '|max:' . ListeningProgress::MAX_SPEED,
            'finished' => 'sometimes|nullable|boolean',
            'recorded_at' => 'sometimes|nullable|date',
        ]);

        $result = $this->progress->record(
            static::readerId(),
            $audiobook,
            (int) $data['position_ms'],
            (float) $data['speed'],
            array_key_exists('finished', $data) && $data['finished'] !== null ? (bool) $data['finished'] : null,
            !empty($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : null,
            ReadingClient::from($request),
        );

        return response()->json(['progress' => ListeningProgress::present($result['row']), 'stale' => $result['stale']]);
    }

    /** POST /api/v1/audiobooks/{id}/bookmarks — {position_ms, note?} → 201 {bookmark}. */
    public function storeBookmark(Request $request, int $id): JsonResponse
    {
        $audiobook = static::find($id, false);
        $data = $request->validate([
            'position_ms' => 'required|integer|min:0',
            'note' => 'nullable|string|max:' . AudiobookBookmark::MAX_NOTE,
        ]);
        $userId = static::readerId();
        if (AudiobookBookmark::where('user_id', $userId)->where('audiobook_id', $id)->count() >= AudiobookBookmark::MAX_PER_AUDIOBOOK) {
            return response()->json(['message' => 'You have ' . AudiobookBookmark::MAX_PER_AUDIOBOOK . ' bookmarks in this audiobook already: delete some first.'], 422);
        }
        $note = trim((string) ($data['note'] ?? ''));

        $bookmark = AudiobookBookmark::create([
            'user_id' => $userId,
            'audiobook_id' => $id,
            'position_ms' => min((int) $data['position_ms'], (int) $audiobook->duration_ms),
            'note' => $note !== '' ? $note : null,
        ]);

        return response()->json(['bookmark' => AudiobookPresenter::bookmark($bookmark)], 201);
    }

    /** DELETE /api/v1/audiobooks/{id}/bookmarks/{bookmarkId} → 204 (the reader's own only). */
    public function destroyBookmark(int $id, int $bookmarkId): Response
    {
        $deleted = AudiobookBookmark::whereKey($bookmarkId)
            ->where('audiobook_id', $id)
            ->where('user_id', static::readerId())
            ->delete();
        abort_if($deleted === 0, 404, 'That bookmark does not exist.');

        return response()->noContent();
    }

    /** GET /api/v1/audiobooks/inbox (admin) — what is waiting in the drop-in folder and what failed. */
    public function inbox(AudiobookInbox $inbox): JsonResponse
    {
        $status = $inbox->status();

        return response()->json([
            'pending' => $status['pending'],
            'failed' => array_map(fn($f) => [
                'name' => $f['name'],
                'kind' => $f['kind'],
                'reason' => $f['reason'],
                'failed_at' => $f['failed_at'] ? Presenter::timestamp(Carbon::createFromTimestamp($f['failed_at'])) : null,
            ], $status['failed']),
            'settle_seconds' => $status['settle_seconds'],
            'last_scan' => $status['last_scan'],
            'problem' => $status['problem'],
            'unmatched_count' => Audiobook::where('status', Audiobook::STATUS_UNMATCHED)->count(),
        ]);
    }

    /** POST /api/v1/audiobooks/{id}/match (admin) — {novel_id: int|null}: link to a book, or standalone. */
    public function match(Request $request, AudiobookMatcher $matcher, int $id): JsonResponse
    {
        $audiobook = static::find($id, false);
        $data = $request->validate(['novel_id' => 'present|nullable|integer|min:1']);

        if (($error = $matcher->match($audiobook, isset($data['novel_id']) ? (int) $data['novel_id'] : null)) !== null) {
            return response()->json(['message' => $error], 422);
        }
        $audiobook = AudiobookPresenter::load($audiobook->fresh());

        return response()->json(['audiobook' => AudiobookPresenter::summary($audiobook, $this->progress->get(static::readerId(), $id))]);
    }

    /** POST /api/v1/audiobooks/scan (admin) — queue a scan of the inbox now → 202. */
    public function scan(): JsonResponse
    {
        ScanAudiobooks::dispatch();

        return response()->json(['queued' => true, 'message' => 'A scan of the audiobooks inbox has been queued.'], 202);
    }

    /** The audiobook (not removed) or 404; with its parts and chapters unless $full is false. */
    public static function find(int $id, bool $full = true): Audiobook
    {
        $query = Audiobook::query()->whereKey($id);
        if ($full) {
            $query->with(['parts', 'chapters', 'novel.file']);
        }
        $audiobook = $query->first();
        abort_if($audiobook === null, 404, 'That audiobook does not exist.');

        return $audiobook;
    }

    /** The reader's bookmarks in an audiobook, in position order. */
    public static function bookmarks(int $userId, int $audiobookId)
    {
        return AudiobookBookmark::where('user_id', $userId)->where('audiobook_id', $audiobookId)
            ->orderBy('position_ms')->orderBy('id')->get();
    }
}
