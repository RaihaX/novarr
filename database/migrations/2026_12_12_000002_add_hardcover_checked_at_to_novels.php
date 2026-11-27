<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * novels.hardcover_checked_at: when a book without a series was asked of
 * Hardcover for one (App\Books\BookSeries::backfillFromHardcover(),
 * `books:series --hardcover`), found or not, so each book is asked once.
 * A failed request leaves it null (asked again next run). Nullable, at the
 * end, indexed (the run picks the unchecked books); guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novels', 'hardcover_checked_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->timestamp('hardcover_checked_at')->nullable()->index('novels_hardcover_checked_at_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('novels', 'hardcover_checked_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropIndex('novels_hardcover_checked_at_index');
            });
            Schema::table('novels', function (Blueprint $table) {
                $table->dropColumn('hardcover_checked_at');
            });
        }
    }
};
