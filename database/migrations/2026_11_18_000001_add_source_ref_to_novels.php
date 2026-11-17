<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * novels.source_ref: which upstream source a novel is fetched from when its
 * own URL does not say. Today only Suwayomi needs it: every Suwayomi series
 * is {server}/manga/{id} on one host, but behind it are many independent
 * sites ("Suwayomi sources", ids like 8448310129093543312). The download
 * lanes (App\Services\DownloadLanes) give each of those sites its own lane,
 * so one slow or dead site does not hold up or cool down the others.
 *
 * Nullable, no default, no index: a metadata-only ALTER on MariaDB, filled
 * in when metadata is fetched or lazily by the next download. Guarded so an
 * interrupted run can be repeated (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novels', 'source_ref')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->string('source_ref', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('novels', 'source_ref')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropColumn('source_ref');
            });
        }
    }
};
