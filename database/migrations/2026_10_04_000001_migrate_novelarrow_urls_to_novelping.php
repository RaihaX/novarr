<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * novelarrow.com rebranded to novelping.com: every novelarrow page now
 * 302-redirects to the same path on novelping.com and the api-web JSON API
 * answers there with the same shapes. Slugs, chapter ids and paths are
 * unchanged, so stored URLs rewrite with a plain prefix swap:
 *
 *   http(s)://(www.)novelarrow.com/…  →  https://novelping.com/…
 *
 * Columns holding source URLs: novels.translator_url / alternative_url /
 * chapter_url, novel_chapters.url, groups.url. Covers are downloaded into
 * storage (no cover URL is stored), and audits.url is a request log, so
 * neither is touched.
 *
 * Statements stay portable (the test suite runs migrations on SQLite):
 * REPLACE() + LIKE on both drivers. novel_chapters is updated in id-range
 * chunks so no single statement locks the whole table on MariaDB. Each
 * UPDATE only matches rows still on an old prefix, so re-running is a no-op.
 *
 * The scraper's sync treats a host-only URL change as a domain move (no
 * re-download), so a TOC run before/after this migration is safe either way.
 */
return new class extends Migration
{
    private const NEW = 'https://novelping.com';

    private const OLD = [
        'https://novelarrow.com',
        'http://novelarrow.com',
        'https://www.novelarrow.com',
        'http://www.novelarrow.com',
    ];

    /** table => URL columns. */
    private const COLUMNS = [
        'novels' => ['translator_url', 'alternative_url', 'chapter_url'],
        'novel_chapters' => ['url'],
        'groups' => ['url'],
    ];

    private const CHUNK = 5000;

    public function up(): void
    {
        $this->rewrite(self::OLD, self::NEW);
    }

    public function down(): void
    {
        // Back to the canonical old host. Note this also moves URLs that were
        // added on novelping.com after the migration — novelarrow.com still
        // redirects, so they keep working.
        $this->rewrite([self::NEW, 'http://novelping.com', 'https://www.novelping.com', 'http://www.novelping.com'], 'https://novelarrow.com');
    }

    /** Swap every $from prefix for $to across the URL columns, logging counts. */
    private function rewrite(array $from, string $to): void
    {
        $counts = [];
        foreach (self::COLUMNS as $table => $columns) {
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (!DB::getSchemaBuilder()->hasColumn($table, $column)) {
                    continue;
                }
                $total = 0;
                foreach ($from as $prefix) {
                    $total += $this->replacePrefix($table, $column, $prefix, $to);
                }
                $counts["{$table}.{$column}"] = $total;
                $this->say("  {$table}.{$column}: {$total} row(s) rewritten to {$to}");
            }
        }

        // One summary line at warning so it survives the production
        // LOG_LEVEL=warning (a one-off data migration worth a record).
        Log::warning("Migration novelarrow→novelping: URLs rewritten to {$to}", $counts);
    }

    /** Per-column counts on the artisan console (not during the test suite). */
    private function say(string $line): void
    {
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            (new \Symfony\Component\Console\Output\ConsoleOutput())->writeln($line);
        }
    }

    /**
     * UPDATE rows whose $column starts with "$prefix/" or is exactly $prefix,
     * in id-range chunks. Returns the number of rows changed.
     */
    private function replacePrefix(string $table, string $column, string $prefix, string $to): int
    {
        $minId = DB::table($table)->min('id');
        $maxId = DB::table($table)->max('id');
        if ($minId === null) {
            return 0;
        }

        // The prefix is anchored with a '/' so "novelarrow.community" can't
        // match, and REPLACE() on the anchored prefix only rewrites the host.
        $sql = "UPDATE {$table} SET {$column} = REPLACE({$column}, ?, ?)"
            . " WHERE id BETWEEN ? AND ? AND {$column} LIKE ?";

        $changed = 0;
        for ($start = (int) $minId; $start <= (int) $maxId; $start += self::CHUNK) {
            $changed += DB::update($sql, [
                $prefix . '/', $to . '/',
                $start, $start + self::CHUNK - 1,
                $prefix . '/%',
            ]);
        }

        // A bare origin with no path (groups.url is "https://novelarrow.com").
        $changed += DB::table($table)->where($column, $prefix)->update([$column => $to]);

        return $changed;
    }
};
