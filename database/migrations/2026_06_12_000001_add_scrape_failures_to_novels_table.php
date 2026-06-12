<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            // Consecutive scrape runs where every pending chapter failed.
            // Reset to 0 on any successful download; surfaced in the daily
            // summary email once it crosses the attention threshold.
            $table->unsignedInteger('scrape_failures')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn('scrape_failures');
        });
    }
};
