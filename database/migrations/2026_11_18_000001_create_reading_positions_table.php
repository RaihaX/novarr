<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reading_positions: where each series was last read, and on which device —
 * one row per novel (see App\Services\ReadingPositions). Feeds the readers'
 * "read on another device more recently — go there?" prompt.
 *
 * No foreign key, like chapter_texts / chapter_pages / bookmarks: rows are
 * removed by the application when the novel or chapter is hard-deleted
 * (Novel / NovelChapter forceDeleted hooks). Guarded so an interrupted run
 * can be repeated (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reading_positions')) {
            return;
        }

        Schema::create('reading_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('novel_id')->unique('uniq_reading_positions_novel');
            $table->unsignedBigInteger('novel_chapter_id')->index('idx_reading_positions_chapter');
            $table->unsignedTinyInteger('progress')->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('device_name', 40)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_positions');
    }
};
