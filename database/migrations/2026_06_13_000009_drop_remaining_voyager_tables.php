<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the remaining Voyager admin-panel tables. Voyager was removed
     * long ago and nothing in the app references these. One-way cleanup.
     * Order respects FK dependencies (pivots first).
     */
    public function up(): void
    {
        // The Voyager users table FK-references roles; drop that constraint
        // first (the app has no role-based auth) so roles can be dropped.
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role_id')) {
            $exists = DB::selectOne(
                "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                 AND CONSTRAINT_NAME = 'users_role_id_foreign'"
            );
            if ($exists) {
                Schema::table('users', fn(Blueprint $t) => $t->dropForeign('users_role_id_foreign'));
            }
        }

        foreach ([
            'permission_role',
            'user_roles',
            'permissions',
            'roles',
            'menu_items',
            'menus',
            'data_rows',
            'data_types',
            'translations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — these are dead admin-panel tables.
    }
};
