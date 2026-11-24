<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * novel_chapters.unavailable_until: the manga fallback
 * (App\Scraping\MangaFallback) found no source that can provide this
 * chapter — its own keeps failing and no other installed source lists it or
 * can deliver it. Nothing tries it again before that time (the chapter
 * sweep's due check and the hourly fallback both leave it out). Cleared when
 * the chapter is downloaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novel_chapters', 'unavailable_until')) {
            Schema::table('novel_chapters', function (Blueprint $table) {
                $table->dateTime('unavailable_until')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('novel_chapters', 'unavailable_until')) {
            Schema::table('novel_chapters', function (Blueprint $table) {
                $table->dropColumn('unavailable_until');
            });
        }
    }
};
