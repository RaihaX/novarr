<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * chapter_reads: each reader's read state of a chapter — when it was read,
 * how far in (0–100) and when that position was written. Replaces
 * novel_chapters.read_at / read_progress / read_progress_at, which stay in
 * place for rollback but are no longer read or written (see
 * 2026_11_19_000004). Written and read only through App\Services\ReadState.
 *
 * A row exists only for a chapter the reader has touched. novel_id is
 * denormalised (chapters never move between novels) so per-novel lookups
 * need no join. Indexes:
 *  - PK (user_id, novel_chapter_id): one chapter's state; the NOT EXISTS
 *    probes behind "first unread".
 *  - (user_id, novel_id, read_at): per-novel counts and "started?" for one reader.
 *  - (user_id, read_at): reading stats' date ranges for one reader.
 *  - (novel_chapter_id): cleanup when a chapter is hard-deleted.
 *  - (novel_id, read_at): "read recently by anyone" (download priority).
 *
 * No foreign keys, like reading_positions / bookmarks / chapter_texts: rows
 * are removed by the application when the chapter, novel or user is
 * hard-deleted (model hooks). Guarded so an interrupted run can be repeated.
 */
return new class extends Migration
{
    private const TABLE = 'chapter_reads';

    /** Secondary index name => columns. */
    private const INDEXES = [
        'idx_chapter_reads_user_novel_read' => ['user_id', 'novel_id', 'read_at'],
        'idx_chapter_reads_user_read' => ['user_id', 'read_at'],
        'idx_chapter_reads_chapter' => ['novel_chapter_id'],
        'idx_chapter_reads_novel_read' => ['novel_id', 'read_at'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('novel_id');
                $table->unsignedInteger('novel_chapter_id');
                $table->timestamp('read_at')->nullable();
                $table->unsignedTinyInteger('progress')->nullable();
                $table->timestamp('progress_at')->nullable();

                $table->primary(['user_id', 'novel_chapter_id']);
            });
        }

        // Each index is its own guarded statement (MySQL grammar adds them
        // with separate ALTERs after the CREATE).
        foreach (self::INDEXES as $name => $columns) {
            if (!Schema::hasIndex(self::TABLE, $name)) {
                Schema::table(self::TABLE, fn(Blueprint $table) => $table->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
