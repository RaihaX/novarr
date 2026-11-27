<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audiobooks (App\Audiobook): one row per audiobook imported from the
 * drop-in folder (App\Audiobooks\AudiobookImporter). Its files live under
 * config('novarr.audiobooks.path')/{dir}; `dir` is relative to that root
 * (the audiobook's id), so the library can move to another disk.
 *
 *  - novel_id: the book it is the audiobook of (medium 'book', or a book
 *    moved to Novels), or null for a standalone one. Unique: a book has one
 *    audiobook (v1). The novel going for good (hard delete) leaves it
 *    standalone.
 *  - status: 'ready' (matched, or left standalone by the owner) or
 *    'unmatched' (waiting for the owner on the Audiobooks page); match_note
 *    says why it was not matched.
 *  - fingerprint: sha1 over each part's first MB and size, so the same
 *    files dropped in again are recognised (unique, soft-deleted rows too).
 *  - size_bytes / duration_ms: the parts' totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audiobooks')) {
            return;
        }

        Schema::create('audiobooks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('novel_id')->nullable();
            $table->string('title', 255);
            $table->string('author', 255)->nullable();
            $table->string('narrator', 255)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('cover_path', 255)->nullable();
            $table->string('dir', 255)->default('');
            $table->string('status', 16)->default('unmatched');
            $table->string('match_note', 255)->nullable();
            $table->string('source_name', 255)->nullable();
            $table->string('fingerprint', 40);
            $table->timestamp('added_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('novel_id', 'uniq_audiobooks_novel');
            $table->unique('fingerprint', 'uniq_audiobooks_fingerprint');
            $table->index(['status', 'deleted_at'], 'idx_audiobooks_status');
            $table->foreign('novel_id', 'fk_audiobooks_novel')->references('id')->on('novels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobooks');
    }
};
