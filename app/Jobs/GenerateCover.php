<?php

namespace App\Jobs;

use App\Covers\CoverGenerator;
use App\Covers\PainterBusy;
use App\Novel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * "Generate a cover" / "Regenerate cover" on a series page (the owner):
 * one AI cover (App\Covers\CoverGenerator), its progress in the cache for
 * the page to poll (CoverGenerator::status(): queued → painting → done |
 * failed). One at a time per series; the painter's own busy retries wait
 * for the GPU.
 */
class GenerateCover implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1500;
    public int $tries = 1;
    public int $uniqueFor = 1800;

    public function __construct(public int $novelId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->novelId;
    }

    public function handle(CoverGenerator $generator): void
    {
        $novel = Novel::with('file')->find($this->novelId);
        if ($novel === null) {
            CoverGenerator::setStatus($this->novelId, CoverGenerator::STATE_FAILED, 'The series is gone.');

            return;
        }
        if (!CoverGenerator::enabled()) {
            CoverGenerator::setStatus($this->novelId, CoverGenerator::STATE_FAILED, 'AI covers are not set up (NOVARR_COVERS_AI_PAINTER_URL).');

            return;
        }
        CoverGenerator::setStatus($this->novelId, CoverGenerator::STATE_PAINTING, 'Painting…');
        try {
            $generator->generate($novel);
        } catch (PainterBusy) {
            CoverGenerator::setStatus($this->novelId, CoverGenerator::STATE_FAILED, 'The GPU is busy. Try again in a few minutes.');

            return;
        } catch (\Throwable $e) {
            Log::warning("AI cover #{$this->novelId} failed: " . $e->getMessage());
            CoverGenerator::setStatus($this->novelId, CoverGenerator::STATE_FAILED, 'The cover could not be painted: ' . $e->getMessage());

            return;
        }
        CoverGenerator::setStatus($this->novelId, CoverGenerator::STATE_DONE, 'The new cover is ready.');
    }

    public function failed(?\Throwable $e): void
    {
        CoverGenerator::setStatus($this->novelId, CoverGenerator::STATE_FAILED, 'The cover could not be painted' . ($e ? ': ' . $e->getMessage() : '.'));
    }
}
