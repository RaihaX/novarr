<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 of the dashboard covering indexes: NovelChapter soft-deletes, so every
 * Eloquent query carries `deleted_at IS NULL` — without deleted_at in the
 * index each entry still triggers a full-row read (longtext) to check it,
 * which kept the cold dashboard at ~4.5s. IS NULL is an equality match, so
 * placing deleted_at inside the equality prefix keeps both indexes covering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_read_recency');
            $table->dropIndex('idx_continue_next');
            $table->index(['status', 'blacklist', 'deleted_at', 'novel_id', 'read_at'], 'idx_read_recency');
            $table->index(['novel_id', 'status', 'blacklist', 'deleted_at', 'read_at', 'book', 'chapter'], 'idx_continue_next');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_read_recency');
            $table->dropIndex('idx_continue_next');
            $table->index(['status', 'blacklist', 'novel_id', 'read_at'], 'idx_read_recency');
            $table->index(['novel_id', 'status', 'blacklist', 'read_at', 'book', 'chapter'], 'idx_continue_next');
        });
    }
};
