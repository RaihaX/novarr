<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sign-in history (App\AuthEvent): sign-ins, failures, rate limits,
 * account changes, invites, tokens and the sign-in switch. No passwords,
 * tokens, invite links or token hashes are ever stored. user_id is the
 * account the event is about, actor_user_id the admin who did it (admin
 * actions only); both unsigned ints to match users.id, no foreign keys
 * (rows outlive a deleted account, as history). Pruned after
 * novarr.auth_log_days (auth:prune-log, daily).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_events')) {
            return;
        }

        Schema::create('auth_events', function (Blueprint $t) {
            $t->id();
            $t->timestamp('occurred_at')->useCurrent();
            $t->unsignedInteger('user_id')->nullable();
            $t->unsignedInteger('actor_user_id')->nullable();
            $t->string('event', 40);
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->boolean('public')->default(false);
            $t->json('detail')->nullable();

            $t->index('occurred_at', 'idx_auth_events_time');
            $t->index(['user_id', 'occurred_at'], 'idx_auth_events_user');
            $t->index(['event', 'occurred_at'], 'idx_auth_events_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
    }
};
