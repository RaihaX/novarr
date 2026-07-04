<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-device reading position: how far through a chapter the reader has
 * scrolled (0–100). read_at says *that* a chapter was opened; this says
 * *where* you are inside it, so Continue Reading can resume mid-chapter on
 * any device.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded: the ALTER copies the whole (longtext-heavy) table and can
        // outlive a client timeout — a retry must not re-add the column.
        if (!Schema::hasColumn('novel_chapters', 'read_progress')) {
            Schema::table('novel_chapters', function (Blueprint $table) {
                $table->unsignedTinyInteger('read_progress')->nullable()->after('read_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropColumn('read_progress');
        });
    }
};
