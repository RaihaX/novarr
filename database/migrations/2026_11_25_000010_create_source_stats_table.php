<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source health counters (App\Services\SourceHealth): how often each source
 * answered a chapter download, a chapter-list (TOC) walk or a search, per
 * day and hour.
 *
 * One row per (day, hour, source, kind, reason): reason '' holds the
 * successes (ok, last_ok_at), every other reason its failures (failed,
 * last_error, last_failed_at). One row per reason, not a JSON column, so
 * every event is a single atomic INSERT … ON CONFLICT / ON DUPLICATE KEY
 * UPDATE n = n + 1 on sqlite and MariaDB alike (a JSON counter can't be
 * incremented in place portably), and any new failure reason is counted
 * without a schema change. The hour makes "last 24 hours" exact. Kept
 * SourceHealth::retentionDays() (90) days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_stats', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->unsignedTinyInteger('hour');
            // Adapter key ("novelfull", "suwayomi:123") or a chapter host.
            $table->string('source', 191);
            // chapter | toc | search
            $table->string('kind', 16);
            // '' for the success row, else the failure category.
            $table->string('reason', 32)->default('');
            $table->unsignedInteger('ok')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('last_ok_at')->nullable();

            $table->unique(['day', 'hour', 'source', 'kind', 'reason'], 'source_stats_bucket_unique');
            $table->index(['source', 'day'], 'source_stats_source_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_stats');
    }
};
