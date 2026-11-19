<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reader profiles, stage 1: users gain a sign-in name, a role and a disabled
 * flag, and the library gets an owner — the account every existing piece of
 * reading state belongs to (app setting `owner_user_id`, see
 * App\Support\CurrentReader). Nothing signs in yet: every request is the owner.
 *
 * The owner is the lowest-id existing user (production has exactly one, left
 * over from an old admin package), else a new "Admin" with a random password
 * nobody knows. Every user gets a username derived from its name; email
 * becomes optional (accounts are name + password).
 *
 * Each step is guarded: MariaDB DDL auto-commits, so an interrupted run must
 * be safe to repeat.
 */
return new class extends Migration
{
    private const OWNER_SETTING = 'owner_user_id';

    public function up(): void
    {
        foreach ([
            'username' => fn(Blueprint $t) => $t->string('username', 60)->nullable()->after('name'),
            'role' => fn(Blueprint $t) => $t->string('role', 16)->default('reader')->after('username'),
            'disabled_at' => fn(Blueprint $t) => $t->timestamp('disabled_at')->nullable(),
            'last_seen_at' => fn(Blueprint $t) => $t->timestamp('last_seen_at')->nullable(),
        ] as $column => $add) {
            if (!Schema::hasColumn('users', $column)) {
                Schema::table('users', $add);
            }
        }

        if (!$this->emailIsNullable()) {
            Schema::table('users', fn(Blueprint $t) => $t->string('email')->nullable()->change());
        }

        $this->fillUsernames();

        if (!Schema::hasIndex('users', 'users_username_unique')) {
            Schema::table('users', fn(Blueprint $t) => $t->unique('username', 'users_username_unique'));
        }

        $ownerId = $this->ownerId();
        DB::table('users')->where('id', $ownerId)->update(['role' => 'admin']);
        \App\Setting::put(self::OWNER_SETTING, (string) $ownerId);
    }

    public function down(): void
    {
        // Email was required before: give account-only users a placeholder
        // rather than deleting them.
        if (Schema::hasColumn('users', 'username')) {
            foreach (DB::table('users')->whereNull('email')->get(['id', 'username']) as $user) {
                DB::table('users')->where('id', $user->id)
                    ->update(['email' => ($user->username ?: 'user' . $user->id) . '@novarr.invalid']);
            }
        }
        if ($this->emailIsNullable()) {
            Schema::table('users', fn(Blueprint $t) => $t->string('email')->nullable(false)->change());
        }

        if (Schema::hasIndex('users', 'users_username_unique')) {
            Schema::table('users', fn(Blueprint $t) => $t->dropUnique('users_username_unique'));
        }
        foreach (['username', 'role', 'disabled_at', 'last_seen_at'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', fn(Blueprint $t) => $t->dropColumn($column));
            }
        }

        DB::table('app_settings')->where('key', self::OWNER_SETTING)->delete();
        \Illuminate\Support\Facades\Cache::forget('settings_all');
    }

    private function emailIsNullable(): bool
    {
        foreach (Schema::getColumns('users') as $column) {
            if ($column['name'] === 'email') {
                return (bool) $column['nullable'];
            }
        }

        return true;
    }

    /** username = slug of the name ("reyhan"), made unique with a numeric suffix. */
    private function fillUsernames(): void
    {
        $taken = DB::table('users')->whereNotNull('username')->pluck('username')->map(fn($u) => strtolower($u))->flip()->all();

        foreach (DB::table('users')->whereNull('username')->orderBy('id')->get(['id', 'name']) as $user) {
            $base = Str::limit(Str::slug((string) $user->name), 50, '') ?: 'admin';
            $username = $base;
            for ($n = 2; isset($taken[$username]); $n++) {
                $username = $base . '-' . $n;
            }
            $taken[$username] = true;
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }
    }

    /** The stored owner when it still exists, else the lowest-id user, else a new admin. */
    private function ownerId(): int
    {
        $stored = (int) DB::table('app_settings')->where('key', self::OWNER_SETTING)->value('value');
        if ($stored > 0 && DB::table('users')->where('id', $stored)->exists()) {
            return $stored;
        }

        $lowest = DB::table('users')->orderBy('id')->value('id');
        if ($lowest !== null) {
            return (int) $lowest;
        }

        $username = DB::table('users')->where('username', 'admin')->exists() ? 'admin-' . Str::lower(Str::random(6)) : 'admin';

        return (int) DB::table('users')->insertGetId([
            'name' => 'Admin',
            'username' => $username,
            'email' => null,
            // Unusable: nobody knows it. Stage 2 lets the owner set a password.
            'password' => Hash::make(Str::random(64)),
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
