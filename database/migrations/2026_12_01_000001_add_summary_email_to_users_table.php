<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The readers' daily summary email (novel:email-summary), per account:
 *   summary_email_off_at — the reader turned it off (Account, or the
 *                          "Stop these emails" link); null = on;
 *   summary_sent_at      — when their last summary run ended without a
 *                          mail failure (sent, or nothing new): the start of
 *                          their next window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'summary_email_off_at')) {
                $t->timestamp('summary_email_off_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'summary_sent_at')) {
                $t->timestamp('summary_sent_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['summary_email_off_at', 'summary_sent_at'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', fn(Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
