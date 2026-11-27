<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Downloads the owner grabbed from a Prowlarr search (App\DownloadGrab,
 * App\Downloads\Grabber): one row per release sent to NZBGet (usenet) or
 * Transmission (torrent), followed by `downloads:poll` until it is imported
 * or failed.
 *
 *  - kind: ebook | audiobook; protocol: usenet | torrent; client: nzbget |
 *    transmission; client_ref: the NZBID / the torrent's info hash.
 *  - novel_request_id: the reader's wanted book it fulfils (an ebook);
 *    novel_id: the book an audiobook is for. Both null: a standalone search.
 *  - status: queued → downloading → completed → imported, or failed (error
 *    says why). completed_path: the download's name in its inbox (the books
 *    inbox or the audiobooks inbox), so the importers can tell which entry
 *    is whose. imported_type / imported_id: what it became (a book request
 *    for review, an audiobook).
 *  - user_id: the owner who grabbed it; auto: Novarr grabbed it by itself
 *    for a wanted item (wanted_id, App\DownloadWanted); guid: Prowlarr's id
 *    of the release (the blocklist's key).
 *  - eta_seconds: the client's estimate; progress_at: when the progress
 *    last moved (a download that stalls for novarr.downloads.stall_hours
 *    is failed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('download_grabs')) {
            return;
        }

        Schema::create('download_grabs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kind', 16);
            $table->string('protocol', 16);
            $table->string('client', 16);
            $table->string('client_ref', 64)->nullable();
            $table->string('guid', 1000)->nullable();
            $table->string('title', 500);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('indexer', 120)->nullable();
            $table->string('info_url', 1000)->nullable();
            $table->unsignedInteger('novel_request_id')->nullable();
            $table->unsignedInteger('novel_id')->nullable();
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('error', 1000)->nullable();
            $table->string('completed_path', 500)->nullable();
            $table->string('imported_type', 32)->nullable();
            $table->unsignedInteger('imported_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->boolean('auto')->default(false);
            $table->unsignedInteger('wanted_id')->nullable();
            $table->unsignedInteger('eta_seconds')->nullable();
            $table->timestamp('progress_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('polled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'client'], 'idx_download_grabs_status');
            $table->index('novel_request_id', 'idx_download_grabs_request');
            $table->index('novel_id', 'idx_download_grabs_novel');
            $table->index(['kind', 'completed_path'], 'idx_download_grabs_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_grabs');
    }
};
