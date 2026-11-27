<?php

namespace App\Console\Commands;

use App\Covers\CoverGenerator;
use App\Covers\CoverPainter;
use App\Covers\PainterBusy;
use Illuminate\Console\Command;

/**
 * AI covers for series without a real one (App\Covers\CoverGenerator):
 *
 *   covers:generate               up to --limit series whose cover is missing
 *                                 or a placeholder (text card, blank page),
 *                                 books once the online search found nothing
 *   covers:generate --id=12       these series (repeatable), without waiting
 *                                 for the online search or a new series' source
 *   covers:generate --regenerate  repaint AI covers too (with --id: those;
 *                                 alone: the oldest AI covers)
 *   covers:generate --dry-run     say which series would get one, paint nothing
 *
 * Never a cover the owner chose. The GPU is shared (Frigate): one painting
 * at a time, a busy painter (503) is asked again with a growing wait, and
 * the run stops after novarr.covers.ai.max_failures failures in a row.
 * Nightly at 02:10 (routes/console.php).
 */
class GenerateCoversCommand extends Command
{
    protected $signature = 'covers:generate
        {--id=* : Only these series}
        {--limit=20 : Paint at most this many covers}
        {--regenerate : Repaint AI covers too}
        {--dry-run : Say which series would get a cover, paint nothing}';

    protected $description = 'Paint AI covers (local LLM brief + SDXL) for series without a real cover';

    public function handle(CoverGenerator $generator, CoverPainter $painter): int
    {
        if (!CoverGenerator::enabled()) {
            $this->warn('AI covers are off: set NOVARR_COVERS_AI_PAINTER_URL (and NOVARR_COVERS_AI_OLLAMA_URL for briefs from the text); GD with FreeType is needed too.');

            return self::SUCCESS;
        }
        $dryRun = (bool) $this->option('dry-run');
        $regenerate = (bool) $this->option('regenerate');
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $limit = max(1, (int) $this->option('limit'));
        $maxFailures = max(1, (int) config('novarr.covers.ai.max_failures', 3));

        if (!$dryRun && $painter->health() === null) {
            $this->error('The cover painter at ' . CoverPainter::url() . ' does not answer.');

            return self::FAILURE;
        }

        $counts = ['painted' => 0, 'failed' => 0, 'skipped' => 0];
        $failuresInARow = 0;
        $attempts = 0;
        foreach (CoverGenerator::candidates($ids, $regenerate)->lazyById(100) as $novel) {
            if ($attempts >= $limit) {
                break;
            }
            $check = $generator->eligibility($novel, $regenerate, $ids !== []);
            if (!$check['ok']) {
                $counts['skipped']++;
                if ($check['reason'] === CoverGenerator::REASON_REAL_COVER && !$dryRun) {
                    CoverGenerator::markReal($novel);
                }
                if ($ids !== [] || $this->getOutput()->isVerbose()) {
                    $this->line("#{$novel->id} {$novel->name}: skipped ({$check['reason']})");
                }

                continue;
            }
            $attempts++;
            if ($dryRun) {
                $this->line("[dry run] #{$novel->id} {$novel->name}: would paint ({$check['reason']})");

                continue;
            }
            try {
                $result = $generator->generate($novel);
            } catch (\Throwable $e) {
                $counts['failed']++;
                $failuresInARow++;
                $this->warn("#{$novel->id} {$novel->name}: " . ($e instanceof PainterBusy ? 'the GPU stayed busy' : $e->getMessage()));
                if ($failuresInARow >= $maxFailures) {
                    $this->warn("Stopping: {$failuresInARow} failures in a row.");

                    break;
                }

                continue;
            }
            $failuresInARow = 0;
            $counts['painted']++;
            $record = $result['record'];
            $this->line("#{$novel->id} {$novel->name}: painted ({$check['reason']}; brief from " . ($record['brief_source'] === 'llm' ? $record['model'] : 'the genres') . ') — ' . $record['key_scene']);
        }

        $this->info(($dryRun ? "[dry run] {$attempts} would be painted, " : "{$counts['painted']} covers painted, {$counts['failed']} failed, ") . "{$counts['skipped']} skipped.");

        return $counts['failed'] > 0 && $counts['painted'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
