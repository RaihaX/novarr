<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series of the Books library (App\Books\BookSeries):
 *
 *   novels.series         the series' name ("The Iron Sky Trilogy"); null = none.
 *                         '' with series_source 'manual' = the owner said "no
 *                         series", so inference never adds one back.
 *   novels.series_index   the book's number in it (2, 2.5); null = unnumbered
 *   novels.series_source  who set it: calibre | epub | inferred | manual
 *
 * All nullable, added at the end of a small table, series indexed (the
 * Library's ?series= filter and the book page's siblings). Guarded so a
 * repeated run is harmless (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novels', 'series')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->string('series', 255)->nullable()->index('novels_series_index');
            });
        }
        if (!Schema::hasColumn('novels', 'series_index')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->decimal('series_index', 8, 2)->nullable();
            });
        }
        if (!Schema::hasColumn('novels', 'series_source')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->string('series_source', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('novels', 'series')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropIndex('novels_series_index');
            });
        }
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn(['series', 'series_index', 'series_source']);
        });
    }
};
