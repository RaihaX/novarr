<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a pending chapter was last fetched (success or failure). The chapter
 * sweep orders pending rows by it (NULL first) so dead or stub chapters at
 * the front of a novel rotate to the back instead of filling the per-run cap
 * on every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dateTime('last_attempt_at')->nullable()->after('download_date');
            $table->index(['novel_id', 'status', 'last_attempt_at'], 'idx_novel_pending_attempt');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_novel_pending_attempt');
            $table->dropColumn('last_attempt_at');
        });
    }
};
