<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copy every chapter body into chapter_texts, verify nothing was missed,
 * then drop novel_chapters.description. The copy runs as chunked
 * INSERT…SELECT (no PHP hydration of the longtext) and is idempotent
 * (INSERT IGNORE), so an interrupted run can simply be re-run.
 *
 * Expect several minutes on a full library: the copy moves the text data,
 * and the final DROP COLUMN rebuilds novel_chapters (which is precisely
 * what makes that table permanently small and fast afterwards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novel_chapters', 'description')) {
            return; // already migrated
        }

        $driver = DB::getDriverName();
        $insertIgnore = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        $max = (int) DB::table('novel_chapters')->max('id');
        for ($from = 0; $from <= $max; $from += 2000) {
            DB::statement("
                {$insertIgnore} INTO chapter_texts (novel_chapter_id, content)
                SELECT id, description FROM novel_chapters
                WHERE description IS NOT NULL AND description != ''
                  AND id > ? AND id <= ?
            ", [$from, $from + 2000]);
        }

        $source = DB::table('novel_chapters')
            ->whereNotNull('description')->where('description', '!=', '')
            ->count();
        $copied = DB::table('chapter_texts')->count();

        if ($copied < $source) {
            throw new RuntimeException(
                "chapter_texts backfill incomplete ({$copied} of {$source} rows) — description column NOT dropped; re-run the migration."
            );
        }

        // The composite fulltext includes description and must go before the
        // column can (search now uses ftx_chapter_label + ftx_chapter_text).
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE novel_chapters DROP INDEX ftx_chapter_content');
        }

        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->longText('description')->nullable();
        });

        $max = (int) DB::table('chapter_texts')->max('novel_chapter_id');
        for ($from = 0; $from <= $max; $from += 2000) {
            DB::statement('
                UPDATE novel_chapters
                JOIN chapter_texts ON chapter_texts.novel_chapter_id = novel_chapters.id
                SET novel_chapters.description = chapter_texts.content
                WHERE novel_chapters.id > ? AND novel_chapters.id <= ?
            ', [$from, $from + 2000]);
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE novel_chapters ADD FULLTEXT ftx_chapter_content (label, description)');
        }
    }
};
