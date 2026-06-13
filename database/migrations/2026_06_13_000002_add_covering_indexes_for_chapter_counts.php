<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            // Covering indexes for the per-novel chapter counts on the novels
            // list (withCount adds a deleted_at IS NULL predicate via
            // SoftDeletes; without deleted_at in the index every counted entry
            // costs a row lookup).
            $table->index(['novel_id', 'status', 'blacklist', 'deleted_at'], 'idx_novel_counts_cover');
            $table->index(['novel_id', 'deleted_at'], 'idx_novel_deleted_cover');

            // Fully covered by the prefix of idx_novel_counts_cover.
            $table->dropIndex('idx_novel_status_blacklist');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->index(['novel_id', 'status', 'blacklist'], 'idx_novel_status_blacklist');
            $table->dropIndex('idx_novel_counts_cover');
            $table->dropIndex('idx_novel_deleted_cover');
        });
    }
};
