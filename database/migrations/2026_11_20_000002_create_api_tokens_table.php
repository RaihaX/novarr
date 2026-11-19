<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reader profiles, stage 2: bearer tokens for the native clients (iOS app,
 * KOReader plugin). Only the SHA-256 of a token is stored; the plain token is
 * shown once. A token lives until revoked (revoked_at) or its user is deleted
 * (App\User's deleted hook — no foreign keys, like the rest of the schema).
 * user_id is an unsigned int to match users.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_tokens')) {
            return;
        }

        Schema::create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('user_id');
            $t->string('name', 120);
            $t->char('token_hash', 64)->unique('uniq_api_tokens_hash');
            $t->string('device_id', 64)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'revoked_at'], 'idx_api_tokens_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
