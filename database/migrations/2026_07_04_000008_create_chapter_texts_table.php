<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chapter body text moves to its own table (see ChapterText). This migration
 * only creates the destination; the backfill + column drop happen in the
 * next migration so a failed copy can be retried.
 *
 * Also adds a label-only fulltext index — search currently relies on the
 * composite (label, description) index that disappears with the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapter_texts', function (Blueprint $table) {
            $table->unsignedBigInteger('novel_chapter_id')->primary();
            $table->longText('content');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE chapter_texts ADD FULLTEXT ftx_chapter_text (content)');
            DB::statement('ALTER TABLE novel_chapters ADD FULLTEXT ftx_chapter_label (label)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE novel_chapters DROP INDEX ftx_chapter_label');
        }
        Schema::dropIfExists('chapter_texts');
    }
};
