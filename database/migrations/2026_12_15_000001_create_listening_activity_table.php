<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time spent listening, rolled up per reader, audiobook, day and hour (in
 * the app timezone): what Stats counts as "hours listened"
 * (App\Audiobooks\ListeningTime). Written by
 * App\Audiobooks\ListeningProgress::record() from the forward position
 * deltas between a reader's consecutive progress writes, clamped to the
 * wall-clock time between them; at most 24 rows per reader, book and day.
 *
 * No foreign keys, like audiobook_progress: rows go with their reader (the
 * User hook) and their audiobook (App\Audiobook's forceDeleted hook).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('listening_activity')) {
            return;
        }

        Schema::create('listening_activity', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->date('day');
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('audiobook_id');
            $table->unsignedInteger('seconds')->default(0);

            $table->primary(['user_id', 'day', 'hour', 'audiobook_id']);
            $table->index('audiobook_id', 'idx_listening_activity_audiobook');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listening_activity');
    }
};
