<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            // Dashboard "missing chapters" panel: count + newest-first listing
            // filtered by status/blacklist. The existing indexes all lead with
            // novel_id, so these queries were full table scans.
            $table->index(['status', 'blacklist', 'created_at'], 'idx_status_blacklist_created');

            // Dashboard "latest chapters" panel: blacklist filter + newest-first.
            $table->index(['blacklist', 'created_at'], 'idx_blacklist_created');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_status_blacklist_created');
            $table->dropIndex('idx_blacklist_created');
        });
    }
};
