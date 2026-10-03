<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Continue-reading's "next unread" now orders by sort_key; the covering
 * index idx_continue_next ends in (book, chapter), so that query became a
 * filesort over each novel's unread rows. Add a matching index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->index(['novel_id', 'status', 'blacklist', 'deleted_at', 'read_at', 'book', 'sort_key'], 'idx_continue_next_sort');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_continue_next_sort');
        });
    }
};
