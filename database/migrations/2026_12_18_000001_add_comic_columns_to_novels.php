<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comics & graphic novels (medium 'comic', App\Comics):
 *
 *   novels.publisher             "Image", "DC Comics" (ComicInfo / Comic Vine)
 *   novels.start_year            the series' first year ("Saga (2012)"): two
 *                                series of one name are told apart by it
 *   novels.comicvine_id          the Comic Vine volume id once matched
 *   novels.comicvine_checked_at  when Comic Vine was last asked for it (null:
 *                                never; a check without a key leaves it null)
 *
 * Nullable, at the end; guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'publisher' => fn(Blueprint $t) => $t->string('publisher', 255)->nullable(),
            'start_year' => fn(Blueprint $t) => $t->unsignedSmallInteger('start_year')->nullable(),
            'comicvine_id' => fn(Blueprint $t) => $t->string('comicvine_id', 32)->nullable(),
            'comicvine_checked_at' => fn(Blueprint $t) => $t->timestamp('comicvine_checked_at')->nullable(),
        ] as $column => $add) {
            if (!Schema::hasColumn('novels', $column)) {
                Schema::table('novels', fn(Blueprint $table) => $add($table));
            }
        }
    }

    public function down(): void
    {
        foreach (['publisher', 'start_year', 'comicvine_id', 'comicvine_checked_at'] as $column) {
            if (Schema::hasColumn('novels', $column)) {
                Schema::table('novels', fn(Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
