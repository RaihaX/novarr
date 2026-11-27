<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Downloads page's History (App\DownloadEvent): grabbed (by the owner or
 * automatically), imported, auto-approved, failed, blocklisted, needs a
 * choice… with the reason, newest first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('download_events')) {
            return;
        }

        Schema::create('download_events', function (Blueprint $table) {
            $table->increments('id');
            $table->string('event', 32);
            $table->string('kind', 16)->nullable();
            $table->unsignedInteger('grab_id')->nullable();
            $table->unsignedInteger('wanted_id')->nullable();
            $table->string('title', 500);
            $table->string('message', 1000)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at', 'idx_download_events_created');
            $table->index('grab_id', 'idx_download_events_grab');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_events');
    }
};
