<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "new Android version" email (app:announce-android), per account:
 *   app_update_emails_off_at — turned off (Account, or the email's "Stop app
 *                              update emails" link); null = on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'app_update_emails_off_at')) {
            Schema::table('users', fn(Blueprint $t) => $t->timestamp('app_update_emails_off_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'app_update_emails_off_at')) {
            Schema::table('users', fn(Blueprint $t) => $t->dropColumn('app_update_emails_off_at'));
        }
    }
};
