<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reader profiles, stage 1: a series' latest position and highlights
 * (bookmarks) belong to a reader. Every existing row is the owner's (app
 * setting owner_user_id, set by 2026_11_19_000001). reading_positions'
 * one-row-per-novel unique key becomes one row per (reader, novel).
 *
 * Each step is guarded: MariaDB DDL auto-commits, so an interrupted run must
 * be safe to repeat. The new unique key is added before the old one goes, so
 * the table is never without one.
 */
return new class extends Migration
{
    private const OLD_POSITION_UNIQUE = 'uniq_reading_positions_novel';

    private const NEW_POSITION_UNIQUE = 'uniq_reading_positions_user_novel';

    private const BOOKMARK_INDEX = 'idx_bookmarks_user_novel';

    public function up(): void
    {
        $owner = $this->ownerId();

        foreach (['reading_positions', 'bookmarks'] as $table) {
            if (!Schema::hasColumn($table, 'user_id')) {
                Schema::table($table, fn(Blueprint $t) => $t->unsignedInteger('user_id')->nullable()->after('id'));
            }
            DB::table($table)->whereNull('user_id')->update(['user_id' => $owner]);
            if ($this->isNullable($table, 'user_id')) {
                Schema::table($table, fn(Blueprint $t) => $t->unsignedInteger('user_id')->nullable(false)->change());
            }

            $missing = DB::table($table)->whereNull('user_id')->count();
            if ($missing > 0) {
                throw new RuntimeException("{$table}: {$missing} rows were not given an owner.");
            }
        }

        if (!Schema::hasIndex('reading_positions', self::NEW_POSITION_UNIQUE)) {
            Schema::table('reading_positions', fn(Blueprint $t) => $t->unique(['user_id', 'novel_id'], self::NEW_POSITION_UNIQUE));
        }
        if (Schema::hasIndex('reading_positions', self::OLD_POSITION_UNIQUE)) {
            Schema::table('reading_positions', fn(Blueprint $t) => $t->dropUnique(self::OLD_POSITION_UNIQUE));
        }

        if (!Schema::hasIndex('bookmarks', self::BOOKMARK_INDEX)) {
            Schema::table('bookmarks', fn(Blueprint $t) => $t->index(['user_id', 'novel_id'], self::BOOKMARK_INDEX));
        }
    }

    /**
     * Back to one owner: other readers' positions and highlights cannot be
     * represented without user_id (the positions' novel_id key would clash),
     * so they are removed; the owner's stay.
     */
    public function down(): void
    {
        if (Schema::hasColumn('reading_positions', 'user_id')) {
            DB::table('reading_positions')->where('user_id', '!=', $this->ownerId())->delete();
        }
        if (Schema::hasColumn('bookmarks', 'user_id')) {
            DB::table('bookmarks')->where('user_id', '!=', $this->ownerId())->delete();
        }

        if (!Schema::hasIndex('reading_positions', self::OLD_POSITION_UNIQUE)) {
            Schema::table('reading_positions', fn(Blueprint $t) => $t->unique('novel_id', self::OLD_POSITION_UNIQUE));
        }
        if (Schema::hasIndex('reading_positions', self::NEW_POSITION_UNIQUE)) {
            Schema::table('reading_positions', fn(Blueprint $t) => $t->dropUnique(self::NEW_POSITION_UNIQUE));
        }
        if (Schema::hasIndex('bookmarks', self::BOOKMARK_INDEX)) {
            Schema::table('bookmarks', fn(Blueprint $t) => $t->dropIndex(self::BOOKMARK_INDEX));
        }

        foreach (['reading_positions', 'bookmarks'] as $table) {
            if (Schema::hasColumn($table, 'user_id')) {
                Schema::table($table, fn(Blueprint $t) => $t->dropColumn('user_id'));
            }
        }
    }

    private function ownerId(): int
    {
        $owner = (int) DB::table('app_settings')->where('key', 'owner_user_id')->value('value');
        if ($owner <= 0) {
            throw new RuntimeException('No owner_user_id setting: run 2026_11_19_000001 first.');
        }

        return $owner;
    }

    private function isNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $info) {
            if ($info['name'] === $column) {
                return (bool) $info['nullable'];
            }
        }

        return false;
    }
};
