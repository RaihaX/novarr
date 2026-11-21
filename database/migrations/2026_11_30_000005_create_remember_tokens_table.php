<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-browser remember-me (App\Services\RememberTokens, App\Auth\BrowserGuard),
 * replacing the one users.remember_token every browser shared: one row per
 * browser sign-in. The cookie holds a selector (looked up) and a random
 * token (only its SHA-256 is stored, compared in constant time). Expires
 * after 30 days unused, and a year after it was made whatever happens.
 * second_step_passed_at: that sign-in passed the second step, so the
 * browser may skip it on the public address. browser_session_id links the
 * row to Account → Signed-in browsers (App\Services\BrowserSessions).
 *
 * browser_sessions.session_ref: the session id, encrypted with the app key,
 * so "Sign out" on one row can end that browser's session in the session
 * store (database, redis, file) at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('remember_tokens')) {
            Schema::create('remember_tokens', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('user_id');
                $t->char('selector', 32)->unique('uniq_remember_tokens_selector');
                $t->char('token_hash', 64);
                $t->unsignedBigInteger('browser_session_id')->nullable();
                $t->timestamp('second_step_passed_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('expires_at')->nullable(); // always written; nullable so MariaDB adds no ON UPDATE CURRENT_TIMESTAMP

                $t->index('user_id', 'idx_remember_tokens_user');
                $t->index('browser_session_id', 'idx_remember_tokens_browser');
            });
        }

        if (!Schema::hasColumn('browser_sessions', 'session_ref')) {
            Schema::table('browser_sessions', fn(Blueprint $t) => $t->text('session_ref')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remember_tokens');
        if (Schema::hasColumn('browser_sessions', 'session_ref')) {
            Schema::table('browser_sessions', fn(Blueprint $t) => $t->dropColumn('session_ref'));
        }
    }
};
