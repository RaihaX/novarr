<?php

namespace App\Console\Commands;

use App\Books\BookCovers;
use App\Books\BookLookup;
use Illuminate\Console\Command;

/**
 * The best cover for each book (App\Books\BookCovers):
 *
 *   books:covers              books never checked: a placeholder (none, tiny,
 *                             a generated text card) is replaced, a real cover
 *                             upgraded when a much larger one of the same book exists
 *   books:covers --missing    only books whose cover is a placeholder
 *   books:covers --upgrade    only books with a real cover
 *   books:covers --id=12      these books (repeatable), checked or not
 *   books:covers --recheck    books checked before too
 *   books:covers --dry-run    say what would change, change nothing
 *
 * A cover the owner chose is never replaced. Runs nightly (routes/console.php).
 */
class BookCoversCommand extends Command
{
    protected $signature = 'books:covers
        {--missing : Only books without a real cover}
        {--upgrade : Only books that have a real cover}
        {--id=* : Only these books}
        {--limit=200 : At most this many books}
        {--recheck : Check books that were checked before too}
        {--dry-run : Say what would change without saving}';

    protected $description = 'Find covers for books without a real one, and larger covers for the rest';

    public function handle(BookCovers $covers): int
    {
        if (BookLookup::sources() === []) {
            $this->warn('No book catalogue is switched on (NOVARR_BOOK_SOURCES).');

            return self::SUCCESS;
        }
        $mode = $this->option('missing') && !$this->option('upgrade') ? 'missing'
            : ($this->option('upgrade') && !$this->option('missing') ? 'upgrade' : 'all');
        $dryRun = (bool) $this->option('dry-run');
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $books = BookCovers::due($ids, (bool) $this->option('recheck'), (int) $this->option('limit'));

        $counts = ['books' => 0, 'replaced' => 0, 'kept' => 0, 'skipped' => 0];
        foreach ($books as $book) {
            $counts['books']++;
            try {
                $result = $covers->check($book, $mode, $dryRun);
            } catch (\Throwable $e) {
                $this->warn("#{$book->id} {$book->name}: " . $e->getMessage());

                continue;
            }
            $counts[$result['action']]++;
            // A book left out only by --missing / --upgrade stays due for a full run.
            if (!$dryRun && ($mode === 'all' || $result['action'] !== 'skipped')) {
                BookCovers::markChecked($book);
            }
            if ($result['action'] === 'replaced' || $this->getOutput()->isVerbose()) {
                $best = $result['best'];
                $this->line(($dryRun ? '[dry run] ' : '') . "#{$book->id} {$book->name}: {$result['action']} ({$result['reason']})"
                    . ($best ? " — {$best['source_name']} {$best['width']}×{$best['height']}" : ''));
            }
        }
        $this->info(($dryRun ? '[dry run] ' : '') . "{$counts['books']} books: {$counts['replaced']} new covers, {$counts['kept']} kept, {$counts['skipped']} skipped.");

        return self::SUCCESS;
    }
}
