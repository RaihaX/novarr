<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue (OPDS) passwords are API tokens of kind `catalogue`: usable only
 * as the HTTP Basic password on /opds and the ePub download, never as a
 * bearer token. Every existing token is an app / device token (`app`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('api_tokens', 'kind')) {
            return;
        }

        Schema::table('api_tokens', function (Blueprint $t) {
            $t->string('kind', 16)->default('app');
            $t->index(['user_id', 'kind'], 'idx_api_tokens_user_kind');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('api_tokens', 'kind')) {
            Schema::table('api_tokens', function (Blueprint $t) {
                $t->dropIndex('idx_api_tokens_user_kind');
                $t->dropColumn('kind');
            });
        }
    }
};
