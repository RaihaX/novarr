<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An audiobook's files in playing order (App\AudiobookPart): idx is 0-based,
 * path is relative to the audiobook's dir ("part-001.mp3"), start_ms the
 * part's offset in the whole book (the sum of the parts before it), so a
 * whole-book position maps to (part, offset) without reading the files.
 * Rows go with their audiobook (App\Audiobook's forceDeleted hook).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audiobook_parts')) {
            return;
        }

        Schema::create('audiobook_parts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('audiobook_id');
            $table->unsignedSmallInteger('idx');
            $table->string('path', 255);
            $table->string('mime', 64);
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('start_ms')->default(0);

            $table->unique(['audiobook_id', 'idx'], 'uniq_audiobook_parts_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_parts');
    }
};
