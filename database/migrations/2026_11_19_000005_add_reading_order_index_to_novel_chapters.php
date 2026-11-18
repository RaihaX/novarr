<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "First unread chapter" (Home's Continue and New chapters, the novel page,
 * the summary email, the offline manifest) used to be answered by
 * idx_continue_ordered, whose read_at IS NULL equality let the index hand
 * rows back in reading order. Read state now lives in chapter_reads (per
 * reader), so the lookup walks a novel's downloaded chapters in reading order
 * and stops at the first one with no read row for the reader (a NOT EXISTS
 * probe on chapter_reads' primary key). This index is that walk:
 * (novel_id, status, blacklist, deleted_at) equalities, then
 * NovelChapter::scopeOrdered()'s (book, sort_key, chapter) — InnoDB appends
 * the primary key, the final id tie-break.
 *
 * idx_continue_ordered stays (it carries the legacy read_at column, kept for
 * rollback until a later release drops it). Guarded: repeatable.
 */
return new class extends Migration
{
    private const TABLE = 'novel_chapters';

    private const INDEX = 'idx_reading_order';

    public function up(): void
    {
        if (!Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['novel_id', 'status', 'blacklist', 'deleted_at', 'book', 'sort_key', 'chapter'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
