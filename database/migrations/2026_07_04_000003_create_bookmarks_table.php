<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Highlights/bookmarks saved from the reader: a selected excerpt plus an
 * optional note, anchored to a chapter. novel_id is denormalised so the
 * bookmarks page can group by novel without joining through chapters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('novel_id')->index();
            $table->unsignedBigInteger('novel_chapter_id')->index();
            $table->text('excerpt');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
