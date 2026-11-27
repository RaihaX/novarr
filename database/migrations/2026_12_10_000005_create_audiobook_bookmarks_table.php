<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reader's bookmarks in an audiobook (App\AudiobookBookmark): a
 * whole-book position and an optional note. Private to the reader. No
 * foreign keys, like bookmarks: they go with their reader (the User hook)
 * and their audiobook (App\Audiobook's forceDeleted hook).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audiobook_bookmarks')) {
            return;
        }

        Schema::create('audiobook_bookmarks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('audiobook_id');
            $table->unsignedBigInteger('position_ms')->default(0);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'audiobook_id'], 'idx_audiobook_bookmarks_reader');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_bookmarks');
    }
};
