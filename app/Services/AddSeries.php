<?php

namespace App\Services;

use App\Http\Helpers\CacheHelper;
use App\Jobs\RunNovelCommand;
use App\Novel;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Add a series to the library from a title and a source URL: the one path
 * POST /api/v1/novels (Api\V1\AddNovelController) and approving a reader's
 * request (App\Services\Requests\Requests::approve()) share.
 *
 * The row is saved as `novel:create` saves it (name, source URL, group 1,
 * medium from the source that claims the URL, origin inferred for text);
 * the metadata, genre and cover fetch then runs as `novel:metadata {id}` on
 * the `commands` queue.
 *
 * Duplicates (DuplicateSeries): the same source page is the library series
 * (`existing`); the same title in the medium from another source is a
 * `duplicate` unless forced (two works can share a title). The decision and
 * the insert are made under one lock, so two taps cannot both create it.
 */
class AddSeries
{
    public const LOCK = 'api_add_novel_lock';

    public const CREATED = 'created';
    public const EXISTING = 'existing';
    public const DUPLICATE = 'duplicate';

    public function __construct(private DuplicateSeries $duplicates)
    {
    }

    /**
     * @return array{result: string, novel: Novel}  result: created | existing | duplicate
     *
     * @throws LockTimeoutException another add holds the lock for more than 5 s
     */
    public function add(string $title, string $url, bool $force = false): array
    {
        $title = trim($title);
        $url = trim($url);

        $outcome = Cache::lock(self::LOCK, 10)->block(5, fn() => $this->findOrCreate($title, $url, $force));

        if ($outcome['result'] === self::DUPLICATE) {
            Log::info('Refused to add a duplicate series', ['title' => $title, 'url' => $url, 'existing' => $outcome['novel']->id]);
        }
        if ($outcome['result'] === self::CREATED) {
            CacheHelper::clearNovelCache((int) $outcome['novel']->id);
            $this->queueMetadata((int) $outcome['novel']->id);
        }

        return $outcome;
    }

    /**
     * The library novel this URL already is (the same source page), else the
     * series of the same title it would duplicate (unless forced), else a
     * new one saved as novel:create saves it.
     *
     * @return array{result: string, novel: Novel}
     */
    private function findOrCreate(string $title, string $url, bool $force): array
    {
        // Novel or manga: decided once, by the source that claims the URL.
        $medium = DuplicateSeries::mediumFor($url);

        $existing = $this->duplicates->find($title, $medium, [], $url);
        if ($existing !== null && $existing['by'] === DuplicateSeries::BY_SOURCE) {
            return ['result' => self::EXISTING, 'novel' => $existing['novel']];
        }
        if ($existing !== null && !$force) {
            return ['result' => self::DUPLICATE, 'novel' => $existing['novel']];
        }

        $novel = new Novel();
        $novel->name = $title;
        $novel->translator_url = $url;
        $novel->group_id = 1;
        $novel->medium = $medium;
        $novel->save();
        if (!$novel->hasPages()) {
            $novel->inferOrigin();
        }

        return ['result' => self::CREATED, 'novel' => $novel];
    }

    public function queueMetadata(int $novelId): void
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
