<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An audiobook's chapters (App\AudiobookChapter): the files' embedded
 * chapters, else one per file, else one "Part 1". start_ms / end_ms are
 * positions in the WHOLE book (across parts). Rows go with their
 * audiobook (App\Audiobook's forceDeleted hook).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audiobook_chapters')) {
            return;
        }

        Schema::create('audiobook_chapters', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('audiobook_id');
            $table->unsignedInteger('idx');
            $table->string('title', 255);
            $table->unsignedBigInteger('start_ms')->default(0);
            $table->unsignedBigInteger('end_ms')->default(0);

            $table->unique(['audiobook_id', 'idx'], 'uniq_audiobook_chapters_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_chapters');
    }
};
