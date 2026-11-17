<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manga support, phase 1.
 *
 *   novels.medium             'novel' | 'manga' (set at creation from the
 *                             source adapter; existing rows are novels)
 *   novels.reading_mode       'rtl' | 'ltr' | 'vertical', null = not chosen
 *   novel_chapters.page_count pages of a downloaded manga chapter (null for text)
 *   chapter_pages             one row per downloaded page image (files on the
 *                             `manga` disk, see App\ChapterPage)
 *
 * chapter_texts is not touched. novel_chapters only gains a nullable column
 * at the end, which MariaDB 10.3+ adds in place (ALGORITHM=INSTANT, no table
 * copy). Each step is guarded so an interrupted run can be repeated (MariaDB
 * DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novels', 'medium')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->string('medium', 16)->default('novel');
                $table->index('medium', 'idx_novels_medium');
            });
        }

        if (!Schema::hasColumn('novels', 'reading_mode')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->string('reading_mode', 16)->nullable();
            });
        }

        if (!Schema::hasColumn('novel_chapters', 'page_count')) {
            Schema::table('novel_chapters', function (Blueprint $table) {
                $table->unsignedInteger('page_count')->nullable();
            });
        }

        // No foreign key, like chapter_texts / bookmarks: rows are removed by
        // the application (ChapterPage::purgeChapter()).
        if (!Schema::hasTable('chapter_pages')) {
            Schema::create('chapter_pages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('novel_chapter_id')->index();
                $table->unsignedSmallInteger('page');
                $table->string('path');
                $table->string('mime', 32);
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedInteger('bytes');
                $table->timestamps();
                $table->unique(['novel_chapter_id', 'page'], 'uniq_chapter_pages_chapter_page');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chapter_pages');

        if (Schema::hasColumn('novel_chapters', 'page_count')) {
            Schema::table('novel_chapters', function (Blueprint $table) {
                $table->dropColumn('page_count');
            });
        }

        if (Schema::hasColumn('novels', 'reading_mode')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropColumn('reading_mode');
            });
        }

        if (Schema::hasColumn('novels', 'medium')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropIndex('idx_novels_medium');
            });
            Schema::table('novels', function (Blueprint $table) {
                $table->dropColumn('medium');
            });
        }
    }
};
