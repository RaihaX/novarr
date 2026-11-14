<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored word counts (set by ChapterText on save; existing rows are filled by
 * `php artisan chapters:word-counts`) so reading stats and the Home hero stop
 * scanning chapter bodies, plus a plain read_at index for the date-range
 * queries on /stats.
 *
 * chapter_texts has a FULLTEXT index, so MariaDB cannot add the column
 * instantly: the ADD COLUMN rebuilds the table. On a large library expect it
 * to take a while during the deploy's maintenance window.
 *
 * Each change is its own guarded statement: MariaDB DDL auto-commits, so a run
 * interrupted after the column was added must not leave every later migrate
 * failing on "Duplicate column".
 */
return new class extends Migration
{
    private const INDEX = 'idx_read_at';

    public function up(): void
    {
        if (!Schema::hasColumn('chapter_texts', 'word_count')) {
            Schema::table('chapter_texts', function (Blueprint $table) {
                $table->unsignedInteger('word_count')->nullable();
            });
        }

        if (!Schema::hasIndex('novel_chapters', self::INDEX)) {
            Schema::table('novel_chapters', function (Blueprint $table) {
                $table->index('read_at', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('novel_chapters', self::INDEX)) {
            Schema::table('novel_chapters', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('chapter_texts', 'word_count')) {
            Schema::table('chapter_texts', function (Blueprint $table) {
                $table->dropColumn('word_count');
            });
        }
    }
};
