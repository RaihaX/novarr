<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-way Calibre sync (App\Books\CalibrePush): the book's id and uuid in
 * Calibre once it is there (sent from Novarr, or found for one imported from
 * Calibre), when Novarr last sent it, and why the last send failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->unsignedInteger('calibre_id')->nullable()->index();
            $table->string('calibre_uuid', 64)->nullable()->index();
            $table->timestamp('calibre_synced_at')->nullable();
            $table->string('calibre_sync_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->dropIndex(['calibre_id']);
            $table->dropIndex(['calibre_uuid']);
            $table->dropColumn(['calibre_id', 'calibre_uuid', 'calibre_synced_at', 'calibre_sync_error']);
        });
    }
};
