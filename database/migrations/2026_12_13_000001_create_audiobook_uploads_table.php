<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audiobooks readers upload (App\AudiobookUpload, App\Audiobooks\AudiobookUploads):
 * chunked into {audiobooks root}/_uploads/{id}/, checked, then approved or
 * declined by the owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audiobook_uploads')) {
            return;
        }

        Schema::create('audiobook_uploads', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            // The book it is for: the reader's choice, else Novarr's suggestion (nullable).
            $table->unsignedInteger('novel_id')->nullable();
            // uploading | processing | pending | approved | declined | failed
            $table->string('status', 16)->default('uploading');
            $table->string('title', 255)->nullable();
            $table->string('note', 1000)->nullable();
            $table->string('decline_reason', 1000)->nullable();
            // Why it failed, or what the reader must re-send (plain words).
            $table->string('error', 1000)->nullable();
            // Relative to the audiobooks root: _uploads/{id}.
            $table->string('dir', 255)->nullable();
            $table->unsignedInteger('chunk_size');
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->unsignedBigInteger('received_bytes')->default(0);
            // [{name, size, sha256, done, path, …}] in the client's order.
            $table->json('files')->nullable();
            // {title, author, narrator, duration_ms, chapters, cover_path, …}
            $table->json('probe')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->unsignedInteger('audiobook_id')->nullable();
            $table->unsignedInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_audiobook_uploads_user_status');
            $table->index('status', 'idx_audiobook_uploads_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_uploads');
    }
};
