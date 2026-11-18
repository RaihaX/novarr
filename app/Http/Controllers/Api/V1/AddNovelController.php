<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Helpers\CacheHelper;
use App\Jobs\RunNovelCommand;
use App\Novel;
use App\Services\DuplicateSeries;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/novels — add a Discover result to the library.
 *
 * The web Discover page adds through the `novel:create {name} {url}`
 * background command, which saves the novel and then fetches its metadata,
 * genres and cover in the same run. Here the row is saved in the request,
 * as novel:create saves it (name, source URL, group 1, origin inferred), so
 * the answer can carry the novel; the fetch then runs as `novel:metadata
 * {id}` on the `commands` queue, which does the same metadata, genre and
 * cover work for an existing novel. As on the web, the chapter list is not
 * scraped yet (POST /api/v1/novels/{id}/check does that).
 *
 * Duplicates (DuplicateSeries): the same source page answers the library
 * novel (200, already_in_library: true), as before. The same title in the
 * medium from another source answers 409 {message, duplicate: {id, title,
 * source}}, unless the request carries force=1 (two works can share a title).
 */
class AddNovelController extends Controller
{
    public function store(Request $request, DuplicateSeries $duplicates): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'url' => 'required|url|max:2048',
            'force' => 'nullable|boolean',
        ]);
        $title = trim($data['title']);
        $url = trim($data['url']);
        $force = $request->boolean('force');

        // The decision and the insert are made under one lock, so two taps
        // on "Add" cannot both create the novel.
        try {
            $result = Cache::lock('api_add_novel_lock', 10)->block(5, fn() => $this->findOrCreate($duplicates, $title, $url, $force));
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Another novel is being added right now. Try again in a moment.'], 503);
        }

        if (isset($result['duplicate'])) {
            Log::info('Refused to add a duplicate series from the API', ['title' => $title, 'url' => $url, 'existing' => $result['duplicate']->id]);

            return response()->json([
                'message' => DuplicateSeries::message($result['duplicate']),
                'duplicate' => DuplicateSeries::describe($result['duplicate']),
            ], 409);
        }

        if ($result['created']) {
            CacheHelper::clearNovelCache($result['id']);
            $this->queueMetadata($result['id']);
        }

        $novel = Presenter::loadNovels([$result['id']])->get($result['id']);
        $body = ['novel' => Presenter::summary($novel, Presenter::context())];

        if (!$result['created']) {
            return response()->json($body + ['already_in_library' => true]);
        }

        return response()->json($body + ['already_in_library' => false], 201);
    }

    /**
     * The library novel this URL already is (the same source page), else the
     * series of the same title it would duplicate (unless forced), else a
     * new one saved as novel:create saves it.
     *
     * @return array{id?: int, created?: bool, duplicate?: Novel}
     */
    private function findOrCreate(DuplicateSeries $duplicates, string $title, string $url, bool $force): array
    {
        // Novel or manga: decided once, by the source that claims the URL.
        $medium = DuplicateSeries::mediumFor($url);

        $existing = $duplicates->find($title, $medium, [], $url);
        if ($existing !== null && $existing['by'] === DuplicateSeries::BY_SOURCE) {
            return ['id' => (int) $existing['novel']->id, 'created' => false];
        }
        if ($existing !== null && !$force) {
            return ['duplicate' => $existing['novel']];
        }

        $novel = new Novel();
        $novel->name = $title;
        $novel->translator_url = $url;
        $novel->group_id = 1;
        $novel->medium = $medium;
        $novel->save();
        if (!$novel->isManga()) {
            $novel->inferOrigin();
        }

        return ['id' => (int) $novel->id, 'created' => true];
    }

    private function queueMetadata(int $novelId): void
    {
        $jobId = uniqid('cmd_', true);
        Log::info('Queuing metadata for a novel added from the API', ['job_id' => $jobId, 'novel' => $novelId]);

        try {
            Bus::dispatch((new RunNovelCommand('novel:metadata', ['novel' => $novelId], $jobId))->onQueue('commands'));
        } catch (\Throwable $e) {
            // On the sync queue the job runs here and rethrows after
            // recording its result; the novel exists either way, and its
            // metadata can be fetched again from the novel page.
            Log::warning("Metadata for novel {$novelId} (added from the API) failed: " . $e->getMessage());
        }
    }
}
