<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devices each account has signed in from with its password
 * (App\Services\KnownDevices): a sign-in from one not listed sends the
 * "New sign-in" email. device_hash is the SHA-256 of a random id the
 * browser keeps in a cookie, or of the app's device_id — never the id
 * itself. At most 50 per account (the oldest go). No foreign keys, like
 * the rest of the schema (App\User's deleted hook removes the rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('known_devices')) {
            return;
        }

        Schema::create('known_devices', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('user_id');
            $t->char('device_hash', 64);
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->string('label', 120)->nullable();

            $t->unique(['user_id', 'device_hash'], 'uniq_known_devices_user_hash');
            $t->index(['user_id', 'last_seen_at'], 'idx_known_devices_user_seen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_devices');
    }
};
