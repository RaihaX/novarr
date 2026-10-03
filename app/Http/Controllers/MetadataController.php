<?php

namespace App\Http\Controllers;

use App\Jobs\RunNovelCommand;
use App\Novel;
use App\Scraping\NovelUpdatesMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * NovelUpdates identity for a novel: list scored search candidates and let
 * the user pick the right series by hand (stored with score 1.0).
 */
class MetadataController extends Controller
{
    /** How many candidates the edit page lists. */
    public const MAX_CANDIDATES = 5;

    /** GET /novels/{id}/metadata/candidates — top scored NovelUpdates candidates. */
    public function candidates($id): JsonResponse
    {
        $novel = Novel::findOrFail($id);

        try {
            $ranked = $this->searchCandidates($novel);
        } catch (\Throwable $e) {
            Log::warning("Metadata candidates failed for novel {$novel->id}: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'NovelUpdates search failed.'], 502);
        }

        return response()->json([
            'success' => true,
            'novel_id' => $novel->id,
            'name' => $novel->name,
            'threshold' => NovelUpdatesMatcher::THRESHOLD,
            'current' => [
                'url' => $novel->novelupdates_url,
                'score' => $novel->novelupdates_match_score === null ? null : (float) $novel->novelupdates_match_score,
            ],
            'candidates' => array_map(fn(array $c) => [
                'title' => (string) ($c['title'] ?? ''),
                'url' => (string) ($c['url'] ?? ''),
                'score' => round((float) ($c['score'] ?? 0), 3),
                'associated' => array_values((array) ($c['associated'] ?? [])),
            ], array_slice($ranked, 0, self::MAX_CANDIDATES)),
        ]);
    }

    /**
     * POST /novels/{id}/metadata/choose — use this series URL (score 1.0)
     * and queue the normal metadata refresh (novel:metadata) for it.
     */
    public function choose(Request $request, $id): JsonResponse
    {
        $novel = Novel::findOrFail($id);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'regex:#^https?://(www\.)?novelupdates\.com/series/[a-z0-9-]+/?$#i'],
        ]);

        $novel->forceFill([
            'novelupdates_url' => rtrim($validated['url'], '/') . '/',
            'novelupdates_match_score' => 1.0,
        ])->saveQuietly();

        Log::info("NovelUpdates match chosen by hand for {$novel->name} (ID {$novel->id}): {$novel->novelupdates_url}");

        $jobId = uniqid('cmd_', true);
        RunNovelCommand::dispatch('novel:metadata', ['novel' => $novel->id], $jobId)->onQueue('commands');

        return response()->json([
            'success' => true,
            'job_id' => $jobId,
            'url' => $novel->novelupdates_url,
            'score' => 1.0,
            'message' => 'NovelUpdates match saved — refreshing metadata.',
        ]);
    }

    /**
     * Ranked candidates (each with 'score') from NovelUpdates' search.
     * Protected so tests can stub the network.
     */
    protected function searchCandidates(Novel $novel): array
    {
        resolveNovelUpdatesUrl($novel->name, $novel->author, $report);

        return $report['candidates'] ?? [];
    }
}
