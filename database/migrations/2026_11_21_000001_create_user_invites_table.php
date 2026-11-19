<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inviting a reader by email (App\UserInvite): the admin types a name and an
 * email, the person gets a link, opens it and chooses their own sign-in name
 * and password. Only the token's SHA-256 is stored; the plain token is only
 * ever in the emailed / copied link. A cancelled invite is deleted; an
 * accepted one keeps its row (accepted_at, user_id) until the user is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_invites')) {
            return;
        }

        Schema::create('user_invites', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->string('email', 255);
            $t->char('token_hash', 64)->unique('uniq_user_invites_token');
            $t->unsignedInteger('invited_by')->nullable();
            // dateTime, not timestamp: see the device_pairings migration
            // (MariaDB could add ON UPDATE CURRENT_TIMESTAMP to a NOT NULL timestamp).
            $t->dateTime('expires_at');
            $t->dateTime('accepted_at')->nullable();
            $t->unsignedInteger('user_id')->nullable();
            $t->timestamps();

            $t->index('email', 'idx_user_invites_email');
            $t->index('user_id', 'idx_user_invites_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invites');
    }
};
