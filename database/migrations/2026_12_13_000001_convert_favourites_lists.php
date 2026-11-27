<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Favourites" becomes a built-in reading list (App\ReadingList::
 * KIND_FAVOURITES, the heart on a series page). A reader who already made
 * their own list called "Favourites" (it was a one-tap starter) keeps it,
 * members and all: it is turned into the built-in one (kind 'favourites',
 * the fixed name and system name_key). A reader who already has the built-in
 * one keeps both as they are.
 *
 * Data only, row by row and guarded, so a repeated or interrupted run does
 * nothing twice. down() turns each built-in "Favourites" back into an own
 * list called "Favourites" where that name is free.
 */
return new class extends Migration
{
    private const KIND = 'favourites';

    private const SYSTEM_KEY = "\x01favourites";

    public function up(): void
    {
        if (!Schema::hasTable('reading_lists') || !Schema::hasColumn('reading_lists', 'kind')) {
            return;
        }

        $own = DB::table('reading_lists')
            ->whereNull('kind')
            ->where('name_key', 'favourites')
            ->orderBy('id')
            ->get(['id', 'user_id']);

        foreach ($own as $list) {
            $hasBuiltIn = DB::table('reading_lists')
                ->where('user_id', $list->user_id)
                ->where('kind', self::KIND)
                ->exists();
            if ($hasBuiltIn) {
                continue;
            }
            DB::table('reading_lists')
                ->where('id', $list->id)
                ->whereNull('kind')
                ->update(['kind' => self::KIND, 'name' => 'Favourites', 'name_key' => self::SYSTEM_KEY]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('reading_lists') || !Schema::hasColumn('reading_lists', 'kind')) {
            return;
        }

        $builtIn = DB::table('reading_lists')
            ->where('kind', self::KIND)
            ->orderBy('id')
            ->get(['id', 'user_id']);

        foreach ($builtIn as $list) {
            $nameTaken = DB::table('reading_lists')
                ->where('user_id', $list->user_id)
                ->where('name_key', 'favourites')
                ->exists();
            if ($nameTaken) {
                continue;
            }
            DB::table('reading_lists')
                ->where('id', $list->id)
                ->update(['kind' => null, 'name' => 'Favourites', 'name_key' => 'favourites']);
        }
    }
};
