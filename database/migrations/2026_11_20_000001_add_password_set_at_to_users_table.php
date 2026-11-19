<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reader profiles, stage 2 (sign-in): when the account's password was last
 * set through Novarr. Null means nobody set one here — the owner carried over
 * from stage 1 has an unknown (legacy or random) hash and cannot sign in, and
 * "Require sign-in" cannot be turned on, until a password is set.
 *
 * Guarded: MariaDB DDL auto-commits, so an interrupted run must be safe to repeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'password_set_at')) {
            Schema::table('users', fn(Blueprint $t) => $t->timestamp('password_set_at')->nullable());
        }
        // Web sessions signed in before this moment are over (account turned
        // off, password reset): App\Http\Middleware\IdentifyReader.
        if (!Schema::hasColumn('users', 'sessions_valid_after')) {
            Schema::table('users', fn(Blueprint $t) => $t->timestamp('sessions_valid_after')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['sessions_valid_after', 'password_set_at'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', fn(Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
