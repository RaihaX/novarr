<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reader profiles, stage 2: linking a device (the KOReader plugin) by a short
 * code instead of a password. The device asks for a pairing (code + secret),
 * a signed-in user types the code on the web, the device collects its token
 * by the secret — once. Rows live ten minutes; expired ones are swept when
 * new pairings are made (App\DevicePairing).
 *
 * `code` is unique only among unexpired pairings (checked when one is made),
 * so it is indexed, not unique. `token` holds the encrypted plain token
 * between "linked" and "collected"; the row is deleted when it is collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_pairings')) {
            return;
        }

        Schema::create('device_pairings', function (Blueprint $t) {
            $t->id();
            $t->char('code', 6);
            $t->char('secret_hash', 64)->unique('uniq_device_pairings_secret');
            $t->string('device_id', 64)->nullable();
            $t->string('device_name', 120)->nullable();
            $t->unsignedInteger('user_id')->nullable();
            // The token issued on linking: revoked if the device never collects it.
            $t->unsignedBigInteger('api_token_id')->nullable();
            $t->text('token')->nullable();
            $t->timestamp('linked_at')->nullable();
            // dateTime, not timestamp: a NOT NULL timestamp on a MariaDB with
            // explicit_defaults_for_timestamp off would gain ON UPDATE
            // CURRENT_TIMESTAMP and reset the expiry when the row is linked.
            $t->dateTime('expires_at');
            $t->timestamps();

            $t->index(['code', 'expires_at'], 'idx_device_pairings_code');
            $t->index('expires_at', 'idx_device_pairings_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pairings');
    }
};
