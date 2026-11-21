<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second sign-in step (App\Services\SecondStep), per account:
 *   totp_secret        — the authenticator app's secret, encrypted with the
 *                        app key (Crypt), null when none is set up;
 *   totp_enabled_at    — when it was set up;
 *   totp_last_step     — the last 30-second time step a code was accepted
 *                        for: a used step is never accepted again;
 *   email_codes_off_at — the reader turned emailed codes off (only allowed
 *                        while a passkey or authenticator exists);
 *   passkey_handle     — the random WebAuthn user handle (never the id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'totp_secret')) {
                $t->text('totp_secret')->nullable();
            }
            if (!Schema::hasColumn('users', 'totp_enabled_at')) {
                $t->timestamp('totp_enabled_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'totp_last_step')) {
                $t->unsignedBigInteger('totp_last_step')->nullable();
            }
            if (!Schema::hasColumn('users', 'email_codes_off_at')) {
                $t->timestamp('email_codes_off_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'passkey_handle')) {
                $t->string('passkey_handle', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['totp_secret', 'totp_enabled_at', 'totp_last_step', 'email_codes_off_at', 'passkey_handle'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', fn(Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
