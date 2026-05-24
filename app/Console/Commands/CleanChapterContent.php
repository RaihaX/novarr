<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\NovelChapter;

class CleanChapterContent extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'novel:clean_chapter_content
                            {novel : Novel ID to clean}
                            {--dry-run : Report what would change without saving}';

    /**
     * The console command description.
     */
    protected $description = 'Strip leftover <style> CSS and Taboola/Outbrain ad widget text from previously downloaded chapter descriptions.';

    /**
     * Marker substrings that identify a paragraph as widget/ad noise.
     * If a <p> contains any of these, that paragraph and every paragraph after it
     * is dropped (the widget block always lives at the tail of the content).
     */
    protected array $tailMarkers = [
        'Sponsored',
        'Read MoreUndo',
        'Play NowUndo',
        'taboola',
        'Outbrain',
    ];

    /**
     * Marker substrings for leading/inline CSS noise. Any <p> containing one of
     * these is dropped wherever it appears.
     */
    protected array $inlineMarkers = [
        'pf-config-',
        '!important',
    ];

    public function handle()
    {
        $novelId = (int) $this->argument('novel');
        $dryRun = (bool) $this->option('dry-run');

        if ($novelId <= 0) {
            $this->error('A novel ID is required.');
            return 1;
        }

        $query = NovelChapter::where('novel_id', $novelId)
            ->where('status', 1)
            ->whereNotNull('description');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("No downloaded chapters found for novel {$novelId}.");
            return 0;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Scanning {$total} chapters for novel {$novelId}...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $changed = 0;
        $tailTruncated = 0;
        $cssRemoved = 0;
        $bytesSaved = 0;

        $query->chunkById(200, function ($chapters) use (&$changed, &$tailTruncated, &$cssRemoved, &$bytesSaved, $dryRun, $bar) {
            foreach ($chapters as $chapter) {
                $original = $chapter->description;
                if ($original === null || $original === '') {
                    $bar->advance();
                    continue;
                }

                [$cleaned, $stats] = $this->cleanDescription($original);

                if ($cleaned !== $original) {
                    $changed++;
                    $bytesSaved += strlen($original) - strlen($cleaned);
                    if ($stats['tail_truncated']) {
                        $tailTruncated++;
                    }
                    if ($stats['css_removed']) {
                        $cssRemoved++;
                    }

                    if (!$dryRun) {
                        $chapter->description = $cleaned;
                        $chapter->save();
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->line('');

        $this->table(
            ['Metric', 'Count'],
            [
                ['Chapters scanned', $total],
                ['Chapters changed', $changed],
                ['Tail (widget) truncated', $tailTruncated],
                ['CSS paragraph removed', $cssRemoved],
                ['Bytes saved', number_format($bytesSaved)],
            ]
        );

        if ($dryRun) {
            $this->warn('Dry run — no rows were saved.');
        }

        return 0;
    }

    /**
     * Clean one chapter description.
     *
     * Strategy:
     *   - Split into <p>...</p> paragraphs (the scraper always wraps content this way).
     *   - Drop paragraphs containing inline CSS markers (e.g. pf-config-...).
     *   - For paragraphs containing a tail marker, truncate at the widget boundary
     *     (the widget text is appended after a blank-line + indenting whitespace gap
     *     onto the end of a legitimate story paragraph). Then drop every paragraph
     *     after it.
     *   - Re-join.
     */
    protected function cleanDescription(string $html): array
    {
        $stats = ['tail_truncated' => false, 'css_removed' => false];

        if (!preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches, PREG_SET_ORDER)) {
            return [$html, $stats];
        }

        $kept = [];

        foreach ($matches as $match) {
            $full = $match[0];
            $inner = $match[1];
            $text = trim(strip_tags($inner));

            if ($this->containsAny($text, $this->inlineMarkers)) {
                $stats['css_removed'] = true;
                continue; // drop just this paragraph
            }

            if ($this->containsAny($text, $this->tailMarkers)) {
                $stats['tail_truncated'] = true;

                // Find the earliest split point: either the "\n\n+ indent" gap that
                // separates story text from widget text, or the first occurrence of a
                // tail marker if there's no such gap.
                $cutPos = $this->findWidgetBoundary($inner);

                $beforeCut = $cutPos !== null ? rtrim(substr($inner, 0, $cutPos)) : '';

                // Only keep the truncated paragraph if it still has real story text
                // (>= 20 chars after trim). Otherwise discard the whole paragraph.
                if (strlen(trim(strip_tags($beforeCut))) >= 20) {
                    $kept[] = '<p>' . $beforeCut . '</p>';
                }

                break; // drop everything after this paragraph
            }

            $kept[] = $full;
        }

        return [implode('', $kept), $stats];
    }

    /**
     * Locate the boundary inside a <p> where the widget block begins. The scraper
     * preserves the original whitespace, so the widget consistently follows two or
     * more newlines + indenting spaces. Fall back to the first tail-marker position.
     */
    protected function findWidgetBoundary(string $inner): ?int
    {
        if (preg_match('/\n{2,}\s+/', $inner, $m, PREG_OFFSET_CAPTURE)) {
            return $m[0][1];
        }

        $earliest = null;
        foreach ($this->tailMarkers as $marker) {
            $pos = stripos($inner, $marker);
            if ($pos !== false && ($earliest === null || $pos < $earliest)) {
                $earliest = $pos;
            }
        }

        return $earliest;
    }

    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
