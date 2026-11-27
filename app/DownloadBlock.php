<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A blocklisted release (download_blocklist): it failed once, so it is never
 * grabbed automatically again. The same release: Prowlarr's guid, or its
 * title on the same indexer.
 */
class DownloadBlock extends Model
{
    protected $table = 'download_blocklist';

    protected $guarded = ['id'];

    protected $casts = ['grab_id' => 'integer'];

    /** Is this search result blocklisted? */
    public static function blocks(array $result, ?array $entries = null): bool
    {
        $entries ??= static::query()->get(['guid', 'title', 'indexer'])->all();
        $guid = (string) ($result['guid'] ?? '');
        $title = mb_strtolower(trim((string) ($result['title'] ?? '')));
        $indexer = mb_strtolower((string) ($result['indexer'] ?? ''));
        foreach ($entries as $entry) {
            if ($guid !== '' && (string) $entry->guid === $guid) {
                return true;
            }
            if ($title !== '' && mb_strtolower(trim((string) $entry->title)) === $title && mb_strtolower((string) $entry->indexer) === $indexer) {
                return true;
            }
        }

        return false;
    }
}
