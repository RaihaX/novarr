<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOC run savings: a fingerprint of the last fully synced table of contents
 * (an unchanged TOC skips the per-row sync), when the last full walk of a
 * paginated TOC happened (Empire Novel walks only new pages in between), and
 * when NovelUpdates was last searched for a novel without a saved match (a
 * miss is not retried for 7 days).
 */
return new class extends Migration
{
    private const COLUMNS = ['toc_fingerprint', 'toc_full_walk_at', 'novelupdates_checked_at'];

    public function up(): void
    {
        // Guarded per column: MariaDB DDL auto-commits, so a failure partway
        // must not leave a re-run tripping over "Duplicate column".
        Schema::table('novels', function (Blueprint $table) {
            if (!Schema::hasColumn('novels', 'toc_fingerprint')) {
                $table->string('toc_fingerprint', 64)->nullable();
            }
            if (!Schema::hasColumn('novels', 'toc_full_walk_at')) {
                $table->dateTime('toc_full_walk_at')->nullable();
            }
            if (!Schema::hasColumn('novels', 'novelupdates_checked_at')) {
                $table->dateTime('novelupdates_checked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(self::COLUMNS, fn($c) => Schema::hasColumn('novels', $c)));
        if ($present === []) {
            return;
        }

        Schema::table('novels', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }
};
