<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * reading_lists.name_key compares exactly (utf8mb4_bin) on MySQL / MariaDB.
 *
 * Built-in lists carry a control character in their key ("\x01favourites")
 * so a reader's own list called "Favourites" ("favourites") can sit beside
 * the built-in one. The default unicode_ci collation ignores control
 * characters, so the two keys compared equal and the unique index refused
 * the built-in list for any reader with an own "Favourites" list. The keys
 * are already normalised (lowercased, trimmed), so exact is right. Binary is
 * stricter, so no existing rows can collide. SQLite already compares exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::statement('ALTER TABLE reading_lists MODIFY name_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::statement('ALTER TABLE reading_lists MODIFY name_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    }
};
