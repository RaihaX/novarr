<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add indexes to novel_chapters table
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->index(['novel_id', 'status', 'blacklist'], 'idx_novel_status_blacklist');
            $table->index(['novel_id', 'blacklist'], 'idx_novel_blacklist');
            $table->index(['novel_id', 'chapter', 'book'], 'idx_novel_chapter_book');
            $table->index('download_date', 'idx_download_date');
            $table->index('created_at', 'idx_created_at');
        });

        // Add indexes to novels table
        Schema::table('novels', function (Blueprint $table) {
            $table->index('name', 'idx_novel_name');
            $table->index('group_id', 'idx_novel_group_id');
            $table->index('language_id', 'idx_novel_language_id');
        });

        // Add indexes to mangas table
        Schema::table('mangas', function (Blueprint $table) {
            $table->index('name', 'idx_manga_name');
        });

        // Add indexes to manga_chapters table
        Schema::table('manga_chapters', function (Blueprint $table) {
            $table->index('manga_id', 'idx_manga_chapter_manga_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_novel_status_blacklist');
            $table->dropIndex('idx_novel_blacklist');
            $table->dropIndex('idx_novel_chapter_book');
            $table->dropIndex('idx_download_date');
            $table->dropIndex('idx_created_at');
        });

        Schema::table('novels', function (Blueprint $table) {
            $table->dropIndex('idx_novel_name');
            $table->dropIndex('idx_novel_group_id');
            $table->dropIndex('idx_novel_language_id');
        });

        Schema::table('mangas', function (Blueprint $table) {
            $table->dropIndex('idx_manga_name');
        });

        Schema::table('manga_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_manga_chapter_manga_id');
        });
    }
};
