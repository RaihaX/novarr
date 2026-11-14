<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group parking of chapters the source lists but does not serve (404 / empty
 * answers). When three in a row fail that way, the novel's pending
 * content-failed chapters are parked together until source_recheck_at, which
 * backs off with source_fail_streak (6 h, 12 h, ... up to 7 days); the first
 * successful download resets both. See App\Scraping\SourceUnavailable.
 *
 * Only `novels` changes: novel_chapters and chapter_texts are far too big to
 * alter casually on production. Each column is its own guarded statement
 * (MariaDB DDL auto-commits, so an interrupted run must be repeatable).
 */
return new class extends Migration
{
    private const COLUMNS = ['source_fail_streak', 'source_recheck_at'];

    public function up(): void
    {
        if (!Schema::hasColumn('novels', 'source_fail_streak')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->unsignedSmallInteger('source_fail_streak')->default(0);
            });
        }

        if (!Schema::hasColumn('novels', 'source_recheck_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dateTime('source_recheck_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        $present = array_values(array_filter(self::COLUMNS, fn($c) => Schema::hasColumn('novels', $c)));
        if ($present === []) {
            return;
        }

        Schema::table('novels', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }
};
