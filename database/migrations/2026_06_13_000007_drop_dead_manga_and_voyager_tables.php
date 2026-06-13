<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the unused manga tables (no routes ever reached them) and the
     * leftover Voyager `settings` table (Voyager was removed long ago; the
     * app's own settings live in app_settings). One-way cleanup.
     */
    public function up(): void
    {
        Schema::dropIfExists('manga_chapters');
        Schema::dropIfExists('mangas');

        // Only drop the Voyager settings table — identified by its Voyager
        // columns — never the app's own (which has no display_name column).
        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'display_name')) {
            Schema::dropIfExists('settings');
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — these are dead tables.
    }
};
