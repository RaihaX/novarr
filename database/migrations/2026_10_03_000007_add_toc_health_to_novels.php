<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TOC health tracking (audit F26/A13): per-novel record of the last TOC run
 * (when, how many entries, consecutive failures, last issue) and, per
 * chapter, when the row was last listed by the source's TOC — so pending
 * rows the source no longer lists can be parked instead of retried forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded per column: MariaDB DDL auto-commits, so a failure partway
        // must not leave a re-run tripping over "Duplicate column".
        Schema::table('novels', function (Blueprint $table) {
            if (!Schema::hasColumn('novels', 'last_toc_at')) {
                $table->dateTime('last_toc_at')->nullable();
            }
            if (!Schema::hasColumn('novels', 'last_toc_count')) {
                $table->unsignedInteger('last_toc_count')->nullable();
            }
            if (!Schema::hasColumn('novels', 'toc_failures')) {
                $table->unsignedInteger('toc_failures')->default(0);
            }
            if (!Schema::hasColumn('novels', 'last_toc_issue')) {
                $table->string('last_toc_issue', 255)->nullable();
            }
        });

        $hadColumn = Schema::hasColumn('novel_chapters', 'last_seen_in_toc_at');
        Schema::table('novel_chapters', function (Blueprint $table) use ($hadColumn) {
            if (!$hadColumn) {
                $table->dateTime('last_seen_in_toc_at')->nullable();
                $table->index(['novel_id', 'last_seen_in_toc_at'], 'idx_novel_last_seen_in_toc');
            }
        });

        // Baseline for pending rows: "seen now", so a row the source has
        // already dropped only counts as missing after 3 days of TOC runs
        // that don't list it — never on the first run after deploy.
        DB::table('novel_chapters')
            ->where('status', 0)
            ->whereNull('last_seen_in_toc_at')
            ->update(['last_seen_in_toc_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_novel_last_seen_in_toc');
            $table->dropColumn('last_seen_in_toc_at');
        });

        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn(['last_toc_at', 'last_toc_count', 'toc_failures', 'last_toc_issue']);
        });
    }
};
