<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\NovelChapter;

class ChapterCleaner extends Command
{
    protected $signature = 'novel:chaptercleaner
                            {novel : Novel ID whose thin chapters should be reset}
                            {--dry-run : List the chapters that would be reset without changing them}';

    protected $description = 'Re-queue downloaded chapters too short to pass the scraper\'s word-count rule so the next scrape re-downloads them.';

    public function handle()
    {
        $novelId = (int) $this->argument('novel');
        $dryRun = (bool) $this->option('dry-run');

        if ($novelId === 0) {
            $this->error('Pass a novel id — this resets chapter content, so it never sweeps all novels.');
            return 1;
        }

        $minWords = (int) setting('min_chapter_words', 250);

        if ($dryRun) {
            $this->info('[dry-run] No chapters will be changed.');
        }

        $reset = 0;
        NovelChapter::with('text')
            ->where('novel_id', $novelId)
            ->where('status', 1)
            ->chunkById(100, function ($chapters) use (&$reset, $minWords, $dryRun) {
                foreach ($chapters as $chapter) {
                    $text = $chapter->rawText() ?? '';

                    if (!self::isThin($chapter->label, $text, $minWords)) {
                        continue;
                    }

                    $words = self::wordCount($text);
                    $reset++;

                    if ($dryRun) {
                        $this->line("Would reset chapter {$chapter->chapter} - {$chapter->label} ({$words} words)");
                        continue;
                    }

                    // Re-queue only: keep the stored text until the scraper
                    // overwrites it with a better fetch.
                    $chapter->status = 0;
                    $chapter->save();
                    $this->line("Reset chapter {$chapter->chapter} - {$chapter->label} ({$words} words)");
                }
            });

        $this->info($dryRun
            ? "{$reset} thin chapter(s) would be reset."
            : "Reset {$reset} thin chapter(s) — they will re-download on the next scrape.");
        return 0;
    }

    /** Words a reader would see in stored chapter HTML. */
    public static function wordCount(string $text): int
    {
        return ChapterScraper::countChapterWords($text);
    }

    /**
     * A downloaded chapter is "thin" when the scraper would not have accepted
     * it: same rule as ChapterScraper (threshold, short specials, author notes).
     */
    public static function isThin(?string $label, string $text, int $minWords): bool
    {
        $words = self::wordCount($text);

        return !ChapterScraper::acceptableWordCount($label, $words, $minWords, $text);
    }
}
