<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Library's two per-novel aggregate queries (NovelController: the reading
 * index and libraryAggregates()) group the whole table by novel_id and read
 * blacklist, deleted_at, status, read_at and download_date. With no index
 * holding all of those, MariaDB walked idx_novel_blacklist and fetched every
 * row (about 450 ms each on a 214,000-chapter library, on every cold load:
 * the cache is dropped whenever a chapter is read or downloaded). This index
 * covers both, so they are answered from the index alone.
 *
 * It starts with (novel_id, blacklist), so it supersedes idx_novel_blacklist.
 * The new index is added before the old one is dropped, and each step is
 * guarded: MariaDB DDL auto-commits, so an interrupted run must be repeatable.
 */
return new class extends Migration
{
    private const TABLE = 'novel_chapters';

    private const NEW_INDEX = 'idx_library_aggregates';

    private const NEW_COLUMNS = ['novel_id', 'blacklist', 'deleted_at', 'status', 'read_at', 'download_date'];

    private const OLD_INDEX = 'idx_novel_blacklist';

    private const OLD_COLUMNS = ['novel_id', 'blacklist'];

    public function up(): void
    {
        if (!Schema::hasIndex(self::TABLE, self::NEW_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(self::NEW_COLUMNS, self::NEW_INDEX);
            });
        }

        if (Schema::hasIndex(self::TABLE, self::OLD_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::OLD_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasIndex(self::TABLE, self::OLD_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(self::OLD_COLUMNS, self::OLD_INDEX);
            });
        }

        if (Schema::hasIndex(self::TABLE, self::NEW_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::NEW_INDEX);
            });
        }
    }
};
