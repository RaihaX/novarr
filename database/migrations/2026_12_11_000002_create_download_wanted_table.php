<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Novarr looks for by itself (App\DownloadWanted, App\Downloads\WantedList),
 * Sonarr/Radarr style:
 *
 *  - kind ebook: a reader's wanted book (novel_request_id; a pending
 *    NovelRequest::isWanted()), added and removed with the request;
 *  - kind audiobook: a book of the library whose audiobook is wanted
 *    (novel_id): the owner's "Want the audiobook", or a reader's "Ask for
 *    the audiobook" (requested_by). Removed once the book has one.
 *
 * monitored: searched automatically (the owner can unmonitor). searches /
 * last_searched_at / next_search_at: the backoff (novarr.downloads.
 * backoff_hours). results_count / needs_choice: the last search found
 * results but none good enough to grab by itself (the owner picks);
 * notified_at: the owner was told (at most once per item).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('download_wanted')) {
            return;
        }

        Schema::create('download_wanted', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kind', 16);
            $table->unsignedInteger('novel_request_id')->nullable();
            $table->unsignedInteger('novel_id')->nullable();
            $table->boolean('monitored')->default(true);
            $table->unsignedInteger('requested_by')->nullable();
            $table->unsignedInteger('searches')->default(0);
            $table->timestamp('last_searched_at')->nullable();
            $table->timestamp('next_search_at')->nullable();
            $table->unsignedInteger('results_count')->default(0);
            $table->boolean('needs_choice')->default(false);
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'novel_request_id'], 'uniq_download_wanted_request');
            $table->unique(['kind', 'novel_id'], 'uniq_download_wanted_novel');
            $table->index(['monitored', 'next_search_at'], 'idx_download_wanted_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_wanted');
    }
};
