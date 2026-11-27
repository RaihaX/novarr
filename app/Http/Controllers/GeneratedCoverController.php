<?php

namespace App\Http\Controllers;

use App\Covers\CoverGenerator;
use App\Jobs\GenerateCover;
use App\Novel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Generate a cover" / "Regenerate cover" (the owner; RouteAccess admin):
 *
 *   POST /novels/{id}/covers/generate   queue one AI cover (App\Jobs\GenerateCover)
 *                                       for the series, whatever its cover now
 *                                       (the owner's choice); JSON for the page's
 *                                       script, a redirect for a plain form
 *   GET  /novels/{id}/covers/generate   its progress: {state: queued | painting
 *                                       | done | failed | idle, message,
 *                                       cover_url when done}
 */
class GeneratedCoverController extends Controller
{
    /** A queued or painting request older than this (seconds) is taken as lost. */
    public const STALE_AFTER = 1800;

    public function store(int $id, Request $request): JsonResponse|RedirectResponse
    {
        $novel = Novel::findOrFail($id);
        if (!CoverGenerator::enabled()) {
            $message = 'AI covers are not set up (NOVARR_COVERS_AI_PAINTER_URL).';

            return $request->expectsJson()
                ? response()->json(['state' => CoverGenerator::STATE_FAILED, 'message' => $message], 503)
                : back()->with('error', $message);
        }

        $status = CoverGenerator::status($novel->id);
        $running = $status !== null
            && in_array($status['state'], [CoverGenerator::STATE_QUEUED, CoverGenerator::STATE_PAINTING], true)
            && now()->getTimestamp() - (int) $status['at'] < self::STALE_AFTER;
        if (!$running) {
            CoverGenerator::setStatus($novel->id, CoverGenerator::STATE_QUEUED, 'Waiting for the GPU…');
            GenerateCover::dispatch((int) $novel->id)->onQueue('commands');
            $status = CoverGenerator::status($novel->id);
        }

        if (!$request->expectsJson()) {
            return redirect()->route('novels.show', $novel->id)->with('success', 'Painting a new cover. It takes about a minute.');
        }

        return response()->json($this->payload($novel, $status), 202);
    }

    public function status(int $id): JsonResponse
    {
        $novel = Novel::with('file')->findOrFail($id);

        return response()->json($this->payload($novel, CoverGenerator::status($novel->id)));
    }

    private function payload(Novel $novel, ?array $status): array
    {
        $state = $status['state'] ?? 'idle';
        $payload = [
            'state' => $state,
            'message' => (string) ($status['message'] ?? ''),
            'status_url' => route('novels.covers.generate_status', $novel->id),
        ];
        if ($state === CoverGenerator::STATE_DONE) {
            $novel->load('file');
            $payload['cover_url'] = $novel->coverUrl('md');
        }

        return $payload;
    }
}
