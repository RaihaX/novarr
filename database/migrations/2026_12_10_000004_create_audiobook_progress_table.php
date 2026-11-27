<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each reader is in each audiobook (App\Audiobooks\ListeningProgress):
 * one row per (reader, audiobook). position_ms is in the whole book.
 * updated_at is when the client says the position was reached (its
 * `recorded_at`, else the time of the request): a write older than the
 * stored one is ignored (last write wins), so an offline queue replayed
 * later never moves a reader back. device / client / ip: where it came
 * from (App\Support\ReadingClient), for the Readers pages.
 *
 * No foreign keys, like chapter_reads: rows go with their reader (the User
 * hook) and their audiobook (App\Audiobook's forceDeleted hook).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audiobook_progress')) {
            return;
        }

        Schema::create('audiobook_progress', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('audiobook_id');
            $table->unsignedBigInteger('position_ms')->default(0);
            $table->decimal('speed', 3, 2)->default(1);
            $table->timestamp('finished_at')->nullable();
            $table->string('device', 120)->nullable();
            $table->string('client', 60)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->primary(['user_id', 'audiobook_id']);
            $table->index(['user_id', 'updated_at'], 'idx_audiobook_progress_recent');
            $table->index('audiobook_id', 'idx_audiobook_progress_audiobook');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_progress');
    }
};
