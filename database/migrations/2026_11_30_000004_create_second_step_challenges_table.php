<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pending state between a correct password and the second step
 * (App\Services\SecondStep): one row per challenge, found by the SHA-256 of
 * its random token (held by the browser's session, or returned to the app
 * as `challenge`). Bound to the account and, for the app, its device_id.
 * Grants nothing by itself; ten minutes; five wrong codes end it; used once
 * (the row is deleted). `purpose` is sign_in or confirm (the Account page's
 * "Confirm it's you"). The emailed code is kept as an HMAC (app key).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('second_step_challenges')) {
            return;
        }

        Schema::create('second_step_challenges', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('user_id');
            $t->char('token_hash', 64)->unique('uniq_second_step_token');
            $t->string('purpose', 16);
            $t->string('via', 8);
            $t->string('device_id', 64)->nullable();
            $t->string('device_name', 120)->nullable();
            $t->unsignedTinyInteger('wrong_tries')->default(0);
            $t->char('email_code_hash', 64)->nullable();
            $t->timestamp('email_code_expires_at')->nullable();
            $t->unsignedTinyInteger('email_sends')->default(0);
            $t->timestamp('expires_at')->nullable(); // always written; nullable so MariaDB adds no ON UPDATE CURRENT_TIMESTAMP
            $t->timestamp('created_at')->nullable();

            $t->index(['user_id', 'expires_at'], 'idx_second_step_user');
            $t->index('expires_at', 'idx_second_step_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('second_step_challenges');
    }
};
