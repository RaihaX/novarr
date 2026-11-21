<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery codes (App\Services\RecoveryCodes): ten single-use codes per
 * account, stored as an HMAC (app key) of the code only. A used code keeps
 * its row with used_at; making a new set deletes the old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recovery_codes')) {
            return;
        }

        Schema::create('recovery_codes', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('user_id');
            $t->char('code_hash', 64);
            $t->timestamp('used_at')->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index(['user_id', 'used_at'], 'idx_recovery_codes_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_codes');
    }
};
