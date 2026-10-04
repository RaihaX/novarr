<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a novel comes from: a translated web novel (Chinese / Korean /
 * Japanese / … original) or an original English one.
 *
 *   origin           translated | original | unknown (null = never evaluated)
 *   origin_language  ISO-ish code of the original language: zh, ko, ja, en, …
 *   origin_source    novelupdates | inferred | manual — who set it; manual
 *                    beats novelupdates beats inferred (see Novel::applyOrigin)
 *   origin_type      NovelUpdates' "Type" verbatim, e.g. "Web Novel (KR)"
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded per column: MariaDB DDL auto-commits, so a failure partway
        // must not leave a re-run tripping over "Duplicate column".
        $hadOrigin = Schema::hasColumn('novels', 'origin');
        Schema::table('novels', function (Blueprint $table) use ($hadOrigin) {
            if (!$hadOrigin) {
                $table->string('origin', 16)->nullable();
                $table->index('origin', 'idx_novels_origin');
            }
            if (!Schema::hasColumn('novels', 'origin_language')) {
                $table->string('origin_language', 8)->nullable();
            }
            if (!Schema::hasColumn('novels', 'origin_source')) {
                $table->string('origin_source', 16)->nullable();
            }
            if (!Schema::hasColumn('novels', 'origin_type')) {
                $table->string('origin_type', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->dropIndex('idx_novels_origin');
        });
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn(['origin', 'origin_language', 'origin_source', 'origin_type']);
        });
    }
};
