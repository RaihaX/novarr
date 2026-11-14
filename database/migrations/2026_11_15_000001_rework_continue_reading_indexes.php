<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home's "first unread chapter" lookup orders by (book, sort_key, chapter, id)
 * (NovelChapter::scopeOrdered). No index continued past sort_key to chapter, so
 * MariaDB fell back to an index intersection plus a filesort. InnoDB appends
 * the primary key to every secondary index, so an index ending in
 * (..., book, sort_key, chapter) hands rows back in exactly that order.
 *
 * The new index supersedes three old ones:
 *  - idx_continue_next      (..., read_at, book, chapter): same prefix, older ordering
 *  - idx_continue_next_sort (..., read_at, book, sort_key): a prefix of the new one
 *  - idx_novel_counts_cover (novel_id, status, blacklist, deleted_at): a prefix of the new one
 *
 * The new index is added before the old ones are dropped so lookups always
 * have a usable index. Each step is its own guarded statement: MariaDB DDL
 * auto-commits, so an interrupted run must be safe to repeat.
 */
return new class extends Migration
{
    private const TABLE = 'novel_chapters';

    private const NEW_INDEX = 'idx_continue_ordered';

    private const NEW_COLUMNS = ['novel_id', 'status', 'blacklist', 'deleted_at', 'read_at', 'book', 'sort_key', 'chapter'];

    /** Old index name => its exact columns (as last created by earlier migrations). */
    private const OLD_INDEXES = [
        'idx_continue_next' => ['novel_id', 'status', 'blacklist', 'deleted_at', 'read_at', 'book', 'chapter'],
        'idx_continue_next_sort' => ['novel_id', 'status', 'blacklist', 'deleted_at', 'read_at', 'book', 'sort_key'],
        'idx_novel_counts_cover' => ['novel_id', 'status', 'blacklist', 'deleted_at'],
    ];

    public function up(): void
    {
        if (!Schema::hasIndex(self::TABLE, self::NEW_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(self::NEW_COLUMNS, self::NEW_INDEX);
            });
        }

        foreach (array_keys(self::OLD_INDEXES) as $name) {
            if (Schema::hasIndex(self::TABLE, $name)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($name) {
                    $table->dropIndex($name);
                });
            }
        }
    }

    public function down(): void
    {
        // Recreate the old ones first, so the lookups are never without an index.
        foreach (self::OLD_INDEXES as $name => $columns) {
            if (!Schema::hasIndex(self::TABLE, $name)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($name, $columns) {
                    $table->index($columns, $name);
                });
            }
        }

        if (Schema::hasIndex(self::TABLE, self::NEW_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::NEW_INDEX);
            });
        }
    }
};
