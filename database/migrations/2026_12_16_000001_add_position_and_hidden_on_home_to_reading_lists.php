<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categories (reading lists) get a reader-defined order and can be hidden
 * from Home (App\Services\ReadingLists::reorder(), setHiddenOnHome()):
 *
 *   position        null until the reader first reorders; lists without one
 *                   follow the ordered ones in the old order (built-ins,
 *                   then by name)
 *   hidden_on_home  the reader took the category off Home (it stays in the
 *                   Library and the category pickers)
 *
 * Guarded so an interrupted run can be repeated (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reading_lists', function (Blueprint $table) {
            if (!Schema::hasColumn('reading_lists', 'position')) {
                $table->unsignedSmallInteger('position')->nullable()->after('kind');
            }
            if (!Schema::hasColumn('reading_lists', 'hidden_on_home')) {
                $table->boolean('hidden_on_home')->default(false)->after('position');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reading_lists', function (Blueprint $table) {
            foreach (['hidden_on_home', 'position'] as $column) {
                if (Schema::hasColumn('reading_lists', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
