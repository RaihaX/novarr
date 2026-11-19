<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * chapter_reads.manual: the read mark on this row was set by hand (a single
 * mark read, "Mark to here", "Mark all read", a bulk read — ReadState::
 * setRead() / markMany()) or by a look-up, not by reading. A manual row is
 * not reading activity, so it never moves "Continue" (App\Services\
 * ResumePoint); reading in the chapter afterwards clears the flag.
 *
 * Backfill: bulk rows were all marked by hand. Single hand marks before the
 * column existed cannot be told from reads and stay activity. Re-runnable.
 */
return new class extends Migration
{
    private const TABLE = 'chapter_reads';

    public function up(): void
    {
        if (!Schema::hasColumn(self::TABLE, 'manual')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->boolean('manual')->default(false);
            });
        }

        DB::table(self::TABLE)
            ->where('bulk', true)
            ->where('manual', false)
            ->update(['manual' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn(self::TABLE, 'manual')) {
            Schema::table(self::TABLE, fn(Blueprint $table) => $table->dropColumn('manual'));
        }
    }
};
