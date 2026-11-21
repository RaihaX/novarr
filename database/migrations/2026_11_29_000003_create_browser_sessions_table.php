<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The web browsers each account is signed in on (Account → Signed-in
 * browsers; App\Services\BrowserSessions), kept by Novarr itself so it
 * works the same on every session driver (database, redis, file).
 * session_hash is the SHA-256 of the session id, device_hash that of the
 * browser's sign-in device cookie (App\Services\KnownDevices) when it has
 * one — never the ids themselves. Written at sign-in, touched at most every
 * 5 minutes, removed at sign-out, "sign out everywhere" and expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('browser_sessions')) {
            return;
        }

        Schema::create('browser_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('user_id');
            $t->char('session_hash', 64)->unique('uniq_browser_sessions_hash');
            $t->char('device_hash', 64)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->boolean('public')->default(false);
            $t->timestamp('signed_in_at')->nullable();
            $t->timestamp('last_active_at')->nullable();

            $t->index(['user_id', 'last_active_at'], 'idx_browser_sessions_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_sessions');
    }
};
