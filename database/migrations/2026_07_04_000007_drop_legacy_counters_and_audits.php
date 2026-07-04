<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the last of the dead schema:
 *  - novels' denormalized counter columns were written only by the retired
 *    novel:calculate_chapter command; the UI has computed all of these live
 *    (NovelController::novelStats) for a long time.
 *  - the audits table (2018) has had nothing writing to it for years.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn([
                'current_chapters',
                'chapters_not_downloaded',
                'duplicate_chapters',
                'missing_chapters',
                'progress',
            ]);
        });

        Schema::dropIfExists('audits');
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->integer('current_chapters')->default(0);
            $table->integer('chapters_not_downloaded')->default(0);
            $table->integer('duplicate_chapters')->default(0);
            $table->integer('missing_chapters')->default(0);
            $table->integer('progress')->default(0);
        });
        // audits is not recreated — it held no data worth restoring.
    }
};
