<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When read_progress was last written. Offline reading positions are
 * replayed later with the time they were recorded, and one older than the
 * stored position must not overwrite it (see NovelChapterController@progress).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded like the read_progress migration: the ALTER can outlive a
        // client timeout, and a retry must not re-add the column.
        if (!Schema::hasColumn('novel_chapters', 'read_progress_at')) {
            Schema::table('novel_chapters', function (Blueprint $table) {
                $table->timestamp('read_progress_at')->nullable()->after('read_progress');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('novel_chapters', 'read_progress_at')) {
            Schema::table('novel_chapters', function (Blueprint $table) {
                $table->dropColumn('read_progress_at');
            });
        }
    }
};
