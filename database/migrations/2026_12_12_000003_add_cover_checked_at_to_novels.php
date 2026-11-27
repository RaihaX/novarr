<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Better covers for the Books library (App\Books\BookCovers, `books:covers`):
 *
 *   novels.cover_checked_at  when the book's cover was last checked against
 *                            the catalogues (replaced or not), so the nightly
 *                            run handles each book once (`--recheck` redoes
 *                            them). A check that could ask no catalogue leaves
 *                            it null. Indexed.
 *   novels.cover_source      who chose the current cover: 'manual' (the owner:
 *                            the edit page's upload, the review page's choice,
 *                            "Find a better cover"), 'catalogue' (books:covers);
 *                            null = the ePub's or a lookup's at import, or
 *                            unknown. A manual cover is never replaced
 *                            automatically.
 *
 * Nullable, at the end; guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novels', 'cover_checked_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->timestamp('cover_checked_at')->nullable()->index('novels_cover_checked_at_index');
            });
        }
        if (!Schema::hasColumn('novels', 'cover_source')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->string('cover_source', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('novels', 'cover_checked_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropIndex('novels_cover_checked_at_index');
            });
        }
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn(['cover_checked_at', 'cover_source']);
        });
    }
};
