<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            // "Snooze" for the dashboard's Needs Attention panel: the novel is
            // left out of the panel (and the daily summary) until this time.
            // It never pauses downloads — that's paused_at.
            $table->dateTime('attention_ignored_until')->nullable()->after('last_scrape_issue');
        });
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn('attention_ignored_until');
        });
    }
};
