<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Releases that failed (App\DownloadBlock, App\Downloads\FailedDownloads):
 * never grabbed automatically again. A release is the same when Prowlarr's
 * guid is, or its title on the same indexer. The owner can remove an entry
 * (and search again) on the Downloads page's Blocklist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('download_blocklist')) {
            return;
        }

        Schema::create('download_blocklist', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kind', 16);
            $table->string('guid', 1000)->nullable();
            $table->string('title', 500);
            $table->string('indexer', 120)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->unsignedInteger('grab_id')->nullable();
            $table->timestamps();

            $table->index('title', 'idx_download_blocklist_title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_blocklist');
    }
};
