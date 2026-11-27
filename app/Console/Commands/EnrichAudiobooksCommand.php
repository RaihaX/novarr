<?php

namespace App\Console\Commands;

use App\Audiobook;
use App\Audiobooks\AudiobookEnricher;
use App\Audiobooks\AudiobookMatcher;
use Illuminate\Console\Command;

/**
 * Audible / Apple Books metadata for audiobooks already in the library
 * (App\Audiobooks\AudiobookEnricher):
 *
 *   audiobooks:enrich            the ones never looked up (enriched_at null)
 *   audiobooks:enrich --all      every audiobook again
 *   audiobooks:enrich --id=12    one (repeatable)
 *
 * An unmatched one that Audible finds is matched again with Audible's
 * title and author too.
 */
class EnrichAudiobooksCommand extends Command
{
    protected $signature = 'audiobooks:enrich
        {--id=* : Only these audiobooks}
        {--all : Look up every audiobook again, not only the ones never looked up}
        {--limit=200 : At most this many}';

    protected $description = 'Fill audiobooks\' ASIN, narrator, description, series, cover and chapter names from Audible (and Apple Books)';

    public function handle(AudiobookEnricher $enricher, AudiobookMatcher $matcher): int
    {
        if (AudiobookEnricher::sources() === []) {
            $this->warn('No audiobook catalogue is switched on (NOVARR_AUDIOBOOK_SOURCES).');

            return self::SUCCESS;
        }
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $query = Audiobook::query()->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } elseif (!$this->option('all')) {
            $query->whereNull('enriched_at');
        }
        $audiobooks = $query->limit(max(1, (int) $this->option('limit')))->get();

        $counts = ['seen' => 0, 'matched' => 0, 'changed' => 0, 'failed' => 0];
        foreach ($audiobooks as $audiobook) {
            $counts['seen']++;
            $result = $enricher->enrich($audiobook);
            if (!$result['asked']) {
                $counts['failed']++;
            }
            if ($result['matched']) {
                $counts['matched']++;
            }
            if ($result['changed'] !== []) {
                $counts['changed']++;
                $this->line("#{$audiobook->id} {$audiobook->title}: " . implode(', ', $result['changed']));
            }
            static::rematch($matcher, $audiobook, $result);
        }
        $this->info("{$counts['seen']} looked up, {$counts['matched']} found on Audible, {$counts['changed']} changed, {$counts['failed']} could not be asked.");

        return self::SUCCESS;
    }

    /** An unmatched audiobook Audible found: matched again with Audible's title and author too. */
    public static function rematch(AudiobookMatcher $matcher, Audiobook $audiobook, array $result): void
    {
        if ($audiobook->status !== Audiobook::STATUS_UNMATCHED || $result['product'] === null) {
            return;
        }
        $matcher->autoMatch($audiobook->fresh(), [
            'catalogue_title' => $result['product']['title'],
            'catalogue_author' => implode(', ', $result['product']['authors']) ?: null,
        ]);
    }
}
