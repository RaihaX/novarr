<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * chapter_reads.bulk: the chapter was marked read in bulk ("Mark to here",
 * "Mark all read", library / API bulk read — ReadState::markMany()) rather
 * than read. Reading stats count bulk rows in the all-time totals but not in
 * daily activity (today, week, chart, streak, most read).
 *
 * Backfill: before the column existed a bulk mark stamped every chapter with
 * the same now(), so a group of BULK_GROUP or more of one reader's rows
 * sharing a read_at second is taken as bulk (nobody reads 20 chapters in one
 * second). One set-based UPDATE; the grouped derived table is materialised,
 * so MariaDB/MySQL accept the self-reference (no error 1093). Re-runnable.
 */
return new class extends Migration
{
    private const TABLE = 'chapter_reads';

    /** Rows of one reader sharing one read_at second that count as a bulk mark. */
    private const BULK_GROUP = 20;

    public function up(): void
    {
        if (!Schema::hasColumn(self::TABLE, 'bulk')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->boolean('bulk')->default(false);
            });
        }

        $groups = DB::table(self::TABLE)
            ->select('user_id', 'read_at')
            ->whereNotNull('read_at')
            ->groupBy('user_id', 'read_at')
            ->havingRaw('COUNT(*) >= ?', [self::BULK_GROUP]);

        DB::table(self::TABLE)
            ->whereNotNull('read_at')
            ->where('bulk', false)
            ->whereExists(fn($q) => $q->fromSub($groups, 'g')
                ->selectRaw('1')
                ->whereColumn('g.user_id', self::TABLE . '.user_id')
                ->whereColumn('g.read_at', self::TABLE . '.read_at'))
            ->update(['bulk' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn(self::TABLE, 'bulk')) {
            Schema::table(self::TABLE, fn(Blueprint $table) => $table->dropColumn('bulk'));
        }
    }
};
