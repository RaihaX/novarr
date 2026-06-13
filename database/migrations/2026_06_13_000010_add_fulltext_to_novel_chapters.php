<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FULLTEXT index over chapter label + content so full-text search uses
     * MATCH…AGAINST instead of a LIKE scan over 90k+ longtext rows.
     */
    public function up(): void
    {
        // FULLTEXT is MySQL-only; the SQLite test DB skips it (search there
        // falls back to LIKE — see SearchController).
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->fullText(['label', 'description'], 'ftx_chapter_content');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropFullText('ftx_chapter_content');
        });
    }
};
