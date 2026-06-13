<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            // Paused ("ignored") novels are skipped by the automatic scrape
            // sweeps and by needs-attention alerts. Explicit per-novel
            // commands still run, and pausing is reversible.
            $table->timestamp('paused_at')->nullable()->after('scrape_failures');
        });
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
