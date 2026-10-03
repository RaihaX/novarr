<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-chapter retry state for the chapter sweep (exponential backoff instead
 * of retrying every dead chapter on every run) and the NovelUpdates identity
 * score for novels (completion is only trusted when the match is confident).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            // Failed fetches since the last success.
            $table->unsignedInteger('attempts')->default(0)->after('last_attempt_at');
            // Not fetched by the sweep before this time (NULL = due now).
            $table->dateTime('next_attempt_at')->nullable()->after('attempts');
            // Failure category of the latest attempt (cloudflare, not_found, ...).
            $table->string('last_failure_reason', 32)->nullable()->after('next_attempt_at');
            $table->index(['novel_id', 'status', 'next_attempt_at'], 'idx_novel_pending_due');
        });

        Schema::table('novels', function (Blueprint $table) {
            // 0..1 confidence that novelupdates_url is this novel; 1.0 = chosen by hand.
            $table->decimal('novelupdates_match_score', 4, 3)->nullable()->after('novelupdates_url');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_novel_pending_due');
            $table->dropColumn(['attempts', 'next_attempt_at', 'last_failure_reason']);
        });

        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn('novelupdates_match_score');
        });
    }
};
