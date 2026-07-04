<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\NovelChapter;

class ChapterCleaner extends Command
{
    protected $signature = 'novel:chaptercleaner {novel : Novel ID whose thin chapters should be reset}';

    protected $description = 'Blank and reset chapters that saved with little content (≤10 paragraphs) so the next scrape re-downloads them.';

    public function handle()
    {
        $novelId = (int) $this->argument('novel');

        if ($novelId === 0) {
            $this->error('Pass a novel id — this resets chapter content, so it never sweeps all novels.');
            return 1;
        }

        $reset = 0;
        NovelChapter::with('text')
            ->where('novel_id', $novelId)
            ->where('status', 1)
            ->chunkById(100, function ($chapters) use (&$reset) {
                foreach ($chapters as $chapter) {
                    $paragraphs = substr_count($chapter->rawText() ?? '', '<p>');

                    if ($paragraphs <= 10) {
                        $chapter->description = '';
                        $chapter->status = 0;
                        $chapter->save();
                        $reset++;
                        $this->line("Reset chapter {$chapter->chapter} ({$paragraphs} paragraphs)");
                    }
                }
            });

        $this->info("Reset {$reset} thin chapter(s) — they will re-download on the next scrape.");
        return 0;
    }
}
