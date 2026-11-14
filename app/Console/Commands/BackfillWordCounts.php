<?php

namespace App\Console\Commands;

use App\ChapterText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fill chapter_texts.word_count for rows saved before the column existed.
 * Only rows still NULL are touched, so it is safe to re-run or interrupt.
 */
class BackfillWordCounts extends Command
{
    protected $signature = 'chapters:word-counts';

    protected $description = 'Backfill stored word counts for chapter texts that have none yet.';

    private const CHUNK = 200;

    public function handle(): int
    {
        $total = DB::table('chapter_texts')->whereNull('word_count')->count();

        if ($total === 0) {
            $this->info('All chapter texts already have a word count.');
            return self::SUCCESS;
        }

        $done = 0;

        DB::table('chapter_texts')
            ->whereNull('word_count')
            ->select(['novel_chapter_id', 'content'])
            ->chunkById(self::CHUNK, function ($rows) use (&$done, $total) {
                DB::transaction(function () use ($rows) {
                    foreach ($rows as $row) {
                        DB::table('chapter_texts')
                            ->where('novel_chapter_id', $row->novel_chapter_id)
                            ->update(['word_count' => ChapterText::countWords($row->content)]);
                    }
                });

                $done += $rows->count();
                $this->line(sprintf('Counted %s / %s chapter texts', number_format($done), number_format($total)));
            }, 'novel_chapter_id');

        $this->info("Done: {$done} chapter text(s) updated.");

        return self::SUCCESS;
    }
}
