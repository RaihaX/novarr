<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            // Human-readable cause of the latest all-failed scrape run (bad
            // source URLs, Cloudflare, empty pages, stub chapters…). Cleared
            // on any successful download; surfaced next to the failure count
            // in the dashboard and the daily summary email.
            $table->string('last_scrape_issue', 500)->nullable()->after('scrape_failures');
        });
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn('last_scrape_issue');
        });
    }
};
