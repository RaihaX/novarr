<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reading_lists.kind: null for a reader's own list, 'want_to_read' for the
 * built-in "Want to read" list (App\ReadingList::KIND_WANT_TO_READ), made on
 * first use, which cannot be renamed or deleted. Unique per reader, so a
 * race can never make two (a reader's own lists are all null, which a unique
 * index allows any number of on every database). Guarded so an interrupted
 * run can be repeated (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('reading_lists', 'kind')) {
            Schema::table('reading_lists', function (Blueprint $table) {
                $table->string('kind', 20)->nullable()->after('name_key');
                $table->unique(['user_id', 'kind'], 'uniq_reading_lists_user_kind');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reading_lists', 'kind')) {
            Schema::table('reading_lists', function (Blueprint $table) {
                $table->dropUnique('uniq_reading_lists_user_kind');
                $table->dropColumn('kind');
            });
        }
    }
};
