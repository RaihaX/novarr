<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-novel scrape priority: novels flagged frequent_toc get their table of
 * contents re-checked hourly (novel:toc --frequent-only) instead of only in
 * the nightly sweep — for actively-updating novels you're following closely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->boolean('frequent_toc')->default(false)->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn('frequent_toc');
        });
    }
};
