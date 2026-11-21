<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passkeys (WebAuthn credentials; App\Services\Passkeys). One row per
 * credential: the address (relying-party id) it was made on — it is only
 * offered and accepted there —, the credential id (base64url) with its
 * SHA-256 for lookups (ids can be up to 1023 bytes: too long to index on
 * MySQL), the COSE public key (base64), the signature counter, transports,
 * the name the reader gave it, and when it was made / last used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('passkeys')) {
            return;
        }

        Schema::create('passkeys', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('user_id');
            $t->string('rp_id', 255);
            $t->char('credential_hash', 64)->unique('uniq_passkeys_credential');
            $t->text('credential_id');
            $t->text('public_key');
            $t->unsignedBigInteger('counter')->default(0);
            $t->string('transports', 255)->nullable();
            $t->string('aaguid', 36)->nullable();
            $t->boolean('backup_eligible')->nullable();
            $t->boolean('backup_status')->nullable();
            $t->string('name', 80);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();

            $t->index('user_id', 'idx_passkeys_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
