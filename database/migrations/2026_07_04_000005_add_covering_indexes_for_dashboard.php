<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covering indexes for the dashboard's Continue Reading queries. Without
 * them MariaDB falls back to reading full rows — including the chapter
 * longtext — which made a cold dashboard take ~5s.
 *
 *  - idx_read_recency: GROUP BY novel_id / MAX(read_at) over read chapters
 *    (recently-read candidates) resolves inside the index.
 *  - idx_continue_next: "first unread downloaded chapter" per novel — the
 *    IS NULL read_at behaves as an equality match, leaving (book, chapter)
 *    usable for the ORDER BY.
 *
 * ADD INDEX is INPLACE on MariaDB/InnoDB — no table copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->index(['status', 'blacklist', 'novel_id', 'read_at'], 'idx_read_recency');
            $table->index(['novel_id', 'status', 'blacklist', 'read_at', 'book', 'chapter'], 'idx_continue_next');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_read_recency');
            $table->dropIndex('idx_continue_next');
        });
    }
};
