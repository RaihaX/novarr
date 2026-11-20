<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reading lists (App\ReadingList): a reader's own named groups of series
 * ("Favourites", "To read", …). Private to the reader; a series can be in
 * several lists.
 *
 *  - reading_lists: user_id is the reader's account id, as chapter_reads
 *    (never null: with sign-in off the reader is the owner's account,
 *    App\Support\CurrentReader). name_key is the name trimmed, whitespace
 *    collapsed and lowercased (ReadingList::key()), unique per reader, so
 *    "Favourites" and " favourites " are the same list on every database.
 *  - reading_list_novel: one row per list and series; (novel_id) serves the
 *    cleanup when a series is hard-deleted.
 *
 * No foreign keys, like chapter_reads / bookmarks: rows go with their list
 * (the ReadingList hook), their series (Novel's forceDeleted hook; a
 * soft-deleted series keeps its rows for a restore and is left out of every
 * list) and their reader (the User hook). Guarded so an interrupted run can
 * be repeated (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reading_lists')) {
            Schema::create('reading_lists', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->string('name', 40);
                $table->string('name_key', 64); // lowercasing can lengthen a few characters
                $table->timestamps();

                $table->unique(['user_id', 'name_key'], 'uniq_reading_lists_user_name');
            });
        }

        if (!Schema::hasTable('reading_list_novel')) {
            Schema::create('reading_list_novel', function (Blueprint $table) {
                $table->unsignedInteger('reading_list_id');
                $table->unsignedInteger('novel_id');
                $table->timestamp('added_at')->nullable();

                $table->primary(['reading_list_id', 'novel_id']);
                $table->index('novel_id', 'idx_reading_list_novel_novel');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_list_novel');
        Schema::dropIfExists('reading_lists');
    }
};
